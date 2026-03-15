<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$guestId = (int)($_SESSION['guest_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$tab = trim((string)Request::get('tab', 'rooms'));
if (!in_array($tab, ['rooms', 'events'], true)) {
    $tab = 'rooms';
}

$rows = [];
$events = [];
$hasEvents = false;
$guestEmail = '';
$guestPhone = '';

if ($conn && $guestId > 0) {
    $repo = new ReservationRepository($conn);
    $rows = $repo->listReservationsByGuestId($guestId, 100);

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
            $eventsHasClientEmail = false;
            $eventsHasClientPhone = false;
            $hasFunctionRooms = false;
            if ($hasEvents) {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_user_id'");
                $eventsHasClientUserId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_guest_id'");
                $eventsHasClientGuestId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_email'");
                $eventsHasClientEmail = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_phone'");
                $eventsHasClientPhone = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'function_rooms'");
                $hasFunctionRooms = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }

            if ($hasEvents) {
                if ($hasFunctionRooms) {
                    $sql =
                        "SELECT e.id, e.event_no, e.title, e.event_date, e.start_time, e.end_time, e.expected_guests, e.status, e.estimated_total, e.deposit_amount,
                                fr.name AS function_room_name
                         FROM events e
                         LEFT JOIN function_rooms fr ON fr.id = e.function_room_id";
                } else {
                    $sql =
                        "SELECT e.id, e.event_no, e.title, e.event_date, e.start_time, e.end_time, e.expected_guests, e.status, e.estimated_total, e.deposit_amount,
                                NULL AS function_room_name
                         FROM events e";
                }
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
                if ($eventsHasClientEmail || $eventsHasClientPhone) {
                    $canCheckIds = ($eventsHasClientGuestId || $eventsHasClientUserId);
                    $idGuard = $canCheckIds ? 'COALESCE(e.client_guest_id,0) = 0 AND COALESCE(e.client_user_id,0) = 0 AND ' : '';

                    if ($guestEmail !== '' && $guestPhone !== '' && $eventsHasClientEmail && $eventsHasClientPhone) {
                        $or[] = "({$idGuard}e.client_email = ? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(e.client_phone, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '') = ?)";
                        $types .= 'ss';
                        $params[] = $guestEmail;
                        $params[] = $guestPhone;

                        $or[] = "(e.client_email = ? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(e.client_phone, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '') = ?)";
                        $types .= 'ss';
                        $params[] = $guestEmail;
                        $params[] = $guestPhone;
                    } elseif ($guestEmail !== '' && $eventsHasClientEmail) {
                        $or[] = "({$idGuard}e.client_email = ?)";
                        $types .= 's';
                        $params[] = $guestEmail;

                        $or[] = "(e.client_email = ?)";
                        $types .= 's';
                        $params[] = $guestEmail;
                    } elseif ($guestPhone !== '' && $eventsHasClientPhone) {
                        $or[] = "({$idGuard}REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(e.client_phone, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '') = ?)";
                        $types .= 's';
                        $params[] = $guestPhone;

                        $or[] = "(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(e.client_phone, '-', ''), ' ', ''), '+', ''), '(', ''), ')', '') = ?)";
                        $types .= 's';
                        $params[] = $guestPhone;
                    }
                }
                if (empty($or)) {
                    $or[] = '1=0';
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
                $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT 100';

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
                        $events[] = $row;
                    }
                    $stmt->close();
                }
            }
        }
    } catch (Throwable $e) {
    }
}

$pageTitle = 'My Reservations - Guest Portal';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-light text-gray-900">My reservations</h1>
                <p class="text-sm text-gray-500 mt-1">Pending reservations need front desk confirmation. Print your deposit slip and bring it with ₱1,000 down payment.</p>
            </div>
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/rooms.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Browse rooms</a>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="mb-4 flex items-center gap-2">
            <a href="reservations.php?tab=rooms" class="px-4 py-2 rounded-lg text-sm border <?= $tab === 'rooms' ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 hover:bg-gray-50' ?>">Room reservations</a>
            <a href="reservations.php?tab=events" class="px-4 py-2 rounded-lg text-sm border <?= $tab === 'events' ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 hover:bg-gray-50' ?>">Event requests</a>
        </div>

        <?php if ($tab === 'events'): ?>
            <div class="bg-white rounded-xl border border-gray-100 p-6">
                <?php if (!$hasEvents): ?>
                    <div class="py-10 text-center text-gray-500 text-sm">Events module is not available.</div>
                <?php elseif (empty($events)): ?>
                    <div class="py-10 text-center text-gray-500 text-sm">No event requests found.</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        <?php foreach ($events as $e): ?>
                            <div class="rounded-xl border border-gray-100 bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-xs text-gray-500">Event</div>
                                        <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars((string)($e['event_no'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500">Status</div>
                                        <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="text-xs text-gray-500">Schedule</div>
                                    <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= htmlspecialchars(trim((string)($e['start_time'] ?? '') . ' - ' . (string)($e['end_time'] ?? ''))) ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">Room: <?= htmlspecialchars((string)($e['function_room_name'] ?? '')) ?></div>
                                </div>

                                <div class="mt-4 grid grid-cols-2 gap-3">
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                        <div class="text-xs text-gray-500">Deposit</div>
                                        <div class="text-sm font-medium text-gray-900 mt-1">₱<?= number_format((float)($e['deposit_amount'] ?? 0), 2) ?></div>
                                    </div>
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                        <div class="text-xs text-gray-500">Total</div>
                                        <div class="text-sm font-medium text-gray-900 mt-1">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></div>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center gap-2">
                                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/events_conferences.php" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Open events</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl border border-gray-100 p-6">
                <?php if (empty($rows)): ?>
                    <div class="py-10 text-center text-gray-500 text-sm">No reservations found.</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        <?php foreach ($rows as $r): ?>
                            <div class="rounded-xl border border-gray-100 bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-xs text-gray-500">Reference</div>
                                        <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500">Status</div>
                                        <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="text-xs text-gray-500">Stay</div>
                                    <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= htmlspecialchars(trim(((string)($r['room_no'] ?? '')) !== '' ? ('Room ' . (string)$r['room_no'] . ' • ') : '') . (string)($r['room_type_name'] ?? '')) ?>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center gap-2">
                                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/deposit_slip.php?id=<?= (int)($r['id'] ?? 0) ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Deposit slip</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
