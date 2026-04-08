<?php

namespace App\Console\Commands\Pipelines;

use App\Models\JobRun;
use App\Services\Attribution\ComputeAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('martech:compute-attribution
    {--days=7 : Compute the last N days (ignored if --start/--end given)}
    {--start= : Start date YYYY-MM-DD inclusive}
    {--end= : End date YYYY-MM-DD inclusive}')]
#[Description('Build attribution_daily rows by joining GSC + GA4 + Bitunix data.')]
class ComputeAttributionCommand extends Command
{
    public function handle(ComputeAttributionService $service): int
    {
        $end = $this->option('end')
            ? CarbonImmutable::parse($this->option('end'))->startOfDay()
            : CarbonImmutable::now('Asia/Jakarta')->subDay()->startOfDay();
        $start = $this->option('start')
            ? CarbonImmutable::parse($this->option('start'))->startOfDay()
            : $end->subDays((int) $this->option('days') - 1);

        $this->info("Compute attribution window=[{$start->toDateString()}, {$end->toDateString()}]");

        $run = JobRun::create([
            'job_name' => 'attribution.compute',
            'status' => JobRun::STATUS_RUNNING,
            'started_at' => now(),
            'metadata' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
        ]);

        try {
            $count = $service->compute($start, $end);
            $run->markCompleted($count);
            $this->line("  ✓ attribution.compute: {$count} rows");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $run->markFailed($e->getMessage());
            $this->error("  ✗ attribution.compute: ".$e->getMessage());

            return self::FAILURE;
        }
    }
}
