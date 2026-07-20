<?php

namespace App\Support;

class EtagMatcher
{
    /**
     * Matches a request If-None-Match value against an ETag emitted by this application.
     * Legacy raw hashes remain accepted for existing node clients.
     */
    public static function matches($header, string $etag): bool
    {
        if (!is_string($header) || $etag === '') {
            return false;
        }

        $quoted = '"' . $etag . '"';
        $weak = 'W/' . $quoted;
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*'
                || hash_equals($etag, $candidate)
                || hash_equals($quoted, $candidate)
                || hash_equals($weak, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
