<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();

$role = (string)($_SESSION['role'] ?? 'staff');
if (!RBACMiddleware::isAdminRole($role)) {
    header('Location: ' . $APP_BASE_URL . '/PHP/Dashboard.php');
    exit();
}

$errors = [];
$success = '';

$editUserId = (int)Request::get('edit_user_id', 0);
$action = (string)Request::post('action', '');

$roleOptions = [
    'admin' => 'Admin',
    'superadmin' => 'Superadmin',
    'staff' => 'Staff (All Modules)',
    'front_desk_staff' => 'Front Desk Staff',
    'housekeeping_manager' => 'Housekeeping Divisions Manager',
    'financial_manager' => 'Financial Manager',
    'inventory_pos_manager' => 'Inventory & POS Manager',
    'events_conference_manager' => 'Events & Conference Manager',
];

$data = [
    'username' => '',
    'password' => '',
    'confirm_password' => '',
    'role' => 'staff',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
    'position_title' => '',
    'department' => '',
    'phone' => '',
];

if ($conn) {
    try {
        $hasUsersActiveCol = false;
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'");
            $hasUsersActiveCol = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }
        if (!$hasUsersActiveCol) {
            try {
                $conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
            } catch (Throwable $e) {
            }
        }

        $conn->query(
            "CREATE TABLE IF NOT EXISTS employees (\n"
            . "  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
            . "  user_id INT UNSIGNED NOT NULL,\n"
            . "  first_name VARCHAR(80) NULL,\n"
            . "  last_name VARCHAR(80) NULL,\n"
            . "  position_title VARCHAR(80) NULL,\n"
            . "  department VARCHAR(80) NULL,\n"
            . "  phone VARCHAR(40) NULL,\n"
            . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uq_employees_user (user_id)\n"
            . ") ENGINE=InnoDB"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS user_2fa (\n"
            . "  user_id INT UNSIGNED NOT NULL PRIMARY KEY,\n"
            . "  totp_secret VARCHAR(80) NOT NULL,\n"
            . "  enabled TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP\n"
            . ") ENGINE=InnoDB"
        );
    } catch (Throwable $e) {
    }
}

if (Request::isPost() && $action === 'create_employee') {
    $data['username'] = trim((string)Request::post('username', ''));
    $data['password'] = (string)Request::post('password', '');
    $data['confirm_password'] = (string)Request::post('confirm_password', '');
    $data['role'] = trim((string)Request::post('role', 'staff'));
    $data['email'] = trim((string)Request::post('email', ''));
    $data['first_name'] = trim((string)Request::post('first_name', ''));
    $data['last_name'] = trim((string)Request::post('last_name', ''));
    $data['position_title'] = trim((string)Request::post('position_title', ''));
    $data['department'] = trim((string)Request::post('department', ''));
    $data['phone'] = trim((string)Request::post('phone', ''));

    if ($data['username'] === '') {
        $errors['username'] = 'Username is required.';
    } elseif (strlen($data['username']) < 3) {
        $errors['username'] = 'Username must be at least 3 characters.';
    }

    if (!isset($roleOptions[$data['role']])) {
        $errors['role'] = 'Role is invalid.';
    }

    if ($data['password'] === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($data['password']) < 6) {
        $errors['password'] = 'Password must be at least 6 characters.';
    }

    if ($data['confirm_password'] === '') {
        $errors['confirm_password'] = 'Confirm password is required.';
    } elseif ($data['confirm_password'] !== $data['password']) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors) && $conn) {
        try {
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('s', $data['username']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $errors['username'] = 'That username is already taken.';
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (empty($errors) && $conn) {
        $newUserId = 0;
        try {
            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $profilePath = null;

            $iStmt = $conn->prepare('INSERT INTO users (guest_id, username, password_hash, role, email, profile_picture) VALUES (NULL, ?, ?, ?, NULLIF(?,\'\'), NULL)');
            if ($iStmt instanceof mysqli_stmt) {
                $iStmt->bind_param('ssss', $data['username'], $hash, $data['role'], $data['email']);
                $ok = $iStmt->execute();
                $newUserId = $ok ? (int)$iStmt->insert_id : 0;
                $iStmt->close();
            }

            if ($newUserId <= 0) {
                $errors['general'] = 'Failed to create user.';
            } else {
                if (isset($_FILES['profile_picture']) && is_array($_FILES['profile_picture']) && (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
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
                                $root = dirname(__DIR__, 2);
                                $uploadDir = $root . '/uploads/profile';
                                if (!is_dir($uploadDir)) {
                                    @mkdir($uploadDir, 0775, true);
                                }
                                if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                                    $errors['profile_picture'] = 'Upload directory is not writable.';
                                } else {
                                    $filename = 'user_' . $newUserId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                                    $dest = $uploadDir . '/' . $filename;
                                    if (!move_uploaded_file($tmp, $dest)) {
                                        $errors['profile_picture'] = 'Failed to save profile picture.';
                                    } else {
                                        $profilePath = '/uploads/profile/' . $filename;
                                        $uStmt = $conn->prepare('UPDATE users SET profile_picture = ? WHERE id = ?');
                                        if ($uStmt instanceof mysqli_stmt) {
                                            $uStmt->bind_param('si', $profilePath, $newUserId);
                                            $uStmt->execute();
                                            $uStmt->close();
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                if (empty($errors['general'])) {
                    $eStmt = $conn->prepare('INSERT INTO employees (user_id, first_name, last_name, position_title, department, phone) VALUES (?, NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'))');
                    if ($eStmt instanceof mysqli_stmt) {
                        $eStmt->bind_param('isssss', $newUserId, $data['first_name'], $data['last_name'], $data['position_title'], $data['department'], $data['phone']);
                        $eStmt->execute();
                        $eStmt->close();
                    }
                }

                if (empty($errors)) {
                    $success = 'Employee created.';
                    $data = [
                        'username' => '',
                        'password' => '',
                        'confirm_password' => '',
                        'role' => 'staff',
                        'email' => '',
                        'first_name' => '',
                        'last_name' => '',
                        'position_title' => '',
                        'department' => '',
                        'phone' => '',
                    ];
                }
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to create employee.';
        }
    } elseif (empty($errors)) {
        $errors['general'] = 'Database unavailable.';
    }
}

if (Request::isPost() && $conn && $action === 'toggle_active') {
    $uid = (int)Request::post('user_id', 0);
    $to = (int)Request::post('to', 1);
    if ($uid <= 0) {
        $errors['general'] = 'Invalid user.';
    } else {
        try {
            $stmt = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ii', $to, $uid);
                $stmt->execute();
                $stmt->close();
                $success = $to === 1 ? 'Employee activated.' : 'Employee deactivated.';
            } else {
                $errors['general'] = 'Failed to update status.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to update status.';
        }
    }
    $editUserId = $uid;
}

if (Request::isPost() && $conn && $action === 'reset_password') {
    $uid = (int)Request::post('user_id', 0);
    $pw = (string)Request::post('new_password', '');
    $cf = (string)Request::post('confirm_password', '');
    if ($uid <= 0) {
        $errors['general'] = 'Invalid user.';
    } elseif ($pw === '' || strlen($pw) < 6) {
        $errors['reset_password'] = 'Password must be at least 6 characters.';
    } elseif ($pw !== $cf) {
        $errors['reset_password'] = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('si', $hash, $uid);
                $stmt->execute();
                $stmt->close();
                $success = 'Password reset.';
            } else {
                $errors['general'] = 'Failed to reset password.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to reset password.';
        }
    }
    $editUserId = $uid;
}

if (Request::isPost() && $conn && $action === 'save_employee') {
    $uid = (int)Request::post('user_id', 0);
    $username = trim((string)Request::post('username', ''));
    $urole = trim((string)Request::post('role', 'staff'));
    $email = trim((string)Request::post('email', ''));
    $first = trim((string)Request::post('first_name', ''));
    $last = trim((string)Request::post('last_name', ''));
    $posTitle = trim((string)Request::post('position_title', ''));
    $dept = trim((string)Request::post('department', ''));
    $phone = trim((string)Request::post('phone', ''));

    if ($uid <= 0) {
        $errors['general'] = 'Invalid user.';
    } elseif ($username === '' || strlen($username) < 3) {
        $errors['edit_username'] = 'Username must be at least 3 characters.';
    } elseif (!isset($roleOptions[$urole])) {
        $errors['edit_role'] = 'Role is invalid.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('si', $username, $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $errors['edit_username'] = 'That username is already taken.';
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (empty($errors)) {
        try {
            $profilePath = null;
            if (isset($_FILES['profile_picture']) && is_array($_FILES['profile_picture']) && (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $err = (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_OK);
                if ($err !== UPLOAD_ERR_OK) {
                    $errors['edit_profile_picture'] = 'Failed to upload profile picture.';
                } else {
                    $tmp = (string)($_FILES['profile_picture']['tmp_name'] ?? '');
                    $orig = (string)($_FILES['profile_picture']['name'] ?? '');
                    $size = (int)($_FILES['profile_picture']['size'] ?? 0);
                    if ($size <= 0) {
                        $errors['edit_profile_picture'] = 'Invalid image file.';
                    } elseif ($size > (8 * 1024 * 1024)) {
                        $errors['edit_profile_picture'] = 'Image must be 8MB or less.';
                    } else {
                        $ext = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                        if (!in_array($ext, $allowed, true)) {
                            $errors['edit_profile_picture'] = 'Image must be JPG, PNG, or WEBP.';
                        } else {
                            $root = dirname(__DIR__, 2);
                            $uploadDir = $root . '/uploads/profile';
                            if (!is_dir($uploadDir)) {
                                @mkdir($uploadDir, 0775, true);
                            }
                            if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                                $errors['edit_profile_picture'] = 'Upload directory is not writable.';
                            } else {
                                $filename = 'user_' . $uid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                                $dest = $uploadDir . '/' . $filename;
                                if (!move_uploaded_file($tmp, $dest)) {
                                    $errors['edit_profile_picture'] = 'Failed to save profile picture.';
                                } else {
                                    $profilePath = '/uploads/profile/' . $filename;
                                }
                            }
                        }
                    }
                }
            }

            if (empty($errors)) {
                if ($profilePath !== null) {
                    $uStmt = $conn->prepare('UPDATE users SET username = ?, role = ?, email = NULLIF(?,\'\'), profile_picture = ? WHERE id = ?');
                    if ($uStmt instanceof mysqli_stmt) {
                        $uStmt->bind_param('ssssi', $username, $urole, $email, $profilePath, $uid);
                        $uStmt->execute();
                        $uStmt->close();
                    }
                } else {
                    $uStmt = $conn->prepare('UPDATE users SET username = ?, role = ?, email = NULLIF(?,\'\') WHERE id = ?');
                    if ($uStmt instanceof mysqli_stmt) {
                        $uStmt->bind_param('sssi', $username, $urole, $email, $uid);
                        $uStmt->execute();
                        $uStmt->close();
                    }
                }

                $eStmt = $conn->prepare(
                    'INSERT INTO employees (user_id, first_name, last_name, position_title, department, phone) VALUES (?, NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\')) '
                    . 'ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), position_title = VALUES(position_title), department = VALUES(department), phone = VALUES(phone)'
                );
                if ($eStmt instanceof mysqli_stmt) {
                    $eStmt->bind_param('isssss', $uid, $first, $last, $posTitle, $dept, $phone);
                    $eStmt->execute();
                    $eStmt->close();
                }

                $success = 'Employee updated.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to update employee.';
        }
    }

    $editUserId = $uid;
}

if (Request::isPost() && $conn && $action === 'start_2fa') {
    $uid = (int)Request::post('user_id', 0);
    if ($uid <= 0) {
        $errors['general'] = 'Invalid user.';
    } else {
        try {
            $secret = Totp::generateSecret();
            $stmt = $conn->prepare('INSERT INTO user_2fa (user_id, totp_secret, enabled) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE totp_secret = VALUES(totp_secret), enabled = 0');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('is', $uid, $secret);
                $stmt->execute();
                $stmt->close();
                $success = '2FA setup started.';
            } else {
                $errors['general'] = 'Failed to start 2FA.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to start 2FA.';
        }
    }
    $editUserId = $uid;
}

if (Request::isPost() && $conn && $action === 'confirm_2fa') {
    $uid = (int)Request::post('user_id', 0);
    $code = trim((string)Request::post('twofa_code', ''));
    if ($uid <= 0) {
        $errors['general'] = 'Invalid user.';
    } elseif ($code === '') {
        $errors['twofa_code'] = 'Code is required.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT totp_secret FROM user_2fa WHERE user_id = ? LIMIT 1');
            $row = null;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
            }
            $secret = (string)($row['totp_secret'] ?? '');
            if ($secret === '' || !Totp::verifyCode($secret, $code, 1, 30, 6)) {
                $errors['twofa_code'] = 'Invalid code.';
            } else {
                $up = $conn->prepare('UPDATE user_2fa SET enabled = 1 WHERE user_id = ?');
                if ($up instanceof mysqli_stmt) {
                    $up->bind_param('i', $uid);
                    $up->execute();
                    $up->close();
                    $success = '2FA enabled.';
                }
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to confirm 2FA.';
        }
    }
    $editUserId = $uid;
}

if (Request::isPost() && $conn && $action === 'disable_2fa') {
    $uid = (int)Request::post('user_id', 0);
    if ($uid > 0) {
        try {
            $stmt = $conn->prepare('UPDATE user_2fa SET enabled = 0 WHERE user_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->close();
                $success = '2FA disabled.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to disable 2FA.';
        }
    }
    $editUserId = $uid;
}

if (Request::isPost() && $conn && $action === 'revoke_trusted') {
    $uid = (int)Request::post('user_id', 0);
    if ($uid > 0) {
        try {
            $stmt = $conn->prepare('DELETE FROM user_trusted_devices WHERE user_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->close();
                $success = 'Trusted devices revoked.';
            }
        } catch (Throwable $e) {
            $errors['general'] = 'Failed to revoke trusted devices.';
        }
    }
    $editUserId = $uid;
}

$employees = [];
if ($conn) {
    try {
        $stmt = $conn->prepare(
            "SELECT u.id, u.username, u.role, u.email, u.profile_picture, u.created_at, u.is_active,\n"
            . "       e.first_name, e.last_name, e.position_title, e.department, e.phone,\n"
            . "       t.enabled AS twofa_enabled\n"
            . "FROM users u\n"
            . "LEFT JOIN employees e ON e.user_id = u.id\n"
            . "LEFT JOIN user_2fa t ON t.user_id = u.id\n"
            . "WHERE u.role <> 'guest'\n"
            . "ORDER BY u.id DESC\n"
            . "LIMIT 200"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $employees[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        $employees = [];
    }
}

$editEmployee = null;
$editTwofa = null;
$editTrustedCount = 0;
if ($conn && $editUserId > 0) {
    try {
        $stmt = $conn->prepare(
            "SELECT u.id, u.username, u.role, u.email, u.profile_picture, u.is_active,\n"
            . "       e.first_name, e.last_name, e.position_title, e.department, e.phone\n"
            . "FROM users u\n"
            . "LEFT JOIN employees e ON e.user_id = u.id\n"
            . "WHERE u.id = ? LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $editUserId);
            $stmt->execute();
            $editEmployee = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }

        $tStmt = $conn->prepare('SELECT totp_secret, enabled FROM user_2fa WHERE user_id = ? LIMIT 1');
        if ($tStmt instanceof mysqli_stmt) {
            $tStmt->bind_param('i', $editUserId);
            $tStmt->execute();
            $editTwofa = $tStmt->get_result()->fetch_assoc() ?: null;
            $tStmt->close();
        }

        $cStmt = $conn->prepare('SELECT COUNT(*) AS c FROM user_trusted_devices WHERE user_id = ? AND expires_at > NOW()');
        if ($cStmt instanceof mysqli_stmt) {
            $cStmt->bind_param('i', $editUserId);
            $cStmt->execute();
            $row = $cStmt->get_result()->fetch_assoc();
            $cStmt->close();
            $editTrustedCount = (int)($row['c'] ?? 0);
        }
    } catch (Throwable $e) {
        $editEmployee = null;
        $editTwofa = null;
        $editTrustedCount = 0;
    }
}

$pageTitle = 'Employees';
$pendingApprovals = [];
include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-light text-gray-900">Employees</h1>
                <p class="text-sm text-gray-500 mt-1">Create staff accounts and assign their positions.</p>
            </div>
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/Dashboard.php" class="px-4 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-gray-50 transition">Back</a>
        </div>

        <?php if (!empty($success)): ?>
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Create Employee</h2>

                <form method="post" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="action" value="create_employee" />
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                        <input name="username" value="<?= htmlspecialchars($data['username']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (!empty($errors['username'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['username']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role / Position Access</label>
                        <select name="role" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php foreach ($roleOptions as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>" <?= $data['role'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['role'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['role']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email (optional)</label>
                        <input name="email" value="<?= htmlspecialchars($data['email']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">First name</label>
                            <input name="first_name" value="<?= htmlspecialchars($data['first_name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Last name</label>
                            <input name="last_name" value="<?= htmlspecialchars($data['last_name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Position title</label>
                        <input name="position_title" value="<?= htmlspecialchars($data['position_title']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                        <input name="department" value="<?= htmlspecialchars($data['department']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input name="phone" value="<?= htmlspecialchars($data['phone']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Profile picture (optional)</label>
                        <input type="file" name="profile_picture" accept="image/*" class="w-full text-sm" />
                        <?php if (!empty($errors['profile_picture'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['profile_picture']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" name="password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (!empty($errors['password'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['password']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm</label>
                            <input type="password" name="confirm_password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (!empty($errors['confirm_password'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create Employee</button>
                </form>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-medium text-gray-900">Employee Directory</h2>
                    <div class="text-xs text-gray-500">Showing up to 200 users</div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">2FA</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($employees)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">No employees found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $emp): ?>
                                    <?php
                                        $pp = trim((string)($emp['profile_picture'] ?? ''));
                                        if ($pp !== '') {
                                            $pp = (substr($pp, 0, 1) === '/') ? ($APP_BASE_URL . $pp) : $pp;
                                        } else {
                                            $pp = $APP_BASE_URL . '/PICTURES/Ser.jpg';
                                        }
                                        $name = trim((string)($emp['first_name'] ?? '') . ' ' . (string)($emp['last_name'] ?? ''));
                                        if ($name === '') {
                                            $name = (string)($emp['username'] ?? '');
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <img src="<?= htmlspecialchars($pp) ?>" alt="Profile" class="rounded-full border" style="width:36px;height:36px;object-fit:cover;" />
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($name) ?></div>
                                                    <div class="text-xs text-gray-500"><?= htmlspecialchars((string)($emp['username'] ?? '')) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars(str_replace('_', ' ', (string)($emp['role'] ?? ''))) ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars((string)($emp['position_title'] ?? '')) ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm <?= (int)($emp['is_active'] ?? 1) === 1 ? 'text-green-700' : 'text-red-700' ?>"><?= (int)($emp['is_active'] ?? 1) === 1 ? 'Active' : 'Deactivated' ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars((string)($emp['department'] ?? '')) ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= ((int)($emp['twofa_enabled'] ?? 0) === 1) ? 'Enabled' : 'Off' ?></td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm">
                                            <a class="text-blue-600 hover:underline" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/employees.php?edit_user_id=<?= (int)($emp['id'] ?? 0) ?>">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($editEmployee): ?>
                    <?php
                        $pp2 = trim((string)($editEmployee['profile_picture'] ?? ''));
                        if ($pp2 !== '') {
                            $pp2 = (substr($pp2, 0, 1) === '/') ? ($APP_BASE_URL . $pp2) : $pp2;
                        } else {
                            $pp2 = $APP_BASE_URL . '/PICTURES/Ser.jpg';
                        }
                        $twofaEnabled = (int)($editTwofa['enabled'] ?? 0) === 1;
                        $secret = (string)($editTwofa['totp_secret'] ?? '');
                        $issuer = 'Hotel System';
                        $acct = (string)($editEmployee['username'] ?? 'employee');
                        $otpauth = $secret !== '' ? Totp::buildOtpAuthUri($acct, $issuer, $secret, 6, 30) : '';
                    ?>

                    <div class="mt-6 border-t border-gray-100 pt-6">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Edit Employee</h3>
                                <div class="text-xs text-gray-500">User ID: <?= (int)$editUserId ?></div>
                            </div>
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/employees.php" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 text-xs hover:bg-gray-50 transition">Close</a>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div class="bg-gray-50 rounded-lg border border-gray-100 p-4">
                                <div class="flex items-center gap-3">
                                    <img src="<?= htmlspecialchars($pp2) ?>" alt="Profile" class="rounded-full border" style="width:52px;height:52px;object-fit:cover;" />
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($editEmployee['username'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500"><?= (int)($editEmployee['is_active'] ?? 1) === 1 ? 'Active' : 'Deactivated' ?></div>
                                    </div>
                                </div>

                                <div class="mt-4 space-y-2">
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle_active" />
                                        <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />
                                        <input type="hidden" name="to" value="<?= (int)($editEmployee['is_active'] ?? 1) === 1 ? 0 : 1 ?>" />
                                        <button class="w-full px-3 py-2 rounded-lg border border-gray-200 text-sm hover:bg-white transition"><?= (int)($editEmployee['is_active'] ?? 1) === 1 ? 'Deactivate' : 'Activate' ?></button>
                                    </form>

                                    <form method="post" class="space-y-2">
                                        <input type="hidden" name="action" value="reset_password" />
                                        <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />
                                        <input type="password" name="new_password" placeholder="New password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <input type="password" name="confirm_password" placeholder="Confirm password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <?php if (!empty($errors['reset_password'])): ?>
                                            <div class="text-xs text-red-600"><?= htmlspecialchars($errors['reset_password']) ?></div>
                                        <?php endif; ?>
                                        <button class="w-full px-3 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Reset Password</button>
                                    </form>
                                </div>
                            </div>

                            <div class="bg-white rounded-lg border border-gray-100 p-4 lg:col-span-2">
                                <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <input type="hidden" name="action" value="save_employee" />
                                    <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                                        <input name="username" value="<?= htmlspecialchars((string)($editEmployee['username'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <?php if (!empty($errors['edit_username'])): ?>
                                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['edit_username']) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                                        <select name="role" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                            <?php foreach ($roleOptions as $k => $lbl): ?>
                                                <option value="<?= htmlspecialchars($k) ?>" <?= ((string)($editEmployee['role'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!empty($errors['edit_role'])): ?>
                                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['edit_role']) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                        <input name="email" value="<?= htmlspecialchars((string)($editEmployee['email'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                        <input name="phone" value="<?= htmlspecialchars((string)($editEmployee['phone'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">First name</label>
                                        <input name="first_name" value="<?= htmlspecialchars((string)($editEmployee['first_name'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Last name</label>
                                        <input name="last_name" value="<?= htmlspecialchars((string)($editEmployee['last_name'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Position title</label>
                                        <input name="position_title" value="<?= htmlspecialchars((string)($editEmployee['position_title'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                        <input name="department" value="<?= htmlspecialchars((string)($editEmployee['department'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Profile picture (optional)</label>
                                        <input type="file" name="profile_picture" accept="image/*" class="w-full text-sm" />
                                        <?php if (!empty($errors['edit_profile_picture'])): ?>
                                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['edit_profile_picture']) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="md:col-span-2">
                                        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save Changes</button>
                                    </div>
                                </form>

                                <div class="mt-6 rounded-lg border border-gray-100 bg-gray-50 p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">Two-Factor Authentication</div>
                                            <div class="text-xs text-gray-500 mt-1">Generate a secret for this employee and confirm with a 6-digit code.</div>
                                        </div>
                                        <div class="text-xs text-gray-500">Trusted: <?= (int)$editTrustedCount ?></div>
                                    </div>

                                    <?php if ($twofaEnabled): ?>
                                        <div class="mt-3 text-sm text-green-700">2FA is enabled.</div>
                                        <div class="mt-3 flex flex-col md:flex-row gap-2">
                                            <form method="post">
                                                <input type="hidden" name="action" value="disable_2fa" />
                                                <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />
                                                <button class="px-3 py-2 rounded-lg border border-red-200 text-red-700 text-sm hover:bg-red-50 transition">Disable 2FA</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="action" value="revoke_trusted" />
                                                <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />
                                                <button class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-gray-50 transition">Revoke trusted devices (<?= (int)$editTrustedCount ?>)</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <?php if ($secret !== ''): ?>
                                            <div class="mt-3 text-xs text-gray-700">
                                                <div class="text-gray-500">Secret</div>
                                                <div class="font-mono break-all"><?= htmlspecialchars($secret) ?></div>
                                                <div class="text-gray-500 mt-2">Authenticator URI</div>
                                                <div class="font-mono break-all"><?= htmlspecialchars($otpauth) ?></div>
                                            </div>

                                            <form method="post" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-2 items-end">
                                                <input type="hidden" name="action" value="confirm_2fa" />
                                                <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />
                                                <div class="md:col-span-2">
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">6-digit code</label>
                                                    <input name="twofa_code" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                                    <?php if (!empty($errors['twofa_code'])): ?>
                                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['twofa_code']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Enable 2FA</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="mt-3 text-sm text-gray-700">2FA is off.</div>
                                        <?php endif; ?>

                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="action" value="start_2fa" />
                                            <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>" />
                                            <button class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 text-sm hover:bg-white transition">Generate / Reset Secret</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</section>
<?php
include __DIR__ . '/../partials/page_end.php';
