<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/ReservationRepository.php';
require_once __DIR__ . '/../Housekeeping/HousekeepingRepository.php';
require_once __DIR__ . '/../Rooms/RoomRepository.php';
require_once __DIR__ . '/../Maintenance/MaintenanceService.php';
require_once __DIR__ . '/../Maintenance/MaintenanceRepository.php';

final class ReservationService
{
    private ReservationRepository $repo;
    private ?HousekeepingRepository $housekeepingRepo;
    private ?RoomRepository $roomRepo;
    private ?MaintenanceService $maintenanceService;

    public function __construct(
        ReservationRepository $repo,
        ?HousekeepingRepository $housekeepingRepo = null,
        ?RoomRepository $roomRepo = null,
        ?MaintenanceService $maintenanceService = null
    )
    {
        $this->repo = $repo;
        $this->housekeepingRepo = $housekeepingRepo;
        $this->roomRepo = $roomRepo;
        $this->maintenanceService = $maintenanceService;
    }

    public static function allowedSources(): array
    {
        return ['Walk-in', 'Phone', 'Website', 'OTA', 'Agent'];
    }

    public static function allowedStatuses(): array
    {
        return ['Pending', 'Confirmed', 'Upcoming', 'Checked In', 'Completed', 'Cancelled', 'No Show'];
    }

    public static function allowedPaymentMethods(): array
    {
        return ['Cash', 'Card', 'GCash', 'Bank Transfer'];
    }

    public function listGuests(string $q = ''): array
    {
        return $this->repo->listGuests($q);
    }

    public function listReservations(array $filters = []): array
    {
        return $this->repo->listReservations($filters);
    }

    public function findAvailableRooms(string $checkinDate, string $checkoutDate, int $roomTypeId = 0): array
    {
        return $this->repo->findAvailableRooms($checkinDate, $checkoutDate, $roomTypeId);
    }

    public function getReservationDetails(int $reservationId): ?array
    {
        return $this->repo->findReservationDetails($reservationId);
    }

    public function updateStatus(int $reservationId, string $newStatus, array $data, array &$errors): bool
    {
        $errors = [];

        if (!Validator::inArray($newStatus, self::allowedStatuses())) {
            $errors['status'] = 'Status is invalid.';
            return false;
        }

        $current = $this->repo->findReservationDetails($reservationId);
        if (!$current) {
            $errors['general'] = 'Reservation not found.';
            return false;
        }

        $currentStatus = (string)($current['status'] ?? '');
        $allowed = $this->allowedTransitions($currentStatus);
        if (!in_array($newStatus, $allowed, true)) {
            $errors['status'] = 'Invalid status transition.';
            return false;
        }

        $deposit = (float)($current['deposit_amount'] ?? 0);
        $paymentMethod = $current['payment_method'] ?? null;

        if ($newStatus === 'Confirmed') {
            $deposit = (float)($data['deposit_amount'] ?? $deposit);
            $pm = (string)($data['payment_method'] ?? '');
            if ($pm !== '') {
                if (!Validator::inArray($pm, self::allowedPaymentMethods())) {
                    $errors['payment_method'] = 'Payment method is invalid.';
                    return false;
                }
                $paymentMethod = $pm;
            }
            if ($deposit <= 0) {
                $errors['deposit_amount'] = 'Deposit is required to confirm the reservation.';
                return false;
            }
        }

        $ok = $this->repo->updateReservationStatus($reservationId, $newStatus, $deposit, $paymentMethod);
        if (!$ok) {
            return false;
        }

        if ($newStatus === 'Checked In') {
            $this->autoSetRoomOccupiedOnCheckin($reservationId);
        }

        if ($newStatus === 'Cancelled' || $newStatus === 'No Show') {
            $this->autoReleaseRoomOnCancelOrNoShow($reservationId, $currentStatus);
        }

        if ($newStatus === 'Completed') {
            $this->autoCreateHousekeepingOnCheckout($reservationId);
            $this->autoCreateMaintenanceOnCheckout($reservationId);
        }

        return true;
    }

    private function autoCreateHousekeepingOnCheckout(int $reservationId): void
    {
        if (!$this->housekeepingRepo || !$this->roomRepo) {
            return;
        }

        $reservation = $this->repo->findReservationDetails($reservationId);
        if (!$reservation) {
            return;
        }

        $roomId = (int)($reservation['room_id'] ?? 0);
        if ($roomId <= 0) {
            return;
        }

        $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ref = (string)($reservation['reference_no'] ?? '');

        $this->housekeepingRepo->createTask([
            'room_id' => $roomId,
            'task_type' => 'Cleaning',
            'status' => 'Open',
            'priority' => 'Normal',
            'assigned_to' => null,
            'created_by' => $createdBy,
            'notes' => $ref !== '' ? ('Auto-created on checkout (' . $ref . ').') : 'Auto-created on checkout.',
        ]);

        $this->roomRepo->updateStatus($roomId, 'Cleaning');
    }

    private function autoSetRoomOccupiedOnCheckin(int $reservationId): void
    {
        if (!$this->roomRepo) {
            return;
        }

        $reservation = $this->repo->findReservationDetails($reservationId);
        if (!$reservation) {
            return;
        }

        $roomId = (int)($reservation['room_id'] ?? 0);
        if ($roomId <= 0) {
            return;
        }

        $this->roomRepo->updateStatus($roomId, 'Occupied');
    }

    private function autoReleaseRoomOnCancelOrNoShow(int $reservationId, string $previousReservationStatus): void
    {
        if (!$this->roomRepo) {
            return;
        }

        if (!in_array($previousReservationStatus, ['Pending', 'Confirmed', 'Upcoming'], true)) {
            return;
        }

        $reservation = $this->repo->findReservationDetails($reservationId);
        if (!$reservation) {
            return;
        }

        $roomId = (int)($reservation['room_id'] ?? 0);
        if ($roomId <= 0) {
            return;
        }

        $this->roomRepo->updateStatus($roomId, 'Vacant');
    }

    private function autoCreateMaintenanceOnCheckout(int $reservationId): void
    {
        if (!$this->maintenanceService) {
            return;
        }

        $reservation = $this->repo->findReservationDetails($reservationId);
        if (!$reservation) {
            return;
        }

        $roomId = (int)($reservation['room_id'] ?? 0);
        if ($roomId <= 0) {
            return;
        }

        $ref = (string)($reservation['reference_no'] ?? '');
        $guestName = trim((string)($reservation['first_name'] ?? '') . ' ' . (string)($reservation['last_name'] ?? ''));

        $errors = [];
        $this->maintenanceService->createTicket([
            'room_id' => $roomId,
            'asset_id' => 0,
            'category_id' => 0,
            'priority' => 'Normal',
            'title' => 'Post-checkout Inspection',
            'description' => $ref !== ''
                ? ('Auto-created on checkout for reservation ' . $ref . ($guestName !== '' ? (' (' . $guestName . ')') : '') . '.')
                : 'Auto-created on checkout.',
            'assigned_to' => 0,
            'vendor_id' => 0,
            'requires_downtime' => 0,
        ], $errors);
    }

    private function allowedTransitions(string $currentStatus): array
    {
        switch ($currentStatus) {
            case 'Pending':
                return ['Confirmed', 'Cancelled'];
            case 'Confirmed':
            case 'Upcoming':
                return ['Checked In', 'Cancelled', 'No Show'];
            case 'Checked In':
                return ['Completed'];
            default:
                return [];
        }
    }

    public function validateCreate(array $data): array
    {
        $errors = [];

        if (!Validator::required($data['checkin_date'] ?? null)) {
            $errors['checkin_date'] = 'Check-in date is required.';
        }
        if (!Validator::required($data['checkout_date'] ?? null)) {
            $errors['checkout_date'] = 'Check-out date is required.';
        }

        $checkin = (string)($data['checkin_date'] ?? '');
        $checkout = (string)($data['checkout_date'] ?? '');
        if ($checkin !== '' && $checkout !== '' && strtotime($checkout) <= strtotime($checkin)) {
            $errors['checkout_date'] = 'Check-out must be after check-in.';
        }

        if (!Validator::inArray($data['source'] ?? 'Walk-in', self::allowedSources())) {
            $errors['source'] = 'Source is invalid.';
        }

        if (!Validator::required($data['room_id'] ?? null) || (int)$data['room_id'] <= 0) {
            $errors['room_id'] = 'Please select an available room.';
        }

        $deposit = (float)($data['deposit_amount'] ?? 0);
        if ($deposit <= 0) {
            $errors['deposit_amount'] = 'Deposit is required to confirm the reservation.';
        }

        $pm = (string)($data['payment_method'] ?? '');
        if ($pm !== '' && !Validator::inArray($pm, self::allowedPaymentMethods())) {
            $errors['payment_method'] = 'Payment method is invalid.';
        }

        if (!Validator::required($data['guest_id'] ?? null) || (int)$data['guest_id'] <= 0) {
            $errors['guest_id'] = 'Please select a guest.';
        }

        return $errors;
    }

    public function createConfirmedOneRoom(array $data, array &$errors): int
    {
        $errors = $this->validateCreate($data);
        if (!empty($errors)) {
            return 0;
        }

        $checkin = (string)$data['checkin_date'];
        $checkout = (string)$data['checkout_date'];
        $roomId = (int)$data['room_id'];

        $available = $this->repo->findAvailableRooms($checkin, $checkout, 0);
        $selectedRoom = null;
        foreach ($available as $r) {
            if ((int)$r['id'] === $roomId) {
                $selectedRoom = $r;
                break;
            }
        }
        if (!$selectedRoom) {
            $errors['room_id'] = 'Selected room is no longer available.';
            return 0;
        }

        $reservationId = $this->repo->createReservation([
            'reference_no' => $this->generateReferenceNo(),
            'guest_id' => (int)$data['guest_id'],
            'source' => (string)($data['source'] ?? 'Walk-in'),
            'status' => 'Confirmed',
            'checkin_date' => $checkin,
            'checkout_date' => $checkout,
            'deposit_amount' => (float)$data['deposit_amount'],
            'payment_method' => (string)($data['payment_method'] ?? ''),
            'notes' => (string)($data['notes'] ?? ''),
        ]);

        if ($reservationId <= 0) {
            $errors['general'] = 'Failed to create reservation.';
            return 0;
        }

        $rate = (float)($selectedRoom['base_rate'] ?? 0);
        if (isset($data['rate']) && is_numeric($data['rate'])) {
            $rate = (float)$data['rate'];
        }
        $okAttach = $this->repo->attachRoomToReservation($reservationId, [
            'room_id' => $roomId,
            'room_type_id' => (int)($selectedRoom['room_type_id'] ?? 0),
            'rate' => $rate,
            'adults' => (int)($data['adults'] ?? 1),
            'children' => (int)($data['children'] ?? 0),
        ]);

        if (!$okAttach) {
            $errors['general'] = 'Reservation created but room assignment failed.';
            return 0;
        }

        return $reservationId;
    }

    private function generateReferenceNo(): string
    {
        $datePart = date('Ymd');
        $rand = str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        return 'RSV-' . $datePart . '-' . $rand;
    }
}
