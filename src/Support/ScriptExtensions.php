<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use InvalidArgumentException;
use Throwable;

/**
 * Lets another plugin add its own action/condition block types to this
 * plugin's drag-and-drop script editor (see CommandScriptRunner and
 * CommandConditionEvaluator for the built-in ones, and
 * resources/views/admin/partials/script-editor-scripts.blade.php for the
 * editor itself) - without either of them knowing anything about that
 * plugin ahead of time.
 *
 * A plugin registers from its own service provider's boot(), e.g.:
 *
 *     ScriptExtensions::registerAction('my_plugin_give_xp', [
 *         'label' => 'Give XP',
 *         'handler' => fn (array $block, CommandScriptExecutionState $state) => MyPlugin::giveXp(
 *             $state->user, (int) $block['amount'] ?? 0
 *         ),
 *         'asset' => asset('plugin/my-plugin/js/script-blocks.js'),
 *     ]);
 *
 *     ScriptExtensions::registerCondition('my_plugin_has_badge', [
 *         'label' => 'Has badge',
 *         'handler' => fn (array $condition, CommandScriptExecutionState $state) => MyPlugin::hasBadge(
 *             $state->user, $condition['badge_id'] ?? null
 *         ),
 *         'asset' => asset('plugin/my-plugin/js/script-blocks.js'),
 *     ]);
 *
 * "handler" is called exactly the way a built-in leaf action/condition
 * would be (see CommandScriptRunner::runLeaf()'s docblock on
 * "result_variable" for what an action's return value can be used for; a
 * condition's handler returns a plain bool). It's fail-soft the same way
 * every built-in leaf action/condition already is - runAction()/
 * evaluateCondition() below catch anything it throws, report() it, and
 * journal it (see Support\PluginLog), rather than letting it break the
 * rest of the script (or, for a condition, take down the whole
 * interaction - see evaluateCondition()'s own docblock).
 *
 * "asset" is a URL to a JS file (versioned/cached however that plugin
 * already serves its own assets) that calls
 * `CommandBuilder.registerActionType()`/`registerConditionType()` (see
 * command-builder-scripts.blade.php's own docblock for that JS-side half of
 * this API) - collected once per unique URL by
 * Concerns\BuildsScriptEditorContext and <script>-included on both script
 * editor pages, in registration order, after window.CommandBuilder exists
 * and before the palette is first rendered.
 *
 * Registration must happen during boot(), not lazily on first use: both the
 * editor pages (which need every type's asset + the fact that it exists,
 * for the palette) and script execution (which needs every type's handler)
 * read the registry as a fixed, already-complete list at the point they're
 * used - there's no lazy-resolution hook once a request is already
 * rendering the editor or running a script.
 */
class ScriptExtensions
{
    /**
     * Every type name CommandScriptRunner/CommandConditionEvaluator/the
     * script editor already give a hardcoded meaning to - reusing one of
     * these silently gets shadowed by the built-in (CommandScriptRunner's
     * own match() arms are always checked first), which would leave a
     * plugin author wondering why their action never runs. Rejected loudly
     * at registration time instead. Kept as one combined list since a
     * clashing action type and a clashing condition type are the same
     * mistake either way.
     */
    protected const RESERVED_TYPES = [
        // Control / variable block types (script-editor-scripts.blade.php) -
        // structural, never just a leaf action.
        'if', 'repeat', 'stop', 'set_variable', 'modify_variable', 'random_number',
        // Built-in leaf actions (CommandScriptRunner::runLeaf()).
        'reply', 'send_dm', 'send_channel_message', 'assign_discord_role',
        'remove_discord_role', 'kick_discord_member', 'ban_discord_member',
        'set_discord_nickname', 'assign_site_role', 'remove_site_role',
        'give_shop_packages', 'modify_balance', 'run_artisan_command', 'show_modal',
        // Built-in conditions (CommandConditionEvaluator::matchesCondition()).
        'site_role', 'balance_min', 'balance_max', 'shop_package',
        'discord_role', 'linked_account', 'compare',
    ];

    protected static array $actions = [];

    protected static array $conditions = [];

    public static function registerAction(string $type, array $definition): void
    {
        static::$actions[$type] = static::validated($type, $definition, static::$actions);
    }

    public static function registerCondition(string $type, array $definition): void
    {
        static::$conditions[$type] = static::validated($type, $definition, static::$conditions);
    }

    /**
     * @return array<string, array>
     */
    public static function actions(): array
    {
        return static::$actions;
    }

    /**
     * @return array<string, array>
     */
    public static function conditions(): array
    {
        return static::$conditions;
    }

    /**
     * @return mixed the handler's return value (see CommandScriptRunner::
     *               storeResult() for how it feeds "result_variable"), or null if $type
     *               isn't a registered action, or its handler threw - the same
     *               "silently did nothing" outcome an unrecognized built-in type, or a
     *               failed built-in action, already has
     */
    public static function runAction(string $type, array $block, CommandScriptExecutionState $state): mixed
    {
        if (! isset(static::$actions[$type])) {
            return null;
        }

        try {
            return (static::$actions[$type]['handler'])($block, $state);
        } catch (Throwable $e) {
            report($e);
            PluginLog::error('a registered script action failed', ['type' => $type, 'exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * A third-party handler is run through the exact same try/catch a
     * built-in leaf action already gets in CommandScriptRunner::runLeaf() -
     * but unlike a leaf action, a condition is evaluated from runIf()/
     * runRepeat(), well outside that try/catch, so this method has to be
     * its own safety net instead of leaning on a caller's. Without it, one
     * badly-written third-party condition would take down the whole script
     * (and, since a script runs synchronously inside the 3-second Discord
     * interaction window, the whole interaction) instead of just failing
     * the one condition it's part of.
     *
     * @return bool false if $type isn't a registered condition, or its
     *              handler threw - matching an unrecognized built-in condition type's
     *              own default
     */
    public static function evaluateCondition(string $type, array $condition, CommandScriptExecutionState $state): bool
    {
        if (! isset(static::$conditions[$type])) {
            return false;
        }

        try {
            return (bool) (static::$conditions[$type]['handler'])($condition, $state);
        } catch (Throwable $e) {
            report($e);
            PluginLog::error('a registered script condition failed', ['type' => $type, 'exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Every unique "asset" URL across every registered action/condition, in
     * registration order - what the two script editor pages <script>-include.
     *
     * @return string[]
     */
    public static function assetUrls(): array
    {
        return collect(static::$actions)
            ->concat(static::$conditions)
            ->pluck('asset')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected static function validated(string $type, array $definition, array $existing): array
    {
        if (in_array($type, self::RESERVED_TYPES, true)) {
            throw new InvalidArgumentException("\"{$type}\" is a built-in Discord Integration script block type and can't be overridden.");
        }

        if (isset($existing[$type])) {
            throw new InvalidArgumentException("A script block type \"{$type}\" is already registered.");
        }

        if (! isset($definition['label']) || ! is_string($definition['label'])) {
            throw new InvalidArgumentException("Script block type \"{$type}\" needs a string \"label\".");
        }

        if (! isset($definition['handler']) || ! is_callable($definition['handler'])) {
            throw new InvalidArgumentException("Script block type \"{$type}\" needs a callable \"handler\".");
        }

        return $definition;
    }
}
