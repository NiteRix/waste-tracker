<?php
// Ends the user session and returns to the login page
require 'config.php';

$_SESSION = [];
session_destroy();

header('Location: index.php');
exit;
