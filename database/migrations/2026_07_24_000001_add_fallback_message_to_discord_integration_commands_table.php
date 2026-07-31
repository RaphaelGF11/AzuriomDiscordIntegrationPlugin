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
        Schema::table('discord_integration_commands', function (Blueprint $table) {
            // Sent (placeholder-resolved) only when the script produced
            // neither a reply nor a modal - Discord requires some non-empty
            // response content regardless, so an empty value here still
            // falls back to a generic translated message rather than
            // sending nothing at all. See
            // InteractionsController::respondToScriptResult().
            $table->text('fallback_message')->nullable()->after('script');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_integration_commands', function (Blueprint $table) {
            $table->dropColumn('fallback_message');
        });
    }
};
