<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$APP_BASE_URL = App::baseUrl();

$errors = [];

$hasReservations = false;
$hasGuests = false;
$hasFolioCharges = false;
$hasReservationPayments = false;
$hasReservationRooms = false;
$hasPosOrders = false;
$hasPosOrderItems = false;
$hasMenuItems = false;
$hasEvents = false;
$eventsHasClientUserId = false;
$eventsHasClientGuestId = false;

$hasLoyaltyTxns = false;
$hasLoyaltyEarnPosts = false;
$hasLoyaltyRedeemPosts = false;
$hasGuestLoyaltyPoints = false;
$hasGuestLoyaltyTier = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservations'");
            $hasReservations = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests'");
            $hasGuests = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservation_folio_charges'");
            $hasFolioCharges = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservation_payments'");
            $hasReservationPayments = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'reservation_rooms'");
            $hasReservationRooms = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_orders'");
            $hasPosOrders = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'pos_order_items'");
            $hasPosOrderItems = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'menu_items'");
            $hasMenuItems = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events'");
            $hasEvents = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            if ($hasEvents) {
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_user_id'");
                $eventsHasClientUserId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
                $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_guest_id'");
                $eventsHasClientGuestId = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            }

            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_transactions'");
            $hasLoyaltyTxns = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_earn_posts'");
            $hasLoyaltyEarnPosts = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_redeem_posts'");
            $hasLoyaltyRedeemPosts = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
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

$guestId = Request::int('get', 'guest_id', 0);
$reservationId = Request::int('get', 'reservation_id', 0);
$q = trim((string)Request::get('q', ''));
$gq = trim((string)Request::get('gq', ''));
$tab = trim((string)Request::get('tab', 'reservations'));
$prefillChargeType = trim((string)Request::get('prefill_charge_type', ''));
$prefillChargeDesc = trim((string)Request::get('prefill_charge_desc', ''));
$prefillChargeAmount = trim((string)Request::get('prefill_charge_amount', ''));
$prefillChargeSourceId = Request::int('get', 'prefill_charge_source_id', 0);
if (!in_array($tab, ['reservations', 'pos', 'events'], true)) {
    $tab = 'reservations';
}

if (Request::isPost() && $conn && $hasReservations) {
    $action = (string)Request::post('action', '');
    $reservationIdPost = Request::int('post', 'reservation_id', 0);
    if ($reservationIdPost > 0) {
        $reservationId = $reservationIdPost;
    }

    if ($action === 'redeem_loyalty' && $hasReservationPayments) {
        $redeemPointsReq = Request::int('post', 'redeem_points', 0);
        if ($reservationId <= 0) {
            $errors['reservation_id'] = 'Reservation is required.';
        }
        if (!$hasGuests || !$hasGuestLoyaltyPoints || !$hasLoyaltyTxns || !$hasLoyaltyRedeemPosts) {
            $errors['general'] = 'Loyalty redeem needs guests.loyalty_points, loyalty_transactions, and loyalty_redeem_posts tables.';
        }
        if ($redeemPointsReq <= 0) {
            $errors['general'] = 'Redeem points must be at least 1.';
        }

        if (empty($errors)) {
            $guestId = 0;
            $stmt = $conn->prepare("SELECT guest_id FROM reservations WHERE id = ? LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $guestId = (int)($row['guest_id'] ?? 0);
            }
            if ($guestId <= 0) {
                $errors['general'] = 'Reservation has no linked guest.';
            }
        }

        $totChargesNow = 0.0;
        $totPaidNow = 0.0;
        if (empty($errors) && $reservationId > 0 && $hasFolioCharges) {
            $stmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM reservation_folio_charges WHERE reservation_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $totChargesNow = (float)($row['s'] ?? 0);
            }
        }
        if (empty($errors) && $reservationId > 0) {
            $stmt = $conn->prepare(
                "SELECT payment_type, amount
                 FROM reservation_payments
                 WHERE reservation_id = ? AND status = 'Posted'"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $t = (string)($row['payment_type'] ?? 'Payment');
                    $a = (float)($row['amount'] ?? 0);
                    if ($t === 'Payment') {
                        $totPaidNow += $a;
                    } elseif ($t === 'Refund') {
                        $totPaidNow -= $a;
                    }
                }
                $stmt->close();
            }
        }
        $balanceNow = $totChargesNow - $totPaidNow;
        if (empty($errors) && $balanceNow <= 0) {
            $errors['general'] = 'No balance due for this reservation.';
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT loyalty_points FROM guests WHERE id = ? LIMIT 1 FOR UPDATE");
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to load guest points.');
                }
                $stmt->bind_param('i', $guestId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    throw new RuntimeException('Guest not found.');
                }
                $currentPoints = (int)($row['loyalty_points'] ?? 0);

                $maxByBalance = (int)floor(max(0.0, $balanceNow));
                $redeemPointsApplied = max(0, min($redeemPointsReq, $currentPoints, $maxByBalance));
                if ($redeemPointsApplied <= 0) {
                    throw new RuntimeException('Insufficient points or balance.');
                }
                $amountF = (float)$redeemPointsApplied;

                $stmt = $conn->prepare(
                    "INSERT INTO reservation_payments (reservation_id, guest_id, payment_type, method, reference, amount, status, created_by)
                     VALUES (?, NULLIF(?,0), 'Payment', 'Loyalty Points', 'LOYALTY REDEEM', ?, 'Posted', NULLIF(?,0))"
                );
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to post loyalty payment.');
                }
                $stmt->bind_param('iidi', $reservationId, $guestId, $amountF, $currentUserId);
                $ok = $stmt->execute();
                $paymentIdNew = (int)($stmt->insert_id ?? 0);
                $stmt->close();
                if (!$ok || $paymentIdNew <= 0) {
                    throw new RuntimeException('Failed to post loyalty payment.');
                }

                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM loyalty_redeem_posts WHERE source_type = 'RES_PAYMENT' AND source_id = ?");
                $alreadyPosted = false;
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $paymentIdNew);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $alreadyPosted = ((int)($r['c'] ?? 0) > 0);
                }
                if ($alreadyPosted) {
                    throw new RuntimeException('Duplicate loyalty redeem posting.');
                }

                $newPoints = $currentPoints - $redeemPointsApplied;
                if ($newPoints < 0) {
                    throw new RuntimeException('Insufficient points.');
                }
                $newTier = $hasGuestLoyaltyTier ? $loyaltyTierForPoints($newPoints) : null;

                $ref = 'RES ' . $reservationId . ' LOYALTY PAY#' . $paymentIdNew;
                $stmt = $conn->prepare(
                    "INSERT INTO loyalty_transactions (guest_id, txn_type, points, reference, created_by)
                     VALUES (?, 'Redeem', ?, ?, NULLIF(?,0))"
                );
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to save loyalty redemption.');
                }
                $stmt->bind_param('iisi', $guestId, $redeemPointsApplied, $ref, $currentUserId);
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

                $stmt = $conn->prepare("INSERT INTO loyalty_redeem_posts (source_type, source_id, guest_id, points, created_by) VALUES ('RES_PAYMENT', ?, ?, ?, NULLIF(?,0))");
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to record loyalty redeem posting.');
                }
                $stmt->bind_param('iiii', $paymentIdNew, $guestId, $redeemPointsApplied, $currentUserId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to record loyalty redeem posting.');
                }

                $conn->commit();
                Flash::set('success', 'Loyalty points redeemed and posted as payment.');
                Response::redirect('billing_payments.php?reservation_id=' . $reservationId);
            } catch (Throwable $e) {
                $conn->rollback();
                $errors['general'] = $e->getMessage();
            }
        }
    }

    if ($action === 'add_charge' && $hasFolioCharges) {
        $chargeType = (string)Request::post('charge_type', 'Other');
        $description = trim((string)Request::post('description', ''));
        $amount = (string)Request::post('amount', '0');
        $sourceId = Request::int('post', 'source_id', 0);

        if ($chargeType === 'Room') {
            $description = 'Room charges (auto)';
            $amount = '0';

            if ($reservationId > 0 && $hasReservationRooms) {
                $stmt = $conn->prepare('SELECT checkin_date, checkout_date FROM reservations WHERE id = ? LIMIT 1');
                $checkin = '';
                $checkout = '';
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $reservationId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $checkin = (string)($row['checkin_date'] ?? '');
                    $checkout = (string)($row['checkout_date'] ?? '');
                }
                $nights = 0;
                $t1 = strtotime($checkin);
                $t2 = strtotime($checkout);
                if ($t1 !== false && $t2 !== false && $t2 > $t1) {
                    $nights = (int)round(($t2 - $t1) / 86400);
                }

                $nightlyRateSum = 0.0;
                $stmt = $conn->prepare('SELECT COALESCE(SUM(rate),0) AS s FROM reservation_rooms WHERE reservation_id = ?');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('i', $reservationId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $nightlyRateSum = (float)($row['s'] ?? 0);
                }

                $amt = $nights * $nightlyRateSum;
                if ($amt > 0) {
                    $amount = (string)$amt;
                }
            } else {
                $errors['amount'] = 'Room charge cannot be auto-calculated (reservation_rooms table missing).';
            }
        }

        if ($reservationId <= 0) {
            $errors['reservation_id'] = 'Reservation is required.';
        }
        if (!in_array($chargeType, ['Room', 'POS', 'Event', 'Other', 'Adjustment'], true)) {
            $errors['charge_type'] = 'Charge type is invalid.';
        }
        if ($description === '') {
            $errors['description'] = 'Description is required.';
        }
        if (!is_numeric($amount) || (float)$amount === 0.0) {
            $errors['amount'] = 'Amount must be a number and not zero.';
        }

        if (empty($errors)) {
            $guestId = 0;
            $stmt = $conn->prepare("SELECT guest_id FROM reservations WHERE id = ? LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $guestId = (int)($row['guest_id'] ?? 0);
            }

            if ($sourceId > 0 && in_array($chargeType, ['POS', 'Event'], true)) {
                $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM reservation_folio_charges WHERE reservation_id = ? AND charge_type = ? AND source_id = ?');
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('isi', $reservationId, $chargeType, $sourceId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ((int)($row['c'] ?? 0) > 0) {
                        $errors['general'] = 'This item is already posted to the folio.';
                    }
                }
            }

            if (empty($errors)) {
                $amountF = (float)$amount;
                $sourceIdDb = ($sourceId > 0 && in_array($chargeType, ['POS', 'Event'], true)) ? $sourceId : null;
                $stmt = $conn->prepare(
                    "INSERT INTO reservation_folio_charges (reservation_id, guest_id, charge_type, source_id, description, amount, created_by)
                     VALUES (?, NULLIF(?,0), ?, ?, ?, ?, NULLIF(?,0))"
                );
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->bind_param('iisssdi', $reservationId, $guestId, $chargeType, $sourceIdDb, $description, $amountF, $currentUserId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        Flash::set('success', 'Charge posted to folio.');
                        Response::redirect('billing_payments.php?reservation_id=' . $reservationId);
                    }
                }

                $errors['general'] = 'Failed to post charge.';
            }
        }
    }

    if ($action === 'add_payment' && $hasReservationPayments) {
        $paymentType = (string)Request::post('payment_type', 'Payment');
        $method = trim((string)Request::post('method', ''));
        $reference = trim((string)Request::post('reference', ''));
        $amount = (string)Request::post('amount', '0');

        $reservationPaymentMethod = '';
        if ($reservationId > 0) {
            $stmt = $conn->prepare('SELECT payment_method FROM reservations WHERE id = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $reservationPaymentMethod = (string)($row['payment_method'] ?? '');
            }
        }

        $totChargesNow = 0.0;
        $totPaidNow = 0.0;
        if ($reservationId > 0 && $hasFolioCharges) {
            $stmt = $conn->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM reservation_folio_charges WHERE reservation_id = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $totChargesNow = (float)($row['s'] ?? 0);
            }
        }
        if ($reservationId > 0) {
            $stmt = $conn->prepare(
                "SELECT payment_type, amount
                 FROM reservation_payments
                 WHERE reservation_id = ? AND status = 'Posted'"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $t = (string)($row['payment_type'] ?? 'Payment');
                    $a = (float)($row['amount'] ?? 0);
                    if ($t === 'Payment') {
                        $totPaidNow += $a;
                    } elseif ($t === 'Refund') {
                        $totPaidNow -= $a;
                    }
                }
                $stmt->close();
            }
        }
        $balanceNow = $totChargesNow - $totPaidNow;

        if ($reservationId <= 0) {
            $errors['reservation_id'] = 'Reservation is required.';
        }
        if (!in_array($paymentType, ['Payment', 'Refund', 'Adjustment'], true)) {
            $errors['payment_type'] = 'Payment type is invalid.';
        }
        if ($paymentType !== 'Adjustment' && !in_array($method, ['Cash', 'Card', 'GCash', 'Bank Transfer'], true)) {
            $errors['method'] = 'Payment method is invalid.';
        }
        if ($paymentType !== 'Adjustment' && $reservationPaymentMethod !== '' && in_array($reservationPaymentMethod, ['Cash', 'Card', 'GCash', 'Bank Transfer'], true)) {
            $method = $reservationPaymentMethod;
        }
        if ($paymentType !== 'Adjustment' && in_array($method, ['GCash', 'Bank Transfer'], true) && $reference === '') {
            $errors['reference'] = 'Reference is required for GCash/Bank Transfer.';
        }
        if (!is_numeric($amount) || (float)$amount === 0.0) {
            $errors['amount'] = 'Amount must be a number and not zero.';
        }

        if (empty($errors['amount'])) {
            $amountF = (float)$amount;
            if ($paymentType === 'Payment') {
                if ($balanceNow <= 0) {
                    $errors['amount'] = 'No balance due for this reservation.';
                } elseif ($amountF > $balanceNow) {
                    $errors['amount'] = 'Payment amount cannot exceed the balance.';
                }
            } elseif ($paymentType === 'Refund') {
                if ($totPaidNow <= 0) {
                    $errors['amount'] = 'No payments available to refund.';
                } elseif ($amountF > $totPaidNow) {
                    $errors['amount'] = 'Refund amount cannot exceed total paid.';
                }
            }
        }

        if (empty($errors)) {
            $guestId = 0;
            $stmt = $conn->prepare("SELECT guest_id FROM reservations WHERE id = ? LIMIT 1");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $reservationId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                $guestId = (int)($row['guest_id'] ?? 0);
            }

            $amountF = (float)$amount;
            $methodFinal = ($paymentType === 'Adjustment') ? null : $method;

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO reservation_payments (reservation_id, guest_id, payment_type, method, reference, amount, status, created_by)
                     VALUES (?, NULLIF(?,0), ?, ?, NULLIF(?,''), ?, 'Posted', NULLIF(?,0))"
                );
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to post payment.');
                }
                $stmt->bind_param('iisssdi', $reservationId, $guestId, $paymentType, $methodFinal, $reference, $amountF, $currentUserId);
                $ok = $stmt->execute();
                $paymentIdNew = (int)($stmt->insert_id ?? 0);
                $stmt->close();
                if (!$ok || $paymentIdNew <= 0) {
                    throw new RuntimeException('Failed to post payment.');
                }

                if ($paymentType === 'Payment' && $guestId > 0 && $hasGuests && $hasGuestLoyaltyPoints && $hasLoyaltyTxns && $hasLoyaltyEarnPosts) {
                    $earned = (int)floor(max(0.0, $amountF) / 100);
                    if ($earned > 0) {
                        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM loyalty_earn_posts WHERE source_type = 'RES_PAYMENT' AND source_id = ?");
                        $alreadyEarned = false;
                        if ($stmt instanceof mysqli_stmt) {
                            $stmt->bind_param('i', $paymentIdNew);
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

                            $ref = $reference !== '' ? ('RES ' . $reservationId . ' ' . $reference) : ('RES ' . $reservationId . ' PAY#' . $paymentIdNew);
                            $stmt = $conn->prepare(
                                "INSERT INTO loyalty_transactions (guest_id, txn_type, points, reference, created_by)
                                 VALUES (?, 'Earn', ?, ?, NULLIF(?,0))"
                            );
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to save loyalty transaction.');
                            }
                            $stmt->bind_param('iisi', $guestId, $earned, $ref, $currentUserId);
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

                            $stmt = $conn->prepare("INSERT INTO loyalty_earn_posts (source_type, source_id, guest_id, points, created_by) VALUES ('RES_PAYMENT', ?, ?, ?, NULLIF(?,0))");
                            if (!($stmt instanceof mysqli_stmt)) {
                                throw new RuntimeException('Failed to record loyalty earn posting.');
                            }
                            $stmt->bind_param('iiii', $paymentIdNew, $guestId, $earned, $currentUserId);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if (!$ok) {
                                throw new RuntimeException('Failed to record loyalty earn posting.');
                            }
                        }
                    }
                }

                $conn->commit();
                Flash::set('success', 'Payment entry posted.');
                Response::redirect('billing_payments.php?reservation_id=' . $reservationId);
            } catch (Throwable $e) {
                $conn->rollback();
                $errors['general'] = $e->getMessage();
            }
        }
    }
}

$guests = [];
$reservations = [];
$selectedReservation = null;
$selectedGuest = null;
$charges = [];
$payments = [];

$totCharges = 0.0;
$totPayments = 0.0;
$balance = 0.0;

$roomChargeSuggested = 0.0;
$roomChargeNights = 0;
$roomChargeNightlyRate = 0.0;

$roomsUsed = [];
$posOrders = [];
$posOrderItemsByOrderId = [];
$eventCharges = [];
$eventChargesTotal = 0.0;

$guestFolioChargesTotal = 0.0;
$guestFolioPaymentsTotal = 0.0;
$guestFolioBalanceTotal = 0.0;
$guestPosPaidTotal = 0.0;
$guestEventsEstimatedTotal = 0.0;
$guestFolioPosChargesTotal = 0.0;
$guestFolioEventChargesTotal = 0.0;
$guestPosUnpostedTotal = 0.0;
$guestEventsUnpostedTotal = 0.0;
$guestActivityTotal = 0.0;

$guestReservations = [];
$guestPosOrders = [];
$guestEvents = [];

if ($conn && $hasGuests) {
    $sql = 'SELECT id, first_name, last_name, phone, email FROM guests';
    $where = [];
    $types = '';
    $params = [];
    if ($gq !== '') {
        $like = '%' . $gq . '%';
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';

    $stmt = $conn->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        if ($types !== '') {
            $bind = [];
            $bind[] = $types;
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $guests[] = $row;
        }
        $stmt->close();
    }
}

if ($conn && $hasReservations && $hasGuests && $reservationId > 0) {
        $stmt = $conn->prepare(
            "SELECT r.id, r.reference_no, r.status, r.checkin_date, r.checkout_date,
                    r.deposit_amount, r.payment_method,
                    g.id AS guest_id, g.first_name, g.last_name, g.phone, g.email
             FROM reservations r
             INNER JOIN guests g ON g.id = r.guest_id
             WHERE r.id = ?
             LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $res = $stmt->get_result();
            $selectedReservation = $res->fetch_assoc() ?: null;
            $stmt->close();
        }
}

if ($selectedReservation && $guestId <= 0) {
    $guestId = (int)($selectedReservation['guest_id'] ?? 0);
}

if ($conn && $hasGuests && $guestId > 0) {
    $tierSelect = $hasGuestLoyaltyTier ? 'loyalty_tier' : "NULL AS loyalty_tier";
    $pointsSelect = $hasGuestLoyaltyPoints ? 'loyalty_points' : '0 AS loyalty_points';
    $stmt = $conn->prepare("SELECT id, first_name, last_name, phone, email, {$tierSelect}, {$pointsSelect} FROM guests WHERE id = ? LIMIT 1");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $selectedGuest = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}

if ($conn && $hasReservations && $guestId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, reference_no, status, checkin_date, checkout_date, deposit_amount, payment_method
         FROM reservations
         WHERE guest_id = ?
         ORDER BY id DESC
         LIMIT 100"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $guestReservations[] = $row;
        }
        $stmt->close();
    }
}

if ($conn && $hasPosOrders && $guestId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, order_no, order_type, status, total, created_at
         FROM pos_orders
         WHERE (guest_id = ? OR reservation_id IN (SELECT id FROM reservations WHERE guest_id = ?))
           AND status = 'Paid'
         ORDER BY id DESC
         LIMIT 50"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('ii', $guestId, $guestId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $guestPosOrders[] = $row;
        }
        $stmt->close();
    }
}

if ($conn && $hasEvents && $guestId > 0 && $selectedGuest) {
    $guestPhone = preg_replace('/\D+/', '', (string)($selectedGuest['phone'] ?? ''));
    $guestEmail = trim((string)($selectedGuest['email'] ?? ''));

    $sql =
        'SELECT id, event_no, title, client_name, client_phone, client_email, event_date, start_time, end_time, status, estimated_total, deposit_amount, notes'
        . ' FROM events';

    $where = [];
    $types = '';
    $params = [];

    if ($eventsHasClientGuestId) {
        $where[] = 'client_guest_id = ?';
        $types .= 'i';
        $params[] = $guestId;
    }
    if ($eventsHasClientUserId) {
        $where[] = 'client_user_id IN (SELECT id FROM users WHERE guest_id = ?)';
        $types .= 'i';
        $params[] = $guestId;
    }
    if ($guestEmail !== '') {
        $where[] = 'client_email = ?';
        $types .= 's';
        $params[] = $guestEmail;
    }
    if ($guestPhone !== '') {
        $where[] = 'REPLACE(REPLACE(REPLACE(REPLACE(client_phone, "-", ""), " ", ""), "(", ""), ")", "") LIKE ?';
        $types .= 's';
        $params[] = '%' . $guestPhone;
    }

    if (!empty($where)) {
        $sql .= ' WHERE (' . implode(' OR ', $where) . ')';
    } else {
        $sql .= ' WHERE 1=0';
    }
    $sql .= ' ORDER BY id DESC LIMIT 50';

    $stmt = $conn->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        if ($types !== '') {
            $bind = [];
            $bind[] = $types;
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $guestEvents[] = $row;
        }
        $stmt->close();
    }
}

if ($conn && $guestId > 0) {
    if ($hasReservations && $hasFolioCharges) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(c.amount), 0) AS total\n             FROM reservation_folio_charges c\n             INNER JOIN reservations r ON r.id = c.reservation_id\n             WHERE r.guest_id = ?"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $guestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $guestFolioChargesTotal = (float)($row['total'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "SELECT\n                COALESCE(SUM(CASE WHEN c.charge_type = 'POS' THEN c.amount ELSE 0 END), 0) AS pos_total,\n                COALESCE(SUM(CASE WHEN c.charge_type = 'Event' THEN c.amount ELSE 0 END), 0) AS event_total\n             FROM reservation_folio_charges c\n             INNER JOIN reservations r ON r.id = c.reservation_id\n             WHERE r.guest_id = ?"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $guestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $guestFolioPosChargesTotal = (float)($row['pos_total'] ?? 0);
            $guestFolioEventChargesTotal = (float)($row['event_total'] ?? 0);
            $stmt->close();
        }
    }

    if ($hasReservations && $hasReservationPayments) {
        $stmt = $conn->prepare(
            "SELECT\n                COALESCE(SUM(CASE WHEN p.payment_type = 'Payment' THEN p.amount WHEN p.payment_type = 'Refund' THEN -p.amount ELSE 0 END), 0) AS total\n             FROM reservation_payments p\n             INNER JOIN reservations r ON r.id = p.reservation_id\n             WHERE r.guest_id = ? AND p.status = 'Posted'"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $guestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $guestFolioPaymentsTotal = (float)($row['total'] ?? 0);
            $stmt->close();
        }
    }

    if ($hasPosOrders) {
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(total), 0) AS total\n             FROM pos_orders\n             WHERE (guest_id = ? OR reservation_id IN (SELECT id FROM reservations WHERE guest_id = ?))\n               AND status = 'Paid'"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ii', $guestId, $guestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $guestPosPaidTotal = (float)($row['total'] ?? 0);
            $stmt->close();
        }
    }

    if (!empty($guestEvents)) {
        foreach ($guestEvents as $e) {
            $guestEventsEstimatedTotal += (float)($e['estimated_total'] ?? 0);
        }
    }

    $guestFolioBalanceTotal = $guestFolioChargesTotal - $guestFolioPaymentsTotal;

    $guestPosUnpostedTotal = max(0.0, $guestPosPaidTotal - $guestFolioPosChargesTotal);
    $guestEventsUnpostedTotal = max(0.0, $guestEventsEstimatedTotal - $guestFolioEventChargesTotal);
    $guestActivityTotal = $guestFolioChargesTotal + $guestPosUnpostedTotal + $guestEventsUnpostedTotal;
}

if ($conn && $selectedReservation && $hasFolioCharges) {
    $stmt = $conn->prepare(
        "SELECT id, charge_type, description, amount, created_at
         FROM reservation_folio_charges
         WHERE reservation_id = ?
         ORDER BY id DESC"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $charges[] = $row;
            $totCharges += (float)($row['amount'] ?? 0);

            if ((string)($row['charge_type'] ?? '') === 'Event') {
                $eventCharges[] = $row;
                $eventChargesTotal += (float)($row['amount'] ?? 0);
            }
        }
        $stmt->close();
    }
}

if ($conn && $selectedReservation && $hasReservationPayments) {
    $stmt = $conn->prepare(
        "SELECT id, payment_type, method, reference, amount, status, created_at
         FROM reservation_payments
         WHERE reservation_id = ?
         ORDER BY id DESC"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $payments[] = $row;
            if (((string)($row['status'] ?? '')) === 'Posted') {
                $type = (string)($row['payment_type'] ?? 'Payment');
                $amt = (float)($row['amount'] ?? 0);
                if ($type === 'Payment') {
                    $totPayments += $amt;
                } elseif ($type === 'Refund') {
                    $totPayments -= $amt;
                }
            }
        }
        $stmt->close();
    }
}

if ($conn && $selectedReservation && $hasReservationRooms) {
    $checkin = (string)($selectedReservation['checkin_date'] ?? '');
    $checkout = (string)($selectedReservation['checkout_date'] ?? '');
    $t1 = strtotime($checkin);
    $t2 = strtotime($checkout);
    if ($t1 !== false && $t2 !== false && $t2 > $t1) {
        $roomChargeNights = (int)round(($t2 - $t1) / 86400);
    }

    $stmt = $conn->prepare('SELECT COALESCE(SUM(rate),0) AS s FROM reservation_rooms WHERE reservation_id = ?');
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $roomChargeNightlyRate = (float)($row['s'] ?? 0);
    }

    $roomChargeSuggested = max(0.0, (float)$roomChargeNights * (float)$roomChargeNightlyRate);

    $stmt = $conn->prepare(
        "SELECT rr.room_id, rr.room_type_id, rr.rate, rr.adults, rr.children,
                rooms.room_no, rooms.floor,
                rt.code AS room_type_code, rt.name AS room_type_name
         FROM reservation_rooms rr
         LEFT JOIN rooms ON rooms.id = rr.room_id
         LEFT JOIN room_types rt ON rt.id = rr.room_type_id
         WHERE rr.reservation_id = ?"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $roomsUsed[] = $row;
        }
        $stmt->close();
    }
}

if ($conn && $selectedReservation && $hasPosOrders) {
    $stmt = $conn->prepare(
        "SELECT id, order_no, order_type, status, subtotal, tax, service_charge, total, created_at
         FROM pos_orders
         WHERE reservation_id = ? AND status IN ('Paid')
         ORDER BY id DESC
         LIMIT 20"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $posOrders[] = $row;
        }
        $stmt->close();
    }

    if ($hasPosOrderItems && $hasMenuItems && !empty($posOrders)) {
        $stmt = $conn->prepare(
            "SELECT i.pos_order_id, i.qty, i.price, i.line_total, mi.name
             FROM pos_order_items i
             INNER JOIN menu_items mi ON mi.id = i.menu_item_id
             WHERE i.pos_order_id = ?
             ORDER BY i.id ASC"
        );
        if ($stmt instanceof mysqli_stmt) {
            foreach ($posOrders as $o) {
                $oid = (int)($o['id'] ?? 0);
                if ($oid <= 0) continue;
                $stmt->bind_param('i', $oid);
                $stmt->execute();
                $res = $stmt->get_result();
                $posOrderItemsByOrderId[$oid] = [];
                while ($row = $res->fetch_assoc()) {
                    $posOrderItemsByOrderId[$oid][] = $row;
                }
            }
            $stmt->close();
        }
    }
}

$balance = $totCharges - $totPayments;

$pendingApprovals = [];

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Billing & Payments</h1>
            <p class="text-sm text-gray-500 mt-1">Guest folios, charges ledger, settlements, payment methods</p>
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

        <?php if (!$hasReservations || !$hasFolioCharges || !$hasReservationPayments): ?>
            <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                This module needs DB tables. Run the updated schema SQL to create <span class="font-medium">reservation_folio_charges</span> and <span class="font-medium">reservation_payments</span>.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Guests</h3>
                    <div class="text-xs text-gray-500">Search</div>
                </div>
                <form method="get" class="mb-3">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>" />
                    <input name="gq" value="<?= htmlspecialchars($gq) ?>" placeholder="Search guest name / phone / email" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </form>

                <?php if (empty($guests)): ?>
                    <div class="text-sm text-gray-500">No guests found.</div>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 520px; overflow:auto;">
                        <?php foreach ($guests as $g): ?>
                            <?php
                                $gid = (int)($g['id'] ?? 0);
                                $active = $guestId > 0 && $gid === $guestId;
                                $full = trim((string)($g['first_name'] ?? '') . ' ' . (string)($g['last_name'] ?? ''));
                            ?>
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?guest_id=<?= $gid ?>&tab=<?= htmlspecialchars($tab) ?>" class="block rounded-lg border px-3 py-2 <?= $active ? 'border-blue-200 bg-blue-50' : 'border-gray-100 hover:bg-gray-50' ?>">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($full) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($g['phone'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($g['email'] ?? '')) ?></div>
                                    </div>
                                    <div class="text-xs text-gray-700">#<?= $gid ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <?php if (!$selectedGuest && !$selectedReservation): ?>
                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="text-sm text-gray-500">Select a guest to view transactions.</div>
                    </div>
                <?php else: ?>
                    <?php
                        $guestDisplay = $selectedGuest ?: ($selectedReservation ? [
                            'id' => (int)($selectedReservation['guest_id'] ?? 0),
                            'first_name' => (string)($selectedReservation['first_name'] ?? ''),
                            'last_name' => (string)($selectedReservation['last_name'] ?? ''),
                            'phone' => (string)($selectedReservation['phone'] ?? ''),
                            'email' => (string)($selectedReservation['email'] ?? ''),
                        ] : null);
                        $guestDisplayName = $guestDisplay ? trim((string)($guestDisplay['first_name'] ?? '') . ' ' . (string)($guestDisplay['last_name'] ?? '')) : '';
                    ?>

                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Guest</h3>
                                <div class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($guestDisplayName) ?> • <?= htmlspecialchars((string)($guestDisplay['phone'] ?? '')) ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($guestDisplay['email'] ?? '')) ?></div>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 mt-4">
                            <a class="px-3 py-2 rounded-lg border text-sm <?= $tab === 'reservations' ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-gray-100 hover:bg-gray-50' ?>" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?guest_id=<?= (int)($guestDisplay['id'] ?? 0) ?>&tab=reservations">Reservations</a>
                            <a class="px-3 py-2 rounded-lg border text-sm <?= $tab === 'pos' ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-gray-100 hover:bg-gray-50' ?>" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?guest_id=<?= (int)($guestDisplay['id'] ?? 0) ?>&tab=pos">POS</a>
                            <a class="px-3 py-2 rounded-lg border text-sm <?= $tab === 'events' ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-gray-100 hover:bg-gray-50' ?>" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?guest_id=<?= (int)($guestDisplay['id'] ?? 0) ?>&tab=events">Events</a>
                        </div>
                    </div>

                    <?php if ($reservationId <= 0): ?>
                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <?php if ($tab === 'reservations'): ?>
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-medium text-gray-900">Reservations</h3>
                                    <div class="text-xs text-gray-500"><?= count($guestReservations) ?> found</div>
                                </div>
                                <?php if (empty($guestReservations)): ?>
                                    <div class="text-sm text-gray-500">No reservations for this guest.</div>
                                <?php else: ?>
                                    <div class="overflow-auto rounded-lg border border-gray-100">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="text-left font-medium px-4 py-3">Reference</th>
                                                    <th class="text-left font-medium px-4 py-3">Dates</th>
                                                    <th class="text-left font-medium px-4 py-3">Status</th>
                                                    <th class="text-right font-medium px-4 py-3">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <?php foreach ($guestReservations as $r): ?>
                                                    <?php $rid = (int)($r['id'] ?? 0); ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-right"><a class="text-blue-700 hover:underline" href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?guest_id=<?= (int)($guestDisplay['id'] ?? 0) ?>&tab=reservations&reservation_id=<?= $rid ?>">Open Folio</a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($tab === 'pos'): ?>
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-medium text-gray-900">POS Orders (Paid)</h3>
                                    <div class="text-xs text-gray-500"><?= count($guestPosOrders) ?> found</div>
                                </div>
                                <?php if (empty($guestPosOrders)): ?>
                                    <div class="text-sm text-gray-500">No paid POS orders linked to this guest.</div>
                                <?php else: ?>
                                    <div class="overflow-auto rounded-lg border border-gray-100">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="text-left font-medium px-4 py-3">Order</th>
                                                    <th class="text-left font-medium px-4 py-3">Type</th>
                                                    <th class="text-left font-medium px-4 py-3">When</th>
                                                    <th class="text-right font-medium px-4 py-3">Total</th>
                                                    <th class="text-right font-medium px-4 py-3">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <?php foreach ($guestPosOrders as $o): ?>
                                                    <?php $oid = (int)($o['id'] ?? 0); ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($o['order_no'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($o['order_type'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($o['created_at'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($o['total'] ?? 0), 2) ?></td>
                                                        <td class="px-4 py-3 text-right">
                                                            <form method="get" class="inline-flex items-center gap-2">
                                                                <input type="hidden" name="guest_id" value="<?= (int)($guestDisplay['id'] ?? 0) ?>" />
                                                                <input type="hidden" name="tab" value="reservations" />
                                                                <input type="hidden" name="prefill_charge_type" value="POS" />
                                                                <input type="hidden" name="prefill_charge_source_id" value="<?= $oid ?>" />
                                                                <input type="hidden" name="prefill_charge_amount" value="<?= htmlspecialchars((string)($o['total'] ?? 0)) ?>" />
                                                                <input type="hidden" name="prefill_charge_desc" value="<?= htmlspecialchars('POS ' . (string)($o['order_no'] ?? '')) ?>" />
                                                                <select name="reservation_id" class="border border-gray-200 rounded-lg px-2 py-1 text-xs">
                                                                    <?php foreach ($guestReservations as $r): ?>
                                                                        <option value="<?= (int)($r['id'] ?? 0) ?>"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button class="text-blue-700 hover:underline text-xs" type="submit">Open Folio</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-medium text-gray-900">Events</h3>
                                    <div class="text-xs text-gray-500"><?= count($guestEvents) ?> found</div>
                                </div>
                                <?php if (empty($guestEvents)): ?>
                                    <div class="text-sm text-gray-500">No events linked to this guest.</div>
                                <?php else: ?>
                                    <div class="overflow-auto rounded-lg border border-gray-100">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="text-left font-medium px-4 py-3">Event</th>
                                                    <th class="text-left font-medium px-4 py-3">Date</th>
                                                    <th class="text-left font-medium px-4 py-3">Status</th>
                                                    <th class="text-right font-medium px-4 py-3">Total</th>
                                                    <th class="text-right font-medium px-4 py-3">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <?php foreach ($guestEvents as $e): ?>
                                                    <?php $eid = (int)($e['id'] ?? 0); ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['event_no'] ?? '')) ?> • <?= htmlspecialchars((string)($e['title'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['event_date'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($e['status'] ?? '')) ?></td>
                                                        <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($e['estimated_total'] ?? 0), 2) ?></td>
                                                        <td class="px-4 py-3 text-right">
                                                            <form method="get" class="inline-flex items-center gap-2">
                                                                <input type="hidden" name="guest_id" value="<?= (int)($guestDisplay['id'] ?? 0) ?>" />
                                                                <input type="hidden" name="tab" value="reservations" />
                                                                <input type="hidden" name="prefill_charge_type" value="Event" />
                                                                <input type="hidden" name="prefill_charge_source_id" value="<?= $eid ?>" />
                                                                <input type="hidden" name="prefill_charge_amount" value="<?= htmlspecialchars((string)($e['estimated_total'] ?? 0)) ?>" />
                                                                <input type="hidden" name="prefill_charge_desc" value="<?= htmlspecialchars('Event ' . (string)($e['event_no'] ?? '') . ' - ' . (string)($e['title'] ?? '')) ?>" />
                                                                <select name="reservation_id" class="border border-gray-200 rounded-lg px-2 py-1 text-xs">
                                                                    <?php foreach ($guestReservations as $r): ?>
                                                                        <option value="<?= (int)($r['id'] ?? 0) ?>"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button class="text-blue-700 hover:underline text-xs" type="submit">Open Folio</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Folio Summary</h3>
                                <div class="text-sm text-gray-500 mt-1">
                                    <?= htmlspecialchars((string)($selectedReservation['reference_no'] ?? '')) ?> •
                                    <?= htmlspecialchars(trim((string)($selectedReservation['first_name'] ?? '') . ' ' . (string)($selectedReservation['last_name'] ?? ''))) ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?= htmlspecialchars((string)($selectedReservation['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($selectedReservation['checkout_date'] ?? '')) ?> •
                                    <?= htmlspecialchars((string)($selectedReservation['status'] ?? '')) ?>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 rounded-lg border border-gray-100 p-4">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-medium text-gray-900">Overall Guest Summary</div>
                                <div class="text-xs text-gray-500">All reservations + POS + events</div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                <div class="rounded-lg border border-gray-100 p-4">
                                    <div class="text-xs text-gray-500">Total Activity</div>
                                    <div class="text-xl font-medium text-gray-900">₱<?= number_format((float)$guestActivityTotal, 2) ?></div>
                                    <div class="text-[11px] text-gray-500 mt-1">Folio charges + unposted POS + unposted events</div>
                                </div>
                                <div class="rounded-lg border border-gray-100 p-4">
                                    <div class="text-xs text-gray-500">Folio Charges / Payments</div>
                                    <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)$guestFolioChargesTotal, 2) ?> / ₱<?= number_format((float)$guestFolioPaymentsTotal, 2) ?></div>
                                    <div class="text-[11px] text-gray-500 mt-1">Balance: ₱<?= number_format((float)$guestFolioBalanceTotal, 2) ?></div>
                                </div>
                                <div class="rounded-lg border border-gray-100 p-4">
                                    <div class="text-xs text-gray-500">POS / Events (Unposted)</div>
                                    <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)$guestPosUnpostedTotal, 2) ?> / ₱<?= number_format((float)$guestEventsUnpostedTotal, 2) ?></div>
                                    <div class="text-[11px] text-gray-500 mt-1">Posted in folio: POS ₱<?= number_format((float)$guestFolioPosChargesTotal, 2) ?> • Events ₱<?= number_format((float)$guestFolioEventChargesTotal, 2) ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                            <div class="rounded-lg border border-gray-100 p-4">
                                <div class="text-xs text-gray-500">Total Charges</div>
                                <div class="text-xl font-medium text-gray-900">₱<?= number_format($totCharges, 2) ?></div>
                            </div>
                            <div class="rounded-lg border border-gray-100 p-4">
                                <div class="text-xs text-gray-500">Total Payments</div>
                                <div class="text-xl font-medium text-gray-900">₱<?= number_format($totPayments, 2) ?></div>
                            </div>
                            <div class="rounded-lg border border-gray-100 p-4">
                                <div class="text-xs text-gray-500">Balance</div>
                                <div class="text-xl font-medium <?= $balance > 0 ? 'text-red-700' : 'text-green-700' ?>">₱<?= number_format($balance, 2) ?></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div class="rounded-lg border border-gray-100 p-4">
                                <div class="text-xs text-gray-500">Room Charges (Suggested)</div>
                                <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)$roomChargeSuggested, 2) ?></div>
                                <div class="text-[11px] text-gray-500 mt-1"><?= (int)$roomChargeNights ?> night(s) • ₱<?= number_format((float)$roomChargeNightlyRate, 2) ?>/night</div>
                            </div>
                            <div class="rounded-lg border border-gray-100 p-4">
                                <div class="text-xs text-gray-500">POS Purchases (Paid)</div>
                                <?php
                                    $posTotal = 0.0;
                                    foreach ($posOrders as $o) {
                                        $posTotal += (float)($o['total'] ?? 0);
                                    }
                                ?>
                                <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)$posTotal, 2) ?></div>
                                <div class="text-[11px] text-gray-500 mt-1"><?= count($posOrders) ?> order(s)</div>
                            </div>
                            <div class="rounded-lg border border-gray-100 p-4">
                                <div class="text-xs text-gray-500">Event Charges (Posted)</div>
                                <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)$eventChargesTotal, 2) ?></div>
                                <div class="text-[11px] text-gray-500 mt-1"><?= count($eventCharges) ?> charge(s)</div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Post Charge</h3>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="add_charge" />
                                <input type="hidden" name="reservation_id" value="<?= (int)$reservationId ?>" />
                                <input type="hidden" id="roomChargeSuggested" value="<?= htmlspecialchars((string)$roomChargeSuggested) ?>" />
                                <input type="hidden" id="roomChargeNights" value="<?= (int)$roomChargeNights ?>" />
                                <input type="hidden" id="roomChargeNightlyRate" value="<?= htmlspecialchars((string)$roomChargeNightlyRate) ?>" />
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                    <select id="chargeTypeSelect" name="charge_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="Other" <?= $prefillChargeType === 'Other' ? 'selected' : '' ?>>Other</option>
                                        <option value="Room" <?= $prefillChargeType === 'Room' ? 'selected' : '' ?>>Room</option>
                                        <option value="POS" <?= $prefillChargeType === 'POS' ? 'selected' : '' ?>>POS</option>
                                        <option value="Event" <?= $prefillChargeType === 'Event' ? 'selected' : '' ?>>Event</option>
                                        <option value="Adjustment" <?= $prefillChargeType === 'Adjustment' ? 'selected' : '' ?>>Adjustment</option>
                                    </select>
                                    <?php if (isset($errors['charge_type'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['charge_type']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <input id="chargeDescInput" name="description" value="<?= htmlspecialchars($prefillChargeDesc) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['description'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <input id="chargeAmountInput" name="amount" value="<?= htmlspecialchars($prefillChargeAmount !== '' ? $prefillChargeAmount : '0') ?>" type="number" step="0.01" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['amount'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['amount']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="source_id" value="<?= (int)$prefillChargeSourceId ?>" />
                                <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Post Charge</button>
                            </form>

                            <script>
                                (function () {
                                    var typeSel = document.getElementById('chargeTypeSelect');
                                    var desc = document.getElementById('chargeDescInput');
                                    var amt = document.getElementById('chargeAmountInput');
                                    var sug = document.getElementById('roomChargeSuggested');
                                    var nightsEl = document.getElementById('roomChargeNights');
                                    var rateEl = document.getElementById('roomChargeNightlyRate');

                                    function numVal(el) {
                                        var v = el ? String(el.value || '') : '';
                                        var n = Number(v);
                                        return isFinite(n) ? n : 0;
                                    }
                                    function setAmount(v) {
                                        if (!amt) return;
                                        var n = Number(v);
                                        if (!isFinite(n) || n < 0) n = 0;
                                        amt.value = n.toFixed(2);
                                    }

                                    function applyRoomAuto() {
                                        if (!typeSel || !desc || !amt) return;
                                        var t = String(typeSel.value || 'Other');
                                        if (t === 'Room') {
                                            var nights = numVal(nightsEl);
                                            var rate = numVal(rateEl);
                                            desc.value = 'Room charges (auto)';
                                            desc.readOnly = true;
                                            setAmount(numVal(sug));
                                            amt.readOnly = true;
                                            amt.classList.add('bg-gray-50');
                                            if (nights > 0 && rate > 0) {
                                                amt.title = nights + ' night(s) x ₱' + rate.toFixed(2);
                                            }
                                        } else {
                                            var wasAuto = !!amt.readOnly;
                                            desc.readOnly = false;
                                            amt.readOnly = false;
                                            amt.classList.remove('bg-gray-50');
                                            amt.removeAttribute('title');
                                            if (desc.value === 'Room charges (auto)') {
                                                desc.value = '';
                                            }
                                            if (wasAuto) {
                                                setAmount(0);
                                            }
                                        }
                                    }

                                    if (typeSel) {
                                        typeSel.addEventListener('change', applyRoomAuto);
                                    }
                                    applyRoomAuto();
                                })();
                            </script>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Post Payment / Refund</h3>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="add_payment" />
                                <input type="hidden" name="reservation_id" value="<?= (int)$reservationId ?>" />
                                <input type="hidden" id="folioBalance" value="<?= htmlspecialchars((string)$balance) ?>" />
                                <input type="hidden" id="folioPaid" value="<?= htmlspecialchars((string)$totPayments) ?>" />
                                <input type="hidden" id="reservationPaymentMethod" value="<?= htmlspecialchars((string)($selectedReservation['payment_method'] ?? '')) ?>" />
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                    <select id="paymentTypeSelect" name="payment_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="Payment">Payment</option>
                                        <option value="Refund">Refund</option>
                                        <option value="Adjustment">Adjustment</option>
                                    </select>
                                    <?php if (isset($errors['payment_type'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['payment_type']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Method</label>
                                    <input type="hidden" name="method" id="methodHidden" value="" />
                                    <select id="methodSelect" name="method" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="Cash">Cash</option>
                                        <option value="Card">Card</option>
                                        <option value="GCash">GCash</option>
                                        <option value="Bank Transfer">Bank Transfer</option>
                                    </select>
                                    <?php if (isset($errors['method'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['method']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference (optional)</label>
                                    <input name="reference" placeholder="OR#, Txn#, Notes" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['reference'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['reference']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <div class="flex items-center gap-2">
                                        <input id="paymentAmountInput" name="amount" value="0" type="number" step="0.01" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                        <button type="button" id="payFullBtn" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Pay Full</button>
                                        <button type="button" id="clearAmtBtn" class="px-3 py-2 rounded-lg border border-gray-200 text-xs hover:bg-gray-50 transition">Clear</button>
                                    </div>
                                    <?php if (isset($errors['amount'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['amount']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Post</button>
                                <p class="text-xs text-gray-500">Refund reduces total payments. Adjustment posts a ledger entry without method rules.</p>
                            </form>

                            <?php if ($selectedReservation && $hasGuests && $hasGuestLoyaltyPoints && $hasLoyaltyTxns && $hasLoyaltyRedeemPosts): ?>
                                <?php
                                    $availPts = (int)($selectedGuest['loyalty_points'] ?? 0);
                                    $maxByBalance = (int)floor(max(0.0, (float)$balance));
                                    $maxRedeem = max(0, min($availPts, $maxByBalance));
                                ?>
                                <div class="mt-6 pt-6 border-t border-gray-100">
                                    <h4 class="text-sm font-medium text-gray-900 mb-2">Redeem Loyalty Points</h4>
                                    <div class="text-xs text-gray-500 mb-3">₱1 per point • Posts as Payment Method: Loyalty Points</div>
                                    <form method="post" class="space-y-2">
                                        <input type="hidden" name="action" value="redeem_loyalty" />
                                        <input type="hidden" name="reservation_id" value="<?= (int)$reservationId ?>" />
                                        <label class="block text-xs text-gray-600">Points to redeem</label>
                                        <input type="number" name="redeem_points" min="1" max="<?= (int)$maxRedeem ?>" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" <?= $maxRedeem <= 0 ? 'disabled' : '' ?> />
                                        <div class="text-[11px] text-gray-500">Available: <?= (int)$availPts ?> pts • Max for this folio: <?= (int)$maxRedeem ?> pts</div>
                                        <button class="w-full px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition" <?= $maxRedeem <= 0 ? 'disabled' : '' ?>>Redeem & Post Payment</button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <script>
                                (function () {
                                    var balanceEl = document.getElementById('folioBalance');
                                    var paidEl = document.getElementById('folioPaid');
                                    var fixedMethodEl = document.getElementById('reservationPaymentMethod');
                                    var typeSel = document.getElementById('paymentTypeSelect');
                                    var methodSel = document.getElementById('methodSelect');
                                    var methodHidden = document.getElementById('methodHidden');
                                    var amtInput = document.getElementById('paymentAmountInput');
                                    var payFullBtn = document.getElementById('payFullBtn');
                                    var clearBtn = document.getElementById('clearAmtBtn');

                                    function numVal(el) {
                                        var v = el ? String(el.value || '') : '';
                                        var n = Number(v);
                                        return isFinite(n) ? n : 0;
                                    }
                                    function setAmount(val) {
                                        if (!amtInput) return;
                                        var n = Number(val);
                                        if (!isFinite(n) || n < 0) n = 0;
                                        amtInput.value = n.toFixed(2);
                                    }

                                    function applyMethodLock() {
                                        if (!methodSel || !typeSel) return;
                                        var t = String(typeSel.value || 'Payment');
                                        var fixed = fixedMethodEl ? String(fixedMethodEl.value || '').trim() : '';
                                        var allowed = ['Cash', 'Card', 'GCash', 'Bank Transfer'];
                                        if (t !== 'Adjustment' && fixed !== '' && allowed.indexOf(fixed) >= 0) {
                                            methodSel.value = fixed;
                                            methodSel.disabled = true;
                                            if (methodHidden) methodHidden.value = fixed;
                                        } else {
                                            methodSel.disabled = (t === 'Adjustment');
                                            if (methodHidden) methodHidden.value = methodSel.value;
                                        }
                                    }

                                    function applyTypeDefaults() {
                                        if (!typeSel) return;
                                        var t = String(typeSel.value || 'Payment');
                                        if (t === 'Payment') {
                                            setAmount(Math.max(0, numVal(balanceEl)));
                                        } else if (t === 'Refund') {
                                            setAmount(Math.max(0, numVal(paidEl)));
                                        } else {
                                            setAmount(0);
                                        }
                                        applyMethodLock();
                                    }

                                    if (typeSel) {
                                        typeSel.addEventListener('change', applyTypeDefaults);
                                    }
                                    if (methodSel) {
                                        methodSel.addEventListener('change', function () {
                                            if (methodHidden) methodHidden.value = methodSel.value;
                                        });
                                    }
                                    if (payFullBtn) {
                                        payFullBtn.addEventListener('click', function () {
                                            applyTypeDefaults();
                                        });
                                    }
                                    if (clearBtn) {
                                        clearBtn.addEventListener('click', function () {
                                            setAmount(0);
                                        });
                                    }

                                    applyTypeDefaults();
                                })();
                            </script>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900">Rooms Used</h3>
                                <div class="text-xs text-gray-500"><?= count($roomsUsed) ?> room(s)</div>
                            </div>
                            <?php if (empty($roomsUsed)): ?>
                                <div class="text-sm text-gray-500">No rooms linked to this reservation yet.</div>
                            <?php else: ?>
                                <div class="overflow-auto rounded-lg border border-gray-100">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 text-gray-600">
                                            <tr>
                                                <th class="text-left font-medium px-4 py-3">Room</th>
                                                <th class="text-left font-medium px-4 py-3">Type</th>
                                                <th class="text-right font-medium px-4 py-3">Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach ($roomsUsed as $rr): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($rr['room_no'] ?? '')) ?></td>
                                                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($rr['room_type_name'] ?? '')) ?></td>
                                                    <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($rr['rate'] ?? 0), 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900">POS Purchases</h3>
                                <div class="text-xs text-gray-500"><?= count($posOrders) ?> paid order(s)</div>
                            </div>
                            <?php if (empty($posOrders)): ?>
                                <div class="text-sm text-gray-500">No POS orders linked to this reservation.</div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($posOrders as $o): ?>
                                        <?php $oid = (int)($o['id'] ?? 0); ?>
                                        <div class="rounded-lg border border-gray-100 p-4">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($o['order_no'] ?? '')) ?></div>
                                                    <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($o['order_type'] ?? '')) ?> • <?= htmlspecialchars((string)($o['created_at'] ?? '')) ?></div>
                                                </div>
                                                <div class="text-sm font-medium text-gray-900">₱<?= number_format((float)($o['total'] ?? 0), 2) ?></div>
                                            </div>
                                            <?php $items = $posOrderItemsByOrderId[$oid] ?? []; ?>
                                            <?php if (!empty($items)): ?>
                                                <div class="mt-3 rounded-lg border border-gray-100 overflow-auto">
                                                    <table class="min-w-full text-xs">
                                                        <thead class="bg-gray-50 text-gray-600">
                                                            <tr>
                                                                <th class="text-left font-medium px-3 py-2">Item</th>
                                                                <th class="text-right font-medium px-3 py-2">Qty</th>
                                                                <th class="text-right font-medium px-3 py-2">Total</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100">
                                                            <?php foreach ($items as $it): ?>
                                                                <tr>
                                                                    <td class="px-3 py-2 text-gray-700"><?= htmlspecialchars((string)($it['name'] ?? '')) ?></td>
                                                                    <td class="px-3 py-2 text-right text-gray-700"><?= (int)($it['qty'] ?? 0) ?></td>
                                                                    <td class="px-3 py-2 text-right text-gray-700">₱<?= number_format((float)($it['line_total'] ?? 0), 2) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-medium text-gray-900">Event Place Charges</h3>
                                <div class="text-xs text-gray-500">₱<?= number_format((float)$eventChargesTotal, 2) ?></div>
                            </div>
                            <?php if (empty($eventCharges)): ?>
                                <div class="text-sm text-gray-500">No event charges posted to folio.</div>
                            <?php else: ?>
                                <div class="overflow-auto rounded-lg border border-gray-100">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 text-gray-600">
                                            <tr>
                                                <th class="text-left font-medium px-4 py-3">When</th>
                                                <th class="text-left font-medium px-4 py-3">Description</th>
                                                <th class="text-right font-medium px-4 py-3">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <?php foreach ($eventCharges as $c): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($c['created_at'] ?? '')) ?></td>
                                                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($c['description'] ?? '')) ?></td>
                                                    <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($c['amount'] ?? 0), 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Charges Ledger</h3>
                            <div class="text-xs text-gray-500"><?= count($charges) ?> entries</div>
                        </div>
                        <?php if (empty($charges)): ?>
                            <div class="text-sm text-gray-500">No charges yet.</div>
                        <?php else: ?>
                            <div class="overflow-auto rounded-lg border border-gray-100">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600">
                                        <tr>
                                            <th class="text-left font-medium px-4 py-3">When</th>
                                            <th class="text-left font-medium px-4 py-3">Type</th>
                                            <th class="text-left font-medium px-4 py-3">Description</th>
                                            <th class="text-right font-medium px-4 py-3">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($charges as $c): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($c['created_at'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($c['charge_type'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($c['description'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($c['amount'] ?? 0), 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Payments Ledger</h3>
                            <div class="text-xs text-gray-500"><?= count($payments) ?> entries</div>
                        </div>
                        <?php if (empty($payments)): ?>
                            <div class="text-sm text-gray-500">No payments yet.</div>
                        <?php else: ?>
                            <div class="overflow-auto rounded-lg border border-gray-100">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600">
                                        <tr>
                                            <th class="text-left font-medium px-4 py-3">When</th>
                                            <th class="text-left font-medium px-4 py-3">Type</th>
                                            <th class="text-left font-medium px-4 py-3">Method</th>
                                            <th class="text-left font-medium px-4 py-3">Reference</th>
                                            <th class="text-right font-medium px-4 py-3">Amount</th>
                                            <th class="text-left font-medium px-4 py-3">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($payments as $p): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($p['created_at'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($p['payment_type'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($p['method'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($p['reference'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)($p['amount'] ?? 0), 2) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($p['status'] ?? '')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php'; ?>
<?php endif; ?>
