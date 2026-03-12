<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$pendingApprovals = [];

$pageTitle = 'Events & Conferences - Hotel Management System';
$extraHeadHtml = <<<'HTML'
<style>
    #content {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    #content::-webkit-scrollbar { display: none; width: 0; height: 0; }
    main {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    main::-webkit-scrollbar { display: none; width: 0; height: 0; }
    * { scrollbar-width: none; -ms-overflow-style: none; }
    *::-webkit-scrollbar { display: none; width: 0; height: 0; }

    .dashboard-card {
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        transition: left 0.6s ease;
    }
    .dashboard-card:hover::before { left: 100%; }
    .dashboard-card:hover {
        transform: translateY(-6px) scale(1.01);
        box-shadow: 0 18px 36px rgba(0,0,0,0.08);
        border-color: #3b82f6;
    }
    .dashboard-card .icon-container { transition: all 0.4s ease; }
    .dashboard-card:hover .icon-container {
        transform: scale(1.08) rotate(4deg);
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    }
    .dashboard-card:hover .icon-container i { color: white !important; }

    .chart-container {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 24px;
        transition: all 0.4s ease;
    }
    .chart-container:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        border-color: #3b82f6;
    }
</style>
HTML;

$errors = [];
$flashLocal = null;

$APP_BASE_URL = App::baseUrl();

$hasFunctionRooms = false;
$hasEvents = false;
$hasFunctionRoomImageColumn = false;
$hasEventImageColumn = false;
if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'function_rooms'"
            );
            $hasFunctionRooms = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events'"
            );
            $hasEvents = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            if ($hasFunctionRooms) {
                $res = $conn->query(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$db}'
                       AND TABLE_NAME = 'function_rooms'
                       AND COLUMN_NAME = 'image_path'"
                );
                $hasFunctionRoomImageColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }

            if ($hasEvents) {
                $res = $conn->query(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$db}'
                       AND TABLE_NAME = 'events'
                       AND COLUMN_NAME = 'image_path'"
                );
                $hasEventImageColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }
        }
    } catch (Throwable $e) {
    }
}

if (Request::isPost() && $conn) {
    $action = (string)Request::post('action', '');

    if ($action === 'create_function_room' && $hasFunctionRooms) {
        $name = trim((string)Request::post('name', ''));
        $capacity = (int)Request::post('capacity', 0);
        $rate = (string)Request::post('base_rate', '0');
        $imagePath = (string)Request::post('image_path', '');
        $status = (string)Request::post('status', 'Available');
        $active = ((string)Request::post('is_active', '1') === '1') ? 1 : 0;
        $notes = (string)Request::post('notes', '');

        if (isset($_FILES['room_image']) && is_array($_FILES['room_image']) && (int)($_FILES['room_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['room_image']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['image_path'] = 'Failed to upload image.';
            } else {
                $tmp = (string)($_FILES['room_image']['tmp_name'] ?? '');
                $orig = (string)($_FILES['room_image']['name'] ?? '');
                $size = (int)($_FILES['room_image']['size'] ?? 0);

                if ($size <= 0) {
                    $errors['image_path'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['image_path'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['image_path'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $root = dirname(__DIR__, 2);
                        $uploadDir = $root . '/uploads/events/function_rooms';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $errors['image_path'] = 'Upload directory is not writable.';
                        } else {
                            $filename = 'function_room_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $dest = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $errors['image_path'] = 'Failed to save uploaded image.';
                            } else {
                                $imagePath = '/uploads/events/function_rooms/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($name === '') {
            $errors['name'] = 'Function room name is required.';
        }
        if ($capacity < 0) {
            $errors['capacity'] = 'Capacity is invalid.';
        }
        if (!is_numeric($rate) || (float)$rate < 0) {
            $errors['base_rate'] = 'Base rate is invalid.';
        }
        if (!in_array($status, ['Available', 'Maintenance'], true)) {
            $errors['status'] = 'Status is invalid.';
        }

        if (empty($errors)) {
            $sql = $hasFunctionRoomImageColumn
                ? "INSERT INTO function_rooms (name, capacity, base_rate, image_path, status, is_active, notes)
                   VALUES (?, ?, ?, NULLIF(?,''), ?, ?, ?)"
                : "INSERT INTO function_rooms (name, capacity, base_rate, status, is_active, notes)
                   VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if ($stmt instanceof mysqli_stmt) {
                $rateF = (float)$rate;
                if ($hasFunctionRoomImageColumn) {
                    $stmt->bind_param('sidssis', $name, $capacity, $rateF, $imagePath, $status, $active, $notes);
                } else {
                    $stmt->bind_param('sidsis', $name, $capacity, $rateF, $status, $active, $notes);
                }
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Function room saved.');
                    Response::redirect('events_conferences.php');
                }
            }

            $errors['general'] = 'Failed to save function room.';
        }
    }

    if ($action === 'create_event' && $hasEvents) {
        $title = trim((string)Request::post('title', ''));
        $eventImagePath = (string)Request::post('event_image_path', '');
        $clientName = trim((string)Request::post('client_name', ''));
        $clientPhone = trim((string)Request::post('client_phone', ''));
        $clientEmail = trim((string)Request::post('client_email', ''));
        $eventDate = trim((string)Request::post('event_date', ''));
        $startTime = trim((string)Request::post('start_time', ''));
        $endTime = trim((string)Request::post('end_time', ''));
        $expectedGuests = (int)Request::post('expected_guests', 0);
        $functionRoomId = (int)Request::post('function_room_id', 0);
        $status = (string)Request::post('status', 'Inquiry');
        $estimatedTotal = (string)Request::post('estimated_total', '0');
        $deposit = (string)Request::post('deposit_amount', '0');
        $notes = (string)Request::post('notes', '');

        if (isset($_FILES['event_image']) && is_array($_FILES['event_image']) && (int)($_FILES['event_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)($_FILES['event_image']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['event_image_path'] = 'Failed to upload image.';
            } else {
                $tmp = (string)($_FILES['event_image']['tmp_name'] ?? '');
                $orig = (string)($_FILES['event_image']['name'] ?? '');
                $size = (int)($_FILES['event_image']['size'] ?? 0);

                if ($size <= 0) {
                    $errors['event_image_path'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['event_image_path'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['event_image_path'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $root = dirname(__DIR__, 2);
                        $uploadDir = $root . '/uploads/events';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                            $errors['event_image_path'] = 'Upload directory is not writable.';
                        } else {
                            $filename = 'event_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            $dest = $uploadDir . '/' . $filename;
                            if (!move_uploaded_file($tmp, $dest)) {
                                $errors['event_image_path'] = 'Failed to save uploaded image.';
                            } else {
                                $eventImagePath = '/uploads/events/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($title === '') {
            $errors['title'] = 'Event title is required.';
        }
        if ($clientName === '') {
            $errors['client_name'] = 'Client name is required.';
        }
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            $errors['event_date'] = 'Event date is invalid.';
        }
        if ($expectedGuests < 0) {
            $errors['expected_guests'] = 'Expected guests is invalid.';
        }
        if (!in_array($status, ['Inquiry', 'Quoted', 'Confirmed', 'Ongoing', 'Completed', 'Cancelled'], true)) {
            $errors['status'] = 'Status is invalid.';
        }
        if (!is_numeric($estimatedTotal) || (float)$estimatedTotal < 0) {
            $errors['estimated_total'] = 'Estimated total is invalid.';
        }
        if (!is_numeric($deposit) || (float)$deposit < 0) {
            $errors['deposit_amount'] = 'Deposit amount is invalid.';
        }

        if (empty($errors)) {
            $eventNo = 'EVT-' . date('Ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            $sql = $hasEventImageColumn
                ? "INSERT INTO events (event_no, title, image_path, client_name, client_phone, client_email, event_date, start_time, end_time, expected_guests, function_room_id, status, estimated_total, deposit_amount, notes)
                   VALUES (?, ?, NULLIF(?,''), ?, ?, ?, ?, NULLIF(?,''), NULLIF(?,''), ?, NULLIF(?,0), ?, ?, ?, ?)"
                : "INSERT INTO events (event_no, title, client_name, client_phone, client_email, event_date, start_time, end_time, expected_guests, function_room_id, status, estimated_total, deposit_amount, notes)
                   VALUES (?, ?, ?, ?, ?, ?, NULLIF(?,''), NULLIF(?,''), ?, NULLIF(?,0), ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if ($stmt instanceof mysqli_stmt) {
                $estF = (float)$estimatedTotal;
                $depF = (float)$deposit;
                if ($hasEventImageColumn) {
                    $stmt->bind_param(
                        'sssssssssiisdds',
                        $eventNo,
                        $title,
                        $eventImagePath,
                        $clientName,
                        $clientPhone,
                        $clientEmail,
                        $eventDate,
                        $startTime,
                        $endTime,
                        $expectedGuests,
                        $functionRoomId,
                        $status,
                        $estF,
                        $depF,
                        $notes
                    );
                } else {
                    $stmt->bind_param(
                        'ssssssssii sdds',
                        $eventNo,
                        $title,
                        $clientName,
                        $clientPhone,
                        $clientEmail,
                        $eventDate,
                        $startTime,
                        $endTime,
                        $expectedGuests,
                        $functionRoomId,
                        $status,
                        $estF,
                        $depF,
                        $notes
                    );
                }
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Event saved.');
                    Response::redirect('events_conferences.php');
                }
            }

            $errors['general'] = 'Failed to save event.';
        }
    }
}

$functionRooms = [];
$eventsUpcoming = [];
$eventsPipeline = [];

$kpiRooms = 0;
$kpiUpcoming = 0;
$kpiInquiries = 0;
$kpiEstRevenueMonth = 0.0;

if ($conn && $hasFunctionRooms) {
    $roomImageSelect = $hasFunctionRoomImageColumn ? 'image_path' : 'NULL AS image_path';
    $res = $conn->query("SELECT id, name, capacity, base_rate, {$roomImageSelect}, status, is_active, notes, created_at FROM function_rooms ORDER BY id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $functionRooms[] = $row;
        }
    }
    $kpiRooms = count($functionRooms);
}

if ($conn && $hasEvents) {
    $eventImageSelect = $hasEventImageColumn ? 'e.image_path' : 'NULL AS image_path';
    $functionRoomImageSelect = $hasFunctionRoomImageColumn ? 'fr.image_path AS function_room_image_path' : 'NULL AS function_room_image_path';
    $res = $conn->query(
        "SELECT e.id, e.event_no, e.title, {$eventImageSelect}, e.client_name, e.event_date, e.start_time, e.end_time, e.status,
                e.expected_guests, e.estimated_total, e.deposit_amount,
                fr.name AS function_room_name, {$functionRoomImageSelect}
         FROM events e
         LEFT JOIN function_rooms fr ON fr.id = e.function_room_id
         WHERE e.event_date >= CURDATE() AND e.status NOT IN ('Cancelled','Completed')
         ORDER BY e.event_date ASC, e.start_time ASC
         LIMIT 10"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $eventsUpcoming[] = $row;
        }
    }

    $res = $conn->query(
        "SELECT e.id, e.event_no, e.title, {$eventImageSelect}, e.client_name, e.event_date, e.status, e.estimated_total,
                fr.name AS function_room_name, {$functionRoomImageSelect}
         FROM events e
         LEFT JOIN function_rooms fr ON fr.id = e.function_room_id
         WHERE e.status IN ('Inquiry','Quoted','Confirmed','Ongoing')
         ORDER BY FIELD(e.status,'Inquiry','Quoted','Confirmed','Ongoing'), e.event_date ASC
         LIMIT 12"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $eventsPipeline[] = $row;
        }
    }

    $res = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status = 'Inquiry'");
    $kpiInquiries = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
    $kpiUpcoming = count($eventsUpcoming);

    $res = $conn->query(
        "SELECT COALESCE(SUM(estimated_total),0) AS s
         FROM events
         WHERE status IN ('Confirmed','Ongoing','Completed')
           AND DATE_FORMAT(event_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"
    );
    $kpiEstRevenueMonth = $res ? (float)($res->fetch_assoc()['s'] ?? 0) : 0.0;
}

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Events & Conferences</h1>
            <p class="text-sm text-gray-500 mt-1">Event bookings, function rooms, packages, schedules</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasFunctionRooms || !$hasEvents): ?>
            <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                This module needs DB tables/columns. Run the updated schema SQL to create <span class="font-medium">function_rooms</span> and <span class="font-medium">events</span> tables (with image columns).
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Function Rooms</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiRooms ?></p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-buildings text-blue-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Upcoming Events</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiUpcoming ?></p>
                        <p class="text-xs text-gray-500 mt-1">Next 10 events</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-calendar-event text-green-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Inquiries</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiInquiries ?></p>
                        <p class="text-xs text-gray-500 mt-1">New leads</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-message-rounded-dots text-orange-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Est. Revenue (Month)</p>
                        <p class="text-2xl font-light text-gray-900">₱<?= number_format((float)$kpiEstRevenueMonth, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Confirmed/Ongoing/Completed</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-money text-purple-600 text-xl'></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="chart-container">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Event Pipeline</h3>
                    <div class="text-xs text-gray-500">Inquiry → Quoted → Confirmed → Ongoing</div>
                </div>

                <?php if (empty($eventsPipeline)): ?>
                    <div class="text-sm text-gray-500">No pipeline items yet.</div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($eventsPipeline as $e): ?>
                            <?php
                                $st = (string)($e['status'] ?? '');
                                $badge = 'border-gray-200 bg-gray-50 text-gray-700';
                                if ($st === 'Inquiry') $badge = 'border-orange-200 bg-orange-50 text-orange-700';
                                if ($st === 'Quoted') $badge = 'border-blue-200 bg-blue-50 text-blue-700';
                                if ($st === 'Confirmed') $badge = 'border-green-200 bg-green-50 text-green-700';
                                if ($st === 'Ongoing') $badge = 'border-purple-200 bg-purple-50 text-purple-700';

                                $thumb = trim((string)($e['image_path'] ?? ''));
                                if ($thumb === '') {
                                    $thumb = trim((string)($e['function_room_image_path'] ?? ''));
                                }
                                if ($thumb !== '' && !preg_match('/^https?:\/\//i', $thumb)) {
                                    $thumb = $APP_BASE_URL . $thumb;
                                }
                            ?>
                            <div class="rounded-lg border border-gray-100 p-4 hover:bg-gray-50 transition">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex items-start gap-3">
                                        <div class="w-14 h-12 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                                            <?php if ($thumb !== ''): ?>
                                                <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                            <?php else: ?>
                                                <div class="text-[10px] text-gray-400">No image</div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['client_name'] ?? '')) ?> • <?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['function_room_name'] ?? 'Unassigned')) ?></div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($st) ?></span>
                                        <div class="text-xs text-gray-500 mt-2">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="chart-container">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Upcoming Schedule</h3>
                    <div class="text-xs text-gray-500">Next 10 events</div>
                </div>

                <?php if (empty($eventsUpcoming)): ?>
                    <div class="text-sm text-gray-500">No upcoming events yet.</div>
                <?php else: ?>
                    <div class="overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left font-medium px-4 py-3">Date</th>
                                    <th class="text-left font-medium px-4 py-3">Event</th>
                                    <th class="text-left font-medium px-4 py-3">Room</th>
                                    <th class="text-right font-medium px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($eventsUpcoming as $e): ?>
                                    <?php
                                        $thumb = trim((string)($e['image_path'] ?? ''));
                                        if ($thumb === '') {
                                            $thumb = trim((string)($e['function_room_image_path'] ?? ''));
                                        }
                                        if ($thumb !== '' && !preg_match('/^https?:\/\//i', $thumb)) {
                                            $thumb = $APP_BASE_URL . $thumb;
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-700">
                                            <div class="font-medium"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars(trim((string)($e['start_time'] ?? ''))) ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-10 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                                                    <?php if ($thumb !== ''): ?>
                                                        <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                    <?php else: ?>
                                                        <div class="text-[10px] text-gray-400">No image</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="text-gray-900 font-medium"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['client_name'] ?? '')) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['function_room_name'] ?? 'Unassigned')) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Add Function Room</h3>
                <form method="post" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="action" value="create_function_room" />
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input name="name" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                        <input name="capacity" type="number" min="0" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Base Rate</label>
                        <input name="base_rate" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                        <input type="file" name="room_image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['image_path'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['image_path']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                        <input name="image_path" placeholder="e.g. /uploads/events/function_rooms/hall.webp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="Available">Available</option>
                            <option value="Maintenance">Maintenance</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4" checked />
                        <label class="text-sm text-gray-700">Active</label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
                    </div>
                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save Room</button>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Function Rooms</h3>
                    <div class="text-xs text-gray-500">List</div>
                </div>
                <?php if (empty($functionRooms)): ?>
                    <div class="text-sm text-gray-500">No function rooms yet.</div>
                <?php else: ?>
                    <div class="overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left font-medium px-4 py-3">Venue</th>
                                    <th class="text-right font-medium px-4 py-3">Capacity</th>
                                    <th class="text-right font-medium px-4 py-3">Rate</th>
                                    <th class="text-right font-medium px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($functionRooms as $fr): ?>
                                    <?php
                                        $img = trim((string)($fr['image_path'] ?? ''));
                                        if ($img !== '' && !preg_match('/^https?:\/\//i', $img)) {
                                            $img = $APP_BASE_URL . $img;
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-12 h-10 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center">
                                                    <?php if ($img !== ''): ?>
                                                        <img src="<?= htmlspecialchars($img) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                    <?php else: ?>
                                                        <div class="text-[10px] text-gray-400">No image</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-gray-900 font-medium"><?= htmlspecialchars((string)($fr['name'] ?? '')) ?></div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= (int)($fr['capacity'] ?? 0) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($fr['base_rate'] ?? 0), 2) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars((string)($fr['status'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Add Event</h3>
                <form method="post" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="action" value="create_event" />
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                        <input name="title" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                        <input type="file" name="event_image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['event_image_path'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['event_image_path']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                        <input name="event_image_path" placeholder="e.g. /uploads/events/event.webp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Name</label>
                        <input name="client_name" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input name="client_phone" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input name="client_email" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Event Date</label>
                        <input type="date" name="event_date" min="2000-01-01" max="2100-12-31" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start</label>
                            <input type="time" name="start_time" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End</label>
                            <input type="time" name="end_time" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expected Guests</label>
                        <input name="expected_guests" type="number" min="0" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Function Room</label>
                        <select name="function_room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Unassigned</option>
                            <?php foreach ($functionRooms as $fr): ?>
                                <option value="<?= (int)($fr['id'] ?? 0) ?>"><?= htmlspecialchars((string)($fr['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="Inquiry">Inquiry</option>
                            <option value="Quoted">Quoted</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Ongoing">Ongoing</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Estimated Total</label>
                            <input name="estimated_total" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Deposit</label>
                            <input name="deposit_amount" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>
                    </div>
                    <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Save Event</button>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">All Events (Recent)</h3>
                    <div class="text-xs text-gray-500">Pipeline + schedule</div>
                </div>
                <?php if (!$hasEvents): ?>
                    <div class="text-sm text-gray-500">Events table not found yet.</div>
                <?php else: ?>
                    <?php
                        $eventsRecent = [];
                        if ($conn) {
                            $res = $conn->query(
                                "SELECT e.event_no, e.title, e.image_path, e.client_name, e.event_date, e.status, e.estimated_total,
                                        fr.name AS function_room_name, fr.image_path AS function_room_image_path
                                 FROM events e
                                 LEFT JOIN function_rooms fr ON fr.id = e.function_room_id
                                 ORDER BY e.id DESC
                                 LIMIT 15"
                            );
                            if ($res) {
                                while ($row = $res->fetch_assoc()) {
                                    $eventsRecent[] = $row;
                                }
                            }
                        }
                    ?>
                    <?php if (empty($eventsRecent)): ?>
                        <div class="text-sm text-gray-500">No events yet.</div>
                    <?php else: ?>
                        <div class="overflow-auto rounded-lg border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left font-medium px-4 py-3">Event</th>
                                        <th class="text-left font-medium px-4 py-3">Client</th>
                                        <th class="text-left font-medium px-4 py-3">Date</th>
                                        <th class="text-left font-medium px-4 py-3">Room</th>
                                        <th class="text-right font-medium px-4 py-3">Total</th>
                                        <th class="text-right font-medium px-4 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($eventsRecent as $e): ?>
                                        <?php
                                            $thumb = trim((string)($e['image_path'] ?? ''));
                                            if ($thumb === '') {
                                                $thumb = trim((string)($e['function_room_image_path'] ?? ''));
                                            }
                                            if ($thumb !== '' && !preg_match('/^https?:\/\//i', $thumb)) {
                                                $thumb = $APP_BASE_URL . $thumb;
                                            }
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-12 h-10 rounded-lg border border-gray-100 bg-gray-50 overflow-hidden flex items-center justify-center flex-shrink-0">
                                                        <?php if ($thumb !== ''): ?>
                                                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                        <?php else: ?>
                                                            <div class="text-[10px] text-gray-400">No image</div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></div>
                                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($e['event_no'] ?? '')) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['client_name'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['function_room_name'] ?? 'Unassigned')) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
