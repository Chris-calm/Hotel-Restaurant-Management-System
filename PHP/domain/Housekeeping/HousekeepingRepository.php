<?php

require_once __DIR__ . '/../../core/Database.php';

final class HousekeepingRepository
{
    private ?mysqli $conn;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function listRooms(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            $sql = "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
                           rt.code AS room_type_code, rt.name AS room_type_name
                    FROM rooms r
                    JOIN room_types rt ON rt.id = r.room_type_id
                    ORDER BY r.room_no ASC LIMIT 500";
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
            "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
                    rt.code AS room_type_code, rt.name AS room_type_name
             FROM rooms r
             JOIN room_types rt ON rt.id = r.room_type_id
             WHERE r.room_no LIKE ? OR r.floor LIKE ? OR rt.code LIKE ? OR rt.name LIKE ? OR r.status LIKE ?
             ORDER BY r.room_no ASC LIMIT 500"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function listOpenTasks(int $limit = 200): array
    {
        if (!$this->conn) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        $sql =
            "SELECT t.id, t.room_id, t.task_type, t.status, t.priority, t.assigned_to,
                    t.started_at, t.completed_at, t.notes, t.created_at,
                    rooms.room_no,
                    rt.name AS room_type_name
             FROM housekeeping_tasks t
             INNER JOIN rooms ON rooms.id = t.room_id
             INNER JOIN room_types rt ON rt.id = rooms.room_type_id
             WHERE t.status IN ('Open','In Progress')
             ORDER BY t.created_at DESC
             LIMIT $limit";

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

    public function createTask(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO housekeeping_tasks (room_id, task_type, status, priority, assigned_to, created_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return 0;
        }

        $roomId = (int)($data['room_id'] ?? 0);
        $taskType = (string)($data['task_type'] ?? 'Cleaning');
        $status = (string)($data['status'] ?? 'Open');
        $priority = (string)($data['priority'] ?? 'Normal');
        $assignedTo = $data['assigned_to'] !== null ? (int)$data['assigned_to'] : null;
        $createdBy = $data['created_by'] !== null ? (int)$data['created_by'] : null;
        $notes = (string)($data['notes'] ?? '');

        $stmt->bind_param('isssiis', $roomId, $taskType, $status, $priority, $assignedTo, $createdBy, $notes);
        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function updateTaskStatus(int $taskId, string $status): bool
    {
        if (!$this->conn) {
            return false;
        }

        $startedAt = null;
        $completedAt = null;

        if ($status === 'In Progress') {
            $startedAt = date('Y-m-d H:i:s');
        }
        if ($status === 'Done') {
            $completedAt = date('Y-m-d H:i:s');
        }

        $stmt = $this->conn->prepare(
            "UPDATE housekeeping_tasks
             SET status = ?,
                 started_at = COALESCE(started_at, ?),
                 completed_at = CASE WHEN ? IS NULL THEN completed_at ELSE ? END
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssssi', $status, $startedAt, $completedAt, $completedAt, $taskId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function findTaskById(int $taskId): ?array
    {
        if (!$this->conn) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT t.id, t.room_id, t.task_type, t.status, t.priority, t.assigned_to,
                    t.started_at, t.completed_at, t.notes, t.created_at,
                    rooms.room_no, rooms.status AS room_status,
                    rt.name AS room_type_name
             FROM housekeeping_tasks t
             INNER JOIN rooms ON rooms.id = t.room_id
             INNER JOIN room_types rt ON rt.id = rooms.room_type_id
             WHERE t.id = ?"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $taskId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
