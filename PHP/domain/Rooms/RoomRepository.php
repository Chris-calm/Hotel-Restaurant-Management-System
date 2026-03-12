<?php

require_once __DIR__ . '/../../core/Database.php';

final class RoomRepository
{
    private ?mysqli $conn;
    private ?bool $hasImageColumn = null;
    private ?bool $hasLockColumns = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasLockColumns(): bool
    {
        if ($this->hasLockColumns !== null) {
            return $this->hasLockColumns;
        }
        if (!$this->conn) {
            $this->hasLockColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasLockColumns = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'rooms'
               AND COLUMN_NAME IN ('lock_provider','lock_device_id','lock_status','lock_battery','lock_last_sync_at')"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasLockColumns = ($count === 5);
        return $this->hasLockColumns;
    }

    private function hasImageColumn(): bool
    {
        if ($this->hasImageColumn !== null) {
            return $this->hasImageColumn;
        }
        if (!$this->conn) {
            $this->hasImageColumn = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasImageColumn = false;
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
        $this->hasImageColumn = ($count === 1);
        return $this->hasImageColumn;
    }

    public function search(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            $imgSelect = $this->hasImageColumn() ? 'r.image_path' : 'NULL AS image_path';
            $lockSelect = $this->hasLockColumns()
                ? "r.lock_provider, r.lock_device_id, r.lock_status, r.lock_battery, r.lock_last_sync_at"
                : "NULL AS lock_provider, NULL AS lock_device_id, NULL AS lock_status, NULL AS lock_battery, NULL AS lock_last_sync_at";

            $sql = "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id, {$imgSelect},
                           {$lockSelect},
                           rt.code as room_type_code, rt.name as room_type_name, rt.base_rate
                    FROM rooms r
                    JOIN room_types rt ON rt.id = r.room_type_id
                    ORDER BY r.room_no ASC LIMIT 500";
            $res = $this->conn->query($sql);
            if (!$res) {
                return [];
            }
            $rows = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }

        $like = '%' . $q . '%';
        $imgSelect = $this->hasImageColumn() ? 'r.image_path' : 'NULL AS image_path';
        $lockSelect = $this->hasLockColumns()
            ? "r.lock_provider, r.lock_device_id, r.lock_status, r.lock_battery, r.lock_last_sync_at"
            : "NULL AS lock_provider, NULL AS lock_device_id, NULL AS lock_status, NULL AS lock_battery, NULL AS lock_last_sync_at";

        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id, {$imgSelect},
                    {$lockSelect},
                    rt.code as room_type_code, rt.name as room_type_name, rt.base_rate
             FROM rooms r
             JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.room_no LIKE ? OR r.floor LIKE ? OR rt.code LIKE ? OR rt.name LIKE ? OR r.status LIKE ?
             ORDER BY r.room_no ASC LIMIT 500"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function findById(int $id): ?array
    {
        if (!$this->conn) {
            return null;
        }
        $imgSelect = $this->hasImageColumn() ? 'r.image_path' : 'NULL AS image_path';
        $lockSelect = $this->hasLockColumns()
            ? "r.lock_provider, r.lock_device_id, r.lock_status, r.lock_battery, r.lock_last_sync_at"
            : "NULL AS lock_provider, NULL AS lock_device_id, NULL AS lock_status, NULL AS lock_battery, NULL AS lock_last_sync_at";

        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id, {$imgSelect},
                    {$lockSelect},
                    rt.code as room_type_code, rt.name as room_type_name, rt.base_rate
             FROM rooms r
             JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.id = ?"
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

    public function create(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        if (!$this->hasImageColumn()) {
            $stmt = $this->conn->prepare(
                "INSERT INTO rooms (room_no, room_type_id, floor, status)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$stmt) {
                return 0;
            }

            $roomNo = (string)($data['room_no'] ?? '');
            $roomTypeId = (int)($data['room_type_id'] ?? 0);
            $floor = (string)($data['floor'] ?? '');
            $status = (string)($data['status'] ?? 'Vacant');

            $stmt->bind_param('siss', $roomNo, $roomTypeId, $floor, $status);
            $ok = $stmt->execute();
            $id = $ok ? (int)$stmt->insert_id : 0;
            $stmt->close();
            return $id;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO rooms (room_no, room_type_id, floor, image_path, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $roomNo = (string)($data['room_no'] ?? '');
        $roomTypeId = (int)($data['room_type_id'] ?? 0);
        $floor = (string)($data['floor'] ?? '');
        $imagePath = (string)($data['image_path'] ?? '');
        $status = (string)($data['status'] ?? 'Vacant');

        if (trim($imagePath) === '') {
            $imagePath = null;
        }

        $stmt->bind_param('sisss', $roomNo, $roomTypeId, $floor, $imagePath, $status);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function update(int $id, array $data): bool
    {
        if (!$this->conn) {
            return false;
        }

        if (!$this->hasImageColumn()) {
            $stmt = $this->conn->prepare(
                "UPDATE rooms
                 SET room_no = ?, room_type_id = ?, floor = ?, status = ?
                 WHERE id = ?"
            );
            if (!$stmt) {
                return false;
            }

            $roomNo = (string)($data['room_no'] ?? '');
            $roomTypeId = (int)($data['room_type_id'] ?? 0);
            $floor = (string)($data['floor'] ?? '');
            $status = (string)($data['status'] ?? 'Vacant');

            $stmt->bind_param('sissi', $roomNo, $roomTypeId, $floor, $status, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        $stmt = $this->conn->prepare(
            "UPDATE rooms
             SET room_no = ?, room_type_id = ?, floor = ?, image_path = ?, status = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $roomNo = (string)($data['room_no'] ?? '');
        $roomTypeId = (int)($data['room_type_id'] ?? 0);
        $floor = (string)($data['floor'] ?? '');
        $imagePath = (string)($data['image_path'] ?? '');
        $status = (string)($data['status'] ?? 'Vacant');

        if (trim($imagePath) === '') {
            $imagePath = null;
        }

        $stmt->bind_param('sisssi', $roomNo, $roomTypeId, $floor, $imagePath, $status, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function delete(int $id): bool
    {
        if (!$this->conn) {
            return false;
        }
        $stmt = $this->conn->prepare("DELETE FROM rooms WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!$this->conn) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE rooms SET status = ? WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $status, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
