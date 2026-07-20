<?php

namespace App\Http\Middleware;

use Closure;

class CORS
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
    private const ALLOWED_HEADERS = [
        'origin',
        'content-type',
        'accept',
        'authorization',
        'x-requested-with',
    ];

    public function handle($request, Closure $next)
    {
        $originHeader = $request->header('Origin');
        $origin = $this->normalizeOrigin($originHeader);
        $isPreflight = $request->isMethod('OPTIONS') && $originHeader !== null;
        $allowed = $origin !== null && $this->isAllowedOrigin($origin);

        if ($isPreflight) {
            if (!$allowed || !$this->isAllowedPreflight($request)) {
                return response('', 403)->header('Vary', 'Origin');
            }
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($originHeader !== null) {
            $response->headers->set('Vary', 'Origin', false);
        }
        if (!$allowed) {
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', self::ALLOWED_METHODS));
        $response->headers->set('Access-Control-Allow-Headers', $this->allowedHeadersValue());
        $response->headers->set('Access-Control-Max-Age', (string)max(0, (int)config('cors.max_age', 0)));

        if (filter_var(config('cors.supports_credentials', false), FILTER_VALIDATE_BOOLEAN)) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function isAllowedOrigin($origin)
    {
        $allowedOrigins = [];
        $this->appendOrigins($allowedOrigins, config('v2board.app_url'));
        $this->appendOrigins($allowedOrigins, config('cors.allowed_origins', []));
        $this->appendOrigins($allowedOrigins, config('v2board.cors_allowed_origins', []));

        if (in_array($origin, array_unique($allowedOrigins), true)) {
            return true;
        }

        $patterns = config('cors.allowed_origins_patterns', []);
        if (!is_array($patterns)) {
            $patterns = [$patterns];
        }
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    private function appendOrigins(array &$origins, $configured)
    {
        if (is_string($configured)) {
            $configured = strpos($configured, ',') !== false
                ? explode(',', $configured)
                : [$configured];
        }
        if (!is_array($configured)) {
            return;
        }

        foreach ($configured as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '' || trim($candidate) === '*') {
                continue;
            }
            $normalized = $this->normalizeOrigin($candidate, true);
            if ($normalized !== null) {
                $origins[] = $normalized;
            }
        }
    }

    private function normalizeOrigin($origin, bool $allowPath = false)
    {
        if (!is_string($origin)) {
            return null;
        }

        $origin = trim($origin);
        if ($origin === '' || strtolower($origin) === 'null' || preg_match('/[\x00-\x20\x7F]/', $origin)) {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (!$allowPath && isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
        if ($host === ''
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            return null;
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        $defaultPort = ($scheme === 'https' ? 443 : 80);
        $displayHost = strpos($host, ':') !== false ? '[' . $host . ']' : $host;

        return $scheme . '://' . $displayHost . ($port && $port !== $defaultPort ? ':' . $port : '');
    }

    private function isAllowedPreflight($request)
    {
        $method = strtoupper((string)$request->header('Access-Control-Request-Method', ''));
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return false;
        }

        $requestedHeaders = (string)$request->header('Access-Control-Request-Headers', '');
        if ($requestedHeaders === '') {
            return true;
        }
        foreach (explode(',', $requestedHeaders) as $header) {
            if (!in_array(strtolower(trim($header)), self::ALLOWED_HEADERS, true)) {
                return false;
            }
        }

        return true;
    }

    private function allowedHeadersValue()
    {
        return implode(', ', array_map(function ($header) {
            return implode('-', array_map('ucfirst', explode('-', $header)));
        }, self::ALLOWED_HEADERS));
    }
}
