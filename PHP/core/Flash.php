<?php

final class Flash
{
    private const KEY = '__flash';

    public static function set(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION[self::KEY] = ['type' => $type, 'message' => $message];
    }

    public static function get(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (!isset($_SESSION[self::KEY])) {
            return null;
        }
        $val = $_SESSION[self::KEY];
        unset($_SESSION[self::KEY]);
        return is_array($val) ? $val : null;
    }
}
