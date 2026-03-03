<?php

require_once __DIR__ . '/../../core/Database.php';

final class GuestRepository
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
            $sql = "SELECT id, first_name, last_name, email, phone, status, created_at FROM guests ORDER BY id DESC LIMIT 200";
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
            "SELECT id, first_name, last_name, email, phone, status, created_at
             FROM guests
             WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?
             ORDER BY id DESC LIMIT 200"
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

    public function findById(int $id): ?array
    {
        if (!$this->conn) {
            return null;
        }
        $stmt = $this->conn->prepare("SELECT id, first_name, last_name, email, phone, status, created_at FROM guests WHERE id = ?");
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
            "INSERT INTO guests (first_name, last_name, email, phone, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $first = (string)($data['first_name'] ?? '');
        $last = (string)($data['last_name'] ?? '');
        $email = (string)($data['email'] ?? '');
        $phone = (string)($data['phone'] ?? '');
        $status = (string)($data['status'] ?? 'Lead');

        $stmt->bind_param('sssss', $first, $last, $email, $phone, $status);
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
            "UPDATE guests
             SET first_name = ?, last_name = ?, email = ?, phone = ?, status = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $first = (string)($data['first_name'] ?? '');
        $last = (string)($data['last_name'] ?? '');
        $email = (string)($data['email'] ?? '');
        $phone = (string)($data['phone'] ?? '');
        $status = (string)($data['status'] ?? 'Lead');

        $stmt->bind_param('sssssi', $first, $last, $email, $phone, $status, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function delete(int $id): bool
    {
        if (!$this->conn) {
            return false;
        }
        $stmt = $this->conn->prepare("DELETE FROM guests WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
