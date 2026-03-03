<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$pendingApprovals = [];

$conn = Database::getConnection();
$service = new ReservationService(new ReservationRepository($conn));

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

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4 font-medium">Reference</th>
                            <th class="py-2 pr-4 font-medium">Guest</th>
                            <th class="py-2 pr-4 font-medium">Room</th>
                            <th class="py-2 pr-4 font-medium">Dates</th>
                            <th class="py-2 pr-4 font-medium">Deposit</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 pr-4 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-gray-500">No reservations found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($rows as $r): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($r['reference_no'] ?? '') ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($r['created_at'] ?? '') ?></div>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="text-gray-900"><?= htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($r['phone'] ?? '') ?></div>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="text-gray-900"><?= htmlspecialchars(($r['room_no'] ?? '-') !== '' ? ('Room ' . ($r['room_no'] ?? '-')) : '-') ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($r['room_type_name'] ?? '') ?></div>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="text-gray-900"><?= htmlspecialchars($r['checkin_date'] ?? '') ?> → <?= htmlspecialchars($r['checkout_date'] ?? '') ?></div>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="text-gray-900">₱<?= number_format((float)($r['deposit_amount'] ?? 0), 2) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($r['payment_method'] ?? '') ?></div>
                                </td>
                                <td class="py-3 pr-4">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border border-gray-200 bg-gray-50 text-gray-700">
                                        <?= htmlspecialchars($r['status'] ?? '') ?>
                                    </span>
                                </td>
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-2">
                                        <a href="reservations_view.php?id=<?= (int)$r['id'] ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">View</a>
                                        <a href="front_desk_receipt.php?id=<?= (int)$r['id'] ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Receipt</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
