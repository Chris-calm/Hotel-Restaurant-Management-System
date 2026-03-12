<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../core/bootstrap.php';
$APP_BASE_URL = App::baseUrl();

$currentUserProfilePic = $APP_BASE_URL . '/PICTURES/Ser.jpg';
$currentUserName = $_SESSION['username'] ?? 'User';
$currentUserRole = $_SESSION['role'] ?? 'staff';

$conn = Database::getConnection();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
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
                $currentUserProfilePic = (substr($pp, 0, 1) === '/') ? ($APP_BASE_URL . $pp) : $pp;
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
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/front_desk.php"><span class="text">Front Desk & Reception</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/reservations.php"><span class="text">Reservation & Booking</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/rooms/index.php"><span class="text">Rooms & Room Types</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/housekeeping_maintenance.php"><span class="text">Housekeeping & Maintenance</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/guests/index.php"><span class="text">Guests (CRM)</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/rooms/locks.php"><span class="text">Door Lock Integration</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/channel_management.php"><span class="text">Channel Management</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/marketing_promotions.php"><span class="text">Marketing & Promotions</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/analytics_reporting.php"><span class="text">Analytics & Reporting</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/events_conferences.php"><span class="text">Events & Conferences</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php"><span class="text">Billing & Payments</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/pos.php"><span class="text">Point of Sale (POS)</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/inventory_stock.php"><span class="text">Inventory & Stock</span></a></li>
                <li><a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/loyalty_rewards.php"><span class="text">Loyalty & Rewards</span></a></li>
            </ul>
        </li>
    </ul>

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
        position: absolute;
        top: 100%;
        left: 0;
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

            if (document.querySelector('#sidebar .dropdown.open')) {
                sidebar.style.overflowY = 'hidden';
            } else {
                sidebar.style.overflowY = 'auto';
            }
        }
    });
});
</script>
