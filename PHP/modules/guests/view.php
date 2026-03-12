<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../domain/Guests/GuestService.php';
require_once __DIR__ . '/../../domain/Reservations/ReservationRepository.php';

$conn = Database::getConnection();
$service = new GuestService(new GuestRepository($conn));

$id = Request::int('get', 'id', 0);
$guest = $service->get($id);
if (!$guest) {
    Flash::set('error', 'Guest not found.');
    Response::redirect('index.php');
}

$flash = Flash::get();
$pageTitle = 'Guest - Hotel Management System';
$pendingApprovals = [];

$APP_BASE_URL = App::baseUrl();

$stayHistory = [];
$resRepo = new ReservationRepository($conn);
if ($id > 0) {
    $stayHistory = $resRepo->listReservationsByGuestId($id, 30);
}

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-light text-gray-900">Guest Profile</h1>
                    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? '')) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="edit.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Edit</a>
                    <a href="delete.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700 transition">Delete</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <?php
            $status = (string)($guest['status'] ?? '');
            $badge = 'border-gray-200 bg-gray-50 text-gray-700';
            if ($status === 'Active') {
                $badge = 'border-green-200 bg-green-50 text-green-700';
            } elseif ($status === 'VIP') {
                $badge = 'border-yellow-200 bg-yellow-50 text-yellow-800';
            } elseif ($status === 'Blacklisted') {
                $badge = 'border-red-200 bg-red-50 text-red-700';
            }
        ?>

        <div class="bg-white rounded-xl border border-gray-100 p-6">
            <div class="flex items-start justify-between gap-4 mb-5">
                <div>
                    <div class="text-xs text-gray-500">Guest</div>
                    <div class="text-lg font-semibold text-gray-900 mt-1"><?= htmlspecialchars(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? '')) ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Status</div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs border mt-1 <?= htmlspecialchars($badge) ?>">
                        <?= htmlspecialchars($status) ?>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">First Name</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['first_name'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Last Name</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['last_name'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Email</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['email'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Phone</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['phone'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">ID Type</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['id_type'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">ID Number</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['id_number'] ?? '') ?></div>
            </div>
            <?php if (trim((string)($guest['id_photo_path'] ?? '')) !== ''): ?>
                <?php
                    $idPhotoPath = trim((string)($guest['id_photo_path'] ?? ''));
                    $idPhotoUrl = $idPhotoPath;
                    if (!preg_match('/^https?:\/\//i', $idPhotoPath)) {
                        if (substr($idPhotoPath, 0, 1) === '/') {
                            $idPhotoUrl = $APP_BASE_URL . $idPhotoPath;
                        } else {
                            $idPhotoUrl = $APP_BASE_URL . '/' . $idPhotoPath;
                        }
                    }
                ?>
                <div class="md:col-span-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">ID Photo</div>
                    <div class="mt-2">
                        <a href="<?= htmlspecialchars($idPhotoUrl) ?>" target="_blank" class="text-blue-600 hover:underline text-sm">Open ID photo</a>
                    </div>
                    <div class="mt-2 rounded-xl border border-gray-100 bg-gray-50 overflow-hidden">
                        <img src="<?= htmlspecialchars($idPhotoUrl) ?>" alt="ID Photo" style="max-height:260px;width:100%;object-fit:contain;" />
                    </div>
                </div>
            <?php endif; ?>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Loyalty Tier</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($guest['loyalty_tier'] ?? '')) ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Loyalty Points</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)((int)($guest['loyalty_points'] ?? 0))) ?></div>
            </div>
            <?php if (trim((string)($guest['preferences'] ?? '')) !== ''): ?>
                <div class="md:col-span-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Preferences</div>
                    <div class="text-sm text-gray-900 mt-1"><?= nl2br(htmlspecialchars((string)$guest['preferences'])) ?></div>
                </div>
            <?php endif; ?>
            <?php if (trim((string)($guest['notes'] ?? '')) !== ''): ?>
                <div class="md:col-span-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">Notes</div>
                    <div class="text-sm text-gray-900 mt-1"><?= nl2br(htmlspecialchars((string)$guest['notes'])) ?></div>
                </div>
            <?php endif; ?>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Created At</div>
                <div class="text-sm text-gray-900 mt-1"><?= !empty($guest['created_at']) ? htmlspecialchars($guest['created_at']) : '' ?></div>
            </div>
            </div>
        </div>

        <div class="mt-6 bg-white rounded-xl border border-gray-100 p-6">
            <div class="flex items-center justify-between gap-4 mb-4">
                <div>
                    <div class="text-lg font-medium text-gray-900">Stay History</div>
                    <div class="text-xs text-gray-500 mt-1">Recent reservations for this guest</div>
                </div>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/reservations.php?q=<?= urlencode((string)($guest['phone'] ?? '')) ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Open Reservation Book</a>
            </div>

            <?php if (empty($stayHistory)): ?>
                <div class="text-sm text-gray-500">No reservations found for this guest.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($stayHistory as $r): ?>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-xs text-gray-500">Reference</div>
                                    <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></div>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border border-gray-200 bg-gray-50 text-gray-700"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></span>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">Room: <?= htmlspecialchars(trim((string)($r['room_no'] ?? '')) !== '' ? ('Room ' . (string)$r['room_no']) : '-') ?><?= trim((string)($r['room_type_name'] ?? '')) !== '' ? (' • ' . (string)$r['room_type_name']) : '' ?></div>
                            <div class="mt-1 text-xs text-gray-500">Dates: <?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></div>
                            <div class="mt-3">
                                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/reservations_view.php?id=<?= (int)($r['id'] ?? 0) ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">View Reservation</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-6">
            <a href="index.php" class="text-blue-600 hover:underline">Back to Guests</a>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
