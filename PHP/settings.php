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
$hasUser2faTable = false;
$hasUserTrustedDevicesTable = false;
$twofa = null;
$trustedDevices = [];
$trustedDevicesCount = 0;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'users'");
            $hasUsers = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'user_2fa'");
            $hasUser2faTable = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'user_trusted_devices'");
            $hasUserTrustedDevicesTable = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
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

    if ($hasUser2faTable) {
        try {
            $tStmt = $conn->prepare('SELECT totp_secret, enabled, updated_at FROM user_2fa WHERE user_id = ? LIMIT 1');
            if ($tStmt instanceof mysqli_stmt) {
                $tStmt->bind_param('i', $userId);
                $tStmt->execute();
                $twofa = $tStmt->get_result()->fetch_assoc() ?: null;
                $tStmt->close();
            }
        } catch (Throwable $e) {
            $twofa = null;
        }
    }

    if ($hasUserTrustedDevicesTable) {
        try {
            $dStmt = $conn->prepare('SELECT id, expires_at, user_agent, created_at, last_used_at FROM user_trusted_devices WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 20');
            if ($dStmt instanceof mysqli_stmt) {
                $dStmt->bind_param('i', $userId);
                $dStmt->execute();
                $res = $dStmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $trustedDevices[] = $row;
                }
                $dStmt->close();
            }
        } catch (Throwable $e) {
            $trustedDevices = [];
        }
        $trustedDevicesCount = count($trustedDevices);
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

    if ($action === 'start_2fa_setup') {
        if (!$hasUser2faTable) {
            $errors['general'] = '2FA is unavailable. Database table missing.';
        } elseif (($user['role'] ?? '') !== 'guest') {
            $errors['general'] = '2FA setup is available for guest accounts only.';
        } else {
            $secret = Totp::generateSecret();
            $_SESSION['pending_2fa_secret'] = $secret;
            Flash::set('success', '2FA setup started. Enter the code from your authenticator app to confirm.');
            Response::redirect('settings.php');
        }
    }

    if ($action === 'confirm_2fa_setup') {
        if (!$hasUser2faTable) {
            $errors['general'] = '2FA is unavailable. Database table missing.';
        } elseif (($user['role'] ?? '') !== 'guest') {
            $errors['general'] = '2FA setup is available for guest accounts only.';
        } else {
            $code = trim((string)Request::post('code', ''));
            $secret = (string)($_SESSION['pending_2fa_secret'] ?? '');
            if ($secret === '') {
                $errors['general'] = '2FA setup session expired. Start again.';
            } elseif ($code === '') {
                $errors['twofa_code'] = 'Code is required.';
            } elseif (!Totp::verifyCode($secret, $code, 1, 30, 6)) {
                $errors['twofa_code'] = 'Invalid code.';
            }

            if (empty($errors)) {
                $stmt = $conn->prepare('INSERT INTO user_2fa (user_id, totp_secret, enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE totp_secret = VALUES(totp_secret), enabled = 1');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('is', $userId, $secret);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        unset($_SESSION['pending_2fa_secret']);
                        Flash::set('success', 'Two-factor authentication enabled.');
                        Response::redirect('settings.php');
                    }
                }
                $errors['general'] = 'Failed to enable 2FA.';
            }
        }
    }

    if ($action === 'disable_2fa') {
        if (!$hasUser2faTable) {
            $errors['general'] = '2FA is unavailable. Database table missing.';
        } elseif (($user['role'] ?? '') !== 'guest') {
            $errors['general'] = '2FA is available for guest accounts only.';
        } else {
            $stmt = $conn->prepare('UPDATE user_2fa SET enabled = 0 WHERE user_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    if ($hasUserTrustedDevicesTable) {
                        $d = $conn->prepare('DELETE FROM user_trusted_devices WHERE user_id = ?');
                        if ($d instanceof mysqli_stmt) {
                            $d->bind_param('i', $userId);
                            $d->execute();
                            $d->close();
                        }
                    }
                    setcookie('trusted_device', '', [
                        'expires' => time() - 3600,
                        'path' => '/',
                        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    unset($_SESSION['pending_2fa_secret']);
                    Flash::set('success', 'Two-factor authentication disabled.');
                    Response::redirect('settings.php');
                }
            }
            $errors['general'] = 'Failed to disable 2FA.';
        }
    }

    if ($action === 'revoke_trusted_devices') {
        if (!$hasUserTrustedDevicesTable) {
            $errors['general'] = 'Trusted devices are unavailable. Database table missing.';
        } else {
            $stmt = $conn->prepare('DELETE FROM user_trusted_devices WHERE user_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    setcookie('trusted_device', '', [
                        'expires' => time() - 3600,
                        'path' => '/',
                        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    Flash::set('success', 'Trusted devices revoked. You will be asked for a code again on next login.');
                    Response::redirect('settings.php');
                }
            }
            $errors['general'] = 'Failed to revoke trusted devices.';
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

                    <?php if (($user['role'] ?? '') === 'guest'): ?>
                        <div class="bg-white rounded-xl border border-gray-100 p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">Two-Factor Authentication</h3>
                                    <p class="text-xs text-gray-500 mt-1">Use an authenticator app to protect your guest account</p>
                                </div>
                            </div>

                            <?php if (!$hasUser2faTable): ?>
                                <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                                    2FA is unavailable. Database table is missing.
                                </div>
                            <?php else: ?>
                                <?php
                                    $twofaEnabled = $twofa && (int)($twofa['enabled'] ?? 0) === 1;
                                    $pendingSecret = (string)($_SESSION['pending_2fa_secret'] ?? '');
                                    $issuer = 'Hotel System';
                                    $acct = (string)($user['username'] ?? 'guest');
                                    $otpauth = $pendingSecret !== '' ? Totp::buildOtpAuthUri($acct, $issuer, $pendingSecret, 6, 30) : '';
                                ?>

                                <?php if ($twofaEnabled): ?>
                                    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                                        2FA is enabled.
                                    </div>
                                    <div class="mt-4 flex flex-col md:flex-row gap-3">
                                        <form method="post">
                                            <input type="hidden" name="action" value="disable_2fa" />
                                            <button class="px-4 py-2 rounded-lg border border-red-200 text-red-700 text-sm hover:bg-red-50 transition">Disable 2FA</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="revoke_trusted_devices" />
                                            <button class="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-gray-50 transition">Revoke trusted devices (<?= (int)$trustedDevicesCount ?>)</button>
                                        </form>
                                    </div>
                                <?php elseif ($pendingSecret !== ''): ?>
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                                        Add this secret to Google Authenticator, then enter the 6-digit code to confirm.
                                    </div>
                                    <div class="mt-4 grid grid-cols-1 gap-3">
                                        <div class="rounded-lg border border-gray-100 p-4">
                                            <div class="text-xs text-gray-500">Secret</div>
                                            <div class="mt-2 font-mono text-sm text-gray-900 break-all"><?= htmlspecialchars($pendingSecret) ?></div>
                                        </div>
                                        <div class="rounded-lg border border-gray-100 p-4">
                                            <div class="text-xs text-gray-500">Authenticator URI</div>
                                            <div class="mt-2 font-mono text-xs text-gray-700 break-all"><?= htmlspecialchars($otpauth) ?></div>
                                        </div>
                                    </div>

                                    <form method="post" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                                        <input type="hidden" name="action" value="confirm_2fa_setup" />
                                        <div class="md:col-span-2">
                                            <label class="block text-sm font-medium text-gray-700 mb-1">6-digit code</label>
                                            <input name="code" inputmode="numeric" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                            <?php if (isset($errors['twofa_code'])): ?>
                                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['twofa_code']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Confirm 2FA</button>
                                    </form>
                                <?php else: ?>
                                    <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                                        Turn on 2FA to require a code from your authenticator app at login. You can trust your device for 7 days.
                                    </div>
                                    <form method="post" class="mt-4">
                                        <input type="hidden" name="action" value="start_2fa_setup" />
                                        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Enable 2FA</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
</section>
<?php include __DIR__ . '/partials/page_end.php';
