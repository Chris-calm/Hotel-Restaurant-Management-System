<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$pendingApprovals = [];

$conn = Database::getConnection();
$roomRepo = new RoomRepository($conn);
$maintenanceService = new MaintenanceService(new MaintenanceRepository($conn), $roomRepo);
$service = new ReservationService(
    new ReservationRepository($conn),
    new HousekeepingRepository($conn),
    $roomRepo,
    $maintenanceService
);

$APP_BASE_URL = App::baseUrl();

$filters = [
    'q' => (string)Request::get('q', ''),
    'status' => (string)Request::get('status', ''),
    'checkin_from' => (string)Request::get('checkin_from', ''),
    'checkin_to' => (string)Request::get('checkin_to', ''),
];

$rows = $service->listReservations($filters);

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Reservation & Booking</h1>
            <p class="text-sm text-gray-500 mt-1">Bookings, availability, rates, confirmations</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Search / Filters</h3>
            <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Reference no, guest name, phone" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <option value="">All</option>
                        <?php foreach (ReservationService::allowedStatuses() as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Check-in From</label>
                    <input type="date" name="checkin_from" value="<?= htmlspecialchars($filters['checkin_from']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Check-in To</label>
                    <input type="date" name="checkin_to" value="<?= htmlspecialchars($filters['checkin_to']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div class="md:col-span-4 flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Apply</button>
                    <a href="reservations.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                    <a href="front_desk.php" class="ml-auto px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">New Reservation (Front Desk)</a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <div class="flex items-center justify-between gap-4 mb-4">
                <h3 class="text-lg font-medium text-gray-900">Recent Reservations</h3>
                <div class="text-xs text-gray-500">Showing up to 200 records</div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="py-10 text-center text-gray-500 text-sm">No reservations found.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $status = (string)($r['status'] ?? '');
                            $badge = 'border-gray-200 bg-gray-50 text-gray-700';
                            if ($status === 'Confirmed' || $status === 'Upcoming') {
                                $badge = 'border-blue-200 bg-blue-50 text-blue-700';
                            } elseif ($status === 'Checked In') {
                                $badge = 'border-green-200 bg-green-50 text-green-700';
                            } elseif ($status === 'Completed') {
                                $badge = 'border-gray-200 bg-white text-gray-900';
                            } elseif ($status === 'Cancelled' || $status === 'No Show') {
                                $badge = 'border-red-200 bg-red-50 text-red-700';
                            }

                            $guestName = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                            $roomLabel = ($r['room_no'] ?? '-') !== '' ? ('Room ' . ($r['room_no'] ?? '-')) : '-';
                        ?>
                        <div class="rounded-xl border border-gray-100 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs text-gray-500">Reference</div>
                                    <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars($r['reference_no'] ?? '') ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($r['created_at'] ?? '') ?></div>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </div>

                            <div class="mt-4 grid grid-cols-1 gap-3">
                                <div>
                                    <div class="text-xs text-gray-500">Guest</div>
                                    <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guestName) ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['phone'] ?? '')) ?></div>
                                    <?php if (trim((string)($r['id_type'] ?? '')) !== '' || trim((string)($r['id_number'] ?? '')) !== ''): ?>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(trim((string)($r['id_type'] ?? '') . ' ' . (string)($r['id_number'] ?? ''))) ?></div>
                                    <?php endif; ?>
                                    <?php if (trim((string)($r['id_photo_path'] ?? '')) !== ''): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            <a class="text-blue-600 hover:underline" target="_blank" href="<?= htmlspecialchars($APP_BASE_URL . (string)$r['id_photo_path']) ?>">Open ID photo</a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <div class="text-xs text-gray-500">Room</div>
                                        <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($roomLabel) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['room_type_name'] ?? '')) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Dates</div>
                                        <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?></div>
                                        <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                        <div class="text-xs text-gray-500">Deposit</div>
                                        <div class="text-sm font-medium text-gray-900 mt-1">₱<?= number_format((float)($r['deposit_amount'] ?? 0), 2) ?></div>
                                    </div>
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                        <div class="text-xs text-gray-500">Payment</div>
                                        <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars((string)($r['payment_method'] ?? '')) ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <a href="reservations_view.php?id=<?= (int)$r['id'] ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">View</a>
                                <a href="front_desk_receipt.php?id=<?= (int)$r['id'] ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Receipt</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
