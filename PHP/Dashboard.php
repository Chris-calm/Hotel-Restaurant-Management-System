<?php
require_once __DIR__ . '/rbac_middleware.php';
RBACMiddleware::checkPageAccess();

include __DIR__ . '/db_connect.php';
include __DIR__ . '/partials/functions.php';

$pendingApprovals = [];

$pageTitle = 'Dashboard - Hotel Management System';
$extraHeadHtml = <<<'HTML'
<style>
    body { overflow-x: hidden; }

    #content {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    #content::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    main {
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    main::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    * {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    *::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

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
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        border-color: #3b82f6;
    }

    .dashboard-card .icon-container { transition: all 0.4s ease; }

    .dashboard-card:hover .icon-container {
        transform: scale(1.1) rotate(5deg);
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

    .activity-item {
        transition: all 0.3s ease;
        border-radius: 8px;
        padding: 12px;
    }

    .activity-item:hover {
        background: #f8fafc;
        transform: translateX(4px);
    }

    .activity-dot { animation: pulse 2s infinite; }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    .dashboard-section { margin-bottom: 3rem; }

    .system-overview-grid { gap: 1rem; }

    .overview-card {
        padding: 2rem;
        margin-bottom: 1.5rem;
    }

    .main-stats { margin-bottom: 2rem; }

    .system-data-overview { margin-bottom: 2rem; }

    .bottom-sections { margin-top: 1rem; }

    .loading-shimmer {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200% 100%;
        animation: shimmer 1.5s infinite;
    }

    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
</style>
HTML;

$totalReservations = getTotalCount($conn, 'reservations');
$totalGuests = getTotalCount($conn, 'guests');
$totalOrders = getTotalCount($conn, 'pos_orders');
$totalInventoryItems = getTotalCount($conn, 'inventory_items');

$todayReservations = 0;
$upcomingReservations = 0;
$cancelledReservations = 0;
$checkedInGuests = 0;
$arrivalsToday = 0;
$departuresToday = 0;
$posOrdersToday = 0;
$openTabs = 0;
$voidedOrders = 0;
$lowStockItems = 0;
$outOfStockItems = 0;
$inStockItems = 0;

$recentReservations = [];

$reservationTrend = [3, 7, 5, 10, 8, 12];
$restaurantActivity = [12, 9, 4];

$pendingApprovals = [];

if ($conn) {
    try {
        $result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(created_at) = CURDATE()");
        $todayReservations = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status IN ('Upcoming','Confirmed')");
        $upcomingReservations = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status IN ('Cancelled','No Show')");
        $cancelledReservations = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM guests WHERE status = 'Checked In'");
        $checkedInGuests = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(checkin_date) = CURDATE()");
        $arrivalsToday = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(checkout_date) = CURDATE()");
        $departuresToday = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM pos_orders WHERE DATE(created_at) = CURDATE()");
        $posOrdersToday = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM pos_orders WHERE status = 'Open'");
        $openTabs = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM pos_orders WHERE status = 'Voided'");
        $voidedOrders = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM inventory_items WHERE quantity <= reorder_level");
        $lowStockItems = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM inventory_items WHERE quantity <= 0");
        $outOfStockItems = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT COUNT(*) as count FROM inventory_items WHERE quantity > 0");
        $inStockItems = $result ? (int)($result->fetch_assoc()['count'] ?? 0) : 0;

        $result = $conn->query("SELECT reference_no, guest_name, status, created_at FROM reservations ORDER BY created_at DESC LIMIT 5");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $recentReservations[] = $row;
            }
        }
    } catch (Throwable $e) {
    }
}

$hasRealData = ($totalReservations + $totalGuests + $totalOrders + $totalInventoryItems) > 0;
if (!$conn || !$hasRealData) {
    $totalReservations = 128;
    $totalGuests = 342;
    $totalOrders = 514;
    $totalInventoryItems = 96;

    $todayReservations = 9;
    $upcomingReservations = 26;
    $cancelledReservations = 2;

    $checkedInGuests = 38;
    $arrivalsToday = 12;
    $departuresToday = 7;

    $posOrdersToday = 41;
    $openTabs = 6;
    $voidedOrders = 1;

    $lowStockItems = 8;
    $outOfStockItems = 3;
    $inStockItems = 85;

    $reservationTrend = [6, 11, 8, 14, 12, 18];
    $restaurantActivity = [41, 32, 8];

    $recentReservations = [
        ['reference_no' => 'RES-2026-0012', 'guest_name' => 'Juan Dela Cruz', 'status' => 'Confirmed', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['reference_no' => 'RES-2026-0011', 'guest_name' => 'Maria Santos', 'status' => 'Upcoming', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 day'))],
        ['reference_no' => 'RES-2026-0010', 'guest_name' => 'John Doe', 'status' => 'Checked In', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 day'))],
        ['reference_no' => 'RES-2026-0009', 'guest_name' => 'Jane Smith', 'status' => 'Completed', 'created_at' => date('Y-m-d H:i:s', strtotime('-4 day'))],
        ['reference_no' => 'RES-2026-0008', 'guest_name' => 'Alex Reyes', 'status' => 'Cancelled', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 day'))],
    ];

    $pendingApprovals = [
        ['type' => 'Reservation Override', 'name' => 'Rate adjustment request', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
        ['type' => 'Inventory Purchase', 'name' => 'Supplier PO for kitchen items', 'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))],
        ['type' => 'Maintenance', 'name' => 'Room 304 AC repair approval', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
    ];
}

include __DIR__ . '/partials/page_start.php';
include __DIR__ . '/partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Overview of your Hotel Management System</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 main-stats">
            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Reservations</p>
                        <p class="text-2xl font-light text-gray-900 counter" data-target="<?= (int)$totalReservations ?>">0</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                        <i class='bx bxs-calendar-check text-orange-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Guests</p>
                        <p class="text-2xl font-light text-gray-900 counter" data-target="<?= (int)$totalGuests ?>">0</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bxs-user text-blue-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">POS Orders</p>
                        <p class="text-2xl font-light text-gray-900 counter" data-target="<?= (int)$totalOrders ?>">0</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bxs-receipt text-green-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Inventory Items</p>
                        <p class="text-2xl font-light text-gray-900 counter" data-target="<?= (int)$totalInventoryItems ?>">0</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center">
                        <i class='bx bxs-box text-purple-600 text-xl'></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="system-data-overview">
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-900">System Data Overview</h2>
                <p class="text-sm text-gray-500 mt-1">Detailed breakdown of hotel and restaurant activity</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 system-overview-grid">
                <div class="bg-white rounded-lg border border-gray-100 overview-card">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Reservations</h3>
                        <div class="w-10 h-10 bg-orange-50 rounded-lg flex items-center justify-center">
                            <i class='bx bxs-calendar-check text-orange-600'></i>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Today</span>
                            <span class="text-sm font-medium text-gray-900"><?= (int)$todayReservations ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Upcoming</span>
                            <span class="text-sm font-medium text-blue-600"><?= (int)$upcomingReservations ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Cancelled</span>
                            <span class="text-sm font-medium text-red-600"><?= (int)$cancelledReservations ?></span>
                        </div>
                        <div class="pt-2 border-t border-gray-100">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-gray-700">Total</span>
                                <span class="text-lg font-semibold text-gray-900"><?= (int)$totalReservations ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-100 overview-card">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Guests</h3>
                        <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                            <i class='bx bxs-user text-blue-600'></i>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Checked In</span>
                            <span class="text-sm font-medium text-blue-600"><?= (int)$checkedInGuests ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Arrivals Today</span>
                            <span class="text-sm font-medium text-green-600"><?= (int)$arrivalsToday ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Departures Today</span>
                            <span class="text-sm font-medium text-yellow-600"><?= (int)$departuresToday ?></span>
                        </div>
                        <div class="pt-2 border-t border-gray-100">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-gray-700">Total</span>
                                <span class="text-lg font-semibold text-gray-900"><?= (int)$totalGuests ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-100 overview-card">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">POS</h3>
                        <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center">
                            <i class='bx bxs-receipt text-green-600'></i>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Orders Today</span>
                            <span class="text-sm font-medium text-green-600"><?= (int)$posOrdersToday ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Open Tabs</span>
                            <span class="text-sm font-medium text-blue-600"><?= (int)$openTabs ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Voided</span>
                            <span class="text-sm font-medium text-red-600"><?= (int)$voidedOrders ?></span>
                        </div>
                        <div class="pt-2 border-t border-gray-100">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-gray-700">Total Orders</span>
                                <span class="text-lg font-semibold text-gray-900"><?= (int)$totalOrders ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg border border-gray-100 overview-card">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Inventory</h3>
                        <div class="w-10 h-10 bg-purple-50 rounded-lg flex items-center justify-center">
                            <i class='bx bxs-box text-purple-600'></i>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Low Stock</span>
                            <span class="text-sm font-medium text-purple-600"><?= (int)$lowStockItems ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">Out of Stock</span>
                            <span class="text-sm font-medium text-red-600"><?= (int)$outOfStockItems ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">In Stock</span>
                            <span class="text-sm font-medium text-green-600"><?= (int)$inStockItems ?></span>
                        </div>
                        <div class="pt-2 border-t border-gray-100">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-gray-700">Total Items</span>
                                <span class="text-lg font-semibold text-gray-900"><?= (int)$totalInventoryItems ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="chart-container">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-medium text-gray-900">Reservation Trends</h3>
                    <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-line-chart text-blue-600'></i>
                    </div>
                </div>
                <div style="height: 200px;">
                    <canvas id="reservationChart"></canvas>
                </div>
            </div>

            <div class="chart-container">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-medium text-gray-900">System Activity</h3>
                    <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-bar-chart-alt-2 text-green-600'></i>
                    </div>
                </div>
                <div style="height: 200px;">
                    <canvas id="activityChart"></canvas>
                </div>
            </div>
        </div>

        <div class="mb-6">
            <div class="bg-white rounded-lg border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">Recent Activity</h3>
                        <div class="activity-dot w-3 h-3 bg-green-500 rounded-full"></div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <div class="activity-item flex items-start space-x-3">
                            <div class="w-8 h-8 bg-orange-50 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class='bx bx-calendar text-orange-600 text-sm'></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">Reservations module ready</p>
                                <p class="text-xs text-gray-500">Placeholder content</p>
                            </div>
                        </div>
                        <div class="activity-item flex items-start space-x-3">
                            <div class="w-8 h-8 bg-green-50 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class='bx bx-receipt text-green-600 text-sm'></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">POS module ready</p>
                                <p class="text-xs text-gray-500">Placeholder content</p>
                            </div>
                        </div>
                        <div class="activity-item flex items-start space-x-3">
                            <div class="w-8 h-8 bg-blue-50 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class='bx bx-home-alt text-blue-600 text-sm'></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">Front Desk module ready</p>
                                <p class="text-xs text-gray-500">Placeholder content</p>
                            </div>
                        </div>
                        <div class="activity-item flex items-start space-x-3">
                            <div class="w-8 h-8 bg-purple-50 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class='bx bx-box text-purple-600 text-sm'></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">Inventory module ready</p>
                                <p class="text-xs text-gray-500">Placeholder content</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 bottom-sections">
            <div class="bg-white rounded-lg border border-gray-100 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-medium text-gray-900">Recent Reservations</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guest</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($recentReservations)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">No recent reservations found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentReservations as $res): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($res['reference_no'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($res['guest_name'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($res['status'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= !empty($res['created_at']) ? date('M j, Y', strtotime($res['created_at'])) : 'N/A' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-medium text-gray-900">Pending Approvals</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($pendingApprovals)): ?>
                        <div class="text-center py-8">
                            <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class='bx bx-check-circle text-green-600 text-2xl'></i>
                            </div>
                            <p class="text-gray-500 font-medium">All caught up!</p>
                            <p class="text-sm text-gray-400 mt-1">No pending approvals</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($pendingApprovals as $item): ?>
                                <a href="#" class="block p-4 rounded-lg border border-gray-100 hover:border-gray-200 hover:bg-gray-50 transition-colors group">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium text-gray-900"><?= htmlspecialchars($item['type'] ?? 'Item') ?></p>
                                            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($item['name'] ?? '') ?></p>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</section>

<?php include __DIR__ . '/partials/success_modal.php'; ?>

<script>
    function animateCounter(element, target, duration = 1200) {
        const start = 0;
        const increment = target / (duration / 16);
        let current = start;

        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            element.textContent = Math.floor(current);
        }, 16);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const counters = document.querySelectorAll('.counter');
        counters.forEach((counter, index) => {
            const target = parseInt(counter.getAttribute('data-target') || '0', 10);
            setTimeout(() => animateCounter(counter, target), index * 120);
        });
    });

    const reservationChart = document.getElementById('reservationChart');
    if (reservationChart) {
        new Chart(reservationChart, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Reservations',
                    data: <?= json_encode(array_values($reservationTrend)) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }

    const activityChart = document.getElementById('activityChart');
    if (activityChart) {
        new Chart(activityChart, {
            type: 'bar',
            data: {
                labels: ['Reservations', 'Guests', 'POS', 'Inventory'],
                datasets: [{
                    label: 'Activity',
                    data: [
                        <?= (int)min(120, max(1, $totalReservations)) ?>,
                        <?= (int)min(120, max(1, $totalGuests)) ?>,
                        <?= (int)min(120, max(1, $totalOrders)) ?>,
                        <?= (int)min(120, max(1, $totalInventoryItems)) ?>
                    ],
                    backgroundColor: [
                        'rgba(249, 115, 22, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(168, 85, 247, 0.8)'
                    ],
                    borderColor: ['#f97316', '#3b82f6', '#22c55e', '#a855f7'],
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
                        max: 120,
                        grid: { color: '#f3f4f6' },
                        ticks: { stepSize: 20 }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }
</script>
<?php
include __DIR__ . '/partials/page_end.php';
