<?php

final class App
{
    private static ?string $baseUrl = null;

    public static function baseUrl(): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $pos = strpos($script, '/PHP/');
        if ($pos !== false) {
            $base = substr($script, 0, $pos);
        } else {
            $base = rtrim(dirname($script), '/\\');
            if ($base === '.') {
                $base = '';
            }
        }

        self::$baseUrl = $base;
        return self::$baseUrl;
    }
}
