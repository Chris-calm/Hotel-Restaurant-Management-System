<?php

require_once __DIR__ . '/../../core/Database.php';

final class GuestRepository
{
    private ?mysqli $conn;
    private ?bool $hasIdentityColumns = null;
    private ?bool $hasPreferencesNotesColumns = null;
    private ?bool $hasLoyaltyColumns = null;
    private ?bool $hasProfilePictureColumn = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasProfilePictureColumn(): bool
    {
        if ($this->hasProfilePictureColumn !== null) {
            return $this->hasProfilePictureColumn;
        }
        if (!$this->conn) {
            $this->hasProfilePictureColumn = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasProfilePictureColumn = false;
            return false;
        }
        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'guests'
               AND COLUMN_NAME = 'profile_picture_path'"
        );
        $this->hasProfilePictureColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        return $this->hasProfilePictureColumn;
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
            $ppSelect = $this->hasProfilePictureColumn() ? 'profile_picture_path,' : 'NULL AS profile_picture_path,';
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
                           {$ppSelect}
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
        $ppSelect = $this->hasProfilePictureColumn() ? 'profile_picture_path,' : 'NULL AS profile_picture_path,';
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
                        {$ppSelect}
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
                        {$ppSelect}
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

        $ppSelect = $this->hasProfilePictureColumn() ? 'profile_picture_path,' : 'NULL AS profile_picture_path,';
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
                    {$ppSelect}
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
        $hasPp = $this->hasProfilePictureColumn();
        $hasPrefs = $this->hasPreferencesNotesColumns();
        $hasLoyalty = $this->hasLoyaltyColumns();

        $cols = ['first_name', 'last_name', 'email', 'phone'];
        $types = 'ssss';
        $params = [
            (string)($data['first_name'] ?? ''),
            (string)($data['last_name'] ?? ''),
            (string)($data['email'] ?? ''),
            (string)($data['phone'] ?? ''),
        ];

        if ($hasPp) {
            $cols[] = 'profile_picture_path';
            $types .= 's';
            $pp = trim((string)($data['profile_picture_path'] ?? ''));
            $params[] = ($pp === '') ? null : $pp;
        }
        if ($hasId) {
            $cols[] = 'id_type';
            $cols[] = 'id_number';
            $cols[] = 'id_photo_path';
            $types .= 'sss';
            $idType = trim((string)($data['id_type'] ?? ''));
            $idNo = trim((string)($data['id_number'] ?? ''));
            $idPhoto = trim((string)($data['id_photo_path'] ?? ''));
            $params[] = ($idType === '') ? null : $idType;
            $params[] = ($idNo === '') ? null : $idNo;
            $params[] = ($idPhoto === '') ? null : $idPhoto;
        }
        if ($hasPrefs) {
            $cols[] = 'preferences';
            $cols[] = 'notes';
            $types .= 'ss';
            $prefs = trim((string)($data['preferences'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            $params[] = ($prefs === '') ? null : $prefs;
            $params[] = ($notes === '') ? null : $notes;
        }
        if ($hasLoyalty) {
            $cols[] = 'loyalty_tier';
            $cols[] = 'loyalty_points';
            $types .= 'si';
            $tier = trim((string)($data['loyalty_tier'] ?? ''));
            $params[] = ($tier === '' || $tier === 'None') ? null : $tier;
            $params[] = is_numeric((string)($data['loyalty_points'] ?? '')) ? (int)$data['loyalty_points'] : 0;
        }

        $cols[] = 'status';
        $types .= 's';
        $params[] = (string)($data['status'] ?? 'Lead');

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO guests (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $bind = [];
        $bind[] = $types;
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);

        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $newId;
    }

    public function update(int $id, array $data): bool
    {
        if (!$this->conn) {
            return false;
        }

        $hasId = $this->hasIdentityColumns();
        $hasPp = $this->hasProfilePictureColumn();
        $hasPrefs = $this->hasPreferencesNotesColumns();
        $hasLoyalty = $this->hasLoyaltyColumns();

        $sets = ['first_name = ?', 'last_name = ?', 'email = ?', 'phone = ?'];
        $types = 'ssss';
        $params = [
            (string)($data['first_name'] ?? ''),
            (string)($data['last_name'] ?? ''),
            (string)($data['email'] ?? ''),
            (string)($data['phone'] ?? ''),
        ];

        if ($hasPp) {
            $sets[] = 'profile_picture_path = ?';
            $types .= 's';
            $pp = trim((string)($data['profile_picture_path'] ?? ''));
            $params[] = ($pp === '') ? null : $pp;
        }
        if ($hasId) {
            $sets[] = 'id_type = ?';
            $sets[] = 'id_number = ?';
            $sets[] = 'id_photo_path = ?';
            $types .= 'sss';
            $idType = trim((string)($data['id_type'] ?? ''));
            $idNo = trim((string)($data['id_number'] ?? ''));
            $idPhoto = trim((string)($data['id_photo_path'] ?? ''));
            $params[] = ($idType === '') ? null : $idType;
            $params[] = ($idNo === '') ? null : $idNo;
            $params[] = ($idPhoto === '') ? null : $idPhoto;
        }
        if ($hasPrefs) {
            $sets[] = 'preferences = ?';
            $sets[] = 'notes = ?';
            $types .= 'ss';
            $prefs = trim((string)($data['preferences'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            $params[] = ($prefs === '') ? null : $prefs;
            $params[] = ($notes === '') ? null : $notes;
        }
        if ($hasLoyalty) {
            $sets[] = 'loyalty_tier = ?';
            $sets[] = 'loyalty_points = ?';
            $types .= 'si';
            $tier = trim((string)($data['loyalty_tier'] ?? ''));
            $params[] = ($tier === '' || $tier === 'None') ? null : $tier;
            $params[] = is_numeric((string)($data['loyalty_points'] ?? '')) ? (int)$data['loyalty_points'] : 0;
        }

        $sets[] = 'status = ?';
        $types .= 's';
        $params[] = (string)($data['status'] ?? 'Lead');

        $types .= 'i';
        $params[] = $id;

        $sql = 'UPDATE guests SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $bind = [];
        $bind[] = $types;
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);

        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
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
