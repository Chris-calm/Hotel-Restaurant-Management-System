<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$types = $typeService->list();

$pageTitle = 'Room Types - Hotel Management System';
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
                    <h1 class="text-2xl font-light text-gray-900">Room Types</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage room types and rates</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="types_create.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">New Room Type</a>
                    <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back to Rooms</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Base Rate</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Adults</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Children</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($types)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-500">No room types found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($types as $t): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($t['code'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($t['name'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars((string)($t['base_rate'] ?? '0.00')) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= (int)($t['max_adults'] ?? 0) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= (int)($t['max_children'] ?? 0) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <a class="text-blue-600 hover:underline" href="types_view.php?id=<?= (int)$t['id'] ?>">View</a>
                                        <span class="text-gray-300 mx-2">|</span>
                                        <a class="text-gray-900 hover:underline" href="types_edit.php?id=<?= (int)$t['id'] ?>">Edit</a>
                                        <span class="text-gray-300 mx-2">|</span>
                                        <a class="text-red-600 hover:underline" href="types_delete.php?id=<?= (int)$t['id'] ?>">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
