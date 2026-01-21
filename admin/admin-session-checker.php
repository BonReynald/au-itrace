<?php


// Set session cookie path and start session
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

require_once '../config.php';

// Debug log — optional (comment out in production)
error_log("SESSION: " . print_r($_SESSION, true));

// Check if session vars are set
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    header("Location: ../au_itrace_portal.php?tab=login&error=session_missing");
    exit;
}

// Check usertype
if (strtoupper($_SESSION['usertype']) !== 'ADMINISTRATOR') {
    die("Access denied: not an administrator.");
}

// Sanitize and get username
$username = trim($_SESSION['username']);

// Ensure DB connection
if (!$link) {
    die("Database connection failed.");
}

// Validate user in database
$sql = "SELECT username, usertype, status FROM tblsystemusers WHERE username = ?";
$stmt = $link->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $link->error);
}
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows !== 1) {
    die("User not found or inactive in DB. (Username: " . htmlspecialchars($username) . ")");
}

$user = $result->fetch_assoc();

// Final checks
if (strtoupper($user['usertype']) !== 'ADMINISTRATOR') {
    die("DB says user is not an administrator.");
}
if (strtoupper($user['status']) !== 'ACTIVE') {
    die("User is not active.");
}

$stmt->close();
