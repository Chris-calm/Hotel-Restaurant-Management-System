<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomService.php';
require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$roomService = new RoomService(new RoomRepository($conn));
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$APP_BASE_URL = App::baseUrl();

function hasRoomLockColumns(?mysqli $conn): bool
{
    if (!$conn) {
        return false;
    }

    $dbRow = $conn->query('SELECT DATABASE()');
    $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
    $db = $conn->real_escape_string($db);
    if ($db === '') {
        return false;
    }

    $res = $conn->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '{$db}'
           AND TABLE_NAME = 'rooms'
           AND COLUMN_NAME IN ('lock_provider','lock_device_id','lock_status','lock_battery','lock_last_sync_at')"
    );
    $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
    return $count === 5;
}

function hasRoomLockLogsTable(?mysqli $conn): bool
{
    if (!$conn) {
        return false;
    }

    $dbRow = $conn->query('SELECT DATABASE()');
    $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
    $db = $conn->real_escape_string($db);
    if ($db === '') {
        return false;
    }

    $res = $conn->query(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = '{$db}'
           AND TABLE_NAME = 'room_lock_logs'"
    );
    $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
    return $count === 1;
}

$lockColumnsReady = hasRoomLockColumns($conn);
$lockLogsReady = hasRoomLockLogsTable($conn);

$flash = Flash::get();

if (Request::isPost() && (string)Request::post('action', '') === 'lock_action') {
    if (!$conn) {
        Flash::set('error', 'Database unavailable.');
        Response::redirect('locks.php');
    }

    if (!$lockColumnsReady) {
        Flash::set('error', 'Door lock fields are not installed yet. Please update the database schema first.');
        Response::redirect('locks.php');
    }

    $roomId = (int)Request::post('room_id', 0);
    $action = (string)Request::post('lock_cmd', '');

    $allowed = ['lock', 'unlock', 'offline', 'online'];
    if ($roomId <= 0 || !in_array($action, $allowed, true)) {
        Flash::set('error', 'Invalid lock action.');
        Response::redirect('locks.php');
    }

    $room = $roomService->get($roomId);
    if (!$room) {
        Flash::set('error', 'Room not found.');
        Response::redirect('locks.php');
    }

    $newStatus = null;
    if ($action === 'lock') {
        $newStatus = 'Locked';
    } elseif ($action === 'unlock') {
        $newStatus = 'Unlocked';
    } elseif ($action === 'offline') {
        $newStatus = 'Offline';
    } elseif ($action === 'online') {
        $newStatus = 'Locked';
    }

    if ($action === 'unlock') {
        $today = date('Y-m-d');
        $statusSql = "('Confirmed','Upcoming','Checked In')";
        $stmt = $conn->prepare(
            "SELECT r.id
             FROM reservation_rooms rr
             INNER JOIN reservations r ON r.id = rr.reservation_id
             WHERE rr.room_id = ?
               AND r.status IN {$statusSql}
               AND r.checkin_date <= ?
               AND r.checkout_date > ?
             ORDER BY r.id DESC
             LIMIT 1"
        );
        $hasActive = false;
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('iss', $roomId, $today, $today);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            $hasActive = (bool)$row;
        }

        if (!$hasActive) {
            Flash::set('error', 'Unlock blocked. Reception confirmation required (no active confirmed/upcoming/checked-in reservation for today).');
            Response::redirect('locks.php');
        }
    }

    $provider = (string)($room['lock_provider'] ?? '');
    if (trim($provider) === '') {
        $provider = 'simulator';
    }

    $deviceId = (string)($room['lock_device_id'] ?? '');
    if (trim($deviceId) === '') {
        $deviceId = 'sim-' . $roomId;
    }

    $battery = $room['lock_battery'] ?? null;
    if ($battery === null || $battery === '') {
        $battery = 95;
    }

    if ($action === 'unlock') {
        $battery = max(0, (int)$battery - 1);
    }

    $uStmt = $conn->prepare(
        "UPDATE rooms
         SET lock_provider = ?,
             lock_device_id = ?,
             lock_status = ?,
             lock_battery = ?,
             lock_last_sync_at = NOW()
         WHERE id = ?"
    );

    if (!($uStmt instanceof mysqli_stmt)) {
        Flash::set('error', 'Failed to update lock status.');
        Response::redirect('locks.php');
    }

    $lockBattery = (int)$battery;
    $uStmt->bind_param('sssii', $provider, $deviceId, $newStatus, $lockBattery, $roomId);
    $ok = $uStmt->execute();
    $uStmt->close();

    if ($ok && $lockLogsReady) {
        $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $notes = null;
        $logStmt = $conn->prepare(
            "INSERT INTO room_lock_logs (room_id, action, actor_user_id, notes)
             VALUES (?, ?, ?, ?)"
        );
        if ($logStmt instanceof mysqli_stmt) {
            $actor = $actorUserId;
            $cmd = strtoupper($action);
            $logStmt->bind_param('isis', $roomId, $cmd, $actor, $notes);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    if ($ok) {
        Flash::set('success', 'Lock updated: ' . $newStatus);
    } else {
        Flash::set('error', 'Failed to update lock.');
    }

    Response::redirect('locks.php');
}

$rooms = $roomService->list('');

$pageTitle = 'Door Locks - Hotel Management System';
$pendingApprovals = [];

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-light text-gray-900">Door Lock Integration</h1>
                    <p class="text-sm text-gray-500 mt-1">Localhost simulator. Unlock is only allowed if reception confirmed the booking (active reservation today).</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back to Rooms</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$lockColumnsReady): ?>
            <div class="mb-6 rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-900">
                Door lock fields are not installed yet. Please run DB migration for: rooms.lock_provider, lock_device_id, lock_status, lock_battery, lock_last_sync_at.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($rooms as $r): ?>
                <?php
                    $imgPath = trim((string)($r['image_path'] ?? ''));
                    $typeLabel = (string)(($r['room_type_code'] ?? '') . ' - ' . ($r['room_type_name'] ?? ''));
                    $lockStatus = (string)($r['lock_status'] ?? '');
                    if (!$lockColumnsReady) {
                        $lockStatus = 'N/A';
                    } elseif (trim($lockStatus) === '') {
                        $lockStatus = 'Locked';
                    }

                    $battery = $lockColumnsReady ? ($r['lock_battery'] ?? null) : null;
                    $provider = $lockColumnsReady ? (string)($r['lock_provider'] ?? '') : '';
                    $deviceId = $lockColumnsReady ? (string)($r['lock_device_id'] ?? '') : '';

                    $badgeClass = 'border-gray-200 text-gray-700';
                    if ($lockStatus === 'Locked') {
                        $badgeClass = 'border-gray-200 text-gray-700';
                    } elseif ($lockStatus === 'Unlocked') {
                        $badgeClass = 'border-green-200 text-green-700';
                    } elseif ($lockStatus === 'Offline') {
                        $badgeClass = 'border-red-200 text-red-700';
                    }
                ?>
                <div class="bg-white rounded-lg border border-gray-100 overflow-hidden">
                    <div class="h-36 bg-gray-50 flex items-center justify-center">
                        <?php if ($imgPath !== ''): ?>
                            <img src="<?= htmlspecialchars($APP_BASE_URL . $imgPath) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                        <?php else: ?>
                            <div class="text-xs text-gray-400">No image</div>
                        <?php endif; ?>
                    </div>
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium text-gray-900">Room <?= htmlspecialchars($r['room_no'] ?? '') ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($typeLabel) ?></div>
                            </div>
                            <div class="text-xs px-2 py-1 rounded-full border <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($lockStatus) ?></div>
                        </div>

                        <div class="mt-3 text-xs text-gray-500">Lock Provider: <?= htmlspecialchars($provider !== '' ? $provider : 'simulator') ?></div>
                        <div class="mt-1 text-xs text-gray-500">Device ID: <?= htmlspecialchars($deviceId !== '' ? $deviceId : ('sim-' . (int)$r['id'])) ?></div>
                        <div class="mt-1 text-xs text-gray-500">Battery: <?= $battery === null || $battery === '' ? '—' : (int)$battery . '%' ?></div>
                        <div class="mt-1 text-xs text-gray-500">Last Sync: <?= htmlspecialchars((string)($r['lock_last_sync_at'] ?? '')) ?></div>

                        <form method="post" class="mt-4">
                            <input type="hidden" name="action" value="lock_action" />
                            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>" />

                            <div class="grid grid-cols-2 gap-2">
                                <button name="lock_cmd" value="lock" class="px-3 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition" <?= $lockColumnsReady ? '' : 'disabled' ?>>Lock</button>
                                <button name="lock_cmd" value="unlock" class="px-3 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition" <?= $lockColumnsReady ? '' : 'disabled' ?>>Unlock</button>
                                <button name="lock_cmd" value="offline" class="px-3 py-2 rounded-lg border border-red-200 text-sm text-red-700 hover:bg-red-50 transition" <?= $lockColumnsReady ? '' : 'disabled' ?>>Offline</button>
                                <button name="lock_cmd" value="online" class="px-3 py-2 rounded-lg border border-green-200 text-sm text-green-700 hover:bg-green-50 transition" <?= $lockColumnsReady ? '' : 'disabled' ?>>Online</button>
                            </div>
                        </form>

                        <div class="mt-3 text-right text-xs">
                            <a class="text-blue-600 hover:underline" href="room_view.php?id=<?= (int)$r['id'] ?>" target="_blank">Open room page</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
