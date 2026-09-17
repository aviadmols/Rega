<?php

use App\Modules\Connections\Http\Controllers\DownloadPluginController;
use Illuminate\Support\Facades\Route;

// Authentication is checked in the controller: the app has no generic "login" route to send
// guests to, only the panel logins.
Route::get('operator/plugin/download', DownloadPluginController::class)
    ->name('connections.plugin.download');
