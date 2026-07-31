<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Gateway;

use Azuriom\Models\DiscordAccount;
use Azuriom\Plugin\DiscordIntegration\Support\RoleSyncEvaluator;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns gateway dispatch events into cache writes (via GatewayCache) and
 * instant role-sync triggers. Holds the authoritative in-memory mirror of
 * everything cached - the daemon is the only writer, so mirroring in memory
 * avoids read-modify-write cycles against the DB rows entirely; the mirror
 * is rebuilt from scratch on every fresh identify (Discord re-sends every
 * GUILD_CREATE then), and survives resumes (same process, missed events are
 * replayed). Deliberately socket-free: GatewayConnection feeds it decoded
 * events and a chunk-request callback, nothing more.
 */
class GatewayEventHandler
{
    /**
     * How long to sit on a member change before reconciling their roles.
     * Discord fires GUILD_MEMBER_UPDATE for the plugin's *own* role writes
     * too, so reconciling immediately would echo one extra (harmless but
     * pointless) reconciliation after each sync; coalescing member events
     * per user for a few seconds absorbs that echo and any event burst.
     */
    protected const RECONCILE_DEBOUNCE_SECONDS = 3.0;

    /** @var array<string, array> guild id => {id, name, icon} */
    protected array $guilds = [];

    /** @var array<string, array<string, array>> guild id => role id => role */
    protected array $roles = [];

    /** @var array<string, array<string, array>> guild id => channel id => channel */
    protected array $channels = [];

    /** @var array<string, array<string, array>> guild id => user id => projected member */
    protected array $members = [];

    /**
     * Whether a guild's member roster has been fully received (all
     * GUILD_MEMBERS_CHUNKs in) - the members row is only trustworthy as a
     * REST replacement once complete, since "absent from the map" must mean
     * "not a member" (see GatewayCache::memberRoles()).
     *
     * @var array<string, bool>
     */
    protected array $membersComplete = [];

    /** @var array<string, float> discord user id => first event time (microtime) */
    protected array $pendingReconciles = [];

    /**
     * Set by GatewayConnection - asks Discord for a guild's full member
     * list (op 8), delivered back through GUILD_MEMBERS_CHUNK events.
     */
    protected ?Closure $chunkRequester = null;

    public function setChunkRequester(Closure $chunkRequester): void
    {
        $this->chunkRequester = $chunkRequester;
    }

    /**
     * Reset the in-memory mirror and the cached snapshots - called on every
     * fresh identify, since Discord is about to re-send the full state.
     */
    public function reset(): void
    {
        $this->guilds = $this->roles = $this->channels = $this->members = $this->membersComplete = [];
        GatewayCache::purge();
        GatewayCache::setGuildCount(0);
    }

    public function handle(string $event, array $data): void
    {
        try {
            match ($event) {
                'GUILD_CREATE' => $this->guildCreate($data),
                'GUILD_UPDATE' => $this->guildUpdate($data),
                'GUILD_DELETE' => $this->guildDelete($data),
                'GUILD_ROLE_CREATE', 'GUILD_ROLE_UPDATE' => $this->roleUpsert($data),
                'GUILD_ROLE_DELETE' => $this->roleDelete($data),
                'CHANNEL_CREATE', 'CHANNEL_UPDATE' => $this->channelUpsert($data),
                'CHANNEL_DELETE' => $this->channelDelete($data),
                'GUILD_MEMBER_ADD' => $this->memberUpsert($data['guild_id'] ?? null, $data, queueSync: true),
                'GUILD_MEMBER_UPDATE' => $this->memberUpsert($data['guild_id'] ?? null, $data, queueSync: true),
                'GUILD_MEMBER_REMOVE' => $this->memberRemove($data),
                'GUILD_MEMBERS_CHUNK' => $this->membersChunk($data),
                default => null,
            };
        } catch (Throwable $e) {
            // One malformed/unexpected event must never take the whole
            // gateway session down with it.
            Log::warning('discord-integration: gateway event handling failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reconcile the roles of every user whose member events have settled
     * past the debounce window - called from the connection's tick loop.
     */
    public function flushPendingReconciles(): void
    {
        foreach ($this->pendingReconciles as $discordUserId => $firstSeenAt) {
            if (microtime(true) - $firstSeenAt < self::RECONCILE_DEBOUNCE_SECONDS) {
                continue;
            }

            unset($this->pendingReconciles[$discordUserId]);

            try {
                $user = DiscordAccount::where('discord_user_id', $discordUserId)->first()?->user;

                if ($user !== null) {
                    app(RoleSyncEvaluator::class)->reconcileUser($user);
                }
            } catch (Throwable $e) {
                Log::warning('discord-integration: gateway-triggered role sync failed', [
                    'discord_user_id' => $discordUserId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function guildCreate(array $guild): void
    {
        $guildId = $guild['id'];

        $this->guilds[$guildId] = [
            'id' => $guildId,
            'name' => $guild['name'] ?? '',
            'icon' => $guild['icon'] ?? null,
        ];

        $this->roles[$guildId] = collect($guild['roles'] ?? [])->keyBy('id')->all();
        $this->channels[$guildId] = collect($guild['channels'] ?? [])->keyBy('id')->all();

        // GUILD_CREATE only carries a handful of members (typically just the
        // bot) - the full roster arrives via the op 8 chunk request below.
        $this->members[$guildId] = [];
        $this->membersComplete[$guildId] = false;

        foreach ($guild['members'] ?? [] as $member) {
            if (isset($member['user']['id'])) {
                $this->members[$guildId][$member['user']['id']] = $this->projectMember($member);
            }
        }

        // The "guild" row keeps the full object minus its bulky arrays -
        // REST's GET /guilds/{id} doesn't include those either, so this
        // matches what guild() callers (e.g. premium_tier) actually expect.
        $guildRow = array_diff_key($guild, array_flip([
            'roles', 'channels', 'threads', 'members', 'presences', 'voice_states',
            'emojis', 'stickers', 'stage_instances', 'guild_scheduled_events', 'soundboard_sounds',
        ]));

        GatewayCache::put($guildId, 'guild', $guildRow);
        $this->writeRoles($guildId);
        $this->writeChannels($guildId);
        $this->writeMembers($guildId);
        $this->writeGuildList();

        if ($this->chunkRequester !== null) {
            ($this->chunkRequester)($guildId);
        }
    }

    protected function guildUpdate(array $guild): void
    {
        $guildId = $guild['id'];

        $this->guilds[$guildId] = [
            'id' => $guildId,
            'name' => $guild['name'] ?? ($this->guilds[$guildId]['name'] ?? ''),
            'icon' => $guild['icon'] ?? null,
        ];

        // GUILD_UPDATE carries the full (non-bulky) guild object.
        $existing = GatewayCache::raw($guildId, 'guild');
        GatewayCache::put($guildId, 'guild', array_merge($existing ?? [], array_diff_key($guild, array_flip(['roles', 'channels', 'members']))));
        $this->writeGuildList();
    }

    protected function guildDelete(array $data): void
    {
        $guildId = $data['id'] ?? null;

        if ($guildId === null) {
            return;
        }

        // Covers both "bot removed from guild" and "guild outage"
        // (unavailable: true) the same way: drop the data now, a
        // GUILD_CREATE will rebuild it when/if the guild comes back.
        unset($this->guilds[$guildId], $this->roles[$guildId], $this->channels[$guildId], $this->members[$guildId], $this->membersComplete[$guildId]);
        GatewayCache::forgetGuild($guildId);
        $this->writeGuildList();
    }

    protected function roleUpsert(array $data): void
    {
        $guildId = $data['guild_id'] ?? null;

        if ($guildId === null || ! isset($data['role']['id'])) {
            return;
        }

        $this->roles[$guildId][$data['role']['id']] = $data['role'];
        $this->writeRoles($guildId);
    }

    protected function roleDelete(array $data): void
    {
        $guildId = $data['guild_id'] ?? null;

        if ($guildId === null || ! isset($data['role_id'])) {
            return;
        }

        unset($this->roles[$guildId][$data['role_id']]);
        $this->writeRoles($guildId);
    }

    protected function channelUpsert(array $channel): void
    {
        $guildId = $channel['guild_id'] ?? null;

        // DM channel events have no guild_id - out of scope, same as the
        // REST channel list this cache mirrors.
        if ($guildId === null || ! isset($channel['id'])) {
            return;
        }

        $this->channels[$guildId][$channel['id']] = $channel;
        $this->writeChannels($guildId);
    }

    protected function channelDelete(array $channel): void
    {
        $guildId = $channel['guild_id'] ?? null;

        if ($guildId === null || ! isset($channel['id'])) {
            return;
        }

        unset($this->channels[$guildId][$channel['id']]);
        $this->writeChannels($guildId);
    }

    protected function memberUpsert(?string $guildId, array $member, bool $queueSync = false): void
    {
        $userId = $member['user']['id'] ?? null;

        if ($guildId === null || $userId === null || ! isset($this->members[$guildId])) {
            return;
        }

        $this->members[$guildId][$userId] = $this->projectMember($member);
        $this->writeMembers($guildId);

        if ($queueSync) {
            $this->queueReconcile($userId);
        }
    }

    protected function memberRemove(array $data): void
    {
        $guildId = $data['guild_id'] ?? null;
        $userId = $data['user']['id'] ?? null;

        if ($guildId === null || $userId === null) {
            return;
        }

        unset($this->members[$guildId][$userId]);
        $this->writeMembers($guildId);
        $this->queueReconcile($userId);
    }

    protected function membersChunk(array $data): void
    {
        $guildId = $data['guild_id'] ?? null;

        if ($guildId === null || ! isset($this->members[$guildId])) {
            return;
        }

        foreach ($data['members'] ?? [] as $member) {
            if (isset($member['user']['id'])) {
                $this->members[$guildId][$member['user']['id']] = $this->projectMember($member);
            }
        }

        // Chunks arrive indexed - the roster is only complete (and therefore
        // only trustworthy as a REST replacement) once the last one lands.
        if (($data['chunk_index'] ?? 0) === ($data['chunk_count'] ?? 1) - 1) {
            $this->membersComplete[$guildId] = true;
        }

        // One write per chunk (up to 1000 members each), not per member.
        $this->writeMembers($guildId);
    }

    /**
     * The subset of a member object every existing guildMembers() consumer
     * reads (LinkController's picker, MessageController::guildMembersInfo(),
     * DiscordAvatar) - same nested "user" shape as REST, just without the
     * bulky/irrelevant leftovers.
     */
    protected function projectMember(array $member): array
    {
        $user = $member['user'] ?? [];

        return [
            'user' => [
                'id' => $user['id'] ?? null,
                'username' => $user['username'] ?? null,
                'discriminator' => $user['discriminator'] ?? null,
                'global_name' => $user['global_name'] ?? null,
                'avatar' => $user['avatar'] ?? null,
                'bot' => $user['bot'] ?? false,
                'public_flags' => $user['public_flags'] ?? 0,
            ],
            'nick' => $member['nick'] ?? null,
            'avatar' => $member['avatar'] ?? null,
            'roles' => $member['roles'] ?? [],
            'joined_at' => $member['joined_at'] ?? null,
        ];
    }

    protected function queueReconcile(string $discordUserId): void
    {
        // Keep the FIRST event's timestamp - re-stamping on every event in a
        // burst would push the flush out indefinitely.
        $this->pendingReconciles[$discordUserId] ??= microtime(true);
    }

    protected function writeGuildList(): void
    {
        GatewayCache::put(null, 'guilds', array_values($this->guilds));
        GatewayCache::setGuildCount(count($this->guilds));
    }

    protected function writeRoles(string $guildId): void
    {
        GatewayCache::put($guildId, 'roles', array_values($this->roles[$guildId] ?? []));
    }

    protected function writeChannels(string $guildId): void
    {
        GatewayCache::put($guildId, 'channels', array_values($this->channels[$guildId] ?? []));
    }

    protected function writeMembers(string $guildId): void
    {
        GatewayCache::put($guildId, 'members', [
            'complete' => $this->membersComplete[$guildId] ?? false,
            'members' => $this->members[$guildId] ?? [],
        ]);
    }
}
