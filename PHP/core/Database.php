<?php

final class Database
{
    private static ?mysqli $conn = null;

    public static function getConnection(): ?mysqli
    {
        if (self::$conn instanceof mysqli) {
            return self::$conn;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $name = getenv('DB_NAME') ?: 'hotel_system';

        try {
            $mysqli = @new mysqli($host, $user, $pass, $name);
            if ($mysqli->connect_errno) {
                self::$conn = null;
                return null;
            }
            $mysqli->set_charset('utf8mb4');
            self::$conn = $mysqli;
            return self::$conn;
        } catch (Throwable $e) {
            self::$conn = null;
            return null;
        }
    }
}
