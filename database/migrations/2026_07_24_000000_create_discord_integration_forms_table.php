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
        // An admin-defined Discord modal ("form"): its text fields and what
        // happens with the submitted values (script) - see Models\Form. A
        // form is never registered as a Discord application command itself
        // (no discord_guild_id/discord_command_id/scope like
        // discord_integration_commands has) - it only ever exists to be
        // shown in response to a command's "show_modal" script action, so
        // "name" only needs to be unique for this plugin's own reference,
        // never sent to Discord.
        Schema::create('discord_integration_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
            $table->boolean('enabled')->default(true);
            $table->json('fields')->nullable();
            $table->json('script')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_integration_forms');
    }
};
