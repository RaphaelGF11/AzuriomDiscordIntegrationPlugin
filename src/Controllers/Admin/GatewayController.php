<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\Setting;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Azuriom\Plugin\DiscordIntegration\Support\Gateway\GatewayCache;
use Azuriom\Plugin\DiscordIntegration\Support\Gateway\ServiceInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The admin Gateway page: the enable toggle and presence editor (persisted
 * as settings the daemon re-reads live, see GatewayConnection), the live
 * connection indicator (status() below, polled by the page's JS), and the
 * systemd setup tutorial. The daemon itself is NOT managed from here - the
 * whole point of the systemd unit is that the site admin owns the process,
 * this page only owns its configuration.
 */
class GatewayController extends Controller
{
    public function show(Request $request)
    {
        return view('discord-integration::admin.gateway', [
            'botAvailable' => DiscordBotClient::available(),
            'enabled' => (bool) setting('discord-integration.gateway_enabled', false),
            'presenceStatus' => setting('discord-integration.gateway_presence_status', 'online'),
            'activityType' => setting('discord-integration.gateway_activity_type', 'none'),
            'activityText' => setting('discord-integration.gateway_activity_text'),
            'unitFileContent' => ServiceInstaller::unitFileContent(),
            'cliInstallCommand' => ServiceInstaller::cliInstallCommand(),
            'installer' => ServiceInstaller::detect(),
        ]);
    }

    public function save(Request $request)
    {
        $data = $this->validate($request, [
            'gateway_presence_status' => ['required', 'in:online,idle,dnd,invisible'],
            'gateway_activity_type' => ['required', 'in:none,playing,listening,watching,custom,competing'],
            'gateway_activity_text' => ['nullable', 'string', 'max:128'],
        ]);

        Setting::updateSettings([
            'discord-integration.gateway_enabled' => $request->boolean('gateway_enabled'),
            'discord-integration.gateway_presence_status' => $data['gateway_presence_status'],
            'discord-integration.gateway_activity_type' => $data['gateway_activity_type'],
            'discord-integration.gateway_activity_text' => $data['gateway_activity_text'] ?? null,
        ]);

        return to_route('discord-integration.admin.gateway')
            ->with('success', trans('messages.status.success'));
    }

    /**
     * Live connection status for the page's polling indicator - "connected"
     * goes through the same freshness gate the cache reads use, so a killed
     * daemon reads as disconnected here within seconds even though its last
     * status row still says connected.
     */
    public function status()
    {
        $status = GatewayCache::status();
        $connected = GatewayCache::fresh();

        return response()->json([
            'connected' => $connected,
            'since' => $connected && $status?->connected_at !== null
                ? Carbon::parse($status->connected_at)->diffForHumans()
                : null,
            'guilds' => $connected ? (int) ($status->guild_count ?? 0) : 0,
            'last_error' => $status?->last_error,
        ]);
    }

}
