<?php

require_once __DIR__ . '/../../core/Database.php';

final class GuestRepository
{
    private ?mysqli $conn;
    private ?bool $hasIdentityColumns = null;
    private ?bool $hasPreferencesNotesColumns = null;
    private ?bool $hasLoyaltyColumns = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasPreferencesNotesColumns(): bool
    {
        if ($this->hasPreferencesNotesColumns !== null) {
            return $this->hasPreferencesNotesColumns;
        }
        if (!$this->conn) {
            $this->hasPreferencesNotesColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasPreferencesNotesColumns = false;
            return false;
        }

        $sql =
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'guests'
               AND COLUMN_NAME IN ('preferences','notes')";
        $res = $this->conn->query($sql);
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasPreferencesNotesColumns = ($count === 2);
        return $this->hasPreferencesNotesColumns;
    }

    private function hasLoyaltyColumns(): bool
    {
        if ($this->hasLoyaltyColumns !== null) {
            return $this->hasLoyaltyColumns;
        }
        if (!$this->conn) {
            $this->hasLoyaltyColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasLoyaltyColumns = false;
            return false;
        }

        $sql =
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'guests'
               AND COLUMN_NAME IN ('loyalty_tier','loyalty_points')";
        $res = $this->conn->query($sql);
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasLoyaltyColumns = ($count === 2);
        return $this->hasLoyaltyColumns;
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
            $idSelect = $this->hasIdentityColumns()
                ? 'id_type, id_number, id_photo_path,'
                : 'NULL AS id_type, NULL AS id_number, NULL AS id_photo_path,';
            $prefsSelect = $this->hasPreferencesNotesColumns()
                ? 'preferences, notes,'
                : 'NULL AS preferences, NULL AS notes,';
            $loyaltySelect = $this->hasLoyaltyColumns()
                ? 'loyalty_tier, loyalty_points,'
                : 'NULL AS loyalty_tier, 0 AS loyalty_points,';

            $sql = "SELECT id, first_name, last_name, email, phone,
                           {$idSelect}
                           {$prefsSelect}
                           {$loyaltySelect}
                           status, created_at
                    FROM guests
                    ORDER BY id DESC LIMIT 200";
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
        $idSelect = $this->hasIdentityColumns()
            ? 'id_type, id_number, id_photo_path,'
            : 'NULL AS id_type, NULL AS id_number, NULL AS id_photo_path,';
        $prefsSelect = $this->hasPreferencesNotesColumns()
            ? 'preferences, notes,'
            : 'NULL AS preferences, NULL AS notes,';
        $loyaltySelect = $this->hasLoyaltyColumns()
            ? 'loyalty_tier, loyalty_points,'
            : 'NULL AS loyalty_tier, 0 AS loyalty_points,';

        if ($this->hasIdentityColumns()) {
            $stmt = $this->conn->prepare(
                "SELECT id, first_name, last_name, email, phone,
                        {$idSelect}
                        {$prefsSelect}
                        {$loyaltySelect}
                        status, created_at
                 FROM guests
                 WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR id_number LIKE ?
                 ORDER BY id DESC LIMIT 200"
            );
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id, first_name, last_name, email, phone,
                        {$idSelect}
                        {$prefsSelect}
                        {$loyaltySelect}
                        status, created_at
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

        $idSelect = $this->hasIdentityColumns()
            ? 'id_type, id_number, id_photo_path,'
            : 'NULL AS id_type, NULL AS id_number, NULL AS id_photo_path,';
        $prefsSelect = $this->hasPreferencesNotesColumns()
            ? 'preferences, notes,'
            : 'NULL AS preferences, NULL AS notes,';
        $loyaltySelect = $this->hasLoyaltyColumns()
            ? 'loyalty_tier, loyalty_points,'
            : 'NULL AS loyalty_tier, 0 AS loyalty_points,';

        $stmt = $this->conn->prepare(
            "SELECT id, first_name, last_name, email, phone,
                    {$idSelect}
                    {$prefsSelect}
                    {$loyaltySelect}
                    status, created_at
             FROM guests WHERE id = ?"
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

        $hasId = $this->hasIdentityColumns();
        $hasPrefs = $this->hasPreferencesNotesColumns();
        $hasLoyalty = $this->hasLoyaltyColumns();

        if ($hasId && $hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, id_type, id_number, id_photo_path, preferences, notes, loyalty_tier, loyalty_points, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($hasId && $hasPrefs && !$hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, id_type, id_number, id_photo_path, preferences, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($hasId && !$hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, id_type, id_number, id_photo_path, loyalty_tier, loyalty_points, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif ($hasId && !$hasPrefs && !$hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, id_type, id_number, id_photo_path, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif (!$hasId && $hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, preferences, notes, loyalty_tier, loyalty_points, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif (!$hasId && $hasPrefs && !$hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, preferences, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
        } elseif (!$hasId && !$hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, loyalty_tier, loyalty_points, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO guests (first_name, last_name, email, phone, status)
                 VALUES (?, ?, ?, ?, ?)"
            );
        }
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
        $preferences = (string)($data['preferences'] ?? '');
        $notes = (string)($data['notes'] ?? '');
        $tier = (string)($data['loyalty_tier'] ?? '');
        $points = is_numeric((string)($data['loyalty_points'] ?? '')) ? (int)$data['loyalty_points'] : 0;
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

        if (trim($preferences) === '') {
            $preferences = null;
        }
        if (trim($notes) === '') {
            $notes = null;
        }
        if (trim($tier) === '' || $tier === 'None') {
            $tier = null;
        }

        $hasId = $this->hasIdentityColumns();
        $hasPrefs = $this->hasPreferencesNotesColumns();
        $hasLoyalty = $this->hasLoyaltyColumns();

        if ($hasId && $hasPrefs && $hasLoyalty) {
            $stmt->bind_param('sssssssssiis', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $preferences, $notes, $tier, $points, $status);
        } elseif ($hasId && $hasPrefs && !$hasLoyalty) {
            $stmt->bind_param('ssssssssss', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $preferences, $notes, $status);
        } elseif ($hasId && !$hasPrefs && $hasLoyalty) {
            $stmt->bind_param('ssssssssis', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $tier, $points, $status);
        } elseif ($hasId && !$hasPrefs && !$hasLoyalty) {
            $stmt->bind_param('ssssssss', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $status);
        } elseif (!$hasId && $hasPrefs && $hasLoyalty) {
            $stmt->bind_param('sssssssis', $first, $last, $email, $phone, $preferences, $notes, $tier, $points, $status);
        } elseif (!$hasId && $hasPrefs && !$hasLoyalty) {
            $stmt->bind_param('sssssss', $first, $last, $email, $phone, $preferences, $notes, $status);
        } elseif (!$hasId && !$hasPrefs && $hasLoyalty) {
            $stmt->bind_param('sssssis', $first, $last, $email, $phone, $tier, $points, $status);
        } else {
            $stmt->bind_param('sssss', $first, $last, $email, $phone, $status);
        }
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

        $hasId = $this->hasIdentityColumns();
        $hasPrefs = $this->hasPreferencesNotesColumns();
        $hasLoyalty = $this->hasLoyaltyColumns();

        if ($hasId && $hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?,
                     id_type = ?, id_number = ?, id_photo_path = ?,
                     preferences = ?, notes = ?, loyalty_tier = ?, loyalty_points = ?,
                     status = ?
                 WHERE id = ?"
            );
        } elseif ($hasId && $hasPrefs && !$hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?,
                     id_type = ?, id_number = ?, id_photo_path = ?,
                     preferences = ?, notes = ?,
                     status = ?
                 WHERE id = ?"
            );
        } elseif ($hasId && !$hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?,
                     id_type = ?, id_number = ?, id_photo_path = ?,
                     loyalty_tier = ?, loyalty_points = ?,
                     status = ?
                 WHERE id = ?"
            );
        } elseif ($hasId && !$hasPrefs && !$hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?, id_type = ?, id_number = ?, id_photo_path = ?, status = ?
                 WHERE id = ?"
            );
        } elseif (!$hasId && $hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?,
                     preferences = ?, notes = ?, loyalty_tier = ?, loyalty_points = ?,
                     status = ?
                 WHERE id = ?"
            );
        } elseif (!$hasId && $hasPrefs && !$hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?,
                     preferences = ?, notes = ?,
                     status = ?
                 WHERE id = ?"
            );
        } elseif (!$hasId && !$hasPrefs && $hasLoyalty) {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?,
                     loyalty_tier = ?, loyalty_points = ?,
                     status = ?
                 WHERE id = ?"
            );
        } else {
            $stmt = $this->conn->prepare(
                "UPDATE guests
                 SET first_name = ?, last_name = ?, email = ?, phone = ?, status = ?
                 WHERE id = ?"
            );
        }
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
        $preferences = (string)($data['preferences'] ?? '');
        $notes = (string)($data['notes'] ?? '');
        $tier = (string)($data['loyalty_tier'] ?? '');
        $points = is_numeric((string)($data['loyalty_points'] ?? '')) ? (int)$data['loyalty_points'] : 0;
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

        if (trim($preferences) === '') {
            $preferences = null;
        }
        if (trim($notes) === '') {
            $notes = null;
        }
        if (trim($tier) === '' || $tier === 'None') {
            $tier = null;
        }

        if ($hasId && $hasPrefs && $hasLoyalty) {
            $stmt->bind_param('ssssssssssisi', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $preferences, $notes, $tier, $points, $status, $id);
        } elseif ($hasId && $hasPrefs && !$hasLoyalty) {
            $stmt->bind_param('ssssssssssi', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $preferences, $notes, $status, $id);
        } elseif ($hasId && !$hasPrefs && $hasLoyalty) {
            $stmt->bind_param('ssssssssisi', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $tier, $points, $status, $id);
        } elseif ($hasId && !$hasPrefs && !$hasLoyalty) {
            $stmt->bind_param('ssssssssi', $first, $last, $email, $phone, $idType, $idNumber, $idPhotoPath, $status, $id);
        } elseif (!$hasId && $hasPrefs && $hasLoyalty) {
            $stmt->bind_param('sssssssisi', $first, $last, $email, $phone, $preferences, $notes, $tier, $points, $status, $id);
        } elseif (!$hasId && $hasPrefs && !$hasLoyalty) {
            $stmt->bind_param('sssssssi', $first, $last, $email, $phone, $preferences, $notes, $status, $id);
        } elseif (!$hasId && !$hasPrefs && $hasLoyalty) {
            $stmt->bind_param('sssssisi', $first, $last, $email, $phone, $tier, $points, $status, $id);
        } else {
            $stmt->bind_param('sssssi', $first, $last, $email, $phone, $status, $id);
        }
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
