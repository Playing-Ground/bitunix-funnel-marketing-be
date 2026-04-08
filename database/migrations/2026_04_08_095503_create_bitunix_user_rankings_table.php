<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bitunix_user_rankings — daily snapshot from `/partner/overview/v2/user_rankings`.
 * Top users in the partner team by trade volume; one row per (date, uid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitunix_user_rankings', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('uid');

            $table->decimal('trade_amount', 30, 10)->default(0);
            $table->decimal('fee', 30, 10)->default(0);
            $table->decimal('commission', 30, 10)->default(0);
            $table->decimal('deposit_amount', 30, 10)->default(0);
            $table->decimal('withdraw_amount', 30, 10)->default(0);
            $table->decimal('asset_balance', 30, 10)->default(0);
            $table->decimal('net_deposit_amount', 30, 10)->default(0);

            $table->timestampsTz();

            $table->unique(['date', 'uid'], 'bitunix_rankings_date_uid_unique');
            $table->index(['date', 'trade_amount'], 'bitunix_rankings_date_volume_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitunix_user_rankings');
    }
};
