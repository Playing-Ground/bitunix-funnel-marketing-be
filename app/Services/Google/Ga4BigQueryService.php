<?php

namespace App\Services\Google;

use App\Models\Ga4Acquisition;
use App\Models\Ga4DailyMetric;
use App\Models\Ga4EventSummary;
use Carbon\CarbonImmutable;
use Google\Cloud\BigQuery\BigQueryClient;
use Illuminate\Support\Facades\Log;

/**
 * GA4 BigQuery service — pulls daily aggregates from the GA4 export dataset
 * and upserts them into the local Postgres tables.
 *
 * The dataset path follows the standard GA4 export convention:
 *   `{project_id}.analytics_{property_id}.events_*`
 *
 * Date range queries always use `_TABLE_SUFFIX BETWEEN ...` so the planner
 * prunes irrelevant daily shards. The service writes to:
 *   - ga4_daily_metrics
 *   - ga4_acquisition
 *   - ga4_events_summary
 *
 * Sessions are computed as `(user_pseudo_id, ga_session_id)` per the standard
 * GA4 BigQuery cookbook. Engaged sessions are those where the
 * `session_engaged` event_param string equals "1".
 */
class Ga4BigQueryService
{
    /** GA4 BigQuery exports use US scope by default. */
    private const QUERY_SCOPES = [
        'https://www.googleapis.com/auth/bigquery.readonly',
    ];

    private readonly BigQueryClient $client;
    private readonly string $projectId;
    private readonly string $dataset;
    private readonly string $tableRef;

    public function __construct(GoogleCredentialsFactory $credentialsFactory)
    {
        $this->projectId = (string) config('services.google.ga4_project_id');
        $this->dataset = (string) config('services.google.ga4_dataset');

        $this->client = new BigQueryClient([
            'projectId' => $this->projectId,
            'credentialsFetcher' => $credentialsFactory->credentials(self::QUERY_SCOPES),
        ]);

        $this->tableRef = "`{$this->projectId}.{$this->dataset}.events_*`";
    }

    /**
     * Quick reachability check used by the /settings status panel.
     */
    public function ping(): array
    {
        $sql = <<<'SQL'
        SELECT 1 AS ok, CURRENT_TIMESTAMP() AS server_time
        SQL;

        $job = $this->client->query($sql);
        foreach ($this->client->runQuery($job) as $row) {
            return [
                'ok' => true,
                'server_time' => (string) $row['server_time'],
                'project_id' => $this->projectId,
                'dataset' => $this->dataset,
            ];
        }

        return ['ok' => false, 'project_id' => $this->projectId];
    }

    /**
     * Fetches daily-grain metrics for the given date window and upserts.
     * Returns the number of rows written.
     */
    public function fetchDailyMetrics(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $sql = <<<SQL
        WITH base AS (
          SELECT
            PARSE_DATE('%Y%m%d', event_date) AS day,
            user_pseudo_id,
            (SELECT value.int_value    FROM UNNEST(event_params) WHERE key = 'ga_session_id')   AS session_id,
            (SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'session_engaged') AS session_engaged,
            event_name
          FROM {$this->tableRef}
          WHERE _TABLE_SUFFIX BETWEEN @start AND @end
        )
        SELECT
          day,
          COUNT(DISTINCT user_pseudo_id)                                                            AS users,
          COUNT(DISTINCT IF(event_name = 'first_visit', user_pseudo_id, NULL))                       AS new_users,
          COUNT(DISTINCT CONCAT(user_pseudo_id, CAST(IFNULL(session_id, 0) AS STRING)))              AS sessions,
          COUNT(DISTINCT IF(session_engaged = '1',
                            CONCAT(user_pseudo_id, CAST(IFNULL(session_id, 0) AS STRING)), NULL))   AS engaged_sessions,
          COUNTIF(event_name = 'page_view')                                                          AS page_views
        FROM base
        WHERE session_id IS NOT NULL
        GROUP BY day
        ORDER BY day
        SQL;

        $job = $this->client->query($sql)->parameters([
            'start' => $start->format('Ymd'),
            'end' => $end->format('Ymd'),
        ]);

        $written = 0;
        foreach ($this->client->runQuery($job) as $row) {
            $sessions = (int) $row['sessions'];
            $engaged = (int) $row['engaged_sessions'];

            Ga4DailyMetric::updateOrCreate(
                ['date' => (string) $row['day']],
                [
                    'users' => (int) $row['users'],
                    'new_users' => (int) $row['new_users'],
                    'sessions' => $sessions,
                    'engaged_sessions' => $engaged,
                    'page_views' => (int) $row['page_views'],
                    'key_events' => 0, // populated by fetchEventCounts when sign_up etc. configured
                    'avg_session_duration_seconds' => 0,
                    'engagement_rate' => $sessions > 0 ? round($engaged / $sessions, 5) : 0,
                ],
            );
            $written++;
        }

        Log::info('Ga4BigQueryService.fetchDailyMetrics', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Fetches traffic-source breakdown using session_traffic_source_last_click
     * (the post-Jul 2024 typed column) with a fallback to collected_traffic_source.
     */
    public function fetchAcquisition(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $sql = <<<SQL
        WITH base AS (
          SELECT
            PARSE_DATE('%Y%m%d', event_date) AS day,
            user_pseudo_id,
            (SELECT value.int_value    FROM UNNEST(event_params) WHERE key = 'ga_session_id')   AS session_id,
            (SELECT value.string_value FROM UNNEST(event_params) WHERE key = 'session_engaged') AS session_engaged,
            event_name,
            COALESCE(session_traffic_source_last_click.manual_campaign.source,
                     collected_traffic_source.manual_source, '(direct)') AS source,
            COALESCE(session_traffic_source_last_click.manual_campaign.medium,
                     collected_traffic_source.manual_medium, '(none)')   AS medium,
            COALESCE(session_traffic_source_last_click.manual_campaign.campaign_name,
                     collected_traffic_source.manual_campaign_name, '(not set)') AS campaign,
            session_traffic_source_last_click.manual_campaign.source_platform AS source_platform
          FROM {$this->tableRef}
          WHERE _TABLE_SUFFIX BETWEEN @start AND @end
        )
        SELECT
          day, source, medium, campaign, source_platform,
          COUNT(DISTINCT user_pseudo_id)                                                          AS users,
          COUNT(DISTINCT IF(event_name = 'first_visit', user_pseudo_id, NULL))                     AS new_users,
          COUNT(DISTINCT CONCAT(user_pseudo_id, CAST(IFNULL(session_id, 0) AS STRING)))            AS sessions,
          COUNT(DISTINCT IF(session_engaged = '1',
                            CONCAT(user_pseudo_id, CAST(IFNULL(session_id, 0) AS STRING)), NULL)) AS engaged_sessions,
          COUNTIF(event_name IN ('sign_up','purchase','generate_lead'))                            AS conversions
        FROM base
        WHERE session_id IS NOT NULL
        GROUP BY day, source, medium, campaign, source_platform
        ORDER BY day, sessions DESC
        SQL;

        $job = $this->client->query($sql)->parameters([
            'start' => $start->format('Ymd'),
            'end' => $end->format('Ymd'),
        ]);

        $written = 0;
        foreach ($this->client->runQuery($job) as $row) {
            $source = (string) ($row['source'] ?? '(direct)');
            $medium = (string) ($row['medium'] ?? '(none)');
            $campaign = (string) ($row['campaign'] ?? '(not set)');
            $platform = $row['source_platform'] ?? null;

            Ga4Acquisition::updateOrCreate(
                [
                    'date' => (string) $row['day'],
                    'row_hash' => md5("{$source}|{$medium}|{$campaign}|{$platform}"),
                ],
                [
                    'source' => $source,
                    'medium' => $medium,
                    'campaign' => $campaign,
                    'source_platform' => $platform,
                    'users' => (int) $row['users'],
                    'new_users' => (int) $row['new_users'],
                    'sessions' => (int) $row['sessions'],
                    'engaged_sessions' => (int) $row['engaged_sessions'],
                    'conversions' => (int) $row['conversions'],
                    'key_events' => (int) $row['conversions'],
                ],
            );
            $written++;
        }

        Log::info('Ga4BigQueryService.fetchAcquisition', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Counts events per (date, event_name).
     */
    public function fetchEventCounts(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $sql = <<<SQL
        SELECT
          PARSE_DATE('%Y%m%d', event_date)            AS day,
          event_name,
          COUNT(*)                                    AS event_count,
          COUNT(DISTINCT user_pseudo_id)              AS users
        FROM {$this->tableRef}
        WHERE _TABLE_SUFFIX BETWEEN @start AND @end
        GROUP BY day, event_name
        ORDER BY day, event_count DESC
        SQL;

        $job = $this->client->query($sql)->parameters([
            'start' => $start->format('Ymd'),
            'end' => $end->format('Ymd'),
        ]);

        $written = 0;
        foreach ($this->client->runQuery($job) as $row) {
            Ga4EventSummary::updateOrCreate(
                [
                    'date' => (string) $row['day'],
                    'event_name' => (string) $row['event_name'],
                ],
                [
                    'event_count' => (int) $row['event_count'],
                    'users' => (int) $row['users'],
                ],
            );
            $written++;
        }

        Log::info('Ga4BigQueryService.fetchEventCounts', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }
}
