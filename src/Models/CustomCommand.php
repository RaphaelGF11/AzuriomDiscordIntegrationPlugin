<?php

namespace Azuriom\Plugin\DiscordIntegration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-defined Discord slash command: its parameters (options) and what
 * happens when it's run - a nested, drag-and-drop-built block script (see
 * Support\CommandScriptRunner for how it's executed and
 * resources/views/admin/command-script.blade.php for the editor). Eligibility
 * is expressed as script logic (an "if" block containing "reply"+"stop")
 * rather than a separate gate - there is no standalone prerequisites concept
 * anymore. Registered/deregistered with Discord's API via DiscordBotClient
 * whenever saved/deleted - see Admin\CommandController.
 *
 * The script is a sequence (ordered array) of block objects. Every block has
 * an `id` (client-generated, for drag-and-drop identity) and a `type`, plus
 * its own fields directly on the object:
 *
 *   - {id, type: "if", condition: Condition[][], then: Block[], else: Block[]}
 *     "condition" is an OR of AND-groups, same catalog/shape as the old
 *     top-level prerequisites (site_role, balance_min, balance_max,
 *     shop_package, discord_role, linked_account, each with an optional
 *     "negate" flag), plus "compare": {type: "compare", negate, left:
 *     string, operator: string, right: string} - left/right are
 *     placeholder-resolvable (so two variables, or a variable and a
 *     literal, can be compared), operator is one of equals/not_equals/
 *     greater_than/greater_than_or_equal/less_than/less_than_or_equal/
 *     contains. Ordering operators only match when both sides resolve to a
 *     numeric string. See CommandConditionEvaluator.
 *   - {id, type: "repeat", count: string, body: Block[]} - count is a
 *     placeholder-resolvable string, clamped at execution time.
 *   - {id, type: "stop"} - aborts the whole script immediately.
 *   - {id, type: "set_variable", name: string, value: string} - value is
 *     placeholder-resolvable (including {var:otherName} for the variables
 *     map's state *before* this assignment - the way to accumulate across a
 *     "repeat" loop). Variables are untyped strings by default.
 *   - {id, type: "modify_variable", name: string, operation: "add"|
 *     "subtract"|"multiply"|"divide"|"set", value: string} - the actual
 *     arithmetic escape hatch: reads the variable (and the placeholder-
 *     resolved value) as numbers, writes the result back as a string.
 *   - {id, type: "random_number", name: string, min: string, max: string} -
 *     sets a variable to a random integer in [min, max] (both
 *     placeholder-resolvable).
 *   - {id, type: "show_modal", form_id: int} - shows one of Models\Form's
 *     forms as a Discord modal instead of a text reply. A hard stop, like
 *     "reply"+"stop" combined - nothing runs after it, and it does nothing
 *     if a reply was already queued earlier in the script (an interaction
 *     can only be answered with a message or a modal, never both). The
 *     form's submission arrives as a completely separate HTTP request with
 *     no continuation of this run's variables - see Form's docblock and
 *     Controllers\InteractionsController::handleModalSubmit().
 *   - Leaf action blocks (13 types), each with their fields flattened onto
 *     the block instead of nested under a "config" key: reply, send_dm,
 *     send_channel_message, assign_discord_role, remove_discord_role,
 *     kick_discord_member, ban_discord_member, set_discord_nickname,
 *     assign_site_role, remove_site_role, give_shop_packages,
 *     modify_balance, run_artisan_command. send_dm's "recipient" is
 *     placeholder-resolvable and optional - empty (or missing, for scripts
 *     saved before it existed) means the invoking user's own linked
 *     account, same as before "recipient" existed. Every leaf action except
 *     "reply" also accepts an optional "result_variable": string - if set,
 *     the action's outcome (a bool for the Discord actions and
 *     assign/remove_site_role, cast to "1"/"" the same way a "Yes/No"
 *     parameter's value already is; a count for give_shop_packages; the new
 *     balance for modify_balance; the exit code for run_artisan_command) is
 *     written to that variable, letting a later block react to whether the
 *     action actually happened. See CommandScriptRunner::storeResult().
 *
 * "fallback_message" is placeholder-resolvable and sent only when the
 * script produced neither a reply nor a modal (Discord always requires some
 * non-empty response content, so an empty/missing value here falls back to
 * a generic translated message rather than sending nothing) - see
 * Controllers\InteractionsController::respondToScriptResult().
 *
 * @property int $id
 * @property string $name
 * @property string $description
 * @property string $scope
 * @property string|null $discord_guild_id
 * @property string|null $discord_command_id
 * @property bool $enabled
 * @property array $options
 * @property array $script
 * @property string|null $fallback_message
 */
class CustomCommand extends Model
{
    protected $table = 'discord_integration_commands';

    protected $fillable = [
        'name', 'description', 'scope', 'discord_guild_id', 'discord_command_id',
        'enabled', 'options', 'script', 'fallback_message',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'options' => 'array',
        'script' => 'array',
    ];
}
