<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('discord_integration_commands', function (Blueprint $table) {
            $table->dropColumn('prerequisites');
            $table->renameColumn('actions', 'script');
        });

        // Pre-release test data only - the old flat actions[] shape isn't
        // directly compatible with the new nested block AST (see
        // CustomCommand's docblock), and the only row that existed when this
        // migration was written was trivial (a single unconditional action),
        // so it's reset rather than shape-migrated.
        DB::table('discord_integration_commands')->update(['script' => '[]']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_integration_commands', function (Blueprint $table) {
            $table->renameColumn('script', 'actions');
            $table->json('prerequisites')->nullable()->after('options');
        });
    }
};
