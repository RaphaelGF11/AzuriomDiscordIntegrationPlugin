<?php

namespace Azuriom\Plugin\DiscordIntegration\Providers;

use Azuriom\Extensions\Plugin\BaseRouteServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends BaseRouteServiceProvider
{
    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function loadRoutes()
    {
        Route::prefix($this->plugin->id)
            ->middleware('web')
            ->name($this->plugin->id.'.')
            ->group(plugin_path($this->plugin->id.'/routes/web.php'));

        Route::prefix('admin/'.$this->plugin->id)
            ->middleware('admin-access')
            ->name($this->plugin->id.'.admin.')
            ->group(plugin_path($this->plugin->id.'/routes/admin.php'));

        // The "api" group has no session/CSRF (see app/Http/Kernel.php) -
        // needed here since Discord POSTs interactions with no CSRF token
        // and no cookies of ours to start a session from. Same mechanism
        // the Shop plugin uses for its payment gateway webhooks.
        Route::prefix('api/'.$this->plugin->id)
            ->middleware('api')
            ->name($this->plugin->id.'.')
            ->group(plugin_path($this->plugin->id.'/routes/api.php'));
    }
}
