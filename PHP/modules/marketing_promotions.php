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
            <h1 class="text-2xl font-light text-gray-900">Marketing & Promotions</h1>
            <p class="text-sm text-gray-500 mt-1">Campaigns, discounts, promo codes, packages</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Promotions</h3>
                <div class="text-sm text-gray-500">Create and manage hotel/restaurant promotions here.</div>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Campaign Performance</h3>
                <div class="text-sm text-gray-500">Analytics widgets will go here.</div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
