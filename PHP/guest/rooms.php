<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';
require_once __DIR__ . '/../domain/Rooms/RoomTypeRepository.php';
require_once __DIR__ . '/../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$filters = [
    'checkin_date' => (string)Request::get('checkin_date', ''),
    'checkout_date' => (string)Request::get('checkout_date', ''),
    'room_type_id' => (int)Request::get('room_type_id', 0),
];

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

$filters['checkin_date'] = $sanitizeDate($filters['checkin_date']);
$filters['checkout_date'] = $sanitizeDate($filters['checkout_date']);

$roomTypes = [];
$availableRooms = [];

if ($conn) {
    $roomRepo = new RoomRepository($conn);
    $maintenanceService = new MaintenanceService(new MaintenanceRepository($conn), $roomRepo);
    $service = new ReservationService(new ReservationRepository($conn), new HousekeepingRepository($conn), $roomRepo, $maintenanceService);

    $rtRepo = new RoomTypeRepository($conn);
    $rtSvc = new RoomTypeService($rtRepo);
    $roomTypes = $rtSvc->list();

    $checkinValid = ($filters['checkin_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['checkin_date']) === 1);
    $checkoutValid = ($filters['checkout_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['checkout_date']) === 1);
    $dateRangeValid = false;
    if ($checkinValid && $checkoutValid) {
        $t1 = strtotime($filters['checkin_date']);
        $t2 = strtotime($filters['checkout_date']);
        $dateRangeValid = ($t1 !== false && $t2 !== false && $t2 > $t1);
    }

    if ($dateRangeValid) {
        $availableRooms = $service->findAvailableRooms($filters['checkin_date'], $filters['checkout_date'], (int)$filters['room_type_id']);
    }
}

$pageTitle = 'Browse Rooms - Guest Portal';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Browse available rooms</h1>
            <p class="text-sm text-gray-500 mt-1">Select dates to see rooms available for your stay. Booking requests are confirmed by the front desk after the ₱1,000 deposit.</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Search availability</h3>
            <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Check-in</label>
                    <input type="date" name="checkin_date" min="2000-01-01" max="2100-12-31" value="<?= htmlspecialchars($filters['checkin_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Check-out</label>
                    <input type="date" name="checkout_date" min="2000-01-01" max="2100-12-31" value="<?= htmlspecialchars($filters['checkout_date']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Room type (optional)</label>
                    <select name="room_type_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <option value="0">All types</option>
                        <?php foreach ($roomTypes as $rt): ?>
                            <option value="<?= (int)$rt['id'] ?>" <?= (int)$filters['room_type_id'] === (int)$rt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($rt['code'] ?? '') . ' - ' . (string)($rt['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Search</button>
                </div>
            </form>
            <div class="text-xs text-gray-500 mt-3">Tip: If you don’t pick a date range, we can’t calculate availability across reservations.</div>
        </div>

        <?php if ($filters['checkin_date'] === '' || $filters['checkout_date'] === ''): ?>
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                Please select your check-in and check-out dates to view available rooms.
            </div>
        <?php elseif (empty($availableRooms)): ?>
            <div class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-sm text-gray-500 text-center">
                No rooms available for the selected date range.
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <?php foreach ($availableRooms as $r): ?>
                    <?php
                        $img = '';
                        if (trim((string)($r['room_image_path'] ?? '')) !== '') {
                            $img = (string)$r['room_image_path'];
                        } elseif (trim((string)($r['room_type_image_path'] ?? '')) !== '') {
                            $img = (string)$r['room_type_image_path'];
                        }
                    ?>
                    <div class="rounded-xl border border-gray-100 bg-white overflow-hidden">
                        <div class="h-40 bg-gray-50 flex items-center justify-center">
                            <?php if ($img !== ''): ?>
                                <img src="<?= htmlspecialchars($APP_BASE_URL . $img) ?>" alt="Room" style="height:100%;width:100%;object-fit:cover;" />
                            <?php else: ?>
                                <div class="text-xs text-gray-400">No image</div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">Room <?= htmlspecialchars((string)($r['room_no'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['room_type_name'] ?? '')) ?></div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs text-gray-500">Rate</div>
                                    <div class="text-sm font-semibold text-gray-900">₱<?= number_format((float)($r['base_rate'] ?? 0), 2) ?></div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/book.php?room_id=<?= (int)($r['id'] ?? 0) ?>&checkin_date=<?= urlencode($filters['checkin_date']) ?>&checkout_date=<?= urlencode($filters['checkout_date']) ?>" class="block w-full text-center px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Request booking</a>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">Deposit slip: ₱1,000 (front desk confirmation required).</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
