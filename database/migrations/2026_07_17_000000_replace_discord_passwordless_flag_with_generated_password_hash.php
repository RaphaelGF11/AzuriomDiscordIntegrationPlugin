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
            // Replaces the discord_integration_passwordless boolean: stores the
            // password hash generated at Discord registration time instead, so
            // "is passwordless" (see Support\DiscordPasswordless) is derived by
            // comparing it against the current password hash rather than kept
            // in sync by a listener on every password-changing code path.
            $table->string('discord_integration_generated_password')->nullable()->after('password_changed_at');
        });

        // Backfill: accounts still flagged passwordless haven't changed their
        // password since registration, so their current hash *is* the
        // originally generated one.
        DB::table('users')
            ->where('discord_integration_passwordless', true)
            ->update(['discord_integration_generated_password' => DB::raw('password')]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('discord_integration_passwordless');
        });

        // No longer needed either: DiscordPasswordless::isPasswordless() reads
        // straight off the user row, so it works the same whether a
        // discord_accounts row exists or not (a forced admin unlink used to
        // need users.discord_integration_passwordless specifically to survive
        // the discord_accounts row being deleted - now nothing needs syncing).
        Schema::table('discord_accounts', function (Blueprint $table) {
            $table->dropColumn('has_custom_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discord_accounts', function (Blueprint $table) {
            $table->boolean('has_custom_password')->default(true)->after('bypass_2fa');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('discord_integration_passwordless')->default(false)->after('password_changed_at');
        });

        DB::table('users')
            ->whereNotNull('discord_integration_generated_password')
            ->whereColumn('discord_integration_generated_password', 'password')
            ->update(['discord_integration_passwordless' => true]);

        DB::table('discord_accounts')
            ->join('users', 'users.id', '=', 'discord_accounts.user_id')
            ->whereColumn('users.discord_integration_generated_password', 'users.password')
            ->update(['discord_accounts.has_custom_password' => false]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('discord_integration_generated_password');
        });
    }
};
