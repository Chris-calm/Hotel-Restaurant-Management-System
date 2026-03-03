<?php

require_once __DIR__ . '/../../core/Database.php';

final class RoomRepository
{
    private ?mysqli $conn;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function search(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            $sql = "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
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
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
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
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
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

    public function update(int $id, array $data): bool
    {
        if (!$this->conn) {
            return false;
        }

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
