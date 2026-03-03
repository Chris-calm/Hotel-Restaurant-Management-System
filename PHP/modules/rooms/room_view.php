<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomService.php';

$conn = Database::getConnection();
$roomService = new RoomService(new RoomRepository($conn));

$id = Request::int('get', 'id', 0);
$room = $roomService->get($id);
if (!$room) {
    Flash::set('error', 'Room not found.');
    Response::redirect('index.php');
}

$pageTitle = 'Room - Hotel Management System';
$pendingApprovals = [];
$flash = Flash::get();

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-light text-gray-900">Room <?= htmlspecialchars($room['room_no'] ?? '') ?></h1>
                    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(($room['room_type_code'] ?? '') . ' - ' . ($room['room_type_name'] ?? '')) ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="room_edit.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Edit</a>
                    <a href="room_delete.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700 transition">Delete</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Room No</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($room['room_no'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Type</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars(($room['room_type_code'] ?? '') . ' - ' . ($room['room_type_name'] ?? '')) ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Floor</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($room['floor'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Status</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($room['status'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Base Rate</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($room['base_rate'] ?? '')) ?></div>
            </div>
        </div>

        <div class="mt-6">
            <a href="index.php" class="text-blue-600 hover:underline">Back to Rooms</a>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
