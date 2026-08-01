<?php

use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\AuthenticationController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\CommandController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\ConfigurationController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\FormController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\GatewayController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\LinkController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\LogController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\MessageController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\RoleSyncController;
use Azuriom\Plugin\DiscordIntegration\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your plugin. These
| routes are loaded by the RouteServiceProvider of your plugin within
| a group which contains the "admin-access" middleware and your plugin
| name as prefix.
|
*/

Route::middleware('can:discord-integration.admin')->group(function () {
    Route::get('/configuration', [ConfigurationController::class, 'show'])->name('configuration');
    Route::post('/configuration', [ConfigurationController::class, 'save'])->name('configuration.save');

    Route::post('/configuration/test-credentials', [ConfigurationController::class, 'testCredentials'])
        ->name('configuration.test-credentials');

    Route::post('/configuration/test-bot-token', [ConfigurationController::class, 'testBotToken'])
        ->name('configuration.test-bot-token');

    Route::get('/configuration/test-callback', [ConfigurationController::class, 'testCallback'])
        ->name('configuration.test-callback');

    Route::get('/authentication', [AuthenticationController::class, 'show'])->name('authentication');
    Route::post('/authentication', [AuthenticationController::class, 'save'])->name('authentication.save');

    Route::post('/authentication/force-login', [AuthenticationController::class, 'forceLogin'])
        ->name('authentication.force-login')
        ->middleware('captcha');

    Route::delete('/authentication/force-login', [AuthenticationController::class, 'disableForceLogin'])
        ->name('authentication.force-login.disable');

    Route::get('/roles', [RoleSyncController::class, 'index'])->name('roles');
    Route::post('/roles', [RoleSyncController::class, 'store'])->name('roles.store');
    Route::put('/roles/{roleSync}', [RoleSyncController::class, 'update'])->name('roles.update');
    Route::delete('/roles/{roleSync}', [RoleSyncController::class, 'destroy'])->name('roles.destroy');

    Route::get('/roles/guild-roles', [RoleSyncController::class, 'guildRoles'])->name('roles.guild-roles');

    Route::get('/roles/export', [RoleSyncController::class, 'export'])->name('roles.export');
    Route::post('/roles/import', [RoleSyncController::class, 'import'])->name('roles.import');

    Route::get('/links', [LinkController::class, 'index'])->name('links');
    Route::post('/links', [LinkController::class, 'store'])->name('links.store');
    Route::delete('/links/{pendingLink}', [LinkController::class, 'destroyPending'])->name('links.destroy-pending');
    Route::get('/links/guild-members', [LinkController::class, 'guildMembers'])->name('links.guild-members');

    Route::get('/commands', [CommandController::class, 'index'])->name('commands');
    Route::post('/commands/public-key', [CommandController::class, 'savePublicKey'])->name('commands.public-key');
    Route::post('/commands', [CommandController::class, 'store'])->name('commands.store');
    Route::put('/commands/{command}', [CommandController::class, 'update'])->name('commands.update');
    Route::delete('/commands/{command}', [CommandController::class, 'destroy'])->name('commands.destroy');
    Route::get('/commands/guild-roles', [CommandController::class, 'guildRoles'])->name('commands.guild-roles');
    Route::get('/commands/guild-channels', [CommandController::class, 'guildChannels'])->name('commands.guild-channels');

    Route::get('/commands/{command}/script', [CommandController::class, 'editScript'])->name('commands.script');
    Route::put('/commands/{command}/script', [CommandController::class, 'updateScript'])->name('commands.script.update');

    // No "forms" index route - the list is folded into commands' index()
    // (the combined "Interactions" page), see CommandController::index().
    Route::post('/forms', [FormController::class, 'store'])->name('forms.store');
    Route::put('/forms/{form}', [FormController::class, 'update'])->name('forms.update');
    Route::delete('/forms/{form}', [FormController::class, 'destroy'])->name('forms.destroy');
    Route::get('/forms/{form}/script', [FormController::class, 'editScript'])->name('forms.script');
    Route::put('/forms/{form}/script', [FormController::class, 'updateScript'])->name('forms.script.update');

    Route::get('/messages', [MessageController::class, 'index'])->name('messages');
    Route::get('/messages/threads', [MessageController::class, 'threads'])->name('messages.threads');
    Route::get('/messages/dm-channel', [MessageController::class, 'dmChannel'])->name('messages.dm-channel');
    Route::get('/messages/list', [MessageController::class, 'list'])->name('messages.list');
    Route::get('/messages/guild-role-names', [MessageController::class, 'guildRoleNames'])->name('messages.guild-role-names');
    Route::get('/messages/guild-channel-names', [MessageController::class, 'guildChannelNames'])->name('messages.guild-channel-names');
    Route::get('/messages/guild-members-info', [MessageController::class, 'guildMembersInfo'])->name('messages.guild-members-info');
    Route::get('/messages/user-details', [MessageController::class, 'userDetails'])->name('messages.user-details');
    Route::get('/messages/upload-limit', [MessageController::class, 'uploadLimit'])->name('messages.upload-limit');

    // No captcha here (unlike the users.* actions below) - these are AJAX
    // actions driven from the Messages page's own JS, not real <form>
    // submissions with a rendered captcha widget, and are already behind
    // the same admin-only gate every other destructive action in this file
    // (commands.destroy, forms.destroy, roles.destroy...) relies on alone.
    Route::post('/messages/typing', [MessageController::class, 'typing'])->name('messages.typing');
    Route::post('/messages/send', [MessageController::class, 'send'])->name('messages.send');
    Route::put('/messages/edit', [MessageController::class, 'edit'])->name('messages.edit');
    Route::delete('/messages', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::post('/messages/bulk-delete', [MessageController::class, 'bulkDestroy'])->name('messages.bulk-delete');
    Route::post('/messages/purge', [MessageController::class, 'purge'])->name('messages.purge');

    Route::get('/gateway', [GatewayController::class, 'show'])->name('gateway');
    Route::post('/gateway', [GatewayController::class, 'save'])->name('gateway.save');
    Route::get('/gateway/status', [GatewayController::class, 'status'])->name('gateway.status');

    Route::get('/logs', [LogController::class, 'index'])->name('logs');
    Route::delete('/logs', [LogController::class, 'clear'])->name('logs.clear');

    Route::post('/users/{user}/force-unlink', [UserController::class, 'forceUnlinkDiscord'])
        ->name('users.force-unlink')
        ->middleware('captcha');

    Route::post('/users/{user}/send-dm', [UserController::class, 'sendDirectMessage'])
        ->name('users.send-dm')
        ->middleware('captcha');

    Route::post('/users/{user}/send-recovery-password', [UserController::class, 'sendRecoveryPassword'])
        ->name('users.send-recovery-password')
        ->middleware('captcha');

    Route::post('/users/{user}/refresh-discord-info', [UserController::class, 'refreshDiscordInfo'])
        ->name('users.refresh-discord-info')
        ->middleware('captcha');

    Route::post('/users/{user}/send-2fa-recovery-codes', [UserController::class, 'send2faRecoveryCodes'])
        ->name('users.send-2fa-recovery-codes')
        ->middleware('captcha');
});
