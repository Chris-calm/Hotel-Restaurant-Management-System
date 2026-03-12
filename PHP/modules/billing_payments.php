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
        }
    } catch (Throwable $e) {
    }
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$reservationId = Request::int('get', 'reservation_id', 0);
$q = trim((string)Request::get('q', ''));

if (Request::isPost() && $conn && $hasReservations) {
    $action = (string)Request::post('action', '');
    $reservationIdPost = Request::int('post', 'reservation_id', 0);
    if ($reservationIdPost > 0) {
        $reservationId = $reservationIdPost;
    }

    if ($action === 'add_charge' && $hasFolioCharges) {
        $chargeType = (string)Request::post('charge_type', 'Other');
        $description = trim((string)Request::post('description', ''));
        $amount = (string)Request::post('amount', '0');

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

            $amountF = (float)$amount;
            $stmt = $conn->prepare(
                "INSERT INTO reservation_folio_charges (reservation_id, guest_id, charge_type, source_id, description, amount, created_by)
                 VALUES (?, NULLIF(?,0), ?, NULL, ?, ?, NULLIF(?,0))"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('iissdi', $reservationId, $guestId, $chargeType, $description, $amountF, $currentUserId);
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

    if ($action === 'add_payment' && $hasReservationPayments) {
        $paymentType = (string)Request::post('payment_type', 'Payment');
        $method = trim((string)Request::post('method', ''));
        $reference = trim((string)Request::post('reference', ''));
        $amount = (string)Request::post('amount', '0');

        if ($reservationId <= 0) {
            $errors['reservation_id'] = 'Reservation is required.';
        }
        if (!in_array($paymentType, ['Payment', 'Refund', 'Adjustment'], true)) {
            $errors['payment_type'] = 'Payment type is invalid.';
        }
        if ($paymentType !== 'Adjustment' && !in_array($method, ['Cash', 'Card', 'GCash', 'Bank Transfer'], true)) {
            $errors['method'] = 'Payment method is invalid.';
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

            $amountF = (float)$amount;
            $methodFinal = ($paymentType === 'Adjustment') ? null : $method;

            $stmt = $conn->prepare(
                "INSERT INTO reservation_payments (reservation_id, guest_id, payment_type, method, reference, amount, status, created_by)
                 VALUES (?, NULLIF(?,0), ?, ?, NULLIF(?,''), ?, 'Posted', NULLIF(?,0))"
            );
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('iisssdi', $reservationId, $guestId, $paymentType, $methodFinal, $reference, $amountF, $currentUserId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    Flash::set('success', 'Payment entry posted.');
                    Response::redirect('billing_payments.php?reservation_id=' . $reservationId);
                }
            }

            $errors['general'] = 'Failed to post payment.';
        }
    }
}

$reservations = [];
$selectedReservation = null;
$charges = [];
$payments = [];

$totCharges = 0.0;
$totPayments = 0.0;
$balance = 0.0;

if ($conn && $hasReservations && $hasGuests) {
    $sql =
        "SELECT r.id, r.reference_no, r.status, r.checkin_date, r.checkout_date,
                r.deposit_amount, r.payment_method,
                g.first_name, g.last_name, g.phone
         FROM reservations r
         INNER JOIN guests g ON g.id = r.guest_id";

    $where = [];
    $types = '';
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(r.reference_no LIKE ? OR g.first_name LIKE ? OR g.last_name LIKE ? OR g.phone LIKE ?)";
        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 150';

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
            $reservations[] = $row;
        }
        $stmt->close();
    }

    if ($reservationId > 0) {
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
                    <h3 class="text-lg font-medium text-gray-900">Reservations</h3>
                    <div class="text-xs text-gray-500">Search</div>
                </div>
                <form method="get" class="mb-3">
                    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search ref / guest / phone" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </form>

                <?php if (empty($reservations)): ?>
                    <div class="text-sm text-gray-500">No reservations found.</div>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 520px; overflow:auto;">
                        <?php foreach ($reservations as $r): ?>
                            <?php
                                $rid = (int)($r['id'] ?? 0);
                                $active = $reservationId > 0 && $rid === $reservationId;
                            ?>
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/billing_payments.php?reservation_id=<?= $rid ?>" class="block rounded-lg border px-3 py-2 <?= $active ? 'border-blue-200 bg-blue-50' : 'border-gray-100 hover:bg-gray-50' ?>">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string)($r['reference_no'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''))) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($r['checkin_date'] ?? '')) ?> → <?= htmlspecialchars((string)($r['checkout_date'] ?? '')) ?></div>
                                    </div>
                                    <div class="text-xs text-gray-700"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <?php if (!$selectedReservation): ?>
                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="text-sm text-gray-500">Select a reservation to view folio and post payments.</div>
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
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Post Charge</h3>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="add_charge" />
                                <input type="hidden" name="reservation_id" value="<?= (int)$reservationId ?>" />
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                    <select name="charge_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="Other">Other</option>
                                        <option value="Room">Room</option>
                                        <option value="POS">POS</option>
                                        <option value="Event">Event</option>
                                        <option value="Adjustment">Adjustment</option>
                                    </select>
                                    <?php if (isset($errors['charge_type'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['charge_type']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <input name="description" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['description'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <input name="amount" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['amount'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['amount']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <button class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Post Charge</button>
                            </form>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-100 p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Post Payment / Refund</h3>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="action" value="add_payment" />
                                <input type="hidden" name="reservation_id" value="<?= (int)$reservationId ?>" />
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                    <select name="payment_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
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
                                    <select name="method" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
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
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                                    <input name="amount" value="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                    <?php if (isset($errors['amount'])): ?>
                                        <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['amount']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <button class="w-full px-4 py-2 rounded-lg bg-green-600 text-white text-sm hover:bg-green-700 transition">Post</button>
                                <p class="text-xs text-gray-500">Refund reduces total payments. Adjustment posts a ledger entry without method rules.</p>
                            </form>
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
<?php include __DIR__ . '/../partials/page_end.php';
