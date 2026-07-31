<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\DiscordAccount;
use Azuriom\Plugin\DiscordIntegration\Models\PendingLink;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A single interactive page for browsing and moderating Discord messages -
 * a guild channel (or one of its active threads) or a DM with a specific
 * Discord user (resolved on demand, see DiscordBotClient::resolveDmChannel() -
 * there's no way to enumerate a bot's past DMs up front, so this never tries
 * to track/persist channel ids proactively). No database model of its own:
 * everything here reads/writes Discord live through DiscordBotClient.
 */
class MessageController extends Controller
{
    /**
     * Show the picker (guild/channel/thread or DM target) - the message
     * list itself is loaded afterwards via list() once a channel id is known.
     */
    public function index()
    {
        $botAvailable = DiscordBotClient::available();

        return view('discord-integration::admin.messages', [
            'botAvailable' => $botAvailable,
            'guilds' => $botAvailable ? DiscordBotClient::guilds() : null,
        ]);
    }

    /**
     * Active threads of one specific channel - Discord only exposes a
     * guild-wide "active threads" endpoint, so this filters it down by
     * "parent_id" itself (see DiscordBotClient::guildActiveThreads()).
     */
    public function threads(Request $request)
    {
        $guildId = $request->query('guild_id', '');
        $channelId = $request->query('channel_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '' || $channelId === '', 404);

        $threads = DiscordBotClient::guildActiveThreads($guildId);

        abort_if($threads === null, 404);

        return response()->json(
            collect($threads)
                ->where('parent_id', $channelId)
                ->values()
                ->map(fn ($thread) => ['id' => $thread['id'], 'name' => $thread['name']])
        );
    }

    /**
     * Resolves (creating it if needed) the DM channel with a given Discord
     * user, for the "load" step of the DM target picker.
     */
    public function dmChannel(Request $request)
    {
        $discordUserId = $request->query('discord_user_id', '');

        abort_if(! DiscordBotClient::available() || $discordUserId === '', 404);

        $channelId = DiscordBotClient::resolveDmChannel($discordUserId);

        abort_if($channelId === null, 404);

        return response()->json(['channel_id' => $channelId]);
    }

    /**
     * Shown when clicking a message's avatar/username - a live Discord
     * lookup (username/avatar/bot flags always reflect their current state,
     * unlike what's cached on the message itself) plus, for a non-bot
     * account, whether it's linked to a site account through this plugin -
     * a real DiscordAccount takes priority over a still-unconfirmed
     * PendingLink (see PendingLink's own docblock).
     */
    public function userDetails(Request $request)
    {
        $discordUserId = $request->query('discord_user_id', '');

        abort_if(! DiscordBotClient::available() || $discordUserId === '', 404);

        $user = DiscordBotClient::user($discordUserId);

        abort_if($user === null, 404);

        $isBot = (bool) ($user['bot'] ?? false);
        $linkedAccount = $isBot ? null : DiscordAccount::where('discord_user_id', $discordUserId)->with('user')->first();
        $pendingLink = $isBot || $linkedAccount !== null ? null : PendingLink::where('discord_user_id', $discordUserId)->with('user')->first();
        $siteUser = $linkedAccount?->user ?? $pendingLink?->user;

        return response()->json([
            'id' => $user['id'],
            'username' => $user['username'],
            'global_name' => $user['global_name'] ?? null,
            'avatar_url' => DiscordAvatar::smallIconUrlFrom($user),
            'is_bot' => $isBot,
            'is_verified_bot' => (($user['public_flags'] ?? 0) & (1 << 16)) !== 0,
            'link_status' => $isBot ? null : ($linkedAccount !== null ? 'linked' : ($pendingLink !== null ? 'pending' : 'unlinked')),
            'linked_site_user' => $siteUser?->name,
            'linked_site_user_avatar' => $siteUser?->getAvatar(32),
            // The site account's own admin edit page - core Azuriom route,
            // not one of this plugin's own, since it's the same page every
            // other admin user-management link in this codebase points to.
            'linked_site_user_url' => $siteUser !== null ? route('admin.users.edit', $siteUser) : null,
        ]);
    }

    /**
     * A page of messages for a channel (a guild channel, a thread, or a DM
     * channel - all just "channels" to Discord's message endpoints), newest
     * first as Discord itself returns them - the view reverses this for
     * display. Each message is tagged with "is_own_message" so the view
     * knows whether to offer an Edit action (Discord only allows editing a
     * message's own author, see DiscordBotClient::editMessage()).
     */
    public function list(Request $request)
    {
        $channelId = $request->query('channel_id', '');
        $before = $request->query('before');

        abort_if(! DiscordBotClient::available() || $channelId === '', 404);

        $messages = DiscordBotClient::channelMessages($channelId, $before);

        abort_if($messages === null, 404);

        $botId = $this->botUserId();

        return response()->json(
            collect($messages)->map(fn ($message) => [
                'id' => $message['id'],
                'content' => $message['content'] ?? '',
                // Discord's own "system messages" (someone joining, a pin, a
                // boost...) come back with an empty "content" - the text
                // shown in the real Discord client for those is generated
                // entirely client-side from this numeric type, never sent by
                // the API. The view uses this to show an equivalent message
                // instead of a blank one for the types that need it.
                'type' => $message['type'] ?? 0,
                'timestamp' => $message['timestamp'] ?? null,
                'edited_timestamp' => $message['edited_timestamp'] ?? null,
                'author_id' => $message['author']['id'] ?? null,
                'author_name' => $message['author']['global_name'] ?? $message['author']['username'] ?? '?',
                'author_avatar' => isset($message['author']) ? DiscordAvatar::smallIconUrlFrom($message['author']) : null,
                'author_is_bot' => (bool) ($message['author']['bot'] ?? false),
                // Discord's "verified bot" checkmark - VERIFIED_BOT is bit
                // 1<<16 (65536) of the user's public_flags bitfield.
                'author_is_verified_bot' => (($message['author']['public_flags'] ?? 0) & (1 << 16)) !== 0,
                'attachments' => $this->mapAttachments($message['attachments'] ?? []),
                'stickers' => $this->mapStickers($message['sticker_items'] ?? []),
                // A GIF sent through Discord's own GIF picker (Tenor, or the
                // newer Klipy provider) isn't an attachment - it's a link in
                // "content" that Discord auto-expands into a "gifv"-type
                // embed. Despite the name, there's no actual .gif file
                // involved - the embed's "video" field points at a small MP4
                // instead (Discord always converts these to video for
                // efficiency), which is what's rendered here.
                'gifs' => $this->mapGifEmbeds($message['embeds'] ?? []),
                'is_own_message' => $botId !== null && ($message['author']['id'] ?? null) === $botId,
                // Discord's own <@id> mention syntax in "content" carries no
                // name - the API separately includes the mentioned users'
                // full objects in "mentions", used here to resolve them for
                // display (see the view's markdown renderer). Role/channel
                // mentions aren't included this way, so those are resolved
                // from a guild-wide name map fetched once per channel load
                // instead (see guildRoleNames()/guildChannelNames() below).
                'mentions' => $this->mapMentions($message['mentions'] ?? []),
                // A forwarded message: the API sends its own "content" empty
                // (or just the forwarder's own added comment, if any) - the
                // actual forwarded text/attachments live in a snapshot of the
                // original message instead, taken at forward time (so it
                // stays the same even if the original is later edited or
                // deleted). Only the first snapshot is ever populated -
                // Discord only supports forwarding a single message at a time.
                'forwarded' => isset($message['message_snapshots'][0]['message']) ? [
                    'content' => $message['message_snapshots'][0]['message']['content'] ?? '',
                    'attachments' => $this->mapAttachments($message['message_snapshots'][0]['message']['attachments'] ?? []),
                    'mentions' => $this->mapMentions($message['message_snapshots'][0]['message']['mentions'] ?? []),
                    'stickers' => $this->mapStickers($message['message_snapshots'][0]['message']['sticker_items'] ?? []),
                    'gifs' => $this->mapGifEmbeds($message['message_snapshots'][0]['message']['embeds'] ?? []),
                ] : null,
            ])
        );
    }

    /**
     * Every role's {id, name, color, position} in a guild, including the
     * @everyone role (unlike CommandController::guildRoles(), which excludes
     * it and managed roles - both can still legitimately appear as a <@&id>
     * mention inside a message, and are worth resolving to their real name
     * here). "color"/"position" are what the view uses to show an author's
     * name in their highest-ranked colored role's color, the same way
     * Discord's own client does.
     */
    public function guildRoleNames(Request $request)
    {
        $guildId = $request->query('guild_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '', 404);

        $roles = DiscordBotClient::guildRoles($guildId);

        abort_if($roles === null, 404);

        return response()->json(
            collect($roles)->map(fn ($role) => [
                'id' => $role['id'],
                'name' => $role['name'],
                'color' => $role['color'],
                'position' => $role['position'],
            ])
        );
    }

    /**
     * {name, roles, avatar_url} for every member of a guild, keyed by user
     * id - the per-message "author" only ever carries the user's own (non
     * guild-specific) data (see list() above), never their guild roles,
     * per-server nickname, or per-server avatar override, so this is
     * fetched separately, once per channel load, to resolve all three (see
     * guildRoleNames() above for the "roles" side, and DiscordAvatar for the
     * guild-specific avatar CDN URL format). Also doubles as the "@" mention
     * autocomplete's user list in the view, since it's already the full
     * member roster. Requires the "Server Members Intent" enabled for the
     * bot, same as every other guildMembers()-based feature in this plugin.
     */
    public function guildMembersInfo(Request $request)
    {
        $guildId = $request->query('guild_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '', 404);

        $members = DiscordBotClient::guildMembers($guildId);

        abort_if($members === null, 404);

        return response()->json(
            collect($members)
                ->filter(fn ($member) => isset($member['user']['id']))
                ->mapWithKeys(fn ($member) => [
                    $member['user']['id'] => [
                        'name' => $member['nick'] ?? $member['user']['global_name'] ?? $member['user']['username'],
                        'roles' => $member['roles'] ?? [],
                        'avatar_url' => isset($member['avatar'])
                            ? "https://cdn.discordapp.com/guilds/{$guildId}/users/{$member['user']['id']}/avatars/{$member['avatar']}.png"
                            : null,
                    ],
                ])
        );
    }

    /**
     * Every channel's {id, name, type} in a guild, including voice/category/
     * stage ones (unlike CommandController::guildChannels(), which only lists
     * the text-postable ones for its "send a channel message" picker) - a
     * <#id> mention inside a message can point at any channel type. "type" is
     * Discord's own numeric channel type (0 = text, 2 = voice, 5 =
     * announcement, 13 = stage, 15 = forum, etc.) - the view uses it to show
     * a distinct icon per type in the "#" mention autocomplete.
     */
    public function guildChannelNames(Request $request)
    {
        $guildId = $request->query('guild_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '', 404);

        $channels = DiscordBotClient::guildChannels($guildId);

        abort_if($channels === null, 404);

        return response()->json(
            collect($channels)->map(fn ($channel) => [
                'id' => $channel['id'],
                'name' => $channel['name'],
                'type' => $channel['type'] ?? 0,
            ])
        );
    }

    /**
     * The largest file the compose box's file picker should let the admin
     * pick for the current target, so a file that's already known to be too
     * big never gets uploaded to this server in the first place (only to
     * fail once relayed to Discord) - purely a client-side courtesy, not a
     * security boundary (send() below doesn't re-derive or enforce this;
     * Discord's own API is still the final word on whether a file is
     * accepted, since neither of the two limits combined here are ever
     * guaranteed to be exactly right - see the two doc comments below).
     */
    public function uploadLimit(Request $request)
    {
        $guildId = $request->query('guild_id');

        return response()->json(['max_bytes' => min($this->discordUploadLimit($guildId), $this->serverUploadLimit())]);
    }

    /**
     * Discord's per-file size cap depends on the target guild's Server Boost
     * level (there's no such thing in a DM, which always gets the base
     * limit) - these are the tiers as officially published, but Discord can
     * change them without any API-visible signal, so this is a best-effort
     * (deliberately the more commonly-cited, conservative split) rather than
     * a hard guarantee.
     */
    protected function discordUploadLimit(?string $guildId): int
    {
        $tierLimits = [0 => 10, 1 => 10, 2 => 50, 3 => 100];

        if ($guildId === null || ! DiscordBotClient::available()) {
            return $tierLimits[0] * 1024 * 1024;
        }

        $tier = DiscordBotClient::guild($guildId)['premium_tier'] ?? 0;

        return ($tierLimits[$tier] ?? $tierLimits[0]) * 1024 * 1024;
    }

    /**
     * This server's own PHP-level upload ceiling - a file allowed by
     * Discord's own limit can still be well above what this server's
     * "upload_max_filesize"/"post_max_size" would ever let through, which
     * would otherwise mean the upload to *this* server fails before ever
     * reaching Discord - exactly the wasted upload this endpoint exists to
     * avoid.
     */
    protected function serverUploadLimit(): int
    {
        return min(
            $this->iniSizeToBytes(ini_get('upload_max_filesize')),
            $this->iniSizeToBytes(ini_get('post_max_size'))
        );
    }

    protected function iniSizeToBytes(string $size): int
    {
        $unit = strtolower(substr(trim($size), -1));
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $size,
        };
    }

    /**
     * "content_type" lets the view render an image attachment inline and an
     * audio one (including Discord's own voice messages - just a regular
     * audio attachment under the hood) with a playable <audio> element,
     * instead of every attachment being just a plain download link.
     *
     * @return array<int, array{filename: string, url: string, content_type: ?string}>
     */
    protected function mapAttachments(array $attachments): array
    {
        return collect($attachments)->map(fn ($attachment) => [
            'filename' => $attachment['filename'] ?? $attachment['url'],
            'url' => $attachment['url'],
            'content_type' => $attachment['content_type'] ?? null,
        ])->values()->all();
    }

    /**
     * Discord only sends a minimal "sticker_items" (id, name, format_type)
     * on a message, not the full sticker object - a display image is built
     * from those directly rather than fetching each sticker individually.
     * PNG/APNG stickers are served from the regular CDN host; GIF ones from
     * a different one (media.discordapp.net); LOTTIE ones (a vector
     * animation format, not a still/raster image) have no image URL at all -
     * the view falls back to showing just the sticker's name for those.
     *
     * @return array<int, array{name: string, url: ?string}>
     */
    protected function mapStickers(array $stickerItems): array
    {
        return collect($stickerItems)->map(function ($sticker) {
            $url = match ($sticker['format_type'] ?? null) {
                1, 2 => "https://cdn.discordapp.com/stickers/{$sticker['id']}.png",
                4 => "https://media.discordapp.net/stickers/{$sticker['id']}.gif",
                default => null,
            };

            return ['name' => $sticker['name'], 'url' => $url];
        })->values()->all();
    }

    /**
     * Only "gifv"-type embeds are handled here (Discord's own GIF picker,
     * Tenor or Klipy) - every other embed type (rich link previews, image
     * embeds from a plain pasted URL, etc.) is out of scope and left alone.
     * Prefers the Discord-proxied "proxy_url" over the provider's own
     * ("url") - same convention as attachments already served straight from
     * Discord's CDN, and avoids depending on Tenor/Klipy's own CDN staying up.
     *
     * @return array<int, array{url: string}>
     */
    protected function mapGifEmbeds(array $embeds): array
    {
        return collect($embeds)
            ->where('type', 'gifv')
            ->map(fn ($embed) => ['url' => $embed['video']['proxy_url'] ?? $embed['video']['url'] ?? null])
            ->filter(fn ($gif) => $gif['url'] !== null)
            ->values()->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    protected function mapMentions(array $mentions): array
    {
        return collect($mentions)->map(fn ($user) => [
            'id' => $user['id'],
            'name' => $user['global_name'] ?? $user['username'] ?? '?',
        ])->values()->all();
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function typing(Request $request)
    {
        $data = $this->validate($request, [
            'channel_id' => ['required', 'string'],
        ]);

        abort_if(! DiscordBotClient::available(), 404);

        $sent = DiscordBotClient::triggerTyping($data['channel_id']);

        return response()->json(['success' => $sent], $sent ? 200 : 502);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function send(Request $request)
    {
        $data = $this->validate($request, [
            'channel_id' => ['required', 'string'],
            // Either is enough on its own (an attachment-only message, like
            // a real Discord client allows, needs no text) - the file's own
            // size is intentionally not re-capped here beyond this generous
            // upper bound (Discord's own largest possible tier); the real
            // limit shown to the admin (uploadLimit() above) is enforced
            // client-side only, as a courtesy, not a security boundary.
            'content' => ['required_without:file', 'nullable', 'string', 'max:2000'],
            'file' => ['required_without:content', 'nullable', 'file', 'max:512000'],
            'reply_to_message_id' => ['nullable', 'string'],
        ]);

        abort_if(! DiscordBotClient::available(), 404);

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            $sent = DiscordBotClient::sendChannelMessageWithFile(
                $data['channel_id'],
                $data['content'] ?? '',
                $file->getClientOriginalName(),
                file_get_contents($file->getRealPath()),
                $data['reply_to_message_id'] ?? null
            );
        } else {
            $sent = DiscordBotClient::sendChannelMessage($data['channel_id'], $data['content'], $data['reply_to_message_id'] ?? null);
        }

        return response()->json(['success' => $sent], $sent ? 200 : 502);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function edit(Request $request)
    {
        $data = $this->validate($request, [
            'channel_id' => ['required', 'string'],
            'message_id' => ['required', 'string'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        abort_if(! DiscordBotClient::available(), 404);

        $edited = DiscordBotClient::editMessage($data['channel_id'], $data['message_id'], $data['content']);

        return response()->json(['success' => $edited], $edited ? 200 : 502);
    }

    public function destroy(Request $request)
    {
        $channelId = $request->query('channel_id', '');
        $messageId = $request->query('message_id', '');

        abort_if(! DiscordBotClient::available() || $channelId === '' || $messageId === '', 404);

        $deleted = DiscordBotClient::deleteMessage($channelId, $messageId);

        return response()->json(['success' => $deleted], $deleted ? 200 : 502);
    }

    /**
     * Deletes several selected messages at once, via deleteBatch() below.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function bulkDestroy(Request $request)
    {
        $data = $this->validate($request, [
            'channel_id' => ['required', 'string'],
            'message_ids' => ['required', 'array', 'min:1', 'max:100'],
            'message_ids.*' => ['string'],
        ]);

        abort_if(! DiscordBotClient::available(), 404);

        $success = $this->deleteBatch($data['channel_id'], $data['message_ids']);

        return response()->json(['success' => $success], $success ? 200 : 502);
    }

    /**
     * Deletes the channel's N most recent messages ("Nettoyer"/Clean) - the
     * admin only picks a channel and a count, not individual messages.
     * Discord's bulk-delete endpoint tops out at 100 ids per call, so a
     * count above that pages backwards through history, deleting (and
     * discarding) one 100-message batch at a time until either the count is
     * reached or the channel runs out of history.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function purge(Request $request)
    {
        $data = $this->validate($request, [
            'channel_id' => ['required', 'string'],
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        abort_if(! DiscordBotClient::available(), 404);

        $channelId = $data['channel_id'];
        $remaining = $data['count'];
        $deleted = 0;
        $before = null;

        while ($remaining > 0) {
            $batchSize = min($remaining, 100);
            $messages = DiscordBotClient::channelMessages($channelId, $before, $batchSize);

            if ($messages === null || $messages === []) {
                break;
            }

            $ids = collect($messages)->pluck('id')->all();
            $before = end($ids);

            if ($this->deleteBatch($channelId, $ids)) {
                $deleted += count($ids);
            }

            $remaining -= count($ids);

            if (count($ids) < $batchSize) {
                break;
            }
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * Discord's bulk-delete endpoint requires 2-100 ids and rejects the
     * whole batch (nothing gets deleted) if any id in it is a duplicate or
     * older than 2 weeks - rather than re-checking either of those itself,
     * this calls the bulk endpoint first and, only if that fails, falls back
     * to deleting the batch one by one so a real-world mixed old/new
     * selection (or purge spanning the 2-week boundary) still succeeds.
     */
    protected function deleteBatch(string $channelId, array $ids): bool
    {
        if (count($ids) === 1) {
            return DiscordBotClient::deleteMessage($channelId, $ids[0]);
        }

        if (DiscordBotClient::bulkDeleteMessages($channelId, $ids)) {
            return true;
        }

        $success = true;

        foreach ($ids as $id) {
            $success = DiscordBotClient::deleteMessage($channelId, $id) && $success;
        }

        return $success;
    }

    /**
     * The bot's own Discord user id, cached for a day - avoids an extra
     * Discord API round-trip on every single list() call just to figure out
     * which messages the bot itself sent (an id that never changes).
     */
    protected function botUserId(): ?string
    {
        return Cache::remember('discord-integration.bot_user_id', now()->addDay(), function () {
            return DiscordBotClient::botUser()['id'] ?? null;
        });
    }
}
