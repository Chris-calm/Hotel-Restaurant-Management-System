<?php
require_once __DIR__ . '/../../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../domain/Guests/GuestService.php';

$conn = Database::getConnection();
$service = new GuestService(new GuestRepository($conn));

$q = (string)Request::get('q', '');
$guests = $service->list($q);

$pageTitle = 'Guests - Hotel Management System';
$pendingApprovals = [];
$flash = Flash::get();

include __DIR__ . '/../../partials/page_start.php';
include __DIR__ . '/../../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-light text-gray-900">Guests</h1>
                    <p class="text-sm text-gray-500 mt-1">Manage guest profiles (CRM base)</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="create.php" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">New Guest</a>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-lg border border-gray-100 bg-white p-4 text-sm">
                <span class="font-medium text-gray-900"><?= htmlspecialchars(ucfirst($flash['type'])) ?>:</span>
                <span class="text-gray-700"><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg border border-gray-100 p-4 mb-6">
            <form method="get" class="flex items-center gap-3 flex-wrap">
                <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name/email/phone" class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full md:w-80" />
                <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Search</button>
                <a href="index.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
            </form>
        </div>

        <div class="bg-white rounded-lg border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($guests)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-gray-500">No guests found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($guests as $g): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars(($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? '')) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($g['email'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($g['phone'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= htmlspecialchars($g['status'] ?? '') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <a class="text-blue-600 hover:underline" href="view.php?id=<?= (int)$g['id'] ?>">View</a>
                                        <span class="text-gray-300 mx-2">|</span>
                                        <a class="text-gray-900 hover:underline" href="edit.php?id=<?= (int)$g['id'] ?>">Edit</a>
                                        <span class="text-gray-300 mx-2">|</span>
                                        <a class="text-red-600 hover:underline" href="delete.php?id=<?= (int)$g['id'] ?>">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../../partials/page_end.php';
