<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../domain/Guests/GuestService.php';

$conn = Database::getConnection();
$service = new GuestService(new GuestRepository($conn));

$errors = [];
$data = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'profile_picture_path' => '',
    'id_type' => '',
    'id_number' => '',
    'id_photo_path' => '',
    'preferences' => '',
    'notes' => '',
    'loyalty_tier' => 'None',
    'loyalty_points' => '0',
    'status' => 'Lead',
];

if (Request::isPost()) {
    $data['first_name'] = (string)Request::post('first_name', '');
    $data['last_name'] = (string)Request::post('last_name', '');
    $data['email'] = (string)Request::post('email', '');
    $data['phone'] = (string)Request::post('phone', '');
    $data['profile_picture_path'] = (string)Request::post('profile_picture_path', '');
    $data['id_type'] = (string)Request::post('id_type', '');
    $data['id_number'] = (string)Request::post('id_number', '');
    $data['id_photo_path'] = (string)Request::post('id_photo_path', '');
    $data['preferences'] = (string)Request::post('preferences', '');
    $data['notes'] = (string)Request::post('notes', '');
    $data['loyalty_tier'] = (string)Request::post('loyalty_tier', 'None');
    $data['loyalty_points'] = (string)Request::post('loyalty_points', '0');
    $data['status'] = (string)Request::post('status', 'Lead');

    if (isset($_FILES['profile_picture']) && is_array($_FILES['profile_picture']) && (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            $errors['profile_picture_path'] = 'Failed to upload profile picture.';
        } else {
            $tmp = (string)($_FILES['profile_picture']['tmp_name'] ?? '');
            $orig = (string)($_FILES['profile_picture']['name'] ?? '');
            $size = (int)($_FILES['profile_picture']['size'] ?? 0);

            if ($size <= 0) {
                $errors['profile_picture_path'] = 'Invalid profile picture file.';
            } elseif ($size > (5 * 1024 * 1024)) {
                $errors['profile_picture_path'] = 'Profile picture must be 5MB or less.';
            } else {
                $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed, true)) {
                    $errors['profile_picture_path'] = 'Profile picture must be JPG, PNG, or WEBP.';
                } else {
                    $root = dirname(__DIR__, 3);
                    $uploadDir = $root . '/uploads/guests';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0775, true);
                    }

                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                        $errors['profile_picture_path'] = 'Upload directory is not writable.';
                    } else {
                        $safeExt = ($ext === 'jpeg') ? 'jpg' : $ext;
                        $filename = 'guest_pp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
                        $dest = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($tmp, $dest)) {
                            $errors['profile_picture_path'] = 'Failed to save uploaded profile picture.';
                        } else {
                            $data['profile_picture_path'] = '/uploads/guests/' . $filename;
                        }
                    }
                }
            }
        }
    }

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
                        $safeExt = ($ext === 'jpeg') ? 'jpg' : $ext;
                        $filename = 'guest_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
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

    if (!$errors) {
        $id = $service->create($data, $errors);
    } else {
        $id = 0;
    }
    if ($id > 0) {
        Flash::set('success', 'Guest created successfully.');
        Response::redirect('view.php?id=' . $id);
    }
}

$pageTitle = 'New Guest - Hotel Management System';
$pendingApprovals = [];

$APP_BASE_URL = App::baseUrl();

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">New Guest</h1>
            <p class="text-sm text-gray-500 mt-1">Create a guest profile</p>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-1">
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 overflow-hidden">
                        <div class="p-4 border-b border-gray-100 bg-white">
                            <div class="text-sm font-medium text-gray-900">Profile Picture</div>
                            <div class="text-xs text-gray-500 mt-1">Upload a photo for the guest profile</div>
                        </div>
                        <div class="h-56 flex items-center justify-center">
                            <img id="pp_preview_img" src="" alt="" style="height:100%;width:100%;object-fit:cover;display:none;" />
                            <div id="pp_preview_empty" class="text-xs text-gray-400">Preview</div>
                        </div>
                        <div class="p-4 bg-white border-t border-gray-100">
                            <div class="text-xs text-gray-500">Supported: JPG, PNG, WEBP (max 5MB)</div>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                    <input name="id_type" value="<?= htmlspecialchars($data['id_type']) ?>" placeholder="Passport, Driver's License, National ID" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['id_type'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['id_type']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Number (optional)</label>
                    <input name="id_number" value="<?= htmlspecialchars($data['id_number']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Loyalty Tier</label>
                    <select name="loyalty_tier" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                        <?php foreach (GuestService::allowedLoyaltyTiers() as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= (string)$data['loyalty_tier'] === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['loyalty_tier'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['loyalty_tier']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Loyalty Points</label>
                    <input name="loyalty_points" value="<?= htmlspecialchars((string)$data['loyalty_points']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['loyalty_points'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['loyalty_points']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Profile Picture Path (optional)</label>
                    <input id="profile_picture_path" name="profile_picture_path" value="<?= htmlspecialchars($data['profile_picture_path']) ?>" placeholder="e.g. /uploads/guests/guest_pp_20260314.jpg" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Profile Picture (optional)</label>
                    <input id="profile_picture_file" type="file" name="profile_picture" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['profile_picture_path'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['profile_picture_path']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">ID Photo Path (optional)</label>
                    <input id="id_photo_path" name="id_photo_path" value="<?= htmlspecialchars($data['id_photo_path']) ?>" placeholder="e.g. /uploads/ids/guest_12_front.jpg" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload ID Photo (optional)</label>
                    <input id="id_photo_file" type="file" name="id_photo" accept="image/*" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['id_photo_path'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['id_photo_path']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Preferences (optional)</label>
                    <textarea name="preferences" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3"><?= htmlspecialchars((string)$data['preferences']) ?></textarea>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                    <textarea name="notes" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3"><?= htmlspecialchars((string)$data['notes']) ?></textarea>
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
                    <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create</button>
                    <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Cancel</a>
                </div>
                    </div>
                </div>
            </form>

            <script>
                (function () {
                    const baseUrl = '<?= htmlspecialchars($APP_BASE_URL, ENT_QUOTES) ?>';
                    const img = document.getElementById('pp_preview_img');
                    const empty = document.getElementById('pp_preview_empty');
                    const fileInput = document.getElementById('profile_picture_file');
                    const pathInput = document.getElementById('profile_picture_path');

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
                            const url = URL.createObjectURL(f);
                            setPreview(url);
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
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
