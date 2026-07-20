<?php

namespace App\Payments\Support;

trait PaymentAmountSupport
{
    protected function decimalToCents($value): ?int
    {
        if (!$this->isNumericScalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (!preg_match('/\A(\d+)(?:\.(\d+))?\z/', $value, $matches)) {
            return null;
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            return null;
        }

        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $maxWhole = (string) intdiv(PHP_INT_MAX - (int) $fraction, 100);
        if ($this->compareUnsignedIntegers($whole, $maxWhole) > 0) {
            return null;
        }

        return ((int) $whole * 100) + (int) $fraction;
    }

    protected function integerAmount($value): ?int
    {
        if (!$this->isNumericScalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (!preg_match('/\A\d+\z/', $value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        if ($this->compareUnsignedIntegers($normalized, (string) PHP_INT_MAX) > 0) {
            return null;
        }

        return (int) $normalized;
    }

    protected function hasScalarCallbackFields(array $params, array $fields): bool
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $params) || !$this->isCallbackScalar($params[$field])) {
                return false;
            }
        }

        return true;
    }

    protected function hasOnlyScalarCallbackValues(array $params): bool
    {
        foreach ($params as $value) {
            if ($value !== null && !$this->isCallbackScalar($value)) {
                return false;
            }
        }

        return true;
    }

    protected function callbackScalarString($value, bool $trim = true): ?string
    {
        if (!$this->isCallbackScalar($value)) {
            return null;
        }

        $value = (string) $value;
        return $trim ? trim($value) : $value;
    }

    private function isNumericScalar($value): bool
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        return !is_float($value) || is_finite($value);
    }

    private function isCallbackScalar($value): bool
    {
        return is_string($value)
            || is_int($value)
            || (is_float($value) && is_finite($value));
    }

    private function compareUnsignedIntegers(string $left, string $right): int
    {
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }

        return strcmp($left, $right);
    }
}
