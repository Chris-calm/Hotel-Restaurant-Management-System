<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$APP_BASE_URL = App::baseUrl();

$errors = [];

$hasCategories = false;
$hasItems = false;
$hasMovements = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'inventory_categories'");
            $hasCategories = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'inventory_items'");
            $hasItems = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'inventory_movements'");
            $hasMovements = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }
    } catch (Throwable $e) {
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if (Request::isPost() && $conn && $hasItems) {
    $action = (string)Request::post('action', '');

    if ($action === 'create_category' && $hasCategories) {
        $name = trim((string)Request::post('name', ''));
        if ($name === '') {
            $errors['name'] = 'Category name is required.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO inventory_categories (name) VALUES (?)");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('s', $name);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Category created.');
                    Response::redirect('inventory_stock.php');
                }
            }
            $errors['general'] = 'Failed to create category.';
        }
    }

    if ($action === 'create_item') {
        $categoryId = Request::int('post', 'category_id', 0);
        $sku = trim((string)Request::post('sku', ''));
        $name = trim((string)Request::post('item_name', ''));
        $unit = trim((string)Request::post('unit', 'pcs'));
        $qty = (string)Request::post('quantity', '0');
        $reorder = (string)Request::post('reorder_level', '0');

        if ($name === '') {
            $errors['item_name'] = 'Item name is required.';
        }
        if ($unit === '') {
            $errors['unit'] = 'Unit is required.';
        }
        if (!is_numeric($qty) || (float)$qty < 0) {
            $errors['quantity'] = 'Quantity is invalid.';
        }
        if (!is_numeric($reorder) || (float)$reorder < 0) {
            $errors['reorder_level'] = 'Reorder level is invalid.';
        }

        if (empty($errors)) {
            $qtyF = (float)$qty;
            $reorderF = (float)$reorder;

            $stmt = $conn->prepare(
                "INSERT INTO inventory_items (category_id, sku, name, unit, quantity, reorder_level)
                 VALUES (NULLIF(?,0), NULLIF(?,''), ?, ?, ?, ?)"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('isssdd', $categoryId, $sku, $name, $unit, $qtyF, $reorderF);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Inventory item created.');
                    Response::redirect('inventory_stock.php');
                }
            }
            $errors['general'] = 'Failed to create inventory item.';
        }
    }

    if ($action === 'post_movement' && $hasMovements) {
        $itemId = Request::int('post', 'inventory_item_id', 0);
        $movementType = (string)Request::post('movement_type', 'IN');
        $qty = (string)Request::post('qty', '0');
        $reference = trim((string)Request::post('reference', ''));

        if ($itemId <= 0) {
            $errors['inventory_item_id'] = 'Select an item.';
        }
        if (!in_array($movementType, ['IN', 'OUT', 'ADJUST'], true)) {
            $errors['movement_type'] = 'Movement type is invalid.';
        }
        if (!is_numeric($qty) || (float)$qty < 0) {
            $errors['qty'] = 'Quantity is invalid.';
        }

        if (empty($errors)) {
            $qtyF = (float)$qty;
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT quantity FROM inventory_items WHERE id = ? LIMIT 1");
                $currentQty = 0.0;
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $itemId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res->fetch_assoc();
                    $stmt->close();
                    $currentQty = (float)($row['quantity'] ?? 0);
                }

                $delta = 0.0;
                $newQty = $currentQty;
                if ($movementType === 'IN') {
                    $delta = $qtyF;
                    $newQty = $currentQty + $qtyF;
                } elseif ($movementType === 'OUT') {
                    $delta = -$qtyF;
                    $newQty = $currentQty - $qtyF;
                } else {
                    $delta = $qtyF - $currentQty;
                    $newQty = $qtyF;
                    if ($reference === '') {
                        $reference = 'ADJUST';
                    }
                }

                if ($newQty < 0) {
                    throw new RuntimeException('Stock cannot go below zero.');
                }

                $stmt = $conn->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to update stock.');
                }
                $stmt->bind_param('di', $newQty, $itemId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to update stock.');
                }

                $refFinal = $reference;
                $stmt = $conn->prepare(
                    "INSERT INTO inventory_movements (inventory_item_id, movement_type, qty, reference, created_by)
                     VALUES (?, ?, ?, NULLIF(?,''), NULLIF(?,0))"
                );
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to save movement.');
                }
                $stmt->bind_param('isdsi', $itemId, $movementType, $delta, $refFinal, $currentUserId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to save movement.');
                }

                $conn->commit();
                Flash::set('success', 'Stock movement posted.');
                Response::redirect('inventory_stock.php');
            } catch (Throwable $e) {
                $conn->rollback();
                $errors['general'] = $e->getMessage();
            }
        }
    }
}

$categories = [];
$items = [];
$movements = [];

$kpiItems = 0;
$kpiLowStock = 0;

if ($conn && $hasCategories) {
    $res = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
    }
}

if ($conn && $hasItems) {
    $res = $conn->query(
        "SELECT i.id, i.category_id, c.name AS category_name, i.sku, i.name, i.unit, i.quantity, i.reorder_level, i.created_at
         FROM inventory_items i
         LEFT JOIN inventory_categories c ON c.id = i.category_id
         ORDER BY i.id DESC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
    }
    $kpiItems = count($items);
    foreach ($items as $it) {
        if ((float)($it['quantity'] ?? 0) <= (float)($it['reorder_level'] ?? 0)) {
            $kpiLowStock++;
        }
    }
}

if ($conn && $hasMovements) {
    $res = $conn->query(
        "SELECT m.id, m.movement_type, m.qty, m.reference, m.created_by, m.created_at,
                i.name AS item_name, i.unit
         FROM inventory_movements m
         INNER JOIN inventory_items i ON i.id = m.inventory_item_id
         ORDER BY m.id DESC
         LIMIT 50"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $movements[] = $row;
        }
    }
}

$pendingApprovals = [];

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Inventory & Stock</h1>
            <p class="text-sm text-gray-500 mt-1">Items, stock levels, receiving, usage, adjustments</p>
        </div>

        <?php $flash = Flash::get(); ?>
        <?php if ($flash): ?>
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm <?= $flash['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($errors['general']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasItems || !$hasMovements): ?>
            <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                This module needs DB tables. Run the updated schema SQL to create <span class="font-medium">inventory_items</span> and <span class="font-medium">inventory_movements</span>.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Items</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiItems ?></p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-box text-blue-600 text-xl'></i>
                    </div>
                </div>
            </div>

            <div class="dashboard-card bg-white rounded-lg border border-gray-100 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Low Stock</p>
                        <p class="text-2xl font-light text-gray-900"><?= (int)$kpiLowStock ?></p>
                        <p class="text-xs text-gray-500 mt-1">Qty ≤ reorder level</p>
                    </div>
                    <div class="icon-container w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center">
                        <i class='bx bx-error text-orange-600 text-xl'></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Add Item</h3>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="action" value="create_item" />
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                        <select name="category_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">Uncategorized</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int)($c['id'] ?? 0) ?>"><?= htmlspecialchars((string)($c['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">SKU (optional)</label>
                            <input name="sku" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                            <input name="unit" value="pcs" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (isset($errors['unit'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['unit']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Item Name</label>
                        <input name="item_name" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        <?php if (isset($errors['item_name'])): ?>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['item_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Starting Qty</label>
                            <input name="quantity" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (isset($errors['quantity'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['quantity']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
                            <input name="reorder_level" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            <?php if (isset($errors['reorder_level'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['reorder_level']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Save Item</button>
                </form>

                <div class="mt-6 pt-6 border-t border-gray-100">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Post Movement</h3>
                    <form method="post" class="space-y-3">
                        <input type="hidden" name="action" value="post_movement" />
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Item</label>
                            <select name="inventory_item_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <option value="0">Select item</option>
                                <?php foreach ($items as $it): ?>
                                    <option value="<?= (int)($it['id'] ?? 0) ?>"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['inventory_item_id'])): ?>
                                <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['inventory_item_id']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="movement_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                    <option value="IN">IN (Receiving)</option>
                                    <option value="OUT">OUT (Usage/Issue)</option>
                                    <option value="ADJUST">ADJUST (Set new qty)</option>
                                </select>
                                <?php if (isset($errors['movement_type'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['movement_type']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Qty</label>
                                <input name="qty" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['qty'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['qty']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reference (optional)</label>
                            <input name="reference" placeholder="PO #, Receiving #, Notes" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                        </div>
                        <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Post</button>
                        <p class="text-xs text-gray-500">For <span class="font-medium">ADJUST</span>, Qty is the new exact stock level.</p>
                    </form>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-2">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Stock Levels</h3>
                    <div class="text-xs text-gray-500">All items</div>
                </div>
                <?php if (empty($items)): ?>
                    <div class="text-sm text-gray-500">No inventory items yet.</div>
                <?php else: ?>
                    <div class="overflow-auto rounded-lg border border-gray-100">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="text-left font-medium px-4 py-3">Item</th>
                                    <th class="text-left font-medium px-4 py-3">Category</th>
                                    <th class="text-right font-medium px-4 py-3">Qty</th>
                                    <th class="text-right font-medium px-4 py-3">Reorder</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($items as $it): ?>
                                    <?php
                                        $qtyV = (float)($it['quantity'] ?? 0);
                                        $reorderV = (float)($it['reorder_level'] ?? 0);
                                        $isLow = $qtyV <= $reorderV;
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($it['sku'] ?? '')) ?><?= ((string)($it['sku'] ?? '') !== '') ? ' • ' : '' ?><?= htmlspecialchars((string)($it['unit'] ?? '')) ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($it['category_name'] ?? '')) ?></td>
                                        <td class="px-4 py-3 text-right <?= $isLow ? 'text-orange-700 font-medium' : 'text-gray-700' ?>"><?= number_format($qtyV, 2) ?></td>
                                        <td class="px-4 py-3 text-right text-gray-700"><?= number_format($reorderV, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="mt-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Recent Movements</h3>
                        <div class="text-xs text-gray-500">Last 50</div>
                    </div>
                    <?php if (empty($movements)): ?>
                        <div class="text-sm text-gray-500">No movements yet.</div>
                    <?php else: ?>
                        <div class="overflow-auto rounded-lg border border-gray-100">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th class="text-left font-medium px-4 py-3">When</th>
                                        <th class="text-left font-medium px-4 py-3">Item</th>
                                        <th class="text-left font-medium px-4 py-3">Type</th>
                                        <th class="text-right font-medium px-4 py-3">Qty</th>
                                        <th class="text-left font-medium px-4 py-3">Reference</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($movements as $m): ?>
                                        <?php
                                            $t = (string)($m['movement_type'] ?? '');
                                            $badge = 'border-gray-200 bg-gray-50 text-gray-700';
                                            if ($t === 'IN') $badge = 'border-green-200 bg-green-50 text-green-700';
                                            if ($t === 'OUT') $badge = 'border-red-200 bg-red-50 text-red-700';
                                            if ($t === 'ADJUST') $badge = 'border-blue-200 bg-blue-50 text-blue-700';
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($m['created_at'] ?? '')) ?></td>
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($m['item_name'] ?? '')) ?></div>
                                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($m['unit'] ?? '')) ?></div>
                                            </td>
                                            <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($t) ?></span></td>
                                            <td class="px-4 py-3 text-right text-gray-700"><?= number_format((float)($m['qty'] ?? 0), 2) ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($m['reference'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
