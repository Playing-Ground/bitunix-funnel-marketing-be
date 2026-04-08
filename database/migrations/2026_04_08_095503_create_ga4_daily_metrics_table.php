<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ga4_daily_metrics — one row per day with property-wide engagement metrics
 * pulled from the BigQuery export. Used by the headline KPIs and trend chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga4_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('key_events')->default(0)
                ->comment('GA4 "key events" are user-marked conversions');
            $table->decimal('avg_session_duration_seconds', 12, 2)->default(0);
            $table->decimal('engagement_rate', 8, 5)->default(0)
                ->comment('engaged_sessions / sessions');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_daily_metrics');
    }
};
