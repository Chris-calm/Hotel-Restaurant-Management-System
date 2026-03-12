<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();
$pendingApprovals = [];

$guestId = (int)($_SESSION['guest_id'] ?? 0);
$rows = [];

if ($conn && $guestId > 0) {
    $repo = new ReservationRepository($conn);
    $rows = $repo->listReservationsByGuestId($guestId, 100);
}

$pageTitle = 'My Reservations - Guest Portal';
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="flex items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-light text-gray-900">My reservations</h1>
                <p class="text-sm text-gray-500 mt-1">Pending reservations need front desk confirmation. Print your deposit slip and bring it with ₱1,000 down payment.</p>
            </div>
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/rooms.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Browse rooms</a>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl border border-gray-100 p-6">
            <?php if (empty($rows)): ?>
                <div class="py-10 text-center text-gray-500 text-sm">No reservations found.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($rows as $r): ?>
                        <div class="rounded-xl border border-gray-100 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs text-gray-500">Reference</div>
                                    <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs text-gray-500">Status</div>
                                    <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <div class="text-xs text-gray-500">Stay</div>
                                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?= htmlspecialchars(trim(((string)($r['room_no'] ?? '')) !== '' ? ('Room ' . (string)$r['room_no'] . ' • ') : '') . (string)($r['room_type_name'] ?? '')) ?>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/deposit_slip.php?id=<?= (int)($r['id'] ?? 0) ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Deposit slip</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
