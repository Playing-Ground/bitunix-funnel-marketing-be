<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gsc_daily_summary — one row per day with site-wide totals.
 * Powers the headline KPI cards on the overview page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_daily_summary', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('total_clicks')->default(0);
            $table->unsignedInteger('total_impressions')->default(0);
            $table->decimal('avg_ctr', 10, 7)->default(0);
            $table->decimal('avg_position', 10, 4)->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_daily_summary');
    }
};
