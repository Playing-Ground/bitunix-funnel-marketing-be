<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Bitunix MarTech ETL schedule
|--------------------------------------------------------------------------
|
| All times are Asia/Jakarta (set via APP_TIMEZONE in .env). Spread the
| pipelines across the early-morning window so they don't compete for the
| same Postgres connection or Google API quota.
|
| GSC has a ~2-day reporting lag — its command default already offsets
| start/end by 2 days. GA4 BigQuery exports daily by 04:00 PT, which is
| ~19:00 Jakarta the day before, so 02:30 Jakarta is safe.
|
*/

Schedule::command('martech:fetch-gsc --days=7')
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('martech:fetch-ga4 --days=7')
    ->dailyAt('02:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('martech:fetch-bitunix --days=7')
    ->dailyAt('03:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('martech:compute-attribution --days=7')
    ->dailyAt('03:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
