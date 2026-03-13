<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Reservations/ReservationService.php';
require_once __DIR__ . '/../domain/Reservations/ReservationRepository.php';
require_once __DIR__ . '/../domain/Housekeeping/HousekeepingRepository.php';
require_once __DIR__ . '/../domain/Rooms/RoomRepository.php';
require_once __DIR__ . '/../domain/Maintenance/MaintenanceService.php';
require_once __DIR__ . '/../domain/Maintenance/MaintenanceRepository.php';

$conn = Database::getConnection();

$roomRepo = new RoomRepository($conn);
$maintenanceService = new MaintenanceService(new MaintenanceRepository($conn), $roomRepo);
$reservationService = new ReservationService(
    new ReservationRepository($conn),
    new HousekeepingRepository($conn),
    $roomRepo,
    $maintenanceService
);

$errors = [];
$form = [
    'code' => '',
    'discount_type' => 'Percent',
    'discount_value' => '',
    'start_date' => '',
    'end_date' => '',
    'max_uses' => '',
    'is_active' => 1,
    'notes' => '',
];

if (Request::isPost()) {
    $action = (string)Request::post('action', '');

    if ($action === 'create_promo') {
        $payload = [
            'code' => (string)Request::post('code', ''),
            'discount_type' => (string)Request::post('discount_type', 'Percent'),
            'discount_value' => (string)Request::post('discount_value', ''),
            'start_date' => (string)Request::post('start_date', ''),
            'end_date' => (string)Request::post('end_date', ''),
            'max_uses' => (string)Request::post('max_uses', ''),
            'is_active' => ((string)Request::post('is_active', '1') === '1') ? 1 : 0,
            'notes' => (string)Request::post('notes', ''),
        ];

        $form = $payload;

        $id = $reservationService->createPromoCode($payload, $errors);
        if ($id > 0) {
            Flash::set('success', 'Promo code created.');
            Response::redirect('marketing_promotions.php');
        }
    }

    if ($action === 'set_promo_active') {
        $promoId = Request::int('post', 'promo_id', 0);
        $isActive = ((string)Request::post('is_active', '0') === '1');
        $ok = $reservationService->setPromoCodeActive($promoId, $isActive, $errors);
        if ($ok) {
            Flash::set('success', 'Promo code updated.');
            Response::redirect('marketing_promotions.php');
        }
    }
}

$promoCodes = $reservationService->listPromoCodes();

$today = date('Y-m-d');
$isScheduled = static function (string $start, string $today): bool {
    return $start !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && $start > $today;
};
$isExpired = static function (string $end, string $today): bool {
    return $end !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $end < $today;
};
$isMaxed = static function ($maxUses, int $used): bool {
    if ($maxUses === null || $maxUses === '') {
        return false;
    }
    $m = (int)$maxUses;
    return $m > 0 && $used >= $m;
};

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

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="font-medium mb-1">Action failed</div>
                <?php foreach ($errors as $msg): ?>
                    <div><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Promo Codes</h3>
                        <div class="text-sm text-gray-500 mt-1">Percent or fixed discounts applied to stay subtotal.</div>
                    </div>
                    <div class="text-xs text-gray-500">Localhost ready</div>
                </div>

                <?php if (empty($promoCodes)): ?>
                    <div class="text-sm text-gray-500">No promo codes yet.</div>
                <?php else: ?>
                    <div class="overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left font-medium px-4 py-3">Code</th>
                                    <th class="text-left font-medium px-4 py-3">Discount</th>
                                    <th class="text-left font-medium px-4 py-3">Date Range</th>
                                    <th class="text-right font-medium px-4 py-3">Uses</th>
                                    <th class="text-right font-medium px-4 py-3">Status</th>
                                    <th class="text-right font-medium px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($promoCodes as $p): ?>
                                    <?php
                                        $active = (int)($p['is_active'] ?? 0) === 1;
                                        $type = (string)($p['discount_type'] ?? '');
                                        $val = (float)($p['discount_value'] ?? 0);
                                        $start = (string)($p['start_date'] ?? '');
                                        $end = (string)($p['end_date'] ?? '');
                                        $maxUses = $p['max_uses'] ?? null;
                                        $used = (int)($p['used_count'] ?? 0);

                                        $scheduled = $isScheduled($start, $today);
                                        $expired = $isExpired($end, $today);
                                        $maxed = $isMaxed($maxUses, $used);

                                        $statusText = $active ? 'Active' : 'Inactive';
                                        $badge = $active ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 bg-gray-50 text-gray-700';
                                        if ($expired) {
                                            $statusText = 'Expired';
                                            $badge = 'border-red-200 bg-red-50 text-red-700';
                                        } elseif ($scheduled) {
                                            $statusText = 'Scheduled';
                                            $badge = 'border-blue-200 bg-blue-50 text-blue-700';
                                        } elseif ($maxed) {
                                            $statusText = 'Maxed Out';
                                            $badge = 'border-amber-200 bg-amber-50 text-amber-800';
                                        }

                                        $canBeUsed = $active && !$scheduled && !$expired && !$maxed;
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($p['code'] ?? '')) ?></div>
                                            <?php if (trim((string)($p['notes'] ?? '')) !== ''): ?>
                                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)$p['notes']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            <?php if ($type === 'Percent'): ?>
                                                <?= number_format($val, 2) ?>%
                                            <?php else: ?>
                                                ₱<?= number_format($val, 2) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            <?= htmlspecialchars($start !== '' ? $start : '—') ?>
                                            <span class="text-gray-400">→</span>
                                            <?= htmlspecialchars($end !== '' ? $end : '—') ?>
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700">
                                            <?= (int)$used ?><?= ($maxUses !== null && $maxUses !== '' && (int)$maxUses > 0) ? (' / ' . (int)$maxUses) : '' ?>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($statusText) ?></span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <form method="post" class="inline">
                                                <input type="hidden" name="action" value="set_promo_active" />
                                                <input type="hidden" name="promo_id" value="<?= (int)($p['id'] ?? 0) ?>" />
                                                <input type="hidden" name="is_active" value="<?= $active ? '0' : '1' ?>" />
                                                <?php if ($active): ?>
                                                    <button class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Disable</button>
                                                <?php else: ?>
                                                    <button class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs transition <?= (!$scheduled && !$expired && !$maxed) ? 'hover:bg-gray-50' : 'opacity-50 cursor-not-allowed' ?>" <?= (!$scheduled && !$expired && !$maxed) ? '' : 'disabled' ?>>Enable</button>
                                                <?php endif; ?>
                                            </form>
                                            <?php if (!$canBeUsed): ?>
                                                <div class="text-xs text-gray-400 mt-1">
                                                    Not applicable now.
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Create Promo Code</h3>
                        <div class="text-xs text-gray-500 mt-1">Applied on Front Desk confirmation</div>
                    </div>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create_promo" />
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                        <input name="code" value="<?= htmlspecialchars((string)($form['code'] ?? '')) ?>" placeholder="e.g., SUMMER10" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Discount Type</label>
                            <select name="discount_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <option value="Percent" <?= ((string)($form['discount_type'] ?? 'Percent')) === 'Percent' ? 'selected' : '' ?>>Percent</option>
                                <option value="Fixed" <?= ((string)($form['discount_type'] ?? 'Percent')) === 'Fixed' ? 'selected' : '' ?>>Fixed</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Discount Value</label>
                            <input name="discount_value" value="<?= htmlspecialchars((string)($form['discount_value'] ?? '')) ?>" placeholder="e.g., 10 or 500" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Date (optional)</label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars((string)($form['start_date'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End Date (optional)</label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars((string)($form['end_date'] ?? '')) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Max Uses (optional)</label>
                        <input name="max_uses" value="<?= htmlspecialchars((string)($form['max_uses'] ?? '')) ?>" placeholder="Leave blank for unlimited" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                        <textarea name="notes" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"><?= htmlspecialchars((string)($form['notes'] ?? '')) ?></textarea>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4" <?= ((int)($form['is_active'] ?? 1) === 1) ? 'checked' : '' ?> />
                        <label class="text-sm text-gray-700">Active</label>
                    </div>
                    <div class="pt-2">
                        <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create Promo</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
