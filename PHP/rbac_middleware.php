<?php

require_once __DIR__ . '/core/bootstrap.php';

class RBACMiddleware
{
    private const ROLE_GUEST = 'guest';
    private const ROLE_STAFF = 'staff';
    private const ROLE_ADMIN = 'admin';
    private const ROLE_SUPERADMIN = 'superadmin';

    private const ROLE_FRONT_DESK = 'front_desk_staff';
    private const ROLE_HOUSEKEEPING_MANAGER = 'housekeeping_manager';
    private const ROLE_FINANCIAL_MANAGER = 'financial_manager';
    private const ROLE_INVENTORY_POS_MANAGER = 'inventory_pos_manager';
    private const ROLE_EVENTS_MANAGER = 'events_conference_manager';

    public static function isAdminRole(string $role): bool
    {
        return in_array($role, [self::ROLE_ADMIN, self::ROLE_SUPERADMIN], true);
    }

    public static function moduleRegistry(string $APP_BASE_URL): array
    {
        return [
            'front_desk' => ['label' => 'Front Desk', 'url' => $APP_BASE_URL . '/PHP/modules/front_desk.php'],
            'reservations' => ['label' => 'Reservation & Booking', 'url' => $APP_BASE_URL . '/PHP/modules/reservations.php'],
            'door_lock' => ['label' => 'Door Lock Integration', 'url' => $APP_BASE_URL . '/PHP/modules/rooms/locks.php'],
            'guest_crm' => ['label' => 'Guess CRM', 'url' => $APP_BASE_URL . '/PHP/modules/guests/index.php'],
            'billing' => ['label' => 'Billing & Payments', 'url' => $APP_BASE_URL . '/PHP/modules/billing_payments.php'],
            'rooms' => ['label' => 'Rooms & Room Types', 'url' => $APP_BASE_URL . '/PHP/modules/rooms/index.php'],
            'housekeeping' => ['label' => 'Housekeeping & Maintenance', 'url' => $APP_BASE_URL . '/PHP/modules/housekeeping_maintenance.php'],
            'marketing' => ['label' => 'Marketing & Promotions', 'url' => $APP_BASE_URL . '/PHP/modules/marketing_promotions.php'],
            'channel_mgmt' => ['label' => 'Channel Management', 'url' => $APP_BASE_URL . '/PHP/modules/channel_management.php'],
            'analytics' => ['label' => 'Anayltics & Reporting', 'url' => $APP_BASE_URL . '/PHP/modules/analytics_reporting.php'],
            'loyalty' => ['label' => 'Loyalty & Rewards', 'url' => $APP_BASE_URL . '/PHP/modules/loyalty_rewards.php'],
            'pos' => ['label' => 'POS', 'url' => $APP_BASE_URL . '/PHP/modules/pos.php'],
            'inventory' => ['label' => 'Inventory & Stocks', 'url' => $APP_BASE_URL . '/PHP/modules/inventory_stock.php'],
            'events' => ['label' => 'Events & Conference', 'url' => $APP_BASE_URL . '/PHP/modules/events_conferences.php'],
            'module_manager' => ['label' => 'Module Manager', 'url' => $APP_BASE_URL . '/PHP/modules/module_manager.php'],
            'employees' => ['label' => 'Employees', 'url' => $APP_BASE_URL . '/PHP/modules/employees.php'],
        ];
    }

    public static function allowedModulesByRole(string $role): array
    {
        $role = $role !== '' ? $role : self::ROLE_STAFF;

        $allCore = [
            'front_desk',
            'reservations',
            'door_lock',
            'guest_crm',
            'billing',
            'rooms',
            'housekeeping',
            'marketing',
            'channel_mgmt',
            'analytics',
            'loyalty',
            'pos',
            'inventory',
            'events',
        ];

        if (self::isAdminRole($role) || $role === self::ROLE_STAFF) {
            return $allCore;
        }

        if ($role === self::ROLE_FRONT_DESK) {
            return ['front_desk', 'reservations', 'door_lock', 'guest_crm', 'billing'];
        }
        if ($role === self::ROLE_HOUSEKEEPING_MANAGER) {
            return ['rooms', 'housekeeping'];
        }
        if ($role === self::ROLE_FINANCIAL_MANAGER) {
            return ['marketing', 'channel_mgmt', 'analytics', 'loyalty'];
        }
        if ($role === self::ROLE_INVENTORY_POS_MANAGER) {
            return ['pos', 'inventory'];
        }
        if ($role === self::ROLE_EVENTS_MANAGER) {
            return ['events'];
        }

        return $allCore;
    }

    public static function allowedModules(?mysqli $conn, string $role): array
    {
        $role = $role !== '' ? $role : self::ROLE_STAFF;

        $hard = self::allowedModulesByRole($role);

        if (self::isAdminRole($role)) {
            return $hard;
        }

        if (!$conn) {
            return $hard;
        }

        $hasPermissions = false;
        try {
            $dbRow = $conn->query('SELECT DATABASE()');
            $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
            $db = $conn->real_escape_string($db);
            if ($db !== '') {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'user_module_permissions'");
                $hasPermissions = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }
        } catch (Throwable $e) {
            $hasPermissions = false;
        }

        if (!$hasPermissions) {
            return $hard;
        }

        $allowed = [];
        try {
            $stmt = $conn->prepare('SELECT module_key FROM user_module_permissions WHERE role = ? AND allowed = 1 ORDER BY module_key ASC');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('s', $role);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $k = (string)($row['module_key'] ?? '');
                    if ($k !== '' && in_array($k, $hard, true)) {
                        $allowed[] = $k;
                    }
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            $allowed = [];
        }

        return !empty($allowed) ? array_values(array_unique($allowed)) : $hard;
    }

    public static function defaultModuleOrderByRole(string $role): array
    {
        return self::allowedModulesByRole($role);
    }

    private static function userTwoFaEnabled(?mysqli $conn, int $userId): bool
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
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $base = (string)basename((string)($_SERVER['PHP_SELF'] ?? ''));

        $APP_BASE_URL = App::baseUrl();

        if ($role !== self::ROLE_GUEST) {
            if (strpos($script, '/PHP/modules/') !== false) {
                $conn = Database::getConnection();
                $moduleKey = self::moduleKeyFromScript($script);
                if ($moduleKey !== '' && !self::canAccessModule($conn, $role, $moduleKey)) {
                    header('Location: ' . $APP_BASE_URL . '/PHP/Dashboard.php');
                    exit();
                }
            }

            return;
        }

        $guestAllowedBasenames = [
            'settings.php',
            'notifications_mark_all_read.php',
            'notifications_mark_read.php',
            'logout.php',
        ];

        $isGuestArea = (strpos($script, '/PHP/guest/') !== false);
        $isAllowed = $isGuestArea || in_array($base, $guestAllowedBasenames, true);

        if (!$isAllowed) {
            header('Location: ' . $APP_BASE_URL . '/PHP/guest/index.php');
            exit();
        }

        if ($isGuestArea && Request::isPost() && !in_array($base, $guestAllowedBasenames, true)) {
            $conn = Database::getConnection();
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if (!self::guestTwoFaEnabled($conn, $uid)) {
                Flash::set('error', 'Please enable 2FA in Settings before making reservations or submitting requests.');
                header('Location: ' . $APP_BASE_URL . '/PHP/settings.php');
                exit();
            }
        }
    }

    private static function moduleKeyFromScript(string $script): string
    {
        $script = str_replace('\\', '/', $script);
        $path = parse_url($script, PHP_URL_PATH);
        $path = is_string($path) ? $path : $script;

        $rel = $path;
        $pos = strpos($rel, '/PHP/modules/');
        if ($pos === false) {
            return '';
        }
        $rel = substr($rel, $pos + strlen('/PHP/modules/'));
        $rel = ltrim($rel, '/');

        if ($rel === 'module_manager.php') {
            return 'module_manager';
        }
        if ($rel === 'employees.php') {
            return 'employees';
        }
        if ($rel === 'front_desk.php' || $rel === 'front_desk_receipt.php') {
            return 'front_desk';
        }
        if ($rel === 'reservations.php' || $rel === 'reservations_view.php') {
            return 'reservations';
        }
        if ($rel === 'rooms/locks.php') {
            return 'door_lock';
        }
        if ($rel === 'guests/index.php' || $rel === 'crm.php') {
            return 'guest_crm';
        }
        if ($rel === 'billing_payments.php') {
            return 'billing';
        }
        if ($rel === 'rooms/index.php' || $rel === 'room_management.php') {
            return 'rooms';
        }
        if ($rel === 'housekeeping_maintenance.php') {
            return 'housekeeping';
        }
        if ($rel === 'marketing_promotions.php') {
            return 'marketing';
        }
        if ($rel === 'channel_management.php') {
            return 'channel_mgmt';
        }
        if ($rel === 'analytics_reporting.php') {
            return 'analytics';
        }
        if ($rel === 'loyalty_rewards.php') {
            return 'loyalty';
        }
        if ($rel === 'pos.php') {
            return 'pos';
        }
        if ($rel === 'inventory_stock.php') {
            return 'inventory';
        }
        if ($rel === 'events_conferences.php') {
            return 'events';
        }

        return '';
    }

    public static function canAccessModule(?mysqli $conn, string $role, string $moduleKey): bool
    {
        if (in_array($moduleKey, ['module_manager', 'employees'], true)) {
            return self::isAdminRole($role);
        }
        $allowed = self::allowedModules($conn, $role);
        return in_array($moduleKey, $allowed, true);
    }

    public static function getOrderedModules(?mysqli $conn, string $role): array
    {
        $APP_BASE_URL = App::baseUrl();
        $registry = self::moduleRegistry($APP_BASE_URL);
        $allowed = self::allowedModules($conn, $role);
        $defaultOrder = array_values(array_filter(self::defaultModuleOrderByRole($role), static function (string $k) use ($allowed): bool {
            return in_array($k, $allowed, true);
        }));

        $savedOrder = [];
        if ($conn) {
            try {
                $stmt = $conn->prepare('SELECT module_key FROM user_module_orders WHERE role = ? ORDER BY position ASC');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('s', $role);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $k = (string)($row['module_key'] ?? '');
                        if ($k !== '' && in_array($k, $allowed, true)) {
                            $savedOrder[] = $k;
                        }
                    }
                    $stmt->close();
                }
            } catch (Throwable $e) {
                $savedOrder = [];
            }
        }

        $order = !empty($savedOrder) ? $savedOrder : $defaultOrder;
        $dedup = [];
        foreach ($order as $k) {
            if (!in_array($k, $dedup, true)) {
                $dedup[] = $k;
            }
        }
        foreach ($defaultOrder as $k) {
            if (!in_array($k, $dedup, true)) {
                $dedup[] = $k;
            }
        }

        $out = [];
        foreach ($dedup as $k) {
            if (isset($registry[$k]) && in_array($k, $allowed, true)) {
                $out[] = ['key' => $k] + $registry[$k];
            }
        }

        return $out;
    }

    public static function hasPermission(string $permission): bool
    {
        return true;
    }
}
