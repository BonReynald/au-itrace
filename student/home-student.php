<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

$username = $_SESSION['username'];

// Get user info (studentID and userID)
// Use a new connection or ensure the old one is closed/reset if the AJAX handler didn't close it
if (!isset($link) || !is_object($link)) {
    require_once '../config.php';
}

$stmtUser = $link->prepare("SELECT userID, studentID FROM tblsystemusers WHERE username = ?");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();

if ($resultUser->num_rows === 0) {
    die("Student not found.");
}

$user = $resultUser->fetch_assoc();
$studentID = $user['studentID'];
$userID = $user['userID'];

// Store userID in session for AJAX calls
$_SESSION['userID'] = $userID;

// FIXED NOTIFICATION TITLE STRING
const FIXED_NOTIF_TITLE = "Your Item Claim Request is scheduled for Physical Verification";

// ======================================================================
// ⚠️ ACTION: Handle AJAX request to clear notifications (Start)
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
        $link->close();
        exit;
    }
}
// ⚠️ ACTION: Handle AJAX request to clear notifications (End)
// ======================================================================

// --- Announcement Fetching Logic (UNCHANGED) ---
$announcements = [];
// Fetch the latest 5 announcements from the admin's table
$sqlAnnouncements = "SELECT message, datecreated FROM tblannouncements ORDER BY datecreated DESC LIMIT 5";
$resultAnnouncements = mysqli_query($link, $sqlAnnouncements);

if ($resultAnnouncements && mysqli_num_rows($resultAnnouncements) > 0) {
    while ($row = mysqli_fetch_assoc($resultAnnouncements)) {
        $announcements[] = $row;
    }
    mysqli_free_result($resultAnnouncements);
}
// --- End Announcement Fetching Logic ---


// --- Notification Handling Logic ---
$notifCount = 0;
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
$notifications = [];
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
    // Add the fixed title to the PHP array for easy display
    $row['notif_title'] = FIXED_NOTIF_TITLE; 
    // Rename the message field to a generic name for consistency in HTML
    $row['notif_message'] = $row['adminmessage']; 
    $notifications[] = $row;
}
$stmtList->close();
// --- End Notification Handling ---


// Initialize claim counts
$pending = 0;
$review = 0;
$claimed = 0;
$declined = 0;

$sql = "
    SELECT 
        cr.claimID,
        cr.status AS claim_status,
        fi.status AS founditem_status,
        is1.status AS item_status
    FROM tblclaimrequests cr
    LEFT JOIN tblfounditems fi ON cr.foundID = fi.foundID
    LEFT JOIN tblitemstatus is1 ON cr.claimID = is1.claimID
    WHERE cr.studentID = ?
";

// Prepare and execute
$stmt = $link->prepare($sql);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $itemStatus = 'Pending'; // default

        // Priority check
        if (isset($row['item_status']) && $row['item_status'] === 'Returned') {
            $itemStatus = 'Returned';
        } elseif ($row['claim_status'] === 'Approved' && $row['founditem_status'] === 'Physical Verification') {
            $itemStatus = 'Physical Verification';
        } elseif ($row['claim_status'] === 'Declined' || (isset($row['item_status']) && $row['item_status'] === 'Declined')) {
            $itemStatus = 'Declined';
        } else {
            // If none of the above, keep 'Pending'
            $itemStatus = 'Pending';
        }

        // Count based on itemStatus
        switch ($itemStatus) {
            case 'Pending':
                $pending++;
                break;
            case 'Physical Verification':
                $review++;
                break;
            case 'Returned':
                $claimed++;
                break;
            case 'Declined':
                $declined++;
                break;
            default:
                $pending++;
        }
    }
}

$stmt->close();
// Only close the connection if it wasn't closed by the AJAX handler above
if (!isset($_POST['action'])) {
    $link->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Welcome to AU iTrace</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet' />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* CSS styles remain the same for brevity */
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
        .notif-btn { background: none; border: none; cursor: pointer; font-size: 1.75rem; position: relative; color: white; }
        .notif-badge { position: absolute; top: -6px; right: -10px; background-color: #ef4444; color: white; border-radius: 9999px; padding: 0 6px; font-size: 0.75rem; font-weight: 700; line-height: 1; user-select: none; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 60px; width: 320px; background: white; border: 1px solid #ccc; border-radius: 0.5rem; box-shadow: 0 8px 16px rgba(0,0,0,0.1); z-index: 1000; max-height: 320px; overflow-y: auto; }
        .notif-dropdown.show { display: block; }
        .notif-dropdown h4 { margin: 0; padding: 0.75rem 1rem; background: #004ea8; color: white; border-radius: 0.5rem 0.5rem 0 0; font-weight: 600; font-size: 1rem; }
        .notif-item { padding: 0.75rem 1rem; border-bottom: 1px solid #eee; font-size: 0.875rem; color: #333; line-height: 1.2; display: flex; justify-content: space-between; align-items: center; background-color: white; }
        .notif-item.unread { background-color: #f0f8ff; border-left: 3px solid #004ea8; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item small { color: #666; font-size: 0.75rem; display: block; margin-top: 4px; }
        .content-wrapper { background-color: white; padding: 1.5rem 2rem; border-radius: 0.5rem; flex-grow: 1; box-shadow: 0 4px 12px rgb(0 0 0 / 0.05); overflow-y: auto; }
        .status-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-top: 1.5rem; }
        .card { background-color: #fef3c7; padding: 1.25rem; border-radius: 0.5rem; text-align: center; font-size: 1.25rem; font-weight: 600; color: #92400e; text-decoration: none; user-select: none; transition: background-color 0.2s; }
        .card:hover { background-color: #fde68a; }
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
        
        /* ANNOUNCEMENT CARD STYLES (MATCHING THE IMAGE) */
        .announcements {
            margin-top: 1.5rem;
            background-color: #e6f7ff; /* Very light blue background */
            border: 2px solid #004ea8; /* Blue border */
            border-radius: 0.5rem;  
            padding: 0; /* Remove padding here, move it inside */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); /* Clear shadow */
        }
        
        .announcements-header {
            background-color: #004ea8; /* Dark blue header background */
            color: white;
            padding: 1rem;
            border-radius: 0.375rem 0.375rem 0 0; /* Rounded top corners */
            font-size: 1.25rem; /* Large font size */
            font-weight: 700;
            display: flex;
            align-items: center;
        }

        .announcement-list-container {
            max-height: 250px; /* Set a fixed height */
            overflow-y: auto; /* Make it scrollable */
            padding: 1rem; /* Padding for the content area */
        }
        
        .announcement-item {
            padding: 0.75rem 0;
            border-bottom: 1px dashed #c0e0f0; /* Light dashed separator */
            position: relative;
        }
        .announcement-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .announcement-item p {
            margin: 0;
            font-size: 1rem;
            line-height: 1.5;
            color: #1f2937; /* Dark text */
            white-space: pre-wrap;
            font-weight: 500;
        }
        .announcement-item small {
            display: block;
            margin-top: 5px;
            font-size: 0.75rem;
            color: #4a5568; /* Slightly darker gray for date */
            font-weight: 400;
            text-align: right;
            padding-right: 5px; /* space from the edge */
        }
        .announcement-empty {
            padding: 1rem;
            text-align: center;
            color: #4a5568;
            font-style: italic;
        }
        
        /* === FOOTER FIX === */
        /* Apply the same margin-left as the main content to clear the fixed sidebar */
        .app-footer {
            margin-left: 250px; /* Same width as nav.sidebar */
            /* Calculate remaining width */
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
            <li><a href="home-student.php" class="active">🏠 Home</a></li>
            <li><a href="found-items-student.php">📦 Found Items</a></li>
            <li><a href="item-status-student.php">🔍 Item Status</a></li>
            <li><a href="help-and-info.php">❓ Help & Info</a></li>
            <li><a href="privacy-policy.php">🔒 Privacy Policy</a></li>
            <li><a href="profile-student.php"><i class='bx bxs-user' style="margin-right: 12px;"></i> Profile</a></li>
        </ul>

        <div class="logout-container">
            <form method="POST" action="../logout.php" class="logout-form" role="form">
                <button type="submit" aria-label="Logout">Logout</button>
            </form>
        </div>
    </nav>

    <div class="main-content">
        <div class="topnav" role="banner">
            <div>Welcome to AU iTrace</div>
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
            <h1 class="text-2xl font-semibold mb-3">Dashboard Overview</h1>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>! This is your student dashboard. Keep track of your claims and updates here.</p>

            <section class="announcements">
                <div class="announcements-header">
                    <i class='bx bxs-megaphone text-3xl mr-3'></i> Official Announcements
                </div>
                
                <div class="announcement-list-container">
                    <?php if (count($announcements) > 0): ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-item">
                                <p><?php echo nl2br(htmlspecialchars($announcement['message'])); ?></p>
                                <small>Posted: <?php echo date("M d, Y h:i A", strtotime($announcement['datecreated'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="announcement-empty">
                            No official announcements at this time.
                        </div>
                    <?php endif; ?>
                </div>
            </section>
            <section class="status-cards mt-6" aria-label="Claim status overview">
                <a href="item-status-student.php" class="card" role="link" tabindex="0" aria-label="Pending Claims: <?php echo $pending; ?>">
                    ⏳<br>Pending Claims: <?php echo $pending; ?>
                </a>
                <a href="item-status-student.php" class="card" role="link" tabindex="0" aria-label="Physical Review: <?php echo $review; ?>">
                    🔍<br>Physical Review: <?php echo $review; ?>
                </a>
                <a href="item-status-student.php" class="card" role="link" tabindex="0" aria-label="Items Claimed: <?php echo $claimed; ?>">
                    ✅<br>Items Claimed: <?php echo $claimed; ?>
                </a>
                <a href="item-status-student.php" class="card" role="link" tabindex="0" aria-label="Items Declined: <?php echo $declined; ?>">
                    ❌<br>Items Declined: <?php echo $declined; ?>
                </a>
            </section>
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
// --- Modal and Dropdown Functions ---

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
 * title and message are now standard, HTML-safe strings.
 */
function showNotificationDetails(event, title, message) {
    // Stop the click event from closing the dropdown immediately
    event.stopPropagation();
    
    try {
        // Since the message is JSON encoded in PHP and passed here, we need to decode it.
        // We use JSON.parse to handle any embedded quotes or special characters safely.
        let decodedMessage;
        try {
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
        // Do NOT use alert(), as per instruction
        // Instead, log the error and use a console message or a custom message box if needed.
        // For now, logging to console as instructed for error handling.
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
    if (!dropdown.contains(e.target) && !button.contains(e.target) && modal.style.display !== 'flex') {
        dropdown.classList.remove('show');
        button.setAttribute('aria-expanded', 'false');
    }
});

// --- AJAX Function to Clear Notification Count (Points to self) ---

function clearNotifCount() {
    const notifBadge = document.getElementById('notifBadge');
    if (!notifBadge) return;
    
    const xhr = new XMLHttpRequest();
    // The AJAX request points back to this same file (home-student.php)
    xhr.open('POST', 'home-student.php', true); 
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

</body>

<!-- 
    CHANGE: Added the class="app-footer" and removed the conflicting inline 
    width: 100%; style, allowing the new CSS class to handle the offset.
-->
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
            
            <!-- Column 1: AU iTrace Info -->
            <div style="width: 100%; max-width: 300px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">AU iTrace</h3>
                <p style="font-size: 0.875rem; line-height: 1.5; margin-bottom: 20px;">
                    Arellano University's Digital Lost and Found System
                </p>
                <!-- Social Media Icons (using Emojis/Text for simplicity) -->
                <div style="font-size: 1.25rem;">
                    <!-- Facebook -->
                    <a href="#" style="color: #f3f4f6; text-decoration: none; margin-right: 15px;">
                        f 
                    </a>
                    <!-- Twitter / X -->
                    <a href="#" style="color: #f3f4f6; text-decoration: none; margin-right: 15px;">
                        &#x1F426; 
                    </a>
                    <!-- Instagram -->
                    <a href="#" style="color: #f3f4f6; text-decoration: none;">
                        &#x1F4F7;
                    </a>
                </div>
            </div>

            <!-- Column 2: Quick Links -->
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

            <!-- Column 3: Resources -->
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
        
        <!-- Thin Copyright Bar (Added back for completeness based on previous context) -->
        <div style="text-align: center; border-top: 1px solid #374151; padding-top: 15px; margin-top: 15px; font-size: 0.75rem; color: #9ca3af;">
            <p style="margin: 0;">
                Copyright &copy; 2025 AU iTrace. All Rights Reserved.
            </p>
        </div>
    </footer>



</html>
