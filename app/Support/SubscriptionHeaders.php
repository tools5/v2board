<?php

namespace App\Support;

final class SubscriptionHeaders
{
    private const DEFAULT_APP_NAME = 'V2Board';
    private const MAX_HEADER_VALUE_LENGTH = 4096;
    private const MAX_APP_NAME_LENGTH = 255;

    public static function applicationName(): string
    {
        return self::value(config('v2board.app_name', self::DEFAULT_APP_NAME), self::MAX_APP_NAME_LENGTH)
            ?? self::DEFAULT_APP_NAME;
    }

    public static function userInfo($user): string
    {
        $fields = [
            'u' => 'upload',
            'd' => 'download',
            'transfer_enable' => 'total',
            'expired_at' => 'expire',
        ];
        $parts = [];

        foreach ($fields as $field => $name) {
            $parts[] = $name . '=' . (self::unsignedInteger(self::userValue($user, $field)) ?? '0');
        }

        return implode('; ', $parts);
    }

    public static function base64ProfileTitle(string $appName): string
    {
        return 'base64:' . base64_encode(self::applicationNameValue($appName));
    }

    public static function contentDisposition(string $appName, string $extension = ''): string
    {
        $extension = preg_match('/\A(?:\.[A-Za-z0-9_-]+)?\z/', $extension) ? $extension : '';

        return "attachment; filename*=UTF-8''" . rawurlencode(self::applicationNameValue($appName)) . $extension;
    }

    public static function send(string $name, $value): void
    {
        if (!preg_match('/\A[A-Za-z0-9-]+\z/', $name)) {
            return;
        }

        $value = self::value($value);
        if ($value !== null) {
            header($name . ': ' . $value);
        }
    }

    public static function value($value, int $maxLength = self::MAX_HEADER_VALUE_LENGTH): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = (string)$value;
        if ($value === ''
            || strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function applicationNameValue(string $appName): string
    {
        return self::value($appName, self::MAX_APP_NAME_LENGTH) ?? self::DEFAULT_APP_NAME;
    }

    private static function userValue($user, string $field)
    {
        if (is_array($user)) {
            return $user[$field] ?? null;
        }

        if ($user instanceof \ArrayAccess && isset($user[$field])) {
            return $user[$field];
        }

        return is_object($user) && isset($user->{$field}) ? $user->{$field} : null;
    }

    private static function unsignedInteger($value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? (string)$value : null;
        }

        if (!is_string($value) || !preg_match('/\A\d{1,20}\z/', $value)) {
            return null;
        }

        return ltrim($value, '0') ?: '0';
    }
}
