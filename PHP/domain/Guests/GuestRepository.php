<?php

require_once __DIR__ . '/../../core/Database.php';

final class GuestRepository
{
    private ?mysqli $conn;
    private ?bool $hasIdentityColumns = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasIdentityColumns(): bool
    {
        if ($this->hasIdentityColumns !== null) {
            return $this->hasIdentityColumns;
        }
        if (!$this->conn) {
            $this->hasIdentityColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasIdentityColumns = false;
            return false;
        }
        $sql =
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'guests'
               AND COLUMN_NAME IN ('id_type','id_number','id_photo_path')";

        $res = $this->conn->query($sql);
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasIdentityColumns = ($count === 3);
        return $this->hasIdentityColumns;
    }

    public function search(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            if ($this->hasIdentityColumns()) {
                $sql = "SELECT id, first_name, last_name, email, phone, id_type, id_number, id_photo_path, status, created_at FROM guests ORDER BY id DESC LIMIT 200";
            } else {
                $sql = "SELECT id, first_name, last_name, email, phone, NULL AS id_type, NULL AS id_number, NULL AS id_photo_path, status, created_at FROM guests ORDER BY id DESC LIMIT 200";
            }
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
        if ($this->hasIdentityColumns()) {
            $stmt = $this->conn->prepare(
                "SELECT id, first_name, last_name, email, phone, id_type, id_number, id_photo_path, status, created_at
                 FROM guests
                 WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR id_number LIKE ?
                 ORDER BY id DESC LIMIT 200"
            );
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id, first_name, last_name, email, phone, NULL AS id_type, NULL AS id_number, NULL AS id_photo_path, status, created_at
                 FROM guests
                 WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?
                 ORDER BY id DESC LIMIT 200"
            );
        }
        if (!$stmt) {
            return [];
        }
        if ($this->hasIdentityColumns()) {
            $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        } else {
            $stmt->bind_param('ssss', $like, $like, $like, $like);
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

    public function findById(int $id): ?array
    {
        if (!$this->conn) {
            return null;
        }

        if ($this->hasIdentityColumns()) {
            $stmt = $this->conn->prepare("SELECT id, first_name, last_name, email, phone, id_type, id_number, id_photo_path, status, created_at FROM guests WHERE id = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT id, first_name, last_name, email, phone, NULL AS id_type, NULL AS id_number, NULL AS id_photo_path, status, created_at FROM guests WHERE id = ?");
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

    public function create(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        if (!$this->hasIdentityColumns()) {
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

        $stmt = $this->conn->prepare(
            "INSERT INTO guests (first_name, last_name, email, phone, id_type, id_number, id_photo_path, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $first = (string)($data['first_name'] ?? '');
        $last = (string)($data['last_name'] ?? '');
        $email = (string)($data['email'] ?? '');
        $phone = (string)($data['phone'] ?? '');
        $idType = (string)($data['id_type'] ?? '');
        $idNumber = (string)($data['id_number'] ?? '');
        $idPhotoPath = (string)($data['id_photo_path'] ?? '');
        $status = (string)($data['status'] ?? 'Lead');

        if (trim($idType) === '') {
            $idType = null;
        }
        if (trim($idNumber) === '') {
            $idNumber = null;
        }
        if (trim($idPhotoPath) === '') {
            $idPhotoPath = null;
        }

        $stmt->bind_param('ssssssss', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $status);
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

        if (!$this->hasIdentityColumns()) {
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

        $stmt = $this->conn->prepare(
            "UPDATE guests
             SET first_name = ?, last_name = ?, email = ?, phone = ?, id_type = ?, id_number = ?, id_photo_path = ?, status = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $first = (string)($data['first_name'] ?? '');
        $last = (string)($data['last_name'] ?? '');
        $email = (string)($data['email'] ?? '');
        $phone = (string)($data['phone'] ?? '');
        $idType = (string)($data['id_type'] ?? '');
        $idNumber = (string)($data['id_number'] ?? '');
        $idPhotoPath = (string)($data['id_photo_path'] ?? '');
        $status = (string)($data['status'] ?? 'Lead');

        if (trim($idType) === '') {
            $idType = null;
        }
        if (trim($idNumber) === '') {
            $idNumber = null;
        }
        if (trim($idPhotoPath) === '') {
            $idPhotoPath = null;
        }

        $stmt->bind_param('ssssssssi', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $status, $id);
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
