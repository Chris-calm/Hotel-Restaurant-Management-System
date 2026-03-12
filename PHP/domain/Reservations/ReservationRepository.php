<?php

require_once __DIR__ . '/../../core/Database.php';

final class ReservationRepository
{
    private ?mysqli $conn;
    private ?bool $hasGuestIdentityColumns = null;
    private ?bool $hasRoomTypeImageColumn = null;
    private ?bool $hasRoomImageColumn = null;
    private ?bool $hasPromoColumns = null;
    private ?bool $hasPromoCodesTable = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function listReservationsByGuestId(int $guestId, int $limit = 50): array
    {
        if (!$this->conn) {
            return [];
        }

        $guestId = max(1, $guestId);
        $limit = max(1, min(200, $limit));

        $sql =
            "SELECT r.id, r.reference_no, r.status, r.checkin_date, r.checkout_date, r.created_at,
                    rr.room_id, rooms.room_no,
                    rt.name AS room_type_name
             FROM reservations r
             LEFT JOIN reservation_rooms rr ON rr.reservation_id = r.id
             LEFT JOIN rooms ON rooms.id = rr.room_id
             LEFT JOIN room_types rt ON rt.id = rr.room_type_id
             WHERE r.guest_id = ?
             ORDER BY r.id DESC
             LIMIT {$limit}";

        $stmt = $this->conn->prepare($sql);
        if (!($stmt instanceof mysqli_stmt)) {
            return [];
        }
        $stmt->bind_param('i', $guestId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
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

    private function hasGuestIdentityColumns(): bool
    {
        if ($this->hasGuestIdentityColumns !== null) {
            return $this->hasGuestIdentityColumns;
        }
        if (!$this->conn) {
            $this->hasGuestIdentityColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasGuestIdentityColumns = false;
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
        $this->hasGuestIdentityColumns = ($count === 3);
        return $this->hasGuestIdentityColumns;
    }

    private function hasPromoColumns(): bool
    {
        if ($this->hasPromoColumns !== null) {
            return $this->hasPromoColumns;
        }
        if (!$this->conn) {
            $this->hasPromoColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasPromoColumns = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'reservations'
               AND COLUMN_NAME IN ('promo_code_id','promo_code','discount_amount')"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasPromoColumns = ($count === 3);
        return $this->hasPromoColumns;
    }

    private function hasPromoCodesTable(): bool
    {
        if ($this->hasPromoCodesTable !== null) {
            return $this->hasPromoCodesTable;
        }
        if (!$this->conn) {
            $this->hasPromoCodesTable = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasPromoCodesTable = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'promo_codes'"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasPromoCodesTable = ($count === 1);
        return $this->hasPromoCodesTable;
    }

    public function listPromoCodes(): array
    {
        if (!$this->conn || !$this->hasPromoCodesTable()) {
            return [];
        }
        $res = $this->conn->query(
            "SELECT id, code, discount_type, discount_value, start_date, end_date,
                    max_uses, used_count, is_active, notes, created_at
             FROM promo_codes
             ORDER BY id DESC"
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

    public function createPromoCode(array $data): int
    {
        if (!$this->conn || !$this->hasPromoCodesTable()) {
            return 0;
        }
        $stmt = $this->conn->prepare(
            "INSERT INTO promo_codes (code, discount_type, discount_value, start_date, end_date, max_uses, used_count, is_active, notes)
             VALUES (?, ?, ?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,0), 0, ?, ?)"
        );
        if (!($stmt instanceof mysqli_stmt)) {
            return 0;
        }

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $discountType = (string)($data['discount_type'] ?? 'Percent');
        $discountValue = (float)($data['discount_value'] ?? 0);
        $startDate = trim((string)($data['start_date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));
        $maxUses = (int)($data['max_uses'] ?? 0);
        $isActive = (int)($data['is_active'] ?? 1);
        $notes = (string)($data['notes'] ?? '');

        $stmt->bind_param('ssdssiis', $code, $discountType, $discountValue, $startDate, $endDate, $maxUses, $isActive, $notes);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function setPromoCodeActive(int $promoId, bool $active): bool
    {
        if (!$this->conn || !$this->hasPromoCodesTable()) {
            return false;
        }
        $promoId = max(1, $promoId);
        $isActive = $active ? 1 : 0;
        $stmt = $this->conn->prepare("UPDATE promo_codes SET is_active = ? WHERE id = ?");
        if (!($stmt instanceof mysqli_stmt)) {
            return false;
        }
        $stmt->bind_param('ii', $isActive, $promoId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    public function findActivePromoByCode(string $code, string $onDate): ?array
    {
        if (!$this->conn || !$this->hasPromoCodesTable()) {
            return null;
        }

        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT id, code, discount_type, discount_value, start_date, end_date, max_uses, used_count, is_active
             FROM promo_codes
             WHERE code = ?
               AND is_active = 1
               AND (start_date IS NULL OR start_date <= ?)
               AND (end_date IS NULL OR end_date >= ?)
             LIMIT 1"
        );
        if (!($stmt instanceof mysqli_stmt)) {
            return null;
        }
        $stmt->bind_param('sss', $code, $onDate, $onDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $maxUses = $row['max_uses'] ?? null;
        if ($maxUses !== null && $maxUses !== '' && (int)$maxUses > 0) {
            if ((int)($row['used_count'] ?? 0) >= (int)$maxUses) {
                return null;
            }
        }

        return $row;
    }

    public function incrementPromoUsedCount(int $promoId): void
    {
        if (!$this->conn || !$this->hasPromoCodesTable()) {
            return;
        }
        $promoId = max(1, $promoId);
        $stmt = $this->conn->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id = ?");
        if (!($stmt instanceof mysqli_stmt)) {
            return;
        }
        $stmt->bind_param('i', $promoId);
        $stmt->execute();
        $stmt->close();
    }

    public function listGuests(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            $sql = "SELECT id, first_name, last_name, email, phone FROM guests ORDER BY id DESC LIMIT 200";
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
            "SELECT id, first_name, last_name, email, phone
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

    public function findGuestById(int $id): ?array
    {
        if (!$this->conn) {
            return null;
        }

        $stmt = $this->conn->prepare("SELECT id, first_name, last_name, email, phone FROM guests WHERE id = ?");
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

    public function findAvailableRooms(string $checkinDate, string $checkoutDate, int $roomTypeId = 0): array
    {
        if (!$this->conn) {
            return [];
        }

        $statusSql = "('Pending','Confirmed','Upcoming','Checked In')";

        $roomTypeImageSelect = $this->hasRoomTypeImageColumn()
            ? 'room_types.image_path AS room_type_image_path'
            : 'NULL AS room_type_image_path';

        $roomImageSelect = $this->hasRoomImageColumn()
            ? 'rooms.image_path AS room_image_path'
            : 'NULL AS room_image_path';

        $sql =
            "SELECT rooms.id, rooms.room_no, rooms.floor, rooms.status AS room_status,
                    room_types.id AS room_type_id, room_types.code AS room_type_code, room_types.name AS room_type_name,
                    room_types.base_rate,
                    {$roomTypeImageSelect},
                    {$roomImageSelect}
             FROM rooms
             INNER JOIN room_types ON room_types.id = rooms.room_type_id
             WHERE rooms.status <> 'Out of Order'
               AND (? = 0 OR rooms.room_type_id = ?)
               AND NOT EXISTS (
                    SELECT 1
                    FROM reservation_rooms rr
                    INNER JOIN reservations r ON r.id = rr.reservation_id
                    WHERE rr.room_id = rooms.id
                      AND r.status IN $statusSql
                      AND r.checkin_date < ?
                      AND r.checkout_date > ?
               )
             ORDER BY rooms.room_no ASC";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('iiss', $roomTypeId, $roomTypeId, $checkoutDate, $checkinDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function findRoomById(int $roomId): ?array
    {
        if (!$this->conn) {
            return null;
        }

        $roomTypeImageSelect = $this->hasRoomTypeImageColumn()
            ? 'room_types.image_path AS room_type_image_path,'
            : 'NULL AS room_type_image_path,';

        $roomImageSelect = $this->hasRoomImageColumn()
            ? 'rooms.image_path AS room_image_path,'
            : 'NULL AS room_image_path,';

        $stmt = $this->conn->prepare(
            "SELECT rooms.id, rooms.room_no, rooms.floor, rooms.status AS room_status,
                    room_types.id AS room_type_id, room_types.code AS room_type_code, room_types.name AS room_type_name,
                    room_types.base_rate,
                    {$roomTypeImageSelect}
                    {$roomImageSelect}
             FROM rooms
             INNER JOIN room_types ON room_types.id = rooms.room_type_id
             WHERE rooms.id = ?"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $roomId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function createReservation(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $hasPromo = $this->hasPromoColumns();
        $sql = $hasPromo
            ? "INSERT INTO reservations (reference_no, guest_id, source, status, checkin_date, checkout_date, promo_code_id, promo_code, discount_amount, deposit_amount, payment_method, notes)
               VALUES (?, ?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,''), ?, ?, ?, ?)"
            : "INSERT INTO reservations (reference_no, guest_id, source, status, checkin_date, checkout_date, deposit_amount, payment_method, notes)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $referenceNo = (string)($data['reference_no'] ?? '');
        $guestId = (int)($data['guest_id'] ?? 0);
        $source = (string)($data['source'] ?? 'Walk-in');
        $status = (string)($data['status'] ?? 'Pending');
        $checkinDate = (string)($data['checkin_date'] ?? '');
        $checkoutDate = (string)($data['checkout_date'] ?? '');
        $promoCodeId = $hasPromo ? (int)($data['promo_code_id'] ?? 0) : 0;
        $promoCode = $hasPromo ? (string)($data['promo_code'] ?? '') : '';
        $discountAmount = $hasPromo ? (float)($data['discount_amount'] ?? 0) : 0.0;
        $depositAmount = (float)($data['deposit_amount'] ?? 0);
        $paymentMethod = (string)($data['payment_method'] ?? '');
        if (trim($paymentMethod) === '') {
            $paymentMethod = null;
        }
        $notes = (string)($data['notes'] ?? '');

        if ($hasPromo) {
            $stmt->bind_param(
                'sissssisddss',
                $referenceNo,
                $guestId,
                $source,
                $status,
                $checkinDate,
                $checkoutDate,
                $promoCodeId,
                $promoCode,
                $discountAmount,
                $depositAmount,
                $paymentMethod,
                $notes
            );
        } else {
            $stmt->bind_param(
                'sissssdss',
                $referenceNo,
                $guestId,
                $source,
                $status,
                $checkinDate,
                $checkoutDate,
                $depositAmount,
                $paymentMethod,
                $notes
            );
        }

        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function attachRoomToReservation(int $reservationId, array $data): bool
    {
        if (!$this->conn) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO reservation_rooms (reservation_id, room_id, room_type_id, rate, adults, children)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        $roomId = (int)($data['room_id'] ?? 0);
        $roomTypeId = (int)($data['room_type_id'] ?? 0);
        $rate = (float)($data['rate'] ?? 0);
        $adults = (int)($data['adults'] ?? 1);
        $children = (int)($data['children'] ?? 0);

        $stmt->bind_param('iiidii', $reservationId, $roomId, $roomTypeId, $rate, $adults, $children);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function findReservationDetails(int $reservationId): ?array
    {
        if (!$this->conn) {
            return null;
        }

        $guestIdentitySelect = $this->hasGuestIdentityColumns()
            ? 'g.id_type, g.id_number, g.id_photo_path,'
            : 'NULL AS id_type, NULL AS id_number, NULL AS id_photo_path,';

        $promoSelect = $this->hasPromoColumns()
            ? 'r.promo_code_id, r.promo_code, r.discount_amount,'
            : 'NULL AS promo_code_id, NULL AS promo_code, 0 AS discount_amount,';

        $stmt = $this->conn->prepare(
            "SELECT r.id, r.reference_no, r.source, r.status, r.checkin_date, r.checkout_date,
                    {$promoSelect}
                    r.deposit_amount, r.payment_method, r.notes, r.created_at,
                    g.id AS guest_id, g.first_name, g.last_name, g.email, g.phone,
                    {$guestIdentitySelect}
                    rr.room_id, rr.room_type_id, rr.rate, rr.adults, rr.children,
                    rooms.room_no, rooms.floor,
                    rt.code AS room_type_code, rt.name AS room_type_name
             FROM reservations r
             INNER JOIN guests g ON g.id = r.guest_id
             LEFT JOIN reservation_rooms rr ON rr.reservation_id = r.id
             LEFT JOIN rooms ON rooms.id = rr.room_id
             LEFT JOIN room_types rt ON rt.id = rr.room_type_id
             WHERE r.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function listReservations(array $filters = []): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim((string)($filters['q'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $from = trim((string)($filters['checkin_from'] ?? ''));
        $to = trim((string)($filters['checkin_to'] ?? ''));

        $guestIdentitySelect = $this->hasGuestIdentityColumns()
            ? 'g.id_type, g.id_number, g.id_photo_path,'
            : 'NULL AS id_type, NULL AS id_number, NULL AS id_photo_path,';

        $promoSelect = $this->hasPromoColumns()
            ? 'r.promo_code, r.discount_amount,'
            : 'NULL AS promo_code, 0 AS discount_amount,';

        $sql =
            "SELECT r.id, r.reference_no, r.status, r.checkin_date, r.checkout_date,
                    {$promoSelect}
                    r.deposit_amount, r.payment_method, r.created_at,
                    g.first_name, g.last_name, g.phone, g.email,
                    {$guestIdentitySelect}
                    rooms.room_no,
                    rt.name AS room_type_name
             FROM reservations r
             INNER JOIN guests g ON g.id = r.guest_id
             LEFT JOIN reservation_rooms rr ON rr.reservation_id = r.id
             LEFT JOIN rooms ON rooms.id = rr.room_id
             LEFT JOIN room_types rt ON rt.id = rr.room_type_id";

        $where = [];
        $types = '';
        $params = [];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(r.reference_no LIKE ? OR g.first_name LIKE ? OR g.last_name LIKE ? OR g.phone LIKE ?)";
            $types .= 'ssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== '') {
            $where[] = "r.status = ?";
            $types .= 's';
            $params[] = $status;
        }
        if ($from !== '') {
            $where[] = "r.checkin_date >= ?";
            $types .= 's';
            $params[] = $from;
        }
        if ($to !== '') {
            $where[] = "r.checkin_date <= ?";
            $types .= 's';
            $params[] = $to;
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY r.id DESC LIMIT 200';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($types !== '') {
            $bind = [];
            $bind[] = $types;
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
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

    public function updateReservationStatus(int $reservationId, string $status, float $depositAmount, ?string $paymentMethod): bool
    {
        if (!$this->conn) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "UPDATE reservations
             SET status = ?, deposit_amount = ?, payment_method = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sdsi', $status, $depositAmount, $paymentMethod, $reservationId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
