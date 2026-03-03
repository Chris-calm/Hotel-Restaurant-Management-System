<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Guests/GuestService.php';
require_once __DIR__ . '/../domain/Rooms/RoomTypeService.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$conn = Database::getConnection();

$guestService = new GuestService(new GuestRepository($conn));
$roomTypeService = new RoomTypeService(new RoomTypeRepository($conn));
$reservationService = new ReservationService(new ReservationRepository($conn));

$pendingApprovals = [];

$filters = [
    'checkin_date' => (string)Request::get('checkin_date', ''),
    'checkout_date' => (string)Request::get('checkout_date', ''),
    'room_type_id' => (int)Request::get('room_type_id', 0),
    'guest_q' => (string)Request::get('guest_q', ''),
];

$guests = $reservationService->listGuests($filters['guest_q']);
$roomTypes = $roomTypeService->list();

$availableRooms = [];
if ($filters['checkin_date'] !== '' && $filters['checkout_date'] !== '') {
    $availableRooms = $reservationService->findAvailableRooms(
        $filters['checkin_date'],
        $filters['checkout_date'],
        $filters['room_type_id']
    );
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
    'deposit_amount' => '',
    'payment_method' => 'Cash',
    'notes' => '',
];

if (Request::isPost()) {
    $data['guest_id'] = Request::int('post', 'guest_id', 0);
    $data['source'] = (string)Request::post('source', 'Walk-in');
    $data['checkin_date'] = (string)Request::post('checkin_date', '');
    $data['checkout_date'] = (string)Request::post('checkout_date', '');
    $data['room_id'] = Request::int('post', 'room_id', 0);
    $data['rate'] = (string)Request::post('rate', '');
    $data['adults'] = (int)Request::post('adults', 1);
    $data['children'] = (int)Request::post('children', 0);
    $data['deposit_amount'] = (string)Request::post('deposit_amount', '');
    $data['payment_method'] = (string)Request::post('payment_method', 'Cash');
    $data['notes'] = (string)Request::post('notes', '');

    $createPayload = [
        'guest_id' => $data['guest_id'],
        'source' => $data['source'],
        'checkin_date' => $data['checkin_date'],
        'checkout_date' => $data['checkout_date'],
        'room_id' => $data['room_id'],
        'rate' => is_numeric($data['rate']) ? (float)$data['rate'] : null,
        'adults' => $data['adults'],
        'children' => $data['children'],
        'deposit_amount' => is_numeric($data['deposit_amount']) ? (float)$data['deposit_amount'] : 0,
        'payment_method' => $data['payment_method'],
        'notes' => $data['notes'],
    ];

    $reservationId = $reservationService->createConfirmedOneRoom($createPayload, $errors);
    if ($reservationId > 0) {
        Flash::set('success', 'Reservation confirmed. Receipt generated.');
        Response::redirect('front_desk_receipt.php?id=' . $reservationId);
    }

    $filters['checkin_date'] = $data['checkin_date'];
    $filters['checkout_date'] = $data['checkout_date'];
    $availableRooms = [];
    if ($filters['checkin_date'] !== '' && $filters['checkout_date'] !== '') {
        $availableRooms = $reservationService->findAvailableRooms(
            $filters['checkin_date'],
            $filters['checkout_date'],
            $filters['room_type_id']
        );
    }
}

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
                <h3 class="text-lg font-medium text-gray-900 mb-4">Search Availability</h3>

                <form method="get" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-in</label>
                        <input type="date" name="checkin_date" value="<?= htmlspecialchars($filters['checkin_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out</label>
                        <input type="date" name="checkout_date" value="<?= htmlspecialchars($filters['checkout_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room Type (optional)</label>
                        <select name="room_type_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">All Types</option>
                            <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?= (int)$rt['id'] ?>" <?= (int)$filters['room_type_id'] === (int)$rt['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rt['code'] . ' - ' . $rt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Guest Search (optional)</label>
                        <input name="guest_q" value="<?= htmlspecialchars($filters['guest_q']) ?>" placeholder="Name, phone, email" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Search</button>
                    <a href="<?= htmlspecialchars(App::baseUrl()) ?>/PHP/modules/guests/create.php" class="block w-full text-center px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Create Guest</a>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Create Reservation (1 room)</h3>
                    <div class="text-xs text-gray-500">Deposit required for confirmation</div>
                </div>

                <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Guest</label>
                        <select name="guest_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select Guest</option>
                            <?php foreach ($guests as $g): ?>
                                <?php $label = trim(($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? '')); ?>
                                <option value="<?= (int)$g['id'] ?>" <?= (int)$data['guest_id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label . ' • ' . ($g['phone'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                        <input type="date" name="checkin_date" value="<?= htmlspecialchars($data['checkin_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['checkin_date'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['checkin_date']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Check-out</label>
                        <input type="date" name="checkout_date" value="<?= htmlspecialchars($data['checkout_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['checkout_date'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['checkout_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Available Room</label>
                        <select name="room_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Select Available Room</option>
                            <?php foreach ($availableRooms as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= (int)$data['room_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars('Room ' . $r['room_no'] . ' • ' . $r['room_type_name'] . ' • ₱' . number_format((float)$r['base_rate'], 2)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['room_id'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['room_id']) ?></div>
                        <?php endif; ?>
                        <?php if ($filters['checkin_date'] === '' || $filters['checkout_date'] === ''): ?>
                            <div class="text-xs text-gray-500 mt-1">Use “Search Availability” to load available rooms for a date range.</div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate (optional override)</label>
                        <input name="rate" value="<?= htmlspecialchars($data['rate']) ?>" placeholder="Leave blank to use base rate" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Deposit Amount</label>
                        <input name="deposit_amount" value="<?= htmlspecialchars($data['deposit_amount']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3"><?= htmlspecialchars($data['notes']) ?></textarea>
                    </div>

                    <div class="md:col-span-2 flex items-center gap-2 pt-2">
                        <button class="px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Confirm & Generate Receipt</button>
                        <a href="front_desk.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
