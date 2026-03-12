<?php
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/rbac_middleware.php';
require_once __DIR__ . '/domain/Guests/GuestService.php';

RBACMiddleware::init();

if (isset($_SESSION['user_id'])) {
    $role = (string)($_SESSION['role'] ?? 'staff');
    if ($role === 'guest') {
        Response::redirect('guest/index.php');
    }
    Response::redirect('Dashboard.php');
}

$conn = Database::getConnection();
$APP_BASE_URL = App::baseUrl();

$hasUsersTable = false;
$hasGuestsTable = false;
$hasUsersGuestIdColumn = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'users'");
            $hasUsersTable = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests'");
            $hasGuestsTable = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'guest_id'");
            $hasUsersGuestIdColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }
    } catch (Throwable $e) {
    }
}

$errors = [];
$data = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'username' => '',
];

if (Request::isPost() && $conn && $hasUsersTable && $hasGuestsTable) {
    $data['first_name'] = trim((string)Request::post('first_name', ''));
    $data['last_name'] = trim((string)Request::post('last_name', ''));
    $data['email'] = trim((string)Request::post('email', ''));
    $data['phone'] = trim((string)Request::post('phone', ''));
    $data['username'] = trim((string)Request::post('username', ''));
    $password = (string)Request::post('password', '');
    $confirm = (string)Request::post('confirm_password', '');

    if ($data['username'] === '') {
        $errors['username'] = 'Username is required.';
    } elseif (strlen($data['username']) < 3) {
        $errors['username'] = 'Username must be at least 3 characters.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters.';
    }

    if ($confirm === '') {
        $errors['confirm_password'] = 'Confirm password is required.';
    } elseif ($confirm !== $password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('s', $data['username']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->fetch_assoc()) {
                $errors['username'] = 'That username is already taken.';
            }
            $stmt->close();
        }
    }

    if (empty($errors)) {
        $guestService = new GuestService(new GuestRepository($conn));
        $guestPayload = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'status' => 'Lead',
        ];
        $guestId = $guestService->create($guestPayload, $errors);

        if ($guestId > 0) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            if (!$hasUsersGuestIdColumn) {
                $errors['general'] = 'System not ready for guest portal. Please run the latest database updates (users.guest_id).';
            } else {
                $role = 'guest';
                $stmt = $conn->prepare('INSERT INTO users (guest_id, username, password_hash, role, email) VALUES (?, ?, ?, ?, NULLIF(?,\'\'))');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('issss', $guestId, $data['username'], $hash, $role, $data['email']);
                    $ok = $stmt->execute();
                    $userId = $ok ? (int)$stmt->insert_id : 0;
                    $stmt->close();

                    if ($userId > 0) {
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['guest_id'] = $guestId;
                        $_SESSION['username'] = $data['username'];
                        $_SESSION['role'] = 'guest';
                        Flash::set('success', 'Account created successfully.');
                        Response::redirect('guest/index.php');
                    } else {
                        $errors['general'] = 'Failed to create user account.';
                    }
                } else {
                    $errors['general'] = 'Failed to create user account.';
                }
            }
        }
    }
}

$pageTitle = 'Create Account - Hotel Management System';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($APP_BASE_URL) ?>/CSS/index.css" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body style="background:#eeeeee;">
<section id="content" style="margin-left:0;">
    <main class="w-full px-6 py-10" style="max-width:920px;margin:0 auto;">
        <div class="mb-6">
            <div class="flex items-center gap-3">
                <img src="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/H.png" alt="Logo" style="width:40px;height:40px;object-fit:contain;" />
                <div>
                    <h1 class="text-2xl font-light text-gray-900">Create Account</h1>
                    <p class="text-sm text-gray-500 mt-1">Create a guest account to book rooms online. Your reservation will be <span class="font-medium">Pending</span> until the front desk confirms it with your ₱1,000 deposit slip.</p>
                </div>
            </div>
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

        <?php if (!$conn || !$hasUsersTable || !$hasGuestsTable): ?>
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                Registration is unavailable. Ensure the database is running and required tables exist.
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-100 p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-1">Guest details</h3>
                    <p class="text-xs text-gray-500 mb-4">These details are used for reservations and loyalty points.</p>

                    <form method="post" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">First name</label>
                                <input name="first_name" value="<?= htmlspecialchars($data['first_name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['first_name'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['first_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Last name</label>
                                <input name="last_name" value="<?= htmlspecialchars($data['last_name']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['last_name'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['last_name']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input name="email" value="<?= htmlspecialchars($data['email']) ?>" placeholder="name@example.com" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (isset($errors['email'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                            <input name="phone" value="<?= htmlspecialchars($data['phone']) ?>" placeholder="09xxxxxxxxx" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (isset($errors['phone'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="pt-2 border-t border-gray-100">
                            <h3 class="text-lg font-medium text-gray-900 mb-1">Login credentials</h3>
                            <p class="text-xs text-gray-500 mb-4">Use these to sign in and manage your bookings.</p>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                                    <input name="username" value="<?= htmlspecialchars($data['username']) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['username'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['username']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                                        <input type="password" name="password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <?php if (isset($errors['password'])): ?>
                                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['password']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm password</label>
                                        <input type="password" name="confirm_password" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <?php if (isset($errors['confirm_password'])): ?>
                                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['confirm_password']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create account</button>
                        <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/index.php" class="block w-full text-center px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Back to login</a>
                    </form>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-3">How online booking works</h3>
                    <div class="space-y-3 text-sm text-gray-700">
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <div class="font-medium text-gray-900">1) Browse available rooms</div>
                            <div class="text-gray-600 mt-1">Select your check-in and check-out dates to see rooms that are available for those dates.</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <div class="font-medium text-gray-900">2) Request booking</div>
                            <div class="text-gray-600 mt-1">Your booking is saved as <span class="font-medium">Pending</span> so the front desk can verify availability and guest details.</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <div class="font-medium text-gray-900">3) Print deposit slip (₱1,000)</div>
                            <div class="text-gray-600 mt-1">Bring the slip to the front desk and pay the deposit. The staff will confirm your reservation and issue the official receipt.</div>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <div class="font-medium text-gray-900">4) Confirmation</div>
                            <div class="text-gray-600 mt-1">After the deposit is received, your reservation will move to <span class="font-medium">Confirmed</span> and you’ll see the updated status in your portal.</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</section>
</body>
</html>
