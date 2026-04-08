<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gsc_page_performance — daily Search Console rows aggregated to the
 * (date × page) grain. Useful for the "top landing pages" view without
 * needing to scan the much larger query-level table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_page_performance', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->text('page');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 10, 7)->default(0);
            $table->decimal('position', 10, 4)->default(0);

            $table->char('row_hash', 32);
            $table->timestampsTz();

            $table->unique(['date', 'row_hash'], 'gsc_page_perf_date_hash_unique');
            $table->index(['date', 'clicks'], 'gsc_page_perf_date_clicks_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_page_performance');
    }
};
