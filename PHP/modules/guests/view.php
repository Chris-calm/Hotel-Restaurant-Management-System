<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../domain/Guests/GuestService.php';

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

        <div class="bg-white rounded-lg border border-gray-100 p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
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
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">ID Photo Path</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['id_photo_path'] ?? '') ?></div>
            </div>
            <?php if (trim((string)($guest['id_photo_path'] ?? '')) !== ''): ?>
                <div class="md:col-span-2">
                    <div class="text-xs text-gray-500 uppercase tracking-wider">ID Photo Preview</div>
                    <div class="mt-2">
                        <a href="<?= htmlspecialchars($APP_BASE_URL . (string)$guest['id_photo_path']) ?>" target="_blank" class="text-blue-600 hover:underline text-sm">Open ID photo</a>
                    </div>
                    <div class="mt-2">
                        <img src="<?= htmlspecialchars($APP_BASE_URL . (string)$guest['id_photo_path']) ?>" alt="ID Photo" style="max-height:220px;" />
                    </div>
                </div>
            <?php endif; ?>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Status</div>
                <div class="text-sm text-gray-900 mt-1"><?= htmlspecialchars($guest['status'] ?? '') ?></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 uppercase tracking-wider">Created At</div>
                <div class="text-sm text-gray-900 mt-1"><?= !empty($guest['created_at']) ? htmlspecialchars($guest['created_at']) : '' ?></div>
            </div>
        </div>

        <div class="mt-6">
            <a href="index.php" class="text-blue-600 hover:underline">Back to Guests</a>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
