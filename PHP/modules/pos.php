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

$hasInventoryItems = false;
$hasInventoryMovements = false;
$hasMenuItemIngredients = false;
$hasPosOrderStockPosts = false;

$hasLoyaltyTxns = false;
$hasLoyaltyEarnPosts = false;
$hasLoyaltyRedeemPosts = false;
$hasGuestLoyaltyPoints = false;
$hasGuestLoyaltyTier = false;
$hasPosOrderLoyaltyRedemptions = false;

$menuItemsHasImagePath = false;

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

			if ($hasMenuItems) {
				$res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'image_path'");
				$menuItemsHasImagePath = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
			}
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservations'");
            $hasReservations = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests'");
            $hasGuests = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservation_folio_charges'");
            $hasFolioCharges = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'inventory_items'");
            $hasInventoryItems = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'inventory_movements'");
            $hasInventoryMovements = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'menu_item_ingredients'");
            $hasMenuItemIngredients = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_order_stock_posts'");
            $hasPosOrderStockPosts = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_transactions'");
            $hasLoyaltyTxns = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_earn_posts'");
            $hasLoyaltyEarnPosts = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_redeem_posts'");
            $hasLoyaltyRedeemPosts = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_order_loyalty_redemptions'");
            $hasPosOrderLoyaltyRedemptions = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            if ($hasGuests) {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME = 'loyalty_points'");
                $hasGuestLoyaltyPoints = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME = 'loyalty_tier'");
                $hasGuestLoyaltyTier = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }
        }
    } catch (Throwable $e) {
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$orderId = Request::int('get', 'order_id', 0);
$categoryId = Request::int('get', 'category_id', 0);

$loyaltyTierForPoints = function (int $points): ?string {
    if ($points >= 3000) {
        return 'Platinum';
    }
    if ($points >= 1500) {
        return 'Gold';
    }
    if ($points >= 500) {
        return 'Silver';
    }
    return null;
};

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

    if ($action === 'set_guest') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        $guestIdPost = Request::int('post', 'guest_id', 0);

        if ($orderIdPost <= 0) {
            $errors['general'] = 'Order is required.';
        }
        if (!$hasGuests) {
            $errors['general'] = 'Guests table is required.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT status FROM pos_orders WHERE id = ? LIMIT 1");
            $status = '';
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $orderIdPost);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $status = (string)($row['status'] ?? '');
            }
            if ($status !== 'Open') {
                $errors['general'] = 'Only Open orders can be edited.';
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare('UPDATE pos_orders SET guest_id = NULLIF(?,0) WHERE id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ii', $guestIdPost, $orderIdPost);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Guest updated.');
                    Response::redirect('pos.php?order_id=' . $orderIdPost);
                }
            }
            $errors['general'] = 'Failed to update guest.';
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
        $redeemPointsReq = Request::int('post', 'redeem_points', 0);
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

                $totalAfterRedeem = $total;

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

                if ($hasInventoryItems && $hasInventoryMovements && $hasMenuItemIngredients && $hasPosOrderStockPosts) {
                    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM pos_order_stock_posts WHERE pos_order_id = ?');
                    $alreadyPosted = false;
                    if ($stmt instanceof mysqli_stmt) {
                        $stmt->bind_param('i', $orderIdPost);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        $alreadyPosted = ((int)($row['c'] ?? 0) > 0);
                    }

                    if (!$alreadyPosted) {
                        $stmt = $conn->prepare(
                            "SELECT mi.inventory_item_id, SUM(mi.qty * oi.qty) AS req_qty
                             FROM pos_order_items oi
                             INNER JOIN menu_item_ingredients mi ON mi.menu_item_id = oi.menu_item_id
                             WHERE oi.pos_order_id = ?
                             GROUP BY mi.inventory_item_id"
                        );
                        if (!($stmt instanceof mysqli_stmt)) {
                            throw new RuntimeException('Failed to prepare stock deduction.');
                        }
                        $stmt->bind_param('i', $orderIdPost);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        $reqs = [];
                        while ($r = $res->fetch_assoc()) {
                            $iid = (int)($r['inventory_item_id'] ?? 0);
                            $rq = (float)($r['req_qty'] ?? 0);
                            if ($iid > 0 && $rq > 0) {
                                $reqs[] = ['inventory_item_id' => $iid, 'req_qty' => $rq];
                            }
                        }
                        $stmt->close();

                        if (!empty($reqs)) {
                            foreach ($reqs as $req) {
                                $iid = (int)$req['inventory_item_id'];
                                $rq = (float)$req['req_qty'];

                                $stmt = $conn->prepare('SELECT quantity FROM inventory_items WHERE id = ? LIMIT 1');
                                $currentQty = 0.0;
                                if ($stmt instanceof mysqli_stmt) {
                                    $stmt->bind_param('i', $iid);
                                    $stmt->execute();
                                    $row = $stmt->get_result()->fetch_assoc();
                                    $stmt->close();
                                    $currentQty = (float)($row['quantity'] ?? 0);
                                }

                                $newQty = $currentQty - $rq;
                                if ($newQty < 0) {
                                    throw new RuntimeException('Insufficient stock for inventory item ID ' . $iid . '.');
                                }

                                $stmt = $conn->prepare('UPDATE inventory_items SET quantity = ? WHERE id = ?');
                                if (!($stmt instanceof mysqli_stmt)) {
                                    throw new RuntimeException('Failed to update stock.');
                                }
                                $stmt->bind_param('di', $newQty, $iid);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if (!$ok) {
                                    throw new RuntimeException('Failed to update stock.');
                                }

                                $reference = 'POS ' . $orderNo;
                                $delta = -$rq;
                                $stmt = $conn->prepare(
                                    "INSERT INTO inventory_movements (inventory_item_id, movement_type, qty, reference, created_by)
                                     VALUES (?, 'OUT', ?, ?, NULLIF(?,0))"
                                );
                                if (!($stmt instanceof mysqli_stmt)) {
                                    throw new RuntimeException('Failed to save movement.');
                                }
                                $stmt->bind_param('idsi', $iid, $delta, $reference, $currentUserId);
                                $ok = $stmt->execute();
                                $stmt->close();
                                if (!$ok) {
                                    throw new RuntimeException('Failed to save movement.');
                                }
                            }
                        }

                        $stmt = $conn->prepare('INSERT INTO pos_order_stock_posts (pos_order_id, created_by) VALUES (?, NULLIF(?,0))');
                        if (!($stmt instanceof mysqli_stmt)) {
                            throw new RuntimeException('Failed to record stock posting.');
                        }
                        $stmt->bind_param('ii', $orderIdPost, $currentUserId);
                        $ok = $stmt->execute();
                        $stmt->close();
                        if (!$ok) {
                            throw new RuntimeException('Failed to record stock posting.');
                        }
                    }
                }

                if ($guestId > 0 && $hasGuests && $hasGuestLoyaltyPoints && $hasLoyaltyTxns && $hasLoyaltyEarnPosts) {
                    if ($redeemPointsReq > 0 && $hasLoyaltyRedeemPosts && $hasPosOrderLoyaltyRedemptions) {
                        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM pos_order_loyalty_redemptions WHERE pos_order_id = ?");
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->bind_param('i', $orderIdPost);
                            $stmt->execute();
                            $row = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if ((int)($row['c'] ?? 0) > 0) {
                                throw new RuntimeException('This order already redeemed points.');
                            }
                        }

                        $stmt = $conn->prepare("SELECT loyalty_points" . ($hasGuestLoyaltyTier ? ", loyalty_tier" : "") . " FROM guests WHERE id = ? LIMIT 1 FOR UPDATE");
                        if (!($stmt instanceof mysqli_stmt)) {
                            throw new RuntimeException('Failed to load guest points.');
                        }
                        $stmt->bind_param('i', $guestId);
                        $stmt->execute();
                        $row = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        $currentPointsLocked = (int)($row['loyalty_points'] ?? 0);

                        $maxByTotal = (int)floor(max(0.0, $total));
                        $redeemPointsApplied = max(0, min($redeemPointsReq, $currentPointsLocked, $maxByTotal));
                        if ($redeemPointsApplied > 0) {
                            $redeemAmount = (float)$redeemPointsApplied;
                            $totalAfterRedeem = max(0.0, $total - $redeemAmount);

                            $newPointsAfterRedeem = $currentPointsLocked - $redeemPointsApplied;
                            $newTierAfterRedeem = $hasGuestLoyaltyTier ? $loyaltyTierForPoints($newPointsAfterRedeem) : null;

                            $referenceRedeem = 'POS ' . $orderNo;
                            $stmt = $conn->prepare(
                                "INSERT INTO loyalty_transactions (guest_id, txn_type, points, reference, created_by)
                                 VALUES (?, 'Redeem', ?, ?, NULLIF(?,0))"
                            );
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to save loyalty redemption.');
                            }
                            $stmt->bind_param('iisi', $guestId, $redeemPointsApplied, $referenceRedeem, $currentUserId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to save loyalty redemption.');
                            }

                            if ($hasGuestLoyaltyTier) {
                                $stmt = $conn->prepare('UPDATE guests SET loyalty_points = ?, loyalty_tier = ? WHERE id = ?');
                                if (!($stmt instanceof mysqli_stmt)) {
                                    throw new RuntimeException('Failed to update guest points.');
                                }
                                $stmt->bind_param('isi', $newPointsAfterRedeem, $newTierAfterRedeem, $guestId);
                            } else {
                                $stmt = $conn->prepare('UPDATE guests SET loyalty_points = ? WHERE id = ?');
                                if (!($stmt instanceof mysqli_stmt)) {
                                    throw new RuntimeException('Failed to update guest points.');
                                }
                                $stmt->bind_param('ii', $newPointsAfterRedeem, $guestId);
                            }
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to update guest points.');
                            }

                            $stmt = $conn->prepare("INSERT INTO loyalty_redeem_posts (source_type, source_id, guest_id, points, created_by) VALUES ('POS', ?, ?, ?, NULLIF(?,0))");
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to record loyalty redeem posting.');
                            }
                            $stmt->bind_param('iiii', $orderIdPost, $guestId, $redeemPointsApplied, $currentUserId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to record loyalty redeem posting.');
                            }

                            $stmt = $conn->prepare('INSERT INTO pos_order_loyalty_redemptions (pos_order_id, guest_id, points_redeemed, amount, created_by) VALUES (?, ?, ?, ?, NULLIF(?,0))');
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to record order redemption.');
                            }
                            $stmt->bind_param('iiidi', $orderIdPost, $guestId, $redeemPointsApplied, $redeemAmount, $currentUserId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to record order redemption.');
                            }

                            $stmt = $conn->prepare('UPDATE pos_orders SET total = ? WHERE id = ?');
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to apply redemption to order.');
                            }
                            $stmt->bind_param('di', $totalAfterRedeem, $orderIdPost);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to apply redemption to order.');
                            }
                        }
                    }

                    $earned = (int)floor(max(0.0, $totalAfterRedeem) / 100);
                    if ($earned > 0) {
                        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM loyalty_earn_posts WHERE source_type = 'POS' AND source_id = ?");
                        $alreadyEarned = false;
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->bind_param('i', $orderIdPost);
                            $stmt->execute();
                            $row = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            $alreadyEarned = ((int)($row['c'] ?? 0) > 0);
                        }

                        if (!$alreadyEarned) {
                            $stmt = $conn->prepare('SELECT loyalty_points FROM guests WHERE id = ? LIMIT 1 FOR UPDATE');
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to load guest points.');
                            }
                            $stmt->bind_param('i', $guestId);
                            $stmt->execute();
                            $row = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            $currentPoints = (int)($row['loyalty_points'] ?? 0);
                            $newPoints = $currentPoints + $earned;
                            $newTier = $hasGuestLoyaltyTier ? $loyaltyTierForPoints($newPoints) : null;

                            $reference = 'POS ' . $orderNo;
                            $stmt = $conn->prepare(
                                "INSERT INTO loyalty_transactions (guest_id, txn_type, points, reference, created_by)
                                 VALUES (?, 'Earn', ?, ?, NULLIF(?,0))"
                            );
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to save loyalty transaction.');
                            }
                            $stmt->bind_param('iisi', $guestId, $earned, $reference, $currentUserId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to save loyalty transaction.');
                            }

                            if ($hasGuestLoyaltyTier) {
                                $stmt = $conn->prepare('UPDATE guests SET loyalty_points = ?, loyalty_tier = ? WHERE id = ?');
                                if (!($stmt instanceof mysqli_stmt)) {
                                    throw new RuntimeException('Failed to update guest points.');
                                }
                                $stmt->bind_param('isi', $newPoints, $newTier, $guestId);
                            } else {
                                $stmt = $conn->prepare('UPDATE guests SET loyalty_points = ? WHERE id = ?');
                                if (!($stmt instanceof mysqli_stmt)) {
                                    throw new RuntimeException('Failed to update guest points.');
                                }
                                $stmt->bind_param('ii', $newPoints, $guestId);
                            }
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to update guest points.');
                            }

                            $stmt = $conn->prepare("INSERT INTO loyalty_earn_posts (source_type, source_id, guest_id, points, created_by) VALUES ('POS', ?, ?, ?, NULLIF(?,0))");
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to record loyalty earn posting.');
                            }
                            $stmt->bind_param('iiii', $orderIdPost, $guestId, $earned, $currentUserId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to record loyalty earn posting.');
                            }
                        }
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

    if ($action === 'post_to_folio') {
        $orderIdPost = Request::int('post', 'order_id', 0);
        if ($orderIdPost <= 0) {
            $errors['general'] = 'Order is required.';
        }
        if (!$hasFolioCharges) {
            $errors['general'] = 'Posting to folio needs reservation_folio_charges table.';
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT order_no, status, reservation_id, guest_id, total FROM pos_orders WHERE id = ? LIMIT 1");
            $order = null;
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $orderIdPost);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
            }
            if (!$order) {
                $errors['general'] = 'Order not found.';
            } elseif ((string)($order['status'] ?? '') !== 'Paid') {
                $errors['general'] = 'Only Paid orders can be posted to a folio.';
            } elseif ((int)($order['reservation_id'] ?? 0) <= 0) {
                $errors['general'] = 'This order is not linked to a reservation.';
            }
        }

        if (empty($errors)) {
            $reservationId = (int)($order['reservation_id'] ?? 0);
            $guestId = (int)($order['guest_id'] ?? 0);
            $total = (float)($order['total'] ?? 0);
            $orderNo = (string)($order['order_no'] ?? '');

            $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM reservation_folio_charges WHERE reservation_id = ? AND charge_type = \'POS\' AND source_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ii', $reservationId, $orderIdPost);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ((int)($row['c'] ?? 0) > 0) {
                    $errors['general'] = 'This POS order is already posted to the folio.';
                }
            }

            if (empty($errors)) {
                $desc = 'POS ' . $orderNo;
                $stmt = $conn->prepare(
                    "INSERT INTO reservation_folio_charges (reservation_id, guest_id, charge_type, source_id, description, amount, created_by)
                     VALUES (?, NULLIF(?,0), 'POS', ?, ?, ?, NULLIF(?,0))"
                );
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('iiisdi', $reservationId, $guestId, $orderIdPost, $desc, $total, $currentUserId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Posted to folio.');
                        Response::redirect('pos.php?order_id=' . $orderIdPost);
                    }
                }

                $errors['general'] = 'Failed to post to folio.';
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

if (Request::isPost() && $conn && $hasMenuCategories && $hasMenuItems) {
    $action = (string)Request::post('action', '');

    if ($action === 'add_menu_category') {
        $name = trim((string)Request::post('name', ''));
        if ($name === '') {
            $errors['general'] = 'Category name is required.';
        }
        if (empty($errors)) {
            $stmt = $conn->prepare('INSERT INTO menu_categories (name) VALUES (?)');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('s', $name);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Menu category added.');
                    Response::redirect('pos.php' . ($orderId > 0 ? ('?order_id=' . $orderId) : ''));
                }
            }
            $errors['general'] = 'Failed to add menu category.';
        }
    }

    if ($action === 'add_menu_item') {
        $categoryIdPost = Request::int('post', 'category_id', 0);
        $name = trim((string)Request::post('name', ''));
        $priceRaw = trim((string)Request::post('price', '0'));
        $isActive = Request::int('post', 'is_active', 1) ? 1 : 0;

        if ($categoryIdPost <= 0) {
            $errors['general'] = 'Category is required.';
        }
        if ($name === '') {
            $errors['general'] = 'Item name is required.';
        }
        if (!is_numeric($priceRaw) || (float)$priceRaw <= 0) {
            $errors['general'] = 'Price must be greater than 0.';
        }

        $imagePath = null;
        if (empty($errors) && $menuItemsHasImagePath && isset($_FILES['image']) && is_array($_FILES['image'])) {
            $f = $_FILES['image'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $errors['general'] = 'Image upload failed.';
                } else {
                    $size = (int)($f['size'] ?? 0);
                    if ($size <= 0 || $size > 5 * 1024 * 1024) {
                        $errors['general'] = 'Image must be <= 5MB.';
                    }

                    $tmp = (string)($f['tmp_name'] ?? '');
                    $orig = (string)($f['name'] ?? '');
                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                        $errors['general'] = 'Image must be JPG, PNG, or WEBP.';
                    }

                    if (empty($errors)) {
                        $root = dirname(__DIR__, 2);
                        $relDir = 'uploads/menu_items';
                        $absDir = $root . DIRECTORY_SEPARATOR . $relDir;
                        if (!is_dir($absDir)) {
                            @mkdir($absDir, 0775, true);
                        }
                        if (!is_dir($absDir) || !is_writable($absDir)) {
                            $errors['general'] = 'Upload folder is not writable: ' . $absDir;
                        } else {
                            $safeExt = ($ext === 'jpeg') ? 'jpg' : $ext;
                            $fn = 'mi_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
                            $absPath = $absDir . DIRECTORY_SEPARATOR . $fn;
                            if (!move_uploaded_file($tmp, $absPath)) {
                                $errors['general'] = 'Failed to save uploaded image.';
                            } else {
                                $imagePath = $relDir . '/' . $fn;
                            }
                        }
                    }
                }
            }
        }

        if (empty($errors)) {
            $price = (float)$priceRaw;
            if ($menuItemsHasImagePath) {
                $stmt = $conn->prepare('INSERT INTO menu_items (category_id, name, price, image_path, is_active) VALUES (?,?,?,?,?)');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('isdsi', $categoryIdPost, $name, $price, $imagePath, $isActive);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Menu item added.');
                        Response::redirect('pos.php' . ($orderId > 0 ? ('?order_id=' . $orderId) : ''));
                    }
                }
            } else {
                $stmt = $conn->prepare('INSERT INTO menu_items (category_id, name, price, is_active) VALUES (?,?,?,?)');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('isdi', $categoryIdPost, $name, $price, $isActive);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Menu item added.');
                        Response::redirect('pos.php' . ($orderId > 0 ? ('?order_id=' . $orderId) : ''));
                    }
                }
            }
            $errors['general'] = 'Failed to add menu item.';
        }
    }
}

$menuCategories = [];
$menuItems = [];
$reservations = [];
$guests = [];
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
    $imgSelect = $menuItemsHasImagePath ? 'mi.image_path,' : "NULL AS image_path,";
    $sql =
        "SELECT mi.id, mi.name, mi.price, mi.category_id, {$imgSelect} mc.name AS category_name
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

if ($conn && $hasGuests) {
    $res = $conn->query(
        "SELECT id, first_name, last_name, phone
         FROM guests
         ORDER BY id DESC
         LIMIT 200"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $guests[] = $row;
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
    $pointsSelect = $hasGuestLoyaltyPoints ? 'g.loyalty_points,' : '0 AS loyalty_points,';
    $tierSelect = $hasGuestLoyaltyTier ? 'g.loyalty_tier,' : "NULL AS loyalty_tier,";
    $stmt = $conn->prepare(
        "SELECT o.id, o.order_no, o.order_type, o.status, o.reservation_id, o.guest_id,
                o.subtotal, o.tax, o.service_charge, o.total, o.created_at,
                r.reference_no,
                g.first_name, g.last_name,
                {$pointsSelect}
                {$tierSelect}
                g.phone
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
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Guest (optional)</label>
                                    <select name="guest_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" <?= $hasGuests ? '' : 'disabled' ?>>
                                        <option value="0">No guest</option>
                                        <?php foreach ($guests as $g): ?>
                                            <option value="<?= (int)($g['id'] ?? 0) ?>"><?= htmlspecialchars(trim((string)($g['first_name'] ?? '') . ' ' . (string)($g['last_name'] ?? ''))) ?><?= ((string)($g['phone'] ?? '') !== '') ? (' • ' . htmlspecialchars((string)($g['phone'] ?? ''))) : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="text-[11px] text-gray-500 mt-1">For Room Charge, guest will be taken from the reservation automatically.</div>
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

                    <?php if ($hasMenuCategories && $hasMenuItems): ?>
                        <div class="mt-6 rounded-lg border border-gray-100 p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">Menu Setup</div>
                                    <div class="text-xs text-gray-500 mt-1">Add categories/items (with image upload)</div>
                                </div>
                                <div class="text-xs text-gray-500">Setup</div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-4">
                                <form method="post" class="space-y-2">
                                    <input type="hidden" name="action" value="add_menu_category" />
                                    <label class="block text-xs text-gray-600">New Category</label>
                                    <div class="flex gap-2">
                                        <input name="name" placeholder="e.g. Drinks" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <button class="px-3 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Add</button>
                                    </div>
                                </form>

                                <form method="post" enctype="multipart/form-data" class="space-y-2">
                                    <input type="hidden" name="action" value="add_menu_item" />
                                    <label class="block text-xs text-gray-600">New Item</label>
                                    <select name="category_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="0">Select category</option>
                                        <?php foreach ($menuCategories as $c): ?>
                                            <option value="<?= (int)($c['id'] ?? 0) ?>"><?= htmlspecialchars((string)($c['name'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input name="name" placeholder="Item name" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <input name="price" type="number" step="0.01" min="0" placeholder="Price" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <input name="image" type="file" accept="image/png,image/jpeg,image/webp" class="w-full text-sm" <?= $menuItemsHasImagePath ? '' : 'disabled' ?> />
                                    <?php if (!$menuItemsHasImagePath): ?>
                                        <div class="text-[11px] text-yellow-900 bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-2">
                                            Image upload needs <span class="font-medium">menu_items.image_path</span> in your current DB. Run: <span class="font-medium">ALTER TABLE menu_items ADD COLUMN image_path VARCHAR(255) NULL;</span>
                                        </div>
                                    <?php endif; ?>
                                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="is_active" value="1" checked />
                                        Active
                                    </label>
                                    <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Add Item</button>
                                    <div class="text-[11px] text-gray-500">Uploads go to <span class="font-medium">/uploads/menu_items</span>. If upload fails, check folder permissions.</div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
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
                                <?php $guestName = trim((string)($activeOrder['first_name'] ?? '') . ' ' . (string)($activeOrder['last_name'] ?? '')); ?>
                                <div class="text-xs text-gray-500 mt-1">Guest: <?= htmlspecialchars($guestName !== '' ? $guestName : 'None') ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">Total</div>
                                <div class="text-xl font-medium text-gray-900">₱<?= number_format((float)($activeOrder['total'] ?? 0), 2) ?></div>
                                <div class="text-xs text-gray-500 mt-1">Sub: ₱<?= number_format((float)($activeOrder['subtotal'] ?? 0), 2) ?> • Tax: ₱<?= number_format((float)($activeOrder['tax'] ?? 0), 2) ?> • Svc: ₱<?= number_format((float)($activeOrder['service_charge'] ?? 0), 2) ?></div>
                            </div>
                        </div>
                        <?php if ($hasGuests): ?>
                            <div class="mt-3">
                                <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-2 items-end">
                                    <input type="hidden" name="action" value="set_guest" />
                                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                    <div class="md:col-span-2">
                                        <label class="block text-xs text-gray-600 mb-1">Attach / Change Guest (for loyalty points)</label>
                                        <select name="guest_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" <?= ((string)($activeOrder['status'] ?? '') !== 'Open') ? 'disabled' : '' ?>>
                                            <option value="0">No guest</option>
                                            <?php $currentGid = (int)($activeOrder['guest_id'] ?? 0); ?>
                                            <?php foreach ($guests as $g): ?>
                                                <?php $gid = (int)($g['id'] ?? 0); ?>
                                                <option value="<?= $gid ?>" <?= $gid === $currentGid ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)($g['first_name'] ?? '') . ' ' . (string)($g['last_name'] ?? ''))) ?><?= ((string)($g['phone'] ?? '') !== '') ? (' • ' . htmlspecialchars((string)($g['phone'] ?? ''))) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition" <?= ((string)($activeOrder['status'] ?? '') !== 'Open') ? 'disabled' : '' ?>>Save</button>
                                </form>
                            </div>
                        <?php endif; ?>
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
                                            <?php
                                                $img = trim((string)($mi['image_path'] ?? ''));
                                                $imgUrl = '';
                                                if ($img !== '') {
                                                    if (preg_match('/^https?:\/\//i', $img) || substr($img, 0, 1) === '/') {
                                                        $imgUrl = $img;
                                                    } else {
                                                        $imgUrl = rtrim((string)$APP_BASE_URL, '/') . '/' . ltrim($img, '/');
                                                    }
                                                }
                                            ?>
                                            <div class="flex items-start justify-between gap-3">
                                                <?php if ($imgUrl !== ''): ?>
                                                    <img src="<?= htmlspecialchars($imgUrl) ?>" alt="" class="w-12 h-12 rounded-lg object-cover border border-gray-100" />
                                                <?php else: ?>
                                                    <div class="w-12 h-12 rounded-lg bg-gray-100 border border-gray-100"></div>
                                                <?php endif; ?>
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

                            <div class="mt-4 space-y-2">
                                <form method="post" class="space-y-2">
                                    <input type="hidden" name="action" value="checkout" />
                                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                    <?php if ((int)($activeOrder['guest_id'] ?? 0) > 0 && $hasGuestLoyaltyPoints): ?>
                                        <?php
                                            $availPts = (int)($activeOrder['loyalty_points'] ?? 0);
                                            $maxByTotal = (int)floor(max(0.0, (float)($activeOrder['total'] ?? 0)));
                                            $maxRedeem = max(0, min($availPts, $maxByTotal));
                                        ?>
                                        <div>
                                            <label class="block text-xs text-gray-600 mb-1">Redeem Points (₱1 per point)</label>
                                            <input type="number" name="redeem_points" min="0" max="<?= (int)$maxRedeem ?>" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" <?= ((string)($activeOrder['status'] ?? '') !== 'Open') ? 'disabled' : '' ?> />
                                            <div class="text-[11px] text-gray-500 mt-1">Available: <?= (int)$availPts ?> pts • Max for this order: <?= (int)$maxRedeem ?> pts • Redeem only once per order.</div>
                                        </div>
                                    <?php endif; ?>
                                    <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition" <?= ((string)($activeOrder['status'] ?? '') !== 'Open') ? 'disabled' : '' ?>>Checkout</button>
                                </form>
                                <?php if ($hasFolioCharges && (int)($activeOrder['reservation_id'] ?? 0) > 0): ?>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <form method="post">
                                            <input type="hidden" name="action" value="post_to_folio" />
                                            <input type="hidden" name="order_id" value="<?= (int)$orderId ?>" />
                                            <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition" <?= ((string)($activeOrder['status'] ?? '') !== 'Paid') ? 'disabled' : '' ?>>Post to Folio</button>
                                        </form>
                                        <a class="w-full px-4 py-2 rounded-lg bg-gray-100 text-gray-900 text-sm hover:bg-gray-200 transition text-center" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?reservation_id=<?= (int)($activeOrder['reservation_id'] ?? 0) ?>">Open Folio</a>
                                    </div>
                                <?php endif; ?>
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

                <?php if ($hasMenuCategories && $hasMenuItems): ?>
                <?php endif; ?>

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
<?php include __DIR__ . '/../partials/page_end.php'; ?>
