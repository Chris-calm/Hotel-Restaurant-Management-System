<?php

require_once __DIR__ . '/../../core/Database.php';

final class ReservationRepository
{
    private ?mysqli $conn;
    private ?bool $hasGuestIdentityColumns = null;
    private ?bool $hasRoomTypeImageColumn = null;
    private ?bool $hasRoomImageColumn = null;

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

        $stmt = $this->conn->prepare(
            "INSERT INTO reservations (reference_no, guest_id, source, status, checkin_date, checkout_date, deposit_amount, payment_method, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $referenceNo = (string)($data['reference_no'] ?? '');
        $guestId = (int)($data['guest_id'] ?? 0);
        $source = (string)($data['source'] ?? 'Walk-in');
        $status = (string)($data['status'] ?? 'Pending');
        $checkinDate = (string)($data['checkin_date'] ?? '');
        $checkoutDate = (string)($data['checkout_date'] ?? '');
        $depositAmount = (float)($data['deposit_amount'] ?? 0);
        $paymentMethod = (string)($data['payment_method'] ?? '');
        if (trim($paymentMethod) === '') {
            $paymentMethod = null;
        }
        $notes = (string)($data['notes'] ?? '');

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

        $stmt = $this->conn->prepare(
            "SELECT r.id, r.reference_no, r.source, r.status, r.checkin_date, r.checkout_date,
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

        $sql =
            "SELECT r.id, r.reference_no, r.status, r.checkin_date, r.checkout_date,
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
