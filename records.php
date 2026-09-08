<?php
require 'config.php';

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
<title>All Records - Waste Tracking System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
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
    <a href="dashboard.php">Dashboard</a>
    <a href="records.php" class="active">All Records</a>
    <a href="logout.php" class="logout">Logout</a>
  </nav>
</header>

<main>

  <div class="page-head">
    <div>
      <h2>All Records</h2>
      <p class="sub">Every waste and emptying event logged by the system</p>
    </div>
  </div>

  <section class="panel">
    <div class="filters">
      <div class="field">
        <label for="lineFilter">Production line</label>
        <select id="lineFilter">
          <option value="">All lines</option>
          <option value="line1">Line 1 - Drawing</option>
          <option value="line2">Line 2 - Extrusion</option>
          <option value="line3">Line 3 - Stranding</option>
        </select>
      </div>

      <div class="field">
        <label for="dateFilter">Date</label>
        <input type="date" id="dateFilter">
      </div>

      <button class="btn" id="filterBtn">Filter</button>
      <button class="btn btn-ghost" id="resetBtn">Reset</button>

      <span class="result-count" id="resultCount"></span>
    </div>
  </section>

  <section class="panel">
    <div class="table-scroll">
      <table id="allRecordsTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Line</th>
            <th class="num">Weight (kg)</th>
            <th>Type</th>
            <th class="col-action"><span class="sr-only">Delete</span></th>
          </tr>
        </thead>
        <tbody id="allRecordsBody"></tbody>
      </table>
    </div>

    <div class="danger-zone">
      <div>
        <strong>Delete all records</strong>
        <span>Clears every waste and emptying event. The bin keeps logging new ones.</span>
      </div>
      <button class="btn btn-danger" id="resetAllBtn">Reset all records</button>
    </div>

    <div class="empty" id="recordsEmpty" style="display:none;">
      <img src="logo-white.svg" alt="" class="empty-icon">
      <h4>No matching records</h4>
      <p>Nothing was logged for this line and date. Try widening the filters, or reset them to see everything.</p>
    </div>
  </section>

</main>

<div class="modal-backdrop" id="confirmModal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <h3 id="confirmTitle">Delete records?</h3>
    <p id="confirmText">This cannot be undone.</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" id="confirmCancel">Cancel</button>
      <button class="btn btn-danger" id="confirmOk">Delete</button>
    </div>
  </div>
</div>

<script src="script.js"></script>
</body>
</html>
