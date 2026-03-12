<?php

require_once __DIR__ . '/../../core/Database.php';

final class MaintenanceRepository
{
    private ?mysqli $conn;
    private ?bool $hasRoomImageColumn = null;
    private ?bool $hasRoomTypeImageColumn = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasRoomImageColumn(): bool
    {
        if ($this->hasRoomImageColumn !== null) {
            return $this->hasRoomImageColumn;
        }
        if (!$this->conn) {
            $this->hasRoomImageColumn = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasRoomImageColumn = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'rooms'
               AND COLUMN_NAME = 'image_path'"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasRoomImageColumn = ($count === 1);
        return $this->hasRoomImageColumn;
    }

    private function hasRoomTypeImageColumn(): bool
    {
        if ($this->hasRoomTypeImageColumn !== null) {
            return $this->hasRoomTypeImageColumn;
        }
        if (!$this->conn) {
            $this->hasRoomTypeImageColumn = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasRoomTypeImageColumn = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'room_types'
               AND COLUMN_NAME = 'image_path'"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasRoomTypeImageColumn = ($count === 1);
        return $this->hasRoomTypeImageColumn;
    }

    public function listRooms(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        $like = '%' . $q . '%';

        if ($q === '') {
            $res = $this->conn->query(
                "SELECT r.id, r.room_no, r.status, rt.name AS room_type_name
                 FROM rooms r
                 JOIN room_types rt ON rt.id = r.room_type_id
                 ORDER BY r.room_no ASC LIMIT 500"
            );
            if (!$res) {
                return [];
            }
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }

        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_no, r.status, rt.name AS room_type_name
             FROM rooms r
             JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.room_no LIKE ? OR r.status LIKE ? OR rt.name LIKE ?
             ORDER BY r.room_no ASC LIMIT 500"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function listAssets(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        $like = '%' . $q . '%';

        if ($q === '') {
            $res = $this->conn->query(
                "SELECT a.id, a.asset_code, a.name, a.asset_type, a.location, a.is_active,
                        r.room_no
                 FROM assets a
                 LEFT JOIN rooms r ON r.id = a.room_id
                 ORDER BY a.asset_code ASC LIMIT 500"
            );
            if (!$res) {
                return [];
            }
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }

        $stmt = $this->conn->prepare(
            "SELECT a.id, a.asset_code, a.name, a.asset_type, a.location, a.is_active,
                    r.room_no
             FROM assets a
             LEFT JOIN rooms r ON r.id = a.room_id
             WHERE a.asset_code LIKE ? OR a.name LIKE ? OR a.asset_type LIKE ? OR a.location LIKE ?
             ORDER BY a.asset_code ASC LIMIT 500"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ssss', $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function listCategories(): array
    {
        if (!$this->conn) {
            return [];
        }
        $res = $this->conn->query("SELECT id, name FROM maintenance_categories WHERE is_active = 1 ORDER BY name ASC");
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function listVendors(): array
    {
        if (!$this->conn) {
            return [];
        }
        $res = $this->conn->query("SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name ASC");
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function listUsers(): array
    {
        if (!$this->conn) {
            return [];
        }
        $res = $this->conn->query("SELECT id, username, role FROM users ORDER BY username ASC LIMIT 500");
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function listTickets(array $filters = []): array
    {
        if (!$this->conn) {
            return [];
        }

        $status = trim((string)($filters['status'] ?? ''));
        $priority = trim((string)($filters['priority'] ?? ''));
        $q = trim((string)($filters['q'] ?? ''));

        $imgSelect = $this->hasRoomImageColumn() ? 'r.image_path AS room_image_path,' : 'NULL AS room_image_path,';
        $rtImgSelect = $this->hasRoomTypeImageColumn() ? 'rt.image_path AS room_type_image_path,' : 'NULL AS room_type_image_path,';

        $sql = "SELECT t.id, t.ticket_no, t.status, t.priority, t.title, t.requires_downtime,
                       t.room_id, r.room_no,
                       {$imgSelect}
                       {$rtImgSelect}
                       t.asset_id, a.asset_code,
                       c.name AS category_name,
                       t.assigned_to, u.username AS assigned_username,
                       t.vendor_id, v.name AS vendor_name,
                       t.opened_at, t.updated_at
                FROM maintenance_tickets t
                LEFT JOIN rooms r ON r.id = t.room_id
                LEFT JOIN room_types rt ON rt.id = r.room_type_id
                LEFT JOIN assets a ON a.id = t.asset_id
                LEFT JOIN maintenance_categories c ON c.id = t.category_id
                LEFT JOIN users u ON u.id = t.assigned_to
                LEFT JOIN vendors v ON v.id = t.vendor_id
                WHERE 1=1";

        $types = '';
        $params = [];

        if ($status !== '') {
            $sql .= " AND t.status = ?";
            $types .= 's';
            $params[] = $status;
        }

        if ($priority !== '') {
            $sql .= " AND t.priority = ?";
            $types .= 's';
            $params[] = $priority;
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= " AND (t.ticket_no LIKE ? OR t.title LIKE ? OR r.room_no LIKE ? OR a.asset_code LIKE ?)";
            $types .= 'ssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY t.opened_at DESC LIMIT 200";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function findTicketById(int $id): ?array
    {
        if (!$this->conn) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT t.*,
                    r.room_no,
                    a.asset_code,
                    c.name AS category_name,
                    u.username AS assigned_username,
                    v.name AS vendor_name
             FROM maintenance_tickets t
             LEFT JOIN rooms r ON r.id = t.room_id
             LEFT JOIN assets a ON a.id = t.asset_id
             LEFT JOIN maintenance_categories c ON c.id = t.category_id
             LEFT JOIN users u ON u.id = t.assigned_to
             LEFT JOIN vendors v ON v.id = t.vendor_id
             WHERE t.id = ?"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function createTicket(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO maintenance_tickets
                (ticket_no, room_id, asset_id, category_id, priority, status, title, description, reported_by, assigned_to, vendor_id, requires_downtime, room_out_of_order_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $ticketNo = (string)($data['ticket_no'] ?? '');
        $roomId = ($data['room_id'] ?? null);
        $assetId = ($data['asset_id'] ?? null);
        $categoryId = ($data['category_id'] ?? null);
        $priority = (string)($data['priority'] ?? 'Normal');
        $status = (string)($data['status'] ?? 'Open');
        $title = (string)($data['title'] ?? '');
        $description = (string)($data['description'] ?? '');
        $reportedBy = ($data['reported_by'] ?? null);
        $assignedTo = ($data['assigned_to'] ?? null);
        $vendorId = ($data['vendor_id'] ?? null);
        $requiresDowntime = (int)($data['requires_downtime'] ?? 0);
        $downtimeFrom = $data['room_out_of_order_from'] ?? null;

        $stmt->bind_param(
            'siiissssiiiis',
            $ticketNo,
            $roomId,
            $assetId,
            $categoryId,
            $priority,
            $status,
            $title,
            $description,
            $reportedBy,
            $assignedTo,
            $vendorId,
            $requiresDowntime,
            $downtimeFrom
        );

        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function updateTicket(int $id, array $data): bool
    {
        if (!$this->conn) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "UPDATE maintenance_tickets
             SET status = ?, assigned_to = ?, vendor_id = ?, requires_downtime = ?,
                 room_out_of_order_from = ?, room_out_of_order_to = ?,
                 resolved_at = ?, closed_at = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $status = (string)($data['status'] ?? 'Open');
        $assignedTo = ($data['assigned_to'] ?? null);
        $vendorId = ($data['vendor_id'] ?? null);
        $requiresDowntime = (int)($data['requires_downtime'] ?? 0);
        $downtimeFrom = $data['room_out_of_order_from'] ?? null;
        $downtimeTo = $data['room_out_of_order_to'] ?? null;
        $resolvedAt = $data['resolved_at'] ?? null;
        $closedAt = $data['closed_at'] ?? null;

        $stmt->bind_param(
            'siiissssi',
            $status,
            $assignedTo,
            $vendorId,
            $requiresDowntime,
            $downtimeFrom,
            $downtimeTo,
            $resolvedAt,
            $closedAt,
            $id
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function createLog(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO maintenance_logs (ticket_id, work_order_id, log_type, message, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $ticketId = (int)($data['ticket_id'] ?? 0);
        $workOrderId = ($data['work_order_id'] ?? null);
        $logType = (string)($data['log_type'] ?? 'Note');
        $message = (string)($data['message'] ?? '');
        $createdBy = ($data['created_by'] ?? null);

        $stmt->bind_param('iissi', $ticketId, $workOrderId, $logType, $message, $createdBy);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function createCost(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO maintenance_costs
                (ticket_id, work_order_id, cost_type, description, qty, unit_cost, total_cost, reference_no, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $ticketId = (int)($data['ticket_id'] ?? 0);
        $workOrderId = ($data['work_order_id'] ?? null);
        $costType = (string)($data['cost_type'] ?? 'Other');
        $description = (string)($data['description'] ?? '');
        $qty = (float)($data['qty'] ?? 1);
        $unitCost = (float)($data['unit_cost'] ?? 0);
        $totalCost = (float)($data['total_cost'] ?? ($qty * $unitCost));
        $ref = (string)($data['reference_no'] ?? '');
        $createdBy = ($data['created_by'] ?? null);

        $stmt->bind_param('iissdddsi', $ticketId, $workOrderId, $costType, $description, $qty, $unitCost, $totalCost, $ref, $createdBy);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function createWorkOrder(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO maintenance_work_orders
                (work_order_no, ticket_id, assigned_to, vendor_id, scheduled_at, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $workOrderNo = (string)($data['work_order_no'] ?? '');
        $ticketId = (int)($data['ticket_id'] ?? 0);
        $assignedTo = ($data['assigned_to'] ?? null);
        $vendorId = ($data['vendor_id'] ?? null);
        $scheduledAt = $data['scheduled_at'] ?? null;
        $status = (string)($data['status'] ?? 'Planned');
        $notes = (string)($data['notes'] ?? '');

        $stmt->bind_param('siiisss', $workOrderNo, $ticketId, $assignedTo, $vendorId, $scheduledAt, $status, $notes);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function addRoomStatusHistory(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO room_status_history
                (room_id, old_status, new_status, source, source_id, changed_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $roomId = (int)($data['room_id'] ?? 0);
        $oldStatus = (string)($data['old_status'] ?? '');
        $newStatus = (string)($data['new_status'] ?? '');
        $source = (string)($data['source'] ?? 'Maintenance');
        $sourceId = ($data['source_id'] ?? null);
        $changedBy = ($data['changed_by'] ?? null);

        $stmt->bind_param('isssii', $roomId, $oldStatus, $newStatus, $source, $sourceId, $changedBy);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }
}
