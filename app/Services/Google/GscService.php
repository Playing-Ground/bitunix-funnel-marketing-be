<?php

namespace App\Services\Google;

use App\Models\GscDailySummary;
use App\Models\GscPagePerformance;
use App\Models\GscQueryPerformance;
use Carbon\CarbonImmutable;
use Google\Client as GoogleClient;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\ApiDimensionFilter;
use Google\Service\SearchConsole\ApiDimensionFilterGroup;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use Illuminate\Support\Facades\Log;

/**
 * GscService — wraps Google Search Console's `searchanalytics.query` endpoint.
 *
 * Auth: service-account JSON via google/apiclient. The service-account email
 * must be added as a user (Restricted is enough) on the GSC property —
 * `https://www.bitunix.com/` in our case. Pacific Time governs date windows
 * server-side, so the SDK passes ISO date strings as-is.
 *
 * Pagination: API caps at rowLimit=25000. We walk pages with `startRow` until
 * we get a short page back. Three dimension axes are populated:
 *   - gsc_query_performance  (date × query × page × country × device)
 *   - gsc_page_performance   (date × page)
 *   - gsc_daily_summary      (one row per day, computed from query rows)
 */
class GscService
{
    private const ROW_LIMIT = 25000;

    private const SCOPES = [
        'https://www.googleapis.com/auth/webmasters.readonly',
    ];

    private readonly SearchConsole $service;
    private readonly string $siteUrl;

    public function __construct(GoogleCredentialsFactory $credentialsFactory)
    {
        $this->siteUrl = (string) config('services.google.gsc_site_url');

        $client = new GoogleClient();
        $client->setAuthConfig($credentialsFactory->path());
        $client->setScopes(self::SCOPES);
        $client->setApplicationName('Bitunix MarTech Dashboard');

        $this->service = new SearchConsole($client);
    }

    public function ping(): array
    {
        try {
            $sites = $this->service->sites->listSites();
            $entries = [];
            foreach ($sites->getSiteEntry() ?? [] as $entry) {
                $entries[] = [
                    'siteUrl' => $entry->getSiteUrl(),
                    'permissionLevel' => $entry->getPermissionLevel(),
                ];
            }

            return [
                'ok' => true,
                'configured_site' => $this->siteUrl,
                'visible_sites' => $entries,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'configured_site' => $this->siteUrl,
            ];
        }
    }

    /**
     * Fetches query × page × country × device rows for each day in the window.
     * Walks startRow pagination until exhausted.
     */
    public function fetchQueryPerformance(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $request = new SearchAnalyticsQueryRequest();
        $request->setStartDate($start->toDateString());
        $request->setEndDate($end->toDateString());
        $request->setDimensions(['date', 'query', 'page', 'country', 'device']);
        $request->setType('web');
        $request->setDataState('final');
        $request->setRowLimit(self::ROW_LIMIT);

        $startRow = 0;
        $written = 0;
        $dailyTotals = []; // for the summary table

        do {
            $request->setStartRow($startRow);
            $response = $this->service->searchanalytics->query($this->siteUrl, $request);
            $rows = $response->getRows() ?? [];

            foreach ($rows as $row) {
                $keys = $row->getKeys();
                [$date, $query, $page, $country, $device] = [
                    $keys[0] ?? null,
                    $keys[1] ?? '',
                    $keys[2] ?? '',
                    $keys[3] ?? '',
                    $keys[4] ?? '',
                ];

                if ($date === null) {
                    continue;
                }

                $clicks = (int) $row->getClicks();
                $impressions = (int) $row->getImpressions();
                $ctr = (float) $row->getCtr();
                $position = (float) $row->getPosition();

                $hash = md5("{$query}|{$page}|{$country}|{$device}");

                GscQueryPerformance::updateOrCreate(
                    ['date' => $date, 'row_hash' => $hash],
                    [
                        'query' => $query,
                        'page' => $page,
                        'country' => $country,
                        'device' => $device,
                        'clicks' => $clicks,
                        'impressions' => $impressions,
                        'ctr' => $ctr,
                        'position' => $position,
                        'data_state' => 'final',
                    ],
                );
                $written++;

                $dailyTotals[$date] ??= ['clicks' => 0, 'impressions' => 0, 'pos_sum' => 0.0, 'pos_n' => 0];
                $dailyTotals[$date]['clicks'] += $clicks;
                $dailyTotals[$date]['impressions'] += $impressions;
                $dailyTotals[$date]['pos_sum'] += $position * $impressions;
                $dailyTotals[$date]['pos_n'] += $impressions;
            }

            $startRow += count($rows);
        } while (count($rows) === self::ROW_LIMIT);

        $this->upsertDailySummary($dailyTotals);

        Log::info('GscService.fetchQueryPerformance', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Fetches the per-page rollup. Smaller table, used for the
     * "top landing pages" panel without scanning the query table.
     */
    public function fetchPagePerformance(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $request = new SearchAnalyticsQueryRequest();
        $request->setStartDate($start->toDateString());
        $request->setEndDate($end->toDateString());
        $request->setDimensions(['date', 'page']);
        $request->setType('web');
        $request->setDataState('final');
        $request->setRowLimit(self::ROW_LIMIT);

        $startRow = 0;
        $written = 0;

        do {
            $request->setStartRow($startRow);
            $response = $this->service->searchanalytics->query($this->siteUrl, $request);
            $rows = $response->getRows() ?? [];

            foreach ($rows as $row) {
                $keys = $row->getKeys();
                $date = $keys[0] ?? null;
                $page = $keys[1] ?? '';

                if ($date === null) {
                    continue;
                }

                GscPagePerformance::updateOrCreate(
                    ['date' => $date, 'row_hash' => md5($page)],
                    [
                        'page' => $page,
                        'clicks' => (int) $row->getClicks(),
                        'impressions' => (int) $row->getImpressions(),
                        'ctr' => (float) $row->getCtr(),
                        'position' => (float) $row->getPosition(),
                    ],
                );
                $written++;
            }

            $startRow += count($rows);
        } while (count($rows) === self::ROW_LIMIT);

        Log::info('GscService.fetchPagePerformance', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * @param  array<string,array{clicks:int,impressions:int,pos_sum:float,pos_n:int}>  $totals
     */
    private function upsertDailySummary(array $totals): void
    {
        foreach ($totals as $date => $row) {
            $impressions = $row['impressions'];
            GscDailySummary::updateOrCreate(
                ['date' => $date],
                [
                    'total_clicks' => $row['clicks'],
                    'total_impressions' => $impressions,
                    'avg_ctr' => $impressions > 0 ? round($row['clicks'] / $impressions, 7) : 0,
                    'avg_position' => $row['pos_n'] > 0 ? round($row['pos_sum'] / $row['pos_n'], 4) : 0,
                ],
            );
        }
    }
}
