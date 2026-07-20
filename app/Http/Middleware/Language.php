<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class Language
{
    public function handle($request, Closure $next)
    {
        $locale = trim((string)$request->header('content-language', ''));
        if ($locale !== '' && in_array($locale, $this->supportedLocales(), true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    private function supportedLocales()
    {
        $locales = [];
        foreach (glob(resource_path('lang/*.json')) ?: [] as $path) {
            $locales[] = pathinfo($path, PATHINFO_FILENAME);
        }

        return $locales;
    }
}
