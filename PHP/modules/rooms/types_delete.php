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

$errors = [];
if (Request::isPost()) {
    $ok = $typeService->delete($id);
    if ($ok) {
        Flash::set('success', 'Room type deleted successfully.');
        Response::redirect('types.php');
    }
    $errors['delete'] = 'Unable to delete room type. It may be linked to rooms/reservations.';
}

$pageTitle = 'Delete Room Type - Hotel Management System';
$pendingApprovals = [];

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Delete Room Type</h1>
            <p class="text-sm text-gray-500 mt-1">This action cannot be undone.</p>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <div class="mb-4 text-sm text-gray-700">
                Are you sure you want to delete room type:
                <span class="font-medium text-gray-900"><?= htmlspecialchars(($type['code'] ?? '') . ' - ' . ($type['name'] ?? '')) ?></span>?
            </div>

            <?php if (isset($errors['delete'])): ?>
                <div class="mb-4 text-sm text-red-600"><?= htmlspecialchars($errors['delete']) ?></div>
            <?php endif; ?>

            <form method="post" class="flex items-center gap-2">
                <button class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700 transition">Yes, delete</button>
                <a href="types_view.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
            </form>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
