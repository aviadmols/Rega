<?php

use App\Modules\Admin\Http\Controllers\HomeController;
use App\Modules\Admin\Http\Controllers\SwitchLocaleController;
use App\Modules\Admin\Http\Controllers\SwitchShopController;
use Illuminate\Support\Facades\Route;

Route::get('admin/locale/{locale}', SwitchLocaleController::class)
    ->where('locale', '[a-z]{2}')
    ->name('admin.locale');

// Which shop the operator panel is looking at. Session-backed, so it survives a page change.
Route::post('admin/shop', SwitchShopController::class)->middleware(['web', 'auth'])->name('admin.shop');

Route::get('/', HomeController::class)->name('home');
