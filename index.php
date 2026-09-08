<?php
require 'config.php';

// redirect to dashboard if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$showError = isset($_GET['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Waste Tracking System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap">
<link rel="stylesheet" href="style.css">
<link rel="icon" type="image/svg+xml" href="logo.svg">
</head>
<body>

<div class="login-page">
  <div class="login-card">
    <img src="logo-white.svg" alt="" class="login-logo">
    <h1>Waste Tracking System</h1>
    <p class="tagline">Smart bin monitoring &middot; production floor</p>

    <?php if ($showError): ?>
      <div class="error-msg" role="alert">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
          <path d="M8 4.5v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          <circle cx="8" cy="11" r="0.9" fill="currentColor"/>
        </svg>
        Invalid username or password
      </div>
    <?php endif; ?>

    <form action="login.php" method="POST">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" autofocus required>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>

      <button class="btn" type="submit">Sign in</button>
    </form>

    <p class="login-foot">Authorised personnel only</p>
  </div>
</div>

</body>
</html>
