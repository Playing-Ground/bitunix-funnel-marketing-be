<?php

namespace App\Services\Google;

use App\Models\Ga4Acquisition;
use App\Models\Ga4DailyMetric;
use App\Models\Ga4EventSummary;
use Carbon\CarbonImmutable;
use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Illuminate\Support\Facades\Log;

/**
 * Ga4DataApiService — pulls daily aggregates from the GA4 Data API directly,
 * bypassing the BigQuery export.
 *
 * Use case: BigQuery export takes ~24 hours to start populating after the
 * link is created and billing enabled. The Data API queries the GA4 property
 * directly so we get yesterday's data immediately.
 *
 * Trade-offs vs BigQuery:
 *   + No 24h export lag, works the moment the SA has Viewer access
 *   + Simpler setup, no BigQuery permissions
 *   - Subject to GA4 Data API quotas (good for daily aggregates, not raw events)
 *   - No raw event-level joins (use BigQuery for those once available)
 *
 * Auth: same service account JSON as the BigQuery client. The SA email must
 * be added as a "Viewer" on the GA4 property in Property Access Management.
 */
class Ga4DataApiService
{
    private const SCOPES = ['https://www.googleapis.com/auth/analytics.readonly'];

    private readonly BetaAnalyticsDataClient $client;
    private readonly string $propertyId;
    private readonly string $property;

    public function __construct(GoogleCredentialsFactory $credentialsFactory)
    {
        $this->propertyId = (string) config('services.google.ga4_property_id');
        $this->property = "properties/{$this->propertyId}";

        $this->client = new BetaAnalyticsDataClient([
            'credentials' => $credentialsFactory->credentials(self::SCOPES),
            // Force REST transport — avoids the gRPC PHP extension dependency.
            'transport' => 'rest',
        ]);
    }

    public function ping(): array
    {
        $request = (new RunReportRequest())
            ->setProperty($this->property)
            ->setDimensions([new Dimension(['name' => 'date'])])
            ->setMetrics([new Metric(['name' => 'activeUsers'])])
            ->setDateRanges([
                new DateRange(['start_date' => 'yesterday', 'end_date' => 'yesterday']),
            ]);

        $response = $this->client->runReport($request);
        $row = null;
        foreach ($response->getRows() as $r) {
            $row = $r;
            break;
        }

        return [
            'ok' => true,
            'property_id' => $this->propertyId,
            'sample_users_yesterday' => $row?->getMetricValues()[0]?->getValue() ?? '0',
            'row_count' => $response->getRowCount(),
        ];
    }

    /**
     * Pulls daily-grain users / sessions / engaged sessions / page views and
     * upserts to ga4_daily_metrics.
     */
    public function fetchDailyMetrics(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $request = (new RunReportRequest())
            ->setProperty($this->property)
            ->setDimensions([new Dimension(['name' => 'date'])])
            ->setMetrics([
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'engagedSessions']),
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'engagementRate']),
            ])
            ->setDateRanges([
                new DateRange([
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ]),
            ])
            ->setOrderBys([
                (new OrderBy())->setDimension(
                    (new DimensionOrderBy())->setDimensionName('date'),
                ),
            ])
            ->setLimit(10000);

        $response = $this->client->runReport($request);

        $written = 0;
        foreach ($response->getRows() as $row) {
            $dimVals = $row->getDimensionValues();
            $metVals = $row->getMetricValues();
            // GA4 Data API returns date as YYYYMMDD; convert to YYYY-MM-DD
            $rawDate = $dimVals[0]->getValue();
            $date = substr($rawDate, 0, 4).'-'.substr($rawDate, 4, 2).'-'.substr($rawDate, 6, 2);

            Ga4DailyMetric::updateOrCreate(
                ['date' => $date],
                [
                    'users' => (int) $metVals[0]->getValue(),
                    'new_users' => (int) $metVals[1]->getValue(),
                    'sessions' => (int) $metVals[2]->getValue(),
                    'engaged_sessions' => (int) $metVals[3]->getValue(),
                    'page_views' => (int) $metVals[4]->getValue(),
                    'key_events' => 0,
                    'avg_session_duration_seconds' => (float) $metVals[5]->getValue(),
                    'engagement_rate' => (float) $metVals[6]->getValue(),
                ],
            );
            $written++;
        }

        Log::info('Ga4DataApiService.fetchDailyMetrics', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Pulls per-(date × source × medium × campaign) acquisition rows.
     */
    public function fetchAcquisition(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $request = (new RunReportRequest())
            ->setProperty($this->property)
            ->setDimensions([
                new Dimension(['name' => 'date']),
                new Dimension(['name' => 'sessionSource']),
                new Dimension(['name' => 'sessionMedium']),
                new Dimension(['name' => 'sessionCampaignName']),
            ])
            ->setMetrics([
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'engagedSessions']),
                new Metric(['name' => 'conversions']),
            ])
            ->setDateRanges([
                new DateRange([
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ]),
            ])
            ->setLimit(10000);

        $response = $this->client->runReport($request);

        $written = 0;
        foreach ($response->getRows() as $row) {
            $dim = $row->getDimensionValues();
            $met = $row->getMetricValues();

            $rawDate = $dim[0]->getValue();
            $date = substr($rawDate, 0, 4).'-'.substr($rawDate, 4, 2).'-'.substr($rawDate, 6, 2);

            $source = $dim[1]->getValue() ?: '(direct)';
            $medium = $dim[2]->getValue() ?: '(none)';
            $campaign = $dim[3]->getValue() ?: '(not set)';

            Ga4Acquisition::updateOrCreate(
                [
                    'date' => $date,
                    'row_hash' => md5("{$source}|{$medium}|{$campaign}|"),
                ],
                [
                    'source' => $source,
                    'medium' => $medium,
                    'campaign' => $campaign,
                    'source_platform' => null,
                    'users' => (int) $met[0]->getValue(),
                    'new_users' => (int) $met[1]->getValue(),
                    'sessions' => (int) $met[2]->getValue(),
                    'engaged_sessions' => (int) $met[3]->getValue(),
                    'conversions' => (int) $met[4]->getValue(),
                    'key_events' => (int) $met[4]->getValue(),
                ],
            );
            $written++;
        }

        Log::info('Ga4DataApiService.fetchAcquisition', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }

    /**
     * Pulls (date × event_name) counts and upserts to ga4_events_summary.
     */
    public function fetchEventCounts(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $request = (new RunReportRequest())
            ->setProperty($this->property)
            ->setDimensions([
                new Dimension(['name' => 'date']),
                new Dimension(['name' => 'eventName']),
            ])
            ->setMetrics([
                new Metric(['name' => 'eventCount']),
                new Metric(['name' => 'totalUsers']),
            ])
            ->setDateRanges([
                new DateRange([
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ]),
            ])
            ->setLimit(10000);

        $response = $this->client->runReport($request);

        $written = 0;
        foreach ($response->getRows() as $row) {
            $dim = $row->getDimensionValues();
            $met = $row->getMetricValues();

            $rawDate = $dim[0]->getValue();
            $date = substr($rawDate, 0, 4).'-'.substr($rawDate, 4, 2).'-'.substr($rawDate, 6, 2);
            $eventName = $dim[1]->getValue();

            Ga4EventSummary::updateOrCreate(
                ['date' => $date, 'event_name' => $eventName],
                [
                    'event_count' => (int) $met[0]->getValue(),
                    'users' => (int) $met[1]->getValue(),
                ],
            );
            $written++;
        }

        Log::info('Ga4DataApiService.fetchEventCounts', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $written,
        ]);

        return $written;
    }
}
