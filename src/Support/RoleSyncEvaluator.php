<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Models\RoleGrant;
use Azuriom\Plugin\DiscordIntegration\Models\RoleSync;
use Azuriom\Plugin\DiscordIntegration\Support\Concerns\ChecksShopPackageOwnership;
use Illuminate\Support\Collection;

/**
 * Evaluates the plugin's role-sync rules against users and keeps their
 * Discord guild roles in line with the result. See RoleSync for the rule
 * shape and DiscordBotClient for the actual Discord API calls.
 */
class RoleSyncEvaluator
{
    use ChecksShopPackageOwnership;

    /**
     * The rules a user currently matches (every condition set on a rule is
     * ANDed; a rule with no conditions at all matches everyone).
     *
     * @return Collection<int, RoleSync>
     */
    public function rulesMatchedBy(User $user): Collection
    {
        return RoleSync::all()->filter(fn (RoleSync $rule) => $this->matches($user, $rule))->values();
    }

    protected function matches(User $user, RoleSync $rule): bool
    {
        if ($rule->site_role_ids !== null && ! in_array($user->role_id, $rule->site_role_ids)) {
            return false;
        }

        if ($rule->balance_min !== null && $user->money < $rule->balance_min) {
            return false;
        }

        if ($rule->balance_max !== null && $user->money > $rule->balance_max) {
            return false;
        }

        if ($rule->shop_package_id !== null && ! $this->ownsPackage($user, $rule->shop_package_id)) {
            return false;
        }

        return true;
    }

    /**
     * The Discord guild roles this user should currently hold across every
     * matched rule - rules targeting the same (guild, role) pair are ORed.
     *
     * @return array<string, string[]> guild id => role ids
     */
    public function desiredRoles(User $user): array
    {
        $desired = [];

        foreach ($this->rulesMatchedBy($user) as $rule) {
            $desired[$rule->discord_guild_id][] = $rule->discord_role_id;
        }

        return array_map('array_unique', $desired);
    }

    /**
     * Reconcile a single user's Discord guild roles against every guild
     * referenced by any rule, granting/revoking via the bot as needed.
     * No-op if the user isn't Discord-linked or no bot token is configured.
     *
     * Revoking is conservative by default: a role is only removed from an
     * ineligible member if the plugin is the one that granted it (tracked via
     * RoleGrant) - a role the member already held independently (given by
     * hand, from another integration, ...) is left alone, unless a rule
     * targeting that (guild, role) has "overwrite" enabled, which enforces
     * eligibility strictly regardless of how the role got there.
     */
    public function reconcileUser(User $user): void
    {
        $account = $user->discordAccount;

        if ($account === null || ! DiscordBotClient::available()) {
            return;
        }

        $desired = $this->desiredRoles($user);

        foreach (RoleSync::pluck('discord_guild_id')->unique() as $guildId) {
            $currentRoles = DiscordBotClient::guildMemberRoles($guildId, $account->discord_user_id);

            if ($currentRoles === null) {
                continue; // not a member of that guild (or the lookup failed) - nothing to sync there
            }

            $desiredForGuild = $desired[$guildId] ?? [];

            foreach (RoleSync::where('discord_guild_id', $guildId)->pluck('discord_role_id')->unique() as $roleId) {
                $shouldHave = in_array($roleId, $desiredForGuild, true);
                $hasIt = in_array($roleId, $currentRoles, true);

                if ($shouldHave && ! $hasIt) {
                    if (DiscordBotClient::assignRole($guildId, $account->discord_user_id, $roleId)) {
                        $this->recordGrant($account->discord_user_id, $guildId, $roleId);
                    }
                } elseif (! $shouldHave && $hasIt) {
                    $weGranted = $this->wasGrantedByPlugin($account->discord_user_id, $guildId, $roleId);
                    $overwrite = RoleSync::where('discord_guild_id', $guildId)
                        ->where('discord_role_id', $roleId)
                        ->where('overwrite', true)
                        ->exists();

                    if (($weGranted || $overwrite) && DiscordBotClient::removeRole($guildId, $account->discord_user_id, $roleId)) {
                        $this->forgetGrant($account->discord_user_id, $guildId, $roleId);
                    }
                }
            }
        }
    }

    /**
     * Reconcile every Discord-linked user against every rule - the
     * correctness backstop for conditions with no real-time event (e.g.
     * subscription expiry), run on a schedule (see SyncDiscordRolesCommand).
     */
    public function reconcileAll(): void
    {
        if (! DiscordBotClient::available() || RoleSync::count() === 0) {
            return;
        }

        User::whereHas('discordAccount')->chunkById(50, function (Collection $users) {
            $users->each(fn (User $user) => $this->reconcileUser($user));
        });
    }

    /**
     * Give back every role the plugin granted this Discord user, regardless
     * of whether a matching rule still exists - called when unlinking (see
     * DiscordIntegrationServiceProvider::registerUnlinkGuard()/registerAccountDeletionCleanup()
     * and Admin\UserController::forceUnlinkDiscord()), so leaving doesn't
     * leave them holding roles that were only ever conditional on being
     * linked. Roles the member held independently (never tracked here) are
     * left untouched, same as during normal reconciliation.
     */
    public function revokeGrantedRoles(string $discordUserId): void
    {
        if (! DiscordBotClient::available()) {
            return;
        }

        RoleGrant::where('discord_user_id', $discordUserId)->get()->each(function (RoleGrant $grant) {
            if (DiscordBotClient::removeRole($grant->discord_guild_id, $grant->discord_user_id, $grant->discord_role_id)) {
                $grant->delete();
            }
        });
    }

    protected function wasGrantedByPlugin(string $discordUserId, string $guildId, string $roleId): bool
    {
        return RoleGrant::where('discord_user_id', $discordUserId)
            ->where('discord_guild_id', $guildId)
            ->where('discord_role_id', $roleId)
            ->exists();
    }

    protected function recordGrant(string $discordUserId, string $guildId, string $roleId): void
    {
        RoleGrant::firstOrCreate([
            'discord_user_id' => $discordUserId,
            'discord_guild_id' => $guildId,
            'discord_role_id' => $roleId,
        ]);
    }

    protected function forgetGrant(string $discordUserId, string $guildId, string $roleId): void
    {
        RoleGrant::where('discord_user_id', $discordUserId)
            ->where('discord_guild_id', $guildId)
            ->where('discord_role_id', $roleId)
            ->delete();
    }
}
