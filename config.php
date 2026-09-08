<?php
// Database connection settings
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'waste_tracker');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

// shared secret the ESP32 sends with every reading. Change this before the
// site goes on a public host, and change it in the device sketch to match.
define('DEVICE_API_KEY', 'waste123');

// how many seconds without a heartbeat before the dashboard calls the bin offline
define('DEVICE_OFFLINE_AFTER', 30);

// PHP must agree with the database about what "now" means, otherwise timestamps
// read back from waste_records get stamped with the wrong offset and the
// dashboard shows events hours out. Keep this matching the MySQL server's zone.
date_default_timezone_set('Africa/Cairo');

session_start();

// one-per-session token, required on destructive requests so that a session
// cookie alone is not enough for another site to post deletions on your behalf
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
