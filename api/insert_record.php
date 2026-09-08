<?php
// receives one waste event from the ESP32 device and stores it
// expected POST body (JSON):
// { "api_key": "waste123", "line_code": "line1", "weight": 0.85, "event_type": "waste" }

require '../config.php';

header('Content-Type: application/json; charset=utf-8');

// the shared key lives in config.php, so the heartbeat endpoint uses the same one

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['api_key']) || $input['api_key'] !== DEVICE_API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$line = $input['line_code'] ?? '';
$weight = $input['weight'] ?? null;
$type = $input['event_type'] ?? 'waste';

if ($line === '' || $weight === null || !is_numeric($weight)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid data']);
    exit;
}

if (!in_array($type, ['waste', 'emptying'])) {
    $type = 'waste';
}

$stmt = mysqli_prepare($conn, "INSERT INTO waste_records (line_code, weight, event_type) VALUES (?, ?, ?)");
mysqli_stmt_bind_param($stmt, "sds", $line, $weight, $type);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn)]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save record']);
}
