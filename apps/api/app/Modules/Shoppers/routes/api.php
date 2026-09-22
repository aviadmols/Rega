<?php

use App\Modules\Shoppers\Http\Controllers\ConfirmController;
use App\Modules\Shoppers\Http\Controllers\SignUpController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader. Public: the storefront widget calls these.
Route::post('widget/{site}/signup', SignUpController::class)
    ->middleware('throttle:shoppers-signup')
    ->name('api.widget.signup');

Route::post('widget/{site}/confirm', ConfirmController::class)
    ->middleware('throttle:shoppers-signup')
    ->name('api.widget.confirm');
