<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$conn = Database::getConnection();
$guestId = (int)($_SESSION['guest_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

$recent = [];
$recentEvents = [];
$recentPosOrders = [];
$recentActivity = [];
$loyaltyPoints = null;
$loyaltyTier = null;
$guestEmail = '';
$guestPhone = '';

if ($conn && $guestId > 0) {
    $repo = new ReservationRepository($conn);
    $recent = $repo->listReservationsByGuestId($guestId, 10);

    try {
        $stmt = $conn->prepare('SELECT email, phone FROM guests WHERE id = ? LIMIT 1');
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $guestId);
            $stmt->execute();
            $g = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($g) {
                $guestEmail = trim((string)($g['email'] ?? ''));
                $guestPhone = preg_replace('/\D+/', '', (string)($g['phone'] ?? ''));
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events'");
            $hasEvents = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $eventsHasClientUserId = false;
            $eventsHasClientGuestId = false;
            if ($hasEvents) {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_user_id'");
                $eventsHasClientUserId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_guest_id'");
                $eventsHasClientGuestId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }

            if ($hasEvents && ($eventsHasClientGuestId || $eventsHasClientUserId)) {
                $sql =
                    "SELECT e.id, e.event_no, e.title, e.event_date, e.status, e.created_at,
                            fr.name AS function_room_name
                     FROM events e
                     LEFT JOIN function_rooms fr ON fr.id = e.function_room_id";
                $where = [];
                $types = '';
                $params = [];
                $or = [];
                if ($eventsHasClientGuestId) {
                    $or[] = 'e.client_guest_id = ?';
                    $types .= 'i';
                    $params[] = $guestId;
                }
                if ($eventsHasClientUserId && $userId > 0) {
                    $or[] = 'e.client_user_id = ?';
                    $types .= 'i';
                    $params[] = $userId;
                }
                if ($guestEmail !== '') {
                    $or[] = 'e.client_email = ?';
                    $types .= 's';
                    $params[] = $guestEmail;
                }
                if ($guestPhone !== '') {
                    $or[] = 'REPLACE(REPLACE(REPLACE(e.client_phone, \'-\', \'\'), \' \', \'\'), \'+\', \'\') = ?';
                    $types .= 's';
                    $params[] = $guestPhone;
                }
                if (empty($or)) {
                    $or[] = '1=0';
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
                $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT 10';

                $stmt = $conn->prepare($sql);
                if ($stmt instanceof mysqli_stmt) {
                    if ($types !== '') {
                        $bind = [];
                        $bind[] = $types;
                        foreach ($params as $k => $v) {
                            $bind[] = &$params[$k];
                        }
                        call_user_func_array([$stmt, 'bind_param'], $bind);
                    }
                    $stmt->execute();
                    $res2 = $stmt->get_result();
                    while ($row = $res2->fetch_assoc()) {
                        $recentEvents[] = $row;
                    }
                    $stmt->close();
                }
            }

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_orders'");
            $hasPosOrders = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            if ($hasPosOrders) {
                $stmt = $conn->prepare(
                    "SELECT id, order_no, order_type, status, total, created_at
                     FROM pos_orders
                     WHERE guest_id = ?
                     ORDER BY id DESC
                     LIMIT 10"
                );
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $guestId);
                    $stmt->execute();
                    $res3 = $stmt->get_result();
                    while ($row = $res3->fetch_assoc()) {
                        $recentPosOrders[] = $row;
                    }
                    $stmt->close();
                }
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME IN ('loyalty_points','loyalty_tier')");
            $has = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
            if ($has === 2) {
                $stmt = $conn->prepare('SELECT loyalty_points, loyalty_tier FROM guests WHERE id = ? LIMIT 1');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $guestId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        $loyaltyPoints = (int)($row['loyalty_points'] ?? 0);
                        $loyaltyTier = (string)($row['loyalty_tier'] ?? '');
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }
}

foreach ($recent as $row) {
    $recentActivity[] = [
        'type' => 'Reservation',
        'ref' => (string)($row['reference_no'] ?? ''),
        'name' => trim(((string)($row['room_no'] ?? '')) !== '' ? ('Room ' . (string)$row['room_no']) : ''),
        'status' => (string)($row['status'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'url' => ($APP_BASE_URL !== '' ? ($APP_BASE_URL . '/PHP/guest/deposit_slip.php?id=' . (int)($row['id'] ?? 0)) : ('/PHP/guest/deposit_slip.php?id=' . (int)($row['id'] ?? 0))),
    ];
}
foreach ($recentEvents as $row) {
    $recentActivity[] = [
        'type' => 'Event',
        'ref' => (string)($row['event_no'] ?? ''),
        'name' => (string)($row['function_room_name'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'url' => ($APP_BASE_URL !== '' ? ($APP_BASE_URL . '/PHP/guest/events_conferences.php') : '/PHP/guest/events_conferences.php'),
    ];
}
foreach ($recentPosOrders as $row) {
    $recentActivity[] = [
        'type' => 'POS',
        'ref' => (string)($row['order_no'] ?? ''),
        'name' => (string)($row['order_type'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'url' => '',
    ];
}

usort($recentActivity, static function (array $a, array $b): int {
    $ta = strtotime((string)($a['created_at'] ?? ''));
    $tb = strtotime((string)($b['created_at'] ?? ''));
    if ($ta === false) { $ta = 0; }
    if ($tb === false) { $tb = 0; }
    return $tb <=> $ta;
});
$recentActivity = array_slice($recentActivity, 0, 12);

$pageTitle = 'Guest Portal - Hotel Management System';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Guest Portal</h1>
            <p class="text-sm text-gray-500 mt-1">Browse rooms, request a booking, and print your ₱1,000 deposit slip for front desk confirmation.</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Quick actions</h3>
                <div class="space-y-3">
                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/rooms.php" class="block w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition text-center">Browse available rooms</a>
                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/reservations.php" class="block w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition text-center">View my reservations</a>
                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/settings.php" class="block w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition text-center">Account settings</a>
                </div>

                <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <div class="text-xs text-gray-500">Deposit policy</div>
                    <div class="text-sm text-gray-900 font-medium mt-1">₱1,000 down payment</div>
                    <div class="text-xs text-gray-600 mt-2">After requesting a booking, print the deposit slip and bring it to the front desk. The staff will confirm your reservation after payment.</div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-xl border border-gray-100 p-6">
                        <div class="text-xs text-gray-500">Loyalty points</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-2"><?= $loyaltyPoints !== null ? (int)$loyaltyPoints : 0 ?></div>
                        <div class="text-xs text-gray-500 mt-2">Tier: <span class="font-medium text-gray-900"><?= htmlspecialchars($loyaltyTier !== null && trim($loyaltyTier) !== '' ? $loyaltyTier : 'None') ?></span></div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-6">
                        <div class="text-xs text-gray-500">Account</div>
                        <div class="text-sm font-medium text-gray-900 mt-2"><?= htmlspecialchars((string)($_SESSION['username'] ?? '')) ?></div>
                        <div class="text-xs text-gray-500 mt-1">Role: Guest</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-6">
                    <div class="flex items-center justify-between gap-4 mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Recent activity</h3>
                        <div class="flex items-center gap-3">
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/reservations.php" class="text-sm text-blue-600 hover:underline">Reservations</a>
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/events_conferences.php" class="text-sm text-blue-600 hover:underline">Events</a>
                        </div>
                    </div>

                    <?php if (empty($recentActivity)): ?>
                        <div class="py-8 text-center text-gray-500 text-sm">No recent activity yet.</div>
                    <?php else: ?>
                        <div class="overflow-auto rounded-lg border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left font-medium px-4 py-3">Type</th>
                                        <th class="text-left font-medium px-4 py-3">Reference</th>
                                        <th class="text-left font-medium px-4 py-3">Details</th>
                                        <th class="text-left font-medium px-4 py-3">Status</th>
                                        <th class="text-left font-medium px-4 py-3">Created</th>
                                        <th class="text-right font-medium px-4 py-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($recentActivity as $a): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($a['type'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($a['ref'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($a['name'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($a['status'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($a['created_at'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <?php if (trim((string)($a['url'] ?? '')) !== ''): ?>
                                                    <a class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition" href="<?= htmlspecialchars((string)$a['url']) ?>">Open</a>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
