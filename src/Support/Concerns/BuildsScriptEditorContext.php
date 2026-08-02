<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Concerns;

use Azuriom\Models\Role;
use Azuriom\Plugin\DiscordIntegration\Support\DiscordBotClient;
use Azuriom\Plugin\DiscordIntegration\Support\ScriptExtensions;
use Azuriom\Plugin\Shop\Models\Package;
use Illuminate\Support\Facades\Artisan;

/**
 * Shared by Admin\CommandController and Admin\FormController - both drive a
 * drag-and-drop script editor (a CustomCommand's or a Form's) needing the
 * exact same pickers: Discord guilds/roles, site roles, shop packages, and
 * the "run a server command" block's Artisan command list.
 */
trait BuildsScriptEditorContext
{
    /**
     * Command name prefixes flagged as "dangerous" in the "run a server
     * command" block's picker (see the script editor) - grayed out with a
     * warning rather than hidden, since the admin remains free to pick one
     * if they really mean to. This is a UX nudge only, not the actual
     * security boundary: CommandScriptRunner re-validates against
     * Artisan::all() at execution time regardless of what was picked here.
     */
    protected const DANGEROUS_COMMAND_PATTERNS = [
        'migrate', 'db:', 'queue:', 'key:generate', 'passport:', 'config:',
        'cache:clear', 'route:cache', 'route:clear', 'schedule:clear-cache',
        'storage:link', 'storage:unlink', 'vendor:publish', 'install:',
    ];

    /**
     * The context every script-editor view needs to render its Discord
     * guild/role/site-role/shop-package pickers and the "run a server
     * command" block's Artisan command list.
     */
    protected function builderContext(): array
    {
        $botAvailable = DiscordBotClient::available();

        return [
            'botAvailable' => $botAvailable,
            'guilds' => $botAvailable ? DiscordBotClient::guilds() : null,
            'siteRoles' => Role::orderByDesc('power')->get(),
            'shopPackages' => class_exists(Package::class) ? Package::enabled()->get(['id', 'name']) : null,
            'artisanCommands' => collect(Artisan::all())
                ->keys()
                ->sort()
                ->values()
                ->map(fn ($name) => ['name' => $name, 'dangerous' => $this->isDangerousCommand($name)]),
            // Other plugins' own registered script action/condition types -
            // see Support\ScriptExtensions. Each asset URL registers its
            // type(s) on window.CommandBuilder once <script>-included (see
            // admin.partials.script-editor-extensions).
            'scriptExtensionAssets' => ScriptExtensions::assetUrls(),
        ];
    }

    protected function isDangerousCommand(string $name): bool
    {
        foreach (self::DANGEROUS_COMMAND_PATTERNS as $pattern) {
            if (str_starts_with($name, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
