<?php

namespace App\Support;

final class MailHeaderValidator
{
    public static function address($value, string $field): string
    {
        $value = self::text($value, $field);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException($field . '格式无效');
        }

        return $value;
    }

    public static function text($value, string $field): string
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException($field . '必须是字符串');
        }

        $value = (string) $value;
        if (preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException($field . '包含非法换行符');
        }

        return $value;
    }
}
