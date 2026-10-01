<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers in the app's chosen language (Phase 7, language setting). The mobile app sends
 * "Accept-Language: en" or "Accept-Language: fil". Only those two are honored; anything else,
 * or no header at all, keeps the default locale (config/app.php: fil = Taglish).
 */
class SetLocaleFromRequest
{
    private const SUPPORTED = ['en', 'fil'];

    public function handle(Request $request, Closure $next): Response
    {
        // "en-US,en;q=0.9" → "en" (only the first, most preferred language counts).
        $first = strtolower(trim(explode(',', (string) $request->header('Accept-Language'))[0]));
        $language = explode('-', explode(';', $first)[0])[0];

        if (in_array($language, self::SUPPORTED, true)) {
            App::setLocale($language);
        }

        return $next($request);
    }
}
