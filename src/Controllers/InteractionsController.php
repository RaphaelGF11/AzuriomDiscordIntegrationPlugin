<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\DiscordAccount;
use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Models\CustomCommand;
use Azuriom\Plugin\DiscordIntegration\Models\Form;
use Azuriom\Plugin\DiscordIntegration\Support\CommandPlaceholderResolver;
use Azuriom\Plugin\DiscordIntegration\Support\CommandScriptExecutionState;
use Azuriom\Plugin\DiscordIntegration\Support\CommandScriptRunner;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Receives Discord's "Interactions" webhook (see the Discord developer
 * portal's "Interactions Endpoint URL" setting, and
 * Admin\ConfigurationController for where the admin pastes it and the
 * public key needed to verify it) - the push counterpart to the rest of
 * this plugin's pull-based DiscordBotClient calls, used for dispatching
 * this plugin's admin-defined slash commands (see Models\CustomCommand) and
 * handling the submission of a modal shown by one of their scripts (see
 * Models\Form, and CustomCommand's "show_modal" block). No session, no CSRF
 * (see routes/api.php and RouteServiceProvider) - Discord itself is the only
 * caller, authenticated via its Ed25519 request signature instead.
 */
class InteractionsController extends Controller
{
    protected const PING = 1;

    protected const APPLICATION_COMMAND = 2;

    protected const MODAL_SUBMIT = 5;

    protected const PONG = 1;

    protected const CHANNEL_MESSAGE_WITH_SOURCE = 4;

    protected const DEFERRED_UPDATE_MESSAGE = 6;

    protected const MODAL = 9;

    protected const EPHEMERAL_FLAG = 64;

    /**
     * Prefix for the custom_id this plugin puts on every modal it shows,
     * followed by the Models\Form's id - the only piece of state that needs
     * to survive the round-trip to a MODAL_SUBMIT interaction, since that's
     * a completely independent HTTP request with nothing else carried over
     * (see handleModalSubmit()). Kept as an explicit prefix (rather than a
     * bare id) so a future message component (a button, a select menu) can
     * share this same custom_id namespace without colliding with a form's.
     */
    protected const FORM_CUSTOM_ID_PREFIX = 'form:';

    public function handle(Request $request, CommandScriptRunner $runner, CommandPlaceholderResolver $placeholders)
    {
        // Temporary timing instrumentation - Discord requires a response
        // within 3s and reports "didn't respond in time" both for a real
        // timeout AND for a response it rejects for other reasons, with no
        // way for us to tell which from our own HTTP status/logs alone.
        // This isolates "our server was slow" from "something else (Cloudflare,
        // the tunnel, Discord's own validation) is the problem" - remove once
        // the real cause is confirmed.
        $start = microtime(true);

        if (! $this->verifySignature($request)) {
            abort(401);
        }

        $payload = $request->json()->all();

        if (($payload['type'] ?? null) === self::PING) {
            return response()->json(['type' => self::PONG]);
        }

        if (($payload['type'] ?? null) === self::MODAL_SUBMIT) {
            $response = $this->handleModalSubmit($payload, $runner, $placeholders);
            $this->logTiming('MODAL_SUBMIT', $start, $response);

            return $response;
        }

        if (($payload['type'] ?? null) !== self::APPLICATION_COMMAND) {
            return $this->reply(trans('discord-integration::messages.interactions.unsupported'));
        }

        $response = $this->handleCommand($payload, $runner, $placeholders);
        $this->logTiming('APPLICATION_COMMAND', $start, $response);

        return $response;
    }

    protected function logTiming(string $label, float $start, $response): void
    {
        \Illuminate\Support\Facades\Log::info('discord-integration: interaction timing', [
            'type' => $label,
            'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
            'response_body' => $response->getContent(),
        ]);
    }

    /**
     * Verifies the request genuinely comes from Discord, via the Ed25519
     * signature scheme documented at https://discord.com/developers/docs/interactions/overview#setting-up-an-endpoint
     * - reads the raw request body first (before anything else touches the
     * request, e.g. $request->json(), which would otherwise consume/parse
     * the stream this needs untouched for the signature to verify).
     */
    protected function verifySignature(Request $request): bool
    {
        $publicKey = setting('discord-integration.interactions_public_key');
        $signature = $request->header('X-Signature-Ed25519');
        $timestamp = $request->header('X-Signature-Timestamp');

        if (! $publicKey || ! $signature || ! $timestamp) {
            return false;
        }

        $rawBody = $request->getContent();

        try {
            return sodium_crypto_sign_verify_detached(
                hex2bin($signature),
                $timestamp.$rawBody,
                hex2bin($publicKey)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function handleCommand(array $payload, CommandScriptRunner $runner, CommandPlaceholderResolver $placeholders)
    {
        $name = $payload['data']['name'] ?? null;
        $guildId = $payload['guild_id'] ?? null;

        $command = CustomCommand::where('name', $name)
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('discord_guild_id')->orWhere('discord_guild_id', $guildId))
            ->first();

        if ($command === null) {
            return $this->reply(trans('discord-integration::messages.interactions.not_found'));
        }

        $discordUserId = $payload['member']['user']['id'] ?? $payload['user']['id'] ?? null;
        $account = $discordUserId !== null ? DiscordAccount::where('discord_user_id', $discordUserId)->first() : null;
        $user = $account?->user;

        if ($user === null) {
            return $this->reply(trans('discord-integration::messages.interactions.not_linked'));
        }

        // No separate eligibility gate anymore - the script runs from the
        // top every time; an admin who wants a "forbidden" reply for
        // ineligible users writes it as script logic themselves (an "if"
        // block checking the condition, containing a "reply" + "stop").
        $optionValues = collect($payload['data']['options'] ?? [])->pluck('value', 'name');

        $state = $runner->run($command->script ?? [], $user, $optionValues, $payload);

        return $this->respondToScriptResult($state, $command->fallback_message, $placeholders, $payload);
    }

    /**
     * A modal's submission (see CustomCommand's "show_modal" block and
     * Models\Form) - a completely independent interaction from whatever
     * triggered the modal, identified only by the form id encoded in
     * custom_id (see modalResponse()). Runs the form's own script exactly
     * like a command's, with the submitted field values standing in for
     * $optionValues via the same {option:name} placeholder mechanism.
     */
    protected function handleModalSubmit(array $payload, CommandScriptRunner $runner, CommandPlaceholderResolver $placeholders)
    {
        $formId = $this->parseFormIdFromCustomId($payload['data']['custom_id'] ?? '');
        $form = $formId !== null ? Form::where('id', $formId)->where('enabled', true)->first() : null;

        if ($form === null) {
            return $this->reply(trans('discord-integration::messages.interactions.form_not_found'));
        }

        $discordUserId = $payload['member']['user']['id'] ?? $payload['user']['id'] ?? null;
        $account = $discordUserId !== null ? DiscordAccount::where('discord_user_id', $discordUserId)->first() : null;
        $user = $account?->user;

        if ($user === null) {
            return $this->reply(trans('discord-integration::messages.interactions.not_linked'));
        }

        $optionValues = $this->extractModalValues($payload);

        $state = $runner->run($form->script ?? [], $user, $optionValues, $payload);

        // A form's own script showing another modal isn't a reliably
        // supported flow in Discord's stable API (see Form's docblock) -
        // rather than error, just fall back to the same "no reply" path a
        // command with no matching reply block already takes.
        return $this->respondToScriptResult($state, $form->fallback_message, $placeholders, $payload, silent: true);
    }

    /**
     * Turns a finished script run into the interaction response: a modal if
     * "show_modal" fired, the queued reply otherwise, or - if the script
     * produced neither - the command/form's own placeholder-resolved
     * "fallback_message" (or a generic translated message if that's blank
     * too). A modal *submission*'s acknowledgment doesn't need a visible
     * message at all ($silent: true, only ever passed from
     * handleModalSubmit()) - Discord has a real "say nothing" response type
     * for that. A slash command interaction has no such option (some
     * non-empty message content is always required), so its fallback -
     * being genuinely mandatory rather than something the script authored
     * on purpose - is deleted again moments after being sent instead, to
     * fake the same "no visible response" effect (see replyThenDelete()).
     */
    protected function respondToScriptResult(CommandScriptExecutionState $state, ?string $fallbackMessage, CommandPlaceholderResolver $placeholders, array $payload, bool $silent = false)
    {
        if ($state->modal !== null) {
            return $this->modalResponse($state->modal['form_id']);
        }

        if ($state->reply !== null) {
            return $this->reply($state->reply['content'], $state->reply['ephemeral']);
        }

        if ($silent && ($fallbackMessage === null || trim($fallbackMessage) === '')) {
            return response()->json(['type' => self::DEFERRED_UPDATE_MESSAGE]);
        }

        $content = $fallbackMessage !== null && trim($fallbackMessage) !== ''
            ? $placeholders->resolve($fallbackMessage, $state->user, $state->optionValues, $state->rawPayload, $state->variables)
            : trans('discord-integration::messages.interactions.done');

        return $silent ? $this->reply($content) : $this->replyThenDelete($content, $payload);
    }

    /**
     * How long the mandatory fallback message stays visible before
     * replyThenDelete() removes it again.
     */
    protected const FALLBACK_MESSAGE_LIFETIME_SECONDS = 3;

    /**
     * Same as reply(), but schedules the sent message for deletion a few
     * seconds later - the slash-command equivalent of a modal submission's
     * silent ack (DEFERRED_UPDATE_MESSAGE, see respondToScriptResult()):
     * Discord has no "respond with nothing visible" option for a slash
     * command interaction, so this fakes it by flashing the mandatory
     * fallback message and removing it moments after.
     *
     * Uses dispatch(...)->afterResponse() (built into Laravel since 8.x) -
     * runs the closure in this same PHP-FPM worker right after the HTTP
     * response has already been flushed to Discord, so the sleep() below
     * doesn't count against Discord's own 3-second interaction budget (that
     * only bounds the initial reply, already sent by the time this runs).
     * No queue worker involved or required - this never touches an actual
     * queue, it's the framework's own fastcgi_finish_request() handling
     * under the hood.
     */
    protected function replyThenDelete(string $content, array $payload, bool $ephemeral = true)
    {
        $appId = DiscordBotClient::applicationId();
        $token = $payload['token'] ?? null;

        if ($appId !== null && $token !== null) {
            dispatch(function () use ($appId, $token) {
                sleep(self::FALLBACK_MESSAGE_LIFETIME_SECONDS);
                DiscordBotClient::deleteInteractionResponse($appId, $token);
            })->afterResponse();
        }

        return $this->reply($content, $ephemeral);
    }

    /**
     * Builds a Discord modal (type 9) response for the given form - re-loads
     * and re-checks it's still enabled rather than trusting the script ran
     * moments ago, since it could have been disabled or deleted in the
     * meantime. Falls back to a plain reply if it's gone, rather than
     * sending Discord a reference to nothing.
     */
    protected function modalResponse(int $formId)
    {
        $form = Form::where('id', $formId)->where('enabled', true)->first();

        if ($form === null) {
            return $this->reply(trans('discord-integration::messages.interactions.form_not_found'));
        }

        return response()->json([
            'type' => self::MODAL,
            'data' => [
                'custom_id' => self::FORM_CUSTOM_ID_PREFIX.$form->id,
                'title' => $form->title,
                // Action Row (type 1) + Text Input (type 4) - the Label
                // (type 18) wrapper Discord's docs currently recommend
                // failed identically in real testing (both silently
                // rejected as "didn't respond in time", while a plain,
                // non-modal reply through the exact same webhook succeeds
                // reliably), so the component nesting shape isn't the
                // culprit - reverted to this simpler, longer-established
                // shape while chasing the real cause elsewhere (see the
                // "value" handling below).
                'components' => collect($form->fields ?? [])->take(5)->map(fn ($field) => [
                    'type' => 1, // Action row
                    'components' => [array_filter([
                        'type' => 4, // Text input
                        'custom_id' => $field['field_key'],
                        'style' => ($field['style'] ?? 'short') === 'paragraph' ? 2 : 1,
                        'label' => $field['label'],
                        'required' => (bool) ($field['required'] ?? false),
                        'min_length' => $field['min_length'] ?? null,
                        'max_length' => $field['max_length'] ?? null,
                        'placeholder' => ! empty($field['placeholder']) ? $field['placeholder'] : null,
                        // An empty string is treated the same as "not set"
                        // here (not just null) - a blank prefilled "value"
                        // together with a non-zero "min_length" is a
                        // contradiction (0 characters can't satisfy a
                        // minimum length), which may be why Discord silently
                        // rejected every modal response so far despite
                        // otherwise-correct structure.
                        'value' => ! empty($field['value']) ? $field['value'] : null,
                    ], fn ($value) => $value !== null)],
                ])->values()->all(),
            ],
        ]);
    }

    protected function parseFormIdFromCustomId(string $customId): ?int
    {
        if (! str_starts_with($customId, self::FORM_CUSTOM_ID_PREFIX)) {
            return null;
        }

        $id = substr($customId, strlen(self::FORM_CUSTOM_ID_PREFIX));

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * A MODAL_SUBMIT payload nests submitted values the same way the modal's
     * components were declared (see modalResponse()): data.components is an
     * array of Action Row (type 1) components, each wrapping a single text
     * input under "components" (plural, an array) with the submitted
     * {custom_id, value}. Also accepts the newer Label (type 18) shape
     * (singular "component") defensively, in case Discord echoes submissions
     * back that way regardless of which shape a modal was declared with -
     * real testing showed the Label shape isn't reliably accepted for
     * *building* a modal, but there's no reason to assume the reverse can't
     * happen for *reading* one back. Flattened here into the same "name =>
     * value" shape $optionValues already takes for a command's Discord
     * options, keyed by each field's custom_id (Form's own "field_key").
     *
     * @return Collection<string, mixed>
     */
    protected function extractModalValues(array $payload): Collection
    {
        return collect($payload['data']['components'] ?? [])
            ->flatMap(fn ($item) => isset($item['component']) ? [$item['component']] : ($item['components'] ?? [$item]))
            ->filter(fn ($component) => isset($component['custom_id']))
            ->mapWithKeys(fn ($component) => [$component['custom_id'] => $component['value'] ?? '']);
    }

    protected function reply(string $content, bool $ephemeral = true)
    {
        return response()->json([
            'type' => self::CHANNEL_MESSAGE_WITH_SOURCE,
            'data' => [
                'content' => $content,
                'flags' => $ephemeral ? self::EPHEMERAL_FLAG : 0,
            ],
        ]);
    }
}
