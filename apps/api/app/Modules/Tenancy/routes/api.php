<?php

use App\Modules\Tenancy\Http\Controllers\ShowShopController;
use Illuminate\Support\Facades\Route;

// Prefixed /api/v1 by the module loader.
Route::middleware(['shop.key', 'throttle:shop-api'])->group(function (): void {
    Route::get('shop', ShowShopController::class)->name('api.shop.show');
});
