<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\DiscordAccount;
use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Models\PendingLink;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LinkController extends Controller
{
    /**
     * List every Discord link, real (already OAuth-confirmed) and pending
     * (admin-declared, not confirmed by the Discord user yet - see
     * PendingLink), and offer a way to force-create the latter.
     */
    public function index()
    {
        $botAvailable = DiscordBotClient::available();

        return view('discord-integration::admin.links', [
            'botAvailable' => $botAvailable,
            'guilds' => $botAvailable ? DiscordBotClient::guilds() : null,
            'links' => DiscordAccount::with('user')->latest()->get(),
            'pendingLinks' => PendingLink::with('user')->latest()->get(),
        ]);
    }

    /**
     * Force-declare that a site account is a given Discord user, without any
     * OAuth proof from that Discord user - stored as a PendingLink until they
     * actually complete a real OAuth round-trip themselves (login, register,
     * or link from their profile), which promotes it into a real
     * DiscordAccount (see DiscordIntegrationController::callback()).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $data = $this->validate($request, [
            'username' => ['required', 'string', 'exists:users,name'],
            'discord_user_id' => ['required', 'string'],
        ]);

        $user = User::firstWhere('name', $data['username']);

        if ($user->discordAccount !== null) {
            throw ValidationException::withMessages([
                'username' => trans('discord-integration::admin.links.user_already_linked'),
            ]);
        }

        if (PendingLink::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'username' => trans('discord-integration::admin.links.user_already_pending'),
            ]);
        }

        if (! setting('discord-integration.allow_duplicates', false)) {
            $alreadyClaimed = DiscordAccount::where('discord_user_id', $data['discord_user_id'])->exists()
                || PendingLink::where('discord_user_id', $data['discord_user_id'])->exists();

            if ($alreadyClaimed) {
                throw ValidationException::withMessages([
                    'discord_user_id' => trans('discord-integration::messages.duplicate'),
                ]);
            }
        }

        $discordUser = DiscordBotClient::available() ? DiscordBotClient::user($data['discord_user_id']) : null;

        PendingLink::create([
            'user_id' => $user->id,
            'discord_user_id' => $data['discord_user_id'],
            'discord_username' => $discordUser['username'] ?? null,
            'discord_avatar' => $discordUser['avatar'] ?? null,
        ]);

        return to_route('discord-integration.admin.links')->with('success', trans('messages.status.success'));
    }

    /**
     * Cancel a pending link before the Discord user ever claims it.
     */
    public function destroyPending(PendingLink $pendingLink)
    {
        $pendingLink->delete();

        return to_route('discord-integration.admin.links')->with('success', trans('messages.status.success'));
    }

    /**
     * Members of the given guild, for the member picker modal (mirrors
     * RoleSyncController::guildRoles()).
     */
    public function guildMembers(Request $request)
    {
        $guildId = $request->query('guild_id', '');

        abort_if(! DiscordBotClient::available() || $guildId === '', 404);

        $members = DiscordBotClient::guildMembers($guildId);

        abort_if($members === null, 404);

        return response()->json(
            collect($members)
                ->filter(fn ($member) => isset($member['user']) && ! ($member['user']['bot'] ?? false))
                ->map(fn ($member) => [
                    'id' => $member['user']['id'],
                    'name' => $member['nick'] ?? $member['user']['global_name'] ?? $member['user']['username'],
                    'avatar' => DiscordAvatar::smallIconUrlFrom($member['user']),
                ])
                ->values()
        );
    }
}
