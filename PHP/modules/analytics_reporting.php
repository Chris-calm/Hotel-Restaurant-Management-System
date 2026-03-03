<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

include __DIR__ . '/../db_connect.php';
include __DIR__ . '/../partials/functions.php';

$pendingApprovals = [];

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
                    <h3 class="text-lg font-medium text-gray-900">Sales</h3>
                    <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-bar-chart-alt-2 text-green-600'></i>
                    </div>
                </div>
                <div style="height: 200px;">
                    <canvas id="salesChart"></canvas>
                </div>
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
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Occupancy',
                    data: [55, 61, 58, 66, 72, 75, 70],
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }

    const salesChart = document.getElementById('salesChart');
    if (salesChart) {
        new Chart(salesChart, {
            type: 'bar',
            data: {
                labels: ['Breakfast', 'Lunch', 'Dinner'],
                datasets: [{
                    label: 'Sales',
                    data: [120, 240, 180],
                    backgroundColor: ['#22c55e', '#3b82f6', '#a855f7']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
</script>
<?php include __DIR__ . '/../partials/page_end.php';
