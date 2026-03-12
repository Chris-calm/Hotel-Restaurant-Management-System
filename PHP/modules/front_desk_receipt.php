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
$discount = (float)($reservation['discount_amount'] ?? 0);
$discount = max(0, min($subtotal, $discount));
$subtotalAfterDiscount = max(0, $subtotal - $discount);
$deposit = (float)($reservation['deposit_amount'] ?? 0);
$balance = max(0, $subtotalAfterDiscount - $deposit);

$APP_BASE_URL = App::baseUrl();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="<?= htmlspecialchars($APP_BASE_URL) ?>/CSS/index.css" />
    <?php include __DIR__ . '/../partials/styles.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { size: A4 landscape; margin: 4mm; }
            html, body {
                width: 100% !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }
            #sidebar, nav, .no-print { display: none !important; }
            #content { margin-left: 0 !important; overflow: visible !important; height: auto !important; }
            main { padding: 0 !important; width: 100% !important; overflow: visible !important; height: auto !important; }
            .print-card {
                border: none !important;
                box-shadow: none !important;
                width: 100% !important;
                max-width: 260mm !important;
                margin: 0 auto !important;
                padding: 8px !important;
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }
            .print-card * { break-inside: avoid !important; page-break-inside: avoid !important; }
            .print-card .mb-6 { margin-bottom: 8px !important; }
            .print-card .mb-4 { margin-bottom: 8px !important; }
            .print-card .mt-6 { margin-top: 8px !important; }
            .print-card .mt-4 { margin-top: 8px !important; }
            .print-card .p-6 { padding: 8px !important; }
            .print-card .p-4 { padding: 8px !important; }
            .print-card .gap-4 { gap: 8px !important; }
            .print-card .text-xs { font-size: 0.65rem !important; line-height: 0.85rem !important; }
            .print-card .text-sm { font-size: 0.78rem !important; line-height: 1.05rem !important; }
            .print-card .text-lg { font-size: 0.92rem !important; line-height: 1.2rem !important; }
            .print-card .text-2xl { font-size: 1.05rem !important; line-height: 1.35rem !important; }
            .print-card { transform: scale(0.88); transform-origin: top center; }
            .print-card .mt-2 { margin-top: 4px !important; }
            .print-card div[style*="height:24px"] { height: 14px !important; }
            body { background: #fff !important; }
        }

        @media screen {
            body { background: #eeeeee; }
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

        <?php
            $status = (string)($reservation['status'] ?? '');
            $statusBadge = 'border-gray-200 bg-gray-50 text-gray-700';
            if ($status === 'Confirmed') {
                $statusBadge = 'border-green-200 bg-green-50 text-green-700';
            } elseif ($status === 'Pending') {
                $statusBadge = 'border-yellow-200 bg-yellow-50 text-yellow-800';
            } elseif ($status === 'Cancelled' || $status === 'No Show') {
                $statusBadge = 'border-red-200 bg-red-50 text-red-700';
            } elseif ($status === 'Checked In') {
                $statusBadge = 'border-blue-200 bg-blue-50 text-blue-700';
            }
        ?>

        <div class="max-w-3xl mx-auto bg-white rounded-2xl border border-gray-100 p-6 md:p-8 print-card">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <img src="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/H.png" alt="Logo" style="width:42px;height:42px;" />
                    <div>
                        <div class="text-sm font-semibold text-gray-900">Hotel Management System</div>
                        <div class="text-xs text-gray-500">Reservation Confirmation</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Reference No</div>
                    <div class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($reservation['reference_no'] ?? '') ?></div>
                    <div class="mt-2">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs border <?= htmlspecialchars($statusBadge) ?>"><?= htmlspecialchars($status) ?></span>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 mb-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Payment Summary</div>
                        <div class="text-lg font-semibold text-gray-900 mt-1">Balance: ₱<?= number_format($balance, 2) ?></div>
                        <div class="text-xs text-gray-500 mt-1">
                            Subtotal ₱<?= number_format($subtotal, 2) ?>
                            <?php if ($discount > 0): ?>
                                • Discount ₱<?= number_format($discount, 2) ?>
                            <?php endif; ?>
                            • Deposit ₱<?= number_format($deposit, 2) ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">Nights</div>
                        <div class="text-lg font-semibold text-gray-900 mt-1"><?= (int)$nights ?></div>
                        <div class="text-xs text-gray-500 mt-1">Rate ₱<?= number_format($rate, 2) ?>/night</div>
                    </div>
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
                    <?php if (trim((string)($reservation['promo_code'] ?? '')) !== ''): ?>
                        <div class="text-sm text-gray-700 mt-1">Promo: <span class="font-medium text-gray-900"><?= htmlspecialchars((string)$reservation['promo_code']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($discount > 0): ?>
                        <div class="text-sm text-gray-700 mt-1">Discount: <span class="font-medium text-gray-900">- ₱<?= number_format($discount, 2) ?></span></div>
                        <div class="text-sm text-gray-700 mt-1">Subtotal after discount: <span class="font-medium text-gray-900">₱<?= number_format($subtotalAfterDiscount, 2) ?></span></div>
                    <?php endif; ?>
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

            <div class="mt-6 pt-4 border-t border-gray-100">
                <div class="flex items-start justify-between gap-4">
                    <div class="text-xs text-gray-500">
                        Generated: <?= htmlspecialchars($reservation['created_at'] ?? '') ?>
                    </div>
                    <div class="text-xs text-gray-500 text-right">
                        Thank you
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <div class="text-xs text-gray-500">Guest Signature</div>
                        <div class="mt-2 border-b border-gray-200" style="height:24px;"></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Staff Signature</div>
                        <div class="mt-2 border-b border-gray-200" style="height:24px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php'; ?>
</body>
</html>
