<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$conn = Database::getConnection();
$service = new ReservationService(new ReservationRepository($conn));

$id = Request::int('get', 'id', 0);
if ($id <= 0) {
    Flash::set('error', 'Invalid reservation id.');
    Response::redirect('front_desk.php');
}

$reservation = $service->getReservationDetails($id);
if (!$reservation) {
    Flash::set('error', 'Reservation not found.');
    Response::redirect('front_desk.php');
}

$pageTitle = 'Reservation Receipt - Hotel Management System';
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

$APP_BASE_URL = App::baseUrl();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($APP_BASE_URL) ?>/CSS/index.css" />
    <style>
        @media print {
            #sidebar, nav, .no-print { display: none !important; }
            #content { margin-left: 0 !important; }
            main { padding: 0 !important; }
            .print-card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="flex items-start justify-between gap-4 mb-6 no-print">
            <div>
                <h1 class="text-2xl font-light text-gray-900">Reservation Receipt</h1>
                <p class="text-sm text-gray-500 mt-1">Print this confirmation and provide it to the guest</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="front_desk.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back to Front Desk</a>
                <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Print</button>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6 print-card">
            <div class="flex items-center justify-between gap-4 mb-4">
                <div class="flex items-center gap-3">
                    <img src="<?= htmlspecialchars($APP_BASE_URL) ?>/PICTURES/hms-logo.svg" alt="Logo" style="width:42px;height:42px;" />
                    <div>
                        <div class="text-sm font-semibold text-gray-900">Hotel Management System</div>
                        <div class="text-xs text-gray-500">Reservation Confirmation</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Reference No</div>
                    <div class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($reservation['reference_no'] ?? '') ?></div>
                    <div class="text-xs text-gray-500 mt-1">Status: <?= htmlspecialchars($reservation['status'] ?? '') ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Guest</div>
                    <div class="text-sm font-medium text-gray-900">
                        <?= htmlspecialchars(trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? ''))) ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($reservation['phone'] ?? '') ?></div>
                    <div class="text-sm text-gray-600"><?= htmlspecialchars($reservation['email'] ?? '') ?></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Room</div>
                    <div class="text-sm font-medium text-gray-900">
                        <?= htmlspecialchars('Room ' . ($reservation['room_no'] ?? '')) ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars(($reservation['room_type_name'] ?? '') . ' (' . ($reservation['room_type_code'] ?? '') . ')') ?></div>
                    <div class="text-sm text-gray-600"><?= htmlspecialchars('Floor: ' . ($reservation['floor'] ?? '-')) ?></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Stay</div>
                    <div class="text-sm text-gray-700">Check-in: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkin) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Check-out: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkout) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Nights: <span class="font-medium text-gray-900"><?= (int)$nights ?></span></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Payment</div>
                    <div class="text-sm text-gray-700">Rate/Night: <span class="font-medium text-gray-900">₱<?= number_format($rate, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Subtotal: <span class="font-medium text-gray-900">₱<?= number_format($subtotal, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Deposit: <span class="font-medium text-gray-900">₱<?= number_format($deposit, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Balance: <span class="font-medium text-gray-900">₱<?= number_format($balance, 2) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Method: <span class="font-medium text-gray-900"><?= htmlspecialchars($reservation['payment_method'] ?? '') ?></span></div>
                </div>
            </div>

            <?php if (trim((string)($reservation['notes'] ?? '')) !== ''): ?>
                <div class="mt-4 rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Notes</div>
                    <div class="text-sm text-gray-700"><?= nl2br(htmlspecialchars($reservation['notes'])) ?></div>
                </div>
            <?php endif; ?>

            <div class="mt-6 text-xs text-gray-500">
                Generated: <?= htmlspecialchars($reservation['created_at'] ?? '') ?>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php'; ?>
</body>
</html>
