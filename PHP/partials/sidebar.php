<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../rbac_middleware.php';
$APP_BASE_URL = App::baseUrl();

$currentUserProfilePic = $APP_BASE_URL . '/PICTURES/Ser.jpg';
$currentUserName = $_SESSION['username'] ?? 'User';
$currentUserRole = $_SESSION['role'] ?? 'staff';

$conn = Database::getConnection();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$orderedModules = [];
if ((string)$currentUserRole !== 'guest') {
    $orderedModules = RBACMiddleware::getOrderedModules($conn, (string)$currentUserRole);
}
if ($conn && $currentUserId > 0) {
    try {
        $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE id = ? LIMIT 1");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $currentUserId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            $pp = trim((string)($row['profile_picture'] ?? ''));
            if ($pp !== '') {
                if (preg_match('/^https?:\/\//i', $pp)) {
                    $currentUserProfilePic = $pp;
                } elseif (substr($pp, 0, 1) === '/') {
                    $currentUserProfilePic = $APP_BASE_URL . $pp;
                } else {
                    $currentUserProfilePic = $APP_BASE_URL . '/' . $pp;
                }
            }
        }
    } catch (Throwable $e) {
    }
}
?>
<section id="sidebar">
    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/Dashboard.php" class="brand">
        <img src="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/H.png" alt="Hotel Ser Reposer Et Diner Logo" class="brand-logo" style="width: 48px; height: 48px;">
        <span class="text" style="font-size: 14px; font-weight: 600;">Hotel Ser Reposer Et Diner</span>
    </a>

    <div class="profile-status">
        <div class="profile-info">
            <div class="profile-avatar">
                <div class="profile-circle">
                    <img src="<?= htmlspecialchars($currentUserProfilePic) ?>" alt="Profile Picture">
                    <div class="status-indicator"></div>
                </div>
            </div>
            <div class="profile-details">
                <div class="profile-name"><?= htmlspecialchars($currentUserName) ?></div>
                <div class="profile-role"><?= htmlspecialchars(str_replace('_', ' ', $currentUserRole)) ?></div>
            </div>
        </div>
    </div>

    <?php if ((string)$currentUserRole === 'guest'): ?>
        <ul class="side-menu top">
            <li <?= (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/PHP/guest/index.php') !== false) ? 'class="active"' : '' ?>>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/index.php">
                    <i class='bx bxs-home'></i>
                    <span class="text">Guest Home</span>
                </a>
            </li>
            <li <?= (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/PHP/guest/rooms.php') !== false) ? 'class="active"' : '' ?>>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/rooms.php">
                    <i class='bx bxs-bed'></i>
                    <span class="text">Browse Rooms</span>
                </a>
            </li>
            <li <?= (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/PHP/guest/reservations.php') !== false) ? 'class="active"' : '' ?>>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/reservations.php">
                    <i class='bx bxs-calendar'></i>
                    <span class="text">My Reservations</span>
                </a>
            </li>
            <li <?= (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/PHP/guest/events_conferences.php') !== false) ? 'class="active"' : '' ?>>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/guest/events_conferences.php">
                    <i class='bx bxs-calendar-event'></i>
                    <span class="text">Event &amp; Conference</span>
                </a>
            </li>
            <li <?= (basename($_SERVER['PHP_SELF']) === 'settings.php') ? 'class="active"' : '' ?>>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/settings.php">
                    <i class='bx bxs-cog'></i>
                    <span class="text">Settings</span>
                </a>
            </li>
        </ul>
    <?php else: ?>
        <ul class="side-menu top">
            <li <?= (basename($_SERVER['PHP_SELF']) === 'Dashboard.php') ? 'class="active"' : '' ?>>
                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/Dashboard.php">
                    <i class='bx bxs-dashboard'></i>
                    <span class="text">Dashboard</span>
                </a>
            </li>

            <li class="dropdown">
                <a href="#" class="dropdown-toggle">
                    <i class='bx bxs-hotel'></i>
                    <span class="text">Hotel Core Modules</span>
                    <i class='bx bx-chevron-down arrow'></i>
                </a>
                <ul class="dropdown-menu">
                    <?php foreach ($orderedModules as $m): ?>
                        <li>
                            <a href="<?= htmlspecialchars((string)($m['url'] ?? '#')) ?>">
                                <span class="text"><?= htmlspecialchars((string)($m['label'] ?? 'Module')) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>

                    <?php if (RBACMiddleware::isAdminRole((string)$currentUserRole)): ?>
                        <li>
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/module_manager.php">
                                <span class="text">Module Manager</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </li>
        </ul>
    <?php endif; ?>

    <ul class="side-menu">
        <li>
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/logout.php" class="logout">
                <i class='bx bxs-log-out-circle'></i>
                <span class="text">Logout</span>
            </a>
        </li>
    </ul>
</section>

<style>
    #sidebar .side-menu.top li.dropdown {
        position: relative;
        padding-right: 10px;
    }

    #sidebar .side-menu.top li.dropdown.open {
        z-index: 10;
    }

    #sidebar .side-menu.top li.dropdown .dropdown-menu {
        display: none;
        list-style: none;
        padding: 0;
        margin: 0;
    }

    #sidebar .side-menu.top li.dropdown.open .dropdown-menu {
        display: block;
        position: relative;
        top: auto;
        left: auto;
        width: 100%;
        background: #e8e8e8;
        animation: slideDown 0.3s ease;
        padding: 8px 0;
        box-shadow: 0 2px 5px rgba(0,0,0,0.15);
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    #sidebar .side-menu.top li.dropdown.open .dropdown-menu li a {
        display: block;
        padding: 8px 20px 8px 50px;
        color: #666;
        text-decoration: none;
        font-size: 13px;
    }

    #sidebar {
        overflow-y: auto;
        height: 100vh;
        width: 290px;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');

    document.addEventListener('click', function(e) {
        const toggle = e.target.closest('.dropdown-toggle');

        if (toggle) {
            e.preventDefault();

            const dropdown = toggle.closest('.dropdown');

            document.querySelectorAll('#sidebar .dropdown.open').forEach(function(item) {
                if (item !== dropdown) {
                    item.classList.remove('open');
                }
            });

            dropdown.classList.toggle('open');
        }
    });
});
</script>
