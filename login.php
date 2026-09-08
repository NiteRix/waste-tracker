<?php
// Handles login form submission and checks credentials
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        header('Location: index.php?error=1');
        exit;
    }

    // look up the user
    $stmt = mysqli_prepare($conn, "SELECT id, username, password FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        header('Location: dashboard.php');
        exit;
    } else {
        header('Location: index.php?error=1');
        exit;
    }

} else {
    header('Location: index.php');
    exit;
}
