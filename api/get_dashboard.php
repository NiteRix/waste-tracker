<?php
// everything the dashboard needs, in one request:
// live bin weight, today's totals, today's events for the chart, and the recent list
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// the bin has been filling since the last time it was emptied - which may be days ago
$res = mysqli_query($conn, "SELECT MAX(created_at) AS t FROM waste_records WHERE event_type = 'emptying'");
$lastEmptied = mysqli_fetch_assoc($res)['t'];

if ($lastEmptied !== null) {
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(SUM(weight), 0) AS s FROM waste_records WHERE event_type = 'waste' AND created_at > ?");
    mysqli_stmt_bind_param($stmt, "s", $lastEmptied);
    mysqli_stmt_execute($stmt);
    $binWeight = (float) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['s'];
} else {
    $res = mysqli_query($conn, "SELECT COALESCE(SUM(weight), 0) AS s FROM waste_records WHERE event_type = 'waste'");
    $binWeight = (float) mysqli_fetch_assoc($res)['s'];
}

// today's headline numbers
$res = mysqli_query($conn, "
    SELECT COALESCE(SUM(CASE WHEN event_type = 'waste' THEN weight END), 0) AS total,
           COUNT(*) AS events
    FROM waste_records
    WHERE DATE(created_at) = CURDATE()
");
$today = mysqli_fetch_assoc($res);

// yesterday's total, so today's figure has something to be compared against
$res = mysqli_query($conn, "
    SELECT COALESCE(SUM(weight), 0) AS total
    FROM waste_records
    WHERE event_type = 'waste' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY
");
$yesterdayTotal = (float) mysqli_fetch_assoc($res)['total'];

// today's events in order - the chart builds its cumulative line from these
$res = mysqli_query($conn, "
    SELECT weight, event_type, created_at
    FROM waste_records
    WHERE DATE(created_at) = CURDATE()
    ORDER BY created_at ASC
");
$series = [];
while ($row = mysqli_fetch_assoc($res)) {
    $dt = new DateTime($row['created_at']);
    $series[] = [
        'at'     => $dt->format('c'),
        'weight' => (float) $row['weight'],
        'type'   => $row['event_type'],
    ];
}

// the recent list is not date-limited, so the panel is never empty first thing in the morning
$res = mysqli_query($conn, "
    SELECT id, line_code, weight, event_type, created_at
    FROM waste_records
    ORDER BY created_at DESC, id DESC
    LIMIT 12
");
$recent = [];
while ($row = mysqli_fetch_assoc($res)) {
    $dt = new DateTime($row['created_at']);
    $recent[] = [
        'id'     => (int) $row['id'],
        'at'     => $dt->format('c'),
        'date'   => $dt->format('Y-m-d'),
        'time'   => $dt->format('H:i:s'),
        'line'   => $row['line_code'],
        'weight' => (float) $row['weight'],
        'type'   => $row['event_type'],
    ];
}

// device liveness - the most recently heard-from bin drives the live indicator
$res = mysqli_query($conn, "
    SELECT device_id, line_code, current_weight, rssi, last_seen,
           TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago
    FROM device_status
    ORDER BY last_seen DESC
    LIMIT 1
");
$deviceRow = mysqli_fetch_assoc($res);

if ($deviceRow) {
    $secondsAgo = (int) $deviceRow['seconds_ago'];
    $device = [
        'known'          => true,
        'online'         => $secondsAgo <= DEVICE_OFFLINE_AFTER,
        'device_id'      => $deviceRow['device_id'],
        'line_code'      => $deviceRow['line_code'],
        'live_weight'    => round((float) $deviceRow['current_weight'], 2),
        'rssi'           => $deviceRow['rssi'] === null ? null : (int) $deviceRow['rssi'],
        'seconds_ago'    => $secondsAgo,
        'last_seen'      => (new DateTime($deviceRow['last_seen']))->format('c'),
    ];
} else {
    $device = ['known' => false, 'online' => false];
}

echo json_encode([
    'device'          => $device,
    'bin_weight'      => round($binWeight, 2),
    'last_emptied'    => $lastEmptied ? (new DateTime($lastEmptied))->format('c') : null,
    'today_total'     => round((float) $today['total'], 2),
    'today_events'    => (int) $today['events'],
    'yesterday_total' => round($yesterdayTotal, 2),
    'server_time'     => (new DateTime())->format('c'),
    'series'          => $series,
    'recent'          => $recent,
]);
