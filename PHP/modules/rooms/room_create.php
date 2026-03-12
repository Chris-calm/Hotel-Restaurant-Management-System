<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Rooms/RoomService.php';
require_once __DIR__ . '/../../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();
$roomService = new RoomService(new RoomRepository($conn));
$typeService = new RoomTypeService(new RoomTypeRepository($conn));

$types = $typeService->list();

$errors = [];
$data = [
    'room_no' => '',
    'room_type_id' => 0,
    'floor' => '',
    'image_path' => '',
    'status' => 'Vacant',
];

if (!empty($types)) {
    $data['room_type_id'] = (int)($types[0]['id'] ?? 0);
}

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

    if (!$errors) {
        $id = $roomService->create($data, $errors);
    } else {
        $id = 0;
    }
    if ($id > 0) {
        Flash::set('success', 'Room created successfully.');
        Response::redirect('room_view.php?id=' . $id);
    }
}

$pageTitle = 'New Room - Hotel Management System';
$pendingApprovals = [];

$APP_BASE_URL = App::baseUrl();

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">New Room</h1>
            <p class="text-sm text-gray-500 mt-1">Create a room record</p>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <?php if (empty($types)): ?>
                <div class="text-sm text-gray-700">
                    No room types found. Please create a room type first.
                    <a class="text-blue-600 hover:underline" href="types_create.php">Create room type</a>
                </div>
            <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-1">
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 overflow-hidden">
                        <div class="p-4 border-b border-gray-100 bg-white">
                            <div class="text-sm font-medium text-gray-900">Room Photo</div>
                            <div class="text-xs text-gray-500 mt-1">Upload a photo or use an existing image path</div>
                        </div>
                        <div class="h-56 flex items-center justify-center">
                            <img id="room_preview_img" src="" alt="" style="height:100%;width:100%;object-fit:cover;display:none;" />
                            <div id="room_preview_empty" class="text-xs text-gray-400">Preview</div>
                        </div>
                        <div class="p-4 bg-white border-t border-gray-100">
                            <div class="text-xs text-gray-500">Supported: JPG, PNG, WEBP (max 8MB)</div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                            <input id="room_image_file" type="file" name="image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                            <input id="room_image_path" name="image_path" value="<?= htmlspecialchars((string)$data['image_path']) ?>" placeholder="e.g. /uploads/rooms/101.webp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (isset($errors['image_path'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['image_path']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="md:col-span-2 flex items-center gap-2 pt-2">
                            <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create</button>
                            <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                        </div>
                    </div>
                </div>
            </form>

            <script>
                (function () {
                    const baseUrl = '<?= htmlspecialchars($APP_BASE_URL, ENT_QUOTES) ?>';
                    const img = document.getElementById('room_preview_img');
                    const empty = document.getElementById('room_preview_empty');
                    const fileInput = document.getElementById('room_image_file');
                    const pathInput = document.getElementById('room_image_path');

                    function setPreview(src) {
                        if (!img || !empty) return;
                        if (!src) {
                            img.removeAttribute('src');
                            img.style.display = 'none';
                            empty.style.display = 'block';
                            return;
                        }
                        img.src = src;
                        img.style.display = 'block';
                        empty.style.display = 'none';
                    }

                    function updateFromPath() {
                        const p = (pathInput ? (pathInput.value || '') : '').trim();
                        if (p === '') {
                            setPreview('');
                            return;
                        }
                        if (/^https?:\/\//i.test(p)) {
                            setPreview(p);
                            return;
                        }
                        setPreview(baseUrl + p);
                    }

                    if (fileInput) {
                        fileInput.addEventListener('change', function () {
                            const f = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                            if (!f) {
                                updateFromPath();
                                return;
                            }
                            setPreview(URL.createObjectURL(f));
                        });
                    }

                    if (pathInput) {
                        pathInput.addEventListener('input', function () {
                            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                                return;
                            }
                            updateFromPath();
                        });
                    }

                    updateFromPath();
                })();
            </script>
            <?php endif; ?>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
