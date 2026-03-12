<?php
require_once __DIR__ . '/../rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../domain/Rooms/RoomTypeService.php';

$conn = Database::getConnection();

$typeService = new RoomTypeService(new RoomTypeRepository($conn));
$roomTypes = $typeService->list();

$sanitizeDate = static function (string $v): string {
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return '';
    }
    return $v;
};

$startDate = $sanitizeDate((string)Request::get('start_date', date('Y-m-d')));
$endDate = $sanitizeDate((string)Request::get('end_date', date('Y-m-d', strtotime('+7 days'))));
$roomTypeId = (int)Request::get('room_type_id', 0);
$markupPctRaw = (string)Request::get('markup_pct', '0');
$markupPct = is_numeric($markupPctRaw) ? (float)$markupPctRaw : 0.0;
$export = (string)Request::get('export', '');

$errors = [];
$report = [];

if ($startDate !== '' && $endDate !== '') {
    $t1 = strtotime($startDate);
    $t2 = strtotime($endDate);
    if ($t1 === false || $t2 === false || $t2 < $t1) {
        $errors[] = 'Invalid date range.';
    }
}

if (empty($errors) && $startDate !== '' && $endDate !== '' && $conn) {
    $statusSql = "('Confirmed','Upcoming','Checked In')";

    $totals = [];
    $res = $conn->query(
        "SELECT rt.id AS room_type_id, rt.name, rt.code, rt.base_rate,
                COUNT(r.id) AS total_rooms
         FROM room_types rt
         LEFT JOIN rooms r ON r.room_type_id = rt.id
         GROUP BY rt.id
         ORDER BY rt.name ASC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rid = (int)($row['room_type_id'] ?? 0);
            if ($rid > 0) {
                $totals[$rid] = [
                    'room_type_id' => $rid,
                    'name' => (string)($row['name'] ?? ''),
                    'code' => (string)($row['code'] ?? ''),
                    'base_rate' => (float)($row['base_rate'] ?? 0),
                    'total_rooms' => (int)($row['total_rooms'] ?? 0),
                ];
            }
        }
    }

    $cur = strtotime($startDate);
    $end = strtotime($endDate);
    $maxDays = 62;
    $days = 0;
    while ($cur !== false && $end !== false && $cur <= $end && $days < $maxDays) {
        $day = date('Y-m-d', $cur);

        $reservedByType = [];
        $stmt = $conn->prepare(
            "SELECT ro.room_type_id, COUNT(DISTINCT rr.room_id) AS reserved_rooms
             FROM reservation_rooms rr
             INNER JOIN reservations r ON r.id = rr.reservation_id
             INNER JOIN rooms ro ON ro.id = rr.room_id
             WHERE r.status IN {$statusSql}
               AND r.checkin_date <= ?
               AND r.checkout_date > ?
             GROUP BY ro.room_type_id"
        );
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ss', $day, $day);
            $stmt->execute();
            $rRes = $stmt->get_result();
            while ($row = $rRes->fetch_assoc()) {
                $rtid = (int)($row['room_type_id'] ?? 0);
                $reservedByType[$rtid] = (int)($row['reserved_rooms'] ?? 0);
            }
            $stmt->close();
        }

        foreach ($totals as $rtid => $info) {
            if ($roomTypeId > 0 && $rtid !== $roomTypeId) {
                continue;
            }
            $totalRooms = (int)($info['total_rooms'] ?? 0);
            $reservedRooms = (int)($reservedByType[$rtid] ?? 0);
            $availableRooms = max(0, $totalRooms - $reservedRooms);

            $baseRate = (float)($info['base_rate'] ?? 0);
            $suggestedRate = $baseRate;
            if ($markupPct !== 0.0) {
                $suggestedRate = $baseRate * (1 + ($markupPct / 100));
            }

            $report[] = [
                'date' => $day,
                'room_type_id' => $rtid,
                'room_type' => (string)($info['name'] ?? ''),
                'code' => (string)($info['code'] ?? ''),
                'total_rooms' => $totalRooms,
                'reserved_rooms' => $reservedRooms,
                'available_rooms' => $availableRooms,
                'base_rate' => $baseRate,
                'suggested_rate' => $suggestedRate,
            ];
        }

        $cur = strtotime('+1 day', $cur);
        $days++;
    }
}

if ($export === 'csv' && empty($errors)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="availability_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['date', 'room_type', 'code', 'total_rooms', 'reserved_rooms', 'available_rooms', 'base_rate', 'suggested_rate']);
    foreach ($report as $row) {
        fputcsv($out, [
            $row['date'],
            $row['room_type'],
            $row['code'],
            (int)$row['total_rooms'],
            (int)$row['reserved_rooms'],
            (int)$row['available_rooms'],
            number_format((float)$row['base_rate'], 2, '.', ''),
            number_format((float)$row['suggested_rate'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

$pendingApprovals = [];

include __DIR__ . '/../partials/page_start.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section id="content">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <main class="w-full px-6 py-6">
        <div class="mb-6">
            <h1 class="text-2xl font-light text-gray-900">Channel Management</h1>
            <p class="text-sm text-gray-500 mt-1">OTA integrations and multi-platform availability</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <div class="font-medium mb-1">Action failed</div>
                <?php foreach ($errors as $msg): ?>
                    <div><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Channels</h3>
                <div class="text-sm text-gray-600">Localhost mode: manual channel exports (no real OTA API integration).</div>

                <div class="mt-4 space-y-3">
                    <div class="rounded-xl border border-gray-100 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-gray-900">Manual Export Channel</div>
                                <div class="text-xs text-gray-500 mt-1">Use this to generate CSV availability/rate sheets for any platform.</div>
                            </div>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border border-green-200 bg-green-50 text-green-700">Enabled</span>
                        </div>
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs text-gray-600">
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <div class="text-gray-500">Sync Type</div>
                                <div class="font-medium text-gray-900 mt-1">Manual</div>
                            </div>
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <div class="text-gray-500">Output</div>
                                <div class="font-medium text-gray-900 mt-1">CSV (Availability + Rates)</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Availability Sync</h3>
                <div class="text-sm text-gray-600">Generate a CSV availability report for a date range. Optional markup can simulate OTA pricing.</div>

                <form method="get" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room Type (optional)</label>
                        <select name="room_type_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="0">All Room Types</option>
                            <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?= (int)$rt['id'] ?>" <?= (int)$rt['id'] === (int)$roomTypeId ? 'selected' : '' ?>><?= htmlspecialchars(($rt['code'] ?? '') . ' - ' . ($rt['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate Markup % (optional)</label>
                        <input name="markup_pct" value="<?= htmlspecialchars((string)$markupPct) ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2 flex items-center gap-2 pt-1">
                        <button class="px-4 py-2 rounded-lg bg-gray-900 text-white text-sm hover:bg-black transition">Generate Report</button>
                        <a href="channel_management.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Reset</a>
                        <a href="channel_management.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&room_type_id=<?= (int)$roomTypeId ?>&markup_pct=<?= urlencode((string)$markupPct) ?>&export=csv" class="ml-auto px-4 py-2 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 transition">Export CSV</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-6 bg-white rounded-lg border border-gray-100 p-6">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 class="text-lg font-medium text-gray-900">Availability Report</h3>
                    <div class="text-sm text-gray-500 mt-1">Based on total rooms minus active reservations (Confirmed/Upcoming/Checked In) per day.</div>
                </div>
                <div class="text-xs text-gray-500">Max 62 days</div>
            </div>

            <?php if (empty($report)): ?>
                <div class="text-sm text-gray-500">Generate a report to see results here.</div>
            <?php else: ?>
                <div class="overflow-auto rounded-lg border border-gray-100">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="text-left font-medium px-4 py-3">Date</th>
                                <th class="text-left font-medium px-4 py-3">Room Type</th>
                                <th class="text-right font-medium px-4 py-3">Total</th>
                                <th class="text-right font-medium px-4 py-3">Reserved</th>
                                <th class="text-right font-medium px-4 py-3">Available</th>
                                <th class="text-right font-medium px-4 py-3">Base Rate</th>
                                <th class="text-right font-medium px-4 py-3">Suggested Rate</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($report as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($row['date']) ?></td>
                                    <td class="px-4 py-3 text-gray-900">
                                        <div class="font-medium"><?= htmlspecialchars((string)$row['room_type']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars((string)$row['code']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700"><?= (int)$row['total_rooms'] ?></td>
                                    <td class="px-4 py-3 text-right text-gray-700"><?= (int)$row['reserved_rooms'] ?></td>
                                    <td class="px-4 py-3 text-right">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border <?= ((int)$row['available_rooms']) > 0 ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700' ?>">
                                            <?= (int)$row['available_rooms'] ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">₱<?= number_format((float)$row['base_rate'], 2) ?></td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900">₱<?= number_format((float)$row['suggested_rate'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</section>
<?php include __DIR__ . '/../partials/page_end.php';
