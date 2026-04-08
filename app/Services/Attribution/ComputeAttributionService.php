<?php

namespace App\Services\Attribution;

use App\Models\AttributionDaily;
use App\Models\BitunixInvitation;
use App\Models\CampaignVipMapping;
use App\Models\Ga4Acquisition;
use App\Models\GscDailySummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * ComputeAttributionService — the killer feature.
 *
 * Builds the unified `attribution_daily` table from three independent sources:
 *
 *   1. Search Console (organic search funnel head)         → gsc_daily_summary
 *   2. Google Analytics 4 acquisition (sessions per UTM)   → ga4_acquisition
 *   3. Bitunix Partner invitations (signups + commission)  → bitunix_invitations
 *
 * The link between Bitunix's per-VIP-code rows and GA4's per-UTM rows lives in
 * `campaign_vip_mappings` (admin-editable). For each (date, utm tuple) we
 * compute one attribution_daily row containing every metric we know.
 *
 * Idempotent: re-running the same window UPSERTS by (date, row_hash) so it's
 * always safe to call from cron, ad-hoc, or after a Bitunix back-fill.
 */
class ComputeAttributionService
{
    private const DEFAULT_SOURCE = '(direct)';
    private const DEFAULT_MEDIUM = '(none)';
    private const DEFAULT_CAMPAIGN = '(not set)';

    /**
     * Compute attribution rows for every day in `[start, end]` inclusive.
     * Returns the total number of rows upserted.
     */
    public function compute(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $written = 0;

        // Iterate inclusive on both bounds.
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $written += $this->computeForDate($day);
        }

        Log::info('ComputeAttributionService.compute', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Build all attribution rows for a single date.
     *
     * Algorithm:
     *   1. Build the universe of UTM tuples for this date by UNION-ing:
     *        a. all (source,medium,campaign) found in ga4_acquisition for the date
     *        b. all (utm_source,utm_medium,utm_campaign) implied by
     *           campaign_vip_mappings whose vip_code has bitunix_invitations rows for the date
     *   2. For each tuple, compute one attribution_daily row by joining
     *      whatever metrics each source has.
     */
    private function computeForDate(CarbonImmutable $date): int
    {
        $dateStr = $date->toDateString();

        // 1. UTM tuples from GA4 (real session attribution)
        $ga4Rows = Ga4Acquisition::query()
            ->where('date', $dateStr)
            ->get()
            ->keyBy(fn (Ga4Acquisition $row) => $this->tupleKey($row->source, $row->medium, $row->campaign));

        // 2. UTM tuples implied by Bitunix mappings (signup attribution)
        //    For each vip_code that has invitation data on this date, look up
        //    the matching campaign mapping. Bitunix-only tuples (without GA4
        //    sessions) are still recorded — they tell us about signups from
        //    sources we haven't tagged with UTMs.
        $bitunixRows = BitunixInvitation::query()
            ->where('date', $dateStr)
            ->get();

        $vipCodes = $bitunixRows->pluck('vip_code')->unique()->all();
        $mappings = CampaignVipMapping::query()
            ->whereIn('vip_code', $vipCodes)
            ->get()
            ->keyBy('vip_code');

        // Group bitunix rows by mapped UTM tuple. Unmapped vip_codes fall into
        // a "(unmapped:<vipcode>)" bucket so they show up on the dashboard
        // but don't pollute real campaign rows.
        $bitunixByTuple = [];
        foreach ($bitunixRows as $row) {
            $mapping = $mappings->get($row->vip_code);
            $tuple = [
                'utm_source' => $mapping?->utm_source ?? self::DEFAULT_SOURCE,
                'utm_medium' => $mapping?->utm_medium ?? '(unmapped)',
                'utm_campaign' => $mapping?->utm_campaign ?? "(vip:{$row->vip_code})",
            ];
            $key = $this->tupleKey($tuple['utm_source'], $tuple['utm_medium'], $tuple['utm_campaign']);

            $bitunixByTuple[$key] ??= [
                'tuple' => $tuple,
                'signups' => 0,
                'first_deposit' => 0,
                'first_trade' => 0,
                'commission' => '0',
                'trading_volume' => '0',
            ];
            $bitunixByTuple[$key]['signups'] += (int) ($row->registered_users ?? 0);
            $bitunixByTuple[$key]['first_deposit'] += $row->first_deposit_users;
            $bitunixByTuple[$key]['first_trade'] += $row->first_trade_users;
            $bitunixByTuple[$key]['commission'] = bcadd($bitunixByTuple[$key]['commission'], $row->my_commission ?: '0', 10);
            $bitunixByTuple[$key]['trading_volume'] = bcadd($bitunixByTuple[$key]['trading_volume'], $row->trading_volume ?: '0', 10);
        }

        // 3. GSC daily totals (only attached to organic Google rows)
        $gscDaily = GscDailySummary::query()->where('date', $dateStr)->first();

        // 4. Compute the union of all tuples to materialise.
        $allKeys = array_unique(array_merge(
            $ga4Rows->keys()->all(),
            array_keys($bitunixByTuple),
        ));

        if (empty($allKeys)) {
            return 0;
        }

        $written = 0;
        foreach ($allKeys as $key) {
            $ga4 = $ga4Rows->get($key);
            $bx = $bitunixByTuple[$key] ?? null;

            // Resolve the tuple from whichever source has it.
            if ($ga4 !== null) {
                $source = $ga4->source ?: self::DEFAULT_SOURCE;
                $medium = $ga4->medium ?: self::DEFAULT_MEDIUM;
                $campaign = $ga4->campaign ?: self::DEFAULT_CAMPAIGN;
            } else {
                $source = $bx['tuple']['utm_source'];
                $medium = $bx['tuple']['utm_medium'];
                $campaign = $bx['tuple']['utm_campaign'];
            }

            $isOrganicGoogle = strtolower($source) === 'google'
                && in_array(strtolower($medium), ['organic', 'organic_search'], true);

            // GSC numbers — only meaningful for organic Google rows. We attach
            // the day's total since GSC doesn't break down by GA4 campaign.
            $gscClicks = $isOrganicGoogle ? (int) ($gscDaily->total_clicks ?? 0) : 0;
            $gscImpressions = $isOrganicGoogle ? (int) ($gscDaily->total_impressions ?? 0) : 0;
            $gscAvgCtr = $isOrganicGoogle ? (string) ($gscDaily->avg_ctr ?? '0') : '0';
            $gscAvgPosition = $isOrganicGoogle ? (string) ($gscDaily->avg_position ?? '0') : '0';

            $ga4Users = (int) ($ga4->users ?? 0);
            $ga4Sessions = (int) ($ga4->sessions ?? 0);
            $ga4Engaged = (int) ($ga4->engaged_sessions ?? 0);
            $ga4Conv = (int) ($ga4->conversions ?? 0);

            $bxSignups = $bx['signups'] ?? 0;
            $bxFirstDeposit = $bx['first_deposit'] ?? 0;
            $bxFirstTrade = $bx['first_trade'] ?? 0;
            $bxCommission = (string) ($bx['commission'] ?? '0');
            $bxTradingVolume = (string) ($bx['trading_volume'] ?? '0');

            // Computed conversion rates — guard against division by zero.
            $clickToSession = $gscClicks > 0 ? round($ga4Sessions / $gscClicks, 5) : 0;
            $sessionToSignup = $ga4Sessions > 0 ? round($bxSignups / $ga4Sessions, 5) : 0;
            $clicksToSignup = $gscClicks > 0 ? round($bxSignups / $gscClicks, 5) : 0;

            $rowHash = md5("{$source}|{$medium}|{$campaign}");

            AttributionDaily::updateOrCreate(
                ['date' => $dateStr, 'row_hash' => $rowHash],
                [
                    'utm_source' => $source,
                    'utm_medium' => $medium,
                    'utm_campaign' => $campaign,
                    'gsc_clicks' => $gscClicks,
                    'gsc_impressions' => $gscImpressions,
                    'gsc_avg_position' => $gscAvgPosition,
                    'gsc_avg_ctr' => $gscAvgCtr,
                    'ga4_users' => $ga4Users,
                    'ga4_sessions' => $ga4Sessions,
                    'ga4_engaged_sessions' => $ga4Engaged,
                    'ga4_conversions' => $ga4Conv,
                    'bitunix_signups' => $bxSignups,
                    'bitunix_first_deposit' => $bxFirstDeposit,
                    'bitunix_first_trade' => $bxFirstTrade,
                    'bitunix_commission' => $bxCommission,
                    'bitunix_trading_volume' => $bxTradingVolume,
                    'click_to_session_rate' => $clickToSession,
                    'session_to_signup_rate' => $sessionToSignup,
                    'clicks_to_signup_rate' => $clicksToSignup,
                ],
            );
            $written++;
        }

        return $written;
    }

    private function tupleKey(?string $source, ?string $medium, ?string $campaign): string
    {
        return ($source ?? self::DEFAULT_SOURCE)
            .'|'.($medium ?? self::DEFAULT_MEDIUM)
            .'|'.($campaign ?? self::DEFAULT_CAMPAIGN);
    }
}
