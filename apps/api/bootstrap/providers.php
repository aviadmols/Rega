<?php

use App\Core\CoreServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Discovers and registers everything in app/Modules. Keep it after AppServiceProvider.
    CoreServiceProvider::class,
];
