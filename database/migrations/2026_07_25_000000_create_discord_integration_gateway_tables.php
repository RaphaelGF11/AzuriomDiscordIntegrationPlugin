<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gateway-fed snapshots of Discord data (guild list, and per-guild
        // roles/channels/members), written by the gateway daemon and read
        // by DiscordBotClient as a local alternative to REST calls while
        // the gateway is connected. A dedicated table rather than the Cache
        // facade on purpose: "php artisan cache:clear" is a routine admin
        // action (and part of this plugin's own deploy guidance) and must
        // not wipe gateway state, and a per-(guild, kind) row allows atomic
        // upserts of just the piece an event touched.
        Schema::create('discord_integration_gateway_cache', function (Blueprint $table) {
            $table->id();
            // Null for the guild-independent "guilds" list row.
            $table->string('guild_id')->nullable();
            // guilds | guild | roles | channels | members
            $table->string('kind');
            $table->longText('payload');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['guild_id', 'kind'], 'discord_integration_gateway_cache_unique');
        });

        // Single-row (id=1) live status of the gateway daemon, polled by the
        // admin Gateway page's indicator and used as the freshness gate for
        // the cache table above. Kept separate from the settings table (the
        // daemon writes here every few seconds - that must never invalidate
        // the settings cache) and from the cache rows (the freshness probe
        // stays one tiny indexed read).
        Schema::create('discord_integration_gateway_status', function (Blueprint $table) {
            $table->id();
            $table->boolean('connected')->default(false);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->unsignedInteger('guild_count')->default(0);
            $table->string('last_error')->nullable();
            $table->string('session_id')->nullable();
        });

        DB::table('discord_integration_gateway_status')->insert(['id' => 1, 'connected' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_integration_gateway_status');
        Schema::dropIfExists('discord_integration_gateway_cache');
    }
};
