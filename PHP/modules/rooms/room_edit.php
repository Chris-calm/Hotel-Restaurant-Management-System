<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomService.php';
require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$roomService = new RoomService(new RoomRepository($conn));
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$id = Request::int('get', 'id', 0);
$room = $roomService->get($id);
if (!$room) {
    Flash::set('error', 'Room not found.');
    Response::redirect('index.php');
}

$types = $typeService->list();

$errors = [];
$data = [
    'room_no' => (string)($room['room_no'] ?? ''),
    'room_type_id' => (int)($room['room_type_id'] ?? 0),
    'floor' => (string)($room['floor'] ?? ''),
    'image_path' => (string)($room['image_path'] ?? ''),
    'status' => (string)($room['status'] ?? 'Vacant'),
];

if (Request::isPost()) {
    $data['room_no'] = (string)Request::post('room_no', '');
    $data['room_type_id'] = (int)Request::post('room_type_id', 0);
    $data['floor'] = (string)Request::post('floor', '');
    $data['image_path'] = (string)Request::post('image_path', '');
    $data['status'] = (string)Request::post('status', 'Vacant');

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
                    $uploadDir = $root . '/uploads/rooms';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }

                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        $errors['image_path'] = 'Upload directory is not writable.';
                    } else {
                        $filename = 'room_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$data['room_no']) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($tmp, $dest)) {
                            $errors['image_path'] = 'Failed to save uploaded image.';
                        } else {
                            $data['image_path'] = '/uploads/rooms/' . $filename;
                        }
                    }
                }
            }
        }
    }

    $ok = false;
    if (!$errors) {
        $ok = $roomService->update($id, $data, $errors);
    }
    if ($ok) {
        Flash::set('success', 'Room updated successfully.');
        Response::redirect('room_view.php?id=' . $id);
    }
}

$pageTitle = 'Edit Room - Hotel Management System';
$pendingApprovals = [];

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Edit Room</h1>
            <p class="text-sm text-gray-500 mt-1">Update room details</p>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Room No</label>
                    <input name="room_no" value="<?= htmlspecialchars($data['room_no']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['room_no'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['room_no']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Room Type</label>
                    <select name="room_type_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$data['room_type_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(($t['code'] ?? '') . ' - ' . ($t['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['room_type_id'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['room_type_id']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Floor</label>
                    <input name="floor" value="<?= htmlspecialchars($data['floor']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                    <input name="image_path" value="<?= htmlspecialchars((string)$data['image_path']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['image_path'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['image_path']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                    <input type="file" name="image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <?php foreach (RoomService::allowedStatuses() as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $data['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['status']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2 flex items-center gap-2 pt-2">
                    <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save</button>
                    <a href="room_view.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                </div>
            </form>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
