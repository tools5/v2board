<?php

namespace App\Support;

class ConfiguredUrl
{
    public static function applicationHost($url = null): string
    {
        return self::extractHttpHost(self::applicationUrl($url));
    }

    /**
     * Returns a normalized, configured application origin (and optional base path).
     * It deliberately never derives the value from a request Host header.
     */
    public static function applicationUrl($url = null): string
    {
        $candidates = [];
        if (is_string($url) && trim($url) !== '') {
            $candidates[] = $url;
        }

        $candidates[] = (string)config('v2board.app_url', '');
        $candidates[] = (string)config('app.url', '');

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeHttpUrl($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * Builds a local frontend URL without deriving an origin from the current request.
     * A relative path is intentionally returned when no valid application URL is configured.
     */
    public static function applicationPathUrl(string $path): string
    {
        if ($path === '' || self::hasUnsafeUrlCharacters($path)) {
            return '';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $decoded = $path;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if (strpos($decoded, '//') === 0) {
            return '';
        }

        $applicationUrl = self::applicationUrl();
        return $applicationUrl === '' ? $path : rtrim($applicationUrl, '/') . $path;
    }

    /**
     * Keeps quick-login return targets inside the frontend router.
     */
    public static function normalizeFrontendRedirect($redirect, string $default = 'dashboard'): string
    {
        if (!is_string($redirect)) {
            return $default;
        }

        $redirect = trim($redirect);
        if ($redirect === '' || strlen($redirect) > 2048) {
            return $default;
        }

        $decoded = $redirect;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $decoded)
            || strpos($decoded, '\\') !== false
            || strpos($decoded, '//') === 0
            || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:/', $decoded)) {
            return $default;
        }

        return $redirect;
    }

    public static function subscriptionHost($url = null): string
    {
        $candidates = [];
        if (is_string($url) && trim($url) !== '') {
            $candidates[] = $url;
        }

        foreach (explode(',', (string)config('v2board.subscribe_url', '')) as $configuredUrl) {
            if (trim($configuredUrl) !== '') {
                $candidates[] = $configuredUrl;
            }
        }

        foreach ($candidates as $candidate) {
            $host = self::extractHttpHost($candidate);
            if ($host !== '') {
                return $host;
            }
        }

        return self::applicationHost();
    }

    public static function normalizeHttpUrl($url): string
    {
        $url = (string)$url;
        if ($url === '' || self::hasUnsafeUrlCharacters($url)) {
            return '';
        }
        $url = trim($url);

        if (!preg_match('#\A[A-Za-z][A-Za-z0-9+.-]*://#', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        if (!in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return '';
        }

        $port = '';
        if (isset($parts['port'])) {
            $portNumber = (int)$parts['port'];
            if ($portNumber < 1 || $portNumber > 65535) {
                return '';
            }
            $port = ':' . $portNumber;
        }

        $path = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
        if ($path !== '' && preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return '';
        }

        $displayHost = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        return strtolower((string)$parts['scheme']) . '://' . $displayHost . $port . $path;
    }

    /**
     * Normalizes an absolute external HTTP(S) URL while preserving its path,
     * query string and fragment. This is for links and asset URLs, unlike an
     * application URL where query strings and fragments must be discarded.
     */
    public static function normalizeExternalHttpUrl($url): string
    {
        $url = (string)$url;
        if ($url === '' || self::hasUnsafeUrlCharacters($url)) {
            return '';
        }
        if (!preg_match('#\Ahttps?://#i', $url)) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        if (!in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return '';
        }

        $port = '';
        if (isset($parts['port'])) {
            $portNumber = (int)$parts['port'];
            if ($portNumber < 1 || $portNumber > 65535) {
                return '';
            }
            $port = ':' . $portNumber;
        }

        $path = (string)($parts['path'] ?? '');
        $query = array_key_exists('query', $parts) ? (string)$parts['query'] : null;
        $fragment = array_key_exists('fragment', $parts) ? (string)$parts['fragment'] : null;
        foreach ([$path, $query, $fragment] as $component) {
            if ($component !== null && self::hasUnsafeUrlCharacters($component)) {
                return '';
            }
        }

        $displayHost = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $normalized = strtolower((string)$parts['scheme']) . '://' . $displayHost . $port . $path;
        if ($query !== null) {
            $normalized .= '?' . $query;
        }
        if ($fragment !== null) {
            $normalized .= '#' . $fragment;
        }

        return $normalized;
    }

    private static function hasUnsafeUrlCharacters(string $value): bool
    {
        if (preg_match('/[\x00-\x20\x7F]/', $value) || strpos($value, '\\') !== false) {
            return true;
        }

        $decoded = $value;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (preg_match('/[\x00-\x1F\x7F]/', $decoded) || strpos($decoded, '\\') !== false) {
                return true;
            }

            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1 || strpos($decoded, '\\') !== false;
    }

    private static function extractHttpHost($url): string
    {
        $normalized = self::normalizeHttpUrl($url);
        if ($normalized === '') {
            return '';
        }

        $parts = parse_url($normalized);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        return strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
    }
}
