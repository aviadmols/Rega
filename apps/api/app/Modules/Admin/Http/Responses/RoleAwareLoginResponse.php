<?php

namespace App\Modules\Admin\Http\Responses;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\PanelHome;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After signing in on either panel's login, operators land in the operator panel and merchants in
 * the merchant panel. A specific page that sent the person to log in still wins.
 */
final class RoleAwareLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = $request->user();
        // The session service, as Filament's own redirect()->intended() uses: Livewire requests do
        // not always carry the session on the request object.
        $intended = session()->pull('url.intended');

        if (is_string($intended) && ! PanelHome::isGenericEntry($intended)) {
            return redirect()->to($intended);
        }

        return redirect()->to(PanelHome::for($user instanceof User ? $user : null));
    }
}
