<?php

if (!isset($pendingApprovals)) {
    $pendingApprovals = [];
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';
$APP_BASE_URL = App::baseUrl();

$currentUserProfilePic = $APP_BASE_URL . '/PICTURES/Ser.jpg';

$conn = Database::getConnection();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$hasNotifications = false;
$notificationUnreadCount = 0;
$notificationItems = [];

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

if ($conn && $currentUserId > 0) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'notifications'");
            $hasNotifications = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }

        if ($hasNotifications) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS c
                 FROM notifications
                 WHERE user_id = ? AND is_read = 0"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $currentUserId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $notificationUnreadCount = (int)($row['c'] ?? 0);
            }

            $stmt = $conn->prepare(
                "SELECT id, title, message, url, is_read, created_at
                 FROM notifications
                 WHERE user_id = ? AND is_read = 0
                 ORDER BY id DESC
                 LIMIT 12"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $currentUserId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $notificationItems[] = $row;
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
    }
}

$headerNotificationCount = $hasNotifications ? $notificationUnreadCount : count($pendingApprovals);
$hasNotificationsFallback = !$hasNotifications;
if ($hasNotificationsFallback && empty($pendingApprovals) && $conn) {
    $pendingApprovals = getPendingItems($conn);
    $headerNotificationCount = count($pendingApprovals);
}
$currentUri = (string)($_SERVER['REQUEST_URI'] ?? '');
?>
<div class="popup-overlay" id="popupOverlay"></div>

<nav>
    <i class='bx bx-menu' ></i>
    <a href="#" class="nav-link">Modules</a>
    <form action="#"></form>
    <a href="#" class="notification" id="notificationBtn">
        <i class='bx bxs-bell' ></i>
        <span class="num"><?= (int)$headerNotificationCount ?></span>
    </a>
    <a href="#" class="profile" id="profileBtn">
        <img src="<?= htmlspecialchars($currentUserProfilePic) ?>" alt="Profile">
    </a>

    <div class="notification-dropdown" id="notificationDropdown">
        <div class="header">
            <span>Notifications</span>
            <span><?= (int)$headerNotificationCount ?></span>
        </div>
        <div class="notification-list">
            <?php if ($hasNotifications): ?>
                <?php if (empty($notificationItems)): ?>
                    <div class="empty-state">
                        <i class='bx bx-bell-off' style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>No new notifications</p>
                    </div>
                <?php else: ?>
                    <div style="padding: 10px 20px; border-bottom: 1px solid #f0f0f0;">
                        <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/notifications_mark_all_read.php?redirect=<?= urlencode($currentUri) ?>" style="font-size: 12px; color: #2c3e50; text-decoration: none;">Mark all as read</a>
                    </div>
                    <?php foreach ($notificationItems as $n): ?>
                        <?php
                            $nid = (int)($n['id'] ?? 0);
                            $nUrl = trim((string)($n['url'] ?? ''));
                            if ($nUrl === '') {
                                $nUrl = '#';
                            } elseif (substr($nUrl, 0, 1) === '/') {
                                $nUrl = $APP_BASE_URL . $nUrl;
                            }
                            $markReadUrl = $APP_BASE_URL . '/PHP/notifications_mark_read.php?id=' . $nid . '&redirect=' . urlencode($currentUri) . '&go=' . urlencode($nUrl);
                            $isRead = (int)($n['is_read'] ?? 0) === 1;
                        ?>
                        <a href="<?= htmlspecialchars($markReadUrl) ?>" class="notification-item" style="<?= $isRead ? 'opacity:0.75;' : '' ?>">
                            <div class="title"><?= htmlspecialchars((string)($n['title'] ?? 'Notification')) ?></div>
                            <div class="desc"><?= htmlspecialchars((string)($n['message'] ?? '')) ?></div>
                            <div class="time"><?= htmlspecialchars((string)($n['created_at'] ?? '')) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <?php if (empty($pendingApprovals)): ?>
                    <div class="empty-state">
                        <i class='bx bx-bell-off' style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>No new notifications</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pendingApprovals as $item): ?>
                    <a href="<?= htmlspecialchars((string)($item['url'] ?? '#')) ?>" class="notification-item">
                        <div class="title"><?= htmlspecialchars($item['type'] ?? 'Item') ?></div>
                        <div class="desc"><?= htmlspecialchars($item['name'] ?? '') ?></div>
                        <div class="time"><?= htmlspecialchars($item['created_at'] ?? '') ?></div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="profile-dropdown" id="profileDropdown">
        <div class="profile-header">
            <img src="<?= htmlspecialchars($currentUserProfilePic) ?>" alt="Profile">
            <div class="name"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></div>
            <div class="role"><?= htmlspecialchars($_SESSION['role'] ?? 'staff') ?></div>
        </div>
        <div class="profile-menu">
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/settings.php">
                <i class='bx bx-cog'></i>
                <span>Settings</span>
            </a>
            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/logout.php" class="logout">
                <i class='bx bx-log-out'></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</nav>

<script>
    const notificationBtn = document.getElementById('notificationBtn');
    const profileBtn = document.getElementById('profileBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const profileDropdown = document.getElementById('profileDropdown');
    const popupOverlay = document.getElementById('popupOverlay');

    if (notificationBtn) {
        notificationBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            profileDropdown.classList.remove('active');
            notificationDropdown.classList.toggle('active');
            popupOverlay.classList.toggle('active');
        });
    }

    if (profileBtn) {
        profileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            notificationDropdown.classList.remove('active');
            profileDropdown.classList.toggle('active');
            popupOverlay.classList.toggle('active');
        });
    }

    if (popupOverlay) {
        popupOverlay.addEventListener('click', function() {
            notificationDropdown.classList.remove('active');
            profileDropdown.classList.remove('active');
            popupOverlay.classList.remove('active');
        });
    }

    document.addEventListener('click', function(e) {
        if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target) &&
            !profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
            notificationDropdown.classList.remove('active');
            profileDropdown.classList.remove('active');
            popupOverlay.classList.remove('active');
        }
    });

    notificationDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    profileDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    (function () {
        var items = document.querySelectorAll('#notificationDropdown .notification-item');
        if (!items.length) return;

        function decCounters() {
            var num = document.querySelector('#notificationBtn .num');
            var headerCount = document.querySelector('#notificationDropdown .header span:last-child');
            if (num) {
                var n = parseInt(num.textContent || '0', 10);
                if (isFinite(n) && n > 0) {
                    num.textContent = String(n - 1);
                }
            }
            if (headerCount) {
                var n2 = parseInt(headerCount.textContent || '0', 10);
                if (isFinite(n2) && n2 > 0) {
                    headerCount.textContent = String(n2 - 1);
                }
            }
        }

        items.forEach(function (a) {
            a.addEventListener('click', function () {
                if (a.dataset.reading === '1') return;
                a.dataset.reading = '1';
                a.style.opacity = '0.5';
                decCounters();
                window.setTimeout(function () {
                    if (a && a.parentNode) {
                        a.parentNode.removeChild(a);
                    }
                }, 50);
            });
        });
    })();
</script>
