<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$guestId = (int)($_SESSION['guest_id'] ?? 0);
$id = Request::int('get', 'id', 0);

if ($id <= 0) {
    Flash::set('error', 'Invalid reservation id.');
    Response::redirect('reservations.php');
}

$service = null;
$reservation = null;

if ($conn) {
    $roomRepo = new RoomRepository($conn);
    $maintenanceService = new MaintenanceService(new MaintenanceRepository($conn), $roomRepo);
    $service = new ReservationService(
        new ReservationRepository($conn),
        new HousekeepingRepository($conn),
        $roomRepo,
        $maintenanceService,
        new NotificationRepository($conn)
    );
    $reservation = $service->getReservationDetails($id);
}

if (!$reservation) {
    Flash::set('error', 'Reservation not found.');
    Response::redirect('reservations.php');
}

if ((int)($reservation['guest_id'] ?? 0) !== $guestId) {
    Flash::set('error', 'Access denied.');
    Response::redirect('reservations.php');
}

$depositRequired = 1000.00;

$checkin = (string)($reservation['checkin_date'] ?? '');
$checkout = (string)($reservation['checkout_date'] ?? '');

$pageTitle = 'Deposit Slip - Guest Portal';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($APP_BASE_URL) ?>/CSS/index.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <?php include __DIR__ . '/../partials/styles.php'; ?>
    <style>
        @media print {
            #sidebar, nav, .no-print { display: none !important; }
            #content { margin-left: 0 !important; }
            main { padding: 0 !important; }
            .print-card { border: none !important; box-shadow: none !important; }
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
                <h1 class="text-2xl font-light text-gray-900">Deposit Slip</h1>
                <p class="text-sm text-gray-500 mt-1">Print this and bring it to the front desk with your ₱1,000 down payment.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="reservations.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back</a>
                <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Print</button>
            </div>
        </div>

        <div class="max-w-3xl mx-auto bg-white rounded-2xl border border-gray-100 p-6 md:p-8 print-card">
            <div class="flex items-start justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <img src="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/H.png" alt="Logo" style="width:42px;height:42px;object-fit:contain;" />
                    <div>
                        <div class="text-sm font-semibold text-gray-900">Hotel Ser Reposer Et Diner</div>
                        <div class="text-xs text-gray-500">Guest Deposit Slip</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Reference No</div>
                    <div class="text-lg font-semibold text-gray-900"><?= htmlspecialchars((string)($reservation['reference_no'] ?? '')) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Status: <span class="font-medium text-gray-900"><?= htmlspecialchars((string)($reservation['status'] ?? '')) ?></span></div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 mb-4">
                <div class="text-xs text-gray-500">Deposit amount required</div>
                <div class="text-2xl font-semibold text-gray-900 mt-1">₱<?= number_format($depositRequired, 2) ?></div>
                <div class="text-xs text-gray-600 mt-2">Present this slip and pay the deposit at the front desk. Staff will then confirm your reservation and issue the official receipt.</div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Guest</div>
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars(trim((string)($reservation['first_name'] ?? '') . ' ' . (string)($reservation['last_name'] ?? ''))) ?></div>
                    <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars((string)($reservation['phone'] ?? '')) ?></div>
                    <div class="text-sm text-gray-600"><?= htmlspecialchars((string)($reservation['email'] ?? '')) ?></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Room</div>
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars('Room ' . (string)($reservation['room_no'] ?? '')) ?></div>
                    <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars((string)($reservation['room_type_name'] ?? '')) ?></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Stay dates</div>
                    <div class="text-sm text-gray-700">Check-in: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkin) ?></span></div>
                    <div class="text-sm text-gray-700 mt-1">Check-out: <span class="font-medium text-gray-900"><?= htmlspecialchars($checkout) ?></span></div>
                </div>

                <div class="rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500 mb-2">Front desk processing</div>
                    <div class="text-sm text-gray-700">Upon deposit payment, staff will update status to <span class="font-medium text-gray-900">Confirmed</span>.</div>
                    <div class="text-sm text-gray-700 mt-1">Bring a valid ID and this slip.</div>
                </div>
            </div>

            <div class="mt-6 pt-4 border-t border-gray-100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Guest signature</div>
                        <div class="mt-2 border-b border-gray-200" style="height:24px;"></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Front desk signature</div>
                        <div class="mt-2 border-b border-gray-200" style="height:24px;"></div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 mt-4">Generated: <?= htmlspecialchars((string)($reservation['created_at'] ?? '')) ?></div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php'; ?>
</body>
</html>
