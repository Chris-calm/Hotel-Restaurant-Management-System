<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$APP_BASE_URL = App::baseUrl();

$errors = [];

$hasPosOrders = false;
$hasPosItems = false;
$hasMenuCategories = false;
$hasMenuItems = false;
$hasReservations = false;
$hasGuests = false;
$hasFolioCharges = false;

$TAX_RATE = 0.12;
$SERVICE_CHARGE_RATE = 0.10;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_orders'");
            $hasPosOrders = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_order_items'");
            $hasPosItems = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'menu_categories'");
            $hasMenuCategories = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'menu_items'");
            $hasMenuItems = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservations'");
            $hasReservations = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests'");
            $hasGuests = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservation_folio_charges'");
            $hasFolioCharges = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        }
    } catch (Throwable $e) {
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$orderId = Request::int('get', 'order_id', 0);
$categoryId = Request::int('get', 'category_id', 0);

$recalcOrderTotals = function (int $orderId) use ($conn, $TAX_RATE, $SERVICE_CHARGE_RATE): void {
    if (!$conn || $orderId <= 0) {
        return;
    }
    $stmt = $conn->prepare("SELECT COALESCE(SUM(line_total),0) AS subtotal FROM pos_order_items WHERE pos_order_id = ?");
    $subtotal = 0.0;
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        $subtotal = (float)($row['subtotal'] ?? 0);
    }
    $tax = round($subtotal * $TAX_RATE, 2);
    $svc = round($subtotal * $SERVICE_CHARGE_RATE, 2);
    $total = round($subtotal + $tax + $svc, 2);
    $stmt = $conn->prepare("UPDATE pos_orders SET subtotal = ?, tax = ?, service_charge = ?, total = ? WHERE id = ?");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('ddddi', $subtotal, $tax, $svc, $total, $orderId);
        $stmt->execute();
        $stmt->close();
    }
};

if (Request::isPost() && $conn && $hasPosOrders && $hasPosItems && $hasMenuItems) {
    $action = (string)Request::post('action', '');

    if ($action === 'create_order') {
        $orderType = (string)Request::post('order_type', 'Dine-in');
        $reservationId = Request::int('post', 'reservation_id', 0);
        $guestId = Request::int('post', 'guest_id', 0);

        if (!in_array($orderType, ['Dine-in', 'Takeout', 'Delivery', 'Room Charge'], true)) {
            $errors['order_type'] = 'Order type is invalid.';
        }
        if ($orderType === 'Room Charge') {
            if ($reservationId <= 0) {
                $errors['reservation_id'] = 'Reservation is required for Room Charge.';
            } elseif ($hasReservations) {
                $stmt = $conn->prepare("SELECT guest_id FROM reservations WHERE id = ? LIMIT 1");
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $reservationId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $row = $res->fetch_assoc();
                    $stmt->close();
                    $guestId = (int)($row['guest_id'] ?? 0);
                }
            }
        }

        if (empty($errors)) {
            $orderNo = 'POS-' . date('Ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare(
                "INSERT INTO pos_orders (order_no, order_type, status, reservation_id, guest_id, subtotal, tax, service_charge, total)
                 VALUES (?, ?, 'Open', NULLIF(?,0), NULLIF(?,0), 0, 0, 0, 0)"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ssii', $orderNo, $orderType, $reservationId, $guestId);
                $ok = $stmt->execute();
                $newId = (int)($stmt->insert_id ?? 0);
                $stmt->close();
                if ($ok && $newId > 0) {
                    Flash::set('success', 'Order created.');
                    Response::redirect('pos.php?order_id=' . $newId);
                }
            }
            $errors['general'] = 'Failed to create order.';
        }
    }

    if ($action === 'add_item') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        $menuItemId = Request::int('post', 'menu_item_id', 0);
        $qty = Request::int('post', 'qty', 1);

        if ($orderIdPost <= 0) {
            $errors['order_id'] = 'Order is required.';
        }
        if ($menuItemId <= 0) {
            $errors['menu_item_id'] = 'Menu item is required.';
        }
        if ($qty <= 0) {
            $errors['qty'] = 'Qty is invalid.';
        }

        $status = '';
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT status FROM pos_orders WHERE id = ? LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $orderIdPost);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $status = (string)($row['status'] ?? '');
            }
            if ($status !== 'Open') {
                $errors['general'] = 'Only Open orders can be edited.';
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT price FROM menu_items WHERE id = ? LIMIT 1");
            $price = 0.0;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $menuItemId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $price = (float)($row['price'] ?? 0);
            }

            $stmt = $conn->prepare("SELECT id, qty FROM pos_order_items WHERE pos_order_id = ? AND menu_item_id = ? LIMIT 1");
            $existingId = 0;
            $existingQty = 0;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ii', $orderIdPost, $menuItemId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $existingId = (int)($row['id'] ?? 0);
                $existingQty = (int)($row['qty'] ?? 0);
            }

            if ($existingId > 0) {
                $newQty = $existingQty + $qty;
                $lineTotal = round($newQty * $price, 2);
                $stmt = $conn->prepare("UPDATE pos_order_items SET qty = ?, price = ?, line_total = ? WHERE id = ?");
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('iddi', $newQty, $price, $lineTotal, $existingId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $lineTotal = round($qty * $price, 2);
                $stmt = $conn->prepare(
                    "INSERT INTO pos_order_items (pos_order_id, menu_item_id, qty, price, line_total)
                     VALUES (?, ?, ?, ?, ?)"
                );
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('iiidd', $orderIdPost, $menuItemId, $qty, $price, $lineTotal);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $recalcOrderTotals($orderIdPost);
            Flash::set('success', 'Item added.');
            Response::redirect('pos.php?order_id=' . $orderIdPost);
        }
    }

    if ($action === 'update_item_qty') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        $itemId = Request::int('post', 'pos_order_item_id', 0);
        $qty = Request::int('post', 'qty', 1);
        if ($orderIdPost <= 0 || $itemId <= 0) {
            $errors['general'] = 'Invalid request.';
        }
        if ($qty <= 0) {
            $errors['general'] = 'Qty must be at least 1.';
        }
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT price FROM pos_order_items WHERE id = ? AND pos_order_id = ? LIMIT 1");
            $price = 0.0;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ii', $itemId, $orderIdPost);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $price = (float)($row['price'] ?? 0);
            }
            $lineTotal = round($qty * $price, 2);
            $stmt = $conn->prepare("UPDATE pos_order_items SET qty = ?, line_total = ? WHERE id = ? AND pos_order_id = ?");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('idii', $qty, $lineTotal, $itemId, $orderIdPost);
                $stmt->execute();
                $stmt->close();
            }
            $recalcOrderTotals($orderIdPost);
            Flash::set('success', 'Item updated.');
            Response::redirect('pos.php?order_id=' . $orderIdPost);
        }
    }

    if ($action === 'remove_item') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        $itemId = Request::int('post', 'pos_order_item_id', 0);
        if ($orderIdPost > 0 && $itemId > 0) {
            $stmt = $conn->prepare("DELETE FROM pos_order_items WHERE id = ? AND pos_order_id = ?");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ii', $itemId, $orderIdPost);
                $stmt->execute();
                $stmt->close();
            }
            $recalcOrderTotals($orderIdPost);
            Flash::set('success', 'Item removed.');
            Response::redirect('pos.php?order_id=' . $orderIdPost);
        }
        $errors['general'] = 'Invalid request.';
    }

    if ($action === 'checkout') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        if ($orderIdPost <= 0) {
            $errors['general'] = 'Order is required.';
        }
        if (empty($errors)) {
            $recalcOrderTotals($orderIdPost);
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT order_no, order_type, status, reservation_id, guest_id, total FROM pos_orders WHERE id = ? LIMIT 1");
                $order = null;
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $orderIdPost);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $order = $res->fetch_assoc() ?: null;
                    $stmt->close();
                }
                if (!$order) {
                    throw new RuntimeException('Order not found.');
                }
                if ((string)($order['status'] ?? '') !== 'Open') {
                    throw new RuntimeException('Only Open orders can be checked out.');
                }

                $stmt = $conn->prepare("UPDATE pos_orders SET status = 'Paid' WHERE id = ?");
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to checkout.');
                }
                $stmt->bind_param('i', $orderIdPost);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to checkout.');
                }

                $orderType = (string)($order['order_type'] ?? '');
                $reservationId = (int)($order['reservation_id'] ?? 0);
                $guestId = (int)($order['guest_id'] ?? 0);
                $total = (float)($order['total'] ?? 0);
                $orderNo = (string)($order['order_no'] ?? '');

                if ($orderType === 'Room Charge' && $reservationId > 0 && $hasFolioCharges) {
                    $desc = 'POS Room Charge ' . $orderNo;
                    $stmt = $conn->prepare(
                        "INSERT INTO reservation_folio_charges (reservation_id, guest_id, charge_type, source_id, description, amount, created_by)
                         VALUES (?, NULLIF(?,0), 'POS', ?, ?, ?, NULLIF(?,0))"
                    );
                    if (!($stmt instanceof mysqli_stmt)) {
                        throw new RuntimeException('Failed to post folio charge.');
                    }
                    $stmt->bind_param('iiisdi', $reservationId, $guestId, $orderIdPost, $desc, $total, $currentUserId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if (!$ok) {
                        throw new RuntimeException('Failed to post folio charge.');
                    }
                }

                $conn->commit();
                Flash::set('success', 'Checkout complete.');
                Response::redirect('pos.php?order_id=' . $orderIdPost);
            } catch (Throwable $e) {
                $conn->rollback();
                $errors['general'] = $e->getMessage();
            }
        }
    }

    if ($action === 'void_order') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        if ($orderIdPost > 0) {
            $stmt = $conn->prepare("UPDATE pos_orders SET status = 'Voided' WHERE id = ? AND status = 'Open'");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $orderIdPost);
                $stmt->execute();
                $stmt->close();
                Flash::set('success', 'Order voided.');
                Response::redirect('pos.php');
            }
        }
        $errors['general'] = 'Failed to void order.';
    }
}

$menuCategories = [];
$menuItems = [];
$reservations = [];
$recentOrders = [];

$activeOrder = null;
$activeOrderItems = [];

if ($conn && $hasMenuCategories) {
    $res = $conn->query("SELECT id, name FROM menu_categories ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $menuCategories[] = $row;
        }
    }
}

if ($conn && $hasMenuItems) {
    $where = '';
    $types = '';
    $params = [];
    if ($categoryId > 0) {
        $where = 'WHERE mi.category_id = ? AND mi.is_active = 1';
        $types = 'i';
        $params[] = $categoryId;
    } else {
        $where = 'WHERE mi.is_active = 1';
    }
    $sql =
        "SELECT mi.id, mi.name, mi.price, mi.category_id, mc.name AS category_name
         FROM menu_items mi
         INNER JOIN menu_categories mc ON mc.id = mi.category_id
         {$where}
         ORDER BY mc.name ASC, mi.name ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $menuItems[] = $row;
        }
        $stmt->close();
    }
}

if ($conn && $hasReservations && $hasGuests) {
    $res = $conn->query(
        "SELECT r.id, r.reference_no, r.status, g.first_name, g.last_name
         FROM reservations r
         INNER JOIN guests g ON g.id = r.guest_id
         WHERE r.status IN ('Confirmed','Upcoming','Checked In')
         ORDER BY r.id DESC
         LIMIT 80"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $reservations[] = $row;
        }
    }
}

if ($conn && $hasPosOrders) {
    $res = $conn->query(
        "SELECT id, order_no, order_type, status, total, created_at
         FROM pos_orders
         ORDER BY id DESC
         LIMIT 20"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentOrders[] = $row;
        }
    }
}

if ($conn && $hasPosOrders && $orderId > 0) {
    $stmt = $conn->prepare(
        "SELECT o.id, o.order_no, o.order_type, o.status, o.reservation_id, o.guest_id,
                o.subtotal, o.tax, o.service_charge, o.total, o.created_at,
                r.reference_no,
                g.first_name, g.last_name
         FROM pos_orders o
         LEFT JOIN reservations r ON r.id = o.reservation_id
         LEFT JOIN guests g ON g.id = o.guest_id
         WHERE o.id = ?
         LIMIT 1"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        $activeOrder = $res->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if ($conn && $hasPosItems && $orderId > 0) {
    $stmt = $conn->prepare(
        "SELECT i.id, i.menu_item_id, i.qty, i.price, i.line_total, mi.name
         FROM pos_order_items i
         INNER JOIN menu_items mi ON mi.id = i.menu_item_id
         WHERE i.pos_order_id = ?
         ORDER BY i.id ASC"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $activeOrderItems[] = $row;
        }
        $stmt->close();
    }
}

$shiftGross = 0.0;
$shiftOrders = 0;
if ($conn && $hasPosOrders) {
    $res = $conn->query(
        "SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS s
         FROM pos_orders
         WHERE status = 'Paid' AND DATE(created_at) = CURDATE()"
    );
    if ($res) {
        $row = $res->fetch_assoc();
        $shiftOrders = (int)($row['c'] ?? 0);
        $shiftGross = (float)($row['s'] ?? 0);
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
            <h1 class="text-2xl font-light text-gray-900">Point of Sale (POS)</h1>
            <p class="text-sm text-gray-500 mt-1">Orders, checkout, and room charging</p>
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

        <?php if (!$hasPosOrders || !$hasPosItems || !$hasMenuItems): ?>
            <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                This module needs DB tables. Run the updated schema SQL to create <span class="font-medium">pos_orders</span>, <span class="font-medium">pos_order_items</span>, and <span class="font-medium">menu_items</span>.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-3">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Order Screen</h3>
                        <div class="text-sm text-gray-500 mt-1">Tax <?= (int)round($TAX_RATE * 100) ?>% • Service <?= (int)round($SERVICE_CHARGE_RATE * 100) ?>%</div>
                    </div>
                    <div class="text-xs text-gray-500">Localhost ready</div>
                </div>

                <?php if (!$activeOrder): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="rounded-lg border border-gray-100 p-4">
                            <h4 class="text-sm font-medium text-gray-900 mb-3">Create New Order</h4>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="create_order" />
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Order Type</label>
                                    <select name="order_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="Dine-in">Dine-in</option>
                                        <option value="Takeout">Takeout</option>
                                        <option value="Delivery">Delivery</option>
                                        <option value="Room Charge">Room Charge</option>
                                    </select>
                                    <?php if (isset($errors['order_type'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['order_type']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Reservation (for Room Charge)</label>
                                    <select name="reservation_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="0">Select reservation</option>
                                        <?php foreach ($reservations as $r): ?>
                                            <option value="<?= (int)($r['id'] ?? 0) ?>"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?> • <?= htmlspecialchars(trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''))) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($errors['reservation_id'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['reservation_id']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Create Order</button>
                            </form>
                        </div>

                        <div class="rounded-lg border border-gray-100 p-4">
                            <h4 class="text-sm font-medium text-gray-900 mb-3">Recent Orders</h4>
                            <?php if (empty($recentOrders)): ?>
                                <div class="text-sm text-gray-500">No orders yet.</div>
                            <?php else: ?>
                                <div class="space-y-2" style="max-height: 360px; overflow:auto;">
                                    <?php foreach ($recentOrders as $o): ?>
                                        <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/pos.php?order_id=<?= (int)($o['id'] ?? 0) ?>" class="block rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($o['order_no'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($o['order_type'] ?? '')) ?> • <?= htmlspecialchars((string)($o['created_at'] ?? '')) ?></div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-xs text-gray-700"><?= htmlspecialchars((string)($o['status'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1">₱<?= number_format((float)($o['total'] ?? 0), 2) ?></div>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rounded-lg border border-gray-100 p-4 mb-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-sm text-gray-500">Order</div>
                                <div class="text-lg font-medium text-gray-900"><?= htmlspecialchars((string)($activeOrder['order_no'] ?? '')) ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($activeOrder['order_type'] ?? '')) ?> • <?= htmlspecialchars((string)($activeOrder['status'] ?? '')) ?></div>
                                <?php if (((string)($activeOrder['order_type'] ?? '')) === 'Room Charge'): ?>
                                    <div class="text-xs text-gray-500 mt-1">Reservation: <?= htmlspecialchars((string)($activeOrder['reference_no'] ?? '')) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">Total</div>
                                <div class="text-xl font-medium text-gray-900">₱<?= number_format((float)($activeOrder['total'] ?? 0), 2) ?></div>
                                <div class="text-xs text-gray-500 mt-1">Sub: ₱<?= number_format((float)($activeOrder['subtotal'] ?? 0), 2) ?> • Tax: ₱<?= number_format((float)($activeOrder['tax'] ?? 0), 2) ?> • Svc: ₱<?= number_format((float)($activeOrder['service_charge'] ?? 0), 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="lg:col-span-2">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-medium text-gray-900">Menu</h4>
                                <div class="text-xs text-gray-500">Add items</div>
                            </div>
                            <div class="mb-3">
                                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/pos.php?order_id=<?= (int)$orderId ?>" class="text-xs <?= $categoryId === 0 ? 'text-blue-700 font-medium' : 'text-gray-600' ?>">All</a>
                                <?php foreach ($menuCategories as $c): ?>
                                    <span class="text-gray-300 mx-1">|</span>
                                    <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/pos.php?order_id=<?= (int)$orderId ?>&category_id=<?= (int)($c['id'] ?? 0) ?>" class="text-xs <?= $categoryId === (int)($c['id'] ?? 0) ? 'text-blue-700 font-medium' : 'text-gray-600' ?>"><?= htmlspecialchars((string)($c['name'] ?? '')) ?></a>
                                <?php endforeach; ?>
                            </div>

                            <?php if (empty($menuItems)): ?>
                                <div class="text-sm text-gray-500">No active menu items.</div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php foreach ($menuItems as $mi): ?>
                                        <form method="post" class="rounded-lg border border-gray-100 p-3 hover:bg-gray-50">
                                            <input type="hidden" name="action" value="add_item" />
                                            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                            <input type="hidden" name="menu_item_id" value="<?= (int)($mi['id'] ?? 0) ?>" />
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($mi['name'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($mi['category_name'] ?? '')) ?></div>
                                                </div>
                                                <div class="text-sm text-gray-900 font-medium">₱<?= number_format((float)($mi['price'] ?? 0), 2) ?></div>
                                            </div>
                                            <div class="flex items-center justify-between gap-3 mt-3">
                                                <input type="number" name="qty" min="1" value="1" class="w-20 border border-gray-200 rounded-lg px-2 py-1 text-sm" />
                                                <button class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs hover:bg-blue-700 transition">Add</button>
                                            </div>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-sm font-medium text-gray-900">Cart</h4>
                                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/pos.php" class="text-xs text-gray-500 hover:text-gray-700">New</a>
                            </div>

                            <?php if (empty($activeOrderItems)): ?>
                                <div class="text-sm text-gray-500">No items yet.</div>
                            <?php else: ?>
                                <div class="space-y-2">
                                    <?php foreach ($activeOrderItems as $it): ?>
                                        <div class="rounded-lg border border-gray-100 p-3">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1">₱<?= number_format((float)($it['price'] ?? 0), 2) ?> each</div>
                                                </div>
                                                <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)($it['line_total'] ?? 0), 2) ?></div>
                                            </div>
                                            <div class="flex items-center gap-2 mt-3">
                                                <form method="post" class="flex items-center gap-2">
                                                    <input type="hidden" name="action" value="update_item_qty" />
                                                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                                    <input type="hidden" name="pos_order_item_id" value="<?= (int)($it['id'] ?? 0) ?>" />
                                                    <input type="number" name="qty" min="1" value="<?= (int)($it['qty'] ?? 1) ?>" class="w-20 border border-gray-200 rounded-lg px-2 py-1 text-sm" />
                                                    <button class="px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs hover:bg-black transition">Update</button>
                                                </form>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="remove_item" />
                                                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                                    <input type="hidden" name="pos_order_item_id" value="<?= (int)($it['id'] ?? 0) ?>" />
                                                    <button class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs hover:bg-red-700 transition">Remove</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="mt-4 space-y-2">
                                <form method="post">
                                    <input type="hidden" name="action" value="checkout" />
                                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                    <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition" <?= ((string)($activeOrder['status'] ?? '') !== 'Open') ? 'disabled' : '' ?>>Checkout</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="void_order" />
                                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                    <button class="w-full px-4 py-2 rounded-lg bg-gray-200 text-gray-900 text-sm hover:bg-gray-300 transition" <?= ((string)($activeOrder['status'] ?? '') !== 'Open') ? 'disabled' : '' ?>>Void</button>
                                </form>
                                <?php if (((string)($activeOrder['order_type'] ?? '')) === 'Room Charge' && !$hasFolioCharges): ?>
                                    <div class="text-xs text-yellow-900 bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-2">
                                        Room Charge checkout needs <span class="font-medium">reservation_folio_charges</span> table.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Shift Summary</h3>
                <div class="text-sm text-gray-500">Today</div>
                <div class="mt-4 rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500">Paid Orders</div>
                    <div class="text-2xl font-light text-gray-900"><?= (int)$shiftOrders ?></div>
                </div>
                <div class="mt-3 rounded-lg border border-gray-100 p-4">
                    <div class="text-xs text-gray-500">Gross Sales</div>
                    <div class="text-2xl font-light text-gray-900">₱<?= number_format($shiftGross, 2) ?></div>
                </div>
                <div class="mt-4">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Recent Orders</h4>
                    <?php if (empty($recentOrders)): ?>
                        <div class="text-sm text-gray-500">No orders yet.</div>
                    <?php else: ?>
                        <div class="space-y-2" style="max-height: 420px; overflow:auto;">
                            <?php foreach ($recentOrders as $o): ?>
                                <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/pos.php?order_id=<?= (int)($o['id'] ?? 0) ?>" class="block rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string)($o['order_type'] ?? '')) ?></div>
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($o['order_no'] ?? '')) ?></div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-xs text-gray-700"><?= htmlspecialchars((string)($o['status'] ?? '')) ?></div>
                                            <div class="text-xs text-gray-500 mt-1">₱<?= number_format((float)($o['total'] ?? 0), 2) ?></div>
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
<?php include __DIR__ . '/../partials/page_end.php';
