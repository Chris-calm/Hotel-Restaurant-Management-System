<?php

require_once __DIR__ . '/../../core/Database.php';

final class HousekeepingRepository
{
    private ?mysqli $conn;
    private ?bool $hasRoomImageColumn = null;
    private ?bool $hasRoomTypeImageColumn = null;
    private ?bool $hasFunctionRoomColumns = null;
    private ?bool $hasFunctionRoomImageColumn = null;
    private ?bool $hasTaskTimingColumns = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasTaskTimingColumns(): bool
    {
        if ($this->hasTaskTimingColumns !== null) {
            return $this->hasTaskTimingColumns;
        }
        if (!$this->conn) {
            $this->hasTaskTimingColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasTaskTimingColumns = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'housekeeping_tasks'
               AND COLUMN_NAME IN ('started_at','completed_at')"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasTaskTimingColumns = ($count === 2);
        return $this->hasTaskTimingColumns;
    }

    private function hasFunctionRoomImageColumn(): bool
    {
        if ($this->hasFunctionRoomImageColumn !== null) {
            return $this->hasFunctionRoomImageColumn;
        }
        if (!$this->conn) {
            $this->hasFunctionRoomImageColumn = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasFunctionRoomImageColumn = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'function_rooms'
               AND COLUMN_NAME = 'image_path'"
        );
        $this->hasFunctionRoomImageColumn = $res ? ((int)($res->fetch_row()[0] ?? 0) === 1) : false;
        return $this->hasFunctionRoomImageColumn;
    }

    private function hasFunctionRoomColumns(): bool
    {
        if ($this->hasFunctionRoomColumns !== null) {
            return $this->hasFunctionRoomColumns;
        }
        if (!$this->conn) {
            $this->hasFunctionRoomColumns = false;
            return false;
        }

        $dbRow = $this->conn->query('SELECT DATABASE()');
        $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
        $db = $this->conn->real_escape_string($db);
        if ($db === '') {
            $this->hasFunctionRoomColumns = false;
            return false;
        }

        $res = $this->conn->query(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '{$db}'
               AND TABLE_NAME = 'housekeeping_tasks'
               AND COLUMN_NAME IN ('function_room_id','scheduled_from','scheduled_to','source_type','source_id')"
        );
        $count = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
        $this->hasFunctionRoomColumns = ($count === 5);
        return $this->hasFunctionRoomColumns;
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

    public function listRooms(string $q = ''): array
    {
        if (!$this->conn) {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            $imgSelect = $this->hasRoomImageColumn() ? 'r.image_path AS room_image_path,' : 'NULL AS room_image_path,';
            $rtImgSelect = $this->hasRoomTypeImageColumn() ? 'rt.image_path AS room_type_image_path,' : 'NULL AS room_type_image_path,';

            $sql = "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
                           {$imgSelect}
                           {$rtImgSelect}
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
        $imgSelect = $this->hasRoomImageColumn() ? 'r.image_path AS room_image_path,' : 'NULL AS room_image_path,';
        $rtImgSelect = $this->hasRoomTypeImageColumn() ? 'rt.image_path AS room_type_image_path,' : 'NULL AS room_type_image_path,';
        $stmt = $this->conn->prepare(
            "SELECT r.id, r.room_no, r.floor, r.status, r.room_type_id,
                    {$imgSelect}
                    {$rtImgSelect}
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

        $imgSelect = $this->hasRoomImageColumn() ? 'rooms.image_path AS room_image_path,' : 'NULL AS room_image_path,';
        $rtImgSelect = $this->hasRoomTypeImageColumn() ? 'rt.image_path AS room_type_image_path,' : 'NULL AS room_type_image_path,';
        $frImgSelect = $this->hasFunctionRoomImageColumn() ? 'fr.image_path AS function_room_image_path,' : 'NULL AS function_room_image_path,';

        if ($this->hasFunctionRoomColumns()) {
            $sql =
                "SELECT t.id, t.room_id, t.function_room_id, t.task_type, t.status, t.priority, t.assigned_to,
                        t.scheduled_from, t.scheduled_to,
                        t.started_at, t.completed_at, t.notes, t.created_at,
                        rooms.room_no,
                        {$imgSelect}
                        {$rtImgSelect}
                        {$frImgSelect}
                        rt.name AS room_type_name,
                        fr.name AS function_room_name
                 FROM housekeeping_tasks t
                 LEFT JOIN rooms ON rooms.id = t.room_id
                 LEFT JOIN room_types rt ON rt.id = rooms.room_type_id
                 LEFT JOIN function_rooms fr ON fr.id = t.function_room_id
                 WHERE t.status IN ('Open','In Progress')
                 ORDER BY t.created_at DESC
                 LIMIT $limit";
        } else {
            $sql =
                "SELECT t.id, t.room_id,
                        NULL AS function_room_id,
                        t.task_type, t.status, t.priority, t.assigned_to,
                        NULL AS scheduled_from,
                        NULL AS scheduled_to,
                        t.started_at, t.completed_at, t.notes, t.created_at,
                        rooms.room_no,
                        {$imgSelect}
                        {$rtImgSelect}
                        NULL AS function_room_image_path,
                        rt.name AS room_type_name,
                        NULL AS function_room_name
                 FROM housekeeping_tasks t
                 INNER JOIN rooms ON rooms.id = t.room_id
                 INNER JOIN room_types rt ON rt.id = rooms.room_type_id
                 WHERE t.status IN ('Open','In Progress')
                 ORDER BY t.created_at DESC
                 LIMIT $limit";
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

    public function createTask(array $data): int
    {
        if (!$this->conn) {
            return 0;
        }

        if ($this->hasFunctionRoomColumns()) {
            $stmt = $this->conn->prepare(
                "INSERT INTO housekeeping_tasks
                    (room_id, function_room_id, task_type, status, priority, assigned_to, created_by, scheduled_from, scheduled_to, source_type, source_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                return 0;
            }

            $roomId = (int)($data['room_id'] ?? 0);
            $functionRoomId = (int)($data['function_room_id'] ?? 0);
            $taskType = (string)($data['task_type'] ?? 'Cleaning');
            $status = (string)($data['status'] ?? 'Open');
            $priority = (string)($data['priority'] ?? 'Normal');
            $assignedTo = $data['assigned_to'] !== null ? (int)$data['assigned_to'] : null;
            $createdBy = $data['created_by'] !== null ? (int)$data['created_by'] : null;
            $scheduledFrom = $data['scheduled_from'] ?? null;
            $scheduledTo = $data['scheduled_to'] ?? null;
            $sourceType = $data['source_type'] ?? null;
            $sourceId = $data['source_id'] ?? null;
            $notes = (string)($data['notes'] ?? '');

            if ($roomId <= 0) {
                $roomId = null;
            }
            if ($functionRoomId <= 0) {
                $functionRoomId = null;
            }
            if (is_string($scheduledFrom) && trim($scheduledFrom) === '') {
                $scheduledFrom = null;
            }
            if (is_string($scheduledTo) && trim($scheduledTo) === '') {
                $scheduledTo = null;
            }
            if (is_string($sourceType) && trim($sourceType) === '') {
                $sourceType = null;
            }
            if ((int)$sourceId <= 0) {
                $sourceId = null;
            }

            $stmt->bind_param(
                'iisssiisssis',
                $roomId,
                $functionRoomId,
                $taskType,
                $status,
                $priority,
                $assignedTo,
                $createdBy,
                $scheduledFrom,
                $scheduledTo,
                $sourceType,
                $sourceId,
                $notes
            );
        } else {
            $roomId = (int)($data['room_id'] ?? 0);
            if ($roomId <= 0) {
                return 0;
            }

            $stmt = $this->conn->prepare(
                "INSERT INTO housekeeping_tasks (room_id, task_type, status, priority, assigned_to, created_by, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                return 0;
            }

            $taskType = (string)($data['task_type'] ?? 'Cleaning');
            $status = (string)($data['status'] ?? 'Open');
            $priority = (string)($data['priority'] ?? 'Normal');
            $assignedTo = $data['assigned_to'] !== null ? (int)$data['assigned_to'] : null;
            $createdBy = $data['created_by'] !== null ? (int)$data['created_by'] : null;
            $notes = (string)($data['notes'] ?? '');

            $stmt->bind_param('isssiis', $roomId, $taskType, $status, $priority, $assignedTo, $createdBy, $notes);
        }

        $ok = $stmt->execute();
        $id = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        return $id;
    }

    public function updateFunctionRoomStatus(int $functionRoomId, string $status): bool
    {
        if (!$this->conn) {
            return false;
        }
        if ($functionRoomId <= 0) {
            return false;
        }
        $stmt = $this->conn->prepare('UPDATE function_rooms SET status = ? WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $status, $functionRoomId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function updateTaskStatus(int $taskId, string $status): bool
    {
        if (!$this->conn) {
            return false;
        }

        if (!$this->hasTaskTimingColumns()) {
            $stmt = $this->conn->prepare('UPDATE housekeeping_tasks SET status = ? WHERE id = ?');
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('si', $status, $taskId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
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

        $frImgSelect = $this->hasFunctionRoomImageColumn() ? 'fr.image_path AS function_room_image_path,' : 'NULL AS function_room_image_path,';

        if ($this->hasFunctionRoomColumns()) {
            $stmt = $this->conn->prepare(
                "SELECT t.id, t.room_id, t.function_room_id, t.task_type, t.status, t.priority, t.assigned_to,
                        t.scheduled_from, t.scheduled_to,
                        t.started_at, t.completed_at, t.notes, t.created_at,
                        rooms.room_no, rooms.status AS room_status,
                        rt.name AS room_type_name,
                        {$frImgSelect}
                        fr.name AS function_room_name
                 FROM housekeeping_tasks t
                 LEFT JOIN rooms ON rooms.id = t.room_id
                 LEFT JOIN room_types rt ON rt.id = rooms.room_type_id
                 LEFT JOIN function_rooms fr ON fr.id = t.function_room_id
                 WHERE t.id = ?"
            );
        } else {
            $stmt = $this->conn->prepare(
                "SELECT t.id, t.room_id,
                        NULL AS function_room_id,
                        t.task_type, t.status, t.priority, t.assigned_to,
                        NULL AS scheduled_from,
                        NULL AS scheduled_to,
                        t.started_at, t.completed_at, t.notes, t.created_at,
                        rooms.room_no, rooms.status AS room_status,
                        rt.name AS room_type_name,
                        NULL AS function_room_image_path,
                        NULL AS function_room_name
                 FROM housekeeping_tasks t
                 INNER JOIN rooms ON rooms.id = t.room_id
                 INNER JOIN room_types rt ON rt.id = rooms.room_type_id
                 WHERE t.id = ?"
            );
        }
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
