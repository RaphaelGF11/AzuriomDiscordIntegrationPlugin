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
        // The admin-visible "Journal" page's data source - see Support\PluginLog
        // for the only thing that writes here, and Models\LogEntry for reads.
        // discord_guild_id/discord_role_id/status are pulled out of context as
        // their own indexed columns (rather than left buried in the JSON blob)
        // specifically so the Roles page can cheaply ask "was there a recent
        // permission failure for this exact (guild, role) pair" on every load.
        Schema::create('discord_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->index();
            $table->string('message');
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('discord_guild_id')->nullable();
            $table->string('discord_role_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['discord_guild_id', 'discord_role_id', 'status'], 'discord_integration_logs_guild_role_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_integration_logs');
    }
};
