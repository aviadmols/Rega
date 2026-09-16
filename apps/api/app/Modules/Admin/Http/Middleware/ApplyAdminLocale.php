<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Core\Facades\Settings;
use App\Core\Localization\Locales;
use App\Modules\Admin\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the admin UI language for this request.
 *
 * Order: the signed-in user's choice, the choice made on the login screen this session, the
 * browser's languages, and finally the system default the operator set. Filament reads the
 * text direction from its own translations, so Hebrew renders right-to-left automatically.
 */
final class ApplyAdminLocale
{
    public const SESSION_KEY = 'admin_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && ($preferred = $user->preferredLocale()) !== null) {
            return $preferred;
        }

        $session = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_string($session) && Locales::isSupported($session)) {
            return $session;
        }

        return Locales::negotiate($request->header('Accept-Language'))
            ?? (string) Settings::get('admin.default_locale');
    }
}
