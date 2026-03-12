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

$errors = [];
if (Request::isPost() && (string)Request::post('action', '') === 'mark_vacant') {
    $currentStatus = (string)($room['status'] ?? '');
    if ($currentStatus !== 'Cleaning') {
        Flash::set('error', 'Room must be in Cleaning status to mark as Vacant.');
        Response::redirect('room_view.php?id=' . $id);
    }

    $payload = [
        'room_no' => (string)($room['room_no'] ?? ''),
        'room_type_id' => (int)($room['room_type_id'] ?? 0),
        'floor' => (string)($room['floor'] ?? ''),
        'image_path' => (string)($room['image_path'] ?? ''),
        'status' => 'Vacant',
    ];

    $ok = $roomService->update($id, $payload, $errors);
    if ($ok) {
        Flash::set('success', 'Room marked as Vacant.');
    } else {
        Flash::set('error', 'Failed to update room status.');
    }
    Response::redirect('room_view.php?id=' . $id);
}

$pageTitle = 'Room - Hotel Management System';
$pendingApprovals = [];
$flash = Flash::get();

$APP_BASE_URL = App::baseUrl();

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
                    <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back</a>
                    <?php if ((string)($room['status'] ?? '') === 'Cleaning'): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="mark_vacant" />
                            <button class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Mark as Vacant</button>
                        </form>
                    <?php endif; ?>
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

        <?php
            $status = (string)($room['status'] ?? '');
            $badge = 'border-gray-200 bg-gray-50 text-gray-700';
            if ($status === 'Vacant') {
                $badge = 'border-green-200 bg-green-50 text-green-700';
            } elseif ($status === 'Occupied') {
                $badge = 'border-blue-200 bg-blue-50 text-blue-700';
            } elseif ($status === 'Cleaning') {
                $badge = 'border-yellow-200 bg-yellow-50 text-yellow-800';
            } elseif ($status === 'Out of Order') {
                $badge = 'border-red-200 bg-red-50 text-red-700';
            }

            $imgPath = trim((string)($room['image_path'] ?? ''));
        ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1">
                <div class="rounded-2xl border border-gray-100 bg-gray-50 overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-white">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium text-gray-900">Room Photo</div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(($room['room_type_code'] ?? '') . ' - ' . ($room['room_type_name'] ?? '')) ?></div>
                            </div>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($status) ?></span>
                        </div>
                    </div>
                    <div class="h-64 flex items-center justify-center">
                        <?php if ($imgPath !== ''): ?>
                            <img src="<?= htmlspecialchars($APP_BASE_URL . $imgPath) ?>" alt="Room" style="height:100%;width:100%;object-fit:cover;" />
                        <?php else: ?>
                            <div class="text-xs text-gray-400">No image</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($imgPath !== ''): ?>
                        <div class="p-4 bg-white border-t border-gray-100">
                            <a class="text-blue-600 hover:underline text-sm" target="_blank" href="<?= htmlspecialchars($APP_BASE_URL . $imgPath) ?>">Open image</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="rounded-xl border border-gray-100 bg-white p-6">
                    <div class="flex items-start justify-between gap-4 mb-5">
                        <div>
                            <div class="text-xs text-gray-500">Room</div>
                            <div class="text-lg font-semibold text-gray-900 mt-1">Room <?= htmlspecialchars((string)($room['room_no'] ?? '')) ?></div>
                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(($room['room_type_code'] ?? '') . ' - ' . ($room['room_type_name'] ?? '')) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-500">Base Rate</div>
                            <div class="text-lg font-semibold text-gray-900 mt-1">₱<?= number_format((float)($room['base_rate'] ?? 0), 2) ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Floor</div>
                            <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars((string)($room['floor'] ?? '')) ?></div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Status</div>
                            <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($status) ?></div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Type</div>
                            <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars(($room['room_type_code'] ?? '') . ' - ' . ($room['room_type_name'] ?? '')) ?></div>
                        </div>
                        <div class="rounded-xl border border-gray-100 p-4">
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Room ID</div>
                            <div class="text-sm text-gray-900 mt-1"><?= (int)($room['id'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
