<?php

require_once __DIR__ . '/../../core/Database.php';

final class RoomTypeRepository
{
    private ?mysqli $conn;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function all(): array
    {
        if (!$this->conn) {
            return [];
        }
        $res = $this->conn->query("SELECT id, code, name, base_rate, max_adults, max_children, created_at FROM room_types ORDER BY id DESC LIMIT 500");
        if (!$res) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function findById(int $id): ?array
    {
        if (!$this->conn) {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT id, code, name, base_rate, max_adults, max_children, created_at FROM room_types WHERE id = ?");
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

    public function findByCode(string $code): ?array
    {
        if (!$this->conn) {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT id, code, name, base_rate, max_adults, max_children, created_at FROM room_types WHERE code = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $code);
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
            "INSERT INTO room_types (code, name, base_rate, max_adults, max_children)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $code = (string)($data['code'] ?? '');
        $name = (string)($data['name'] ?? '');
        $baseRate = (float)($data['base_rate'] ?? 0);
        $maxAdults = (int)($data['max_adults'] ?? 2);
        $maxChildren = (int)($data['max_children'] ?? 0);

        $stmt->bind_param('ssdii', $code, $name, $baseRate, $maxAdults, $maxChildren);
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
            "UPDATE room_types
             SET code = ?, name = ?, base_rate = ?, max_adults = ?, max_children = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $code = (string)($data['code'] ?? '');
        $name = (string)($data['name'] ?? '');
        $baseRate = (float)($data['base_rate'] ?? 0);
        $maxAdults = (int)($data['max_adults'] ?? 2);
        $maxChildren = (int)($data['max_children'] ?? 0);

        $stmt->bind_param('ssdiii', $code, $name, $baseRate, $maxAdults, $maxChildren, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function delete(int $id): bool
    {
        if (!$this->conn) {
            return false;
        }
        $stmt = $this->conn->prepare("DELETE FROM room_types WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
