<?php

namespace App\Support;

final class SafeRedirect
{
    /** Return a local path only; never trust intended URLs or Referer hosts. */
    public static function path(mixed $target, string $fallback = '/app'): string
    {
        if (! is_string($target) || preg_match('/[\\\\\x00-\x20]/', rawurldecode($target))) {
            return $fallback;
        }

        $parts = parse_url($target);
        $origin = parse_url(config('app.url'));

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        if (isset($parts['host']) || isset($parts['scheme'])) {
            if (strtolower($parts['scheme'] ?? '') !== strtolower($origin['scheme'] ?? '')
                || strtolower($parts['host'] ?? '') !== strtolower($origin['host'] ?? '')
                || ($parts['port'] ?? null) !== ($origin['port'] ?? null)) {
                return $fallback;
            }
        }

        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with(rawurldecode($path), '//')) {
            return $fallback;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
