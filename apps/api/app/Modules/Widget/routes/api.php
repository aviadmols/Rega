<?php

use App\Modules\Widget\Http\Controllers\PageBankController;
use App\Modules\Widget\Http\Controllers\WidgetScriptController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront calls these from every visitor.
Route::get('widget/rega.js', WidgetScriptController::class)->name('api.widget.script');

Route::get('widget/{site}/page', PageBankController::class)
    ->middleware('throttle:widget-page')
    ->name('api.widget.page');
