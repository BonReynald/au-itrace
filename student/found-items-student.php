<?php
// found-items-student.php
session_start();
require_once '../config.php';

// 1. SESSION & LOGIN CHECK
if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

// 2. GET USER INFO
$username = $_SESSION['username'];
$sql = "SELECT userID, studentID FROM tblsystemusers WHERE username = ? AND usertype = 'STUDENT'";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$resultUser = mysqli_stmt_get_result($stmt);

if ($user = mysqli_fetch_assoc($resultUser)) {
    $studentID = $user['studentID'];
    $userID = $user['userID'];
} else {
    session_destroy();
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}
mysqli_stmt_close($stmt);

// 3. STUDENT ACTIVITY CHECK
$sqlCheck = "SELECT * FROM tblactivestudents WHERE studentID = ? AND status = 'Active'";
$stmt = mysqli_prepare($link, $sqlCheck);
mysqli_stmt_bind_param($stmt, "s", $studentID); 
mysqli_stmt_execute($stmt);
$activeResult = mysqli_stmt_get_result($stmt);

if (!mysqli_fetch_assoc($activeResult)) {
    session_destroy();
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}
mysqli_stmt_close($stmt);

$_SESSION['studentID'] = $studentID;
$_SESSION['userID'] = $userID;

const FIXED_NOTIF_TITLE = "Your Item Claim Request is scheduled for Physical Verification";

// ======================================================================
// ⚠️ AJAX HANDLER (Notification Clearing)
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_notifications') {
    if (isset($_SESSION['userID'])) {
        $clearUserID = $_SESSION['userID'];
        $stmtClear = $link->prepare("UPDATE tblnotifications SET isread = 1 WHERE userID = ? AND isread = 0");
        $stmtClear->bind_param("i", $clearUserID);
        if ($stmtClear->execute()) {
            echo "Success";
        } else {
            http_response_code(500);
            echo "Database Error";
        }
        $stmtClear->close();
        mysqli_close($link);
        exit; 
    } else {
        http_response_code(401);
        echo "Unauthorized";
        mysqli_close($link);
        exit;
    }
}

// 4. NOTIFICATION LOGIC (Count & List)
$notifCount = 0;
$sqlNotifCount = "SELECT COUNT(*) AS count FROM tblnotifications WHERE userID = ? AND isread = 0";
$stmtCount = $link->prepare($sqlNotifCount);
$stmtCount->bind_param("i", $userID);
$stmtCount->execute();
$resultCount = $stmtCount->get_result();
if ($notifCountRow = $resultCount->fetch_assoc()) {
    $notifCount = $notifCountRow['count'];
}
$stmtCount->close();

$notifications = [];
$sqlNotifList = "SELECT notifID, adminmessage, datecreated, isread FROM tblnotifications WHERE userID = ? ORDER BY datecreated DESC LIMIT 5";
$stmtList = $link->prepare($sqlNotifList);
$stmtList->bind_param("i", $userID);
$stmtList->execute();
$resultList = $stmtList->get_result();
while ($notifRow = $resultList->fetch_assoc()) {
    $notifRow['notif_title'] = FIXED_NOTIF_TITLE; 
    $notifRow['notif_message'] = $notifRow['adminmessage']; 
    $notifications[] = $notifRow;
}
$stmtList->close();

// 5. SEARCH & FILTER
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';

$locations = [];
$locationResult = mysqli_query($link, "SELECT DISTINCT locationfound FROM tblfounditems ORDER BY locationfound");
while ($loc = mysqli_fetch_assoc($locationResult)) {
    $locations[] = $loc['locationfound'];
}

// ======================================================================
// 6. MAIN FOUND ITEMS QUERY (FORCE UNIQUE ROWS)
// ======================================================================
$whereClauses = ["fi.status = 'Unclaimed'"];
if (!empty($search)) {
    $whereClauses[] = "fi.itemname LIKE '%" . mysqli_real_escape_string($link, $search) . "%'";
}
if (!empty($location)) {
    $whereClauses[] = "fi.locationfound = '" . mysqli_real_escape_string($link, $location) . "'";
}
$whereString = implode(' AND ', $whereClauses);

// Added DISTINCT to force uniqueness
$query = "
    SELECT DISTINCT
        fi.*,
        (SELECT COUNT(*) 
         FROM tblclaimrequests cr 
         WHERE cr.foundID = fi.foundID 
         AND cr.studentID = ? 
         AND cr.status = 'Pending') AS is_claimed_by_current_user
    FROM 
        tblfounditems fi
    WHERE 
        {$whereString}
    ORDER BY 
        fi.foundID DESC
";

$stmt_items = mysqli_prepare($link, $query);
mysqli_stmt_bind_param($stmt_items, "s", $studentID); 
mysqli_stmt_execute($stmt_items);
$result = mysqli_stmt_get_result($stmt_items);

// Note: Do NOT close $link here if you are using it further down in the HTML.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Found Items - AU iTrace</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet' />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* CSS styles copied from home-student.php and found-items-student.php */
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
        
        /* Notification Styles (Copied from home-student.php) */
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
        
        /* Found Items Specific Styles */
        .item-card {
            border-radius: 0.5rem; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            height: 100%;
            transition: transform 0.2s ease;
            background-color: white;
            display: flex;
            flex-direction: column;
        }
        .item-card:hover {
            transform: scale(1.02);
        }
        .item-card img {
            height: 180px;
            width: 100%;
            object-fit: cover;
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
        }
        .item-card-body {
            padding: 0.75rem 1rem;
            flex-grow: 1;
        }
        .item-card-footer {
            background-color: white;
            border-top: 1px solid #f3f4f6;
            padding: 0.75rem;
            text-align: center;
        }
        
        .item-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        
        /* FOOTER FIX (Copied from previous fix) */
        .app-footer {
            margin-left: 250px; 
            width: calc(100% - 250px); 
            box-sizing: border-box; 
        }
        
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav class="sidebar" role="navigation" aria-label="Sidebar Navigation">
        <h2>AU iTrace — Student</h2>
        <ul>
            <li><a href="home-student.php">🏠 Home</a></li>
            <li><a href="found-items-student.php" class="active">📦 Found Items</a></li>
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
            <div>Found Items</div>
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
                                // Clean the title and JSON encode the message for safe JS passing
                                $clean_title = str_replace(["\r", "\n"], ' ', $notif['notif_title']);
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
            <h1 class="text-2xl font-semibold mb-3">Recently Found Items</h1>
            <p class="text-gray-600 mb-6">These items were recently reported and are awaiting claim. Use the search and filter options to find your lost item.</p>

            <?php if (isset($_GET['success']) && $_GET['success'] === 'claim_submitted'): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Success!</strong>
                    <span class="block sm:inline">Claim submitted successfully!</span>
                </div>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'duplicate_claim'): ?>
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Warning!</strong>
                    <span class="block sm:inline">You have already submitted a claim for this item.</span>
                </div>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'db_error'): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <strong class="font-bold">Error!</strong>
                    <span class="block sm:inline">An error occurred while submitting your claim. Please try again.</span>
                </div>
            <?php endif; ?>

            <form method="GET" class="flex gap-4 mb-8 items-center">
                <input type="text" name="search" class="w-full max-w-sm p-2 border border-gray-300 rounded-md focus:outline-none focus:border-blue-500" placeholder="Search by item name..." value="<?= htmlspecialchars($search) ?>">
                <select name="location" class="p-2 border border-gray-300 rounded-md focus:outline-none focus:border-blue-500">
                    <option value="">Filter by location</option>
                    <?php foreach ($locations as $loc) : ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= $location == $loc ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md transition duration-150">Search</button>
            </form>

        
<section class="item-grid" aria-label="List of Found Items">
    <?php if (mysqli_num_rows($result) > 0): ?>
        <?php while ($row = mysqli_fetch_assoc($result)): ?>
            <div class="item-card">
                <a href="view-found-item-student.php?foundID=<?= urlencode($row['foundID']) ?>" class="...">
                    <?php 
                        $imgList = !empty($row['image']) ? explode(',', $row['image']) : [];
                        $displayImg = !empty($imgList) ? trim($imgList[0]) : '';
                        
                        if (!empty($displayImg)): 
                    ?>
                        <img src="../fitems_admin/<?= htmlspecialchars($displayImg) ?>" alt="Image of <?= htmlspecialchars($row['itemname']) ?>" class="item-card-img">
                    <?php else: ?>
                        <img src="https://via.placeholder.com/300x180?text=No+Image+Available" alt="No Image Available" class="item-card-img">
                    <?php endif; ?>
                    
                    <div class="item-card-body">
                        <h3 class="text-lg font-bold text-gray-800 mb-1"><?= htmlspecialchars($row['itemname']) ?></h3>
                        <p class="text-sm text-blue-600 font-semibold mb-1"><?= htmlspecialchars($row['category']) ?></p>
                        <p class="text-sm text-gray-500 italic mb-3 line-clamp-2"><?= htmlspecialchars($row['description']) ?></p>
                        <p class="text-sm text-gray-600 mb-1">📍 <strong>Location:</strong> <?= htmlspecialchars($row['locationfound']) ?></p>
                        <p class="text-xs text-gray-500">📅 <strong>Found on:</strong> <?= htmlspecialchars($row['datefound']) ?></p>
                    </div>
                </a>
                
                <div class="item-card-footer flex flex-col gap-2">
                    <a href="view-found-item-student.php?foundID=<?= urlencode($row['foundID']) ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md text-sm transition duration-150 w-full text-center">
                        View Details
                    </a>

                    <?php if ($row['is_claimed_by_current_user']): ?>
                        <button class="bg-yellow-500 text-white font-bold py-2 px-4 rounded-md text-sm cursor-not-allowed opacity-75 w-full" disabled>
                            Claim Submitted
                        </button>
                    <?php else: ?>
                        <a href="claim-request.php?foundID=<?= urlencode($row['foundID']) ?>" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-md text-sm transition duration-150 w-full text-center">
                            Claim Item
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-span-full">
            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
                <p class="font-bold">Heads up!</p>
                <p>No unclaimed items match your criteria. Please adjust your search or filter.</p>
            </div>
        </div>
    <?php endif; ?>
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
        let decodedMessage;
        try {
            // Message is JSON encoded in PHP and passed here
            decodedMessage = JSON.parse(message);
        } catch (e) {
            // Fallback for non-JSON strings
            decodedMessage = message;
        }

        // Fill modal content
        document.getElementById('modalTitle').textContent = 'Notification Details';
        document.getElementById('modal-notif-title').textContent = title;
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
    // The AJAX request points back to this same file (found-items-student.php)
    xhr.open('POST', 'found-items-student.php', true); 
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