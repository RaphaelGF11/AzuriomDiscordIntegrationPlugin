<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // An admin-declared "this site account is this Discord user" link
        // (see Admin\LinkController), made without any OAuth proof from the
        // Discord user themselves. Kept separate from discord_accounts (which
        // implies a real OAuth exchange, tokens usable for the "guilds.join"
        // auto-add and refreshing cached info) - promoted into a real
        // discord_accounts row, and deleted, the moment that Discord user
        // actually completes a real OAuth round-trip (see
        // DiscordIntegrationController::callback()).
        Schema::create('discord_integration_pending_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('discord_user_id')->unique();
            $table->string('discord_username')->nullable();
            $table->string('discord_avatar')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_integration_pending_links');
    }
};
