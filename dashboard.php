<?php
require 'config.php';

// only logged-in users can view this page
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard - Waste Tracking System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap">
<link rel="stylesheet" href="style.css">
<link rel="icon" type="image/svg+xml" href="logo.svg">
</head>
<body>

<header>
  <div class="brand">
    <img src="logo-white.svg" alt="" class="brand-icon">
    <h1>Waste Tracking System</h1>
  </div>
  <nav>
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="records.php">All Records</a>
    <a href="logout.php" class="logout">Logout</a>
  </nav>
</header>

<main>

  <div class="page-head">
    <div>
      <h2>Production Waste Overview</h2>
      <p class="sub">Readings from the smart bin, updated automatically</p>
    </div>
    <div class="live" id="liveIndicator">
      <span class="live-dot"></span>
      <span class="live-label">Connecting…</span>
    </div>
  </div>

  <section class="cards">
    <div class="card is-primary">
      <h3>Current Bin Weight</h3>
      <div class="stat">
        <span class="stat-value" id="binWeight">0.00</span>
        <span class="stat-unit">kg</span>
      </div>
      <div class="gauge"><div class="gauge-fill" id="binGauge"></div></div>
      <div class="stat-note" id="binNote">&nbsp;</div>
    </div>

    <div class="card">
      <h3>Total Waste Today</h3>
      <div class="stat">
        <span class="stat-value" id="todayTotal">0.00</span>
        <span class="stat-unit">kg</span>
      </div>
      <div class="stat-note" id="todayNote">&nbsp;</div>
    </div>

    <div class="card">
      <h3>Events Today</h3>
      <div class="stat">
        <span class="stat-value" id="eventsCount">0</span>
      </div>
      <div class="stat-note" id="eventsNote">&nbsp;</div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Cumulative Waste Today</h2>
    </div>
    <div class="panel-body">
      <div class="chart-wrap" id="chartWrap">
        <svg class="chart" id="wasteChart" role="img" aria-label="Cumulative waste collected today"></svg>
        <div class="tooltip" id="chartTooltip"></div>
      </div>
    </div>
    <div class="chart-legend">
      <span><i class="swatch waste"></i>Cumulative waste (kg)</span>
      <span><i class="swatch emptying"></i>Bin emptied</span>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Recent Events</h2>
      <a href="records.php" class="btn btn-ghost btn-link">View all</a>
    </div>

    <div class="table-scroll">
      <table id="recordsTable">
        <thead>
          <tr>
            <th>Time</th>
            <th>Line</th>
            <th class="num">Weight (kg)</th>
            <th>Type</th>
            <th class="hide-xs">When</th>
          </tr>
        </thead>
        <tbody id="recordsBody"></tbody>
      </table>
    </div>

    <div class="empty" id="recentEmpty" style="display:none;">
      <img src="logo-white.svg" alt="" class="empty-icon">
      <h4>No events recorded yet</h4>
      <p>Once the smart bin sends its first reading it will appear here. The device posts to <code>api/insert_record.php</code>.</p>
    </div>
  </section>

</main>

<script src="script.js"></script>
</body>
</html>
