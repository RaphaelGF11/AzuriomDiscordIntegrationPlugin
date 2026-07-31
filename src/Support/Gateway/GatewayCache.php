<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Gateway;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read/write API over the two gateway tables (see the gateway migration):
 * the daemon (GatewayCommand/GatewayConnection/GatewayEventHandler) writes
 * snapshots and heartbeats here, and DiscordBotClient reads them back as a
 * local alternative to REST while the gateway is connected. Reads are gated
 * on freshness (see fresh()) so a dead or stopped daemon automatically
 * degrades every caller back to plain REST within seconds - no caller ever
 * needs to know whether the gateway feature is even enabled.
 */
class GatewayCache
{
    /**
     * How stale the daemon's heartbeat may be before cached data stops
     * being served. The daemon touches the heartbeat every few seconds
     * while connected, so 90s tolerates a couple of missed writes without
     * ever serving data from a connection that's actually gone.
     */
    protected const FRESHNESS_SECONDS = 90;

    /**
     * A cached payload, or null unless the daemon is live (fresh()) AND the
     * row exists - the "?? REST" pattern in DiscordBotClient relies on null
     * meaning "no usable cache, fall through".
     */
    public static function get(?string $guildId, string $kind): ?array
    {
        if (! static::fresh()) {
            return null;
        }

        $row = DB::table('discord_integration_gateway_cache')
            ->where('kind', $kind)
            ->when($guildId === null, fn ($query) => $query->whereNull('guild_id'))
            ->when($guildId !== null, fn ($query) => $query->where('guild_id', $guildId))
            ->first();

        if ($row === null) {
            return null;
        }

        $payload = json_decode($row->payload, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * The full member roster of a guild in REST guildMembers() shape, or
     * null unless the roster is COMPLETE (all member chunks received) - a
     * partial roster must never be served where "absent" is meaningful.
     */
    public static function members(string $guildId): ?array
    {
        $payload = static::get($guildId, 'members');

        if ($payload === null || ! ($payload['complete'] ?? false)) {
            return null;
        }

        return array_values($payload['members'] ?? []);
    }

    /**
     * A member's role ids, with three distinct outcomes mirroring what
     * guildMemberRoles() needs: an array (their roles), null (complete
     * roster and they're NOT in it - authoritative, matches REST's 404
     * semantics, so the caller must NOT fall back to REST), or false (no
     * usable cache - the caller should use REST).
     */
    public static function memberRoles(string $guildId, string $discordUserId): array|null|false
    {
        $payload = static::get($guildId, 'members');

        if ($payload === null || ! ($payload['complete'] ?? false)) {
            return false;
        }

        $member = $payload['members'][$discordUserId] ?? null;

        return $member === null ? null : ($member['roles'] ?? []);
    }

    /**
     * Ungated read for the daemon's own bookkeeping - the daemon may need a
     * row before it has marked itself connected, so the freshness gate the
     * web-facing get() applies would get in its way.
     */
    public static function raw(?string $guildId, string $kind): ?array
    {
        $row = DB::table('discord_integration_gateway_cache')
            ->where('kind', $kind)
            ->when($guildId === null, fn ($query) => $query->whereNull('guild_id'))
            ->when($guildId !== null, fn ($query) => $query->where('guild_id', $guildId))
            ->first();

        if ($row === null) {
            return null;
        }

        $payload = json_decode($row->payload, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * How long a fresh()/stale verdict is reused before re-querying the
     * status row - a page rendering several pickers would otherwise pay one
     * extra DB round-trip per cached read just to re-learn the same answer.
     * Kept short since this memo also lives inside the daemon process (via
     * its role-sync path) where a longer one could go stale meaningfully.
     */
    protected const FRESHNESS_MEMO_SECONDS = 5;

    protected static ?bool $freshMemo = null;

    protected static float $freshMemoExpiresAt = 0.0;

    /**
     * Whether the daemon reports itself connected with a recent heartbeat -
     * the single freshness gate for every cached read, also what the admin
     * page's status endpoint reports as "connected".
     */
    public static function fresh(): bool
    {
        if (static::$freshMemo !== null && microtime(true) < static::$freshMemoExpiresAt) {
            return static::$freshMemo;
        }

        $status = static::status();

        $fresh = $status !== null
            && $status->connected
            && $status->last_heartbeat_at !== null
            && Carbon::parse($status->last_heartbeat_at)->gt(now()->subSeconds(static::FRESHNESS_SECONDS));

        static::$freshMemo = $fresh;
        static::$freshMemoExpiresAt = microtime(true) + static::FRESHNESS_MEMO_SECONDS;

        return $fresh;
    }

    public static function status(): ?object
    {
        return DB::table('discord_integration_gateway_status')->find(1);
    }

    /* ---------- Daemon-side writes ---------- */

    public static function put(?string $guildId, string $kind, array $payload): void
    {
        DB::table('discord_integration_gateway_cache')->updateOrInsert(
            ['guild_id' => $guildId, 'kind' => $kind],
            ['payload' => json_encode($payload), 'updated_at' => now()]
        );
    }

    public static function forgetGuild(string $guildId): void
    {
        DB::table('discord_integration_gateway_cache')->where('guild_id', $guildId)->delete();
    }

    /**
     * Wipe every snapshot - called when a session ends for good (disabled,
     * or identify required), so the next session starts from scratch
     * instead of serving whatever the old session last saw.
     */
    public static function purge(): void
    {
        DB::table('discord_integration_gateway_cache')->delete();
    }

    public static function markConnected(?string $sessionId): void
    {
        DB::table('discord_integration_gateway_status')->where('id', 1)->update([
            'connected' => true,
            'connected_at' => now(),
            'last_heartbeat_at' => now(),
            'last_error' => null,
            'session_id' => $sessionId,
        ]);
    }

    public static function markDisconnected(?string $error = null): void
    {
        DB::table('discord_integration_gateway_status')->where('id', 1)->update([
            'connected' => false,
            'last_error' => $error !== null ? mb_substr($error, 0, 255) : null,
        ]);
    }

    /**
     * Touch the liveness timestamp - the daemon calls this every few
     * seconds from its tick loop, NOT only on Discord heartbeats (those are
     * ~41s apart, too coarse for a 90s freshness window to feel responsive).
     */
    public static function heartbeat(): void
    {
        DB::table('discord_integration_gateway_status')->where('id', 1)->update([
            'connected' => true,
            'last_heartbeat_at' => now(),
        ]);
    }

    public static function setGuildCount(int $count): void
    {
        DB::table('discord_integration_gateway_status')->where('id', 1)->update(['guild_count' => $count]);
    }
}
