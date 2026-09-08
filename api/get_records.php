<?php
// returns waste records as JSON, optionally filtered by line and/or date
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$line = $_GET['line'] ?? '';
$date = $_GET['date'] ?? '';

$sql = "SELECT id, line_code, weight, event_type, created_at FROM waste_records WHERE 1=1";
$params = [];
$types = "";

if ($line !== '') {
    $sql .= " AND line_code = ?";
    $params[] = $line;
    $types .= "s";
}

if ($date !== '') {
    $sql .= " AND DATE(created_at) = ?";
    $params[] = $date;
    $types .= "s";
}

$sql .= " ORDER BY created_at ASC";

$stmt = mysqli_prepare($conn, $sql);

if ($params) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$records = [];
while ($row = mysqli_fetch_assoc($result)) {
    $dt = new DateTime($row['created_at']);
    $records[] = [
        'id'     => (int) $row['id'],
        'date'   => $dt->format('Y-m-d'),
        'time'   => $dt->format('H:i:s'),
        'line'   => $row['line_code'],
        'weight' => (float) $row['weight'],
        'type'   => $row['event_type'],
    ];
}

echo json_encode($records);
