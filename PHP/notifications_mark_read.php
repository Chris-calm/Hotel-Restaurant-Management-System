<?php
require_once __DIR__ . '/rbac_middleware.php';
RBACMiddleware::checkPageAccess();

require_once __DIR__ . '/core/bootstrap.php';

$conn = Database::getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);

$id = Request::int('get', 'id', 0);
$redirect = (string)Request::get('redirect', '');
$go = (string)Request::get('go', '');

if ($redirect === '') {
    $redirect = 'Dashboard.php';
}

if (!$conn || $userId <= 0 || $id <= 0) {
    Response::redirect($redirect);
}

try {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
}

$target = $go !== '' ? $go : $redirect;
Response::redirect($target);
