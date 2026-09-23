<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Changes which shop the operator panel is looking at, and returns to the page they were on.
 *
 * Only an operator may do this. A merchant's shop comes from the address of their own panel and
 * is never a choice, so this route is closed to them.
 */
final class SwitchShopController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_operator) {
            throw new AccessDeniedHttpException;
        }

        CurrentShop::set((string) $request->input('shop', CurrentShop::EVERY));

        // Back to the screen they were on, and to the panel's home if it is not one of ours.
        $back = (string) $request->input('back', '');

        return str_starts_with($back, '/'.User::OPERATOR_PANEL)
            ? redirect()->to($back)
            : redirect()->to('/'.User::OPERATOR_PANEL);
    }
}
