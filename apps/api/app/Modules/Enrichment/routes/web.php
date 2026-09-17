<?php

use App\Modules\Enrichment\Http\Controllers\DownloadTaskFileController;
use Illuminate\Support\Facades\Route;

// Authentication is checked in the controller, like the plugin download.
Route::get('operator/enrichment/batches/{batch}/tasks.jsonl', DownloadTaskFileController::class)
    ->name('enrichment.batches.download');
