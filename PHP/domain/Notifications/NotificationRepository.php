<?php

require_once __DIR__ . '/../../core/Database.php';

final class NotificationRepository
{
    private ?mysqli $conn;
    private ?bool $hasNotificationsTable = null;

    public function __construct(?mysqli $conn)
    {
        $this->conn = $conn;
    }

    private function hasNotificationsTable(): bool
    {
        if ($this->hasNotificationsTable !== null) {
            return $this->hasNotificationsTable;
        }
        if (!$this->conn) {
            $this->hasNotificationsTable = false;
            return false;
        }

        try {
            $dbRow = $this->conn->query('SELECT DATABASE()');
            $db = $dbRow ? (string)($dbRow->fetch_row()[0] ?? '') : '';
            $db = $this->conn->real_escape_string($db);
            if ($db === '') {
                $this->hasNotificationsTable = false;
                return false;
            }

            $res = $this->conn->query(
                "SELECT COUNT(*) AS c
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'notifications'"
            );
            $this->hasNotificationsTable = $res ? ((int)($res->fetch_assoc()['c'] ?? 0) === 1) : false;
            return $this->hasNotificationsTable;
        } catch (Throwable $e) {
            $this->hasNotificationsTable = false;
            return false;
        }
    }

    public function createForUser(int $userId, string $title, string $message = '', string $url = ''): bool
    {
        if (!$this->conn || !$this->hasNotificationsTable()) {
            return false;
        }

        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }

        $title = trim($title);
        if ($title === '') {
            $title = 'Notification';
        }

        $message = trim($message);
        if ($message === '') {
            $message = null;
        }

        $url = trim($url);
        if ($url === '') {
            $url = null;
        }

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO notifications (user_id, title, message, url, is_read)
                 VALUES (?, ?, ?, ?, 0)"
            );
            if (!($stmt instanceof mysqli_stmt)) {
                return false;
            }
            $stmt->bind_param('isss', $userId, $title, $message, $url);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool)$ok;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function createForStaff(string $title, string $message = '', string $url = ''): int
    {
        if (!$this->conn || !$this->hasNotificationsTable()) {
            return 0;
        }

        $count = 0;
        try {
            $res = $this->conn->query("SELECT id FROM users WHERE role <> 'guest'");
            if (!$res) {
                return 0;
            }

            while ($row = $res->fetch_assoc()) {
                $uid = (int)($row['id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                if ($this->createForUser($uid, $title, $message, $url)) {
                    $count++;
                }
            }

            return $count;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
