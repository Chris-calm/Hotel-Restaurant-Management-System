<?php

require_once __DIR__ . '/core/bootstrap.php';

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

        $role = (string)($_SESSION['role'] ?? 'staff');
        if ($role !== 'guest') {
            return;
        }

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $base = (string)basename((string)($_SERVER['PHP_SELF'] ?? ''));

        $guestAllowedBasenames = [
            'settings.php',
            'logout.php',
        ];

        $isGuestArea = (strpos($script, '/PHP/guest/') !== false);
        $isAllowed = $isGuestArea || in_array($base, $guestAllowedBasenames, true);

        if (!$isAllowed) {
            $APP_BASE_URL = App::baseUrl();
            header('Location: ' . $APP_BASE_URL . '/PHP/guest/index.php');
            exit();
        }
    }

    public static function hasPermission(string $permission): bool
    {
        return true;
    }
}
