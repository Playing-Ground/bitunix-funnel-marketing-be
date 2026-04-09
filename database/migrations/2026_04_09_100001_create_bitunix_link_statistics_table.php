<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitunix_link_statistics', function (Blueprint $table) {
            $table->id();
            $table->string('vip_code', 30);
            $table->string('customer_vip_code', 100)->nullable();
            $table->unsignedInteger('click_users')->default(0);
            $table->unsignedInteger('registered_users')->default(0);
            $table->unsignedInteger('first_deposit_users')->default(0);
            $table->unsignedInteger('first_trade_users')->default(0);
            $table->unsignedInteger('transaction_users')->default(0);
            $table->unsignedInteger('deposit_users')->default(0);
            $table->string('trade_amount', 50)->default('0');
            $table->timestamp('created_at_bitunix')->nullable();
            $table->timestamp('latest_registration_time')->nullable();
            $table->timestamps();

            $table->unique('vip_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitunix_link_statistics');
    }
};
