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

if (Request::isPost() && (string)Request::post('action', '') === 'update_room') {
    $errors = [];
    $id = (int)Request::post('id', 0);
    $data = [
        'room_no' => (string)Request::post('room_no', ''),
        'room_type_id' => (int)Request::post('room_type_id', 0),
        'floor' => (string)Request::post('floor', ''),
        'image_path' => (string)Request::post('image_path', ''),
        'status' => (string)Request::post('status', 'Vacant'),
    ];

    if ($id > 0 && isset($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
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

    if (empty($errors)) {
        $ok = $roomService->update($id, $data, $errors);
        if ($ok) {
            Flash::set('success', 'Room updated successfully.');
            Response::redirect('index.php?q=' . urlencode($q));
        }
    }

    Flash::set('error', $errors['image_path'] ?? $errors['room_no'] ?? $errors['room_type_id'] ?? $errors['status'] ?? 'Failed to update room.');
    Response::redirect('index.php?q=' . urlencode($q));
}

$pageTitle = 'Rooms - Hotel Management System';
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
                    <h1 class="text-2xl font-light text-gray-900">Rooms</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage room master data and room types</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="room_create.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">New Room</a>
                    <a href="locks.php" class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Door Locks</a>
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

        <?php if (empty($rooms)): ?>
            <div class="bg-white rounded-lg border border-gray-100 p-10 text-center text-gray-500">No rooms found.</div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($rooms as $r): ?>
                    <?php
                        $imgPath = trim((string)($r['image_path'] ?? ''));
                        $typeLabel = (string)(($r['room_type_code'] ?? '') . ' - ' . ($r['room_type_name'] ?? ''));
                    ?>
                    <div class="bg-white rounded-lg border border-gray-100 overflow-hidden">
                        <div class="h-36 bg-gray-50 flex items-center justify-center">
                            <?php if ($imgPath !== ''): ?>
                                <img src="<?= htmlspecialchars($APP_BASE_URL . $imgPath) ?>" alt="" style="height:100%;width:100%;object-fit:cover;" />
                            <?php else: ?>
                                <div class="text-xs text-gray-400">No image</div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">Room <?= htmlspecialchars($r['room_no'] ?? '') ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($typeLabel) ?></div>
                                </div>
                                <div class="text-xs px-2 py-1 rounded-full border border-gray-200 text-gray-700"><?= htmlspecialchars($r['status'] ?? '') ?></div>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">Floor: <?= htmlspecialchars($r['floor'] ?? '') ?></div>
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    class="px-3 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition"
                                    onclick="openViewRoomModal(this)"
                                    data-id="<?= (int)$r['id'] ?>"
                                    data-room_no="<?= htmlspecialchars($r['room_no'] ?? '', ENT_QUOTES) ?>"
                                    data-floor="<?= htmlspecialchars($r['floor'] ?? '', ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($r['status'] ?? '', ENT_QUOTES) ?>"
                                    data-room_type_id="<?= (int)($r['room_type_id'] ?? 0) ?>"
                                    data-room_type_label="<?= htmlspecialchars($typeLabel, ENT_QUOTES) ?>"
                                    data-image_path="<?= htmlspecialchars($imgPath, ENT_QUOTES) ?>"
                                >View</button>
                                <button
                                    type="button"
                                    class="px-3 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition"
                                    onclick="openEditRoomModal(this)"
                                    data-id="<?= (int)$r['id'] ?>"
                                    data-room_no="<?= htmlspecialchars($r['room_no'] ?? '', ENT_QUOTES) ?>"
                                    data-floor="<?= htmlspecialchars($r['floor'] ?? '', ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($r['status'] ?? '', ENT_QUOTES) ?>"
                                    data-room_type_id="<?= (int)($r['room_type_id'] ?? 0) ?>"
                                    data-image_path="<?= htmlspecialchars($imgPath, ENT_QUOTES) ?>"
                                >Edit</button>
                            </div>
                            <div class="mt-3 text-right text-xs">
                                <a class="text-red-600 hover:underline" href="room_delete.php?id=<?= (int)$r['id'] ?>">Delete</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div id="roomViewModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" aria-hidden="true">
            <div class="absolute inset-0 bg-black/40" onclick="closeRoomModal('roomViewModal')"></div>
            <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-lg overflow-hidden max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-lg font-medium text-gray-900">Room Details</div>
                            <div id="view_room_sub" class="text-sm text-gray-500 mt-1"></div>
                        </div>
                        <button type="button" class="text-gray-500 hover:text-gray-900" onclick="closeRoomModal('roomViewModal')">✕</button>
                    </div>

                    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2 h-56 bg-gray-50 rounded-xl overflow-hidden flex items-center justify-center">
                            <img id="view_room_img" src="" alt="" style="height:100%;width:100%;object-fit:cover;display:none;" />
                            <div id="view_room_noimg" class="text-xs text-gray-400">No image</div>
                        </div>

                        <div>
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Room No</div>
                            <div id="view_room_no" class="text-sm text-gray-900 mt-1"></div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Status</div>
                            <div id="view_room_status" class="text-sm text-gray-900 mt-1"></div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Type</div>
                            <div id="view_room_type" class="text-sm text-gray-900 mt-1"></div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 uppercase tracking-wider">Floor</div>
                            <div id="view_room_floor" class="text-sm text-gray-900 mt-1"></div>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end">
                        <button type="button" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition" onclick="closeRoomModal('roomViewModal')">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="roomEditModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" aria-hidden="true">
            <div class="absolute inset-0 bg-black/40" onclick="closeRoomModal('roomEditModal')"></div>
            <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-lg overflow-hidden max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-lg font-medium text-gray-900">Edit Room</div>
                            <div class="text-sm text-gray-500 mt-1">Update room details</div>
                        </div>
                        <button type="button" class="text-gray-500 hover:text-gray-900" onclick="closeRoomModal('roomEditModal')">✕</button>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="hidden" name="action" value="update_room" />
                        <input type="hidden" name="id" id="edit_id" value="0" />

                        <div class="md:col-span-2 h-56 bg-gray-50 rounded-xl overflow-hidden flex items-center justify-center">
                            <img id="edit_room_img" src="" alt="" style="height:100%;width:100%;object-fit:cover;display:none;" />
                            <div id="edit_room_noimg" class="text-xs text-gray-400">Preview</div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image (optional)</label>
                            <input type="file" name="image" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" onchange="previewEditRoomImage(this)" />
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image Path (optional)</label>
                            <input name="image_path" id="edit_image_path" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Room No</label>
                            <input name="room_no" id="edit_room_no" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Room Type</label>
                            <select name="room_type_id" id="edit_room_type_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars(($t['code'] ?? '') . ' - ' . ($t['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Floor</label>
                            <input name="floor" id="edit_floor" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="edit_status" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <?php foreach (RoomService::allowedStatuses() as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="md:col-span-2 grid grid-cols-2 gap-3 pt-2">
                            <button type="button" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition" onclick="closeRoomModal('roomEditModal')">Cancel</button>
                            <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function openRoomModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('hidden');
                el.classList.add('flex');
                el.setAttribute('aria-hidden', 'false');
            }

            function closeRoomModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('flex');
                el.classList.add('hidden');
                el.setAttribute('aria-hidden', 'true');
            }

            function openViewRoomModal(btn) {
                const roomNo = btn.getAttribute('data-room_no') || '';
                const floor = btn.getAttribute('data-floor') || '';
                const status = btn.getAttribute('data-status') || '';
                const typeLabel = btn.getAttribute('data-room_type_label') || '';
                const imagePath = btn.getAttribute('data-image_path') || '';

                document.getElementById('view_room_no').textContent = roomNo;
                document.getElementById('view_room_floor').textContent = floor;
                document.getElementById('view_room_status').textContent = status;
                document.getElementById('view_room_type').textContent = typeLabel;
                document.getElementById('view_room_sub').textContent = typeLabel;

                const img = document.getElementById('view_room_img');
                const noimg = document.getElementById('view_room_noimg');
                if (imagePath.trim() !== '') {
                    img.src = '<?= htmlspecialchars($APP_BASE_URL, ENT_QUOTES) ?>' + imagePath;
                    img.style.display = 'block';
                    noimg.style.display = 'none';
                } else {
                    img.removeAttribute('src');
                    img.style.display = 'none';
                    noimg.style.display = 'block';
                }

                openRoomModal('roomViewModal');
            }

            function openEditRoomModal(btn) {
                const id = btn.getAttribute('data-id') || '0';
                const roomNo = btn.getAttribute('data-room_no') || '';
                const floor = btn.getAttribute('data-floor') || '';
                const status = btn.getAttribute('data-status') || 'Vacant';
                const typeId = btn.getAttribute('data-room_type_id') || '0';
                const imagePath = btn.getAttribute('data-image_path') || '';

                document.getElementById('edit_id').value = id;
                document.getElementById('edit_room_no').value = roomNo;
                document.getElementById('edit_floor').value = floor;
                document.getElementById('edit_status').value = status;
                document.getElementById('edit_room_type_id').value = typeId;
                document.getElementById('edit_image_path').value = imagePath;

                const img = document.getElementById('edit_room_img');
                const noimg = document.getElementById('edit_room_noimg');
                if (imagePath.trim() !== '') {
                    img.src = '<?= htmlspecialchars($APP_BASE_URL, ENT_QUOTES) ?>' + imagePath;
                    img.style.display = 'block';
                    noimg.style.display = 'none';
                } else {
                    img.removeAttribute('src');
                    img.style.display = 'none';
                    noimg.style.display = 'block';
                    noimg.textContent = 'Preview';
                }

                openRoomModal('roomEditModal');
            }

            function previewEditRoomImage(fileInput) {
                const file = fileInput && fileInput.files ? fileInput.files[0] : null;
                if (!file) return;
                const url = URL.createObjectURL(file);
                const img = document.getElementById('edit_room_img');
                const noimg = document.getElementById('edit_room_noimg');
                img.src = url;
                img.style.display = 'block';
                noimg.style.display = 'none';
            }

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeRoomModal('roomViewModal');
                    closeRoomModal('roomEditModal');
                }
            });
        </script>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
