<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$pendingApprovals = [];

$pageTitle = 'Analytics & Reporting - Hotel Management System';
$extraHeadHtml = <<<'HTML'
<style>
    #content {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    #content::-webkit-scrollbar { display: none; width: 0; height: 0; }
    main {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    main::-webkit-scrollbar { display: none; width: 0; height: 0; }
    * { scrollbar-width: none; -ms-overflow-style: none; }
    *::-webkit-scrollbar { display: none; width: 0; height: 0; }

    .dashboard-card {
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
    }
    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
        transition: left 0.6s ease;
    }
    .dashboard-card:hover::before { left: 100%; }
    .dashboard-card:hover {
        transform: translateY(-6px) scale(1.01);
        box-shadow: 0 18px 36px rgba(0,0,0,0.08);
        border-color: #3b82f6;
    }
    .dashboard-card .icon-container { transition: all 0.4s ease; }
    .dashboard-card:hover .icon-container {
        transform: scale(1.08) rotate(4deg);
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    }
    .dashboard-card:hover .icon-container i { color: white !important; }

    .chart-container {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 24px;
        transition: all 0.4s ease;
    }
    .chart-container:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        border-color: #3b82f6;
    }
</style>
HTML;

$today = date('Y-m-d');
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime('-' . $i . ' day'));
}

$totalRooms = 0;
$todayOccupiedRooms = 0;
$todayOccupancyPct = 0.0;
$todayReservations = 0;
$monthlyReservations = 0;
$roomRevenueMonth = 0.0;
$posRevenueMonth = 0.0;
$discountsMonth = 0.0;

$occupancySeries = [];
$occupancyLabels = [];

$salesLabels = ['Rooms', 'Restaurant (POS)', 'Discounts'];
$salesSeries = [0, 0, 0];

$hasRealData = false;

$dbName = '';
$hasPosOrdersTable = false;
$hasReservationDiscountColumn = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $dbName = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $dbName = $conn->real_escape_string($dbName);
        if ($dbName !== '') {
            $res = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'pos_orders'");
            $hasPosOrdersTable = $res ? ((int)($res->fetch_assoc()['c'] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'reservations' AND COLUMN_NAME = 'discount_amount'");
            $hasReservationDiscountColumn = $res ? ((int)($res->fetch_assoc()['c'] ?? 0) === 1) : false;
        }
    } catch (Throwable $e) {
        $dbName = '';
        $hasPosOrdersTable = false;
        $hasReservationDiscountColumn = false;
    }
}

if ($conn) {
    try {
        $res = $conn->query("SELECT COUNT(*) AS c FROM rooms");
        $totalRooms = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;

        $res = $conn->query("SELECT COUNT(*) AS c FROM reservations WHERE DATE(created_at) = CURDATE()");
        $todayReservations = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;

        $res = $conn->query("SELECT COUNT(*) AS c FROM reservations WHERE DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')");
        $monthlyReservations = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;

        if ($totalRooms > 0) {
            $stmt = $conn->prepare(
                "SELECT COUNT(DISTINCT rr.room_id) AS c
                 FROM reservations r
                 INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                 WHERE r.status IN ('Confirmed','Upcoming','Checked In')
                   AND r.checkin_date <= ?
                   AND r.checkout_date > ?
                   AND rr.room_id IS NOT NULL"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $today, $today);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $todayOccupiedRooms = (int)($row['c'] ?? 0);
                $stmt->close();
            }
            $todayOccupancyPct = $totalRooms > 0 ? round(($todayOccupiedRooms / $totalRooms) * 100, 1) : 0.0;
        }

        $res = $conn->query(
            $hasReservationDiscountColumn
                ? "SELECT COALESCE(SUM(r.discount_amount),0) AS discounts
                   FROM reservations r
                   WHERE r.status NOT IN ('Cancelled','No Show')
                     AND DATE_FORMAT(r.created_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"
                : "SELECT 0 AS discounts"
        );
        $discountsMonth = $res ? (float)($res->fetch_assoc()['discounts'] ?? 0) : 0.0;

        $res = $conn->query(
            $hasReservationDiscountColumn
                ? "SELECT COALESCE(SUM((DATEDIFF(r.checkout_date, r.checkin_date) * rr.rate) - COALESCE(r.discount_amount,0)),0) AS revenue
                   FROM reservations r
                   INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                   WHERE r.status NOT IN ('Cancelled','No Show')
                     AND DATE_FORMAT(r.created_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"
                : "SELECT COALESCE(SUM((DATEDIFF(r.checkout_date, r.checkin_date) * rr.rate)),0) AS revenue
                   FROM reservations r
                   INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                   WHERE r.status NOT IN ('Cancelled','No Show')
                     AND DATE_FORMAT(r.created_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"
        );
        $roomRevenueMonth = $res ? (float)($res->fetch_assoc()['revenue'] ?? 0) : 0.0;

        if ($hasPosOrdersTable) {
            $res = $conn->query(
                "SELECT COALESCE(SUM(total),0) AS revenue
                 FROM pos_orders
                 WHERE status = 'Paid'
                   AND DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"
            );
            $posRevenueMonth = $res ? (float)($res->fetch_assoc()['revenue'] ?? 0) : 0.0;
        } else {
            $posRevenueMonth = 0.0;
        }

        foreach ($days as $d) {
            $label = date('D', strtotime($d));
            $occupancyLabels[] = $label;
            if ($totalRooms <= 0) {
                $occupancySeries[] = 0;
                continue;
            }
            $occ = 0;
            $stmt = $conn->prepare(
                "SELECT COUNT(DISTINCT rr.room_id) AS c
                 FROM reservations r
                 INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                 WHERE r.status IN ('Confirmed','Upcoming','Checked In')
                   AND r.checkin_date <= ?
                   AND r.checkout_date > ?
                   AND rr.room_id IS NOT NULL"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $d, $d);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $occ = (int)($row['c'] ?? 0);
                $stmt->close();
            }
            $occupancySeries[] = (float)round(($occ / $totalRooms) * 100, 1);
        }

        $salesSeries = [
            (float)round($roomRevenueMonth, 2),
            (float)round($posRevenueMonth, 2),
            (float)round($discountsMonth, 2)
        ];

        $hasRealData = true;
    } catch (Throwable $e) {
    }
}

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Analytics & Reporting</h1>
            <p class="text-sm text-gray-500 mt-1">KPIs, revenue, occupancy, restaurant sales reports</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Today Occupancy</p>
                        <p class="text-2xl font-light text-gray-900"><?= number_format((float)$todayOccupancyPct, 1) ?>%</p>
                        <p class="text-xs text-gray-500 mt-1"><?= (int)$todayOccupiedRooms ?> / <?= (int)$totalRooms ?> rooms</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-bed text-blue-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Reservations Today</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$todayReservations ?></p>
                        <p class="text-xs text-gray-500 mt-1">Created today</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                        <i class='bx bxs-calendar-check text-orange-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Room Revenue (Month)</p>
                        <p class="text-2xl font-light text-gray-900">₱<?= number_format((float)$roomRevenueMonth, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= (int)$monthlyReservations ?> reservations</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-money text-green-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">POS Revenue (Month)</p>
                        <p class="text-2xl font-light text-gray-900">₱<?= number_format((float)$posRevenueMonth, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Paid orders</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center">
                        <i class='bx bxs-receipt text-purple-600 text-xl'></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="chart-container">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-medium text-gray-900">Occupancy</h3>
                    <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-line-chart text-blue-600'></i>
                    </div>
                </div>
                <div style="height: 200px;">
                    <canvas id="occupancyChart"></canvas>
                </div>
            </div>

            <div class="chart-container">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-medium text-gray-900">Revenue (Month)</h3>
                    <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-bar-chart-alt-2 text-green-600'></i>
                    </div>
                </div>
                <div style="height: 200px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-medium text-gray-900">Promo Discounts (Month)</h3>
                    <div class="w-10 h-10 bg-yellow-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-purchase-tag text-yellow-700'></i>
                    </div>
                </div>
                <div class="text-2xl font-light text-gray-900">₱<?= number_format((float)$discountsMonth, 2) ?></div>
                <div class="text-sm text-gray-500 mt-1">Total discount applied to reservations</div>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="text-sm text-gray-500">Tip: Occupancy is computed from reservations with status Confirmed/Upcoming/Checked In and date overlap.</div>
            </div>
        </div>
    </main>
</section>
<script>
    const occupancyChart = document.getElementById('occupancyChart');
    if (occupancyChart) {
        new Chart(occupancyChart, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_values($occupancyLabels)) ?>,
                datasets: [{
                    label: 'Occupancy',
                    data: <?= json_encode(array_values($occupancySeries)) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: 100,
                        ticks: { callback: (v) => v + '%' },
                        grid: { color: '#f3f4f6' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    const salesChart = document.getElementById('salesChart');
    if (salesChart) {
        new Chart(salesChart, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_values($salesLabels)) ?>,
                datasets: [{
                    label: 'Sales',
                    data: <?= json_encode(array_values($salesSeries)) ?>,
                    backgroundColor: ['rgba(34, 197, 94, 0.85)', 'rgba(59, 130, 246, 0.85)', 'rgba(249, 115, 22, 0.85)'],
                    borderColor: ['#22c55e', '#3b82f6', '#f97316'],
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f3f4f6' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }
</script>
<?php include __DIR__ . '/../partials/page_end.php';
