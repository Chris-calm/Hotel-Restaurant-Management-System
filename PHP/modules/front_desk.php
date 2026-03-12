<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Guests/GuestService.php';
require_once __DIR__ . '/../domain/Rooms/RoomTypeService.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';
require_once __DIR__ . '/../domain/Notifications/NotificationRepository.php';

$conn = Database::getConnection();

$guestService = new GuestService(new GuestRepository($conn));
$roomTypeService = new RoomTypeService(new RoomTypeRepository($conn));
$roomRepo = new RoomRepository($conn);
$maintenanceService = new MaintenanceService(new MaintenanceRepository($conn), $roomRepo);
$reservationService = new ReservationService(
    new ReservationRepository($conn),
    new HousekeepingRepository($conn),
    $roomRepo,
    $maintenanceService
);

$pendingApprovals = [];

$reservationId = Request::int('get', 'reservation_id', 0);
$prefillReservation = null;
$lockRoomSelection = false;
$systemReservationsOnly = true;

if ($reservationId > 0) {
    $prefillReservation = $reservationService->getReservationDetails($reservationId);
    if ($prefillReservation && (string)($prefillReservation['status'] ?? '') === 'Pending' && (int)($prefillReservation['room_id'] ?? 0) > 0) {
        $lockRoomSelection = true;
    } else {
        $prefillReservation = null;
        $reservationId = 0;
        $lockRoomSelection = false;
    }
}

$filters = [
    'checkin_date' => (string)Request::get('checkin_date', ''),
    'checkout_date' => (string)Request::get('checkout_date', ''),
    'room_type_id' => (int)Request::get('room_type_id', 0),
    'guest_q' => (string)Request::get('guest_q', ''),
];

if ($prefillReservation) {
    $filters['checkin_date'] = (string)($prefillReservation['checkin_date'] ?? '');
    $filters['checkout_date'] = (string)($prefillReservation['checkout_date'] ?? '');
    $filters['room_type_id'] = 0;
}

$sanitizeDate = static function (string $v): string {
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return '';
    }
    $y = (int)substr($v, 0, 4);
    if ($y < 2000) {
        return '';
    }
    return $v;
};

$filters['checkin_date'] = $sanitizeDate((string)$filters['checkin_date']);
$filters['checkout_date'] = $sanitizeDate((string)$filters['checkout_date']);

$guests = $reservationService->listGuests($filters['guest_q']);
$roomTypes = $roomTypeService->list();

$pendingOnlineReservations = [];
if ($conn) {
    $pendingOnlineReservations = $reservationService->listPendingOnlineReservations(50);
}

$availableRooms = [];
$checkinValid = ($filters['checkin_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['checkin_date']) === 1);
$checkoutValid = ($filters['checkout_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['checkout_date']) === 1);
$dateRangeValid = false;
if ($checkinValid && $checkoutValid) {
    $t1 = strtotime($filters['checkin_date']);
    $t2 = strtotime($filters['checkout_date']);
    $dateRangeValid = ($t1 !== false && $t2 !== false && $t2 > $t1);
}

if (!$systemReservationsOnly || $prefillReservation) {
    if ($dateRangeValid) {
        $availableRooms = $reservationService->findAvailableRooms(
            $filters['checkin_date'],
            $filters['checkout_date'],
            $filters['room_type_id']
        );
    } else {
        $rooms = $roomRepo->search('');
        foreach ($rooms as $r) {
            if ((string)($r['status'] ?? '') !== 'Vacant') {
                continue;
            }
            if ((int)$filters['room_type_id'] > 0 && (int)($r['room_type_id'] ?? 0) !== (int)$filters['room_type_id']) {
                continue;
            }

            if (!isset($r['room_image_path']) && isset($r['image_path'])) {
                $r['room_image_path'] = $r['image_path'];
            }
            $availableRooms[] = $r;
        }
    }
} else {
    $availableRooms = [];
}

if ($lockRoomSelection && $prefillReservation) {
    $roomId = (int)($prefillReservation['room_id'] ?? 0);
    $locked = $roomRepo->findById($roomId);
    if (!$locked) {
        $locked = (new ReservationRepository($conn))->findRoomById($roomId);
    }

    if (is_array($locked) && !isset($locked['room_image_path']) && isset($locked['image_path'])) {
        $locked['room_image_path'] = $locked['image_path'];
    }

    if (!$locked && $roomId > 0) {
        $locked = [
            'id' => $roomId,
            'room_no' => (string)($prefillReservation['room_no'] ?? ''),
            'floor' => (string)($prefillReservation['floor'] ?? ''),
            'status' => 'Vacant',
            'room_type_id' => (int)($prefillReservation['room_type_id'] ?? 0),
            'room_type_name' => (string)($prefillReservation['room_type_name'] ?? ''),
            'room_type_code' => (string)($prefillReservation['room_type_code'] ?? ''),
            'base_rate' => is_numeric((string)($prefillReservation['rate'] ?? '')) ? (float)$prefillReservation['rate'] : 0,
        ];
    }

    $availableRooms = [];
    if (is_array($locked) && (int)($locked['id'] ?? 0) > 0) {
        if (!isset($locked['base_rate']) && isset($locked['rate'])) {
            $locked['base_rate'] = $locked['rate'];
        }
        $availableRooms[] = $locked;
    }
}

$nights = 0;
if ($dateRangeValid) {
    $n1 = strtotime($filters['checkin_date']);
    $n2 = strtotime($filters['checkout_date']);
    if ($n1 !== false && $n2 !== false && $n2 > $n1) {
        $nights = (int)round(($n2 - $n1) / 86400);
    }
}

$errors = [];
$data = [
    'guest_id' => 0,
    'source' => 'Walk-in',
    'checkin_date' => $filters['checkin_date'],
    'checkout_date' => $filters['checkout_date'],
    'room_id' => 0,
    'rate' => '',
    'adults' => 1,
    'children' => 0,
    'promo_code' => '',
    'deposit_amount' => '',
    'payment_method' => 'Cash',
    'notes' => '',
];

if ($prefillReservation) {
    $data['guest_id'] = (int)($prefillReservation['guest_id'] ?? 0);
    $data['source'] = (string)($prefillReservation['source'] ?? 'Website');
    $data['checkin_date'] = (string)($prefillReservation['checkin_date'] ?? '');
    $data['checkout_date'] = (string)($prefillReservation['checkout_date'] ?? '');
    $data['room_id'] = (int)($prefillReservation['room_id'] ?? 0);
    $data['rate'] = (string)($prefillReservation['rate'] ?? '');
    $data['adults'] = (int)($prefillReservation['adults'] ?? 1);
    $data['children'] = (int)($prefillReservation['children'] ?? 0);
    $data['deposit_amount'] = (string)($prefillReservation['deposit_amount'] ?? '1000');
    $data['payment_method'] = (string)($prefillReservation['payment_method'] ?? 'Cash');
    $data['notes'] = (string)($prefillReservation['notes'] ?? '');
}

if (Request::isPost()) {
    if ($systemReservationsOnly && !$prefillReservation) {
        $errors['general'] = 'Walk-in creation is disabled. Please select a Pending online reservation from the list.';
    }

    $data['guest_id'] = Request::int('post', 'guest_id', 0);
    $data['source'] = (string)Request::post('source', 'Walk-in');
    $data['checkin_date'] = $sanitizeDate((string)Request::post('checkin_date', ''));
    $data['checkout_date'] = $sanitizeDate((string)Request::post('checkout_date', ''));
    $data['room_id'] = Request::int('post', 'room_id', 0);
    $data['rate'] = (string)Request::post('rate', '');
    $data['adults'] = (int)Request::post('adults', 1);
    $data['children'] = (int)Request::post('children', 0);
    $data['promo_code'] = (string)Request::post('promo_code', '');
    $data['deposit_amount'] = (string)Request::post('deposit_amount', '');
    $data['payment_method'] = (string)Request::post('payment_method', 'Cash');
    $data['notes'] = (string)Request::post('notes', '');

    if (empty($errors)) {
        if ($prefillReservation) {
            $payload = [];
            if (is_numeric((string)($data['deposit_amount'] ?? ''))) {
                $payload['deposit_amount'] = (float)$data['deposit_amount'];
            }
            if ((string)($data['payment_method'] ?? '') !== '') {
                $payload['payment_method'] = (string)$data['payment_method'];
            }

            $ok = $reservationService->updateStatus((int)$prefillReservation['id'], 'Confirmed', $payload, $errors);
            if ($ok) {
                $guestUserId = 0;
                try {
                    $gid = (int)($prefillReservation['guest_id'] ?? 0);
                    if ($gid > 0 && $conn) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE guest_id = ? LIMIT 1");
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->bind_param('i', $gid);
                            $stmt->execute();
                            $row = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            $guestUserId = (int)($row['id'] ?? 0);
                        }
                    }
                } catch (Throwable $e) {
                }

                if ($guestUserId > 0) {
                    $notifRepo = new NotificationRepository($conn);
                    $ref = (string)($prefillReservation['reference_no'] ?? '');
                    $title = 'Reservation confirmed';
                    $msg = $ref !== '' ? ('Your reservation ' . $ref . ' has been confirmed.') : 'Your reservation has been confirmed.';
                    $url = '/PHP/guest/reservations.php';
                    $notifRepo->createForUser($guestUserId, $title, $msg, $url);
                }

                Flash::set('success', 'Reservation confirmed. Receipt generated.');
                Response::redirect('front_desk_receipt.php?id=' . (int)$prefillReservation['id']);
            }
        } else {
            $errors['general'] = 'Please select a Pending online reservation to confirm.';
        }
    }

    $filters['checkin_date'] = $data['checkin_date'];
    $filters['checkout_date'] = $data['checkout_date'];
    $nights = 0;
    if ($filters['checkin_date'] !== '' && $filters['checkout_date'] !== '') {
        $n1 = strtotime($filters['checkin_date']);
        $n2 = strtotime($filters['checkout_date']);
        if ($n1 !== false && $n2 !== false && $n2 > $n1) {
            $nights = (int)round(($n2 - $n1) / 86400);
        }
    }
    if ($lockRoomSelection && $prefillReservation) {
        $roomId = (int)($prefillReservation['room_id'] ?? 0);
        $locked = $roomRepo->findById($roomId);
        if (!$locked) {
            $locked = (new ReservationRepository($conn))->findRoomById($roomId);
        }
        $availableRooms = [];
        if (is_array($locked) && (int)($locked['id'] ?? 0) > 0) {
            $availableRooms[] = $locked;
        }
    }
}

$selectedRoom = null;
foreach ($availableRooms as $r) {
    if ((int)($data['room_id'] ?? 0) === (int)($r['id'] ?? 0)) {
        $selectedRoom = $r;
        break;
    }
}

$ratePerNight = 0.0;
if ($selectedRoom) {
    $ratePerNight = (float)($selectedRoom['base_rate'] ?? 0);
}
if (isset($data['rate']) && is_numeric($data['rate'])) {
    $ratePerNight = (float)$data['rate'];
}

$staySubtotal = $nights * $ratePerNight;
$depositPreview = is_numeric((string)($data['deposit_amount'] ?? '')) ? (float)$data['deposit_amount'] : 0.0;
$balancePreview = max(0, $staySubtotal - $depositPreview);

$APP_BASE_URL = App::baseUrl();

$pageTitle = 'Front Desk - Hotel Management System';

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Front Desk & Reception</h1>
            <p class="text-sm text-gray-500 mt-1">Create and confirm reservations (staff only)</p>
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Online Reservations</h3>
                <div class="text-xs text-gray-500 mb-4">Pending bookings requested by guests (Website). Click a guest to auto-fill and confirm.</div>

                <?php if (empty($pendingOnlineReservations)): ?>
                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm text-gray-600">
                        No pending online reservations.
                    </div>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 520px; overflow:auto;">
                        <?php foreach ($pendingOnlineReservations as $pr): ?>
                            <?php
                                $name = trim((string)($pr['first_name'] ?? '') . ' ' . (string)($pr['last_name'] ?? ''));
                                $roomLabel = trim(((string)($pr['room_no'] ?? '')) !== '' ? ('Room ' . (string)$pr['room_no'] . ' • ') : '') . (string)($pr['room_type_name'] ?? '');
                            ?>
                            <a href="front_desk.php?reservation_id=<?= (int)($pr['id'] ?? 0) ?>" class="block rounded-xl border border-gray-100 hover:border-gray-900 transition p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($name !== '' ? $name : ('Guest #' . (int)($pr['guest_id'] ?? 0))) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($pr['phone'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($pr['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($pr['checkout_date'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($roomLabel) ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-500">Ref</div>
                                        <div class="text-xs font-semibold text-gray-900 mt-1"><?= htmlspecialchars((string)($pr['reference_no'] ?? '')) ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4">
                    <a href="<?= htmlspecialchars(App::baseUrl()) ?>/PHP/modules/guests/create.php" class="block w-full text-center px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Create Guest</a>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Create Reservation (1 room)</h3>
                    <div class="text-xs text-gray-500">Deposit required for confirmation</div>
                </div>

                <?php if ($systemReservationsOnly && !$prefillReservation): ?>
                    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        Select a <span class="font-medium">Pending</span> online reservation from the list to auto-fill details and confirm payment.
                    </div>
                <?php endif; ?>

                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Guest</label>
                        <select id="guest_id" name="guest_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" <?= $prefillReservation ? 'disabled' : '' ?>>
                            <option value="0">Select Guest</option>
                            <?php foreach ($guests as $g): ?>
                                <?php $label = trim(($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? '')); ?>
                                <?php
                                    $pendingRid = 0;
                                    foreach ($pendingOnlineReservations as $pr) {
                                        if ((int)($pr['guest_id'] ?? 0) === (int)($g['id'] ?? 0)) {
                                            $pendingRid = (int)($pr['id'] ?? 0);
                                            break;
                                        }
                                    }
                                ?>
                                <option value="<?= (int)$g['id'] ?>" data-pending-reservation-id="<?= (int)$pendingRid ?>" <?= (int)$data['guest_id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label . ' • ' . ($g['phone'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($prefillReservation): ?>
                            <input type="hidden" name="guest_id" value="<?= (int)($data['guest_id'] ?? 0) ?>" />
                        <?php endif; ?>
                        <div id="noPendingReservationNotice" class="hidden text-xs text-amber-700 mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                            This guest has no Pending online reservation. Please select a guest from the Online Reservations list.
                        </div>
                        <?php if (isset($errors['guest_id'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['guest_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
                        <select name="source" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach (ReservationService::allowedSources() as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>" <?= $data['source'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['source'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['source']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                        <select name="payment_method" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach (ReservationService::allowedPaymentMethods() as $pm): ?>
                                <option value="<?= htmlspecialchars($pm) ?>" <?= $data['payment_method'] === $pm ? 'selected' : '' ?>><?= htmlspecialchars($pm) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['payment_method'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['payment_method']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-in</label>
                        <input id="res_checkin" type="date" name="checkin_date" min="2000-01-01" max="2100-12-31" value="<?= htmlspecialchars($data['checkin_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['checkin_date'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['checkin_date']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out</label>
                        <input id="res_checkout" type="date" name="checkout_date" min="2000-01-01" max="2100-12-31" value="<?= htmlspecialchars($data['checkout_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['checkout_date'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['checkout_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Available Room</label>
                        <select id="room_id" name="room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" <?= $lockRoomSelection ? 'disabled' : '' ?>>
                            <option value="0">Select Available Room</option>
                            <?php foreach ($availableRooms as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" data-rate="<?= htmlspecialchars((string)($r['base_rate'] ?? 0)) ?>" <?= (int)$data['room_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars('Room ' . $r['room_no'] . ' — ' . $r['room_type_name'] . ' (₱' . number_format((float)$r['base_rate'], 2) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($lockRoomSelection): ?>
                            <input type="hidden" name="room_id" value="<?= (int)($data['room_id'] ?? 0) ?>" />
                        <?php endif; ?>
                        <?php if ($lockRoomSelection): ?>
                            <div class="text-xs text-gray-500 mt-2">
                                This reservation was created online. Room selection is locked to the guest-selected room.
                            </div>
                        <?php endif; ?>
                        <?php if (empty($availableRooms)): ?>
                            <div class="text-xs text-gray-500 mt-2">
                                No rooms to show. Select a date range to check availability, or ensure you have rooms in <span class="font-medium">Vacant</span> status.
                            </div>
                        <?php elseif ($filters['checkin_date'] === '' || $filters['checkout_date'] === ''): ?>
                            <div class="text-xs text-gray-500 mt-2">
                                Showing <span class="font-medium">Vacant</span> rooms for walk-ins. Select a date range to check availability for future stays.
                            </div>
                        <?php endif; ?>
                        <?php if (isset($errors['room_id'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['room_id']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($availableRooms)): ?>
                            <div class="mt-3">
                                <div class="text-xs text-gray-500 mb-2">Quick select</div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <?php foreach ($availableRooms as $r): ?>
                                        <?php
                                            $img = '';
                                            if (trim((string)($r['room_image_path'] ?? '')) !== '') {
                                                $img = (string)$r['room_image_path'];
                                            } elseif (trim((string)($r['room_type_image_path'] ?? '')) !== '') {
                                                $img = (string)$r['room_type_image_path'];
                                            }
                                            $isSel = (int)$data['room_id'] === (int)$r['id'];
                                        ?>
                                        <button
                                            type="button"
                                            class="text-left rounded-xl border <?= $isSel ? 'border-gray-900' : 'border-gray-200' ?> overflow-hidden hover:border-gray-900 transition"
                                            onclick="selectRoomCard(<?= (int)$r['id'] ?>)"
                                        >
                                            <div class="h-24 bg-gray-50 flex items-center justify-center">
                                                <?php if ($img !== ''): ?>
                                                    <img src="<?= htmlspecialchars($APP_BASE_URL . $img) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                                                <?php else: ?>
                                                    <div class="text-xs text-gray-400">No image</div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="p-3">
                                                <div class="text-sm font-medium text-gray-900">Room <?= htmlspecialchars($r['room_no'] ?? '') ?></div>
                                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($r['room_type_name'] ?? '') ?></div>
                                                <div class="text-xs text-gray-700 mt-2">₱<?= number_format((float)($r['base_rate'] ?? 0), 2) ?>/night</div>
                                            </div>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="md:col-span-2 rounded-xl border border-gray-100 p-4 bg-gray-50">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-xs text-gray-500">Payment Preview</div>
                                <div class="text-sm font-medium text-gray-900 mt-1">Stay total: ₱<span id="pv_stay_total"><?= number_format((float)$staySubtotal, 2) ?></span></div>
                                <div class="text-xs text-gray-500 mt-1">Deposit: ₱<span id="pv_deposit"><?= number_format((float)$depositPreview, 2) ?></span> • Balance: ₱<span id="pv_balance"><?= number_format((float)$balancePreview, 2) ?></span></div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">Nights</div>
                                <div class="text-sm font-medium text-gray-900"><span id="pv_nights"><?= (int)$nights ?></span></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                            <div class="rounded-lg border border-gray-100 bg-white p-3">
                                <div class="text-xs text-gray-500">Rate / Night</div>
                                <div class="text-sm font-medium text-gray-900 mt-1">₱<span id="pv_rate"><?= number_format((float)$ratePerNight, 2) ?></span></div>
                            </div>
                            <div class="rounded-lg border border-gray-100 bg-white p-3">
                                <div class="text-xs text-gray-500">Stay Total</div>
                                <div class="text-sm font-medium text-gray-900 mt-1">₱<span id="pv_stay_total_card"><?= number_format((float)$staySubtotal, 2) ?></span></div>
                            </div>
                            <div class="rounded-lg border border-gray-100 bg-white p-3">
                                <div class="text-xs text-gray-500">Balance</div>
                                <div class="text-sm font-medium text-gray-900 mt-1">₱<span id="pv_balance_card"><?= number_format((float)$balancePreview, 2) ?></span></div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate (optional override)</label>
                        <input id="rate_override" name="rate" value="<?= htmlspecialchars($data['rate']) ?>" placeholder="Leave blank to use base rate" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Deposit Amount</label>
                        <input id="deposit_amount" name="deposit_amount" value="<?= htmlspecialchars($data['deposit_amount']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['deposit_amount'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['deposit_amount']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Adults</label>
                        <input name="adults" type="number" min="1" value="<?= (int)$data['adults'] ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Children</label>
                        <input name="children" type="number" min="0" value="<?= (int)$data['children'] ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Promo Code (optional)</label>
                        <input name="promo_code" value="<?= htmlspecialchars((string)$data['promo_code']) ?>" placeholder="e.g., SUMMER10" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['promo_code'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['promo_code']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3"><?= htmlspecialchars($data['notes']) ?></textarea>
                    </div>

                    <div class="md:col-span-2 flex items-center gap-2 pt-2">
                        <button class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Confirm & Generate Receipt</button>
                        <a href="front_desk.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                    </div>
                </form>

                <script>
                    function selectRoomCard(roomId) {
                        const sel = document.getElementById('room_id');
                        if (!sel) return;
                        sel.value = String(roomId);
                        sel.dispatchEvent(new Event('change'));
                    }

                    (function () {
                        const guestSel = document.getElementById('guest_id');
                        if (!guestSel) return;
                        guestSel.addEventListener('change', function () {
                            const opt = guestSel.options[guestSel.selectedIndex];
                            if (!opt) return;
                            const rid = parseInt(opt.getAttribute('data-pending-reservation-id') || '0', 10);
                            const notice = document.getElementById('noPendingReservationNotice');
                            if (rid > 0) {
                                if (notice) notice.classList.add('hidden');
                                window.location.href = 'front_desk.php?reservation_id=' + encodeURIComponent(String(rid));
                            } else {
                                if (notice) notice.classList.remove('hidden');
                            }
                        });
                    })();

                    (function () {
                        const checkin = document.getElementById('res_checkin');
                        const checkout = document.getElementById('res_checkout');
                        const roomSel = document.getElementById('room_id');
                        const rateOverride = document.getElementById('rate_override');
                        const depositInput = document.getElementById('deposit_amount');

                        const pv = {
                            nights: document.getElementById('pv_nights'),
                            rate: document.getElementById('pv_rate'),
                            stayTotal: document.getElementById('pv_stay_total'),
                            stayTotalCard: document.getElementById('pv_stay_total_card'),
                            deposit: document.getElementById('pv_deposit'),
                            balance: document.getElementById('pv_balance'),
                            balanceCard: document.getElementById('pv_balance_card'),
                        };

                        function fmtMoney(n) {
                            const v = isFinite(n) ? n : 0;
                            return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }

                        function parseNum(val) {
                            const s = String(val ?? '').replace(/,/g, '').trim();
                            const n = parseFloat(s);
                            return isFinite(n) ? n : 0;
                        }

                        function normalizeDateValue(raw) {
                            const v = (raw || '').trim();
                            if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return '';
                            const y = parseInt(v.slice(0, 4), 10);
                            if (!isFinite(y) || y < 2000) return '';
                            return v;
                        }

                        function calcNights(ci, co) {
                            if (!ci || !co) return 0;
                            const d1 = new Date(ci + 'T00:00:00');
                            const d2 = new Date(co + 'T00:00:00');
                            if (!(d1 instanceof Date) || !(d2 instanceof Date)) return 0;
                            const ms = d2.getTime() - d1.getTime();
                            if (!isFinite(ms) || ms <= 0) return 0;
                            return Math.round(ms / 86400000);
                        }

                        function getBaseRate() {
                            if (!roomSel) return 0;
                            const opt = roomSel.options[roomSel.selectedIndex];
                            if (!opt) return 0;
                            return parseNum(opt.getAttribute('data-rate') || '0');
                        }

                        function updatePaymentPreviewOnly() {
                            const ci = normalizeDateValue(checkin?.value || '');
                            const co = normalizeDateValue(checkout?.value || '');
                            const nights = calcNights(ci, co);
                            const baseRate = getBaseRate();
                            const override = parseNum(rateOverride?.value || '');
                            const rate = override > 0 ? override : baseRate;
                            const stayTotal = nights * rate;
                            const deposit = parseNum(depositInput?.value || '');
                            const balance = Math.max(0, stayTotal - deposit);

                            if (pv.nights) pv.nights.textContent = String(nights);
                            if (pv.rate) pv.rate.textContent = fmtMoney(rate);
                            if (pv.stayTotal) pv.stayTotal.textContent = fmtMoney(stayTotal);
                            if (pv.stayTotalCard) pv.stayTotalCard.textContent = fmtMoney(stayTotal);
                            if (pv.deposit) pv.deposit.textContent = fmtMoney(deposit);
                            if (pv.balance) pv.balance.textContent = fmtMoney(balance);
                            if (pv.balanceCard) pv.balanceCard.textContent = fmtMoney(balance);
                        }

                        function updateAvailabilityUrl() {
                            if (!checkin || !checkout) return;
                            const ci = normalizeDateValue(checkin.value || '');
                            const co = normalizeDateValue(checkout.value || '');
                            if (ci === '' || co === '') return;

                            const params = new URLSearchParams(window.location.search);
                            params.set('checkin_date', ci);
                            params.set('checkout_date', co);

                            const currentRoomType = params.get('room_type_id');
                            if (!currentRoomType) {
                                params.set('room_type_id', '0');
                            }

                            window.location.href = window.location.pathname + '?' + params.toString();
                        }

                        checkin && checkin.addEventListener('change', updateAvailabilityUrl);
                        checkout && checkout.addEventListener('change', updateAvailabilityUrl);

                        checkin && checkin.addEventListener('input', updatePaymentPreviewOnly);
                        checkout && checkout.addEventListener('input', updatePaymentPreviewOnly);
                        roomSel && roomSel.addEventListener('change', updatePaymentPreviewOnly);
                        rateOverride && rateOverride.addEventListener('input', updatePaymentPreviewOnly);
                        depositInput && depositInput.addEventListener('input', updatePaymentPreviewOnly);

                        updatePaymentPreviewOnly();
                    })();
                </script>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
