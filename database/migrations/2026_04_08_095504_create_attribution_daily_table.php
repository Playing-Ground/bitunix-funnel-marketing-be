<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attribution_daily — the killer feature.
 *
 * Computed by ComputeAttributionJob (Phase 5) which joins:
 *   GSC click data → GA4 sessions (by utm_source/medium/campaign + date)
 *                  → Bitunix invitations (via campaign_vip_mappings.vip_code)
 *
 * One row per (date × source × medium × campaign). Powers the
 * `/attribution` and `/keyword-roi` dashboard pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('utm_source', 255)->default('(direct)');
            $table->string('utm_medium', 255)->default('(none)');
            $table->string('utm_campaign', 255)->default('(not set)');

            // From GSC
            $table->unsignedInteger('gsc_clicks')->default(0);
            $table->unsignedInteger('gsc_impressions')->default(0);
            $table->decimal('gsc_avg_position', 10, 4)->default(0);
            $table->decimal('gsc_avg_ctr', 10, 7)->default(0);

            // From GA4
            $table->unsignedInteger('ga4_users')->default(0);
            $table->unsignedInteger('ga4_sessions')->default(0);
            $table->unsignedInteger('ga4_engaged_sessions')->default(0);
            $table->unsignedInteger('ga4_conversions')->default(0);

            // From Bitunix (joined via vip_code → utm_campaign mapping)
            $table->unsignedInteger('bitunix_signups')->default(0);
            $table->unsignedInteger('bitunix_first_deposit')->default(0);
            $table->unsignedInteger('bitunix_first_trade')->default(0);
            $table->decimal('bitunix_commission', 30, 10)->default(0);
            $table->decimal('bitunix_trading_volume', 30, 10)->default(0);

            // Computed metrics — denormalized so the dashboard can sort cheaply
            $table->decimal('click_to_session_rate', 8, 5)->default(0);
            $table->decimal('session_to_signup_rate', 8, 5)->default(0);
            $table->decimal('clicks_to_signup_rate', 8, 5)->default(0);

            $table->char('row_hash', 32);
            $table->timestampsTz();

            $table->unique(['date', 'row_hash'], 'attribution_daily_date_hash_unique');
            $table->index(['date', 'bitunix_signups'], 'attribution_daily_date_signups_idx');
            $table->index(['date', 'bitunix_commission'], 'attribution_daily_date_commission_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_daily');
    }
};
