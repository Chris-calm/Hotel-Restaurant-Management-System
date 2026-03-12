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
    'id_type' => (string)($guest['id_type'] ?? ''),
    'id_number' => (string)($guest['id_number'] ?? ''),
    'id_photo_path' => (string)($guest['id_photo_path'] ?? ''),
    'status' => (string)($guest['status'] ?? 'Lead'),
];

if (Request::isPost()) {
    $data['first_name'] = (string)Request::post('first_name', '');
    $data['last_name'] = (string)Request::post('last_name', '');
    $data['email'] = (string)Request::post('email', '');
    $data['phone'] = (string)Request::post('phone', '');
    $data['id_type'] = (string)Request::post('id_type', '');
    $data['id_number'] = (string)Request::post('id_number', '');
    $data['id_photo_path'] = (string)Request::post('id_photo_path', '');
    $data['status'] = (string)Request::post('status', 'Lead');

    if (isset($_FILES['id_photo']) && is_array($_FILES['id_photo']) && (int)($_FILES['id_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)($_FILES['id_photo']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            $errors['id_photo_path'] = 'Failed to upload ID photo.';
        } else {
            $tmp = (string)($_FILES['id_photo']['tmp_name'] ?? '');
            $orig = (string)($_FILES['id_photo']['name'] ?? '');
            $size = (int)($_FILES['id_photo']['size'] ?? 0);

            if ($size <= 0) {
                $errors['id_photo_path'] = 'Invalid ID photo file.';
            } elseif ($size > (5 * 1024 * 1024)) {
                $errors['id_photo_path'] = 'ID photo must be 5MB or less.';
            } else {
                $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed, true)) {
                    $errors['id_photo_path'] = 'ID photo must be JPG, PNG, or WEBP.';
                } else {
                    $root = dirname(__DIR__, 3);
                    $uploadDir = $root . '/uploads/ids';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }

                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        $errors['id_photo_path'] = 'Upload directory is not writable.';
                    } else {
                        $filename = 'guest_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($tmp, $dest)) {
                            $errors['id_photo_path'] = 'Failed to save uploaded ID photo.';
                        } else {
                            $data['id_photo_path'] = '/uploads/ids/' . $filename;
                        }
                    }
                }
            }
        }
    }

    $ok = false;
    if (!$errors) {
        $ok = $service->update($id, $data, $errors);
    }
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
            <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Type (optional)</label>
                    <input name="id_type" value="<?= htmlspecialchars($data['id_type']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['id_type'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['id_type']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Number (optional)</label>
                    <input name="id_number" value="<?= htmlspecialchars($data['id_number']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Photo Path (optional)</label>
                    <input name="id_photo_path" value="<?= htmlspecialchars($data['id_photo_path']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload ID Photo (optional)</label>
                    <input type="file" name="id_photo" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['id_photo_path'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['id_photo_path']) ?></div>
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
