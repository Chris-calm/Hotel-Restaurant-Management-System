<?php

final class Validator
{
    public static function required($value): bool
    {
        return trim((string)$value) !== '';
    }

    public static function email($value): bool
    {
        $v = trim((string)$value);
        if ($v === '') {
            return true;
        }
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function inArray($value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }
}
