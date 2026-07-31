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
        Schema::table('users', function (Blueprint $table) {
            // Mirrors discord_accounts.has_custom_password onto the user itself
            // (inverted), so it survives a forced admin unlink of a passwordless
            // account: the discord_accounts row is gone at that point, but the
            // account still needs to be recognized as unable to log in.
            $table->boolean('discord_integration_passwordless')->default(false)->after('password_changed_at');
        });

        // Backfill from the column this one mirrors, rather than leaving every
        // row at the default. Upgrading from a version predating this column
        // otherwise silently loses the passwordless state of existing accounts:
        // the very next migration derives the generated-password hash from this
        // flag, and when both run in the same batch (a site jumping several
        // releases at once) there is no window for the application to have
        // populated it in between.
        if (Schema::hasColumn('discord_accounts', 'has_custom_password')) {
            DB::table('users')
                ->whereIn('id', DB::table('discord_accounts')
                    ->where('has_custom_password', false)
                    ->select('user_id'))
                ->update(['discord_integration_passwordless' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('discord_integration_passwordless');
        });
    }
};
