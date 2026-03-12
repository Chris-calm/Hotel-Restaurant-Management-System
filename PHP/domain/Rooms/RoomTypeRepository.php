<?php

require_once __DIR__ . '/../../core/Database.php';

final class RoomTypeRepository
{
    private ?mysqli $conn;
    private ?bool $hasImageColumn = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
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
               AND TABLE_NAME = 'room_types'
               AND COLUMN_NAME = 'image_path'"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasImageColumn = ($count === 1);
        return $this->hasImageColumn;
    }

    public function all(): array
    {
        if (!$this->conn) {
            return [];
        }
        if ($this->hasImageColumn()) {
            $res = $this->conn->query("SELECT id, code, name, base_rate, max_adults, max_children, image_path, created_at FROM room_types ORDER BY id DESC LIMIT 500");
        } else {
            $res = $this->conn->query("SELECT id, code, name, base_rate, max_adults, max_children, NULL AS image_path, created_at FROM room_types ORDER BY id DESC LIMIT 500");
        }
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
        if ($this->hasImageColumn()) {
            $stmt = $this->conn->prepare("SELECT id, code, name, base_rate, max_adults, max_children, image_path, created_at FROM room_types WHERE id = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT id, code, name, base_rate, max_adults, max_children, NULL AS image_path, created_at FROM room_types WHERE id = ?");
        }
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
        if ($this->hasImageColumn()) {
            $stmt = $this->conn->prepare("SELECT id, code, name, base_rate, max_adults, max_children, image_path, created_at FROM room_types WHERE code = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT id, code, name, base_rate, max_adults, max_children, NULL AS image_path, created_at FROM room_types WHERE code = ?");
        }
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

        if (!$this->hasImageColumn()) {
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

        $stmt = $this->conn->prepare(
            "INSERT INTO room_types (code, name, base_rate, max_adults, max_children, image_path)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $code = (string)($data['code'] ?? '');
        $name = (string)($data['name'] ?? '');
        $baseRate = (float)($data['base_rate'] ?? 0);
        $maxAdults = (int)($data['max_adults'] ?? 2);
        $maxChildren = (int)($data['max_children'] ?? 0);

        $imagePath = (string)($data['image_path'] ?? '');
        if (trim($imagePath) === '') {
            $imagePath = null;
        }

        $stmt->bind_param('ssdiis', $code, $name, $baseRate, $maxAdults, $maxChildren, $imagePath);
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

        $stmt = $this->conn->prepare(
            "UPDATE room_types
             SET code = ?, name = ?, base_rate = ?, max_adults = ?, max_children = ?, image_path = ?
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

        $imagePath = (string)($data['image_path'] ?? '');
        if (trim($imagePath) === '') {
            $imagePath = null;
        }

        $stmt->bind_param('ssdiisi', $code, $name, $baseRate, $maxAdults, $maxChildren, $imagePath, $id);
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
