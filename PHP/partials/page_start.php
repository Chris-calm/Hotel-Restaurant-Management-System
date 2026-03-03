<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../core/bootstrap.php';
$APP_BASE_URL = App::baseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Hotel Management System') ?></title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="<?= htmlspecialchars($APP_BASE_URL) ?>/CSS/index.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php include __DIR__ . '/styles.php'; ?>
    <?= $extraHeadHtml ?? '' ?>
</head>
<body class="flex h-screen overflow-hidden" style="background-color: #eeeeee;">
