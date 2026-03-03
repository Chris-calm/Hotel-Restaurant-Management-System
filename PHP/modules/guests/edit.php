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

$errors = [];
$data = [
    'first_name' => (string)($guest['first_name'] ?? ''),
    'last_name' => (string)($guest['last_name'] ?? ''),
    'email' => (string)($guest['email'] ?? ''),
    'phone' => (string)($guest['phone'] ?? ''),
    'status' => (string)($guest['status'] ?? 'Lead'),
];

if (Request::isPost()) {
    $data['first_name'] = (string)Request::post('first_name', '');
    $data['last_name'] = (string)Request::post('last_name', '');
    $data['email'] = (string)Request::post('email', '');
    $data['phone'] = (string)Request::post('phone', '');
    $data['status'] = (string)Request::post('status', 'Lead');

    $ok = $service->update($id, $data, $errors);
    if ($ok) {
        Flash::set('success', 'Guest updated successfully.');
        Response::redirect('view.php?id=' . $id);
    }
}

$pageTitle = 'Edit Guest - Hotel Management System';
$pendingApprovals = [];

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Edit Guest</h1>
            <p class="text-sm text-gray-500 mt-1">Update guest profile</p>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                    <input name="first_name" value="<?= htmlspecialchars($data['first_name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['first_name'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['first_name']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                    <input name="last_name" value="<?= htmlspecialchars($data['last_name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['last_name'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['last_name']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input name="email" value="<?= htmlspecialchars($data['email']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['email'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input name="phone" value="<?= htmlspecialchars($data['phone']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['phone'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['phone']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <?php foreach (GuestService::allowedStatuses() as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $data['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['status']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2 flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save</button>
                    <a href="view.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                </div>
            </form>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
