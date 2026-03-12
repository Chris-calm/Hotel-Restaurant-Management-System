<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$conn = Database::getConnection();
$roomRepo = new RoomRepository($conn);
$maintenanceService = new MaintenanceService(new MaintenanceRepository($conn), $roomRepo);
$service = new ReservationService(
    new ReservationRepository($conn),
    new HousekeepingRepository($conn),
    $roomRepo,
    $maintenanceService
);

$id = Request::int('get', 'id', 0);
if ($id <= 0) {
    Flash::set('error', 'Invalid reservation id.');
    Response::redirect('reservations.php');
}

$errors = [];
if (Request::isPost()) {
    $action = (string)Request::post('action', '');
    $depositAmount = (string)Request::post('deposit_amount', '');
    $paymentMethod = (string)Request::post('payment_method', '');

    $payload = [];
    if (is_numeric($depositAmount)) {
        $payload['deposit_amount'] = (float)$depositAmount;
    }
    if ($paymentMethod !== '') {
        $payload['payment_method'] = $paymentMethod;
    }

    $ok = $service->updateStatus($id, $action, $payload, $errors);
    if ($ok) {
        Flash::set('success', 'Reservation updated successfully.');
        Response::redirect('reservations_view.php?id=' . $id);
    }
}

$reservation = $service->getReservationDetails($id);
if (!$reservation) {
    Flash::set('error', 'Reservation not found.');
    Response::redirect('reservations.php');
}

$APP_BASE_URL = App::baseUrl();

$pageTitle = 'Reservation Details - Hotel Management System';
$pendingApprovals = [];

$checkin = $reservation['checkin_date'] ?? '';
$checkout = $reservation['checkout_date'] ?? '';
$nights = 0;
if ($checkin !== '' && $checkout !== '') {
    $n1 = strtotime($checkin);
    $n2 = strtotime($checkout);
    if ($n1 !== false && $n2 !== false && $n2 > $n1) {
        $nights = (int)round(($n2 - $n1) / 86400);
    }
}

$rate = (float)($reservation['rate'] ?? 0);
$subtotal = $nights * $rate;
$deposit = (float)($reservation['deposit_amount'] ?? 0);
$balance = max(0, $subtotal - $deposit);

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-light text-gray-900">Reservation Details</h1>
                <p class="text-sm text-gray-500 mt-1">Manage status, confirm deposit, check-in/out</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="reservations.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back</a>
                <a href="front_desk_receipt.php?id=<?= (int)$reservation['id'] ?>" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Receipt</a>
            </div>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="font-medium mb-1">Action failed</div>
                <?php foreach ($errors as $msg): ?>
                    <div><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <div class="flex items-center justify-between gap-4 mb-4">
                <div>
                    <div class="text-xs text-gray-500">Reference No</div>
                    <div class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($reservation['reference_no'] ?? '') ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Status</div>
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($reservation['status'] ?? '') ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Guest</div>
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars(trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? ''))) ?></div>
                    <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($reservation['phone'] ?? '') ?></div>
                    <div class="text-sm text-gray-600"><?= htmlspecialchars($reservation['email'] ?? '') ?></div>
                    <?php if (trim((string)($reservation['id_type'] ?? '')) !== '' || trim((string)($reservation['id_number'] ?? '')) !== ''): ?>
                        <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars(trim((string)($reservation['id_type'] ?? '') . ' ' . (string)($reservation['id_number'] ?? ''))) ?></div>
                    <?php endif; ?>
                    <?php if (trim((string)($reservation['id_photo_path'] ?? '')) !== ''): ?>
                        <div class="text-xs text-gray-500 mt-1">
                            <a class="text-blue-600 hover:underline" target="_blank" href="<?= htmlspecialchars($APP_BASE_URL . (string)$reservation['id_photo_path']) ?>">Open ID photo</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Room</div>
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars('Room ' . ($reservation['room_no'] ?? '-')) ?></div>
                    <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars(($reservation['room_type_name'] ?? '') . ' (' . ($reservation['room_type_code'] ?? '') . ')') ?></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Stay</div>
                    <div class="text-sm text-gray-700">Check-in: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkin) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Check-out: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkout) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Nights: <span class="font-medium text-gray-900"><?= (int)$nights ?></span></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Charges</div>
                    <div class="text-sm text-gray-700">Rate/Night: <span class="font-medium text-gray-900">₱<?= number_format($rate, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Subtotal: <span class="font-medium text-gray-900">₱<?= number_format($subtotal, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Deposit: <span class="font-medium text-gray-900">₱<?= number_format($deposit, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Balance: <span class="font-medium text-gray-900">₱<?= number_format($balance, 2) ?></span></div>
                </div>
            </div>

            <div class="mt-6">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Actions</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-lg border border-gray-100 p-4">
                        <div class="text-xs text-gray-500 mb-2">Confirm (requires deposit)</div>
                        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <input type="hidden" name="action" value="Confirmed" />
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Deposit Amount</label>
                                <input name="deposit_amount" value="<?= htmlspecialchars((string)$deposit) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                                <select name="payment_method" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                    <option value="">(keep current)</option>
                                    <?php foreach (ReservationService::allowedPaymentMethods() as $pm): ?>
                                        <option value="<?= htmlspecialchars($pm) ?>" <?= ($reservation['payment_method'] ?? '') === $pm ? 'selected' : '' ?>><?= htmlspecialchars($pm) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Confirm Reservation</button>
                            </div>
                        </form>
                    </div>

                    <div class="rounded-lg border border-gray-100 p-4">
                        <div class="text-xs text-gray-500 mb-2">Status Updates</div>
                        <div class="grid grid-cols-1 gap-2">
                            <form method="post">
                                <input type="hidden" name="action" value="Checked In" />
                                <button class="w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Mark as Checked In</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="action" value="Completed" />
                                <button class="w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Mark as Completed (Check-out)</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="action" value="No Show" />
                                <button class="w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Mark as No Show</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="action" value="Cancelled" />
                                <button class="w-full px-4 py-2 rounded-lg border border-red-200 text-red-700 text-sm hover:bg-red-50 transition">Cancel Reservation</button>
                            </form>
                        </div>
                        <div class="text-xs text-gray-500 mt-2">Buttons will only work if the transition is allowed for the current status.</div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
