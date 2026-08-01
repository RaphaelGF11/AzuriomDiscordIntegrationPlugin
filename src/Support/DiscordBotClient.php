<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Plugin\DiscordIntegration\Support\Gateway\GatewayCache;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the Discord Bot REST API, used for everything the
 * plugin's OAuth-only flows can't do: sending a DM, and assigning/removing
 * or checking a guild member's roles. Every call returns null/false on any
 * failure (missing token, network error, Discord rejecting the request)
 * instead of throwing, so callers can show a translated error message
 * rather than a crash - failures are still logged for diagnosis.
 *
 * When the optional gateway daemon is connected (see Gateway\GatewayCache),
 * the read methods for guild data (guilds/roles/channels/members) serve its
 * event-fed snapshots instead of calling REST - faster, and less load on
 * Discord's API. This is invisible to callers: GatewayCache returns null
 * whenever its data isn't fresh (daemon stopped, feature disabled...), so
 * every method falls back to the plain REST call it always was.
 */
class DiscordBotClient
{
    protected const BASE_URL = 'https://discord.com/api/v10';

    /**
     * Whether a bot token is configured at all (see DiscordCredentials::botToken()).
     */
    public static function available(): bool
    {
        return DiscordCredentials::botToken() !== null;
    }

    /**
     * Verify the configured bot token is valid.
     */
    public static function testToken(): bool
    {
        return static::request('get', '/users/@me') !== null;
    }

    /**
     * Send a direct message to a Discord user. Requires the bot to share at
     * least one guild with that user (a Discord platform constraint, not
     * something this plugin can control) - fails gracefully otherwise.
     */
    public static function sendDirectMessage(string $discordUserId, string $content): bool
    {
        $channelId = static::resolveDmChannel($discordUserId);

        if ($channelId === null) {
            return false;
        }

        return static::sendChannelMessage($channelId, $content);
    }

    /**
     * The id of the DM channel between the bot and a Discord user - created
     * on first use, otherwise the same existing channel id is returned
     * (Discord upserts by recipient, same convention as registerCommand()
     * upserting by name). There's no way to enumerate a bot's DM channels
     * up front (see Admin\MessageController, which resolves one on demand
     * whenever an admin wants to browse a specific user's DMs, rather than
     * trying to track/persist channel ids proactively).
     */
    public static function resolveDmChannel(string $discordUserId): ?string
    {
        return static::request('post', '/users/@me/channels', ['recipient_id' => $discordUserId])?->json('id');
    }

    /**
     * Assign a guild role to a member. Requires the bot to have the "Manage
     * Roles" permission in that guild, with a role position above the target role.
     */
    public static function assignRole(string $guildId, string $discordUserId, string $roleId): bool
    {
        return static::request('put', "/guilds/{$guildId}/members/{$discordUserId}/roles/{$roleId}") !== null;
    }

    /**
     * Remove a guild role from a member.
     */
    public static function removeRole(string $guildId, string $discordUserId, string $roleId): bool
    {
        return static::request('delete', "/guilds/{$guildId}/members/{$discordUserId}/roles/{$roleId}") !== null;
    }

    /**
     * The role IDs currently held by a guild member, or null if the request
     * failed or the user isn't a member of that guild.
     *
     * @return string[]|null
     */
    public static function guildMemberRoles(string $guildId, string $discordUserId): ?array
    {
        // With a complete gateway-fed roster, the cache is authoritative
        // both ways: an array is their roles, and null means they're NOT a
        // member - the same thing REST's 404 below expresses - so no REST
        // fallback on null (only on false, meaning "no usable cache").
        $cached = GatewayCache::memberRoles($guildId, $discordUserId);

        if ($cached !== false) {
            return $cached;
        }

        // A 404 here just means the user isn't currently a member of that
        // guild (e.g. they left after being linked) - RoleSyncEvaluator
        // calls this for every rule's guild on every reconciliation sweep,
        // so it's a routine, expected outcome there, not a failure worth
        // logging every cycle.
        return static::request('get', "/guilds/{$guildId}/members/{$discordUserId}", silentStatuses: [404])?->json('roles');
    }

    /**
     * Add a user to a guild using their own OAuth access token (obtained with
     * the "guilds.join" scope). Requires the bot to already be a member of
     * that guild with the "Create Instant Invite" permission.
     */
    public static function addGuildMember(string $guildId, string $discordUserId, string $userAccessToken): bool
    {
        return static::request('put', "/guilds/{$guildId}/members/{$discordUserId}", [
            'access_token' => $userAccessToken,
        ]) !== null;
    }

    /**
     * The guilds the bot is currently a member of, used to populate a server
     * picker in the admin UI instead of asking for a raw guild ID. Null on
     * any failure (missing token, network error), same convention as every
     * other method here.
     *
     * @return array[]|null
     */
    public static function guilds(): ?array
    {
        return GatewayCache::get(null, 'guilds')
            ?? static::request('get', '/users/@me/guilds?limit=200')?->json();
    }

    /**
     * The full guild object (unlike the partial ones guilds() returns) -
     * needed for fields not included in that partial list, e.g.
     * "premium_tier" (the guild's Server Boost level, see
     * Admin\MessageController::uploadLimit()).
     */
    public static function guild(string $guildId): ?array
    {
        return GatewayCache::get($guildId, 'guild')
            ?? static::request('get', "/guilds/{$guildId}")?->json();
    }

    /**
     * The roles of a guild, used to populate a role picker in the admin UI
     * instead of asking for a raw role ID. Null on any failure, same
     * convention as every other method here.
     *
     * @return array[]|null
     */
    public static function guildRoles(string $guildId): ?array
    {
        return GatewayCache::get($guildId, 'roles')
            ?? static::request('get', "/guilds/{$guildId}/roles")?->json();
    }

    /**
     * The channels of a guild, used to populate a channel picker in the
     * admin UI (the "send a channel message" script action) instead of
     * asking for a raw channel ID. Null on any failure, same convention as
     * every other method here.
     *
     * @return array[]|null
     */
    public static function guildChannels(string $guildId): ?array
    {
        return GatewayCache::get($guildId, 'channels')
            ?? static::request('get', "/guilds/{$guildId}/channels")?->json();
    }

    /**
     * Every active (public or private) thread in the guild - Discord has no
     * per-channel thread listing endpoint, only this guild-wide one, so
     * Admin\MessageController filters the result down to a specific
     * channel's threads itself (by "parent_id").
     *
     * @return array[]|null
     */
    public static function guildActiveThreads(string $guildId): ?array
    {
        return static::request('get', "/guilds/{$guildId}/threads/active")?->json('threads');
    }

    /**
     * Kick a member from a guild. Requires the bot to have the "Kick
     * Members" permission.
     */
    public static function kickMember(string $guildId, string $discordUserId): bool
    {
        return static::request('delete', "/guilds/{$guildId}/members/{$discordUserId}") !== null;
    }

    /**
     * Ban a member from a guild. Requires the bot to have the "Ban Members"
     * permission. The reason (if any) is passed via the audit-log header
     * Discord expects rather than the request body, which this endpoint
     * doesn't accept it in.
     */
    public static function banMember(string $guildId, string $discordUserId, ?string $reason = null): bool
    {
        $headers = $reason ? ['X-Audit-Log-Reason' => $reason] : [];

        return static::request('put', "/guilds/{$guildId}/bans/{$discordUserId}", [], headers: $headers) !== null;
    }

    /**
     * Change a member's nickname in a guild. Requires the bot to have the
     * "Manage Nicknames" permission.
     */
    public static function setNickname(string $guildId, string $discordUserId, string $nickname): bool
    {
        return static::request('patch', "/guilds/{$guildId}/members/{$discordUserId}", ['nick' => $nickname]) !== null;
    }

    /**
     * Post a message to a channel the bot has access to, optionally as a
     * reply to an existing message (any message, not just the bot's own -
     * unlike editing, Discord has no author restriction on who can be
     * replied to).
     */
    public static function sendChannelMessage(string $channelId, string $content, ?string $replyToMessageId = null): bool
    {
        $payload = ['content' => $content];

        if ($replyToMessageId !== null) {
            $payload['message_reference'] = ['message_id' => $replyToMessageId, 'channel_id' => $channelId];
        }

        return static::request('post', "/channels/{$channelId}/messages", $payload) !== null;
    }

    /**
     * Shows the "X is typing..." indicator to everyone else in the channel
     * for about 10 seconds (or until a message is actually sent there) -
     * see Admin\MessageController::typing(), called repeatedly from the
     * compose box's own JS for as long as the admin keeps typing, to renew
     * it before it expires.
     */
    public static function triggerTyping(string $channelId): bool
    {
        return static::request('post', "/channels/{$channelId}/typing") !== null;
    }

    /**
     * Post a message with a single file attachment. Discord requires a
     * multipart request whenever a file is involved (a "files[0]" part plus
     * a "payload_json" field carrying the message body, instead of a plain
     * JSON body) - bypasses request() for that reason, the same way
     * deleteInteractionResponse() does for its own different special case.
     */
    public static function sendChannelMessageWithFile(string $channelId, string $content, string $filename, string $fileContents, ?string $replyToMessageId = null): bool
    {
        $token = DiscordCredentials::botToken();

        if ($token === null) {
            return false;
        }

        $payload = ['content' => $content];

        if ($replyToMessageId !== null) {
            $payload['message_reference'] = ['message_id' => $replyToMessageId, 'channel_id' => $channelId];
        }

        try {
            $response = Http::withToken($token, 'Bot')
                ->acceptJson()
                ->timeout(30)
                ->attach('files[0]', $fileContents, $filename)
                ->post(self::BASE_URL."/channels/{$channelId}/messages", ['payload_json' => json_encode($payload)]);
        } catch (Throwable $e) {
            report($e);
            PluginLog::error('a Discord API request failed', ['exception' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            PluginLog::warning('Discord Bot API call failed', [
                'method' => 'post',
                'path' => "/channels/{$channelId}/messages (with file)",
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Up to $limit messages from a channel (a guild channel, a thread, or a
     * DM channel - they're all just "channels" to this endpoint), newest
     * first. $before pages backwards through history (the oldest message id
     * currently loaded), matching Discord's own before/after/around cursor
     * convention - only "before" is exposed here since Admin\MessageController
     * only ever needs to load further back in history, never forward.
     *
     * @return array[]|null
     */
    public static function channelMessages(string $channelId, ?string $before = null, int $limit = 50): ?array
    {
        $query = 'limit='.$limit.($before !== null ? '&before='.$before : '');

        return static::request('get', "/channels/{$channelId}/messages?{$query}")?->json();
    }

    /**
     * Edits a message's content - Discord only allows this for the
     * message's own author (a bot can never edit another user's message,
     * even with "Manage Messages" - that permission only lets it edit a
     * message's *flags*, not its content), so this only ever succeeds for
     * messages the bot itself sent.
     */
    public static function editMessage(string $channelId, string $messageId, string $content): bool
    {
        return static::request('patch', "/channels/{$channelId}/messages/{$messageId}", ['content' => $content]) !== null;
    }

    /**
     * Deletes a message - the bot's own messages always, anyone else's only
     * with the "Manage Messages" permission in that channel.
     */
    public static function deleteMessage(string $channelId, string $messageId): bool
    {
        return static::request('delete', "/channels/{$channelId}/messages/{$messageId}") !== null;
    }

    /**
     * Deletes 2-100 messages in one call (Discord's own limits - this isn't
     * re-validated here, see Admin\MessageController::bulkDestroy(), which
     * also handles the single-message case Discord rejects here). Requires
     * "Manage Messages" in that channel. Discord fails the *entire* request
     * (nothing is deleted) if any message id is a duplicate, or older than
     * 2 weeks - Admin\MessageController::bulkDestroy() falls back to
     * deleting one by one when this happens, rather than re-implementing
     * that age check itself.
     */
    public static function bulkDeleteMessages(string $channelId, array $messageIds): bool
    {
        return static::request('post', "/channels/{$channelId}/messages/bulk-delete", ['messages' => $messageIds]) !== null;
    }

    /**
     * The bot's own Discord user - used by Admin\MessageController to tell
     * whether a given message was sent by the bot (and can therefore be
     * edited, see editMessage()) without hardcoding or re-deriving that id
     * elsewhere.
     */
    public static function botUser(): ?array
    {
        return static::request('get', '/users/@me')?->json();
    }

    /**
     * The members of a guild, used to populate a member picker for force-
     * linking a site account to a Discord user (see Admin\LinkController)
     * instead of asking for a raw user ID. Requires the "Server Members
     * Intent" enabled for the bot application in the Discord developer
     * portal - fails (returns null) like any other call otherwise.
     *
     * @return array[]|null
     */
    public static function guildMembers(string $guildId): ?array
    {
        // members() only ever serves a COMPLETE roster (see GatewayCache),
        // so this can't regress below what the REST call returns.
        return GatewayCache::members($guildId)
            ?? static::request('get', "/guilds/{$guildId}/members?limit=1000")?->json();
    }

    /**
     * Look up a single Discord user by id, used to cache a display name/
     * avatar for a force-linked account (see Admin\LinkController) before it
     * has ever completed an OAuth round-trip itself.
     */
    public static function user(string $discordUserId): ?array
    {
        return static::request('get', "/users/{$discordUserId}")?->json();
    }

    /**
     * The Discord application id used to register/deregister slash commands
     * - same value as the OAuth client id for a single-application bot,
     * so no separate credential is needed for it.
     */
    public static function applicationId(): ?string
    {
        return DiscordCredentials::clientId();
    }

    /**
     * Deletes an interaction's initial response (its "@original" message) -
     * used to auto-remove the mandatory-but-empty fallback reply a slash
     * command sends when its script has nothing to say (see
     * InteractionsController::replyThenDelete()), a moment after sending it,
     * faking the same "no visible response" effect a modal submission's
     * silent ack already gets for free. Unlike every other method here,
     * this is a webhook-style call authenticated by the interaction token
     * itself (in the URL), not the bot token - no Authorization header is
     * sent, and none is needed.
     */
    public static function deleteInteractionResponse(string $applicationId, string $interactionToken): bool
    {
        try {
            $response = Http::timeout(10)->delete(self::BASE_URL."/webhooks/{$applicationId}/{$interactionToken}/messages/@original");
        } catch (Throwable $e) {
            report($e);
            PluginLog::error('a Discord API request failed', ['exception' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            PluginLog::warning('failed to delete an interaction response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Create or update a slash command (see Models\CustomCommand) - Discord
     * upserts by name within the given scope, so this is safe to call again
     * on every save. Global commands can take up to an hour to propagate to
     * every guild; guild-scoped ones are near-instant.
     *
     * @return array|null the registered command payload (including its
     *                     Discord-assigned "id"), or null on failure
     */
    public static function registerCommand(?string $guildId, array $payload): ?array
    {
        $appId = static::applicationId();

        if ($appId === null) {
            return null;
        }

        $path = $guildId !== null
            ? "/applications/{$appId}/guilds/{$guildId}/commands"
            : "/applications/{$appId}/commands";

        return static::request('post', $path, $payload)?->json();
    }

    /**
     * Deregister a slash command. A 404 (already gone on Discord's side,
     * e.g. removed by hand from the developer portal) is treated the same
     * as success rather than logged as a failure.
     */
    public static function deleteCommand(?string $guildId, string $commandId): bool
    {
        $appId = static::applicationId();

        if ($appId === null) {
            return false;
        }

        $path = $guildId !== null
            ? "/applications/{$appId}/guilds/{$guildId}/commands/{$commandId}"
            : "/applications/{$appId}/commands/{$commandId}";

        return static::request('delete', $path, silentStatuses: [404]) !== null;
    }

    /**
     * Perform a Bot-authenticated request, returning the response on success
     * or null on any failure (missing token, network error, non-2xx status).
     * A non-2xx status listed in $silentStatuses is still treated as a
     * failure (null is returned) but isn't logged - for call sites where
     * that particular status is a routine, expected outcome rather than
     * something worth surfacing in the logs every time it happens.
     */
    protected static function request(string $method, string $path, array $payload = [], array $silentStatuses = [], array $headers = []): ?Response
    {
        $token = DiscordCredentials::botToken();

        if ($token === null) {
            return null;
        }

        try {
            $client = Http::withToken($token, 'Bot')->acceptJson()->timeout(10);

            if ($headers !== []) {
                $client = $client->withHeaders($headers);
            }

            // Some GET calls above embed their query string directly in
            // $path (e.g. "?limit=1000"). Passing $payload as-is even when
            // empty would still pass an (empty) 'query' option to Guzzle,
            // which replaces rather than merges with that embedded query
            // string - silently dropping it and letting Discord fall back
            // to its own (often much lower) default, so it's only passed
            // through here when there's actually something in it.
            $response = $payload === []
                ? $client->{$method}(self::BASE_URL.$path)
                : $client->{$method}(self::BASE_URL.$path, $payload);
        } catch (Throwable $e) {
            report($e);
            PluginLog::error('a Discord API request failed', ['exception' => $e->getMessage(), 'method' => $method, 'path' => $path]);

            return null;
        }

        if ($response->failed()) {
            if (! in_array($response->status(), $silentStatuses, true)) {
                PluginLog::warning('Discord Bot API call failed', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return null;
        }

        return $response;
    }
}
