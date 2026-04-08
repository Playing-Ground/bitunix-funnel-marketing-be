<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_tokens — secure storage for third-party tokens that need to be
 * rotated by hand. Used initially for the Bitunix Partner JWT (180-day TTL,
 * refreshed manually via the /settings page).
 *
 * The `token` column is encrypted at the model layer via the `encrypted` cast,
 * so the raw text never lives on disk in plain form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('service', 50)->unique()
                ->comment('e.g. bitunix_partner');
            $table->text('token')
                ->comment('encrypted via the Eloquent encrypted cast');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->jsonb('metadata')->nullable()
                ->comment('e.g. JWT payload uuid/key for traceability');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tokens');
    }
};
