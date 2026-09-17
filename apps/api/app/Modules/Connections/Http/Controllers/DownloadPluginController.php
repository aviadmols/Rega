<?php

namespace App\Modules\Connections\Http\Controllers;

use App\Modules\Connections\Support\PluginPackage;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the store plugin zip to operators. Guests go to the operator login and come back here.
 */
final class DownloadPluginController
{
    public function __invoke(Request $request): BinaryFileResponse|RedirectResponse
    {
        $panel = Filament::getPanel('operator');
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest($panel->getLoginUrl());
        }

        abort_unless($user instanceof FilamentUser && $user->canAccessPanel($panel), 403);

        $package = PluginPackage::latest();

        abort_if($package === null, 404);

        return response()->download($package->path, $package->filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }
}
