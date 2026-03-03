<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$id = Request::int('get', 'id', 0);
$type = $typeService->get($id);
if (!$type) {
    Flash::set('error', 'Room type not found.');
    Response::redirect('types.php');
}

$pageTitle = 'Room Type - Hotel Management System';
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
                    <h1 class="text-2xl font-light text-gray-900">Room Type <?= htmlspecialchars($type['code'] ?? '') ?></h1>
                    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($type['name'] ?? '') ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="types_edit.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Edit</a>
                    <a href="types_delete.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700 transition">Delete</a>
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
                <div class="text-xs text-gray-500 uppercase tracking-wider">Code</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($type['code'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Name</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($type['name'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Base Rate</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($type['base_rate'] ?? '0.00')) ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Max Adults</div>
                <div class="text-sm text-gray-900 mt-1"><?= (int)($type['max_adults'] ?? 0) ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Max Children</div>
                <div class="text-sm text-gray-900 mt-1"><?= (int)($type['max_children'] ?? 0) ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Created At</div>
                <div class="text-sm text-gray-900 mt-1"><?= !empty($type['created_at']) ? htmlspecialchars($type['created_at']) : '' ?></div>
            </div>
        </div>

        <div class="mt-6">
            <a href="types.php" class="text-blue-600 hover:underline">Back to Room Types</a>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
