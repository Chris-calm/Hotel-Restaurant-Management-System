<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/ReservationRepository.php';
require_once __DIR__ . '/../Housekeeping/HousekeepingRepository.php';
require_once __DIR__ . '/../Rooms/RoomRepository.php';
require_once __DIR__ . '/../Maintenance/MaintenanceService.php';
require_once __DIR__ . '/../Maintenance/MaintenanceRepository.php';
require_once __DIR__ . '/../Notifications/NotificationRepository.php';

final class ReservationService
{
    private ReservationRepository $repo;
    private ?HousekeepingRepository $housekeepingRepo;
    private ?RoomRepository $roomRepo;
    private ?MaintenanceService $maintenanceService;
    private ?NotificationRepository $notifRepo;

    public function __construct(
        ReservationRepository $repo,
        ?HousekeepingRepository $housekeepingRepo = null,
        ?RoomRepository $roomRepo = null,
        ?MaintenanceService $maintenanceService = null,
        ?NotificationRepository $notifRepo = null
    )
    {
        $this->repo = $repo;
        $this->housekeepingRepo = $housekeepingRepo;
        $this->roomRepo = $roomRepo;
        $this->maintenanceService = $maintenanceService;
        $this->notifRepo = $notifRepo;
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

    public function listPendingOnlineReservations(int $limit = 50): array
    {
        return $this->repo->listPendingOnlineReservations($limit);
    }

    public function listPromoCodes(): array
    {
        return $this->repo->listPromoCodes();
    }

    public function createPromoCode(array $data, array &$errors): int
    {
        $errors = [];

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
            $errors['code'] = 'Promo code must be 3-30 chars (A-Z, 0-9, _ or -).';
        }

        $type = (string)($data['discount_type'] ?? 'Percent');
        if (!Validator::inArray($type, ['Percent', 'Fixed'])) {
            $errors['discount_type'] = 'Discount type is invalid.';
        }

        $valRaw = (string)($data['discount_value'] ?? '');
        if (!is_numeric($valRaw)) {
            $errors['discount_value'] = 'Discount value must be a number.';
        } else {
            $val = (float)$valRaw;
            if ($val <= 0) {
                $errors['discount_value'] = 'Discount value must be greater than 0.';
            }
            if ($type === 'Percent' && $val > 90) {
                $errors['discount_value'] = 'Percent discount is too high.';
            }
        }

        $start = trim((string)($data['start_date'] ?? ''));
        $end = trim((string)($data['end_date'] ?? ''));
        if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $errors['start_date'] = 'Start date is invalid.';
        }
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $errors['end_date'] = 'End date is invalid.';
        }
        if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
            $errors['end_date'] = 'End date must be after start date.';
        }

        $maxUsesRaw = trim((string)($data['max_uses'] ?? ''));
        if ($maxUsesRaw !== '' && !ctype_digit($maxUsesRaw)) {
            $errors['max_uses'] = 'Max uses must be a whole number.';
        }

        if (!empty($errors)) {
            return 0;
        }

        return $this->repo->createPromoCode([
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => (float)$valRaw,
            'start_date' => $start,
            'end_date' => $end,
            'max_uses' => $maxUsesRaw !== '' ? (int)$maxUsesRaw : 0,
            'is_active' => (int)($data['is_active'] ?? 1),
            'notes' => (string)($data['notes'] ?? ''),
        ]);
    }

    public function setPromoCodeActive(int $promoId, bool $active, array &$errors): bool
    {
        $errors = [];
        if ($promoId <= 0) {
            $errors['promo_id'] = 'Invalid promo id.';
            return false;
        }
        return $this->repo->setPromoCodeActive($promoId, $active);
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

            $this->applyLoyaltyTierDiscountOnConfirm($current);
        }

        $ok = $this->repo->updateReservationStatus($reservationId, $newStatus, $deposit, $paymentMethod);
        if (!$ok) {
            return false;
        }

        if ($this->notifRepo && in_array($newStatus, ['Confirmed', 'Checked In', 'Completed', 'Cancelled', 'No Show'], true)) {
            try {
                $ref = trim((string)($current['reference_no'] ?? ''));
                $roomNo = trim((string)($current['room_no'] ?? ''));
                $guestName = trim((string)($current['first_name'] ?? '') . ' ' . (string)($current['last_name'] ?? ''));

                $title = 'Reservation ' . $newStatus;
                $msgParts = [];
                if ($ref !== '') {
                    $msgParts[] = $ref;
                }
                if ($guestName !== '') {
                    $msgParts[] = $guestName;
                }
                if ($roomNo !== '') {
                    $msgParts[] = 'Room ' . $roomNo;
                }
                $msg = implode(' • ', $msgParts);
                if ($msg === '') {
                    $msg = 'Reservation updated to ' . $newStatus . '.';
                } else {
                    $msg .= ' → ' . $newStatus . '.';
                }

                $url = '/PHP/modules/reservations_view.php?id=' . (int)$reservationId;
                $this->notifRepo->createForStaff($title, $msg, $url);
            } catch (Throwable $e) {
            }
        }

        if ($newStatus === 'Checked In') {
            $this->autoSetRoomOccupiedOnCheckin($reservationId);
        }

        if ($newStatus === 'Cancelled' || $newStatus === 'No Show') {
            $this->autoReleaseRoomOnCancelOrNoShow($reservationId, $currentStatus);
        }

        if ($newStatus === 'Completed') {
            $this->autoCreateHousekeepingOnCheckout($reservationId);
        }

        return true;
    }

    private function applyLoyaltyTierDiscountOnConfirm(array $reservation): void
    {
        $guestId = (int)($reservation['guest_id'] ?? 0);
        if ($guestId <= 0) {
            return;
        }

        $promoCodeId = (int)($reservation['promo_code_id'] ?? 0);
        $promoCode = trim((string)($reservation['promo_code'] ?? ''));
        $existingDiscount = (float)($reservation['discount_amount'] ?? 0);
        if ($promoCodeId > 0 || $promoCode !== '' || $existingDiscount > 0) {
            return;
        }

        $loyalty = $this->repo->getGuestLoyalty($guestId);
        if (!$loyalty) {
            return;
        }
        $tier = trim((string)($loyalty['loyalty_tier'] ?? ''));

        $pct = 0.0;
        if ($tier === 'Silver') {
            $pct = 0.05;
        } elseif ($tier === 'Gold') {
            $pct = 0.08;
        } elseif ($tier === 'Platinum') {
            $pct = 0.12;
        }
        if ($pct <= 0) {
            return;
        }

        $checkin = (string)($reservation['checkin_date'] ?? '');
        $checkout = (string)($reservation['checkout_date'] ?? '');
        $rate = (float)($reservation['rate'] ?? 0);
        if ($checkin === '' || $checkout === '' || $rate <= 0) {
            return;
        }

        $nights = 0;
        $n1 = strtotime($checkin);
        $n2 = strtotime($checkout);
        if ($n1 !== false && $n2 !== false && $n2 > $n1) {
            $nights = (int)round(($n2 - $n1) / 86400);
        }
        if ($nights <= 0) {
            return;
        }

        $subtotal = $nights * $rate;
        $discountAmount = $subtotal * $pct;
        if ($discountAmount <= 0) {
            return;
        }

        $this->repo->updateReservationDiscountAmount((int)($reservation['id'] ?? 0), $discountAmount);
    }

    private function awardLoyaltyPointsOnCompletion(array $reservation): void
    {
        $guestId = (int)($reservation['guest_id'] ?? 0);
        if ($guestId <= 0) {
            return;
        }

        $checkin = (string)($reservation['checkin_date'] ?? '');
        $checkout = (string)($reservation['checkout_date'] ?? '');
        $rate = (float)($reservation['rate'] ?? 0);
        if ($checkin === '' || $checkout === '' || $rate <= 0) {
            return;
        }

        $nights = 0;
        $n1 = strtotime($checkin);
        $n2 = strtotime($checkout);
        if ($n1 !== false && $n2 !== false && $n2 > $n1) {
            $nights = (int)round(($n2 - $n1) / 86400);
        }
        if ($nights <= 0) {
            return;
        }

        $subtotal = $nights * $rate;
        $discount = (float)($reservation['discount_amount'] ?? 0);
        $net = max(0.0, $subtotal - max(0.0, $discount));
        $earned = (int)floor($net / 100);
        if ($earned <= 0) {
            return;
        }

        $current = $this->repo->getGuestLoyalty($guestId);
        $existingPoints = $current && is_numeric((string)($current['loyalty_points'] ?? ''))
            ? (int)$current['loyalty_points']
            : 0;

        $newPoints = $existingPoints + $earned;
        $tier = $this->loyaltyTierForPoints($newPoints);
        $this->repo->updateGuestLoyalty($guestId, $newPoints, $tier);
    }

    private function loyaltyTierForPoints(int $points): ?string
    {
        if ($points >= 3000) {
            return 'Platinum';
        }
        if ($points >= 1500) {
            return 'Gold';
        }
        if ($points >= 500) {
            return 'Silver';
        }
        return null;
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

        $rate = (float)($selectedRoom['base_rate'] ?? 0);
        if (isset($data['rate']) && is_numeric($data['rate'])) {
            $rate = (float)$data['rate'];
        }

        $nights = 0;
        $n1 = strtotime($checkin);
        $n2 = strtotime($checkout);
        if ($n1 !== false && $n2 !== false && $n2 > $n1) {
            $nights = (int)round(($n2 - $n1) / 86400);
        }
        $staySubtotal = $nights * $rate;

        $promoCodeInput = strtoupper(trim((string)($data['promo_code'] ?? '')));
        $promoId = 0;
        $promoCodeUsed = '';
        $discountAmount = 0.0;
        if ($promoCodeInput !== '') {
            $promo = $this->repo->findActivePromoByCode($promoCodeInput, $checkin);
            if (!$promo) {
                $errors['promo_code'] = 'Promo code is invalid or inactive.';
                return 0;
            }

            $promoId = (int)($promo['id'] ?? 0);
            $promoCodeUsed = (string)($promo['code'] ?? $promoCodeInput);
            $type = (string)($promo['discount_type'] ?? 'Percent');
            $val = (float)($promo['discount_value'] ?? 0);
            if ($type === 'Percent') {
                $discountAmount = $staySubtotal * ($val / 100);
            } else {
                $discountAmount = $val;
            }
            $discountAmount = max(0.0, min($staySubtotal, $discountAmount));
        }

        $reservationId = $this->repo->createReservation([
            'reference_no' => $this->generateReferenceNo(),
            'guest_id' => (int)$data['guest_id'],
            'source' => (string)($data['source'] ?? 'Walk-in'),
            'status' => 'Confirmed',
            'checkin_date' => $checkin,
            'checkout_date' => $checkout,
            'promo_code_id' => $promoId,
            'promo_code' => $promoCodeUsed,
            'discount_amount' => $discountAmount,
            'deposit_amount' => (float)$data['deposit_amount'],
            'payment_method' => (string)($data['payment_method'] ?? ''),
            'notes' => (string)($data['notes'] ?? ''),
        ]);

        if ($reservationId <= 0) {
            $errors['general'] = 'Failed to create reservation.';
            return 0;
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

        if ($promoId > 0) {
            $this->repo->incrementPromoUsedCount($promoId);
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
