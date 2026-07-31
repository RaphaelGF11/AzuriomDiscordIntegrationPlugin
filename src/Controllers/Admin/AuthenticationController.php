<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\Setting;
use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Models\PendingLink;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * Show the plugin's login/registration behavior settings.
     */
    public function show()
    {
        return view('discord-integration::admin.authentication', [
            'enabled' => setting('discord-integration.enabled', true),
            'allowPasswordless' => setting('discord-integration.allow_passwordless', true),
            'matchByEmail' => setting('discord-integration.match_by_email', false),
            'customizableEmail' => setting('discord-integration.customizable_email', false),
            'bypassMaintenance' => setting('discord-integration.bypass_maintenance', true),
            'forceRegister' => setting('discord-integration.force_register', false),
            'forceLogin' => setting('discord-integration.force_login', false),
            'unlinkedUsersCount' => $this->unlinkedUsersCount(),
        ]);
    }

    /**
     * Save the plugin's login/registration behavior settings.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function save(Request $request)
    {
        $matchByEmail = $request->boolean('match_by_email');
        $customizableEmail = $request->boolean('customizable_email');

        // Matching accounts by email is already the weaker, opt-in fallback (see
        // DiscordIntegrationController::callback()); combining it with duplicate
        // Discord links (see Admin\ConfigurationController::save(), which lives
        // on a different page/form now) or a freely customizable registration
        // email would make that matching even less meaningful, so these options
        // are kept mutually exclusive.
        if ($matchByEmail && setting('discord-integration.allow_duplicates', false)) {
            throw ValidationException::withMessages([
                'match_by_email' => trans('discord-integration::admin.incompatible_with_match_by_email', [
                    'option' => trans('discord-integration::admin.match_by_email'),
                ]),
            ]);
        }

        if ($matchByEmail && $customizableEmail) {
            throw ValidationException::withMessages([
                'customizable_email' => trans('discord-integration::admin.incompatible_with_match_by_email', [
                    'option' => trans('discord-integration::admin.match_by_email'),
                ]),
            ]);
        }

        Setting::updateSettings([
            'discord-integration.enabled' => $request->boolean('enabled'),
            'discord-integration.allow_passwordless' => $request->boolean('allow_passwordless'),
            'discord-integration.match_by_email' => $matchByEmail,
            'discord-integration.customizable_email' => $customizableEmail,
            'discord-integration.bypass_maintenance' => $request->boolean('bypass_maintenance'),
            'discord-integration.force_register' => $request->boolean('force_register'),
        ]);

        return to_route('discord-integration.admin.authentication')
            ->with('success', trans('messages.status.success'));
    }

    /**
     * Turn on "force Discord login" (classic password login rejected for
     * everyone, /login redirects straight to Discord - see
     * ForceDiscordAuthMiddleware and DiscordOnlyAwareUserProvider). Sensitive
     * enough (a misconfiguration locks every account with no fallback) that
     * it requires the acting admin's own password and a captcha (see
     * routes/admin.php) on top of the usual permission check, and refuses to
     * turn on at all while any site account has no way to be recognized via
     * Discord yet (see unlinkedUsersCount()).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function forceLogin(Request $request)
    {
        $this->validate($request, [
            'password' => ['required', 'current_password'],
        ]);

        $unlinkedCount = $this->unlinkedUsersCount();

        if ($unlinkedCount > 0) {
            throw ValidationException::withMessages([
                'password' => trans('discord-integration::admin.force_login_precondition', ['count' => $unlinkedCount]),
            ]);
        }

        Setting::updateSettings(['discord-integration.force_login' => true]);

        return to_route('discord-integration.admin.authentication')
            ->with('success', trans('messages.status.success'));
    }

    /**
     * Turning it back off needs none of the safeguards above: it only ever
     * relaxes access, it can't lock anyone out.
     */
    public function disableForceLogin()
    {
        Setting::updateSettings(['discord-integration.force_login' => false]);

        return to_route('discord-integration.admin.authentication')
            ->with('success', trans('messages.status.success'));
    }

    /**
     * How many non-deleted site accounts have no way to be recognized via
     * Discord yet (no real link, no pending one either) - "force Discord
     * login" can't be turned on while this is above zero, or those accounts
     * would become unreachable the moment it is.
     */
    protected function unlinkedUsersCount(): int
    {
        return User::whereNull('deleted_at')
            ->whereDoesntHave('discordAccount')
            ->whereNotIn('id', PendingLink::pluck('user_id'))
            ->count();
    }
}
