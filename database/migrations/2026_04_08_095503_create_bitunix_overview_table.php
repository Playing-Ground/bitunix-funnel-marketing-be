<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bitunix_overview — daily snapshot from `/partner/overview/v2/metrics`.
 *
 * Money fields use decimal(30,10) because the Bitunix API returns string
 * decimals to preserve precision; never store these as floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitunix_overview', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('registrations')->default(0);
            $table->unsignedInteger('first_deposit_users')->default(0);
            $table->unsignedInteger('first_trade_users')->default(0);
            $table->decimal('commission', 30, 10)->default(0);
            $table->decimal('fee', 30, 10)->default(0);
            $table->jsonb('raw')->nullable()
                ->comment('full original API response for audit/replay');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitunix_overview');
    }
};
