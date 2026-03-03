<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomService.php';
require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$roomService = new RoomService(new RoomRepository($conn));
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$q = (string)Request::get('q', '');
$rooms = $roomService->list($q);
$types = $typeService->list();

$pageTitle = 'Rooms - Hotel Management System';
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
                    <h1 class="text-2xl font-light text-gray-900">Rooms</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage room master data and room types</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="room_create.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">New Room</a>
                    <a href="types.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Room Types</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-4 mb-6">
            <form method="get" class="flex items-center gap-3 flex-wrap">
                <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search room/type/status" class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full md:w-80" />
                <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Search</button>
                <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
            </form>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-1">Room Types</h3>
                <p class="text-sm text-gray-500">Total configured</p>
                <div class="text-2xl font-light text-gray-900 mt-2"><?= (int)count($types) ?></div>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-1">Rooms</h3>
                <p class="text-sm text-gray-500">In list (filtered)</p>
                <div class="text-2xl font-light text-gray-900 mt-2"><?= (int)count($rooms) ?></div>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-1">Quick Links</h3>
                <div class="mt-3 space-y-2 text-sm">
                    <a class="block text-blue-600 hover:underline" href="types.php">Manage room types</a>
                    <a class="block text-blue-600 hover:underline" href="room_create.php">Create new room</a>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Floor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($rooms)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-gray-500">No rooms found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rooms as $r): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($r['room_no'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars(($r['room_type_code'] ?? '') . ' - ' . ($r['room_type_name'] ?? '')) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($r['floor'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($r['status'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <a class="text-blue-600 hover:underline" href="room_view.php?id=<?= (int)$r['id'] ?>">View</a>
                                        <span class="text-gray-300 mx-2">|</span>
                                        <a class="text-gray-900 hover:underline" href="room_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
                                        <span class="text-gray-300 mx-2">|</span>
                                        <a class="text-red-600 hover:underline" href="room_delete.php?id=<?= (int)$r['id'] ?>">Delete</a>
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
