<?php

namespace App\Console\Commands\Pipelines;

use App\Models\JobRun;
use App\Services\Google\Ga4BigQueryService;
use App\Services\Google\Ga4DataApiService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('martech:fetch-ga4
    {--days=90 : Fetch the last N days (ignored if --start/--end are given)}
    {--start= : Start date (YYYY-MM-DD), inclusive}
    {--end= : End date (YYYY-MM-DD), inclusive}
    {--pipeline=all : daily | acquisition | events | all}
    {--backend=data-api : data-api (default, queries the GA4 property directly) | bigquery (raw events, requires export to be running)}')]
#[Description('Pull GA4 metrics for a given date window into Postgres.')]
class FetchGa4Command extends Command
{
    public function handle(Ga4DataApiService $dataApi, Ga4BigQueryService $bigQuery): int
    {
        // GA4 acquisition over a 90d window can be 5-10k rows; the protobuf
        // message tree blows past PHP's 128M default on allocation.
        @ini_set('memory_limit', '1024M');
        $start = $this->option('start')
            ? CarbonImmutable::parse($this->option('start'))
            : CarbonImmutable::now('Asia/Jakarta')->subDays((int) $this->option('days'))->startOfDay();
        $end = $this->option('end')
            ? CarbonImmutable::parse($this->option('end'))
            : CarbonImmutable::now('Asia/Jakarta')->subDay()->startOfDay();

        $pipeline = (string) $this->option('pipeline');
        $runs = match ($pipeline) {
            'daily' => ['daily'],
            'acquisition' => ['acquisition'],
            'events' => ['events'],
            'all' => ['daily', 'acquisition', 'events'],
            default => $this->fail("unknown --pipeline=$pipeline"),
        };

        $backend = (string) $this->option('backend');
        $service = match ($backend) {
            'data-api' => $dataApi,
            'bigquery' => $bigQuery,
            default => $this->fail("unknown --backend=$backend (use data-api or bigquery)"),
        };

        $this->info("GA4 fetch [{$pipeline}] backend={$backend} window={$start->toDateString()} → {$end->toDateString()}");

        foreach ($runs as $name) {
            $jobName = "ga4.$name";
            $run = JobRun::create([
                'job_name' => $jobName,
                'status' => JobRun::STATUS_RUNNING,
                'started_at' => now(),
                'metadata' => [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'backend' => $backend,
                ],
            ]);

            try {
                $count = match ($name) {
                    'daily' => $service->fetchDailyMetrics($start, $end),
                    'acquisition' => $service->fetchAcquisition($start, $end),
                    'events' => $service->fetchEventCounts($start, $end),
                };

                $run->markCompleted($count);
                $this->line("  ✓ {$jobName}: {$count} rows");
            } catch (Throwable $e) {
                $run->markFailed($e->getMessage());
                $this->error("  ✗ {$jobName}: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
