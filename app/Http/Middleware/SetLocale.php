<?php

namespace App\Http\Middleware;

use App\Services\BusinessSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class SetLocale
{
    /**
     * The supported locales cache.
     */
    protected ?array $supported = null;

    /**
     * Handle an incoming request.
     *
     * Read the locale from session, validate it against the configured
     * supported locales, and apply it to the application. If the stored
     * locale is invalid or missing, fall back to the configured default.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $locale = $this->resolveLocale();
        App::setLocale($locale);

        return $next($request);
    }

    /**
     * Resolve the active locale from session, the current business default,
     * or the config default, in that order of precedence.
     */
    protected function resolveLocale(): string
    {
        $sessionKey = config('localization.session_key', 'locale');
        $stored = Session::get($sessionKey);

        if ($stored && $this->isSupported($stored)) {
            return $stored;
        }

        // An explicit session selection always wins. Otherwise the current
        // business's regional.locale default applies; with no business (or an
        // unset business locale) the global default is used. The service is
        // queried only when needed and never raises for guests.
        $businessLocale = app(BusinessSettings::class)->get('regional.locale');

        if ($businessLocale && $this->isSupported($businessLocale)) {
            return $businessLocale;
        }

        return config('app.locale', 'en');
    }

    /**
     * Check whether a locale code is in the supported list.
     */
    public function isSupported(string $locale): bool
    {
        return array_key_exists($locale, $this->getSupported());
    }

    /**
     * Get the supported locales array.
     */
    protected function getSupported(): array
    {
        if ($this->supported === null) {
            $this->supported = config('localization.supported', []);
        }

        return $this->supported;
    }

    /**
     * Get the text direction for a locale.
     */
    public static function direction(string $locale): string
    {
        $supported = config('localization.supported', []);

        return $supported[$locale]['direction'] ?? 'ltr';
    }

    /**
     * Check if a locale is RTL.
     */
    public static function isRtl(string $locale): bool
    {
        return static::direction($locale) === 'rtl';
    }
}
