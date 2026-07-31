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
        Schema::table('discord_integration_forms', function (Blueprint $table) {
            // Same convention as CustomCommand's own fallback_message (added
            // in the same-named migration for discord_integration_commands)
            // - sent only when the form's script produced no reply.
            $table->text('fallback_message')->nullable()->after('script');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_integration_forms', function (Blueprint $table) {
            $table->dropColumn('fallback_message');
        });
    }
};
