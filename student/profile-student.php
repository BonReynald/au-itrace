<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

$studentID = $_SESSION['studentID'] ?? null;
$username = $_SESSION['username'];
$userData = [];
$studentName = '';
$message = '';
$userID = $_SESSION['userID'] ?? null; // Ensure userID is available

// Re-establish link if it was closed in a previous request
if (!isset($link) || !is_object($link)) {
    require_once '../config.php';
}

// ----------------------------------------------------------------------
// NOTIFICATION CONSTANT (COPIED FROM home-student.php)
const FIXED_NOTIF_TITLE = "Your Item Claim Request is scheduled for Physical Verification";
// ----------------------------------------------------------------------


if ($studentID) {
    // 1. Get system user data (including userID if not set in session)
    $stmt = $link->prepare("SELECT userID, studentID FROM tblsystemusers WHERE studentID = ?");
    $stmt->bind_param("s", $studentID);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $userData = $result->fetch_assoc();
        $userID = $userData['userID'];
        $_SESSION['userID'] = $userID; // Set userID in session
    }
    $stmt->close();

    // 2. Get full name from tblactivestudents
    $stmt2 = $link->prepare("SELECT name FROM tblactivestudents WHERE studentID = ?");
    $stmt2->bind_param("s", $studentID);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    if ($result2->num_rows === 1) {
        $row = $result2->fetch_assoc();
        $studentName = $row['name'];
    }
    $stmt2->close();
}


// ======================================================================
// ⚠️ ACTION: Handle AJAX request to clear notifications (Start) - COPIED FROM home-student.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_notifications') {
    if (isset($_SESSION['userID'])) {
        $clearUserID = $_SESSION['userID'];
        
        // IMPORTANT: Re-establish connection if it was closed previously
        if (!isset($link) || !is_object($link)) {
            require_once '../config.php';
        }
        
        $stmtClear = $link->prepare("UPDATE tblnotifications SET isread = 1 WHERE userID = ? AND isread = 0");
        $stmtClear->bind_param("i", $clearUserID);
        
        if ($stmtClear->execute()) {
            echo "Success";
            $stmtClear->close();
            // Close the connection explicitly for the AJAX exit
            $link->close();
            exit; // Stop execution after handling the AJAX request
        } else {
            http_response_code(500);
            echo "Database Error";
            $stmtClear->close();
            $link->close();
            exit;
        }
    } else {
        http_response_code(401);
        echo "Unauthorized";
        if (isset($link) && is_object($link)) { $link->close(); }
        exit;
    }
}
// ⚠️ ACTION: Handle AJAX request to clear notifications (End)
// ======================================================================

// --- Notification Handling Logic (COPIED FROM home-student.php) ---
$notifCount = 0;
$notifications = [];

if ($userID) {
    // 1. Get unread notification count
    $sqlNotifCount = "SELECT COUNT(*) AS count FROM tblnotifications WHERE userID = ? AND isread = 0";
    $stmtCount = $link->prepare($sqlNotifCount);
    $stmtCount->bind_param("i", $userID);
    $stmtCount->execute();
    $resultCount = $stmtCount->get_result();
    if ($row = $resultCount->fetch_assoc()) {
        $notifCount = $row['count'];
    }
    $stmtCount->close();

    // 2. Get latest 5 notifications
    $sqlNotifList = "
        SELECT 
            notifID, 
            adminmessage, 
            datecreated, 
            isread 
        FROM tblnotifications 
        WHERE userID = ? 
        ORDER BY datecreated DESC 
        LIMIT 5
    ";
    $stmtList = $link->prepare($sqlNotifList);
    $stmtList->bind_param("i", $userID);
    $stmtList->execute();
    $resultList = $stmtList->get_result();
    while ($row = $resultList->fetch_assoc()) {
        $row['notif_title'] = FIXED_NOTIF_TITLE; 
        $row['notif_message'] = $row['adminmessage']; 
        $notifications[] = $row;
    }
    $stmtList->close();
}
// --- End Notification Handling ---


// Handle password update (Original logic, simplified connection close)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    // Re-establish link if it was closed by notification logic
    if (!isset($link) || !is_object($link)) {
        require_once '../config.php';
    }

    if ($current && $new && $new === $confirm) {
        // Fetch current hashed password
        $check_stmt = $link->prepare("SELECT password FROM tblsystemusers WHERE studentID = ?");
        $check_stmt->bind_param("s", $studentID);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows === 1) {
            $row = $check_result->fetch_assoc();
            $hashedPassword = $row['password'];

            // Verify current password
            if (password_verify($current, $hashedPassword) || $current === $hashedPassword) {
                $newHashed = password_hash($new, PASSWORD_DEFAULT);

                // Update tblsystemusers
                $update1 = $link->prepare("UPDATE tblsystemusers SET password = ? WHERE studentID = ?");
                $update1->bind_param("ss", $newHashed, $studentID);

                // Update tblregistration
                $update2 = $link->prepare("UPDATE tblregistration SET password = ? WHERE studentID = ?");
                $update2->bind_param("ss", $newHashed, $studentID);

                // Update tblactivestudents
                $update3 = $link->prepare("UPDATE tblactivestudents SET password = ? WHERE studentID = ?");
                $update3->bind_param("ss", $newHashed, $studentID);

                if ($update1->execute() && $update2->execute() && $update3->execute()) {
                    $message = "Password updated successfully!";
                } else {
                    $message = "Failed to update password.";
                }
                $update1->close(); $update2->close(); $update3->close();
            } else {
                $message = "Current password is incorrect.";
            }
        } else {
            $message = "User not found.";
        }
        $check_stmt->close();
    } else {
        $message = "Passwords do not match or are empty.";
    }
}

// Only close the connection if it wasn't closed by the AJAX handler
if (!isset($_POST['action'])) {
    if (isset($link) && is_object($link)) {
        $link->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Student Profile - AU iTrace</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet' />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* CSS styles for layout (Copied from home-student.php) */
        body { font-family: 'Poppins', sans-serif;background-color: #f3f4f6; margin: 0; }
        nav.sidebar { width: 250px; background-color: #004ea8; color: white; padding: 20px 0 70px 0; display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; }
        nav.sidebar h2 { padding: 0 20px; font-size: 20px; margin-bottom: 30px; }
        nav.sidebar ul { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        nav.sidebar ul li a { display: flex; align-items: center; padding: 12px 20px; color: white; text-decoration: none; }
        nav.sidebar ul li a:hover, nav.sidebar ul li a.active { background-color: #2980b9; }
        .logout-container { position: fixed; bottom: 20px; left: 0; width: 250px; padding: 0 20px; }
        form.logout-form button { width: 100%; background-color: #ef4444; border: none; color: white; padding: 12px 0; border-radius: 0.375rem; font-size: 1rem; cursor: pointer; font-weight: 700; transition: background-color 0.2s; }
        form.logout-form button:hover { background-color: #dc2626; }
        .main-wrapper { display: flex; min-height: 100vh; }
        .main-content { margin-left: 250px; flex: 1; padding: 1rem 2rem; min-height: 100vh; display: flex; flex-direction: column; }
        .topnav { background-color: #004ea8; padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; color: white; font-weight: 700; font-size: 1.5rem; border-radius: 0.5rem; box-shadow: 0 2px 8px rgb(0 0 0 / 0.15); margin-bottom: 1.5rem; user-select: none; }
        .content-wrapper { background-color: white; padding: 1.5rem 2rem; border-radius: 0.5rem; flex-grow: 1; box-shadow: 0 4px 12px rgb(0 0 0 / 0.05); overflow-y: auto; }
        
        /* Notification styles (Copied from home-student.php) */
        .notif-btn { background: none; border: none; cursor: pointer; font-size: 1.75rem; position: relative; color: white; }
        .notif-badge { position: absolute; top: -6px; right: -10px; background-color: #ef4444; color: white; border-radius: 9999px; padding: 0 6px; font-size: 0.75rem; font-weight: 700; line-height: 1; user-select: none; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 60px; width: 320px; background: white; border: 1px solid #ccc; border-radius: 0.5rem; box-shadow: 0 8px 16px rgba(0,0,0,0.1); z-index: 1000; max-height: 320px; overflow-y: auto; }
        .notif-dropdown.show { display: block; }
        .notif-dropdown h4 { margin: 0; padding: 0.75rem 1rem; background: #004ea8; color: white; border-radius: 0.5rem 0.5rem 0 0; font-weight: 600; font-size: 1rem; }
        .notif-item { padding: 0.75rem 1rem; border-bottom: 1px solid #eee; font-size: 0.875rem; color: #333; line-height: 1.2; display: flex; justify-content: space-between; align-items: center; background-color: white; }
        .notif-item.unread { background-color: #f0f8ff; border-left: 3px solid #004ea8; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item small { color: #666; font-size: 0.75rem; display: block; margin-top: 4px; }
        .view-btn { background-color: #10b981; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; border: none; cursor: pointer; transition: background-color 0.2s; }
        .view-btn:hover { background-color: #059669; }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: none; justify-content: center; align-items: center; z-index: 2000; }
        .modal-content { 
            background: white; 
            padding: 30px; 
            border-radius: 8px; 
            width: 90%; 
            max-width: 700px; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); 
            position: relative; 
        }
        .close-btn { position: absolute; top: 10px; right: 15px; font-size: 1.5rem; font-weight: bold; color: #aaa; cursor: pointer; }
        .close-btn:hover { color: #333; }
        
        /* Profile specific styles (from previous fix) */
        .profile-container {
            display: flex;
            gap: 1.5rem; 
            flex-wrap: wrap;
        }
        .box {
            background: white;
            padding: 1.5rem; 
            border-radius: 0.5rem;
            flex: 1 1 350px; 
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb; 
        }
        .box h3 {
            font-size: 1.25rem; 
            font-weight: 700; 
            margin-bottom: 1rem; 
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #004ea8;
            color: #004ea8;
        }
        .info p {
            margin: 0.75rem 0;
            font-size: 1rem;
            line-height: 1.5;
            color: #4b5563;
        }
        .info strong {
            display: block;
            color: #1f2937;
            font-weight: 600;
        }
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            box-sizing: border-box;
        }
        .update-btn {
            background: #10b981; 
            color: white;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 0.375rem;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .update-btn:hover {
            background-color: #059669;
        }
        .message {
            background: #d1fae5; 
            color: #065f46; 
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            border: 1px solid #a7f3d0;
            font-weight: 500;
            animation: fadeOut 3s forwards;
        }
        @keyframes fadeOut {
            0% { opacity: 1; }
            90% { opacity: 1; }
            100% { opacity: 0; display: none; }
        }
        
        /* === FOOTER FIX === */
        .app-footer {
            margin-left: 250px;
            width: calc(100% - 250px); 
            box-sizing: border-box; 
        }
        /* === END FOOTER FIX === */
    </style>
</head>
<body>
    
<div class="main-wrapper">
    <nav class="sidebar" role="navigation" aria-label="Sidebar Navigation">
        <h2>AU iTrace — Student</h2>
        <ul>
            <li><a href="home-student.php">🏠 Home</a></li>
            <li><a href="found-items-student.php">📦 Found Items</a></li>
            <li><a href="item-status-student.php">🔍 Item Status</a></li>
            <li><a href="help-and-info.php">❓ Help & Info</a></li>
            <li><a href="privacy-policy.php">🔒 Privacy Policy</a></li>
            <li><a href="profile-student.php" class="active"><i class='bx bxs-user' style="margin-right: 12px;"></i> Profile</a></li>
        </ul>

        <div class="logout-container">
            <form method="POST" action="../logout.php" class="logout-form" role="form">
                <button type="submit" aria-label="Logout">Logout</button>
            </form>
        </div>
    </nav>

    <div class="main-content">
        <div class="topnav" role="banner">
            <div>My Profile</div>
            
            <div style="position: relative;">
                <button class="notif-btn" onclick="toggleDropdown()" aria-label="Toggle Notifications" aria-expanded="false" aria-controls="notifDropdown">
                    <i class='bx bxs-bell'></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-badge" id="notifBadge" aria-live="polite" aria-atomic="true"><?php echo $notifCount; ?></span>
                    <?php endif; ?>
                </button>
                
                <div class="notif-dropdown" id="notifDropdown" role="region" aria-live="polite" aria-label="Notifications List" tabindex="-1">
                    <h4>🔔 Notifications</h4>
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php $isUnread = $notif['isread'] == 0 ? 'unread' : ''; ?>
                            <div class="notif-item <?= $isUnread ?>" tabindex="0">
                                <div>
                                    <strong><?php echo htmlspecialchars($notif['notif_title']); ?></strong>
                                    <small><?php echo date("M d, Y h:i A", strtotime($notif['datecreated'])); ?></small>
                                </div>
                                
                                <?php
                                // Clean the title to remove newlines/carriage returns, if any exist.
                                $clean_title = str_replace(["\r", "\n"], ' ', $notif['notif_title']);
                                
                                // For the multi-line message, use json_encode for safety.
                                $safe_message = htmlspecialchars(json_encode($notif['notif_message']), ENT_QUOTES, 'UTF-8');
                                ?>

                                <button 
                                    class="view-btn" 
                                    onclick="showNotificationDetails(event, 
                                        '<?php echo htmlspecialchars($clean_title, ENT_QUOTES); ?>', 
                                        '<?php echo $safe_message; ?>'
                                    )"
                                    aria-label="View details for notification"
                                >View</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-item" tabindex="0">No notifications</div>
                    <?php endif; ?>
                </div>
            </div>
            </div>

        <div class="content-wrapper" role="main">
            <h1 class="text-2xl font-semibold mb-3">My Profile</h1>
            <p>View your information and manage your account security here.</p>
            
            <div class="profile-container mt-6">
                <div class="box">
                    <h3>Student Information</h3>
                    <div class="info">
                        <p><strong>Student Name:</strong><br> <?= htmlspecialchars($studentName ?: 'N/A') ?></p>
                        <p><strong>Student ID:</strong><br> <?= htmlspecialchars($studentID ?: 'N/A') ?></p>
                        <p><strong>Username:</strong><br> <?= htmlspecialchars($username ?: 'N/A') ?></p>
                    </div>
                </div>

                <div class="box">
                    <h3>Change Password</h3>
                    <?php if (!empty($message)): ?>
                        <div class="message" id="message"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>

                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required>

                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>

                        <button type="submit" name="update_password" class="update-btn">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="notificationModal" class="modal" role="dialog" aria-labelledby="modalTitle" aria-modal="true">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()" aria-label="Close modal">&times;</span>
        <h3 id="modalTitle" class="text-xl font-bold mb-3">Notification Details</h3>
        
        <strong class="block mb-2 text-lg" id="modal-notif-title"></strong>
        <div id="modal-notif-message" class="whitespace-pre-wrap text-gray-700 p-3 bg-gray-50 border border-gray-200 rounded"></div>
        
        <div class="mt-4 text-sm text-gray-500">
            *This is the full message sent by the Office of Student Affairs regarding your claim.
        </div>
    </div>
</div>

<script>
// --- Modal and Dropdown Functions (COPIED AND ADAPTED FROM home-student.php) ---

// Script to fade out the password update message after 3 seconds
setTimeout(() => {
    const msg = document.getElementById("message");
    if (msg) {
        // Remove it from the DOM instead of just hiding (prevents layout shift)
        msg.parentNode.removeChild(msg); 
    }
}, 3000);


function toggleDropdown() {
    const dropdown = document.getElementById('notifDropdown');
    const button = document.querySelector('.notif-btn');
    const expanded = button.getAttribute('aria-expanded') === 'true';
    
    // Toggle the dropdown visibility
    button.setAttribute('aria-expanded', !expanded);
    dropdown.classList.toggle('show');

    // If opening the dropdown, clear the unread count via AJAX
    if (!expanded) {
        clearNotifCount();
        dropdown.focus();
    }
}

/**
 * Shows the notification details in a modal.
 */
function showNotificationDetails(event, title, message) {
    // Stop the click event from closing the dropdown immediately
    event.stopPropagation();
    
    try {
        let decodedMessage;
        try {
            // Decode the JSON string passed from PHP
            decodedMessage = JSON.parse(message);
        } catch (e) {
            // Fallback for non-JSON strings
            decodedMessage = message;
        }

        // Fill modal content
        document.getElementById('modalTitle').textContent = 'Notification Details';
        document.getElementById('modal-notif-title').textContent = title;
        // The whitespace-pre-wrap CSS will handle the formatting of the text content
        document.getElementById('modal-notif-message').textContent = decodedMessage;

        // Show the modal
        document.getElementById('notificationModal').style.display = 'flex';
    } catch (e) {
        console.error("Error displaying notification data:", e);
    }
}

function closeModal() {
    document.getElementById('notificationModal').style.display = 'none';
}

// Close dropdown if clicked outside
document.addEventListener('click', function (e) {
    const dropdown = document.getElementById('notifDropdown');
    const button = document.querySelector('.notif-btn');
    const modal = document.getElementById('notificationModal');
    
    // Check if the click is outside the dropdown AND the notification button AND the modal
    if (dropdown && button && modal && !dropdown.contains(e.target) && !button.contains(e.target) && modal.style.display !== 'flex') {
        dropdown.classList.remove('show');
        button.setAttribute('aria-expanded', 'false');
    }
});

// --- AJAX Function to Clear Notification Count (Points to self) ---

function clearNotifCount() {
    const notifBadge = document.getElementById('notifBadge');
    if (!notifBadge) return;
    
    const xhr = new XMLHttpRequest();
    // The AJAX request points back to this same file (profile-student.php)
    xhr.open('POST', 'profile-student.php', true); 
    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
    
    xhr.onload = function () {
        if (xhr.status === 200 && xhr.responseText.trim() === 'Success') {
            // Success: Remove the badge from the UI
            notifBadge.remove();
            
            // Visually mark items as read
            document.querySelectorAll('.notif-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
        }
    };
    
    // Send the POST data that the PHP block at the top of the file checks for
    xhr.send('action=clear_notifications'); 
}
</script>

<footer class="app-footer" style="
        background-color: #222b35; 
        color: #f3f4f6; /* Light text color */
        padding: 30px 20px; 
        font-family: 'Poppins', sans-serif; /* Fallback font */
        box-sizing: border-box;
    ">
        <div style="
            max-width: 1200px; 
            margin-left: auto; 
            margin-right: auto; 
            display: flex; 
            flex-wrap: wrap; /* Allows wrapping on smaller screens */
            justify-content: space-between;
        ">
            
            <div style="width: 100%; max-width: 300px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">AU iTrace</h3>
                <p style="font-size: 0.875rem; line-height: 1.5; margin-bottom: 20px;">
                    Arellano University's Digital Lost and Found System
                </p>
                <div style="font-size: 1.25rem;">
                    <a href="#" style="color: #f3f4f6; text-decoration: none; margin-right: 15px;">
                        f 
                    </a>
                    <a href="#" style="color: #f3f4f6; text-decoration: none; margin-right: 15px;">
                        &#x1F426; 
                    </a>
                    <a href="#" style="color: #f3f4f6; text-decoration: none;">
                        &#x1F4F7;
                    </a>
                </div>
            </div>

            <div style="width: 100%; max-width: 200px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">Quick Links</h3>
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.875rem;">
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">Home</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">Found Items</a>
                    </li>
                </ul>
            </div>

            <div style="width: 100%; max-width: 200px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">Resources</h3>
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.875rem;">
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">User Guide</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">FAQs</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">Privacy Policy</a>
                    </li>
                </ul>
            </div>
        </div>
        
        <div style="text-align: center; border-top: 1px solid #374151; padding-top: 15px; margin-top: 15px; font-size: 0.75rem; color: #9ca3af;">
            <p style="margin: 0;">
                Copyright &copy; 2025 AU iTrace. All Rights Reserved.
            </p>
        </div>
    </footer>
</html>