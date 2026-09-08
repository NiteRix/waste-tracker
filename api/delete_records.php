<?php
// deletes waste records. POST only, logged in only, and the request must carry
// the session's CSRF token - a session cookie on its own is not enough.
//
// expected POST body (JSON), one of:
//   { "csrf_token": "...", "mode": "one", "id": 25 }
//   { "csrf_token": "...", "mode": "filter", "line": "line1", "date": "2026-09-02" }
//   { "csrf_token": "...", "mode": "all" }

require '../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// never allow this over GET - a link or a prefetch must not be able to delete
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string) $input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing token. Reload the page and try again.']);
    exit;
}

$mode = $input['mode'] ?? '';

if ($mode === 'one') {
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing record id']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM waste_records WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $deleted = mysqli_stmt_affected_rows($stmt);

} elseif ($mode === 'filter') {
    $line = trim($input['line'] ?? '');
    $date = trim($input['date'] ?? '');

    // refuse an unfiltered "filter" delete - that is what mode "all" is for,
    // so nobody wipes the table by accident with empty inputs
    if ($line === '' && $date === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Set a line or a date, or use Reset all']);
        exit;
    }

    $sql = "DELETE FROM waste_records WHERE 1=1";
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

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $deleted = mysqli_stmt_affected_rows($stmt);

} elseif ($mode === 'all') {
    $res = mysqli_query($conn, "SELECT COUNT(*) AS n FROM waste_records");
    $deleted = (int) mysqli_fetch_assoc($res)['n'];

    mysqli_query($conn, "DELETE FROM waste_records");
    // start counting from 1 again now that the table is empty
    mysqli_query($conn, "ALTER TABLE waste_records AUTO_INCREMENT = 1");

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown mode']);
    exit;
}

echo json_encode(['success' => true, 'deleted' => (int) $deleted]);
