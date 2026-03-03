<?php

final class Request
{
    public static function isPost(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public static function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    public static function get(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function int(string $source, string $key, int $default = 0): int
    {
        $arr = $source === 'post' ? $_POST : $_GET;
        if (!isset($arr[$key])) {
            return $default;
        }
        return (int)$arr[$key];
    }
}
