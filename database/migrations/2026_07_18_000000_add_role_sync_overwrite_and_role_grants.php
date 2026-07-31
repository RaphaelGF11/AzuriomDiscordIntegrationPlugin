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
        Schema::table('discord_integration_role_syncs', function (Blueprint $table) {
            // If enabled, this rule removes its Discord role from an
            // ineligible member even if the plugin never granted it in the
            // first place (see discord_integration_role_grants below). Off by
            // default, so a role a member already held independently is left
            // alone unless an admin explicitly opts a rule into that.
            $table->boolean('overwrite')->default(false)->after('discord_role_id');
        });

        // Tracks every (guild, role) the plugin itself granted to a Discord
        // user via role sync, so reconciliation and unlink cleanup (see
        // RoleSyncEvaluator) can tell "we gave you this" apart from "you
        // already had this" and only retract the former by default.
        Schema::create('discord_integration_role_grants', function (Blueprint $table) {
            $table->id();
            $table->string('discord_user_id');
            $table->string('discord_guild_id');
            $table->string('discord_role_id');
            $table->timestamps();

            $table->unique(['discord_user_id', 'discord_guild_id', 'discord_role_id'], 'discord_integration_role_grants_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_integration_role_grants');

        Schema::table('discord_integration_role_syncs', function (Blueprint $table) {
            $table->dropColumn('overwrite');
        });
    }
};
