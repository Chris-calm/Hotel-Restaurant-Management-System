<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/../Rooms/RoomRepository.php';
require_once __DIR__ . '/MaintenanceRepository.php';

final class MaintenanceService
{
    private MaintenanceRepository $repo;
    private RoomRepository $roomRepo;

    public function __construct(MaintenanceRepository $repo, RoomRepository $roomRepo)
    {
        $this->repo = $repo;
        $this->roomRepo = $roomRepo;
    }

    public static function allowedPriorities(): array
    {
        return ['Low', 'Normal', 'High', 'Urgent'];
    }

    public static function allowedStatuses(): array
    {
        return ['Open', 'Assigned', 'In Progress', 'On Hold', 'Resolved', 'Closed', 'Cancelled'];
    }

    public static function allowedCostTypes(): array
    {
        return ['Labor', 'Part', 'Vendor', 'Other'];
    }

    public function listTickets(array $filters = []): array
    {
        return $this->repo->listTickets($filters);
    }

    public function listRooms(string $q = ''): array
    {
        return $this->repo->listRooms($q);
    }

    public function listAssets(string $q = ''): array
    {
        return $this->repo->listAssets($q);
    }

    public function listCategories(): array
    {
        return $this->repo->listCategories();
    }

    public function listVendors(): array
    {
        return $this->repo->listVendors();
    }

    public function listUsers(): array
    {
        return $this->repo->listUsers();
    }

    public function createTicket(array $data, array &$errors): int
    {
        $errors = [];

        $roomId = (int)($data['room_id'] ?? 0);
        $assetId = (int)($data['asset_id'] ?? 0);

        if ($roomId <= 0 && $assetId <= 0) {
            $errors['target'] = 'Select a room or an asset.';
        }

        $priority = (string)($data['priority'] ?? 'Normal');
        if (!Validator::inArray($priority, self::allowedPriorities())) {
            $errors['priority'] = 'Priority is invalid.';
        }

        $title = trim((string)($data['title'] ?? ''));
        if (!Validator::required($title)) {
            $errors['title'] = 'Title is required.';
        }

        $requiresDowntime = !empty($data['requires_downtime']);

        if (!empty($errors)) {
            return 0;
        }

        $ticketNo = $this->generateTicketNo();
        $reportedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $roomOutOfOrderFrom = null;
        if ($requiresDowntime && $roomId > 0) {
            $roomOutOfOrderFrom = date('Y-m-d H:i:s');
        }

        $id = $this->repo->createTicket([
            'ticket_no' => $ticketNo,
            'room_id' => $roomId > 0 ? $roomId : null,
            'asset_id' => $assetId > 0 ? $assetId : null,
            'category_id' => ((int)($data['category_id'] ?? 0)) ?: null,
            'priority' => $priority,
            'status' => 'Open',
            'title' => $title,
            'description' => (string)($data['description'] ?? ''),
            'reported_by' => $reportedBy,
            'assigned_to' => ((int)($data['assigned_to'] ?? 0)) ?: null,
            'vendor_id' => ((int)($data['vendor_id'] ?? 0)) ?: null,
            'requires_downtime' => $requiresDowntime ? 1 : 0,
            'room_out_of_order_from' => $roomOutOfOrderFrom,
        ]);

        if ($id > 0 && $requiresDowntime && $roomId > 0) {
            $room = $this->roomRepo->findById($roomId);
            $oldStatus = (string)($room['status'] ?? '');

            $this->roomRepo->updateStatus($roomId, 'Out of Order');
            $this->repo->addRoomStatusHistory([
                'room_id' => $roomId,
                'old_status' => $oldStatus,
                'new_status' => 'Out of Order',
                'source' => 'Maintenance',
                'source_id' => $id,
                'changed_by' => $reportedBy,
            ]);

            $this->repo->createLog([
                'ticket_id' => $id,
                'work_order_id' => null,
                'log_type' => 'Downtime',
                'message' => 'Room set to Out of Order.',
                'created_by' => $reportedBy,
            ]);
        }

        return $id;
    }

    public function updateTicketStatus(int $ticketId, string $status, array &$errors): bool
    {
        $errors = [];

        if (!Validator::inArray($status, self::allowedStatuses())) {
            $errors['status'] = 'Status is invalid.';
            return false;
        }

        $ticket = $this->repo->findTicketById($ticketId);
        if (!$ticket) {
            $errors['general'] = 'Ticket not found.';
            return false;
        }

        $data = [
            'status' => $status,
            'assigned_to' => $ticket['assigned_to'] ?? null,
            'vendor_id' => $ticket['vendor_id'] ?? null,
            'requires_downtime' => (int)($ticket['requires_downtime'] ?? 0),
            'room_out_of_order_from' => $ticket['room_out_of_order_from'] ?? null,
            'room_out_of_order_to' => $ticket['room_out_of_order_to'] ?? null,
            'resolved_at' => $ticket['resolved_at'] ?? null,
            'closed_at' => $ticket['closed_at'] ?? null,
        ];

        if ($status === 'Resolved' && empty($data['resolved_at'])) {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }

        if ($status === 'Closed' && empty($data['closed_at'])) {
            $data['closed_at'] = date('Y-m-d H:i:s');
        }

        if ($status === 'Closed' && (int)($ticket['requires_downtime'] ?? 0) === 1 && empty($data['room_out_of_order_to'])) {
            $data['room_out_of_order_to'] = date('Y-m-d H:i:s');
        }

        $ok = $this->repo->updateTicket($ticketId, $data);
        if (!$ok) {
            $errors['general'] = 'Failed to update ticket.';
            return false;
        }

        $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->createLog([
            'ticket_id' => $ticketId,
            'work_order_id' => null,
            'log_type' => 'Status Change',
            'message' => 'Status updated to ' . $status . '.',
            'created_by' => $actorId,
        ]);

        if ($status === 'Closed' && (int)($ticket['requires_downtime'] ?? 0) === 1) {
            $roomId = (int)($ticket['room_id'] ?? 0);
            if ($roomId > 0) {
                $room = $this->roomRepo->findById($roomId);
                $oldStatus = (string)($room['status'] ?? '');

                $this->roomRepo->updateStatus($roomId, 'Cleaning');
                $this->repo->addRoomStatusHistory([
                    'room_id' => $roomId,
                    'old_status' => $oldStatus,
                    'new_status' => 'Cleaning',
                    'source' => 'Maintenance',
                    'source_id' => $ticketId,
                    'changed_by' => $actorId,
                ]);

                $this->repo->createLog([
                    'ticket_id' => $ticketId,
                    'work_order_id' => null,
                    'log_type' => 'Downtime',
                    'message' => 'Room released from Out of Order and set to Cleaning.',
                    'created_by' => $actorId,
                ]);
            }
        }

        return true;
    }

    public function updateAssignment(int $ticketId, ?int $assignedTo, ?int $vendorId, array &$errors): bool
    {
        $errors = [];

        $ticket = $this->repo->findTicketById($ticketId);
        if (!$ticket) {
            $errors['general'] = 'Ticket not found.';
            return false;
        }

        $ok = $this->repo->updateTicket($ticketId, [
            'status' => (string)($ticket['status'] ?? 'Open'),
            'assigned_to' => $assignedTo,
            'vendor_id' => $vendorId,
            'requires_downtime' => (int)($ticket['requires_downtime'] ?? 0),
            'room_out_of_order_from' => $ticket['room_out_of_order_from'] ?? null,
            'room_out_of_order_to' => $ticket['room_out_of_order_to'] ?? null,
            'resolved_at' => $ticket['resolved_at'] ?? null,
            'closed_at' => $ticket['closed_at'] ?? null,
        ]);

        if (!$ok) {
            $errors['general'] = 'Failed to update assignment.';
            return false;
        }

        $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->createLog([
            'ticket_id' => $ticketId,
            'work_order_id' => null,
            'log_type' => 'Assignment',
            'message' => 'Assignment updated.',
            'created_by' => $actorId,
        ]);

        return true;
    }

    public function addLog(int $ticketId, string $message, array &$errors): int
    {
        $errors = [];

        $message = trim($message);
        if (!Validator::required($message)) {
            $errors['message'] = 'Log message is required.';
            return 0;
        }

        $ticket = $this->repo->findTicketById($ticketId);
        if (!$ticket) {
            $errors['general'] = 'Ticket not found.';
            return 0;
        }

        $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        return $this->repo->createLog([
            'ticket_id' => $ticketId,
            'work_order_id' => null,
            'log_type' => 'Note',
            'message' => $message,
            'created_by' => $actorId,
        ]);
    }

    public function addCost(array $data, array &$errors): int
    {
        $errors = [];

        $ticketId = (int)($data['ticket_id'] ?? 0);
        if ($ticketId <= 0) {
            $errors['ticket_id'] = 'Ticket is required.';
        }

        $costType = (string)($data['cost_type'] ?? 'Other');
        if (!Validator::inArray($costType, self::allowedCostTypes())) {
            $errors['cost_type'] = 'Cost type is invalid.';
        }

        $description = trim((string)($data['description'] ?? ''));
        if (!Validator::required($description)) {
            $errors['description'] = 'Cost description is required.';
        }

        $qty = (float)($data['qty'] ?? 1);
        $unitCost = (float)($data['unit_cost'] ?? 0);
        if ($qty <= 0) {
            $errors['qty'] = 'Qty must be greater than 0.';
        }
        if ($unitCost < 0) {
            $errors['unit_cost'] = 'Unit cost must be 0 or greater.';
        }

        if (!empty($errors)) {
            return 0;
        }

        $actorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $totalCost = $qty * $unitCost;

        return $this->repo->createCost([
            'ticket_id' => $ticketId,
            'work_order_id' => null,
            'cost_type' => $costType,
            'description' => $description,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'reference_no' => (string)($data['reference_no'] ?? ''),
            'created_by' => $actorId,
        ]);
    }

    public function createWorkOrder(array $data, array &$errors): int
    {
        $errors = [];

        $ticketId = (int)($data['ticket_id'] ?? 0);
        if ($ticketId <= 0) {
            $errors['ticket_id'] = 'Ticket is required.';
        }

        if (!empty($errors)) {
            return 0;
        }

        $workOrderNo = $this->generateWorkOrderNo();

        return $this->repo->createWorkOrder([
            'work_order_no' => $workOrderNo,
            'ticket_id' => $ticketId,
            'assigned_to' => ((int)($data['assigned_to'] ?? 0)) ?: null,
            'vendor_id' => ((int)($data['vendor_id'] ?? 0)) ?: null,
            'scheduled_at' => !empty($data['scheduled_at']) ? (string)$data['scheduled_at'] : null,
            'status' => 'Planned',
            'notes' => (string)($data['notes'] ?? ''),
        ]);
    }

    private function generateTicketNo(): string
    {
        $date = date('Ymd');
        $rand = random_int(1000, 9999);
        return 'MT-' . $date . '-' . $rand;
    }

    private function generateWorkOrderNo(): string
    {
        $date = date('Ymd');
        $rand = random_int(1000, 9999);
        return 'WO-' . $date . '-' . $rand;
    }
}
