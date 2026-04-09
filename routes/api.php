<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Models\AttributionDaily;
use App\Models\BitunixInvitation;
use App\Models\BitunixOverview;
use App\Models\BitunixUserRanking;
use App\Models\Ga4Acquisition;
use App\Models\Ga4DailyMetric;
use App\Models\Ga4EventSummary;
use App\Models\GscDailySummary;
use App\Models\GscPagePerformance;
use App\Models\GscQueryPerformance;
use App\Models\IntegrationToken;
use App\Models\JobRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Bitunix MarTech dashboard
|--------------------------------------------------------------------------
*/

Route::get('/ping', fn (): JsonResponse => response()->json([
    'ok' => true,
    'service' => 'bitunix-martech-api',
    'time' => now()->toIso8601String(),
]));

// ===================================================================
// Auth — Sanctum token-based authentication
// ===================================================================
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // User management — admin only
    Route::middleware('admin')->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});

Route::middleware('api.key')->group(function (): void {
    Route::get('/me', fn (): JsonResponse => response()->json([
        'ok' => true,
        'authenticated' => true,
        'service' => 'bitunix-martech-api',
    ]));

    Route::prefix('v1')->group(function (): void {

        // ===================================================================
        // Helpers
        // ===================================================================

        // Default window: last 90 days. Can be overridden via ?days=N
        // or explicit ?start=YYYY-MM-DD&end=YYYY-MM-DD.
        // Timezone can be overridden via ?tz=Asia/Tokyo (default: Asia/Dubai).
        $window = function (Request $r): array {
            $tz = $r->query('tz', 'Asia/Dubai');
            try {
                new \DateTimeZone($tz);
            } catch (\Exception) {
                $tz = 'Asia/Dubai';
            }

            $end = $r->query('end')
                ? CarbonImmutable::parse($r->query('end'))->startOfDay()
                : CarbonImmutable::now($tz)->subDay()->startOfDay();
            $start = $r->query('start')
                ? CarbonImmutable::parse($r->query('start'))->startOfDay()
                : $end->subDays(((int) $r->query('days', 90)) - 1);

            return [$start, $end];
        };

        // Returns the previous-period window of equal length, immediately
        // preceding [$start, $end]. Used for delta comparison.
        $prevWindow = function (CarbonImmutable $start, CarbonImmutable $end): array {
            $days = $start->diffInDays($end) + 1;

            return [
                $start->subDays($days),
                $start->subDay(),
            ];
        };

        // Build a CASE expression that classifies a query as 'branded' or
        // 'non-branded' based on services.google.gsc_brand_terms.
        $brandCase = function (string $column = 'query'): string {
            $terms = (array) config('services.google.gsc_brand_terms', []);
            if (empty($terms)) {
                return "'non-branded'";
            }
            $or = collect($terms)->map(
                fn ($t) => "LOWER({$column}) LIKE '%".strtolower(addslashes($t))."%'",
            )->implode(' OR ');

            return "CASE WHEN {$or} THEN 'branded' ELSE 'non-branded' END";
        };

        // Compute percent change between current and previous numeric values.
        $delta = function (float|int|string|null $cur, float|int|string|null $prev): ?float {
            $c = (float) $cur;
            $p = (float) $prev;
            if ($p == 0.0) {
                return $c == 0.0 ? 0.0 : null;
            }

            return round((($c - $p) / abs($p)) * 100, 2);
        };

        // ===================================================================
        // Overview — top-of-dashboard KPIs with previous-period delta.
        // ===================================================================
        Route::get('/overview', function (Request $r) use ($window, $prevWindow, $delta) {
            [$start, $end] = $window($r);
            [$pStart, $pEnd] = $prevWindow($start, $end);

            $sumGsc = fn ($s, $e) => GscDailySummary::whereBetween('date', [$s, $e])
                ->selectRaw('COALESCE(SUM(total_clicks),0) clicks, COALESCE(SUM(total_impressions),0) impressions, COALESCE(AVG(avg_position),0) avg_position')
                ->first();

            $sumGa4 = fn ($s, $e) => Ga4DailyMetric::whereBetween('date', [$s, $e])
                ->selectRaw('COALESCE(SUM(users),0) users, COALESCE(SUM(sessions),0) sessions, COALESCE(SUM(engaged_sessions),0) engaged_sessions, COALESCE(SUM(key_events),0) key_events')
                ->first();

            $sumBitunix = fn ($s, $e) => BitunixOverview::whereBetween('date', [$s, $e])
                ->selectRaw("COALESCE(SUM(registrations),0) registrations, COALESCE(SUM(first_deposit_users),0) first_deposit_users, COALESCE(SUM(first_trade_users),0) first_trade_users, COALESCE(SUM(commission::numeric),0) commission, COALESCE(SUM(fee::numeric),0) fee")
                ->first();

            $g = $sumGsc($start, $end);
            $gp = $sumGsc($pStart, $pEnd);
            $a = $sumGa4($start, $end);
            $ap = $sumGa4($pStart, $pEnd);
            $b = $sumBitunix($start, $end);
            $bp = $sumBitunix($pStart, $pEnd);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'previous_window' => ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()],
                'gsc' => [
                    'clicks' => (int) $g->clicks,
                    'clicks_delta_pct' => $delta($g->clicks, $gp->clicks),
                    'impressions' => (int) $g->impressions,
                    'impressions_delta_pct' => $delta($g->impressions, $gp->impressions),
                    'avg_position' => round((float) $g->avg_position, 2),
                    'avg_position_delta_pct' => $delta($g->avg_position, $gp->avg_position),
                ],
                'ga4' => [
                    'users' => (int) $a->users,
                    'users_delta_pct' => $delta($a->users, $ap->users),
                    'sessions' => (int) $a->sessions,
                    'sessions_delta_pct' => $delta($a->sessions, $ap->sessions),
                    'engaged_sessions' => (int) $a->engaged_sessions,
                    'key_events' => (int) $a->key_events,
                ],
                'bitunix' => [
                    'registrations' => (int) $b->registrations,
                    'registrations_delta_pct' => $delta($b->registrations, $bp->registrations),
                    'first_deposit_users' => (int) $b->first_deposit_users,
                    'first_deposit_users_delta_pct' => $delta($b->first_deposit_users, $bp->first_deposit_users),
                    'first_trade_users' => (int) $b->first_trade_users,
                    'first_trade_users_delta_pct' => $delta($b->first_trade_users, $bp->first_trade_users),
                    'commission' => (string) $b->commission,
                    'fee' => (string) $b->fee,
                    'fee_delta_pct' => $delta($b->fee, $bp->fee),
                ],
            ]);
        });

        // ===================================================================
        // GSC — daily series + branded split + queries + pages + countries
        // ===================================================================

        Route::get('/gsc/daily', function (Request $r) use ($window) {
            [$start, $end] = $window($r);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => GscDailySummary::whereBetween('date', [$start, $end])
                    ->orderBy('date')
                    ->get(),
            ]);
        });

        // Branded vs non-branded KPI overview.
        Route::get('/gsc/branded-overview', function (Request $r) use ($window, $prevWindow, $delta, $brandCase) {
            [$start, $end] = $window($r);
            [$pStart, $pEnd] = $prevWindow($start, $end);

            $aggregate = function (CarbonImmutable $s, CarbonImmutable $e) use ($brandCase) {
                $case = $brandCase('query');

                return GscQueryPerformance::query()
                    ->whereBetween('date', [$s, $e])
                    ->selectRaw("{$case} AS bucket, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS avg_position")
                    ->groupBy('bucket')
                    ->get()
                    ->keyBy('bucket');
            };

            $cur = $aggregate($start, $end);
            $prev = $aggregate($pStart, $pEnd);

            $bucket = function (string $key) use ($cur, $prev, $delta) {
                $c = $cur->get($key);
                $p = $prev->get($key);
                $clicks = (int) ($c->clicks ?? 0);
                $impr = (int) ($c->impressions ?? 0);
                $ctr = $impr > 0 ? $clicks / $impr : 0;
                $pos = (float) ($c->avg_position ?? 0);

                $pClicks = (int) ($p->clicks ?? 0);
                $pImpr = (int) ($p->impressions ?? 0);
                $pCtr = $pImpr > 0 ? $pClicks / $pImpr : 0;
                $pPos = (float) ($p->avg_position ?? 0);

                return [
                    'clicks' => $clicks,
                    'clicks_delta_pct' => $delta($clicks, $pClicks),
                    'impressions' => $impr,
                    'impressions_delta_pct' => $delta($impr, $pImpr),
                    'ctr' => round($ctr, 6),
                    'ctr_delta_pct' => $delta($ctr, $pCtr),
                    'avg_position' => round($pos, 2),
                    'avg_position_delta_pct' => $delta($pos, $pPos),
                ];
            };

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'previous_window' => ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()],
                'branded' => $bucket('branded'),
                'non_branded' => $bucket('non-branded'),
            ]);
        });

        // Branded vs non-branded daily time-series for both periods.
        Route::get('/gsc/branded-timeseries', function (Request $r) use ($window, $prevWindow, $brandCase) {
            [$start, $end] = $window($r);
            [$pStart, $pEnd] = $prevWindow($start, $end);
            $case = $brandCase('query');

            $series = function (CarbonImmutable $s, CarbonImmutable $e) use ($case) {
                return GscQueryPerformance::query()
                    ->whereBetween('date', [$s, $e])
                    ->selectRaw("date, {$case} AS bucket, SUM(clicks) AS clicks, SUM(impressions) AS impressions")
                    ->groupBy('date', 'bucket')
                    ->orderBy('date')
                    ->get();
            };

            $cur = $series($start, $end);
            $prev = $series($pStart, $pEnd);

            // Build current rows
            $byDate = [];
            foreach ($cur as $row) {
                $key = (string) $row->date;
                $byDate[$key] ??= ['date' => $key, 'branded' => 0, 'non_branded' => 0, 'branded_prev' => 0, 'non_branded_prev' => 0];
                if ($row->bucket === 'branded') {
                    $byDate[$key]['branded'] = (int) $row->clicks;
                } else {
                    $byDate[$key]['non_branded'] = (int) $row->clicks;
                }
            }

            // Overlay previous period — index by day-offset from start so the
            // x-axis aligns visually.
            $offset = $start->diffInDays($pStart);
            foreach ($prev as $row) {
                $shifted = CarbonImmutable::parse($row->date)->addDays($offset)->toDateString();
                $byDate[$shifted] ??= ['date' => $shifted, 'branded' => 0, 'non_branded' => 0, 'branded_prev' => 0, 'non_branded_prev' => 0];
                if ($row->bucket === 'branded') {
                    $byDate[$shifted]['branded_prev'] = (int) $row->clicks;
                } else {
                    $byDate[$shifted]['non_branded_prev'] = (int) $row->clicks;
                }
            }

            ksort($byDate);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'previous_window' => ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()],
                'rows' => array_values($byDate),
            ]);
        });

        // Per-query report with previous-period delta. Filterable by ?bucket=branded|non-branded
        // and ?search=string for the search-input filter on the dashboard.
        Route::get('/gsc/queries', function (Request $r) use ($window, $prevWindow, $delta, $brandCase) {
            [$start, $end] = $window($r);
            [$pStart, $pEnd] = $prevWindow($start, $end);

            $bucket = $r->query('bucket');
            $search = trim((string) $r->query('search', ''));
            $limit = min((int) $r->query('limit', 100), 1000);

            $case = $brandCase('query');

            $build = function (CarbonImmutable $s, CarbonImmutable $e) use ($case) {
                return GscQueryPerformance::query()
                    ->whereBetween('date', [$s, $e])
                    ->selectRaw("query, {$case} AS bucket, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS avg_position")
                    ->groupBy('query');
            };

            $curQ = $build($start, $end);
            if ($bucket === 'branded' || $bucket === 'non-branded') {
                $curQ->havingRaw("{$case} = ?", [$bucket]);
            }
            if ($search !== '') {
                $curQ->where('query', 'ilike', '%'.$search.'%');
            }
            $cur = $curQ->orderByDesc('clicks')->limit($limit)->get();

            // Previous-period lookups by query
            $queries = $cur->pluck('query')->all();
            $prev = $build($pStart, $pEnd)->whereIn('query', $queries)->get()->keyBy('query');

            $rows = $cur->map(function ($row) use ($prev, $delta) {
                $p = $prev->get($row->query);
                $clicks = (int) $row->clicks;
                $impr = (int) $row->impressions;
                $ctr = $impr > 0 ? $clicks / $impr : 0;
                $pos = (float) $row->avg_position;

                $pClicks = (int) ($p->clicks ?? 0);
                $pImpr = (int) ($p->impressions ?? 0);
                $pCtr = $pImpr > 0 ? $pClicks / $pImpr : 0;
                $pPos = (float) ($p->avg_position ?? 0);

                return [
                    'query' => $row->query,
                    'bucket' => $row->bucket,
                    'clicks' => $clicks,
                    'clicks_delta_pct' => $delta($clicks, $pClicks),
                    'impressions' => $impr,
                    'impressions_delta_pct' => $delta($impr, $pImpr),
                    'ctr' => round($ctr, 6),
                    'ctr_delta_pct' => $delta($ctr, $pCtr),
                    'avg_position' => round($pos, 2),
                    'avg_position_delta_pct' => $delta($pos, $pPos),
                ];
            })->values();

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'previous_window' => ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()],
                'rows' => $rows,
            ]);
        });

        // Per-page report with previous-period delta + ?search filter.
        Route::get('/gsc/pages', function (Request $r) use ($window, $prevWindow, $delta) {
            [$start, $end] = $window($r);
            [$pStart, $pEnd] = $prevWindow($start, $end);

            $search = trim((string) $r->query('search', ''));
            $limit = min((int) $r->query('limit', 100), 1000);

            $build = function (CarbonImmutable $s, CarbonImmutable $e) {
                return GscPagePerformance::query()
                    ->whereBetween('date', [$s, $e])
                    ->selectRaw('page, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS avg_position')
                    ->groupBy('page');
            };

            $curQ = $build($start, $end);
            if ($search !== '') {
                $curQ->where('page', 'ilike', '%'.$search.'%');
            }
            $cur = $curQ->orderByDesc('clicks')->limit($limit)->get();
            $pages = $cur->pluck('page')->all();
            $prev = $build($pStart, $pEnd)->whereIn('page', $pages)->get()->keyBy('page');

            $rows = $cur->map(function ($row) use ($prev, $delta) {
                $p = $prev->get($row->page);
                $clicks = (int) $row->clicks;
                $impr = (int) $row->impressions;
                $ctr = $impr > 0 ? $clicks / $impr : 0;
                $pos = (float) $row->avg_position;

                $pClicks = (int) ($p->clicks ?? 0);
                $pImpr = (int) ($p->impressions ?? 0);
                $pCtr = $pImpr > 0 ? $pClicks / $pImpr : 0;
                $pPos = (float) ($p->avg_position ?? 0);

                return [
                    'page' => $row->page,
                    'clicks' => $clicks,
                    'clicks_delta_pct' => $delta($clicks, $pClicks),
                    'impressions' => $impr,
                    'impressions_delta_pct' => $delta($impr, $pImpr),
                    'ctr' => round($ctr, 6),
                    'ctr_delta_pct' => $delta($ctr, $pCtr),
                    'avg_position' => round($pos, 2),
                    'avg_position_delta_pct' => $delta($pos, $pPos),
                ];
            })->values();

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'previous_window' => ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()],
                'rows' => $rows,
            ]);
        });

        // Country breakdown — average position + clicks per ISO-3 country.
        Route::get('/gsc/countries', function (Request $r) use ($window) {
            [$start, $end] = $window($r);
            $limit = min((int) $r->query('limit', 30), 200);

            $rows = GscQueryPerformance::query()
                ->whereBetween('date', [$start, $end])
                ->selectRaw('country, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS avg_position')
                ->groupBy('country')
                ->orderByDesc('clicks')
                ->limit($limit)
                ->get();

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => $rows,
            ]);
        });

        // ===================================================================
        // GA4 endpoints
        // ===================================================================
        Route::get('/ga4/daily', function (Request $r) use ($window) {
            [$start, $end] = $window($r);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => Ga4DailyMetric::whereBetween('date', [$start, $end])
                    ->orderBy('date')
                    ->get(),
            ]);
        });

        Route::get('/ga4/acquisition', function (Request $r) use ($window) {
            [$start, $end] = $window($r);
            $limit = min((int) $r->query('limit', 100), 500);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => Ga4Acquisition::whereBetween('date', [$start, $end])
                    ->selectRaw('source, medium, campaign, SUM(users) AS users, SUM(sessions) AS sessions, SUM(engaged_sessions) AS engaged_sessions, SUM(conversions) AS conversions')
                    ->groupBy('source', 'medium', 'campaign')
                    ->orderByDesc('sessions')
                    ->limit($limit)
                    ->get(),
            ]);
        });

        Route::get('/ga4/events', function (Request $r) use ($window) {
            [$start, $end] = $window($r);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => Ga4EventSummary::whereBetween('date', [$start, $end])
                    ->selectRaw('event_name, SUM(event_count) AS event_count, SUM(users) AS users')
                    ->groupBy('event_name')
                    ->orderByDesc('event_count')
                    ->get(),
            ]);
        });

        // ===================================================================
        // Bitunix Partner endpoints
        // ===================================================================
        Route::get('/bitunix/overview', function (Request $r) use ($window) {
            [$start, $end] = $window($r);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => BitunixOverview::whereBetween('date', [$start, $end])
                    ->orderBy('date')
                    ->get(),
            ]);
        });

        Route::get('/bitunix/invitations', function (Request $r) use ($window) {
            [$start, $end] = $window($r);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => BitunixInvitation::whereBetween('date', [$start, $end])
                    ->orderBy('date', 'desc')
                    ->orderByRaw('trading_volume::numeric DESC')
                    ->get(),
            ]);
        });

        Route::get('/bitunix/user-rankings', function (Request $r) use ($window) {
            [$start, $end] = $window($r);
            $limit = min((int) $r->query('limit', 20), 100);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => BitunixUserRanking::whereBetween('date', [$start, $end])
                    ->orderByRaw('trade_amount::numeric DESC')
                    ->limit($limit)
                    ->get(),
            ]);
        });

        // ===================================================================
        // Attribution
        // ===================================================================
        Route::get('/attribution', function (Request $r) use ($window) {
            [$start, $end] = $window($r);

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'rows' => AttributionDaily::whereBetween('date', [$start, $end])
                    ->selectRaw('
                        utm_source, utm_medium, utm_campaign,
                        SUM(gsc_clicks) AS gsc_clicks,
                        SUM(gsc_impressions) AS gsc_impressions,
                        SUM(ga4_users) AS ga4_users,
                        SUM(ga4_sessions) AS ga4_sessions,
                        SUM(ga4_conversions) AS ga4_conversions,
                        SUM(bitunix_signups) AS bitunix_signups,
                        SUM(bitunix_first_deposit) AS bitunix_first_deposit,
                        SUM(bitunix_first_trade) AS bitunix_first_trade,
                        SUM(bitunix_trading_volume::numeric) AS bitunix_trading_volume,
                        SUM(bitunix_commission::numeric) AS bitunix_commission
                    ')
                    ->groupBy('utm_source', 'utm_medium', 'utm_campaign')
                    ->orderByRaw('SUM(bitunix_trading_volume::numeric) DESC')
                    ->get(),
            ]);
        });

        // ===================================================================
        // Funnel — unified GSC → GA4 → Bitunix funnel with conversion rates
        // ===================================================================
        Route::get('/funnel', function (Request $r) use ($window, $prevWindow, $delta) {
            [$start, $end] = $window($r);
            [$pStart, $pEnd] = $prevWindow($start, $end);

            // GSC aggregates
            $gscSum = fn ($s, $e) => GscDailySummary::whereBetween('date', [$s, $e])
                ->selectRaw('COALESCE(SUM(total_clicks),0) clicks, COALESCE(SUM(total_impressions),0) impressions')
                ->first();

            // GA4 aggregates
            $ga4Sum = fn ($s, $e) => Ga4DailyMetric::whereBetween('date', [$s, $e])
                ->selectRaw('COALESCE(SUM(users),0) users, COALESCE(SUM(sessions),0) sessions, COALESCE(SUM(engaged_sessions),0) engaged_sessions, COALESCE(SUM(page_views),0) page_views, COALESCE(SUM(key_events),0) key_events')
                ->first();

            // GA4 events breakdown
            $ga4Events = Ga4EventSummary::whereBetween('date', [$start, $end])
                ->selectRaw('event_name, SUM(event_count) AS event_count, SUM(users) AS users')
                ->groupBy('event_name')
                ->orderByDesc('event_count')
                ->get();

            // Bitunix aggregates
            $bxSum = fn ($s, $e) => BitunixOverview::whereBetween('date', [$s, $e])
                ->selectRaw("COALESCE(SUM(registrations),0) registrations, COALESCE(SUM(first_deposit_users),0) first_deposit_users, COALESCE(SUM(first_trade_users),0) first_trade_users, COALESCE(SUM(commission::numeric),0) commission, COALESCE(SUM(fee::numeric),0) fee")
                ->first();

            $gsc = $gscSum($start, $end);
            $gscP = $gscSum($pStart, $pEnd);
            $ga4 = $ga4Sum($start, $end);
            $ga4P = $ga4Sum($pStart, $pEnd);
            $bx = $bxSum($start, $end);
            $bxP = $bxSum($pStart, $pEnd);

            // Daily time series for the funnel chart
            $dailyGsc = GscDailySummary::whereBetween('date', [$start, $end])
                ->select('date', 'total_clicks', 'total_impressions')
                ->orderBy('date')
                ->get()
                ->keyBy(fn ($r) => $r->date->toDateString());

            $dailyGa4 = Ga4DailyMetric::whereBetween('date', [$start, $end])
                ->select('date', 'users', 'sessions', 'engaged_sessions', 'page_views', 'key_events')
                ->orderBy('date')
                ->get()
                ->keyBy(fn ($r) => $r->date->toDateString());

            $dailyBx = BitunixOverview::whereBetween('date', [$start, $end])
                ->select('date', 'registrations', 'first_deposit_users', 'first_trade_users')
                ->orderBy('date')
                ->get()
                ->keyBy(fn ($r) => $r->date->toDateString());

            // Merge daily data
            $allDates = collect()
                ->merge($dailyGsc->keys())
                ->merge($dailyGa4->keys())
                ->merge($dailyBx->keys())
                ->unique()
                ->sort()
                ->values();

            $daily = $allDates->map(function ($date) use ($dailyGsc, $dailyGa4, $dailyBx) {
                $g = $dailyGsc->get($date);
                $a = $dailyGa4->get($date);
                $b = $dailyBx->get($date);

                return [
                    'date' => $date,
                    'impressions' => (int) ($g->total_impressions ?? 0),
                    'clicks' => (int) ($g->total_clicks ?? 0),
                    'users' => (int) ($a->users ?? 0),
                    'sessions' => (int) ($a->sessions ?? 0),
                    'engaged_sessions' => (int) ($a->engaged_sessions ?? 0),
                    'page_views' => (int) ($a->page_views ?? 0),
                    'key_events' => (int) ($a->key_events ?? 0),
                    'registrations' => (int) ($b->registrations ?? 0),
                    'first_deposit' => (int) ($b->first_deposit_users ?? 0),
                    'first_trade' => (int) ($b->first_trade_users ?? 0),
                ];
            });

            // Build funnel stages
            $impressions = (int) $gsc->impressions;
            $clicks = (int) $gsc->clicks;
            $sessions = (int) $ga4->sessions;
            $engagedSessions = (int) $ga4->engaged_sessions;
            $pageViews = (int) $ga4->page_views;
            $keyEvents = (int) $ga4->key_events;
            $registrations = (int) $bx->registrations;
            $firstDeposit = (int) $bx->first_deposit_users;
            $firstTrade = (int) $bx->first_trade_users;

            $rate = fn ($cur, $prev) => $prev > 0 ? round($cur / $prev, 6) : null;

            $stages = [
                ['stage' => 'Impressions', 'source' => 'GSC', 'value' => $impressions, 'delta_pct' => $delta($impressions, (int) $gscP->impressions), 'conversion_rate' => null],
                ['stage' => 'Clicks', 'source' => 'GSC', 'value' => $clicks, 'delta_pct' => $delta($clicks, (int) $gscP->clicks), 'conversion_rate' => $rate($clicks, $impressions)],
                ['stage' => 'Sessions', 'source' => 'GA4', 'value' => $sessions, 'delta_pct' => $delta($sessions, (int) $ga4P->sessions), 'conversion_rate' => $rate($sessions, $clicks)],
                ['stage' => 'Engaged Sessions', 'source' => 'GA4', 'value' => $engagedSessions, 'delta_pct' => $delta($engagedSessions, (int) $ga4P->engaged_sessions), 'conversion_rate' => $rate($engagedSessions, $sessions)],
                ['stage' => 'Page Views', 'source' => 'GA4', 'value' => $pageViews, 'delta_pct' => $delta($pageViews, (int) $ga4P->page_views), 'conversion_rate' => $rate($pageViews, $sessions)],
                ['stage' => 'Key Events', 'source' => 'GA4', 'value' => $keyEvents, 'delta_pct' => $delta($keyEvents, (int) $ga4P->key_events), 'conversion_rate' => $rate($keyEvents, $sessions)],
                ['stage' => 'Registrations', 'source' => 'Bitunix', 'value' => $registrations, 'delta_pct' => $delta($registrations, (int) $bxP->registrations), 'conversion_rate' => $rate($registrations, $sessions)],
                ['stage' => 'First Deposit', 'source' => 'Bitunix', 'value' => $firstDeposit, 'delta_pct' => $delta($firstDeposit, (int) $bxP->first_deposit_users), 'conversion_rate' => $rate($firstDeposit, $registrations)],
                ['stage' => 'First Trade', 'source' => 'Bitunix', 'value' => $firstTrade, 'delta_pct' => $delta($firstTrade, (int) $bxP->first_trade_users), 'conversion_rate' => $rate($firstTrade, $firstDeposit)],
            ];

            return response()->json([
                'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'previous_window' => ['start' => $pStart->toDateString(), 'end' => $pEnd->toDateString()],
                'stages' => $stages,
                'events' => $ga4Events,
                'daily' => $daily,
                'totals' => [
                    'fee' => (string) $bx->fee,
                    'commission' => (string) $bx->commission,
                    'fee_delta_pct' => $delta($bx->fee, $bxP->fee),
                ],
            ]);
        });

        // ===================================================================
        // Settings / ops
        // ===================================================================
        Route::get('/settings/integrations', function () {
            $tokens = IntegrationToken::all()->map(fn (IntegrationToken $t) => [
                'service' => $t->service,
                'expires_at' => $t->expires_at?->toIso8601String(),
                'last_refreshed_at' => $t->last_refreshed_at?->toIso8601String(),
                'is_expired' => $t->isExpired(),
                'expires_in_days' => $t->expires_at ? (int) round(now()->diffInDays($t->expires_at, false)) : null,
                'metadata' => $t->metadata,
            ]);

            return response()->json(['tokens' => $tokens]);
        });

        Route::get('/settings/job-runs', function (Request $r) {
            $limit = min((int) $r->query('limit', 50), 200);

            return response()->json([
                'rows' => JobRun::orderByDesc('started_at')->limit($limit)->get(),
            ]);
        });
    });
});
