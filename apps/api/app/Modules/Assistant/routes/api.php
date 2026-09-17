<?php

use App\Modules\Assistant\Http\Controllers\AskController;
use App\Modules\Assistant\Http\Controllers\QuestionsController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront widget calls these.
Route::get('widget/{site}/questions', QuestionsController::class)
    ->middleware('throttle:widget-page')
    ->name('api.widget.questions');

Route::post('widget/{site}/ask', AskController::class)
    ->middleware('throttle:assistant-ask')
    ->name('api.widget.ask');
