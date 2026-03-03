<?php

if (!isset($pendingApprovals)) {
    $pendingApprovals = [];
}

require_once __DIR__ . '/../core/bootstrap.php';
$APP_BASE_URL = App::baseUrl();

$currentUserProfilePic = $APP_BASE_URL . '/PICTURES/Ser.jpg';
?>
<div class="popup-overlay" id="popupOverlay"></div>

<nav>
    <i class='bx bx-menu' ></i>
    <a href="#" class="nav-link">Modules</a>
    <form action="#"></form>
    <a href="#" class="notification" id="notificationBtn">
        <i class='bx bxs-bell' ></i>
        <span class="num"><?= count($pendingApprovals) ?></span>
    </a>
    <a href="#" class="profile" id="profileBtn">
        <img src="<?= htmlspecialchars($currentUserProfilePic) ?>" alt="Profile">
    </a>

    <div class="notification-dropdown" id="notificationDropdown">
        <div class="header">
            <span>Notifications</span>
            <span><?= count($pendingApprovals) ?></span>
        </div>
        <div class="notification-list">
            <?php if (empty($pendingApprovals)): ?>
                <div class="empty-state">
                    <i class='bx bx-bell-off' style="font-size: 48px; margin-bottom: 10px;"></i>
                    <p>No new notifications</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendingApprovals as $item): ?>
                <a href="#" class="notification-item">
                    <div class="title"><?= htmlspecialchars($item['type'] ?? 'Item') ?></div>
                    <div class="desc"><?= htmlspecialchars($item['name'] ?? '') ?></div>
                    <div class="time"><?= htmlspecialchars($item['created_at'] ?? '') ?></div>
                </a>
                <?php endforeach; ?>
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
            <a href="#">
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
</script>
