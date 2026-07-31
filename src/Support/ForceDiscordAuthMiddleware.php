<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends visitors straight to Discord instead of the classic login/register
 * forms, when the matching "force" setting is enabled - registered globally
 * (see DiscordIntegrationServiceProvider) since it has to run ahead of
 * core's own LoginController/RegisterController, which this plugin can't
 * otherwise intercept.
 *
 * Only the GET form-display routes are redirected here; POST /login is
 * instead rejected at the credential-check level (see
 * DiscordOnlyAwareUserProvider), since a POST redirect would silently drop
 * the submitted credentials rather than explaining why login failed.
 */
class ForceDiscordAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || DiscordCredentials::clientId() === null || ! setting('discord-integration.enabled', true)) {
            return $next($request);
        }

        if ($request->routeIs('register') && setting('discord-integration.force_register', false)) {
            return redirect()->route('discord-integration.redirect', ['intent' => 'register']);
        }

        if ($request->routeIs('login') && setting('discord-integration.force_login', false)) {
            return redirect()->route('discord-integration.redirect', ['intent' => 'login']);
        }

        return $next($request);
    }
}
