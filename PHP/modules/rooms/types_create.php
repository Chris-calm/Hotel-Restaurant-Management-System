<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$errors = [];
$data = [
    'code' => '',
    'name' => '',
    'base_rate' => '0.00',
    'max_adults' => 2,
    'max_children' => 0,
    'image_path' => '',
];

if (Request::isPost()) {
    $data['code'] = (string)Request::post('code', '');
    $data['name'] = (string)Request::post('name', '');
    $data['base_rate'] = (string)Request::post('base_rate', '0.00');
    $data['max_adults'] = (int)Request::post('max_adults', 2);
    $data['max_children'] = (int)Request::post('max_children', 0);
    $data['image_path'] = (string)Request::post('image_path', '');

    if (isset($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            $errors['image_path'] = 'Failed to upload image.';
        } else {
            $tmp = (string)($_FILES['image']['tmp_name'] ?? '');
            $orig = (string)($_FILES['image']['name'] ?? '');
            $size = (int)($_FILES['image']['size'] ?? 0);

            if ($size <= 0) {
                $errors['image_path'] = 'Invalid image file.';
            } elseif ($size > (8 * 1024 * 1024)) {
                $errors['image_path'] = 'Image must be 8MB or less.';
            } else {
                $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed, true)) {
                    $errors['image_path'] = 'Image must be JPG, PNG, or WEBP.';
                } else {
                    $root = dirname(__DIR__, 3);
                    $uploadDir = $root . '/uploads/rooms/types';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }

                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        $errors['image_path'] = 'Upload directory is not writable.';
                    } else {
                        $filename = 'room_type_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($tmp, $dest)) {
                            $errors['image_path'] = 'Failed to save uploaded image.';
                        } else {
                            $data['image_path'] = '/uploads/rooms/types/' . $filename;
                        }
                    }
                }
            }
        }
    }

    if (!$errors) {
        $id = $typeService->create($data, $errors);
    } else {
        $id = 0;
    }
    if ($id > 0) {
        Flash::set('success', 'Room type created successfully.');
        Response::redirect('types_view.php?id=' . $id);
    }
}

$pageTitle = 'New Room Type - Hotel Management System';
$pendingApprovals = [];

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">New Room Type</h1>
            <p class="text-sm text-gray-500 mt-1">Create a room type</p>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                    <input name="code" value="<?= htmlspecialchars($data['code']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['code'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['code']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input name="name" value="<?= htmlspecialchars($data['name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['name'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Base Rate</label>
                    <input name="base_rate" value="<?= htmlspecialchars((string)$data['base_rate']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['base_rate'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['base_rate']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Adults</label>
                    <input name="max_adults" type="number" min="1" value="<?= (int)$data['max_adults'] ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['max_adults'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['max_adults']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Children</label>
                    <input name="max_children" type="number" min="0" value="<?= (int)$data['max_children'] ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['max_children'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['max_children']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                    <input name="image_path" value="<?= htmlspecialchars((string)$data['image_path']) ?>" placeholder="e.g. /uploads/rooms/types/deluxe.webp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['image_path'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['image_path']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                    <input type="file" name="image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div class="md:col-span-2 flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create</button>
                    <a href="types.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                </div>
            </form>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
