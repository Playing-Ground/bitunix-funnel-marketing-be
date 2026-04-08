<?php

namespace App\Console\Commands\Pipelines;

use App\Models\JobRun;
use App\Services\Bitunix\BitunixPartnerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('martech:fetch-bitunix
    {--days=7 : Fetch the last N days (ignored if --start/--end are given)}
    {--start= : Start date YYYY-MM-DD inclusive (UTC)}
    {--end= : End date YYYY-MM-DD inclusive (UTC); the API uses [start,end+1) internally}
    {--pipeline=all : overview | invitations | rankings | all}')]
#[Description('Pull Bitunix Partner data into Postgres for the given window.')]
class FetchBitunixCommand extends Command
{
    public function handle(BitunixPartnerService $bitunix): int
    {
        // Bitunix windows are in UTC midnight half-open intervals.
        // For "yesterday and 6 days back" we want [today-7 00:00Z, today 00:00Z).
        $end = $this->option('end')
            ? CarbonImmutable::parse($this->option('end'), 'UTC')->startOfDay()->addDay()
            : CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->option('start')
            ? CarbonImmutable::parse($this->option('start'), 'UTC')->startOfDay()
            : $end->subDays((int) $this->option('days'));

        $pipeline = (string) $this->option('pipeline');
        $runs = match ($pipeline) {
            'overview' => ['overview'],
            'invitations' => ['invitations'],
            'rankings' => ['rankings'],
            'all' => ['overview', 'invitations', 'rankings'],
            default => $this->fail("unknown --pipeline=$pipeline"),
        };

        $this->info("Bitunix fetch [{$pipeline}] window=[{$start->toIso8601String()}, {$end->toIso8601String()})");

        foreach ($runs as $name) {
            $jobName = "bitunix.$name";
            $run = JobRun::create([
                'job_name' => $jobName,
                'status' => JobRun::STATUS_RUNNING,
                'started_at' => now(),
                'metadata' => [
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                ],
            ]);

            try {
                $count = match ($name) {
                    'overview' => $bitunix->fetchOverview($start, $end),
                    'invitations' => $bitunix->fetchInvitations($start, $end),
                    'rankings' => $bitunix->fetchUserRankings($start, $end),
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
