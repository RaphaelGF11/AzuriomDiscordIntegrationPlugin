<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Models\Role;
use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Support\Exceptions\CommandScriptStepBudgetExceeded;
use Azuriom\Plugin\DiscordIntegration\Support\Exceptions\CommandScriptStopSignal;
use Azuriom\Plugin\Shop\Models\Package;
use Azuriom\Plugin\Shop\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Runs a "script" - a nested tree of blocks built with the drag-and-drop
 * editor (see CustomCommand's docblock for the JSON shape) - for one
 * interaction. Takes the script array directly rather than a CustomCommand,
 * since Models\Form's own script runs through this exact same interpreter
 * (a form's submitted field values arrive as $optionValues exactly like a
 * command's Discord parameters do, see InteractionsController).
 *
 * Fail-soft is scoped ONLY around each leaf action's actual side-effecting
 * call (see runLeaf()), not around the structural recursion in
 * runSequence()/runBlock() - a broad catch there would also swallow
 * CommandScriptStopSignal (itself a Throwable), breaking "stop"'s ability to
 * abort the whole script from an arbitrarily nested block. This is also why
 * "show_modal" (see runShowModal()) is dispatched from runBlock() rather
 * than runLeaf(): it needs to throw that same stop signal, which runLeaf()'s
 * catch-all would otherwise swallow.
 */
class CommandScriptRunner
{
    /**
     * Total blocks entered across the whole run (every block counts once,
     * including each "repeat" iteration) - bounds worst-case execution time
     * given Discord's 3-second interaction response window.
     */
    protected const MAX_STEPS = 500;

    /**
     * A single "repeat" block's count is clamped to this ceiling so one
     * misconfigured loop can't burn the entire step budget by itself.
     */
    protected const MAX_REPEAT_COUNT = 50;

    public function __construct(
        protected CommandConditionEvaluator $conditions,
        protected CommandPlaceholderResolver $placeholders,
    ) {
    }

    /**
     * @param  array  $script  see CustomCommand's docblock for the block tree shape
     * @param  Collection<string, mixed>  $optionValues
     * @return CommandScriptExecutionState the full execution state - callers
     *                                     read ->reply and ->modal off it to see what the script produced (at
     *                                     most one of the two is ever set, since an interaction can only be
     *                                     answered with a message or a modal, never both)
     */
    public function run(array $script, User $user, Collection $optionValues, array $rawPayload): CommandScriptExecutionState
    {
        $state = new CommandScriptExecutionState($user, $optionValues, $rawPayload);

        try {
            $this->runSequence($script, $state);
        } catch (CommandScriptStopSignal $e) {
            // A "stop" (or "show_modal") block ran - deliberate early exit, not an error.
        } catch (CommandScriptStepBudgetExceeded $e) {
            report($e);
            PluginLog::warning('a command script exceeded its execution step budget', ['user_id' => $user->id]);
        }

        return $state;
    }

    protected function runSequence(array $blocks, CommandScriptExecutionState $state): void
    {
        foreach ($blocks as $block) {
            $this->runBlock($block, $state);
        }
    }

    protected function runBlock(array $block, CommandScriptExecutionState $state): void
    {
        $state->stepsExecuted++;

        if ($state->stepsExecuted > self::MAX_STEPS) {
            throw new CommandScriptStepBudgetExceeded("Command script exceeded its execution step budget (".self::MAX_STEPS.').');
        }

        match ($block['type'] ?? null) {
            'if' => $this->runIf($block, $state),
            'repeat' => $this->runRepeat($block, $state),
            'stop' => throw new CommandScriptStopSignal(),
            'set_variable' => $this->runSetVariable($block, $state),
            'modify_variable' => $this->runModifyVariable($block, $state),
            'random_number' => $this->runRandomNumber($block, $state),
            'show_modal' => $this->runShowModal($block, $state),
            default => $this->runLeaf($block, $state),
        };
    }

    protected function runIf(array $block, CommandScriptExecutionState $state): void
    {
        $matches = $this->conditions->matchesAny($state, $block['condition'] ?? []);

        $this->runSequence($matches ? ($block['then'] ?? []) : ($block['else'] ?? []), $state);
    }

    protected function runRepeat(array $block, CommandScriptExecutionState $state): void
    {
        $resolved = $this->resolve((string) ($block['count'] ?? '0'), $state);
        $count = max(0, min(self::MAX_REPEAT_COUNT, is_numeric($resolved) ? (int) $resolved : 0));

        for ($i = 0; $i < $count; $i++) {
            $this->runSequence($block['body'] ?? [], $state);
        }
    }

    /**
     * Queues a Models\Form to be shown as a modal - a hard stop, like
     * "reply"+"stop" combined, since an interaction can only be answered
     * with a message or a modal, never both, and nothing meaningful can
     * follow it. Guarded against a reply (or modal) already being queued so
     * the first one to run wins, same convention as runReply() - though in
     * practice this guard is mostly defensive, since the throw below means
     * a script can never reach a second show_modal in the same run anyway.
     */
    protected function runShowModal(array $block, CommandScriptExecutionState $state): void
    {
        if ($state->reply === null && $state->modal === null && isset($block['form_id'])) {
            $state->modal = ['form_id' => (int) $block['form_id']];
        }

        throw new CommandScriptStopSignal();
    }

    protected function runSetVariable(array $block, CommandScriptExecutionState $state): void
    {
        $name = $block['name'] ?? null;

        if (! $name) {
            return;
        }

        $state->variables[$name] = $this->resolve((string) ($block['value'] ?? ''), $state);
    }

    /**
     * Arithmetic on a variable (add/subtract/multiply/divide/set), the
     * escape hatch for the "variables are just strings" limitation
     * documented on CustomCommand - the current value and the operand are
     * both read through the same placeholder resolver as everything else
     * (so a variable can be combined with an {option:} or another {var:}),
     * then the numeric result is written back as a string like every other
     * variable.
     */
    protected function runModifyVariable(array $block, CommandScriptExecutionState $state): void
    {
        $name = $block['name'] ?? null;

        if (! $name) {
            return;
        }

        $current = $state->variables[$name] ?? '0';
        $currentValue = is_numeric($current) ? (float) $current : 0.0;

        $resolvedOperand = $this->resolve((string) ($block['value'] ?? '0'), $state);
        $operand = is_numeric($resolvedOperand) ? (float) $resolvedOperand : 0.0;

        $result = match ($block['operation'] ?? 'add') {
            'add' => $currentValue + $operand,
            'subtract' => $currentValue - $operand,
            'multiply' => $currentValue * $operand,
            'divide' => $operand !== 0.0 ? $currentValue / $operand : $currentValue,
            'set' => $operand,
            default => $currentValue,
        };

        $state->variables[$name] = $this->formatNumber($result);
    }

    protected function runRandomNumber(array $block, CommandScriptExecutionState $state): void
    {
        $name = $block['name'] ?? null;

        if (! $name) {
            return;
        }

        $min = $this->resolveInt($block['min'] ?? '1', $state, 1);
        $max = $this->resolveInt($block['max'] ?? '100', $state, 100);

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $state->variables[$name] = (string) random_int($min, $max);
    }

    protected function resolveInt(string $template, CommandScriptExecutionState $state, int $default): int
    {
        $resolved = $this->resolve($template, $state);

        return is_numeric($resolved) ? (int) $resolved : $default;
    }

    /**
     * Formats a float as the shortest string that round-trips through
     * is_numeric()/(float) cleanly - "3" rather than "3.000000", but still
     * "3.5" for a genuinely fractional result.
     */
    protected function formatNumber(float $value): string
    {
        if ($value === 0.0) {
            $value = 0.0; // normalizes -0.0 to 0.0
        }

        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.') ?: '0';
    }

    /**
     * Every leaf action - dispatches by type, then stores the action's
     * result (if it produced one, and the block asked for it via
     * "result_variable") the same way every other variable-writing block
     * does. Fail-soft here covers the actual side-effecting call only, same
     * as before "result_variable" existed - a failed action still resolves
     * to a result (false/null/the count actually processed), it just never
     * reaches storeResult() if it threw.
     */
    protected function runLeaf(array $block, CommandScriptExecutionState $state): void
    {
        try {
            $result = match ($block['type'] ?? null) {
                'reply' => $this->runReply($block, $state),
                'send_dm' => $this->sendDm($block, $state),
                'send_channel_message' => DiscordBotClient::sendChannelMessage($block['channel_id'] ?? '', $this->resolve($block['content'] ?? '', $state)),
                'assign_discord_role' => $this->withDiscordAccount($state->user, fn ($account) => DiscordBotClient::assignRole($block['guild_id'], $account->discord_user_id, $block['role_id'])),
                'remove_discord_role' => $this->withDiscordAccount($state->user, fn ($account) => DiscordBotClient::removeRole($block['guild_id'], $account->discord_user_id, $block['role_id'])),
                'kick_discord_member' => $this->withDiscordAccount($state->user, fn ($account) => DiscordBotClient::kickMember($block['guild_id'], $account->discord_user_id)),
                'ban_discord_member' => $this->withDiscordAccount($state->user, fn ($account) => DiscordBotClient::banMember($block['guild_id'], $account->discord_user_id, $this->resolve($block['reason'] ?? '', $state) ?: null)),
                'set_discord_nickname' => $this->withDiscordAccount($state->user, fn ($account) => DiscordBotClient::setNickname($block['guild_id'], $account->discord_user_id, $this->resolve($block['nickname'] ?? '', $state))),
                'assign_site_role' => $this->assignSiteRole($block, $state->user),
                'remove_site_role' => $this->removeSiteRole($block, $state->user),
                'give_shop_packages' => $this->giveShopPackages($block, $state->user),
                'modify_balance' => $this->modifyBalance($block, $state),
                'run_artisan_command' => $this->runArtisanCommand($block, $state),
                default => null,
            };

            $this->storeResult($block, $result, $state);
        } catch (Throwable $e) {
            report($e);
            PluginLog::error('a command script action failed', [
                'exception' => $e->getMessage(),
                'block_type' => $block['type'] ?? null,
                'user_id' => $state->user->id,
            ]);
        }
    }

    /**
     * Writes a leaf action's result into a variable, the same string-cast
     * convention as everywhere else a bool ends up in a variable (PHP casts
     * true to "1" and false to "" - the same shape a Discord "Yes/No"
     * parameter's value already takes through CommandPlaceholderResolver).
     * A block without "result_variable" set, or an action that produced no
     * result at all (null), leaves the variables map untouched.
     */
    protected function storeResult(array $block, mixed $result, CommandScriptExecutionState $state): void
    {
        $name = $block['result_variable'] ?? null;

        if (! $name || $result === null) {
            return;
        }

        $state->variables[$name] = (string) $result;
    }

    protected function runReply(array $block, CommandScriptExecutionState $state): void
    {
        if ($state->reply === null) {
            $state->reply = [
                'content' => $this->resolve($block['content'] ?? '', $state),
                'ephemeral' => $block['ephemeral'] ?? true,
            ];
        }
    }

    protected function resolve(string $template, CommandScriptExecutionState $state): string
    {
        return $this->placeholders->resolve($template, $state->user, $state->optionValues, $state->rawPayload, $state->variables);
    }

    /**
     * Runs $callback with the user's linked Discord account, returning its
     * result - or false if there's no linked account at all, since that's
     * itself a meaningful "the action didn't happen" outcome for
     * "result_variable" to capture, not just a silent no-op.
     */
    protected function withDiscordAccount(User $user, callable $callback): mixed
    {
        $account = $user->discordAccount;

        return $account !== null ? $callback($account) : false;
    }

    protected function sendDm(array $block, CommandScriptExecutionState $state): bool
    {
        $discordUserId = $this->resolveDmRecipient($block, $state);

        if ($discordUserId === null) {
            return false;
        }

        return DiscordBotClient::sendDirectMessage($discordUserId, $this->resolve($block['content'] ?? '', $state));
    }

    /**
     * The DM's target: an explicit recipient (placeholder-resolved, e.g.
     * {option:name} from a "Discord user" parameter, or a literal id) if
     * the admin set one, falling back to the invoking user's own linked
     * account otherwise - the only behavior this block had before
     * "recipient" existed, kept as the default so existing scripts are
     * unaffected.
     */
    protected function resolveDmRecipient(array $block, CommandScriptExecutionState $state): ?string
    {
        $recipient = trim($this->resolve((string) ($block['recipient'] ?? ''), $state));

        if ($recipient !== '') {
            return $recipient;
        }

        return $state->user->discordAccount?->discord_user_id;
    }

    protected function assignSiteRole(array $block, User $user): bool
    {
        $role = isset($block['role_id']) ? Role::find($block['role_id']) : null;

        if ($role === null) {
            return false;
        }

        $user->role()->associate($role)->save();

        return true;
    }

    /**
     * Azuriom users only ever have a single role, so "removing" one only
     * makes sense if the user currently holds exactly that role - in which
     * case they fall back to the site's default role (see
     * Role::defaultRoleId()), same as what a brand new account gets.
     */
    protected function removeSiteRole(array $block, User $user): bool
    {
        if (! isset($block['role_id']) || $user->role_id !== $block['role_id']) {
            return false;
        }

        $defaultRole = Role::defaultRole();

        if ($defaultRole === null) {
            return false;
        }

        $user->role()->associate($defaultRole)->save();

        return true;
    }

    /**
     * Grants shop packages for free, reusing the exact same manual-payment
     * shape Shop's own admin "create a payment" page uses (see
     * Plugin\Shop\Controllers\Admin\PaymentController::store()) rather than
     * a parallel ad-hoc path, so it stays consistent with the rest of the
     * commerce system (reports, "owns this package" checks elsewhere).
     */
    /**
     * @return int the number of packages actually found and granted (may be
     *             less than the block's package_ids if some no longer exist) -
     *             a plain count rather than a bool, since "how many" is more
     *             useful than "did anything happen" for this one
     */
    protected function giveShopPackages(array $block, User $user): int
    {
        if (! class_exists(Package::class) || empty($block['package_ids'])) {
            return 0;
        }

        $granted = 0;

        foreach (Package::findMany($block['package_ids']) as $package) {
            $package->isSubscription()
                ? $this->grantSubscriptionPackage($package, $user)
                : $this->grantOneTimePackage($package, $user);
            $granted++;
        }

        return $granted;
    }

    protected function grantOneTimePackage(Package $package, User $user): void
    {
        $payment = Payment::create([
            'user_id' => $user->id,
            'price' => 0,
            'currency' => currency(),
            'status' => 'completed',
            'gateway_type' => 'manual',
            'transaction_id' => 'discord-command-'.bin2hex(random_bytes(8)),
        ]);

        $payment->items()
            ->make(['name' => $package->name, 'price' => 0, 'quantity' => 1])
            ->buyable()
            ->associate($package)
            ->save();

        $payment->deliver();
    }

    /**
     * Subscription-billed packages aren't handled by a bare Payment the way
     * one-time packages are (see PaymentMethod::createSubscription()/
     * renewSubscription() for the pattern this mirrors) - they need a
     * Subscription row (created once, then extended on each grant) whose
     * addRenewalPayment() builds the actual Payment/PaymentItem and bumps
     * ends_at by the package's billing period.
     */
    protected function grantSubscriptionPackage(Package $package, User $user): void
    {
        $subscription = $package->subscriptions()->where('user_id', $user->id)->first();
        $isNew = $subscription === null;

        if ($isNew) {
            $subscription = $package->subscriptions()->create([
                'user_id' => $user->id,
                'subscription_id' => 'discord-command-'.bin2hex(random_bytes(8)),
                'gateway_type' => 'manual',
                'status' => 'active',
                'price' => 0,
                'currency' => currency(),
            ]);
        }

        $payment = $subscription->addRenewalPayment('discord-command-'.bin2hex(random_bytes(8)));
        $payment->deliver(! $isNew);
    }

    /**
     * @return float|null the user's new balance after the change (increment()/
     *                     decrement() update the in-memory attribute, so $state->user->money
     *                     already reflects it here) - null if the amount didn't resolve to a
     *                     number, so nothing happened
     */
    protected function modifyBalance(array $block, CommandScriptExecutionState $state): ?float
    {
        $resolved = $this->resolve((string) ($block['amount'] ?? '0'), $state);
        $amount = is_numeric($resolved) ? (float) $resolved : null;

        if ($amount === null) {
            return null;
        }

        match ($block['operation'] ?? 'add') {
            // 'money' isn't in User::$fillable (only ever meant to be
            // changed through dedicated flows like addMoney()/removeMoney()),
            // so a plain update() would silently no-op here - forceFill()
            // bypasses that guard the same way addMoney()/removeMoney()
            // themselves do via increment()/decrement().
            'add' => $state->user->addMoney($amount),
            'subtract' => $state->user->removeMoney($amount),
            'set' => $state->user->forceFill(['money' => $amount])->save(),
            default => null,
        };

        return $state->user->money;
    }

    /**
     * Runs a Laravel Artisan command already registered on this install -
     * Artisan::call() binds $parameters as structured Symfony Console
     * arguments/options, not a shell string, so there's no command-
     * injection surface here the way exec()/shell_exec() would create. The
     * real safeguard is re-checking the command actually exists in
     * Artisan::all() (the admin UI's picker is only a client-side nudge,
     * not the security boundary) before calling it.
     */
    /**
     * @return int|null the command's exit code (0 is success, same
     *                   convention as every CLI tool) - null if the command name didn't
     *                   resolve to a real, registered command, so nothing ran
     */
    protected function runArtisanCommand(array $block, CommandScriptExecutionState $state): ?int
    {
        $commandName = $block['command'] ?? null;

        if (! $commandName || ! array_key_exists($commandName, Artisan::all())) {
            return null;
        }

        $parameters = collect($block['parameters'] ?? [])
            ->mapWithKeys(fn ($value, $key) => [$key => $this->resolve((string) $value, $state)])
            ->all();

        return Artisan::call($commandName, $parameters);
    }
}
