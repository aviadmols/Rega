<?php

use App\Modules\Leads\Http\Controllers\LeadStepController;
use Illuminate\Support\Facades\Route;

// One step of the lead flow. Throttled per site like every other storefront route, because a
// form that can be posted to as fast as a script wants is a form that will be.
Route::post('widget/{site}/lead', LeadStepController::class)
    ->middleware('throttle:widget-lead')
    ->name('leads.step');
