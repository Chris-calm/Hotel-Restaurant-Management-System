<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';

$conn = Database::getConnection();

$APP_BASE_URL = App::baseUrl();

$errors = [];

$hasGuests = false;
$hasLoyaltyTxns = false;
$hasGuestLoyaltyTier = false;
$hasGuestLoyaltyPoints = false;

if ($conn) {
    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests'");
            $hasGuests = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'loyalty_transactions'");
            $hasLoyaltyTxns = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

            if ($hasGuests) {
                $res = $conn->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME = 'loyalty_tier'"
                );
                $hasGuestLoyaltyTier = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;

                $res = $conn->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'guests' AND COLUMN_NAME = 'loyalty_points'"
                );
                $hasGuestLoyaltyPoints = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
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
$q = trim((string)Request::get('q', ''));

if (Request::isPost() && $conn && $hasGuests && $hasLoyaltyTxns) {
    $action = (string)Request::post('action', '');
    $guestIdPost = Request::int('post', 'guest_id', 0);
    if ($guestIdPost > 0) {
        $guestId = $guestIdPost;
    }

    if ($action === 'post_points') {
        if (!$hasGuestLoyaltyPoints) {
            $errors['general'] = 'Loyalty points column is missing in guests table.';
        }
        $txnType = (string)Request::post('txn_type', 'Earn');
        $points = Request::int('post', 'points', 0);
        $reference = trim((string)Request::post('reference', ''));

        if ($guestId <= 0) {
            $errors['guest_id'] = 'Guest is required.';
        }
        if (!in_array($txnType, ['Earn', 'Redeem', 'Adjust'], true)) {
            $errors['txn_type'] = 'Transaction type is invalid.';
        }
        if ($points <= 0) {
            $errors['points'] = 'Points must be at least 1.';
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
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    throw new RuntimeException('Guest not found.');
                }
                $current = (int)($row['loyalty_points'] ?? 0);

                $delta = 0;
                if ($txnType === 'Earn') {
                    $delta = $points;
                } elseif ($txnType === 'Redeem') {
                    $delta = -$points;
                } else {
                    $delta = $points;
                    if ($reference === '') {
                        $reference = 'ADJUST';
                    }
                }
                $newPoints = $current + $delta;
                if ($newPoints < 0) {
                    throw new RuntimeException('Insufficient points.');
                }

                $newTier = $hasGuestLoyaltyTier ? $loyaltyTierForPoints($newPoints) : null;

                $stmt = $conn->prepare(
                    "INSERT INTO loyalty_transactions (guest_id, txn_type, points, reference, created_by)
                     VALUES (?, ?, ?, NULLIF(?,''), NULLIF(?,0))"
                );
                if (!($stmt instanceof mysqli_stmt)) {
                    throw new RuntimeException('Failed to save transaction.');
                }
                $stmt->bind_param('isisi', $guestId, $txnType, $points, $reference, $currentUserId);
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to save transaction.');
                }

                if ($hasGuestLoyaltyTier) {
                    $stmt = $conn->prepare("UPDATE guests SET loyalty_points = ?, loyalty_tier = ? WHERE id = ?");
                    if (!($stmt instanceof mysqli_stmt)) {
                        throw new RuntimeException('Failed to update points.');
                    }
                    $stmt->bind_param('isi', $newPoints, $newTier, $guestId);
                } else {
                    $stmt = $conn->prepare("UPDATE guests SET loyalty_points = ? WHERE id = ?");
                    if (!($stmt instanceof mysqli_stmt)) {
                        throw new RuntimeException('Failed to update points.');
                    }
                    $stmt->bind_param('ii', $newPoints, $guestId);
                }
                $ok = $stmt->execute();
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to update points.');
                }

                $conn->commit();
                Flash::set('success', 'Points updated.');
                Response::redirect('loyalty_rewards.php?guest_id=' . $guestId . ($q !== '' ? ('&q=' . urlencode($q)) : ''));
            } catch (Throwable $e) {
                $conn->rollback();
                $errors['general'] = $e->getMessage();
            }
        }
    }
}

$guests = [];
$selectedGuest = null;
$txns = [];

if ($conn && $hasGuests) {
    $tierSelect = $hasGuestLoyaltyTier ? 'loyalty_tier' : "NULL AS loyalty_tier";
    $pointsSelect = $hasGuestLoyaltyPoints ? 'loyalty_points' : '0 AS loyalty_points';
    $sql = "SELECT id, first_name, last_name, phone, email, {$tierSelect}, {$pointsSelect}, status
            FROM guests";
    $where = [];
    $types = '';
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ?)";
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

    if ($guestId > 0) {
        $stmt = $conn->prepare(
            "SELECT id, first_name, last_name, phone, email, {$tierSelect}, {$pointsSelect}, status
             FROM guests
             WHERE id = ?
             LIMIT 1"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('i', $guestId);
            $stmt->execute();
            $res = $stmt->get_result();
            $selectedGuest = $res->fetch_assoc() ?: null;
            $stmt->close();
        }
    }
}

if ($conn && $hasLoyaltyTxns && $selectedGuest) {
    $stmt = $conn->prepare(
        "SELECT id, txn_type, points, reference, created_at
         FROM loyalty_transactions
         WHERE guest_id = ?
         ORDER BY id DESC
         LIMIT 100"
    );
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $txns[] = $row;
        }
        $stmt->close();
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
            <h1 class="text-2xl font-light text-gray-900">Loyalty & Rewards</h1>
            <p class="text-sm text-gray-500 mt-1">Points ledger, tiers, earn/redeem</p>
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

        <?php if (!$hasGuests || !$hasLoyaltyTxns): ?>
            <div class="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-900">
                This module needs DB tables. Run the updated schema SQL to create <span class="font-medium">loyalty_transactions</span> and ensure <span class="font-medium">guests.loyalty_points</span> exists.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6 lg:col-span-1">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Members</h3>
                    <div class="text-xs text-gray-500">Search</div>
                </div>
                <form method="get" class="mb-3">
                    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name / phone / email" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                </form>
                <?php if (empty($guests)): ?>
                    <div class="text-sm text-gray-500">No guests found.</div>
                <?php else: ?>
                    <div class="space-y-2" style="max-height: 560px; overflow:auto;">
                        <?php foreach ($guests as $g): ?>
                            <?php $gid = (int)($g['id'] ?? 0); $active = $guestId > 0 && $gid === $guestId; ?>
                            <a href="<?= htmlspecialchars($APP_BASE_URL) ?>/PHP/modules/loyalty_rewards.php?guest_id=<?= $gid ?><?= $q !== '' ? ('&q=' . urlencode($q)) : '' ?>" class="block rounded-lg border px-3 py-2 <?= $active ? 'border-blue-200 bg-blue-50' : 'border-gray-100 hover:bg-gray-50' ?>">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars(trim((string)($g['first_name'] ?? '') . ' ' . (string)($g['last_name'] ?? ''))) ?></div>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($g['phone'] ?? '')) ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs text-gray-700"><?= (int)($g['loyalty_points'] ?? 0) ?> pts</div>
                                        <div class="text-[10px] text-gray-500 mt-1"><?= htmlspecialchars((string)($g['loyalty_tier'] ?? '')) ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <?php if (!$selectedGuest): ?>
                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="text-sm text-gray-500">Select a member to manage points.</div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">Member Profile</h3>
                                <div class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(trim((string)($selectedGuest['first_name'] ?? '') . ' ' . (string)($selectedGuest['last_name'] ?? ''))) ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($selectedGuest['phone'] ?? '')) ?><?= ((string)($selectedGuest['email'] ?? '') !== '') ? ' • ' : '' ?><?= htmlspecialchars((string)($selectedGuest['email'] ?? '')) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">Current Points</div>
                                <div class="text-2xl font-light text-gray-900"><?= (int)($selectedGuest['loyalty_points'] ?? 0) ?></div>
                                <div class="text-xs text-gray-500 mt-1">Tier: <?= htmlspecialchars((string)($selectedGuest['loyalty_tier'] ?? '')) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Post Points</h3>
                        <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                            <input type="hidden" name="action" value="post_points" />
                            <input type="hidden" name="guest_id" value="<?= (int)$guestId ?>" />
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="txn_type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                    <option value="Earn">Earn</option>
                                    <option value="Redeem">Redeem</option>
                                    <option value="Adjust">Adjust</option>
                                </select>
                                <?php if (isset($errors['txn_type'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['txn_type']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Points</label>
                                <input type="number" min="1" name="points" value="1" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                                <?php if (isset($errors['points'])): ?>
                                    <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($errors['points']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Reference (optional)</label>
                                <input name="reference" placeholder="OR#, POS#, Note" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                            </div>
                            <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 transition">Post</button>
                        </form>
                        <p class="text-xs text-gray-500 mt-2">Redeem subtracts points. Adjust adds points as an admin adjustment.</p>
                    </div>

                    <div class="bg-white rounded-lg border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Transactions</h3>
                            <div class="text-xs text-gray-500">Last 100</div>
                        </div>
                        <?php if (empty($txns)): ?>
                            <div class="text-sm text-gray-500">No transactions yet.</div>
                        <?php else: ?>
                            <div class="overflow-auto rounded-lg border border-gray-100">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-gray-600">
                                        <tr>
                                            <th class="text-left font-medium px-4 py-3">When</th>
                                            <th class="text-left font-medium px-4 py-3">Type</th>
                                            <th class="text-right font-medium px-4 py-3">Points</th>
                                            <th class="text-left font-medium px-4 py-3">Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($txns as $t): ?>
                                            <?php
                                                $ty = (string)($t['txn_type'] ?? '');
                                                $badge = 'border-gray-200 bg-gray-50 text-gray-700';
                                                if ($ty === 'Earn') $badge = 'border-green-200 bg-green-50 text-green-700';
                                                if ($ty === 'Redeem') $badge = 'border-red-200 bg-red-50 text-red-700';
                                                if ($ty === 'Adjust') $badge = 'border-blue-200 bg-blue-50 text-blue-700';
                                            ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($t['created_at'] ?? '')) ?></td>
                                                <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= htmlspecialchars($badge) ?>"><?= htmlspecialchars($ty) ?></span></td>
                                                <td class="px-4 py-3 text-right text-gray-700"><?= (int)($t['points'] ?? 0) ?></td>
                                                <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($t['reference'] ?? '')) ?></td>
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
?>
