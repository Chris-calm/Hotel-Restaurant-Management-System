<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$conn = Database::getConnection();
$guestId = (int)($_SESSION['guest_id'] ?? 0);

$recent = [];
$loyaltyPoints = null;
$loyaltyTier = null;

if ($conn && $guestId > 0) {
    $repo = new ReservationRepository($conn);
    $recent = $repo->listReservationsByGuestId($guestId, 10);

    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME IN ('loyalty_points','loyalty_tier')");
            $has = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
            if ($has === 2) {
                $stmt = $conn->prepare('SELECT loyalty_points, loyalty_tier FROM guests WHERE id = ? LIMIT 1');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $guestId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($row) {
                        $loyaltyPoints = (int)($row['loyalty_points'] ?? 0);
                        $loyaltyTier = (string)($row['loyalty_tier'] ?? '');
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }
}

$pageTitle = 'Guest Portal - Hotel Management System';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Guest Portal</h1>
            <p class="text-sm text-gray-500 mt-1">Browse rooms, request a booking, and print your ₱1,000 deposit slip for front desk confirmation.</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Quick actions</h3>
                <div class="space-y-3">
                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/rooms.php" class="block w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition text-center">Browse available rooms</a>
                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/reservations.php" class="block w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition text-center">View my reservations</a>
                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/settings.php" class="block w-full px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition text-center">Account settings</a>
                </div>

                <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <div class="text-xs text-gray-500">Deposit policy</div>
                    <div class="text-sm text-gray-900 font-medium mt-1">₱1,000 down payment</div>
                    <div class="text-xs text-gray-600 mt-2">After requesting a booking, print the deposit slip and bring it to the front desk. The staff will confirm your reservation after payment.</div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-xl border border-gray-100 p-6">
                        <div class="text-xs text-gray-500">Loyalty points</div>
                        <div class="text-2xl font-semibold text-gray-900 mt-2"><?= $loyaltyPoints !== null ? (int)$loyaltyPoints : 0 ?></div>
                        <div class="text-xs text-gray-500 mt-2">Tier: <span class="font-medium text-gray-900"><?= htmlspecialchars($loyaltyTier !== null && trim($loyaltyTier) !== '' ? $loyaltyTier : 'None') ?></span></div>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-6">
                        <div class="text-xs text-gray-500">Account</div>
                        <div class="text-sm font-medium text-gray-900 mt-2"><?= htmlspecialchars((string)($_SESSION['username'] ?? '')) ?></div>
                        <div class="text-xs text-gray-500 mt-1">Role: Guest</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-6">
                    <div class="flex items-center justify-between gap-4 mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Recent reservations</h3>
                        <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/reservations.php" class="text-sm text-blue-600 hover:underline">View all</a>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="py-8 text-center text-gray-500 text-sm">No reservations yet. Browse rooms to request a booking.</div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent as $r): ?>
                                <div class="rounded-lg border border-gray-100 p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="text-xs text-gray-500">Reference</div>
                                            <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?= htmlspecialchars(trim(((string)($r['room_no'] ?? '')) !== '' ? ('Room ' . (string)$r['room_no'] . ' • ') : '') . (string)($r['room_type_name'] ?? '')) ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-xs text-gray-500">Status</div>
                                            <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></div>
                                            <div class="mt-3 flex items-center gap-2 justify-end">
                                                <a class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/deposit_slip.php?id=<?= (int)($r['id'] ?? 0) ?>">Deposit slip</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
