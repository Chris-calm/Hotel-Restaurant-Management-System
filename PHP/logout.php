<?php
session_start();
session_unset();
session_destroy();
setcookie('trusted_device', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
header('Location: ../index.php');
exit();
