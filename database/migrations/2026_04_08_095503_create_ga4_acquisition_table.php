<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ga4_acquisition — daily traffic-source breakdown from GA4 BigQuery.
 *
 * Sourced from `session_traffic_source_last_click` (post-Jul 2024 typed
 * column), falling back to `collected_traffic_source` for older sessions.
 * Nulls are coalesced to '(direct)' / '(none)' / '(not set)' so the unique
 * constraint behaves predictably across upserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga4_acquisition', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('source', 255)->default('(direct)');
            $table->string('medium', 255)->default('(none)');
            $table->string('campaign', 255)->default('(not set)');
            $table->string('source_platform', 100)->nullable();

            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('key_events')->default(0);
            $table->unsignedInteger('conversions')->default(0);

            $table->char('row_hash', 32);
            $table->timestampsTz();

            $table->unique(['date', 'row_hash'], 'ga4_acq_date_hash_unique');
            $table->index(['date', 'sessions'], 'ga4_acq_date_sessions_idx');
            $table->index(['source', 'medium', 'campaign'], 'ga4_acq_smc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_acquisition');
    }
};
