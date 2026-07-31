<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\ActionLog;
use Azuriom\Models\DiscordAccount;
use Azuriom\Models\User;
use Azuriom\Plugin\DiscordIntegration\Models\PendingLink;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordAvatar;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordCredentials;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordPasswordless;
use Azuriom\Rules\GameAuth;
use Azuriom\Rules\Username;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DiscordIntegrationController extends Controller
{
    /**
     * How long a pending Discord login/registration stays valid in session, in seconds.
     */
    protected const PENDING_LIFETIME = 900;

    /**
     * Redirect the user to Discord to log in or register.
     */
    public function redirect(Request $request)
    {
        abort_if(DiscordCredentials::clientId() === null, 404);
        abort_if(! setting('discord-integration.enabled', true), 404);

        $intent = $request->query('intent') === 'register' ? 'register' : 'login';

        // Mirrors core's own registration toggle (setting('register'), see
        // admin/settings/auth "Enable user registration"): its route simply
        // doesn't exist when disabled (Auth::routes(['register' => ...]) in
        // routes/web.php), so an explicit "Sign up with Discord" attempt 404s
        // the same way here instead of silently creating an account anyway.
        abort_if($intent === 'register' && ! setting('register', true), 404);

        $request->session()->put('discord-integration.intent', $intent);

        return $this->driver()->redirect();
    }

    /**
     * Handle the single Discord OAuth callback, shared by the login/registration
     * flow (redirect()) and the password-confirmation flow (redirectConfirm()) -
     * one redirect_uri is simpler to register on Discord's side than two. Which
     * flow to run is decided by the "intent" stashed in session right before
     * leaving for Discord, since that's the only thing distinguishing an
     * otherwise identical callback request.
     */
    public function callback(Request $request): Response
    {
        abort_if(DiscordCredentials::clientId() === null, 404);

        if ($request->session()->pull('discord-integration.admin_test', false)) {
            return $this->handleAdminTest();
        }

        $intent = $request->session()->pull('discord-integration.intent', 'login');

        if ($intent === 'confirm') {
            return $this->handleConfirm($request);
        }

        // Only gates the login/registration flow, not the admin test above or
        // the password-confirmation flow: an account with no password already
        // relies on "Confirm with Discord" as its only way to confirm identity
        // (see overrides/auth/passwords/confirm.blade.php), so it stays usable
        // even while new Discord logins are turned off.
        abort_if(! setting('discord-integration.enabled', true), 404);

        // Not guest-only at the route level (see routes/web.php), so an already
        // authenticated, non-test visit is redirected away here instead.
        if (Auth::check()) {
            return to_route('home');
        }

        $discordUser = $this->driver()->user();

        // Guild membership (and the "guilds.join" auto-add) is enforced later,
        // only once a login or registration attempt actually gets validated -
        // see loginUser() and register() - rather than here on every raw OAuth
        // callback, which would join people to the server even when their
        // attempt was going to be rejected anyway (banned, abandoned
        // registration, maintenance mode, ...).

        // Only trust the email if Discord reports it as verified, otherwise anyone
        // could put someone else's address on their Discord account and register
        // with it here (squatting the address and blocking its real owner).
        $discordEmail = Arr::get($discordUser->getRaw(), 'verified')
            ? Arr::get($discordUser->getRaw(), 'email')
            : null;

        $accounts = DiscordAccount::where('discord_user_id', $discordUser->getId())->get();

        if ($accounts->isEmpty()) {
            // An admin previously force-linked this Discord user to a site
            // account without OAuth proof (see Admin\LinkController) - now
            // that they've actually completed a real OAuth round-trip
            // themselves, promote it into a real link and log them in,
            // rather than treating this as a brand new registration.
            $pendingLink = PendingLink::where('discord_user_id', $discordUser->getId())->first();

            if ($pendingLink !== null) {
                $account = (new DiscordAccount())->forceFill([
                    'user_id' => $pendingLink->user_id,
                    'name' => $discordUser->getNickname() ?? $discordUser->getName(),
                    'discord_user_id' => $discordUser->getId(),
                    'access_token' => $discordUser->token,
                    'refresh_token' => $discordUser->refreshToken,
                    'expires_at' => now()->addSeconds($discordUser->expiresIn),
                    'bypass_2fa' => false,
                ]);
                $account->save();

                $pendingLink->delete();

                return $this->loginAccount($request, $account, $discordEmail, $this->avatarData($discordUser));
            }

            // Opt-in fallback: match the (verified) Discord email against site
            // accounts. Only used when no explicit Discord link matched, and only
            // for logins - a register attempt keeps the normal "email already
            // used" behavior instead of silently logging into someone's account.
            if ($intent === 'login'
                && $discordEmail !== null
                && setting('discord-integration.match_by_email', false)
                // Kept mutually exclusive with duplicate links and a customizable
                // registration email (see Admin\AuthenticationController::save());
                // this check is a defensive fallback in case settings ever drift out
                // of sync with each other (e.g. edited directly in the database).
                && ! setting('discord-integration.allow_duplicates', false)
                && ! setting('discord-integration.customizable_email', false)) {
                $user = User::firstWhere('email', $discordEmail);

                if ($user !== null) {
                    return $this->loginUser($request, $user, null, null, $this->avatarData($discordUser), $discordUser->token);
                }
            }

            // No linked account and no email match: reaching this point always
            // means creating a new account next (see register()), even when the
            // user clicked "Log in with Discord" rather than "Sign up" - so this
            // is where core's registration toggle has to be enforced, not just
            // on an explicit register intent (see redirect() above).
            if (! setting('register', true)) {
                return to_route('login')->with('error', trans('discord-integration::messages.registration_disabled'));
            }

            $request->session()->put('discord-integration.pending', [
                'id' => $discordUser->getId(),
                'username' => $discordUser->getNickname() ?? $discordUser->getName(),
                'email' => $discordEmail,
                'avatar' => Arr::get($discordUser->getRaw(), 'avatar'),
                'discriminator' => Arr::get($discordUser->getRaw(), 'discriminator'),
                'access_token' => $discordUser->token,
                'refresh_token' => $discordUser->refreshToken,
                'expires_in' => $discordUser->expiresIn,
                'created_at' => now()->timestamp,
                'duplicate' => false,
            ]);

            return to_route('discord-integration.register.show');
        }

        // The user clicked "Sign up with Discord" but this Discord is already linked
        // to an account: don't silently log them in, ask what they want to do instead.
        if ($intent === 'register') {
            $request->session()->put('discord-integration.conflict', [
                'discord_user_id' => $discordUser->getId(),
                'username' => $discordUser->getNickname() ?? $discordUser->getName(),
                'user_ids' => $accounts->pluck('user_id')->all(),
                'avatar' => Arr::get($discordUser->getRaw(), 'avatar'),
                'discriminator' => Arr::get($discordUser->getRaw(), 'discriminator'),
                'access_token' => $discordUser->token,
                'refresh_token' => $discordUser->refreshToken,
                'expires_in' => $discordUser->expiresIn,
                'email' => $discordEmail,
                'created_at' => now()->timestamp,
            ]);

            return to_route('discord-integration.conflict.show');
        }

        if ($accounts->count() === 1) {
            $account = $accounts->first();

            $account->forceFill([
                'access_token' => $discordUser->token,
                'refresh_token' => $discordUser->refreshToken,
                'expires_at' => now()->addSeconds($discordUser->expiresIn),
            ])->save();

            return $this->loginAccount($request, $account, $discordEmail, $this->avatarData($discordUser));
        }

        $request->session()->put('discord-integration.choice', [
            'discord_user_id' => $discordUser->getId(),
            'user_ids' => $accounts->pluck('user_id')->all(),
            'avatar' => Arr::get($discordUser->getRaw(), 'avatar'),
            'discriminator' => Arr::get($discordUser->getRaw(), 'discriminator'),
            'access_token' => $discordUser->token,
            'refresh_token' => $discordUser->refreshToken,
            'expires_in' => $discordUser->expiresIn,
            'email' => $discordEmail,
            'created_at' => now()->timestamp,
        ]);

        return to_route('discord-integration.choose.show');
    }

    /**
     * Show the "this Discord is already linked" notice for a register attempt.
     */
    public function showConflict(Request $request)
    {
        $conflict = $this->pending($request, 'discord-integration.conflict');

        if ($conflict === null) {
            return to_route('login');
        }

        return view('discord-integration::conflict', [
            'allowDuplicates' => setting('discord-integration.allow_duplicates', false),
        ]);
    }

    /**
     * Log in to the existing account(s) linked to this Discord, from the conflict screen.
     */
    public function conflictLogin(Request $request): Response
    {
        $conflict = $this->pending($request, 'discord-integration.conflict');

        if ($conflict === null) {
            return to_route('login');
        }

        $request->session()->forget('discord-integration.conflict');

        if (count($conflict['user_ids']) === 1) {
            $account = DiscordAccount::where('discord_user_id', $conflict['discord_user_id'])
                ->where('user_id', $conflict['user_ids'][0])
                ->firstOrFail();

            $account->forceFill([
                'access_token' => $conflict['access_token'],
                'refresh_token' => $conflict['refresh_token'],
                'expires_at' => now()->addSeconds($conflict['expires_in']),
            ])->save();

            return $this->loginAccount($request, $account, $conflict['email'], [
                'id' => $conflict['discord_user_id'],
                'avatar' => $conflict['avatar'] ?? null,
                'discriminator' => $conflict['discriminator'] ?? null,
            ]);
        }

        $request->session()->put('discord-integration.choice', Arr::except($conflict, ['username']));

        return to_route('discord-integration.choose.show');
    }

    /**
     * Create a new account anyway despite this Discord already being linked,
     * only allowed when duplicate links are enabled.
     */
    public function conflictRegister(Request $request)
    {
        abort_if(! setting('discord-integration.allow_duplicates', false), 403);
        abort_if(! setting('register', true), 403);

        $conflict = $this->pending($request, 'discord-integration.conflict');

        if ($conflict === null) {
            return to_route('login');
        }

        $request->session()->forget('discord-integration.conflict');

        $request->session()->put('discord-integration.pending', [
            'id' => $conflict['discord_user_id'],
            'username' => $conflict['username'],
            'email' => $conflict['email'],
            'avatar' => $conflict['avatar'] ?? null,
            'discriminator' => $conflict['discriminator'] ?? null,
            'access_token' => $conflict['access_token'],
            'refresh_token' => $conflict['refresh_token'],
            'expires_in' => $conflict['expires_in'],
            'created_at' => now()->timestamp,
            'duplicate' => true,
        ]);

        return to_route('discord-integration.register.show');
    }

    /**
     * Show the account picker when a Discord account is linked to several users.
     */
    public function showChoose(Request $request)
    {
        $choice = $this->pending($request, 'discord-integration.choice');

        if ($choice === null) {
            return to_route('login');
        }

        return view('discord-integration::choose', [
            'users' => User::whereIn('id', $choice['user_ids'])->get(),
        ]);
    }

    /**
     * Log in the account picked by the user among the linked duplicates.
     */
    public function chooseAccount(Request $request): Response
    {
        $choice = $this->pending($request, 'discord-integration.choice');

        if ($choice === null) {
            return to_route('login');
        }

        $this->validate($request, [
            'user_id' => ['required', Rule::in($choice['user_ids'])],
        ]);

        $account = DiscordAccount::where('discord_user_id', $choice['discord_user_id'])
            ->where('user_id', $request->input('user_id'))
            ->firstOrFail();

        $account->forceFill([
            'access_token' => $choice['access_token'],
            'refresh_token' => $choice['refresh_token'],
            'expires_at' => now()->addSeconds($choice['expires_in']),
        ])->save();

        $request->session()->forget('discord-integration.choice');

        return $this->loginAccount($request, $account, $choice['email'], [
            'id' => $choice['discord_user_id'],
            'avatar' => $choice['avatar'] ?? null,
            'discriminator' => $choice['discriminator'] ?? null,
        ]);
    }

    /**
     * Show the registration completion form for a Discord account with no matching user.
     */
    public function showRegister(Request $request)
    {
        $pending = $this->pending($request, 'discord-integration.pending');

        // Defense in depth: callback() and conflictRegister() already refuse to
        // start a pending registration while disabled, but registration could
        // have been turned off after a pending one was stashed in session.
        if ($pending === null || ! setting('register', true)) {
            return to_route('login');
        }

        return view('discord-integration::register', [
            'defaultName' => $pending['username'],
            'discordEmail' => $pending['email'],
            'customizableEmail' => setting('discord-integration.customizable_email', false),
            'duplicate' => $pending['duplicate'] ?? false,
            'passwordRequired' => ! setting('discord-integration.allow_passwordless', true),
        ]);
    }

    /**
     * Complete the registration of a new account for a Discord user.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function register(Request $request): Response
    {
        $pending = $this->pending($request, 'discord-integration.pending');

        // Same defense in depth as showRegister() above.
        if ($pending === null || ! setting('register', true)) {
            return to_route('login');
        }

        $customizableEmail = setting('discord-integration.customizable_email', false);
        $passwordRequired = ! setting('discord-integration.allow_passwordless', true);

        if ($customizableEmail) {
            $data = $this->validate($request, [
                'name' => ['required', 'string', 'max:25', 'unique:users', new Username(), new GameAuth()],
                'email' => ['required', 'string', 'email', 'max:50', 'unique:users'],
                'password' => [$passwordRequired ? 'required' : 'nullable', 'confirmed', Password::default()],
            ]);

            $email = $data['email'];
        } else {
            if ($pending['email'] !== null && User::where('email', $pending['email'])->exists()) {
                throw ValidationException::withMessages([
                    'name' => trans('discord-integration::messages.register.email_used'),
                ]);
            }

            $data = $this->validate($request, [
                'name' => ['required', 'string', 'max:25', 'unique:users', new Username(), new GameAuth()],
                'password' => [$passwordRequired ? 'required' : 'nullable', 'confirmed', Password::default()],
            ]);

            $email = $pending['email'];
        }

        $password = $data['password'] ?? null;

        // The submitted registration data is valid at this point, so this is
        // where the account is considered "about to be validated" - only now
        // does joining the required Discord server (if configured) happen,
        // not speculatively back in callback() for every OAuth round-trip
        // regardless of whether registration is ever completed.
        if (($guildError = $this->ensureGuildMembership($pending['id'], $pending['access_token'])) !== null) {
            return $guildError;
        }

        // $pending['email'] is only ever populated when Discord reported it
        // verified (see callback()), so the final email matching it exactly
        // means it's already been proven to belong to this user: mark it
        // verified right away instead of sending a confirmation email for it.
        $emailVerified = $email !== null && $email === $pending['email'];

        // Pre-hashed here (rather than left to the password' => 'hashed' cast
        // on User) so the exact same hash can also be stored as the "generated
        // password" reference - see Support\DiscordPasswordless.
        $generatedPassword = $password === null ? Hash::make(Str::random(128)) : null;

        // Atomic: if the duplicate guard rejects the Discord link, the user row
        // is rolled back too instead of remaining as an orphan squatting the
        // username and email.
        $user = DB::transaction(function () use ($request, $data, $pending, $password, $generatedPassword, $email, $emailVerified) {
            $user = User::forceCreate([
                'name' => $data['name'],
                'email' => $email,
                'email_verified_at' => $emailVerified ? now() : null,
                'avatar' => setting('discord-integration.sync_avatar', false) ? DiscordAvatar::urlFrom([
                    'id' => $pending['id'],
                    'avatar' => $pending['avatar'] ?? null,
                    'discriminator' => $pending['discriminator'] ?? null,
                ]) : null,
                'password' => $generatedPassword ?? $password,
                'discord_integration_generated_password' => $generatedPassword,
                'game_id' => game()->getUserUniqueId($data['name']),
                'last_login_ip' => $request->ip(),
                'last_login_at' => now(),
            ]);

            (new DiscordAccount())->forceFill([
                'user_id' => $user->id,
                'name' => $pending['username'],
                'discord_user_id' => $pending['id'],
                'access_token' => $pending['access_token'],
                'refresh_token' => $pending['refresh_token'],
                'expires_at' => now()->addSeconds($pending['expires_in']),
                'bypass_2fa' => false,
            ])->save();

            return $user;
        });

        event(new Registered($user));

        $request->session()->forget('discord-integration.pending');

        Auth::login($user);

        return to_route('home')->with('success', trans('messages.status.success'));
    }

    /**
     * Enable or disable 2FA bypass for the current user's Discord login.
     */
    public function toggleBypass2fa(Request $request): Response
    {
        $account = $request->user()->discordAccount;

        abort_if($account === null, 404);

        $this->validate($request, ['bypass_2fa' => ['required', 'boolean']]);

        $account->forceFill(['bypass_2fa' => $request->boolean('bypass_2fa')])->save();

        return to_route('profile.index')->with('success', trans('messages.profile.updated'));
    }

    /**
     * Let a Discord-only account (no custom password yet) set a real password.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function setPassword(Request $request): Response
    {
        $account = $request->user()->discordAccount;

        abort_if($account === null || ! DiscordPasswordless::isPasswordless($request->user()), 404);

        // Setting a password grants a durable credential (and unlocks unlinking
        // Discord), so require a recent identity confirmation first — the same
        // guarantee the core gets from 'current_password' on password changes.
        // For these accounts the confirm page offers the "Confirm with Discord" button.
        $confirmedAt = time() - $request->session()->get('auth.password_confirmed_at', 0);

        if ($confirmedAt > config('auth.password_timeout', 10800)) {
            $request->session()->put('url.intended', route('profile.index'));

            return to_route('password.confirm');
        }

        $data = $this->validate($request, [
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        // No further bookkeeping needed: once users.password no longer matches
        // discord_integration_generated_password, DiscordPasswordless::isPasswordless()
        // naturally starts returning false everywhere it's checked.
        $request->user()->update(['password' => $data['password']]);

        Auth::logoutOtherDevices($data['password']);

        return to_route('profile.index')->with('success', trans('messages.profile.updated'));
    }

    /**
     * Redirect to Discord to confirm the user's identity in place of a password,
     * for accounts that have no custom password set.
     */
    public function redirectConfirm(Request $request)
    {
        $account = $request->user()->discordAccount;

        // Only accounts without a real password may substitute Discord for the
        // password confirmation; accounts with one must confirm with it.
        abort_if($account === null || ! DiscordPasswordless::isPasswordless($request->user()), 404);

        $request->session()->put('discord-integration.intent', 'confirm');

        return Socialite::driver('discord-integration')
            ->scopes(['identify'])
            ->redirectUrl(route('discord-integration.callback'))
            ->redirect();
    }

    /**
     * Handle the Discord confirmation callback, standing in for a password
     * confirmation (Illuminate\Auth\Middleware\RequirePassword). Reached
     * through the shared callback() above when intent is "confirm", so this
     * isn't behind the 'auth' middleware like the rest of the confirm flow -
     * check the user manually instead of assuming one is present.
     */
    protected function handleConfirm(Request $request): Response
    {
        if (! Auth::check()) {
            return to_route('login');
        }

        $account = $request->user()->discordAccount;

        abort_if($account === null || ! DiscordPasswordless::isPasswordless($request->user()), 404);

        $discordUser = Socialite::driver('discord-integration')
            ->scopes(['identify'])
            ->redirectUrl(route('discord-integration.callback'))
            ->user();

        if ((string) $discordUser->getId() !== (string) $account->discord_user_id) {
            return to_route('password.confirm')->with('error', trans('discord-integration::messages.confirm.mismatch'));
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('home'));
    }

    /**
     * Build the Discord Socialite driver with this plugin's own callback and scopes,
     * reusing the client_id/client_secret already configured for role linking.
     * Only requests the broader "guilds.join" scope when the guild-restriction
     * setting is actually active, so it isn't asked for needlessly otherwise.
     */
    protected function driver()
    {
        $scopes = ['identify', 'email'];

        if (setting('discord-integration.required_guild_id') !== null) {
            $scopes[] = 'guilds.join';
        }

        return Socialite::driver('discord-integration')
            ->scopes($scopes)
            ->redirectUrl(route('discord-integration.callback'));
    }

    /**
     * When "restrict to members of a server" is configured, make sure this
     * Discord user is (or, thanks to the "guilds.join" scope above, can be
     * made) a member of that guild before letting them go any further -
     * applies to every login/register attempt, not just the first link, so
     * someone who later leaves the guild is caught too.
     *
     * Called from loginUser() and register() once their own checks have
     * passed, not from callback() itself - joining someone to the server is
     * a side effect that should only happen once the login/registration it's
     * tied to is actually going to succeed (see the callers for why).
     *
     * Returns a redirect response to abort the flow with, or null to continue.
     */
    protected function ensureGuildMembership(?string $discordUserId, ?string $accessToken): ?Response
    {
        $guildId = setting('discord-integration.required_guild_id');

        if ($guildId === null) {
            return null;
        }

        // A null result covers both "not a member" and any other API failure -
        // either way, attempting to add them is the right next step, and if
        // that also fails we fall through to the same "please join" error.
        if (DiscordBotClient::guildMemberRoles($guildId, $discordUserId) !== null) {
            return null;
        }

        if ($accessToken !== null && DiscordBotClient::addGuildMember($guildId, $discordUserId, $accessToken)) {
            return null;
        }

        return to_route('login')->with('error', trans('discord-integration::messages.guild_required'));
    }

    /**
     * Handle a request initiated from the admin "test configuration" button
     * (see Admin\ConfigurationController::testCallback()): reaching this code
     * at all proves Discord accepted the callback URL as a registered redirect URI.
     */
    protected function handleAdminTest(): Response
    {
        try {
            $discordUser = Socialite::driver('discord-integration')
                ->scopes(['identify'])
                ->redirectUrl(url()->current())
                ->user();
        } catch (Throwable $e) {
            return to_route('discord-integration.admin.configuration')
                ->with('error', trans('discord-integration::admin.test.callback_failed'));
        }

        return to_route('discord-integration.admin.configuration')->with('success', trans(
            'discord-integration::admin.test.callback_ok',
            ['name' => $discordUser->getNickname() ?? $discordUser->getName()]
        ));
    }

    /**
     * Retrieve a session payload, discarding it if it has expired.
     */
    protected function pending(Request $request, string $key): ?array
    {
        $data = $request->session()->get($key);

        if ($data === null) {
            return null;
        }

        if (now()->timestamp - Arr::get($data, 'created_at', 0) > self::PENDING_LIFETIME) {
            $request->session()->forget($key);

            return null;
        }

        return $data;
    }

    /**
     * Log in the user behind a linked Discord account, honoring bans, maintenance
     * mode and the per-account 2FA bypass setting.
     */
    protected function loginAccount(Request $request, DiscordAccount $account, ?string $discordEmail, array $discordAvatarData = []): Response
    {
        return $this->loginUser($request, $account->user, $account, $discordEmail, $discordAvatarData);
    }

    /**
     * Log in a user matched via Discord (by explicit link, or by verified email
     * when that fallback is enabled - in which case there is no DiscordAccount
     * row and the 2FA bypass never applies).
     */
    protected function loginUser(Request $request, ?User $user, ?DiscordAccount $account, ?string $discordEmail, array $discordAvatarData = [], ?string $discordAccessToken = null): Response
    {
        // Same gates as the core password login: deleted accounts (stale
        // discord_accounts rows from before this plugin cleaned them up) and
        // admin-forced password resets must not be bypassable via Discord.
        if ($user === null || $user->isDeleted()) {
            return to_route('login')->with('error', trans('auth.failed'));
        }

        if ($user->mustChangePassword()) {
            return to_route('login')->with('error', trans('passwords.change'));
        }

        if ($user->isBanned()) {
            return to_route('login')->with('error', trans('auth.suspended'));
        }

        if ($this->isMaintenance($user)) {
            return to_route('login')->with('error', trans('auth.maintenance'));
        }

        // Every other guard above has passed, so this login is about to be
        // validated - only now does joining the required Discord server (if
        // configured) happen, see ensureGuildMembership().
        if (($guildError = $this->ensureGuildMembership($discordAvatarData['id'] ?? null, $discordAccessToken ?? $account?->access_token)) !== null) {
            return $guildError;
        }

        if ($user->hasTwoFactorAuth() && ! ($account?->bypass_2fa)) {
            $request->session()->put('login.2fa', [
                'id' => $user->id,
                'remember' => true,
            ]);

            return to_route('login.2fa');
        }

        Auth::login($user, true);

        $attributes = [
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ];

        if (setting('discord-integration.sync_avatar', false) && $discordAvatarData !== []) {
            $attributes['avatar'] = DiscordAvatar::urlFrom($discordAvatarData);
        }

        $user->forceFill($attributes)->save();

        ActionLog::log('users.login', null, [
            'ip' => $request->ip(),
            '2fa' => $user->hasTwoFactorAuth() ? 'on' : 'off',
        ]);

        // The shared layout only supports "success"/"error" flash messages, so the
        // email mismatch is surfaced as the login's success message rather than
        // blocking the login (as requested: warn, but let the user in).
        $message = ($discordEmail !== null && $discordEmail !== $user->email)
            ? trans('discord-integration::messages.email_mismatch')
            : trans('messages.status.success');

        return to_route('home')->with('success', $message);
    }

    /**
     * The subset of a Socialite Discord user's raw data DiscordAvatar::urlFrom()
     * needs, used both directly (fresh $discordUser in this request) and
     * reconstructed from what's stashed in session (conflict/choice payloads).
     */
    protected function avatarData($discordUser): array
    {
        return [
            'id' => $discordUser->getId(),
            'avatar' => Arr::get($discordUser->getRaw(), 'avatar'),
            'discriminator' => Arr::get($discordUser->getRaw(), 'discriminator'),
        ];
    }

    protected function isMaintenance(User $user): bool
    {
        if (! setting('maintenance.enabled', false)) {
            return false;
        }

        if (setting('discord-integration.bypass_maintenance', true)) {
            return false;
        }

        if ($user->can('maintenance.access')) {
            return false;
        }

        $paths = setting('maintenance.paths', []);

        if (empty($paths)) {
            return true;
        }

        return Arr::first($paths, function (string $path) {
            return Str::startsWith(trim($path, '/'), 'user/login');
        }) !== null;
    }
}
