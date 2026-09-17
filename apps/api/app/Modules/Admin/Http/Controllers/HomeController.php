<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\PanelHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The site root. Signed-in people go to their own panel; guests go to a login that sends them to
 * their panel afterwards.
 */
final class HomeController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            return redirect()->to(PanelHome::for($user));
        }

        return redirect()->to(url('/'.User::MERCHANT_PANEL.'/login'));
    }
}
