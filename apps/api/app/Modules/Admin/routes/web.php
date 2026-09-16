<?php

use App\Modules\Admin\Http\Controllers\SwitchLocaleController;
use Illuminate\Support\Facades\Route;

Route::get('admin/locale/{locale}', SwitchLocaleController::class)
    ->where('locale', '[a-z]{2}')
    ->name('admin.locale');

Route::redirect('/', '/merchant');
