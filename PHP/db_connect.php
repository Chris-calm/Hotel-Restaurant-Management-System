<?php

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_NAME = getenv('DB_NAME') ?: 'hotel_system';

$conn = null;

try {
    $mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if (!$mysqli->connect_errno) {
        $mysqli->set_charset('utf8mb4');
        $conn = $mysqli;
    }
} catch (Throwable $e) {
    $conn = null;
}
