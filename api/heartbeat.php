<?php
// the smart bin calls this every few seconds to say "I am alive", and to report
// what the load cell currently reads. No row is written to waste_records here -
// this is only liveness plus the live scale value.
//
// expected POST body (JSON):
// { "api_key": "waste123", "device_id": "bin-line1", "line_code": "line1",
//   "weight": 1.24, "rssi": -58 }

require '../config.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['api_key']) || !hash_equals(DEVICE_API_KEY, (string) $input['api_key'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

$deviceId = trim($input['device_id'] ?? '');
$line     = trim($input['line_code'] ?? '');
$weight   = $input['weight'] ?? null;
$rssi     = isset($input['rssi']) ? (int) $input['rssi'] : null;

if ($deviceId === '' || $line === '' || $weight === null || !is_numeric($weight)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid data']);
    exit;
}

$weight = (float) $weight;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

$stmt = mysqli_prepare($conn, "
    INSERT INTO device_status (device_id, line_code, current_weight, rssi, ip_address, last_seen)
    VALUES (?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        line_code = VALUES(line_code),
        current_weight = VALUES(current_weight),
        rssi = VALUES(rssi),
        ip_address = VALUES(ip_address),
        last_seen = NOW()
");
mysqli_stmt_bind_param($stmt, "ssdis", $deviceId, $line, $weight, $rssi, $ip);

if (mysqli_stmt_execute($stmt)) {
    // handing the device the server clock lets it log accurate times over serial
    // without needing NTP or the compile-time fallback
    echo json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record heartbeat']);
}
