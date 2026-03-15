<?php

require_once __DIR__ . '/core/bootstrap.php';

class RBACMiddleware
{
    private static function guestTwoFaEnabled(?mysqli $conn, int $userId): bool
    {
        if (!$conn || $userId <= 0) {
            return false;
        }

        try {
            $dbRow = $conn->query('SELECT DATABASE()');
            $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
            $db = $conn->real_escape_string($db);
            if ($db === '') {
                return false;
            }

            $res = $conn->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'user_2fa'"
            );
            $has = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
            if ($has !== 1) {
                return false;
            }

            $stmt = $conn->prepare('SELECT enabled FROM user_2fa WHERE user_id = ? LIMIT 1');
            if (!($stmt instanceof mysqli_stmt)) {
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return ((int)($row['enabled'] ?? 0) === 1);
        } catch (Throwable $e) {
            return false;
        }
    }

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

        if ($isGuestArea && Request::isPost() && !in_array($base, $guestAllowedBasenames, true)) {
            $conn = Database::getConnection();
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if (!self::guestTwoFaEnabled($conn, $uid)) {
                Flash::set('error', 'Please enable 2FA in Settings before making reservations or submitting requests.');
                $APP_BASE_URL = App::baseUrl();
                header('Location: ' . $APP_BASE_URL . '/PHP/settings.php');
                exit();
            }
        }
    }

    public static function hasPermission(string $permission): bool
    {
        return true;
    }
}
