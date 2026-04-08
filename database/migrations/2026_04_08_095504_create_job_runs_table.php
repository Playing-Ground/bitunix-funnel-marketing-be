<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * job_runs — audit log for ETL job executions. Each scheduled fetch
 * (GSC, GA4, Bitunix, attribution) writes a row here so the dashboard
 * can show "last successful run" + error tail per pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 100);
            $table->string('status', 20)
                ->comment('pending | running | completed | failed');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('records_processed')->default(0);
            $table->text('error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['job_name', 'started_at'], 'job_runs_name_started_idx');
            $table->index(['status', 'started_at'], 'job_runs_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
