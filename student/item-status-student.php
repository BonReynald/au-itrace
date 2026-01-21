<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
    // Redirect to login page
    header("Location: au_itrace_portal.php?tab=login");
    exit(); // Stop script execution after redirect
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

// FIXED NOTIFICATION TITLE STRING (Copied from home-student.php)
const FIXED_NOTIF_TITLE = "Your Item Claim Request is scheduled for Physical Verification";


// ======================================================================
// ⚠️ ACTION: Handle AJAX request to clear notifications (Start - Copied from home-student.php)
// The item-status page now handles its own AJAX for clearing notifications, pointing to itself.
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

// --- Notification Handling Logic (Copied from home-student.php) ---
$notifCount = 0;
// 1. Get unread notification count
$sqlNotifCount = "SELECT COUNT(*) AS count FROM tblnotifications WHERE userID = ? AND isread = 0";

// IMPORTANT: Re-establish connection for standard page load if it was closed by an earlier POST
if (!isset($link) || !is_object($link)) {
    require_once '../config.php';
}

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


// Handle claim cancellation if form is submitted (Original Logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_claim']) && isset($_POST['claimID'])) {
    // Since this runs before the notification logic, the $link connection should still be open.
    // However, if the notification AJAX ran, it might be closed. We ensure it's open.
    if (!isset($link) || !is_object($link)) {
        require_once '../config.php';
    }

    if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
        header("Location: au_itrace_portal.php?tab=login");
        exit;
    }

    $claimID = $_POST['claimID'];
    $username = $_SESSION['username'];

    // Verify ownership of the claim and its status
    $verifyStmt = $link->prepare("
        SELECT cr.claimID
        FROM tblclaimrequests cr
        INNER JOIN tblsystemusers su ON cr.studentID = su.studentID
        WHERE cr.claimID = ? AND su.username = ? AND cr.status = 'pending'
    ");
    $verifyStmt->bind_param("ss", $claimID, $username);
    $verifyStmt->execute();
    $result = $verifyStmt->get_result();

    if ($result && $result->num_rows === 1) {
        // Delete claim request
        $deleteStmt = $link->prepare("DELETE FROM tblclaimrequests WHERE claimID = ?");
        $deleteStmt->bind_param("s", $claimID);
        $deleteStmt->execute();
        $deleteStmt->close();

        // Optionally delete related status
        $cleanupStmt = $link->prepare("DELETE FROM tblitemstatus WHERE claimID = ?");
        $cleanupStmt->bind_param("s", $claimID);
        $cleanupStmt->execute();
        $cleanupStmt->close();
    }

    $verifyStmt->close();
    $link->close(); // Close connection after use in POST handler

    // Redirect to avoid resubmission
    header("Location: item-status-student.php");
    exit;
}

// Data fetching logic for the item status display
// IMPORTANT: Re-establish connection for standard page load if it was closed by a POST handler
if (!isset($link) || !is_object($link)) {
    require_once '../config.php';
}

// Updated SQL to consider tblitemstatus for 'Returned' and claim status for others
$sql = "
    SELECT
        cr.claimID,
        cr.datesubmitted,
        cr.foundID,
        fi.itemname,
        COALESCE(
            (SELECT status FROM tblitemstatus WHERE claimID = cr.claimID LIMIT 1),
            'No Status'
        ) AS item_status_in_tblitemstatus,
        cr.status AS claim_status,
        fi.status AS founditem_status
    FROM tblclaimrequests cr
    JOIN tblfounditems fi ON cr.foundID = fi.foundID
    WHERE cr.studentID = ?
    ORDER BY cr.datesubmitted DESC
";

$stmt = $link->prepare($sql);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$result = $stmt->get_result();

$pending_claims = [];
$physical_review = [];
$claimed_items = [];
$declined_items = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Determine effective status based on your requirements:
        $effective_status = 'Pending'; // default

        if ($row['item_status_in_tblitemstatus'] === 'Returned') {
            $effective_status = 'Returned';
        } elseif (strtolower($row['claim_status']) === 'declined') {
            $effective_status = 'Declined';
        } elseif (strtolower($row['claim_status']) === 'approved' && strtolower($row['founditem_status']) === 'physical verification') {
            $effective_status = 'Physical Verification';
        } elseif (strtolower($row['claim_status']) === 'pending') {
            $effective_status = 'Pending';
        }


        // Now group based on effective status
        switch ($effective_status) {
            case 'Pending':
                $pending_claims[] = $row;
                break;
            case 'Physical Verification':
                $physical_review[] = $row;
                break;
            case 'Returned':
                $claimed_items[] = $row;
                break;
            case 'Declined':
                $declined_items[] = $row;
                break;
        }
    }
}
$stmt->close();
// Only close the connection if it wasn't closed by a POST handler
if (!isset($_POST['action']) && !isset($_POST['cancel_claim'])) {
    $link->close();
}


$count_pending = count($pending_claims);
$count_review = count($physical_review);
$count_claimed = count($claimed_items);
$count_declined = count($declined_items);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Item Status - AU iTrace</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* CSS styles copied from home-student.php for consistent layout */
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
        
        /* Topnav and Notification Styles from home-student.php */
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
        .content-wrapper { background-color: white; padding: 1.5rem 2rem; border-radius: 0.5rem; flex-grow: 1; box-shadow: 0 4px 12px rgb(0 0 0 / 0.05); overflow-y: auto; }
        
        /* Item Status Cards specific styles (Enhanced for clarity) */
        .status-card-item {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .status-card-item > div {
            min-height: 150px;
        }

        /* === FOOTER FIX === */
        .app-footer {
            margin-left: 250px; /* Same width as nav.sidebar */
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
            <li><a href="item-status-student.php" class="active">🔍 Item Status</a></li>
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
            <div>Item Status Tracker</div>
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
            <h1 class="text-2xl font-semibold mb-3">Claim Status</h1>
            <p class="text-gray-600 mb-6">Track your claim requests for lost items here. You can cancel pending requests at any time.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 status-card-item">
                
                <div class="bg-white p-5 rounded-lg shadow-lg border-l-4 border-yellow-500">
                    <h2 class="text-lg font-semibold text-yellow-600 mb-3 flex justify-between items-center">
                        Pending Claims ⏳
                        <span class="text-xl font-bold"><?= $count_pending ?></span>
                    </h2>
                    <?php if ($count_pending > 0): ?>
                        <div class="max-h-64 overflow-y-auto">
                            <?php foreach ($pending_claims as $item): ?>
                                <div class="border p-3 rounded mb-3 bg-gray-50">
                                    <div class="font-semibold text-sm"><?= htmlspecialchars($item['itemname']) ?></div>
                                    <div class="text-xs text-gray-500 mb-2">Status: Pending</div>
                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to cancel the claim for <?= htmlspecialchars($item['itemname']) ?>?');">
                                        <input type="hidden" name="cancel_claim" value="1">
                                        <input type="hidden" name="claimID" value="<?= htmlspecialchars($item['claimID']) ?>">
                                        <button type="submit" class="mt-1 px-3 py-1 bg-red-500 text-white rounded hover:bg-red-600 text-xs font-medium">
                                            Cancel Claim
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-gray-400 text-center py-4">No pending claims.</div>
                    <?php endif; ?>
                </div>

                <div class="bg-white p-5 rounded-lg shadow-lg border-l-4 border-blue-500">
                    <h2 class="text-lg font-semibold text-blue-600 mb-3 flex justify-between items-center">
                        Physical Review 🔍
                        <span class="text-xl font-bold"><?= $count_review ?></span>
                    </h2>
                    <?php if ($count_review > 0): ?>
                        <div class="max-h-64 overflow-y-auto">
                            <?php foreach ($physical_review as $item): ?>
                                <div class="border p-3 rounded mb-3 bg-gray-50">
                                    <div class="font-semibold text-sm"><?= htmlspecialchars($item['itemname']) ?></div>
                                    <div class="text-xs text-blue-500">Status: Approved - Proceed to OSA</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-gray-400 text-center py-4">No items under review.</div>
                    <?php endif; ?>
                </div>

                <div class="bg-white p-5 rounded-lg shadow-lg border-l-4 border-green-500">
                    <h2 class="text-lg font-semibold text-green-600 mb-3 flex justify-between items-center">
                        Claimed Items ✅
                        <span class="text-xl font-bold"><?= $count_claimed ?></span>
                    </h2>
                    <?php if ($count_claimed > 0): ?>
                        <div class="max-h-64 overflow-y-auto">
                            <?php foreach ($claimed_items as $item): ?>
                                <div class="border p-3 rounded mb-3 bg-gray-50 flex justify-between items-center">
                                    <div>
                                        <div class="font-semibold text-sm"><?= htmlspecialchars($item['itemname']) ?></div>
                                        <div class="text-xs text-green-500">Status: Returned</div>
                                    </div>
                                    <span class="text-green-500 text-xl">✔</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-gray-400 text-center py-4">No claimed items yet.</div>
                    <?php endif; ?>
                </div>

                <div class="bg-white p-5 rounded-lg shadow-lg border-l-4 border-red-500">
                    <h2 class="text-lg font-semibold text-red-600 mb-3 flex justify-between items-center">
                        Declined Items ❌
                        <span class="text-xl font-bold"><?= $count_declined ?></span>
                    </h2>
                    <?php if ($count_declined > 0): ?>
                        <div class="max-h-64 overflow-y-auto">
                            <?php foreach ($declined_items as $item): ?>
                                <div class="border p-3 rounded mb-3 bg-gray-50 flex justify-between items-center">
                                    <div>
                                        <div class="font-semibold text-sm"><?= htmlspecialchars($item['itemname']) ?></div>
                                        <div class="text-xs text-red-500">Status: Declined</div>
                                    </div>
                                    <span class="text-red-500 text-xl">✘</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-gray-400 text-center py-4">No declined items.</div>
                    <?php endif; ?>
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
// --- Modal and Dropdown Functions (Copied from home-student.php) ---

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
        // Since the message is JSON encoded in PHP and passed here, we need to decode it.
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
    if (dropdown && button && !dropdown.contains(e.target) && !button.contains(e.target) && modal.style.display !== 'flex') {
        dropdown.classList.remove('show');
        button.setAttribute('aria-expanded', 'false');
    }
});

// --- AJAX Function to Clear Notification Count (Points to self: item-status-student.php) ---

function clearNotifCount() {
    const notifBadge = document.getElementById('notifBadge');
    if (!notifBadge) return;
    
    const xhr = new XMLHttpRequest();
    // The AJAX request points back to this same file (item-status-student.php)
    xhr.open('POST', 'item-status-student.php', true); 
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
                        <a href="home-student.php" style="color: #d1d5db; text-decoration: none;">Home</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="found-items-student.php" style="color: #d1d5db; text-decoration: none;">Found Items</a>
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
</body>
</html>