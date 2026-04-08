<?php

namespace App\Console\Commands\Pipelines;

use App\Models\JobRun;
use App\Services\Google\GscService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('martech:fetch-gsc
    {--days=7 : Fetch the last N days (ignored if --start/--end are given)}
    {--start= : Start date (YYYY-MM-DD), inclusive}
    {--end= : End date (YYYY-MM-DD), inclusive}
    {--pipeline=all : queries | pages | all}')]
#[Description('Pull Search Console rows for a given date window into Postgres.')]
class FetchGscCommand extends Command
{
    public function handle(GscService $gsc): int
    {
        // GSC reports lag ~2 days; offset the default window so we don't
        // chase a moving "first_incomplete_date" target.
        $start = $this->option('start')
            ? CarbonImmutable::parse($this->option('start'))
            : CarbonImmutable::now('Asia/Jakarta')->subDays((int) $this->option('days') + 2)->startOfDay();
        $end = $this->option('end')
            ? CarbonImmutable::parse($this->option('end'))
            : CarbonImmutable::now('Asia/Jakarta')->subDays(2)->startOfDay();

        $pipeline = (string) $this->option('pipeline');
        $runs = match ($pipeline) {
            'queries' => ['queries'],
            'pages' => ['pages'],
            'all' => ['queries', 'pages'],
            default => $this->fail("unknown --pipeline=$pipeline"),
        };

        $this->info("GSC fetch [{$pipeline}] window={$start->toDateString()} → {$end->toDateString()}");

        foreach ($runs as $name) {
            $jobName = "gsc.$name";
            $run = JobRun::create([
                'job_name' => $jobName,
                'status' => JobRun::STATUS_RUNNING,
                'started_at' => now(),
                'metadata' => [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
            ]);

            try {
                $count = match ($name) {
                    'queries' => $gsc->fetchQueryPerformance($start, $end),
                    'pages' => $gsc->fetchPagePerformance($start, $end),
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
