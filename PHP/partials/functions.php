<?php

function getTotalCount($conn, string $table): int
{
    if (!$conn) {
        return 0;
    }

    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($tableSafe === '') {
        return 0;
    }

    $sql = "SELECT COUNT(*) as total FROM `{$tableSafe}`";
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return isset($row['total']) ? (int)$row['total'] : 0;
}

function getRecentCaseRecords($conn): array
{
    return [];
}

function getPendingItems($conn): array
{
    return [];
}
