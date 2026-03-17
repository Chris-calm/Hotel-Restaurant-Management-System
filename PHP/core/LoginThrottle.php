<?php

class LoginThrottle
{
    private static bool $initialized = false;

    private const WINDOW_SECONDS = 900;
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 900;

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
                "CREATE TABLE IF NOT EXISTS login_attempts (\n"
                . "  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
                . "  username VARCHAR(120) NOT NULL,\n"
                . "  ip VARCHAR(64) NOT NULL,\n"
                . "  fail_count INT UNSIGNED NOT NULL DEFAULT 0,\n"
                . "  first_failed_at DATETIME NULL,\n"
                . "  last_failed_at DATETIME NULL,\n"
                . "  locked_until DATETIME NULL,\n"
                . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
                . "  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n"
                . "  UNIQUE KEY uq_login_attempts (username, ip),\n"
                . "  INDEX idx_locked_until (locked_until)\n"
                . ") ENGINE=InnoDB"
            );
        } catch (Throwable $e) {
        }
    }

    public static function getLockRemainingSeconds(?mysqli $conn, string $username, string $ip): int
    {
        self::init($conn);
        if (!$conn || $username === '' || $ip === '') {
            return 0;
        }

        try {
            $stmt = $conn->prepare('SELECT locked_until FROM login_attempts WHERE username = ? AND ip = ? LIMIT 1');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $username, $ip);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $lockedUntil = (string)($row['locked_until'] ?? '');
                if ($lockedUntil !== '') {
                    $ts = strtotime($lockedUntil);
                    if ($ts !== false) {
                        $rem = $ts - time();
                        return $rem > 0 ? (int)$rem : 0;
                    }
                }
            }
        } catch (Throwable $e) {
        }

        return 0;
    }

    public static function recordFailure(?mysqli $conn, string $username, string $ip): int
    {
        self::init($conn);
        if (!$conn || $username === '' || $ip === '') {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $stmt = $conn->prepare('SELECT fail_count, first_failed_at, locked_until FROM login_attempts WHERE username = ? AND ip = ? LIMIT 1');
            $failCount = 0;
            $firstFailedAt = '';
            $lockedUntil = '';

            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $username, $ip);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $failCount = (int)($row['fail_count'] ?? 0);
                $firstFailedAt = (string)($row['first_failed_at'] ?? '');
                $lockedUntil = (string)($row['locked_until'] ?? '');
            }

            if ($lockedUntil !== '') {
                $ts = strtotime($lockedUntil);
                if ($ts !== false && $ts > time()) {
                    return (int)($ts - time());
                }
            }

            $windowStart = 0;
            if ($firstFailedAt !== '') {
                $ts = strtotime($firstFailedAt);
                if ($ts !== false) {
                    $windowStart = $ts;
                }
            }

            if ($windowStart <= 0 || (time() - $windowStart) > self::WINDOW_SECONDS) {
                $failCount = 0;
                $firstFailedAt = $now;
            }

            $failCount++;

            $lockRem = 0;
            $newLockedUntil = null;
            if ($failCount >= self::MAX_FAILS) {
                $newLockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
                $lockRem = self::LOCK_SECONDS;
            }

            $up = $conn->prepare(
                'INSERT INTO login_attempts (username, ip, fail_count, first_failed_at, last_failed_at, locked_until) VALUES (?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE fail_count = VALUES(fail_count), first_failed_at = VALUES(first_failed_at), last_failed_at = VALUES(last_failed_at), locked_until = VALUES(locked_until)'
            );
            if ($up instanceof mysqli_stmt) {
                $lu = $newLockedUntil;
                $up->bind_param('ssisss', $username, $ip, $failCount, $firstFailedAt, $now, $lu);
                $up->execute();
                $up->close();
            }

            return $lockRem;
        } catch (Throwable $e) {
        }

        return 0;
    }

    public static function clear(?mysqli $conn, string $username, string $ip): void
    {
        self::init($conn);
        if (!$conn || $username === '' || $ip === '') {
            return;
        }

        try {
            $stmt = $conn->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ?');
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('ss', $username, $ip);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
        }
    }
}
