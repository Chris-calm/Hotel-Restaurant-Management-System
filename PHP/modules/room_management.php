<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$pendingApprovals = [];

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Room Management & Service</h1>
            <p class="text-sm text-gray-500 mt-1">Rooms, status, services, amenities, requests</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Room Status</h3>
                <div class="text-sm text-gray-500">Occupied / Vacant / Cleaning / Out of Order</div>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Service Requests</h3>
                <div class="text-sm text-gray-500">Room service and guest requests will show here.</div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
