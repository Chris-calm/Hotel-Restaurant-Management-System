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

$sanitizeDate = static function (string $v): string {
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return '';
    }
    return $v;
};

$today = date('Y-m-d');

$rangeStart = $sanitizeDate((string)Request::get('start_date', date('Y-m-d', strtotime('-29 days'))));
$rangeEnd = $sanitizeDate((string)Request::get('end_date', $today));

$errors = [];
if ($rangeStart === '' || $rangeEnd === '') {
    $errors[] = 'Invalid date range.';
} else {
    $t1 = strtotime($rangeStart);
    $t2 = strtotime($rangeEnd);
    if ($t1 === false || $t2 === false || $t2 < $t1) {
        $errors[] = 'Invalid date range.';
    } else {
        $maxDays = 366;
        $rangeDays = (int)floor(($t2 - $t1) / 86400) + 1;
        if ($rangeDays > $maxDays) {
            $errors[] = 'Date range too long. Max 366 days.';
        }
    }
}

$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime($rangeEnd . ' -' . $i . ' day'));
}

$totalRooms = 0;
$todayOccupiedRooms = 0;
$todayOccupancyPct = 0.0;
$todayReservations = 0;
$rangeReservations = 0;
$avgOccupancyPctRange = 0.0;
$roomRevenueRange = 0.0;
$posRevenueRange = 0.0;
$discountsRange = 0.0;

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

if ($conn && empty($errors)) {
    try {
        $res = $conn->query("SELECT COUNT(*) AS c FROM rooms");
        $totalRooms = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;

        $res = $conn->query("SELECT COUNT(*) AS c FROM reservations WHERE DATE(created_at) = CURDATE()");
        $todayReservations = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;

        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM reservations WHERE DATE(created_at) BETWEEN ? AND ?");
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ss', $rangeStart, $rangeEnd);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $rangeReservations = (int)($row['c'] ?? 0);
            $stmt->close();
        }

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
                $stmt->bind_param('ss', $rangeEnd, $rangeEnd);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $todayOccupiedRooms = (int)($row['c'] ?? 0);
                $stmt->close();
            }
            $todayOccupancyPct = $totalRooms > 0 ? round(($todayOccupiedRooms / $totalRooms) * 100, 1) : 0.0;
        }

        $t1 = strtotime($rangeStart);
        $t2 = strtotime($rangeEnd);
        $rangeDays = 0;
        if ($t1 !== false && $t2 !== false && $t2 >= $t1) {
            $rangeDays = (int)floor(($t2 - $t1) / 86400) + 1;
        }
        if ($totalRooms > 0 && $rangeDays > 0) {
            $endPlus1 = date('Y-m-d', strtotime($rangeEnd . ' +1 day'));
            $stmt = $conn->prepare(
                "SELECT COALESCE(SUM(
                        GREATEST(
                            0,
                            DATEDIFF(
                                LEAST(r.checkout_date, ?),
                                GREATEST(r.checkin_date, ?)
                            )
                        )
                    ), 0) AS occupied_room_nights
                 FROM reservations r
                 INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                 WHERE r.status IN ('Confirmed','Upcoming','Checked In')
                   AND r.checkin_date < ?
                   AND r.checkout_date > ?
                   AND rr.room_id IS NOT NULL"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ssss', $endPlus1, $rangeStart, $endPlus1, $rangeStart);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $occupiedRoomNights = (float)($row['occupied_room_nights'] ?? 0);
                $stmt->close();

                $denom = (float)($totalRooms * $rangeDays);
                if ($denom > 0) {
                    $avgOccupancyPctRange = round(($occupiedRoomNights / $denom) * 100, 1);
                }
            }
        }

        if ($hasReservationDiscountColumn) {
            $stmt = $conn->prepare(
                "SELECT COALESCE(SUM(r.discount_amount),0) AS discounts
                 FROM reservations r
                 WHERE r.status NOT IN ('Cancelled','No Show')
                   AND DATE(r.created_at) BETWEEN ? AND ?"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $rangeStart, $rangeEnd);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $discountsRange = (float)($row['discounts'] ?? 0);
                $stmt->close();
            }
        }

        if ($hasReservationDiscountColumn) {
            $stmt = $conn->prepare(
                "SELECT COALESCE(SUM((DATEDIFF(r.checkout_date, r.checkin_date) * rr.rate) - COALESCE(r.discount_amount,0)),0) AS revenue
                 FROM reservations r
                 INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                 WHERE r.status NOT IN ('Cancelled','No Show')
                   AND DATE(r.created_at) BETWEEN ? AND ?"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT COALESCE(SUM((DATEDIFF(r.checkout_date, r.checkin_date) * rr.rate)),0) AS revenue
                 FROM reservations r
                 INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
                 WHERE r.status NOT IN ('Cancelled','No Show')
                   AND DATE(r.created_at) BETWEEN ? AND ?"
            );
        }
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ss', $rangeStart, $rangeEnd);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $roomRevenueRange = (float)($row['revenue'] ?? 0);
            $stmt->close();
        }

        if ($hasPosOrdersTable) {
            $stmt = $conn->prepare(
                "SELECT COALESCE(SUM(total),0) AS revenue
                 FROM pos_orders
                 WHERE status = 'Paid'
                   AND DATE(created_at) BETWEEN ? AND ?"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $rangeStart, $rangeEnd);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $posRevenueRange = (float)($row['revenue'] ?? 0);
                $stmt->close();
            }
        } else {
            $posRevenueRange = 0.0;
        }

        $occStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT rr.room_id) AS c
             FROM reservations r
             INNER JOIN reservation_rooms rr ON rr.reservation_id = r.id
             WHERE r.status IN ('Confirmed','Upcoming','Checked In')
               AND r.checkin_date <= ?
               AND r.checkout_date > ?
               AND rr.room_id IS NOT NULL"
        );

        foreach ($days as $d) {
            $label = date('D', strtotime($d));
            $occupancyLabels[] = $label;
            if ($totalRooms <= 0) {
                $occupancySeries[] = 0;
                continue;
            }
            $occ = 0;
            if ($occStmt instanceof mysqli_stmt) {
                $occStmt->bind_param('ss', $d, $d);
                $occStmt->execute();
                $row = $occStmt->get_result()->fetch_assoc();
                $occ = (int)($row['c'] ?? 0);
            }
            $occupancySeries[] = (float)round(($occ / $totalRooms) * 100, 1);
        }

        if ($occStmt instanceof mysqli_stmt) {
            $occStmt->close();
        }

        $salesSeries = [
            (float)round($roomRevenueRange, 2),
            (float)round($posRevenueRange, 2),
            (float)round($discountsRange, 2)
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

        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="font-medium mb-1">Invalid filters</div>
                <?php foreach ($errors as $msg): ?>
                    <div><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 bg-white rounded-lg border border-gray-100 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">Filters</h3>
                    <div class="text-sm text-gray-500 mt-1">Revenue and reservations are computed by reservation <span class="font-medium">created_at</span> inside the selected range.</div>
                </div>
            </div>
            <form method="get" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($rangeStart) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($rangeEnd) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </div>
                <div class="flex items-end gap-2">
                    <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Apply</button>
                    <a href="analytics_reporting.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Occupancy (End Date)</p>
                        <p class="text-2xl font-light text-gray-900"><?= number_format((float)$todayOccupancyPct, 1) ?>%</p>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($rangeEnd) ?> • <?= (int)$todayOccupiedRooms ?> / <?= (int)$totalRooms ?> rooms</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-bed text-blue-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Average Occupancy (Range)</p>
                        <p class="text-2xl font-light text-gray-900"><?= number_format((float)$avgOccupancyPctRange, 1) ?>%</p>
                        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($rangeStart) ?> → <?= htmlspecialchars($rangeEnd) ?></p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-sky-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-stats text-sky-600 text-xl'></i>
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
                        <p class="text-2xl font-light text-gray-900">₱<?= number_format((float)$roomRevenueRange, 2) ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= (int)$rangeReservations ?> reservations</p>
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
                        <p class="text-2xl font-light text-gray-900">₱<?= number_format((float)$posRevenueRange, 2) ?></p>
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
                <div class="text-2xl font-light text-gray-900">₱<?= number_format((float)$discountsRange, 2) ?></div>
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
