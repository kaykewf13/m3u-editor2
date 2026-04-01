<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Applies the user's DB-stored locale preference on every authenticated request.
 *
 * The plugin's SetLocale middleware runs as persistent (outer) middleware and
 * is called BEFORE this middleware. It may set the locale to the app default
 * when the session/cookie is absent (e.g. first login, expired session).
 * By calling App::setLocale() here we override that with the correct DB value
 * regardless of execution order.
 *
 * We also write the value back into the session so the plugin's dropdown
 * reflects the correct language on the current request.
 *
 * Flow:
 *  1. User logs in — DB locale is 'de', session is empty.
 *  2. Plugin's SetLocale runs first (persistent): session empty → setLocale('en').
 *  3. This middleware runs: user.locale = 'de' → session = 'de', setLocale('de') ← corrects it.
 *  4. User switches via dropdown → LocaleChanged event → PersistUserLocale saves to DB.
 *  5. Next request: session is already 'de' AND DB is 'de' — both paths agree.
 */
class SeedLocaleFromUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->hasSession() && auth()->check()) {
            $locale = auth()->user()->locale;

            if (! empty($locale)) {
                // Force-write to session so the plugin's dropdown UI reads the
                // correct value, then set the app locale directly to override
                // whatever the plugin's SetLocale may have set earlier.
                $request->session()->put('locale', $locale);
                App::setLocale($locale);
            }
        }

        return $next($request);
    }
}
