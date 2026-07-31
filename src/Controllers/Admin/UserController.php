<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Models\DiscordAccount;
use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordPasswordless;
use Azuriom\Plugin\DiscordIntegration\Support\RoleSyncEvaluator;
use Azuriom\Support\Discord\LinkedRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * Force-unlink a passwordless account's Discord link from the admin
     * panel, bypassing the guard that normally prevents it (that guard
     * exists precisely because doing this removes the account's only way
     * to log in - see DiscordIntegrationServiceProvider::registerUnlinkGuard()).
     * Gated by a captcha (see routes/admin.php) and, in the admin UI, a
     * confirmation delay. The admin is then responsible for giving the
     * account a new way to log in (e.g. setting a password below on the
     * same page) - this action does not do it for them.
     */
    public function forceUnlinkDiscord(User $user, RoleSyncEvaluator $evaluator): Response
    {
        $account = $user->discordAccount;

        abort_if($account === null || ! DiscordPasswordless::isPasswordless($user), 404);

        LinkedRoles::clearRole($account);
        $evaluator->revokeGrantedRoles($account->discord_user_id);

        DiscordAccount::whereKey($account->getKey())->delete();

        // No bookkeeping needed here anymore: discord_integration_generated_password
        // lives on the user row, so DiscordPasswordless::isPasswordless() keeps
        // returning true after the discord_accounts row above is gone.
        ActionLog::log('users.updated', $user)?->createEntries(
            ['discord' => $account->name],
            ['discord' => null]
        );

        return to_route('admin.users.edit', $user)
            ->with('success', trans('messages.status.success'));
    }

    /**
     * Send a direct message to the user's linked Discord account.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function sendDirectMessage(Request $request, User $user): Response
    {
        $account = $user->discordAccount;

        abort_if($account === null || ! DiscordBotClient::available(), 404);

        $data = $this->validate($request, [
            'content' => ['required', 'string', 'max:2000'],
        ]);

        if (! DiscordBotClient::sendDirectMessage($account->discord_user_id, $data['content'])) {
            return to_route('admin.users.edit', $user)
                ->with('error', trans('discord-integration::admin.tools.dm.failed'));
        }

        return to_route('admin.users.edit', $user)
            ->with('success', trans('discord-integration::admin.tools.dm.sent'));
    }

    /**
     * Generate a fresh random password, force it to be changed at the next
     * login, and send it to the user via Discord DM. Optionally invalidates
     * the user's currently open sessions.
     */
    public function sendRecoveryPassword(Request $request, User $user): Response
    {
        $account = $user->discordAccount;

        abort_if($account === null || ! DiscordBotClient::available(), 404);

        $password = Str::password(24, symbols: false);

        if (! DiscordBotClient::sendDirectMessage($account->discord_user_id, trans('discord-integration::messages.tools.recovery_dm', [
            'site' => site_name(),
            'password' => $password,
        ]))) {
            return to_route('admin.users.edit', $user)
                ->with('error', trans('discord-integration::admin.tools.dm.failed'));
        }

        // Forces the password-change screen at the next login, mirroring core's
        // own admin "force password change" action (UserController::forcePasswordChange()).
        $user->update(['password' => $password, 'password_changed_at' => null]);

        if ($request->boolean('invalidate_sessions')) {
            $this->invalidateSessions($user);
        }

        ActionLog::log('users.updated', $user)?->createEntries(
            ['password' => '**old**'],
            ['password' => '**recovery**']
        );

        return to_route('admin.users.edit', $user)
            ->with('success', trans('discord-integration::admin.tools.recovery_password.sent'));
    }

    /**
     * Refresh the cached Discord username from Discord, using the account's
     * own OAuth token (no bot needed) - e.g. after the user renamed themselves
     * on Discord.
     */
    public function refreshDiscordInfo(User $user): Response
    {
        $account = $user->discordAccount;

        abort_if($account === null, 404);

        $response = Http::withToken($account->refreshAccessToken())
            ->acceptJson()
            ->get('https://discord.com/api/v10/users/@me');

        if ($response->failed()) {
            return to_route('admin.users.edit', $user)
                ->with('error', trans('discord-integration::admin.tools.refresh.failed'));
        }

        $discriminator = $response->json('discriminator');
        $username = $response->json('username').($discriminator && $discriminator !== '0' ? '#'.$discriminator : '');

        $account->forceFill(['name' => $username])->save();

        if (setting('discord-integration.sync_avatar', false)) {
            $user->forceFill(['avatar' => DiscordAvatar::urlFrom([
                'id' => $response->json('id'),
                'avatar' => $response->json('avatar'),
                'discriminator' => $discriminator,
            ])])->save();
        }

        return to_route('admin.users.edit', $user)
            ->with('success', trans('discord-integration::admin.tools.refresh.done'));
    }

    /**
     * Regenerate this user's 2FA recovery codes and send them via Discord DM.
     */
    public function send2faRecoveryCodes(User $user): Response
    {
        $account = $user->discordAccount;

        abort_if($account === null || ! DiscordBotClient::available(), 404);
        abort_if(! $user->hasTwoFactorAuth(), 404);

        $codes = $user->generateRecoveryCodes();

        if (! DiscordBotClient::sendDirectMessage($account->discord_user_id, trans('discord-integration::messages.tools.recovery_codes_dm', [
            'site' => site_name(),
            'codes' => implode("\n", $codes),
        ]))) {
            return to_route('admin.users.edit', $user)
                ->with('error', trans('discord-integration::admin.tools.dm.failed'));
        }

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return to_route('admin.users.edit', $user)
            ->with('success', trans('discord-integration::admin.tools.recovery_codes.sent'));
    }

    /**
     * Best-effort logout of the user's other sessions: rotates the "remember
     * me" token (invalidates persistent auto-logins on any session driver),
     * and additionally purges their rows from the sessions table when using
     * the database session driver (the only driver that can be targeted by
     * user_id - file/cookie/array sessions have no such index).
     */
    protected function invalidateSessions(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
