<?php

use Azuriom\Plugin\DiscordIntegration\Controllers\InteractionsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your plugin. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/interactions', [InteractionsController::class, 'handle'])->name('interactions');
