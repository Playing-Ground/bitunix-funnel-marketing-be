<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campaign_vip_mappings — admin-editable table that links a Bitunix VIP code
 * (e.g. "TOPTOP", "BITUNIXBONUS") to one or more UTM tuples used in marketing.
 *
 * The attribution job uses this to roll Bitunix signups up against GA4
 * sessions and GSC clicks. Edit via the /settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_vip_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('vip_code', 50)->unique()
                ->comment('matches bitunix_invitations.vip_code');
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_vip_mappings');
    }
};
