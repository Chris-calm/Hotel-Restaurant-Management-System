<?php
require_once __DIR__ . '/rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/core/bootstrap.php';

$conn = Database::getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);
$redirect = (string)Request::get('redirect', '');

if ($redirect === '') {
    $redirect = 'Dashboard.php';
}

if (!$conn || $userId <= 0) {
    Response::redirect($redirect);
}

try {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
}

Response::redirect($redirect);
