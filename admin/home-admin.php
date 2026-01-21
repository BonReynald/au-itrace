<?php
// Set secure session cookie parameters
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Include database configuration
require_once '../config.php';

// Check for valid database connection
if (!$link || $link->connect_error) {
    die("Database connection failed: " . ($link ? $link->connect_error : "DB link not set."));
}

// --- Initial Authentication and Authorization Checks (Hardened) ---
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    // Destroy the session and redirect to prevent partial state
    session_destroy();
    header("Location: ../login.php"); // Assuming login is in parent directory
    exit;
}

// Must be an administrator to proceed
if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    http_response_code(403);
    die("Access Denied. User type is not ADMINISTRATOR.");
}

$username = trim($_SESSION['username']);

// Validate user's current status and type in the database
$sql = "SELECT usertype, status FROM tblsystemusers WHERE username = ?";
$stmt = $link->prepare($sql);

if (!$stmt) {
    error_log("Prepare failed for auth check: " . $link->error);
    http_response_code(500);
    die("System error. Cannot verify user.");
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    // User not found in DB or inactive
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Enforce type and status from DB
if (strcasecmp($user['usertype'], 'ADMINISTRATOR') !== 0 || strcasecmp($user['status'], 'ACTIVE') !== 0) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}
// --- END Authentication Checks ---


// --- NEW FUNCTION: Log Admin Activity ---
function log_admin_activity($link, $username, $action) {
    // Use NULL for foundID, assuming it's an INT or NULLABLE column.
    // The `i` type is used for integer which works for NULL in prepared statements.
    $page = 'Home/Announcements';
    $foundID = NULL; 

    // Use explicit column list for the types: s(username), s(action), s(page), i(foundID - nullable int)
    $stmt = $link->prepare("INSERT INTO tbladminlogs (username, action, page, foundID, date_time) VALUES (?, ?, ?, ?, NOW())");
    
    if (!$stmt) {
        error_log("Failed to prepare log statement: " . $link->error);
        return false;
    }
    
    // Pass PHP NULL directly for nullable integer column
    $stmt->bind_param("sssi", $username, $action, $page, $foundID);
    
    if (!$stmt->execute()) {
        error_log("Failed to execute log statement: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $stmt->close();
    return true;
}
// --- END NEW FUNCTION ---


// --- FIX: ENFORCE UTF8MB4 FOR EMOJI SUPPORT ---
if (!$link->set_charset("utf8mb4")) {
    error_log("Error loading character set utf8mb4: " . $link->error);
}
// --- END FIX ---


// **********************************************
// ********* CORE LOGIC: ADMIN ACTIVITY CHECK *****
// **********************************************

$has_admin_activity = false;
$sql_check_activity = "SELECT COUNT(*) AS total FROM tblannouncements"; 
$stmt_activity = $link->prepare($sql_check_activity);

if ($stmt_activity) {
    $stmt_activity->execute();
    $result_activity = $stmt_activity->get_result();
    $row = $result_activity->fetch_assoc();
    
    if ($row && $row['total'] > 0) {
        $has_admin_activity = true;
    }
    $stmt_activity->close();
} else {
    error_log("DB Error preparing activity check: " . $link->error);
}


// **********************************************
// ********* REFACTORED: FETCH STATS USING PREPARED STATEMENTS *****
// **********************************************

$stats = [
    'found_items' => 0,
    'returned_items' => 0,
    'pending_claims' => 0,
    'active_users' => 0,
];

// Helper function to execute a simple count query using prepared statements
function fetch_stat_count($link, $sql, &$stats, $key) {
    if (!$stmt = $link->prepare($sql)) {
        error_log("DB Error preparing stat query for {$key}: " . $link->error);
        return;
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row) {
            $stats[$key] = $row['total'];
        }
    } else {
        error_log("DB Error executing stat query for {$key}: " . $stmt->error);
    }
    $stmt->close();
}

if ($link) {    
    // 1. Items Found
    fetch_stat_count($link, "SELECT COUNT(*) AS total FROM tblfounditems", $stats, 'found_items');

    // 2. Returned Items
    fetch_stat_count($link, "SELECT COUNT(*) AS total FROM tblitemstatus WHERE status = 'Returned'", $stats, 'returned_items');

    // 3. Pending Claims
    fetch_stat_count($link, "SELECT COUNT(*) AS total FROM tblclaimrequests WHERE status = 'Pending'", $stats, 'pending_claims');

    // 4. Active Users
    // NOTE: If tblactivestudents is the table for active students, this is fine.
    fetch_stat_count($link, "SELECT COUNT(*) AS total FROM tblactivestudents", $stats, 'active_users');
}

// **********************************************
// ********* END REFACTORED: FETCH STATS *******
// **********************************************


// Handle form submission
$message = "";
$error = "";

// 1. HANDLE NEW ANNOUNCEMENT POSTING
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message']) && !isset($_POST['edit_announcement_id']) && !isset($_POST['delete_announcement_id'])) {
    $msg = trim($_POST['message']);

    if (!empty($msg)) {
        // Prepare statement for posting. We explicitly use '?' for values.
        $stmt = $link->prepare("INSERT INTO tblannouncements (message, datecreated) VALUES (?, NOW())");
        
        if (!$stmt) {
             $error = "DB Prepare Error: " . $link->error;
        } else {
            $stmt->bind_param("s", $msg);
            
            if ($stmt->execute()) {
                $new_id = $link->insert_id;

                // ⭐ LOG ACTIVITY: Post Announcement
                log_admin_activity($link, $username, "Posted new announcement (ID: {$new_id}): " . substr($msg, 0, 50) . "...");

                $stmt->close();
                $has_admin_activity = true;   
                header("Location: " . $_SERVER['PHP_SELF'] . "?posted=1");
                exit;
            } else {
                $error = "Failed to post announcement: " . $stmt->error;
            }
            if ($stmt) $stmt->close();
        }
    } else {
        $error = "Message cannot be empty.";
    }
}

// 2. HANDLE ANNOUNCEMENT EDIT/UPDATE     
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_announcement_id']) && isset($_POST['edit_message'])) {
    $id = filter_var($_POST['edit_announcement_id'], FILTER_VALIDATE_INT);
    $new_message = trim($_POST['edit_message']);

    if ($id > 0 && !empty($new_message)) {
        $stmt = $link->prepare("UPDATE tblannouncements SET message = ? WHERE id = ?");
        
        if (!$stmt) {
            $error = "DB Prepare Error: " . $link->error;
        } else {
            $stmt->bind_param("si", $new_message, $id);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    // ⭐ LOG ACTIVITY: Edit Announcement
                    log_admin_activity($link, $username, "Edited announcement ID: {$id}. New content: " . substr($new_message, 0, 50) . "...");

                    $stmt->close();
                    header("Location: " . $_SERVER['PHP_SELF'] . "?updated=1");
                    exit;
                } else {
                     $error = "No announcement found with ID: {$id} or message was identical.";
                }
            } else {
                $error = "Failed to update announcement: " . $stmt->error;
            }
            if ($stmt) $stmt->close();
        }
    } else {
        $error = "Invalid ID or empty message for update.";
    }
}

// 3. HANDLE ANNOUNCEMENT DELETE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_announcement_id'])) {
    $id = filter_var($_POST['delete_announcement_id'], FILTER_VALIDATE_INT);

    if ($id > 0) {
        // First, fetch the message before deleting it for logging purposes
        $old_message = '';
        $stmt_fetch = $link->prepare("SELECT message FROM tblannouncements WHERE id = ?");
        $stmt_fetch->bind_param("i", $id);
        if ($stmt_fetch->execute()) {
            $result_fetch = $stmt_fetch->get_result();
            if ($row = $result_fetch->fetch_assoc()) {
                $old_message = $row['message'];
            }
        }
        $stmt_fetch->close();

        $stmt = $link->prepare("DELETE FROM tblannouncements WHERE id = ?");
        
        if (!$stmt) {
             $error = "DB Prepare Error: " . $link->error;
        } else {
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                // ⭐ LOG ACTIVITY: Delete Announcement
                $log_content = "Deleted announcement ID: {$id}. Content was: " . substr($old_message, 0, 50) . "...";
                log_admin_activity($link, $username, $log_content);

                $stmt->close();
                header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
                exit;
            } else {
                $error = "Failed to delete announcement: " . $stmt->error;
            }
            if ($stmt) $stmt->close();
        }
    } else {
        $error = "Invalid ID for deletion.";
    }
}


// Show success messages if redirected after operations
if (isset($_GET['posted']) && $_GET['posted'] == 1) {
    $message = "Announcement posted successfully! 🎉";
}
if (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $message = "Announcement updated successfully! 💾";
}
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $message = "Announcement deleted successfully! 🗑️";
}


// Fetch announcements to display
$announcements = [];
// Select all announcements and order by date
$sql = "SELECT id, message, datecreated FROM tblannouncements ORDER BY datecreated DESC LIMIT 5"; 

$stmt_announcements = $link->prepare($sql);
if ($stmt_announcements) {
    $stmt_announcements->execute();
    $result = $stmt_announcements->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
    }
    $stmt_announcements->close();
} else {
    error_log("DB Error preparing announcement fetch: " . $link->error);
}


// Close the database connection
if ($link) {
    $link->close();
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* General Styles */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6; /* Light background for the content area */
            color: #333;
        }
        
        /* Top Header Bar Styles (BLUE) */
        .top-header {
            position: fixed;
            top: 0;
            left: 250px; /* Aligned next to sidebar */
            right: 0;
            height: 60px; /* Standard header height */
            background-color: #004ea8; /* Blue background */
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 500;
            display: flex;
            align-items: center;
            padding: 0 20px;
        }
        .top-header h1 {
            margin: 0;
            font-size: 24px;
            color: white; /* White text for blue background */
        }

        /* Sidebar Styles */
        nav {
            width: 250px;
            background-color: #004ea8;
            color: white;
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            /* Make sidebar fixed and full height */
            position: fixed; 
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
        }
        nav h2 {
            padding: 0 20px;
            font-size: 20px;
            margin-bottom: 30px;
        }
        nav ul {
            list-style: none;
            padding: 0;
            flex-grow: 1; /* Allow list to take up space */
        }
        nav ul li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        nav ul li a:hover {
            background-color: #1a6ab9; /* Slightly darker on hover */
        }
        nav ul li a.active {
            background-color: #2980b9;
        }

        /* Logout Button in Sidebar (RED - Explicitly Set) */
        .sidebar-logout {
            padding: 20px;
            border-top: 1px solid #1a6ab9;
        }
        .sidebar-logout button {
            width: 100%;
            /* BRIGHTER RED for clear danger/exit action */
            background-color: #ff3333 !important; 
            color: white !important;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold; 
            transition: background-color 0.2s;
        }
        .sidebar-logout button:hover {
            background-color: #cc0000 !important; /* Darker red on hover */
        }
        
        /* Main Layout Styles */
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .main-content {
            /* Margin down by the header height (60px) */
            margin-left: 250px; 
            margin-top: 60px; 
            flex: 1;
            padding: 20px;
            min-height: calc(100vh - 120px); 
        }
        .main-content h3 {
            color: #004ea8;
            margin-bottom: 20px;
            margin-top: 0; 
        }
        
        /* ADMIN WELCOME STYLES */
        .new-admin-welcome {
            background-color: #e9f5ff; /* Light blue background */
            padding: 30px;
            border-radius: 8px;
            border: 2px solid #004ea8;
            margin-bottom: 30px;
            text-align: center;
        }
        .new-admin-welcome h2 {
            color: #004ea8;
            margin-top: 0;
            font-size: 28px;
        }
        .new-admin-welcome p {
            font-size: 18px;
            color: #333;
        }
        /* END ADMIN WELCOME STYLES */

        /* STATS STYLES */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background-color: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            text-align: center;
            border-top: 5px solid;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
        .stat-card.found { border-color: #ffc107; } /* Yellow */
        .stat-card.returned { border-color: #28a745; } /* Green */
        .stat-card.pending { border-color: #fd7e14; } /* Orange */
        .stat-card.users { border-color: #007bff; } /* Blue */

        .stat-card .icon {
            font-size: 36px;
            margin-bottom: 10px;
        }
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        .stat-card .label {
            font-size: 14px;
            color: #6c757d;
            font-weight: 600;
        }
        /* END STATS STYLES */

        /* Announcement Posting Section Styles */
        .card {
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border-left: 5px solid #004ea8; /* Accent color */
        }
        .card h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        textarea {
            width: 100%;
            max-width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            resize: vertical;
            min-height: 100px;
            font-size: 16px;
        }
        button[type="submit"] {
            background-color: #28a745; /* Green color for Post */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.2s;
        }
        button[type="submit"]:hover {
            background-color: #1e7e34;
        }

        /* Message Styles */
        .message-box {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-weight: bold;
            opacity: 1; /* Default visible state for fade out */
            transition: opacity 0.5s ease-in-out;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Recent Announcements Styles */
        .announcement-list {
            list-style: none;
            padding: 0;
        }
        .announcement-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: white;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 10px;
            transition: box-shadow 0.2s;
        }
        .announcement-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .announcement-content {
            flex-grow: 1;
        }
        .announcement-meta {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 5px;
        }
        .announcement-actions {
            margin-left: 20px;
            display: flex;
            gap: 10px;
        }
        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        .edit-btn {
            background-color: #ffc107; /* Yellow for Edit */
            color: #212529;
        }
        .edit-btn:hover {
            background-color: #e0a800;
        }
        .delete-btn {
            background-color: #dc3545; /* Red for Delete */
            color: white;
        }
        .delete-btn:hover {
            background-color: #c82333;
        }

        /* MODAL CSS */
        .modal {
            display: none; /* Hidden by default */
            position: fixed;
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5); /* Black w/ opacity */
            padding-top: 50px;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto; /* 5% from the top and centered */
            padding: 30px;
            border: 1px solid #888;
            width: 80%; /* Could be more or less, depending on screen size */
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .modal-content h3 {
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            margin-top: 0;
        }
        .modal-footer {
            text-align: right;
            padding-top: 15px;
            border-top: 1px solid #eee;
            margin-top: 20px;
        }
        .modal-footer button {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        .modal-save-btn {
            background-color: #28a745;
            color: white;
        }
        .modal-save-btn:hover {
            background-color: #1e7e34;
        }
        .modal-cancel-btn {
            background-color: #6c757d;
            color: white;
            margin-left: 10px;
        }
        .modal-cancel-btn:hover {
            background-color: #5a6268;
        }
        .modal-delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .modal-delete-btn:hover {
            background-color: #c82333;
        }
        .modal-textarea {
            width: 100%;
            min-height: 150px;
            margin-bottom: 15px;
        }
        /* --- FOOTER STYLES FIX --- */
        .app-footer {
            /* Keep the same style as the image: dark background */
            background-color: #222b35; 
            color: #9ca3af; /* Light gray text */
            padding: 40px 20px 20px; /* Top padding, side padding, bottom padding */
            font-size: 14px;
            /* Position footer correctly next to the fixed sidebar */
            margin-left: 250px; 
            width: calc(100% - 250px); 
            box-sizing: border-box; 
        }
        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .footer-column {
            width: 100%;
            max-width: 300px; /* Standard column width */
            margin-bottom: 30px;
        }
        .footer-column h3 {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: white; /* White header text */
            margin-top: 0;
        }
        .footer-column ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .footer-column ul li {
            margin-bottom: 8px;
        }
        .footer-column a {
            color: #9ca3af; /* Light gray link color */
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-column a:hover {
            color: white; /* White on hover */
        }
        .footer-copyright {
            text-align: center;
            border-top: 1px solid #374151; /* Darker line */
            padding-top: 15px;
            margin-top: 15px;
            font-size: 0.75rem; /* Smaller text */
            color: #6b7280; /* Darker gray for copyright */
        }
        @media (min-width: 768px) {
            .footer-column {
                width: auto;
                margin-bottom: 0;
            }
        }
        /* --- END FOOTER STYLES FIX --- */
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav>
        <h2>AU iTrace — Admin</h2>
        <ul>
            <li><a href="home-admin.php" class="active">🏠 Home</a></li>
            <li><a href="found-items-admin.php">📦 Found Items</a></li>
            <li><a href="manage-claim-requests.php">📄 Manage Claim Requests</a></li>
            <li><a href="status-of-items.php">ℹ️ Status of Items</a></li>
            <li><a href="user-accounts.php">🔒 User Account</a></li>
            <li><a href="admin-accounts.php">🛡️ Admin Accounts</a></li>
            <li><a href="admin-profile.php">👤 Admin Profile</a></li>
        </ul>
        <div class="sidebar-logout">
            <form method="POST" action="../logout.php">
                <button type="submit">Logout 🚪</button>
            </form>
        </div>
        </nav>

    <header class="top-header">
        <h1>Admin Dashboard</h1>
    </header>
    <div class="main-content">
        <h3>Welcome, <?= htmlspecialchars($username) ?></h3>

        <?php if (!$has_admin_activity): ?>
        
        <div class="new-admin-welcome">
            <h2>Welcome, <?= htmlspecialchars($username) ?>! 👋</h2>
            <p>This is the **initial** dashboard view. There is no system activity or history to display yet.</p>
            <p>To begin, post the first announcement below. Once the first announcement is posted by **any** admin, the full system statistics and recent announcements will be displayed for all administrators.</p>
        </div>

        <?php else: ?>

        <div class="dashboard-stats">
            
            <div class="stat-card found">
                <div class="icon">🔍</div>
                <div class="value"><?= htmlspecialchars($stats['found_items']) ?></div>
                <div class="label">Items Found</div>
            </div>

            <div class="stat-card returned">
                <div class="icon">✅</div>
                <div class="value"><?= htmlspecialchars($stats['returned_items']) ?></div>
                <div class="label">Returned Items</div>
            </div>

            <div class="stat-card pending">
                <div class="icon">⏳</div>
                <div class="value"><?= htmlspecialchars($stats['pending_claims']) ?></div>
                <div class="label">Pending Claims</div>
            </div>

            <div class="stat-card users">
                <div class="icon">👥</div>
                <div class="value"><?= htmlspecialchars($stats['active_users']) ?></div>
                <div class="label">Active Users</div>
            </div>
            
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Post an Announcement</h2>

            <?php if ($message): ?>
                <div class="message-box success" id="successMessageBox"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message-box error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <textarea name="message" rows="4" placeholder="Write your announcement here..." required></textarea>
                <button type="submit">Post Announcement 📢</button>
            </form>
        </div>

        <div class="card">
            <h2>Recent System Announcements (Last 5)</h2>
            
            <ul class="announcement-list">
                <?php if (empty($announcements)): ?>
                    <p style="color:#6c757d;">No announcements have been posted to the system yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $a): ?>
                        <li class="announcement-item">
                            <div class="announcement-content">
                                <strong><?= nl2br(htmlspecialchars($a['message'])) ?></strong>
                                <div class="announcement-meta" 
                                    data-message="<?= htmlspecialchars($a['message']) ?>">
                                    Posted on: <?= date('M d, Y H:i:s', strtotime($a['datecreated'])) ?>
                                </div>
                            </div>
                            <div class="announcement-actions">
                                <button type="button" class="action-btn edit-btn" 
                                    onclick="showEditModal(<?= $a['id'] ?>, '<?= rawurlencode(json_encode($a['message'])) ?>')">
                                    Edit ✏️
                                </button>
                                <button type="button" class="action-btn delete-btn" 
                                    onclick="showDeleteModal(<?= $a['id'] ?>)">
                                    Delete 🗑️
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <h3>Edit Announcement</h3>
        <form method="POST" action="">
            <input type="hidden" name="edit_announcement_id" id="edit_announcement_id" value="">
            
            <label for="edit_message">Announcement Message:</label>
            <textarea name="edit_message" id="edit_message" class="modal-textarea" required></textarea>
            
            <div class="modal-footer">
                <button type="submit" class="modal-save-btn">Save Changes</button>
                <button type="button" class="modal-cancel-btn" onclick="closeModal('editModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3>Confirm Deletion</h3>
        <p>Are you sure you want to delete this announcement (ID: <strong id="delete_announcement_id_display"></strong>)? This action cannot be undone.</p>
        
        <form method="POST" action="">
            <input type="hidden" name="delete_announcement_id" id="delete_announcement_id" value="">
            
            <div class="modal-footer">
                <button type="submit" class="modal-delete-btn">Yes, Delete It</button>
                <button type="button" class="modal-cancel-btn" onclick="closeModal('deleteModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<footer class="app-footer">
    <div class="footer-content">
        
        <div class="footer-column">
            <h3>AU iTrace</h3>
            <p style="margin: 0 0 10px 0;">
                A system for lost and found management for students and faculty.
            </p>
        </div>

        <div class="footer-column">
            <h3>Quick Links</h3>
            <ul>
                <li><a href="home-admin.php">Home</a></li>
                <li><a href="found-items-admin.php">Found Items</a></li>
                <li><a href="manage-claim-requests.php">Manage Claims</a></li>
                <li><a href="status-of-items.php">Status of Items</a></li>
            </ul>
        </div>

        <div class="footer-column">
            <h3>Resources</h3>
            <ul>
                <li><a href="#">User Guide</a></li>
                <li><a href="#">FAQs</a></li>
                <li><a href="#">Privacy Policy</a></li>
            </ul>
        </div>
    </div>
    
    <div class="footer-copyright">
        <p style="margin: 0;">
            &copy; 2024 AU iTrace. All Rights Reserved.
        </p>
    </div>
</footer>
<script>
    // Function to automatically hide the success message
    function hideSuccessMessage() {
        const msgBox = document.getElementById('successMessageBox');
        if (msgBox) {
            // Use setTimeout to wait for 3000 milliseconds (3 seconds)
            setTimeout(() => {
                // Set opacity to 0 to start the fade-out effect (due to CSS transition)
                msgBox.style.opacity = '0';
                
                // After the transition finishes (0.5s from CSS), remove the element completely
                setTimeout(() => {
                    msgBox.remove();
                }, 500);   
            }, 3000); // 3 seconds delay before starting fade out
        }
    }

    // Call the hide function when the page loads
    window.onload = hideSuccessMessage;

    // CRITICAL FIX: Updated showEditModal function
    function showEditModal(id, encodedMessage) {
        let message;
        
        try {
            // 1. URL Decode the string (undo rawurlencode)
            const uriDecoded = decodeURIComponent(encodedMessage);
            // 2. JSON Parse the resulting string (undo json_encode)
            message = JSON.parse(uriDecoded);
        } catch (e) {
            console.error("Error decoding announcement message:", e);
            // Fallback: use a data attribute if JavaScript decoding fails
            const itemElement = document.querySelector(`.announcement-item .announcement-meta[data-message][data-id="${id}"]`);
            // This fallback logic is complex and often fails. Stick to the decoded message.
            message = "Error loading message content. Check console for details."; 
        }
        
        // Set the ID and decoded message in the modal form
        document.getElementById('edit_announcement_id').value = id;
        document.getElementById('edit_message').value = message;
        
        // Show the modal
        document.getElementById('editModal').style.display = 'block';
    }

    function showDeleteModal(id) {
        // Set the ID in the hidden form field
        document.getElementById('delete_announcement_id').value = id;
        // Display the ID in the confirmation message
        document.getElementById('delete_announcement_id_display').textContent = id;
        
        // Show the modal
        document.getElementById('deleteModal').style.display = 'block';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    // Close the modal if the user clicks anywhere outside of it
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = "none";
        }
    }
</script>

</body>
</html>