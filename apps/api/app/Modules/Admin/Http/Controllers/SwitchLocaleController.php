<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Localization\Locales;
use App\Modules\Admin\Http\Middleware\ApplyAdminLocale;
use App\Modules\Admin\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the admin UI language. Remembered on the user when signed in, and in the session
 * so the choice made on the login screen carries into the panel.
 */
final class SwitchLocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Locales::isSupported($locale), 404);

        $request->session()->put(ApplyAdminLocale::SESSION_KEY, $locale);

        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return redirect()->to($this->safePreviousUrl($request));
    }

    /** Only ever redirect back to this application, never to a Referer on another host. */
    private function safePreviousUrl(Request $request): string
    {
        $previous = url()->previous();
        $host = parse_url($previous, PHP_URL_HOST);

        return $host === null || $host === $request->getHost() ? $previous : url('/');
    }
}
