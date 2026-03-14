<?php

require_once __DIR__ . '/../../core/Validator.php';
require_once __DIR__ . '/../Rooms/RoomRepository.php';
require_once __DIR__ . '/HousekeepingRepository.php';

final class HousekeepingService
{
    private HousekeepingRepository $repo;
    private RoomRepository $roomRepo;

    public function __construct(HousekeepingRepository $repo, RoomRepository $roomRepo)
    {
        $this->repo = $repo;
        $this->roomRepo = $roomRepo;
    }

    public static function allowedTaskTypes(): array
    {
        return ['Cleaning', 'Inspection'];
    }

    public static function allowedTaskStatuses(): array
    {
        return ['Open', 'In Progress', 'Done'];
    }

    public static function allowedPriorities(): array
    {
        return ['Low', 'Normal', 'High'];
    }

    public function listRooms(string $q = ''): array
    {
        return $this->repo->listRooms($q);
    }

    public function listOpenTasks(): array
    {
        return $this->repo->listOpenTasks();
    }

    public function createCleaningTask(array $data, array &$errors): int
    {
        $errors = [];

        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) {
            $errors['room_id'] = 'Please select a room.';
        }

        $taskType = (string)($data['task_type'] ?? 'Cleaning');
        if (!Validator::inArray($taskType, self::allowedTaskTypes())) {
            $errors['task_type'] = 'Task type is invalid.';
        }

        $priority = (string)($data['priority'] ?? 'Normal');
        if (!Validator::inArray($priority, self::allowedPriorities())) {
            $errors['priority'] = 'Priority is invalid.';
        }

        if (!empty($errors)) {
            return 0;
        }

        $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $id = $this->repo->createTask([
            'room_id' => $roomId,
            'function_room_id' => null,
            'task_type' => $taskType,
            'status' => 'In Progress',
            'priority' => $priority,
            'assigned_to' => null,
            'created_by' => $createdBy,
            'scheduled_from' => null,
            'scheduled_to' => null,
            'source_type' => null,
            'source_id' => null,
            'notes' => (string)($data['notes'] ?? ''),
        ]);

        if ($id > 0) {
            $this->roomRepo->updateStatus($roomId, 'Cleaning');
        }

        return $id;
    }

    public function createFunctionRoomCleanupTask(array $data, array &$errors): int
    {
        $errors = [];

        $functionRoomId = (int)($data['function_room_id'] ?? 0);
        if ($functionRoomId <= 0) {
            $errors['function_room_id'] = 'Please select a function room.';
        }

        $taskType = (string)($data['task_type'] ?? 'Cleaning');
        if (!Validator::inArray($taskType, self::allowedTaskTypes())) {
            $errors['task_type'] = 'Task type is invalid.';
        }

        $priority = (string)($data['priority'] ?? 'Normal');
        if (!Validator::inArray($priority, self::allowedPriorities())) {
            $errors['priority'] = 'Priority is invalid.';
        }

        if (!empty($errors)) {
            return 0;
        }

        $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        return $this->repo->createTask([
            'room_id' => null,
            'function_room_id' => $functionRoomId,
            'task_type' => $taskType,
            'status' => (string)($data['status'] ?? 'In Progress'),
            'priority' => $priority,
            'assigned_to' => null,
            'created_by' => $createdBy,
            'scheduled_from' => $data['scheduled_from'] ?? null,
            'scheduled_to' => $data['scheduled_to'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'notes' => (string)($data['notes'] ?? ''),
        ]);
    }

    public function setTaskStatus(int $taskId, string $status, array &$errors): bool
    {
        $errors = [];

        if (!Validator::inArray($status, self::allowedTaskStatuses())) {
            $errors['status'] = 'Status is invalid.';
            return false;
        }

        $task = $this->repo->findTaskById($taskId);
        if (!$task) {
            $errors['general'] = 'Task not found.';
            return false;
        }

        $ok = $this->repo->updateTaskStatus($taskId, $status);
        if (!$ok) {
            $errors['general'] = 'Failed to update task.';
            return false;
        }

        if ($status === 'Done') {
            $roomId = (int)($task['room_id'] ?? 0);
            if ($roomId > 0) {
                $this->roomRepo->updateStatus($roomId, 'Vacant');
            }
        }

        return true;
    }
}
