<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ga4_events_summary — daily counts per GA4 event_name. Powers the events
 * page (sign_up, page_view, session_start, scroll, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga4_events_summary', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('event_name', 100);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->timestampsTz();

            $table->unique(['date', 'event_name'], 'ga4_events_date_name_unique');
            $table->index(['event_name', 'date'], 'ga4_events_name_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_events_summary');
    }
};
