<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bitunix_team_overview — daily aggregated team-level metrics from the
 * `/partner/teamOverview/*` endpoint family. Single row per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitunix_team_overview', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('nick_name', 255)->nullable();

            // Team composition
            $table->unsignedInteger('team_size')->default(0);
            $table->unsignedInteger('partner_size')->default(0);
            $table->unsignedInteger('direct_size')->default(0);
            $table->unsignedInteger('new_users')->default(0);

            // Money flow (decimal strings from API)
            $table->decimal('my_profit', 30, 10)->default(0);
            $table->decimal('total_deposit', 30, 10)->default(0);
            $table->decimal('total_withdraw', 30, 10)->default(0);
            $table->decimal('trade_amount', 30, 10)->default(0);
            $table->decimal('fee', 30, 10)->default(0);

            // Counts
            $table->unsignedInteger('deposits_numbers')->default(0);
            $table->unsignedInteger('withdraw_numbers')->default(0);

            // Last-activity timestamps from /teamOverview/overview
            $table->timestampTz('recent_register_time')->nullable();
            $table->timestampTz('recent_join_time')->nullable();
            $table->timestampTz('recent_trade_time')->nullable();
            $table->timestampTz('recent_deposit_time')->nullable();
            $table->timestampTz('recent_withdraw_time')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitunix_team_overview');
    }
};
