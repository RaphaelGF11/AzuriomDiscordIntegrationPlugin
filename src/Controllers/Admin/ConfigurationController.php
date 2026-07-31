<?php

namespace Azuriom\Plugin\DiscordIntegration\Controllers\Admin;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Models\Setting;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class ConfigurationController extends Controller
{
    /**
     * Show the plugin's Discord connection settings.
     */
    public function show(Request $request)
    {
        return view('discord-integration::admin.configuration', [
            'useCustomCredentials' => setting('discord-integration.use_custom_credentials', false),
            'customClientId' => setting('discord-integration.client_id'),
            'customClientSecret' => setting('discord-integration.client_secret'),
            'customBotToken' => setting('discord-integration.bot_token'),
            'botAvailable' => DiscordBotClient::available(),
            'showHttpWarning' => $this->isInsecureNonLocalUrl($request),
            'allowDuplicates' => setting('discord-integration.allow_duplicates', false),
            'syncAvatar' => setting('discord-integration.sync_avatar', false),
            'requiredGuildId' => setting('discord-integration.required_guild_id'),
            'guilds' => DiscordBotClient::available() ? DiscordBotClient::guilds() : null,
        ]);
    }

    /**
     * Discord requires HTTPS redirect URIs, except for the "localhost"/"127.0.0.1"
     * exception it makes for local development. Everything else served over plain
     * HTTP will always fail with an "invalid redirect_uri" error on Discord's side.
     */
    protected function isInsecureNonLocalUrl(Request $request): bool
    {
        if ($request->secure()) {
            return false;
        }

        return ! in_array($request->getHost(), ['localhost', '127.0.0.1'], true);
    }

    /**
     * Save the plugin's Discord connection settings.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function save(Request $request)
    {
        $this->validate($request, [
            'client_id' => ['nullable', 'required_with:use_custom_credentials', 'string'],
            'client_secret' => ['nullable', 'required_with:use_custom_credentials', 'string'],
            'required_guild_id' => ['nullable', 'string'],
        ]);

        $allowDuplicates = $request->boolean('allow_duplicates');

        // Kept mutually exclusive with "match by email" (see
        // Admin\AuthenticationController::save()) - that setting lives on a
        // different page/form now, so this checks its currently saved value
        // rather than something submitted together in this same request.
        if ($allowDuplicates && setting('discord-integration.match_by_email', false)) {
            throw ValidationException::withMessages([
                'allow_duplicates' => trans('discord-integration::admin.incompatible_with_match_by_email', [
                    'option' => trans('discord-integration::admin.match_by_email'),
                ]),
            ]);
        }

        Setting::updateSettings([
            'discord-integration.use_custom_credentials' => $request->boolean('use_custom_credentials'),
            'discord-integration.client_id' => $request->input('client_id'),
            'discord-integration.client_secret' => $request->input('client_secret'),
            'discord-integration.bot_token' => $request->input('bot_token'),
            'discord-integration.allow_duplicates' => $allowDuplicates,
            'discord-integration.sync_avatar' => $request->boolean('sync_avatar'),
            'discord-integration.required_guild_id' => $request->input('required_guild_id'),
        ]);

        return to_route('discord-integration.admin.configuration')
            ->with('success', trans('messages.status.success'));
    }

    /**
     * Verify that client_id/client_secret are a valid Discord app pair, using the
     * OAuth2 client_credentials grant. This alone cannot confirm the redirect URLs
     * are registered on Discord's side (see testCallback() below for that).
     */
    public function testCredentials()
    {
        $clientId = DiscordCredentials::clientId();
        $clientSecret = DiscordCredentials::clientSecret();

        if ($clientId === null || $clientSecret === null) {
            return to_route('discord-integration.admin.configuration')
                ->with('error', trans('discord-integration::admin.test.not_configured'));
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->post('https://discord.com/api/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'scope' => 'identify',
                ]);
        } catch (Throwable $e) {
            return to_route('discord-integration.admin.configuration')
                ->with('error', trans('discord-integration::admin.test.network_error'));
        }

        if (! $response->successful()) {
            return to_route('discord-integration.admin.configuration')
                ->with('error', trans('discord-integration::admin.test.credentials_invalid'));
        }

        return to_route('discord-integration.admin.configuration')
            ->with('success', trans('discord-integration::admin.test.credentials_ok'));
    }

    /**
     * Verify the configured bot token is valid, using GET /users/@me with
     * Bot authentication. Doesn't confirm guild-specific permissions
     * (Manage Roles, Create Instant Invite) - those can only fail later,
     * per-action, since they depend on which guild is targeted.
     */
    public function testBotToken()
    {
        if (! DiscordBotClient::available()) {
            return to_route('discord-integration.admin.configuration')
                ->with('error', trans('discord-integration::admin.test.not_configured'));
        }

        if (! DiscordBotClient::testToken()) {
            return to_route('discord-integration.admin.configuration')
                ->with('error', trans('discord-integration::admin.test.bot_token_invalid'));
        }

        return to_route('discord-integration.admin.configuration')
            ->with('success', trans('discord-integration::admin.test.bot_token_ok'));
    }

    /**
     * Start a real OAuth round-trip through the plugin's callback URL and back,
     * the only reliable way to confirm it is actually registered on the Discord
     * application: Discord validates redirect_uri when the (logged-in admin)
     * user reaches the consent step, well after the initial /authorize request,
     * so nothing short of an actual round-trip can prove it server-side. See
     * DiscordIntegrationController::callback()/handleAdminTest() for the other
     * end of this round-trip.
     */
    public function testCallback(Request $request)
    {
        abort_if(DiscordCredentials::clientId() === null, 404);

        $request->session()->put('discord-integration.admin_test', true);

        return Socialite::driver('discord-integration')
            ->scopes(['identify'])
            ->redirectUrl(route('discord-integration.callback'))
            ->redirect();
    }
}
