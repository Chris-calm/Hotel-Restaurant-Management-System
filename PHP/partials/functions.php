<?php

function getTotalCount($conn, string $table): int
{
    if (!$conn) {
        return 0;
    }

    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($tableSafe === '') {
        return 0;
    }

    $sql = "SELECT COUNT(*) as total FROM `{$tableSafe}`";
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return isset($row['total']) ? (int)$row['total'] : 0;
}

function getRecentCaseRecords($conn): array
{
    return [];
}

function getPendingItems($conn): array
{
    if (!$conn) {
        return [];
    }

    $items = [];
    $APP_BASE_URL = class_exists('App') ? App::baseUrl() : '';

    try {
        $sql =
            "SELECT r.id, r.reference_no, r.created_at,
                    g.first_name, g.last_name
             FROM reservations r
             INNER JOIN guests g ON g.id = r.guest_id
             WHERE r.status = 'Pending' AND r.source = 'Website'
             ORDER BY r.id DESC
             LIMIT 12";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rid = (int)($row['id'] ?? 0);
                $ref = (string)($row['reference_no'] ?? 'Reservation');
                $guest = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                $items[] = [
                    'type' => 'Online Reservation',
                    'name' => ($guest !== '' ? ($ref . ' - ' . $guest) : $ref),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'url' => ($APP_BASE_URL !== '' ? ($APP_BASE_URL . '/PHP/modules/front_desk.php?reservation_id=' . $rid) : ('/PHP/modules/front_desk.php?reservation_id=' . $rid)),
                ];
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'events'");
            $hasEvents = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            if ($hasEvents) {
                $res2 = $conn->query(
                    "SELECT id, event_no, title, created_at
                     FROM events
                     WHERE status = 'Inquiry'
                     ORDER BY id DESC
                     LIMIT 12"
                );
                if ($res2) {
                    while ($row = $res2->fetch_assoc()) {
                        $eid = (int)($row['id'] ?? 0);
                        $eno = (string)($row['event_no'] ?? 'Event');
                        $title = trim((string)($row['title'] ?? ''));
                        $items[] = [
                            'type' => 'Event Inquiry',
                            'name' => ($title !== '' ? ($eno . ' - ' . $title) : $eno),
                            'created_at' => (string)($row['created_at'] ?? ''),
                            'url' => ($APP_BASE_URL !== '' ? ($APP_BASE_URL . '/PHP/modules/events_conferences.php') : '/PHP/modules/events_conferences.php'),
                        ];
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $dbRow = $conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $conn->real_escape_string($db);
        if ($db !== '') {
            $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'maintenance_tickets'");
            $hasMaintenance = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
            if ($hasMaintenance) {
                $res2 = $conn->query(
                    "SELECT t.id, t.ticket_no, t.title, t.status, t.created_at
                     FROM maintenance_tickets t
                     WHERE t.status IN ('Open','Assigned','In Progress','On Hold')
                       AND (t.assigned_to IS NULL OR t.assigned_to = 0)
                     ORDER BY t.id DESC
                     LIMIT 12"
                );
                if ($res2) {
                    while ($row = $res2->fetch_assoc()) {
                        $tid = (int)($row['id'] ?? 0);
                        $tno = (string)($row['ticket_no'] ?? 'Ticket');
                        $title = trim((string)($row['title'] ?? ''));
                        $items[] = [
                            'type' => 'Maintenance Ticket',
                            'name' => ($title !== '' ? ($tno . ' - ' . $title) : $tno),
                            'created_at' => (string)($row['created_at'] ?? ''),
                            'url' => ($APP_BASE_URL !== '' ? ($APP_BASE_URL . '/PHP/modules/housekeeping_maintenance.php') : '/PHP/modules/housekeeping_maintenance.php'),
                        ];
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }

    return array_slice($items, 0, 12);
}
