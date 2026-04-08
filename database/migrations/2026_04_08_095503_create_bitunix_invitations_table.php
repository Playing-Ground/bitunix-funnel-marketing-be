<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bitunix_invitations — daily snapshot from `/partner/overview/v2/invitations`.
 * One row per (date, vip_code).
 *
 * `vip_code` is the internal partner-side code; `customer_vip_code` is the
 * promo string customers actually type (e.g. "BITUNIXBONUS", "TOPTOP"). The
 * link to UTM campaigns lives in `campaign_vip_mappings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitunix_invitations', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('vip_code', 50);
            $table->string('customer_vip_code', 100)->nullable();

            $table->decimal('exchange_self_ratio', 10, 4)->default(0);
            $table->decimal('exchange_sub_ratio', 10, 4)->default(0);
            $table->decimal('future_self_ratio', 10, 4)->default(0);
            $table->decimal('future_sub_ratio', 10, 4)->default(0);

            $table->unsignedInteger('registered_users')->nullable();
            $table->unsignedInteger('first_deposit_users')->default(0);
            $table->unsignedInteger('first_trade_users')->default(0);
            $table->unsignedInteger('click_users')->nullable();

            $table->decimal('trading_volume', 30, 10)->default(0);
            $table->decimal('my_commission', 30, 10)->default(0);

            $table->timestampsTz();

            $table->unique(['date', 'vip_code'], 'bitunix_inv_date_code_unique');
            $table->index(['date', 'trading_volume'], 'bitunix_inv_date_volume_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitunix_invitations');
    }
};
