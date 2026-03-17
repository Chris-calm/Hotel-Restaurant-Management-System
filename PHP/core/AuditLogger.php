<?php

class AuditLogger
{
    private static bool $initialized = false;

    private static function init(?mysqli $conn): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        if (!$conn) {
            return;
        }

        try {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS audit_logs (\n"
                . "  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
                . "  actor_user_id INT UNSIGNED NULL,\n"
                . "  actor_role VARCHAR(40) NULL,\n"
                . "  target_user_id INT UNSIGNED NULL,\n"
                . "  action VARCHAR(80) NOT NULL,\n"
                . "  ip VARCHAR(64) NULL,\n"
                . "  user_agent VARCHAR(255) NULL,\n"
                . "  details TEXT NULL,\n"
                . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
                . "  INDEX idx_audit_actor (actor_user_id),\n"
                . "  INDEX idx_audit_target (target_user_id),\n"
                . "  INDEX idx_audit_action_time (action, created_at)\n"
                . ") ENGINE=InnoDB"
            );
        } catch (Throwable $e) {
        }
    }

    public static function log(?mysqli $conn, ?int $actorUserId, ?string $actorRole, string $action, ?int $targetUserId = null, array $details = []): void
    {
        self::init($conn);

        if (!$conn) {
            return;
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ua = substr($ua, 0, 255);

        $detailsJson = '';
        if (!empty($details)) {
            try {
                $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                $detailsJson = '';
            }
        }

        try {
            $stmt = $conn->prepare(
                'INSERT INTO audit_logs (actor_user_id, actor_role, target_user_id, action, ip, user_agent, details) VALUES (NULLIF(?,0), NULLIF(?,\'\'), NULLIF(?,0), ?, NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'))'
            );
            if ($stmt instanceof mysqli_stmt) {
                $actor = $actorUserId !== null ? (int)$actorUserId : 0;
                $target = $targetUserId !== null ? (int)$targetUserId : 0;
                $role = (string)($actorRole ?? '');
                $act = (string)$action;
                $stmt->bind_param(
                    'isissss',
                    $actor,
                    $role,
                    $target,
                    $act,
                    $ip,
                    $ua,
                    $detailsJson
                );
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
        }
    }
}
