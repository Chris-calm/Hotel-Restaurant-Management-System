<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';
require_once __DIR__ . '/../domain/Notifications/NotificationRepository.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$guestId = (int)($_SESSION['guest_id'] ?? 0);
if ($guestId <= 0) {
    Flash::set('error', 'Guest profile is not linked. Please contact front desk.');
    Response::redirect('index.php');
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

$roomId = Request::int('get', 'room_id', 0);
$checkin = $sanitizeDate((string)Request::get('checkin_date', ''));
$checkout = $sanitizeDate((string)Request::get('checkout_date', ''));

$errors = [];
$room = null;
$nights = 0;

$depositAmount = 1000.00;

if ($checkin !== '' && $checkout !== '') {
    $t1 = strtotime($checkin);
    $t2 = strtotime($checkout);
    if ($t1 !== false && $t2 !== false && $t2 > $t1) {
        $nights = (int)round(($t2 - $t1) / 86400);
    }
}

if ($conn && $roomId > 0) {
    $repo = new ReservationRepository($conn);
    $room = $repo->findRoomById($roomId);
}

if (!$conn) {
    $errors['general'] = 'Database unavailable.';
} elseif (!$room) {
    $errors['general'] = 'Room not found.';
} elseif ($checkin === '' || $checkout === '' || $nights <= 0) {
    $errors['general'] = 'Please provide a valid date range.';
}

if (Request::isPost() && empty($errors) && $conn && $room) {
    $adults = (int)Request::post('adults', 1);
    $children = (int)Request::post('children', 0);
    $notes = trim((string)Request::post('notes', ''));
    $source = trim((string)Request::post('source', 'Website'));
    $paymentMethod = trim((string)Request::post('payment_method', ''));

    $allowedSources = ['Walk-in', 'Phone', 'Website', 'OTA', 'Agent'];
    if (!in_array($source, $allowedSources, true)) {
        $source = 'Website';
    }

    $allowedPaymentMethods = ['GCash', 'Bank Transfer'];
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = '';
    }

    if ($adults <= 0) {
        $errors['adults'] = 'Adults must be at least 1.';
    }
    if ($children < 0) {
        $errors['children'] = 'Children cannot be negative.';
    }

    if ($paymentMethod === '') {
        $errors['payment_method'] = 'Please select a payment method for the deposit.';
    }

    if (empty($errors)) {
        $repo = new ReservationRepository($conn);
        $available = $repo->findAvailableRooms($checkin, $checkout, 0);
        $selected = null;
        foreach ($available as $r) {
            if ((int)($r['id'] ?? 0) === (int)$roomId) {
                $selected = $r;
                break;
            }
        }
        if (!$selected) {
            $errors['general'] = 'Selected room is no longer available for those dates.';
        } else {
            $referenceNo = 'WEB-' . date('Ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            $reservationId = $repo->createReservation([
                'reference_no' => $referenceNo,
                'guest_id' => $guestId,
                'source' => $source,
                'status' => 'Pending',
                'checkin_date' => $checkin,
                'checkout_date' => $checkout,
                'promo_code_id' => 0,
                'promo_code' => '',
                'discount_amount' => 0,
                'deposit_amount' => $depositAmount,
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ]);

            if ($reservationId <= 0) {
                $errors['general'] = 'Failed to create reservation.';
            } else {
                $okAttach = $repo->attachRoomToReservation($reservationId, [
                    'room_id' => $roomId,
                    'room_type_id' => (int)($selected['room_type_id'] ?? 0),
                    'rate' => (float)($selected['base_rate'] ?? 0),
                    'adults' => $adults,
                    'children' => $children,
                ]);

                if (!$okAttach) {
                    $errors['general'] = 'Reservation created but room assignment failed.';
                } else {
                    $notifRepo = new NotificationRepository($conn);
                    $guestName = '';
                    try {
                        $stmt = $conn->prepare("SELECT first_name, last_name FROM guests WHERE id = ? LIMIT 1");
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->bind_param('i', $guestId);
                            $stmt->execute();
                            $row = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            $guestName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                        }
                    } catch (Throwable $e) {
                    }

                    $title = 'New online reservation';
                    $msg = ($guestName !== '' ? ($guestName . ' ') : '') . 'requested a booking for Room ' . (string)($room['room_no'] ?? '') . '.';
                    $url = '/PHP/modules/front_desk.php?reservation_id=' . $reservationId;
                    $notifRepo->createForStaff($title, $msg, $url);

                    Flash::set('success', 'Booking request created. Print your deposit slip for front desk confirmation.');
                    Response::redirect('deposit_slip.php?id=' . $reservationId);
                }
            }
        }
    }
}

$pageTitle = 'Request Booking - Guest Portal';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Request booking</h1>
            <p class="text-sm text-gray-500 mt-1">This creates a <span class="font-medium">Pending</span> reservation. Bring the ₱1,000 deposit slip to the front desk to confirm your booking.</p>
        </div>

        <?php if (isset($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Selected room</h3>
                <?php if ($room): ?>
                    <div class="text-sm font-semibold text-gray-900">Room <?= htmlspecialchars((string)($room['room_no'] ?? '')) ?></div>
                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($room['room_type_name'] ?? '')) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Rate: ₱<?= number_format((float)($room['base_rate'] ?? 0), 2) ?>/night</div>
                    <div class="text-xs text-gray-500 mt-3">Dates: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkin) ?></span> → <span class="font-medium text-gray-900"><?= htmlspecialchars($checkout) ?></span></div>
                    <div class="text-xs text-gray-500 mt-1">Nights: <span class="font-medium text-gray-900"><?= (int)$nights ?></span></div>
                <?php else: ?>
                    <div class="text-sm text-gray-500">No room selected.</div>
                <?php endif; ?>

                <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <div class="text-xs text-gray-500">Deposit required</div>
                    <div class="text-lg font-semibold text-gray-900 mt-1">₱<?= number_format($depositAmount, 2) ?></div>
                    <div class="text-xs text-gray-600 mt-2">This is the down payment required by the front desk to confirm your reservation.</div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-2">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Guest details</h3>
                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Adults</label>
                        <input type="number" min="1" name="adults" value="<?= htmlspecialchars((string)Request::post('adults', '1')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['adults'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['adults']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Children</label>
                        <input type="number" min="0" name="children" value="<?= htmlspecialchars((string)Request::post('children', '0')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['children'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['children']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source</label>
                        <select name="source" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php $src = (string)Request::post('source', 'Website'); ?>
                            <?php foreach (['Walk-in','Phone','Website','OTA','Agent'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $src === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Deposit payment channel</label>
                        <select name="payment_method" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php $pm = (string)Request::post('payment_method', ''); ?>
                            <option value="" <?= $pm === '' ? 'selected' : '' ?>>Select payment method</option>
                            <?php foreach (['GCash','Bank Transfer'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $pm === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Payment is not processed online. This tells the front desk how you will pay the ₱1,000 deposit.</div>
                        <?php if (isset($errors['payment_method'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['payment_method']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Special requests (optional)</label>
                        <textarea name="notes" rows="4" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars((string)Request::post('notes', '')) ?></textarea>
                    </div>
                    <div class="md:col-span-2 flex items-center gap-2">
                        <button class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Submit booking request</button>
                        <a href="rooms.php?checkin_date=<?= urlencode($checkin) ?>&checkout_date=<?= urlencode($checkout) ?>" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
