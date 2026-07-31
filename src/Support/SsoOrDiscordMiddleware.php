<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Http\Middleware\SocialiteLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Core's SocialiteLogin middleware redirects straight to the site's SSO
 * provider whenever oauth_login() is enabled, before /login or /register is
 * ever reached - which would otherwise permanently hide the Discord buttons
 * this plugin adds to those pages (see the "Known limitation" in the
 * README). When Discord login is also available, this shows a small choice
 * page instead of redirecting immediately, and only defers to the normal
 * SSO redirect once the visitor actually picks it (the "?sso=1" flag) or
 * Discord isn't an option here.
 */
class SsoOrDiscordMiddleware extends SocialiteLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! oauth_login()
            || $request->query('sso') === '1'
            || ! setting('discord-integration.enabled', true)
            || DiscordCredentials::clientId() === null) {
            return parent::handle($request, $next);
        }

        return response()->view('discord-integration::choose-method', [
            'intent' => $request->routeIs('register') ? 'register' : 'login',
        ]);
    }
}
