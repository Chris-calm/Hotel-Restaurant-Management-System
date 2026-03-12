<?php
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/rbac_middleware.php';

RBACMiddleware::init();

if (!isset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_username'])) {
    header('Location: ../index.php');
    exit();
}

$userId = (int)$_SESSION['pending_otp_user_id'];
$username = (string)$_SESSION['pending_otp_username'];

$conn = Database::getConnection();
if (!$conn) {
    Flash::set('error', 'Database unavailable for OTP verification.');
    Response::redirect('../index.php');
}

$errors = [];

if (Request::isPost()) {
    $otp = trim((string)Request::post('otp', ''));

    if ($otp === '') {
        $errors['otp'] = 'OTP is required.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, otp_code, expires_at
             FROM user_otps
             WHERE user_id = ? AND username = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('is', $userId, $username);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $errors['otp'] = 'OTP not found.';
            } else {
                $expiresAt = strtotime((string)$row['expires_at']);
                if ($expiresAt !== false && $expiresAt < time()) {
                    $errors['otp'] = 'OTP expired.';
                } elseif (!hash_equals((string)$row['otp_code'], $otp)) {
                    $errors['otp'] = 'Invalid OTP.';
                } else {
                    $uStmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
                    if ($uStmt instanceof mysqli_stmt) {
                        $uStmt->bind_param('i', $userId);
                        $uStmt->execute();
                        $uRes = $uStmt->get_result();
                        $user = $uRes->fetch_assoc();
                        $uStmt->close();

                        if ($user) {
                            unset($_SESSION['pending_otp_user_id'], $_SESSION['pending_otp_username']);

                            $_SESSION['user_id'] = (int)$user['id'];
                            $_SESSION['username'] = (string)$user['username'];
                            $_SESSION['role'] = (string)$user['role'];

                            Response::redirect('Dashboard.php');
                        } else {
                            $errors['otp'] = 'User not found.';
                        }
                    } else {
                        $errors['otp'] = 'Failed to verify user.';
                    }
                }
            }
        } else {
            $errors['otp'] = 'Failed to verify OTP.';
        }
    }
}

$pageTitle = 'Verify OTP - Hotel Management System';
$pendingApprovals = [];

$APP_BASE_URL = App::baseUrl();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($APP_BASE_URL) ?>/CSS/index.css" />
</head>
<body>
<section id="content" style="margin-left:0;">
    <main class="w-full px-6 py-6" style="max-width:520px;margin:0 auto;">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Verify OTP</h1>
            <p class="text-sm text-gray-500 mt-1">Enter the one-time password sent to your email</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-6">
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">OTP</label>
                    <input name="otp" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    <?php if (isset($errors['otp'])): ?>
                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['otp']) ?></div>
                    <?php endif; ?>
                </div>

                <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Verify</button>
                <a href="../index.php" class="block w-full text-center px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back to Login</a>
            </form>
        </div>
    </main>
</section>
</body>
</html>
