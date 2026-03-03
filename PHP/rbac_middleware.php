<?php

class RBACMiddleware
{
    public static function init(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function checkPageAccess(): void
    {
        self::init();
        if (!isset($_SESSION['user_id'])) {
            header('Location: ../index.php');
            exit();
        }
    }

    public static function hasPermission(string $permission): bool
    {
        return true;
    }
}
