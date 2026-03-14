<?php
require_once __DIR__ . '/rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/core/bootstrap.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();

$userId = (int)($_SESSION['user_id'] ?? 0);
$guestId = (int)($_SESSION['guest_id'] ?? 0);
$errors = [];

$root = dirname(__DIR__);

$user = null;
$hasUsers = false;
$guestLoyalty = null;
$hasGuestProfilePicture = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'users'");
            $hasUsers = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }
    } catch (Throwable $e) {
    }
}

if ($conn && $hasUsers && $userId > 0) {
    $stmt = $conn->prepare("SELECT id, username, role, email, profile_picture, password_hash FROM users WHERE id = ? LIMIT 1");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc() ?: null;
        $stmt->close();
    }

    if ($guestId > 0) {
        try {
            $dbRow = $conn->query('SELECT DATABASE()');
            $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
            $db = $conn->real_escape_string($db);
            if ($db !== '') {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME = 'profile_picture_path'");
                $hasGuestProfilePicture = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }
        } catch (Throwable $e) {
        }
    }

    if ($guestId > 0) {
        try {
            $dbRow = $conn->query('SELECT DATABASE()');
            $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
            $db = $conn->real_escape_string($db);
            if ($db !== '') {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME IN ('loyalty_points','loyalty_tier')");
                $hasCols = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
                if ($hasCols === 2) {
                    $gStmt = $conn->prepare('SELECT loyalty_points, loyalty_tier FROM guests WHERE id = ? LIMIT 1');
                    if ($gStmt instanceof mysqli_stmt) {
                        $gStmt->bind_param('i', $guestId);
                        $gStmt->execute();
                        $guestLoyalty = $gStmt->get_result()->fetch_assoc() ?: null;
                        $gStmt->close();
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
}

if ($conn && $hasUsers && $userId > 0 && $guestId > 0 && $hasGuestProfilePicture && $user) {
    $uPic = trim((string)($user['profile_picture'] ?? ''));
    try {
        $gStmt = $conn->prepare('SELECT profile_picture_path FROM guests WHERE id = ? LIMIT 1');
        if ($gStmt instanceof mysqli_stmt) {
            $gStmt->bind_param('i', $guestId);
            $gStmt->execute();
            $row = $gStmt->get_result()->fetch_assoc() ?: null;
            $gStmt->close();

            $gPic = trim((string)($row['profile_picture_path'] ?? ''));
            if ($uPic !== '' && $gPic === '') {
                $up = $conn->prepare('UPDATE guests SET profile_picture_path = ? WHERE id = ?');
                if ($up instanceof mysqli_stmt) {
                    $up->bind_param('si', $uPic, $guestId);
                    $up->execute();
                    $up->close();
                }
            }
        }
    } catch (Throwable $e) {
    }
}

if (Request::isPost() && $conn && $hasUsers && $userId > 0) {
    $action = (string)Request::post('action', '');

    if ($action === 'update_username') {
        $username = trim((string)Request::post('username', ''));

        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (strlen($username) < 3) {
            $errors['username'] = 'Username must be at least 3 characters.';
        } elseif (strlen($username) > 50) {
            $errors['username'] = 'Username must be 50 characters or less.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $exists = false;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('si', $username, $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = (bool)($res && $res->fetch_assoc());
                $stmt->close();
            }

            if ($exists) {
                $errors['username'] = 'That username is already taken.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('si', $username, $userId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        $_SESSION['username'] = $username;
                        Flash::set('success', 'Username updated.');
                        Response::redirect('settings.php');
                    }
                }
                $errors['general'] = 'Failed to update username.';
            }
        }
    }

    if ($action === 'remove_profile_picture') {
        $stmt = $conn->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $userId);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                if ($guestId > 0 && $hasGuestProfilePicture) {
                    try {
                        $gStmt = $conn->prepare('UPDATE guests SET profile_picture_path = NULL WHERE id = ?');
                        if ($gStmt instanceof mysqli_stmt) {
                            $gStmt->bind_param('i', $guestId);
                            $gStmt->execute();
                            $gStmt->close();
                        }
                    } catch (Throwable $e) {
                    }
                }
                Flash::set('success', 'Profile picture removed.');
                Response::redirect('settings.php');
            }
        }
        $errors['general'] = 'Failed to remove profile picture.';
    }

    if ($action === 'update_profile_picture') {
        if (!isset($_FILES['profile_picture']) || !is_array($_FILES['profile_picture'])) {
            $errors['profile_picture'] = 'Profile picture is required.';
        } elseif ((int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['profile_picture'] = 'Profile picture is required.';
        } else {
            $err = (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_OK);
            if ($err !== UPLOAD_ERR_OK) {
                $errors['profile_picture'] = 'Failed to upload profile picture.';
            } else {
                $tmp = (string)($_FILES['profile_picture']['tmp_name'] ?? '');
                $orig = (string)($_FILES['profile_picture']['name'] ?? '');
                $size = (int)($_FILES['profile_picture']['size'] ?? 0);
                if ($size <= 0) {
                    $errors['profile_picture'] = 'Invalid image file.';
                } elseif ($size > (8 * 1024 * 1024)) {
                    $errors['profile_picture'] = 'Image must be 8MB or less.';
                } else {
                    $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                    if (!in_array($ext, $allowed, true)) {
                        $errors['profile_picture'] = 'Image must be JPG, PNG, or WEBP.';
                    } else {
                        $uploadDir = $root . '/uploads/profile';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }
                        $filename = 'user_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                        $dest = $uploadDir . '/' . $filename;
                        if (!move_uploaded_file($tmp, $dest)) {
                            $errors['profile_picture'] = 'Failed to save profile picture.';
                        } else {
                            $path = '/uploads/profile/' . $filename;
                            $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                            if ($stmt instanceof mysqli_stmt) {
                                $stmt->bind_param('si', $path, $userId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if ($ok) {
                                    if ($guestId > 0 && $hasGuestProfilePicture) {
                                        try {
                                            $gStmt = $conn->prepare('UPDATE guests SET profile_picture_path = ? WHERE id = ?');
                                            if ($gStmt instanceof mysqli_stmt) {
                                                $gStmt->bind_param('si', $path, $guestId);
                                                $gStmt->execute();
                                                $gStmt->close();
                                            }
                                        } catch (Throwable $e) {
                                        }
                                    }
                                    Flash::set('success', 'Profile picture updated.');
                                    Response::redirect('settings.php');
                                }
                            }
                            $errors['general'] = 'Failed to update profile picture.';
                        }
                    }
                }
            }
        }
    }

    if ($action === 'update_email') {
        $email = trim((string)Request::post('email', ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE users SET email = NULLIF(?, '') WHERE id = ?");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('si', $email, $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Settings updated.');
                    Response::redirect('settings.php');
                }
            }
            $errors['general'] = 'Failed to update settings.';
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string)Request::post('current_password', '');
        $newPassword = (string)Request::post('new_password', '');
        $confirmPassword = (string)Request::post('confirm_password', '');

        if ($currentPassword === '') {
            $errors['current_password'] = 'Current password is required.';
        }
        if ($newPassword === '') {
            $errors['new_password'] = 'New password is required.';
        } elseif (strlen($newPassword) < 6) {
            $errors['new_password'] = 'New password must be at least 6 characters.';
        }
        if ($confirmPassword === '') {
            $errors['confirm_password'] = 'Confirm password is required.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $hash = null;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $hash = $row ? (string)($row['password_hash'] ?? '') : null;
            }

            if (!$hash || !password_verify($currentPassword, $hash)) {
                $errors['current_password'] = 'Current password is incorrect.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('si', $newHash, $userId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Password changed successfully.');
                        Response::redirect('settings.php');
                    }
                }
                $errors['general'] = 'Failed to change password.';
            }
        }
    }
}

include __DIR__ . '/partials/page_start.php';
include __DIR__ . '/partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Settings</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your profile, security, and account preferences</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$conn || !$hasUsers || !$user): ?>
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                Settings page is unavailable. Ensure the <span class="font-medium">users</span> table exists and you are logged in.
            </div>
        <?php else: ?>
            <?php
                $profilePic = trim((string)($user['profile_picture'] ?? ''));
                if ($profilePic !== '') {
                    $profilePic = (substr($profilePic, 0, 1) === '/') ? ($APP_BASE_URL . $profilePic) : $profilePic;
                } else {
                    $profilePic = $APP_BASE_URL . '/PICTURES/Ser.jpg';
                }
            ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white rounded-xl border border-gray-100 p-6 lg:col-span-1">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">Profile</h3>
                            <p class="text-xs text-gray-500 mt-1">Photo and basic account identity</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 mb-5">
                        <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile" class="rounded-full border" style="width:72px;height:72px;object-fit:cover;" />
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars((string)($user['username'] ?? '')) ?></div>
                            <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars((string)($user['role'] ?? '')) ?></div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <?php if ($guestLoyalty): ?>
                            <div class="rounded-lg border border-gray-100 p-4 bg-gray-50">
                                <div class="text-xs text-gray-500">Loyalty</div>
                                <div class="mt-2 grid grid-cols-2 gap-3">
                                    <div class="rounded-lg border border-gray-100 bg-white p-3">
                                        <div class="text-xs text-gray-500">Points</div>
                                        <div class="text-sm font-semibold text-gray-900 mt-1"><?= (int)($guestLoyalty['loyalty_points'] ?? 0) ?></div>
                                    </div>
                                    <div class="rounded-lg border border-gray-100 bg-white p-3">
                                        <div class="text-xs text-gray-500">Tier</div>
                                        <div class="text-sm font-semibold text-gray-900 mt-1"><?= htmlspecialchars(trim((string)($guestLoyalty['loyalty_tier'] ?? '')) !== '' ? (string)$guestLoyalty['loyalty_tier'] : 'None') ?></div>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 mt-3">Points are earned from eligible stays and purchases posted by staff.</div>
                            </div>
                        <?php endif; ?>

                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="text-sm font-medium text-gray-900 mb-2">Change username</div>
                            <form method="post" class="space-y-2">
                                <input type="hidden" name="action" value="update_username" />
                                <input name="username" value="<?= htmlspecialchars((string)($user['username'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['username'])): ?>
                                    <div class="text-xs text-red-600">
                                        <?= htmlspecialchars($errors['username']) ?>
                                    </div>
                                <?php endif; ?>
                                <button class="w-full px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Save Username</button>
                            </form>
                        </div>

                        <div class="rounded-lg border border-gray-100 p-4">
                            <div class="text-sm font-medium text-gray-900 mb-2">Profile picture</div>
                            <form method="post" enctype="multipart/form-data" class="space-y-2">
                                <input type="hidden" name="action" value="update_profile_picture" />
                                <input type="file" name="profile_picture" accept="image/*" class="w-full text-sm" />
                                <?php if (isset($errors['profile_picture'])): ?>
                                    <div class="text-xs text-red-600">
                                        <?= htmlspecialchars($errors['profile_picture']) ?>
                                    </div>
                                <?php endif; ?>
                                <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Upload New Photo</button>
                            </form>

                            <form method="post" class="mt-2">
                                <input type="hidden" name="action" value="remove_profile_picture" />
                                <button class="w-full px-4 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-gray-50 transition">Remove Photo</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-xl border border-gray-100 p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Account</h3>
                                <p class="text-xs text-gray-500 mt-1">Contact information used for recovery and alerts</p>
                            </div>
                        </div>
                        <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                            <input type="hidden" name="action" value="update_email" />
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input name="email" value="<?= htmlspecialchars((string)($user['email'] ?? '')) ?>" placeholder="name@example.com" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['email'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['email']) ?></div>
                                <?php endif; ?>
                                <div class="text-xs text-gray-500 mt-1">Leave blank to clear your email.</div>
                            </div>
                            <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save</button>
                        </form>
                    </div>

                    <div class="bg-white rounded-xl border border-gray-100 p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Security</h3>
                                <p class="text-xs text-gray-500 mt-1">Keep your account protected</p>
                            </div>
                        </div>
                        <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <input type="hidden" name="action" value="change_password" />
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                                <input type="password" name="current_password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['current_password'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['current_password']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                                <input type="password" name="new_password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['new_password'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['new_password']) ?></div>
                                <?php endif; ?>
                                <div class="text-xs text-gray-500 mt-1">Minimum 6 characters.</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                                <input type="password" name="confirm_password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['confirm_password'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="md:col-span-3">
                                <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Change Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</section>
<?php include __DIR__ . '/partials/page_end.php';
