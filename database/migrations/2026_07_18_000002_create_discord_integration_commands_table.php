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
        // An admin-defined Discord slash command: its parameters (options),
        // who is allowed to run it (prerequisites), and what happens when it
        // runs (actions) - see Models\CustomCommand and
        // Support\CommandPrerequisiteEvaluator/CommandActionRunner.
        // discord_command_id is filled in after the command is registered
        // with Discord's API (see Admin\CommandController::syncWithDiscord()),
        // needed to update/delete that same registration later.
        Schema::create('discord_integration_commands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description');
            $table->string('scope')->default('global');
            $table->string('discord_guild_id')->nullable();
            $table->string('discord_command_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->json('prerequisites')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();

            $table->unique(['discord_guild_id', 'name'], 'discord_integration_commands_guild_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_integration_commands');
    }
};
