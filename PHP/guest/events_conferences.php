<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Notifications/NotificationRepository.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$userId = (int)($_SESSION['user_id'] ?? 0);
$guestId = (int)($_SESSION['guest_id'] ?? 0);

if ($guestId <= 0) {
    Flash::set('error', 'Guest profile is not linked. Please contact front desk.');
    Response::redirect('index.php');
}

$errors = [];

$hasFunctionRooms = false;
$hasEvents = false;
$hasClientUserId = false;
$hasClientGuestId = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'function_rooms'");
            $hasFunctionRooms = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events'");
            $hasEvents = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            if ($hasEvents) {
                $res = $conn->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_user_id'"
                );
                $hasClientUserId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_guest_id'"
                );
                $hasClientGuestId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }
        }
    } catch (Throwable $e) {
    }
}

$functionRooms = [];
if ($conn && $hasFunctionRooms) {
    $hasHkCompletedAt = false;
    $hasHkFunctionRoomId = false;
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = '{$db}'
                   AND TABLE_NAME = 'housekeeping_tasks'
                   AND COLUMN_NAME = 'completed_at'"
            );
            $hasHkCompletedAt = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = '{$db}'
                   AND TABLE_NAME = 'housekeeping_tasks'
                   AND COLUMN_NAME = 'function_room_id'"
            );
            $hasHkFunctionRoomId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }
    } catch (Throwable $e) {
    }

    if ($hasHkCompletedAt && $hasHkFunctionRoomId) {
        $res = $conn->query(
            "SELECT fr.id, fr.name, fr.capacity, fr.base_rate, fr.image_path, fr.status, fr.is_active, fr.notes,
                    (
                        SELECT MAX(t.completed_at)
                        FROM housekeeping_tasks t
                        WHERE t.function_room_id = fr.id
                          AND t.status = 'Done'
                    ) AS last_cleaned_at
             FROM function_rooms fr
             WHERE fr.is_active = 1 AND fr.status = 'Available'
             ORDER BY last_cleaned_at DESC, fr.base_rate ASC, fr.name ASC"
        );
    } else {
        $res = $conn->query(
            "SELECT id, name, capacity, base_rate, image_path, status, is_active, notes
             FROM function_rooms
             WHERE is_active = 1 AND status = 'Available'
             ORDER BY base_rate ASC, name ASC"
        );
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $functionRooms[] = $row;
        }
    }
}

$myEvents = [];
if ($conn && $hasEvents) {
    $sql =
        "SELECT e.id, e.event_no, e.title, e.event_date, e.start_time, e.end_time, e.expected_guests, e.status, e.estimated_total, e.deposit_amount,
                fr.name AS function_room_name
         FROM events e
         LEFT JOIN function_rooms fr ON fr.id = e.function_room_id";

    $where = [];
    $types = '';
    $params = [];
    if ($hasClientGuestId) {
        $where[] = 'e.client_guest_id = ?';
        $types .= 'i';
        $params[] = $guestId;
    } elseif ($hasClientUserId) {
        $where[] = 'e.client_user_id = ?';
        $types .= 'i';
        $params[] = $userId;
    } else {
        $where[] = '1=0';
    }

    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT 50';

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
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $myEvents[] = $row;
        }
        $stmt->close();
    }
}

$sanitizeDate = static function (string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return '';
    $y = (int)substr($v, 0, 4);
    if ($y < 2000) return '';
    return $v;
};

$loadGuestProfile = function () use ($conn, $guestId): array {
    $nm = '';
    $ph = '';
    $em = '';
    if (!$conn || $guestId <= 0) {
        return [$nm, $ph, $em];
    }
    $stmt = $conn->prepare('SELECT first_name, last_name, phone, email FROM guests WHERE id = ? LIMIT 1');
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $nm = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            $ph = preg_replace('/\D+/', '', (string)($row['phone'] ?? ''));
            $em = trim((string)($row['email'] ?? ''));
        }
    }
    return [$nm, $ph, $em];
};

if (Request::isPost() && $conn && $hasEvents) {
    $action = (string)Request::post('action', '');

    if ($action === 'create_event') {
        $title = trim((string)Request::post('title', ''));
        $eventDate = $sanitizeDate((string)Request::post('event_date', ''));
        $startTime = trim((string)Request::post('start_time', ''));
        $endTime = trim((string)Request::post('end_time', ''));
        $expectedGuestsRaw = trim((string)Request::post('expected_guests', '0'));
        $expectedGuests = (int)$expectedGuestsRaw;
        $functionRoomId = (int)Request::post('function_room_id', 0);
        $notes = trim((string)Request::post('notes', ''));

        if (!$hasFunctionRooms) {
            $errors['general'] = 'Function rooms are not configured yet.';
        }
        if ($title === '') {
            $errors['title'] = 'Event title is required.';
        }
        if ($eventDate === '') {
            $errors['event_date'] = 'Event date is invalid.';
        }
        if ($expectedGuestsRaw === '' || !ctype_digit($expectedGuestsRaw) || $expectedGuests <= 0) {
            $errors['expected_guests'] = 'Expected guests must be at least 1.';
        }
        if ($functionRoomId <= 0) {
            $errors['function_room_id'] = 'Please select a function room.';
        }
        if ($startTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            $errors['start_time'] = 'Start time is invalid.';
        }
        if ($endTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            $errors['end_time'] = 'End time is invalid.';
        }
        if ($startTime !== '' && $endTime !== '' && empty($errors['start_time']) && empty($errors['end_time'])) {
            $t1 = strtotime('1970-01-01 ' . $startTime);
            $t2 = strtotime('1970-01-01 ' . $endTime);
            if ($t1 !== false && $t2 !== false && $t2 <= $t1) {
                $errors['end_time'] = 'End time must be after start time.';
            }
        }

        $roomCapacity = 0;
        $roomRate = 0.0;
        if (empty($errors) && $functionRoomId > 0) {
            $stmt = $conn->prepare("SELECT capacity, base_rate FROM function_rooms WHERE id = ? AND is_active = 1 AND status = 'Available' LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $functionRoomId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    $errors['function_room_id'] = 'Selected function room is not available.';
                } else {
                    $roomCapacity = (int)($row['capacity'] ?? 0);
                    $roomRate = (float)($row['base_rate'] ?? 0);
                }
            }

            if (empty($errors['expected_guests']) && $roomCapacity > 0 && $expectedGuests > $roomCapacity) {
                $errors['expected_guests'] = 'Expected guests cannot exceed the function room capacity.';
            }
        }

        if (empty($errors) && $functionRoomId > 0 && $eventDate !== '') {
            $startForCheck = $startTime !== '' ? $startTime : '00:00:00';
            $endForCheck = $endTime !== '' ? $endTime : '23:59:59';

            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM events
                 WHERE function_room_id = ?
                   AND event_date = ?
                   AND status NOT IN ('Cancelled','Completed')
                   AND COALESCE(start_time, '00:00:00') < ?
                   AND COALESCE(end_time, '23:59:59') > ?"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('isss', $functionRoomId, $eventDate, $endForCheck, $startForCheck);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ((int)($row['c'] ?? 0) > 0) {
                    $errors['function_room_id'] = 'Selected function room is already booked for this schedule.';
                }
            }
        }

        if (empty($errors)) {
            [$clientName, $clientPhone, $clientEmail] = $loadGuestProfile();
            if ($clientName === '') {
                $clientName = (string)($_SESSION['username'] ?? 'Guest');
            }

            $eventNo = 'EVT-' . date('Ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $estimatedTotal = max(0.0, $roomRate);
            $deposit = round($estimatedTotal * 0.20, 2);

            $cols = ['event_no', 'title', 'client_name', 'client_phone', 'client_email', 'event_date', 'start_time', 'end_time', 'expected_guests', 'function_room_id', 'status', 'estimated_total', 'deposit_amount', 'notes'];
            $placeholders = ['?', '?', '?', '?', '?', '?', 'NULLIF(?,\'\')', 'NULLIF(?,\'\')', '?', 'NULLIF(?,0)', '?', '?', '?', '?'];
            $types = 'sssssssssi sdds';
            $types = str_replace(' ', '', $types);
            $params = [];

            if ($hasClientUserId) {
                array_splice($cols, 3, 0, ['client_user_id']);
                array_splice($placeholders, 3, 0, ['NULLIF(?,0)']);
            }
            if ($hasClientGuestId) {
                $idx = $hasClientUserId ? 4 : 3;
                array_splice($cols, $idx, 0, ['client_guest_id']);
                array_splice($placeholders, $idx, 0, ['NULLIF(?,0)']);
            }

            $sql = 'INSERT INTO events (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';

            $stmt = $conn->prepare($sql);
            if (!($stmt instanceof mysqli_stmt)) {
                $errors['general'] = 'Failed to create event.';
            } else {
                $status = 'Inquiry';

                $bindTypes = '';
                $bindValues = [];

                $bindValues[] = $eventNo;
                $bindTypes .= 's';

                $bindValues[] = $title;
                $bindTypes .= 's';

                if ($hasClientUserId) {
                    $bindValues[] = $userId;
                    $bindTypes .= 'i';
                }
                if ($hasClientGuestId) {
                    $bindValues[] = $guestId;
                    $bindTypes .= 'i';
                }

                $bindValues[] = $clientName;
                $bindTypes .= 's';

                $bindValues[] = $clientPhone;
                $bindTypes .= 's';

                $bindValues[] = $clientEmail;
                $bindTypes .= 's';

                $bindValues[] = $eventDate;
                $bindTypes .= 's';

                $bindValues[] = $startTime;
                $bindTypes .= 's';

                $bindValues[] = $endTime;
                $bindTypes .= 's';

                $bindValues[] = $expectedGuests;
                $bindTypes .= 'i';

                $bindValues[] = $functionRoomId;
                $bindTypes .= 'i';

                $bindValues[] = $status;
                $bindTypes .= 's';

                $bindValues[] = $estimatedTotal;
                $bindTypes .= 'd';

                $bindValues[] = $deposit;
                $bindTypes .= 'd';

                $bindValues[] = $notes;
                $bindTypes .= 's';

                $bind = [];
                $bind[] = $bindTypes;
                foreach ($bindValues as $k => $v) {
                    $bind[] = &$bindValues[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
                $ok = $stmt->execute();
                $eventIdNew = (int)($stmt->insert_id ?? 0);
                $stmt->close();

                if ($ok && $eventIdNew > 0) {
                    $notifRepo = new NotificationRepository($conn);
                    $t = 'New event inquiry';
                    $m = $clientName . ' requested an event: ' . $title . '.';
                    $url = '/PHP/modules/events_conferences.php?edit_event_id=' . $eventIdNew;
                    $notifRepo->createForStaff($t, $m, $url);

                    Flash::set('success', 'Event request submitted. The front desk will confirm your booking.');
                    Response::redirect('events_conferences.php');
                }

                $errors['general'] = 'Failed to create event.';
            }
        }
    }
}

$pageTitle = 'Event & Conference - Guest Portal';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-light text-gray-900">Event & Conference</h1>
                <p class="text-sm text-gray-500 mt-1">Request a function room booking. Front desk confirmation is required.</p>
            </div>
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
                This page needs DB tables <span class="font-medium">function_rooms</span> and <span class="font-medium">events</span>. Please run the updated schema.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Available function rooms</h3>
                <?php if (empty($functionRooms)): ?>
                    <div class="text-sm text-gray-500">No function rooms available.</div>
                <?php else: ?>
                    <div class="space-y-3" style="max-height: 520px; overflow:auto;">
                        <?php foreach ($functionRooms as $fr): ?>
                            <?php
                                $img = trim((string)($fr['image_path'] ?? ''));
                                $imgUrl = '';
                                if ($img !== '') {
                                    $imgUrl = (substr($img, 0, 1) === '/') ? (rtrim($APP_BASE_URL, '/') . $img) : $img;
                                }
                            ?>
                            <div class="rounded-xl border border-gray-100 overflow-hidden">
                                <div class="h-28 bg-gray-50 flex items-center justify-center">
                                    <?php if ($imgUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                    <?php else: ?>
                                        <div class="text-xs text-gray-400">No image</div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-4">
                                    <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars((string)($fr['name'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-1">Capacity: <?= (int)($fr['capacity'] ?? 0) ?></div>
                                    <div class="text-xs text-gray-500 mt-1">Rate: ₱<?= number_format((float)($fr['base_rate'] ?? 0), 2) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-2">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Request an event</h3>
                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <input type="hidden" name="action" value="create_event" />

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Event title</label>
                        <input name="title" value="<?= htmlspecialchars((string)Request::post('title', '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['title'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['title']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Event date</label>
                        <input type="date" name="event_date" min="2000-01-01" max="2100-12-31" value="<?= htmlspecialchars((string)Request::post('event_date', '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['event_date'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['event_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expected guests</label>
                        <input type="number" name="expected_guests" min="1" value="<?= htmlspecialchars((string)Request::post('expected_guests', '1')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['expected_guests'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['expected_guests']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start time (optional)</label>
                        <input type="time" name="start_time" value="<?= htmlspecialchars((string)Request::post('start_time', '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['start_time'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['start_time']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End time (optional)</label>
                        <input type="time" name="end_time" value="<?= htmlspecialchars((string)Request::post('end_time', '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['end_time'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['end_time']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Function room</label>
                        <select name="function_room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select function room</option>
                            <?php $selRoom = (int)Request::post('function_room_id', 0); ?>
                            <?php foreach ($functionRooms as $fr): ?>
                                <option value="<?= (int)($fr['id'] ?? 0) ?>" <?= $selRoom === (int)($fr['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)($fr['name'] ?? '')) ?> • cap <?= (int)($fr['capacity'] ?? 0) ?> • ₱<?= number_format((float)($fr['base_rate'] ?? 0), 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['function_room_id'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['function_room_id']) ?></div>
                        <?php endif; ?>
                        <div class="text-xs text-gray-500 mt-1">Estimated total is based on the function room base rate. Deposit is 20%.</div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                        <textarea name="notes" rows="4" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars((string)Request::post('notes', '')) ?></textarea>
                    </div>

                    <div class="md:col-span-2 flex items-center gap-2">
                        <button class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Submit event request</button>
                    </div>
                </form>

                <div class="mt-8">
                    <h3 class="text-lg font-medium text-gray-900 mb-3">My event requests</h3>
                    <?php if (empty($myEvents)): ?>
                        <div class="text-sm text-gray-500">No event requests yet.</div>
                    <?php else: ?>
                        <div class="overflow-auto rounded-lg border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left font-medium px-4 py-3">Event #</th>
                                        <th class="text-left font-medium px-4 py-3">Title</th>
                                        <th class="text-left font-medium px-4 py-3">Date</th>
                                        <th class="text-left font-medium px-4 py-3">Room</th>
                                        <th class="text-right font-medium px-4 py-3">Total</th>
                                        <th class="text-right font-medium px-4 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($myEvents as $e): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['event_no'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['title'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['function_room_name'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></td>
                                            <td class="px-4 py-3 text-right text-gray-700"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
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
