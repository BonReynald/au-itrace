<?php
// Define database connection
define('DB_SERVER', '127.0.0.1');
define('DB_USERNAME', 'reynald');
define('DB_PASSWORD', 'bon');
define('DB_NAME', 'au-itrace');

// Attempt to connect to the database
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check if the connection is unsuccessful
if ($link === false) {
    die("❌ ERROR: CANNOT CONNECT. " . mysqli_connect_error());
} 

// Set time zone
date_default_timezone_set('Asia/Manila');
?>
