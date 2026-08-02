<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Support\Concerns\ChecksShopPackageOwnership;

/**
 * Evaluates an "if" block's condition (an OR of AND-groups of conditions -
 * see CustomCommand's docblock for the JSON shape) against the script's
 * current execution state, generalizing the same AND-group idea
 * RoleSyncEvaluator already uses per-rule into an explicit, multi-group
 * structure. matchesGroup() is also reused for a "repeat"/leaf block's own
 * inline condition wherever a script only needs a single AND-group rather
 * than the full OR-of-groups shape.
 *
 * Takes the whole CommandScriptExecutionState (not just the User) because
 * the "compare" condition type resolves placeholders ({var:}/{option:} on
 * both sides) the same way every other script field does.
 *
 * Renamed from CommandPrerequisiteEvaluator: there is no longer a separate
 * top-level "prerequisites" gate on a command - eligibility is expressed as
 * script logic ("if" + "stop"), so this class only ever evaluates a
 * condition value passed to it, never reads a "prerequisites" field itself.
 */
class CommandConditionEvaluator
{
    use ChecksShopPackageOwnership;

    public function __construct(protected CommandPlaceholderResolver $placeholders)
    {
    }

    /**
     * Whether the user satisfies at least one of the given OR-groups. No
     * groups at all (or an empty list) means everyone matches, same
     * convention as an "if" block with no condition configured.
     */
    public function matchesAny(CommandScriptExecutionState $state, array $groups): bool
    {
        if ($groups === []) {
            return true;
        }

        foreach ($groups as $group) {
            if ($this->matchesGroup($state, $group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every condition in the group matches (AND) - an empty group
     * matches everyone.
     */
    public function matchesGroup(CommandScriptExecutionState $state, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->matchesCondition($state, $condition)) {
                return false;
            }
        }

        return true;
    }

    protected function matchesCondition(CommandScriptExecutionState $state, array $condition): bool
    {
        $user = $state->user;

        $result = match ($condition['type'] ?? null) {
            'site_role' => in_array($user->role_id, $condition['role_ids'] ?? [], true),
            'balance_min' => $user->money >= $condition['value'],
            'balance_max' => $user->money <= $condition['value'],
            'shop_package' => $this->ownsPackage($user, $condition['package_id']),
            'discord_role' => $this->hasDiscordRole($user, $condition['guild_id'], $condition['role_id']),
            'linked_account' => $user->discordAccount !== null,
            'compare' => $this->matchesCompare($state, $condition),
            default => ScriptExtensions::evaluateCondition($condition['type'] ?? '', $condition, $state),
        };

        return ($condition['negate'] ?? false) ? ! $result : $result;
    }

    /**
     * Compares two placeholder-resolved values - the general-purpose escape
     * hatch for conditions the fixed catalog above doesn't cover (e.g. a
     * roll against a threshold, one variable against another, an option
     * against a literal). Numeric comparison (including the ordering
     * operators) only applies when both sides resolve to a numeric string;
     * otherwise ordering operators are false (there's no meaningful "greater
     * than" for arbitrary text) and equals/not_equals/contains fall back to
     * a plain string comparison.
     */
    protected function matchesCompare(CommandScriptExecutionState $state, array $condition): bool
    {
        $left = $this->resolve((string) ($condition['left'] ?? ''), $state);
        $right = $this->resolve((string) ($condition['right'] ?? ''), $state);
        $numeric = is_numeric($left) && is_numeric($right);

        return match ($condition['operator'] ?? 'equals') {
            'equals' => $numeric ? (float) $left === (float) $right : $left === $right,
            'not_equals' => $numeric ? (float) $left !== (float) $right : $left !== $right,
            'contains' => $right !== '' && str_contains($left, $right),
            'greater_than' => $numeric && (float) $left > (float) $right,
            'greater_than_or_equal' => $numeric && (float) $left >= (float) $right,
            'less_than' => $numeric && (float) $left < (float) $right,
            'less_than_or_equal' => $numeric && (float) $left <= (float) $right,
            default => false,
        };
    }

    protected function resolve(string $template, CommandScriptExecutionState $state): string
    {
        return $this->placeholders->resolve($template, $state->user, $state->optionValues, $state->rawPayload, $state->variables);
    }

    protected function hasDiscordRole(User $user, string $guildId, string $roleId): bool
    {
        $account = $user->discordAccount;

        if ($account === null) {
            return false;
        }

        $roles = DiscordBotClient::guildMemberRoles($guildId, $account->discord_user_id);

        return $roles !== null && in_array($roleId, $roles, true);
    }
}
