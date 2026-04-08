<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gsc_query_performance — daily Search Console rows at the
 * (date × query × page × country × device × searchAppearance) grain.
 *
 * `row_hash` is the MD5 of the dimension tuple and lets us define a UNIQUE
 * constraint that fits inside Postgres' B-tree size limit even when query
 * strings or page URLs are long. Use it for idempotent upserts from the ETL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_query_performance', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->text('query');
            $table->text('page');
            $table->string('country', 3);
            $table->string('device', 20);
            $table->string('search_appearance', 50)->nullable();

            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 10, 7)->default(0);
            $table->decimal('position', 10, 4)->default(0);

            $table->string('data_state', 20)->default('final')
                ->comment('final | all | hourly_all');

            $table->char('row_hash', 32);
            $table->timestampsTz();

            $table->unique(['date', 'row_hash'], 'gsc_query_perf_date_hash_unique');
            $table->index(['date', 'clicks'], 'gsc_query_perf_date_clicks_idx');
            $table->index(['date', 'impressions'], 'gsc_query_perf_date_impressions_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_query_performance');
    }
};
