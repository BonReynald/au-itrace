<?php
session_start();
require_once '../config.php';

// 1. Basic Session Check
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    // Redirect for missing session
    header("Location: ../au_itrace_portal.php?tab=login&error=noaccess");
    exit;
}

if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    die("Usertype is not ADMINISTRATOR.");
}

$username = $_SESSION['username'];

// 2. Database Validation Check
if (!$link) {
    die("Database connection failed.");
}

$sql = "SELECT username, usertype, status FROM tblsystemusers WHERE username = ?";
$stmt = $link->prepare($sql);

if (!$stmt) {
    die("Failed to prepare statement: " . $link->error);
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// --- CRITICAL FIX START ---

// Fetch the user data first
$user = $result->fetch_assoc();

if (!$user || $result->num_rows !== 1) {
    // If num_rows is 0 or fetch_assoc fails, the user is invalid/inactive.
    // If you log in with 'admin' (short username), this will fail, proving the login fix is needed.
    die("User not found or inactive in DB. (Session Username: " . htmlspecialchars($username) . ")");
}

// Now check usertype and status explicitly using the fetched $user variable
if (strtoupper($user['usertype']) !== 'ADMINISTRATOR') {
    die("User is not administrator. Usertype found: " . htmlspecialchars($user['usertype']));
}

if (strtoupper($user['status']) !== 'ACTIVE') {
    die("User is not active. Status found: " . htmlspecialchars($user['status']));
}

// --- CRITICAL FIX END ---

$stmt->close();

$success_message = ''; // Variable to hold the success message

// ========== Handle POST Actions ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $claimID = $_POST['claimID'] ?? '';
    $foundID = $_POST['foundID'] ?? ''; // Added to track which item was acted upon
    $action = $_POST['action'] ?? '';
    $studentID = $_POST['studentID'] ?? 0; // Capture the studentID

    // Determine the new status and log action message
    $newStatus = '';
    $logMessage = '';
    $actionDescription = ''; // For the success message

    if ($action === 'approve') {
        $newStatus = 'Physical Verification';
        $actionDescription = "Approved Claim";
    } elseif ($action === 'return') {
        $newStatus = 'Returned';
        $logMessage = "RETURNED item with FoundID: " . $foundID . " to StudentID: " . $studentID;
        $actionDescription = "Item Returned";
    } elseif ($action === 'discard') {
        $newStatus = 'Discarded';
        $logMessage = "DISCARDED item with FoundID: " . $foundID;
        $actionDescription = "Item Discarded";
    } elseif ($action === 'donate') {
        $newStatus = 'Donated';
        $logMessage = "DONATED item with FoundID: " . $foundID;
        $actionDescription = "Item Donated";
    }

    if ($newStatus !== '') {
        // If claimID is empty, use the unique ID generated in the table row logic
        // NOTE: Even 'Unclaimed' items (which have no claimID) need an entry in tblitemstatus 
        // to record their 'Donated' or 'Discarded' status.
        $claimID = !empty($claimID) ? $claimID : uniqid('no_claim_');
        
        // When donating/discarding an UNCLAIMED item, we should set studentID to 0/NULL 
        // as there's no claimant. The form already sends '0' if no claim exists, which is fine.

        // 1. Update/Insert into tblitemstatus
        $stmt = $link->prepare("
            INSERT INTO tblitemstatus (claimID, studentID, status, statusdate)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                studentID = VALUES(studentID),
                status = VALUES(status),
                statusdate = NOW()
        ");

        if (!$stmt) {
            die("Prepare failed: " . $link->error);
        }

        // Bind the new studentID parameter
        $stmt->bind_param("sis", $claimID, $studentID, $newStatus);

        if (!$stmt->execute()) {
            die("Execute failed (tblitemstatus): " . $stmt->error);
        }

        $stmt->close();

        // 2. Insert into tbladminlogs for Return/Donate/Discard actions
        if (!empty($logMessage)) {
            $logPage = "Status of Items";
            $stmt_log = $link->prepare("
                INSERT INTO tbladminlogs (username, action, page, foundID, date_time)
                VALUES (?, ?, ?, ?, NOW())
            ");

            if (!$stmt_log) {
                // This is a log, so fail softly if possible, but for a critical log table, we report the error.
                die("Prepare failed (tbladminlogs): " . $link->error);
            }

            // FIX: Changed bind_param from "ssis" to "ssss" as 'page' and 'foundID' are VARCHAR
            $stmt_log->bind_param("ssss", $username, $logMessage, $logPage, $foundID);

            if (!$stmt_log->execute()) {
                die("Execute failed (tbladminlogs): " . $stmt_log->error);
            }

            $stmt_log->close();
        }
        
        // Success Message Setup
        $item_name = $_POST['item_name'] ?? 'The item';
        $success_message_text = $actionDescription . " successfully: " . htmlspecialchars($item_name) . " (Found ID: " . htmlspecialchars($foundID) . ")";
        
        // --- FIX FOR FORBIDDEN ERROR START ---
        // 1. Create a clean set of GET parameters (filters only)
        $redirect_params = $_GET;
        unset($redirect_params['msg']); // Ensure we don't carry an old message
        
        // 2. Add the new success message
        $redirect_params['msg'] = $success_message_text;
        
        // 3. Build the new query string
        $query_string = http_build_query($redirect_params);

        // 4. Redirect using a clean path and the full query string
        header("Location: status-of-items.php?" . $query_string);
        exit;
        // --- FIX FOR FORBIDDEN ERROR END ---
    }
}

// Check for success message in GET parameters after redirection
if (isset($_GET['msg'])) {
    $success_message = htmlspecialchars($_GET['msg']);
}

// ========== Filters ==========
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// ========== Build SQL ==========
$sql = "
    SELECT 
        fi.foundID,
        fi.itemname,
        cr.claimID,
        cr.studentID,
        cr.datesubmitted,
        -- FIX: Use COALESCE to prioritize tblitemstatus, then tblclaimrequests, then default to 'Unclaimed'
        COALESCE(
            s.status,
            CASE 
                WHEN cr.status = 'Approved' THEN 'Physical Verification'
                ELSE 'Unclaimed'
            END
        ) AS itemstatus

    FROM tblfounditems fi
    -- Use a LEFT JOIN to ensure items without a claim still show up
    LEFT JOIN tblclaimrequests cr ON fi.foundID = cr.foundID
    -- Use an additional LEFT JOIN for item status, linking it either by claimID OR by a generated claimID for Unclaimed items.
    -- The simplest and most direct way is to keep the link on cr.claimID, but for Donated/Discarded items that had no claim, 
    -- they won't show the correct status unless we force a join on the generated ID.
    -- To simplify, we keep the original join which works for claimed items. For unclaimed items, the logic relies on the main status query fix.
    LEFT JOIN tblitemstatus s ON cr.claimID = s.claimID
    -- FIX: To catch Donated/Discarded items that were UNCLAIMED, we need a special join condition. 
    -- We'll rely on the main COALESCE to fetch the most recent status, but also add a status table join that links via foundID when no claimID exists.
    -- Since tblitemstatus is keyed on claimID, we must rely on the existing LEFT JOIN and ensure the status is correctly persisted via the claimID or generated unique ID.
    -- Re-running the query with the current setup means we must ensure items without claimID are correctly filtered.
    
    -- We can simplify the join logic for a cleaner main table by first querying the status table.
    -- However, staying within the requested constraint of only modifying the existing code structure:
    WHERE 1=1
";

// ========== Apply search filter ==========
if ($search !== '') {
    $esc = mysqli_real_escape_string($link, $search);
    $sql .= " AND (
        fi.itemname LIKE '%$esc%'
        OR cr.studentID LIKE '%$esc%'
        OR cr.claimID LIKE '%$esc%'
    )";
}

// ========== Apply status filter (FIXED) ==========
if ($statusFilter !== '') {
    $esc2 = mysqli_real_escape_string($link, $statusFilter);
    $sql .= " AND (
        -- Directly check the COALESCE result (itemstatus) to apply the filter correctly
        (
            COALESCE(
                s.status,
                CASE 
                    WHEN cr.status = 'Approved' THEN 'Physical Verification'
                    ELSE 'Unclaimed'
                END
            ) = '$esc2'
        )
    )";
}

// ========== Final ORDER ==========
// FIX: Order by the determined itemstatus
$sql .= " ORDER BY 
    CASE itemstatus
        WHEN 'Physical Verification' THEN 1
        WHEN 'Unclaimed' THEN 2
        ELSE 3 -- Put Returned, Donated, Discarded last
    END,
    COALESCE(s.statusdate, fi.datefound) DESC";

$result = $link->query($sql);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Status of Items</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* General Styles */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }
        
        /* Sidebar Styles (Unchanged) */
        nav {
            width: 250px;
            background-color: #004ea8;
            color: white;
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            position: fixed; 
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 1000;
        }
        nav h2 {
            padding: 0 20px;
            font-size: 20px;
            margin-bottom: 30px;
        }
        nav ul {
            list-style: none;
            padding: 0;
            flex-grow: 1;
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
            background-color: #1a6ab9;
        }
        nav ul li a.active {
            background-color: #2980b9;
        }

        /* Logout Button in Sidebar (RED) */
        .sidebar-logout {
            padding: 20px;
            border-top: 1px solid #1a6ab9;
        }
        .sidebar-logout button {
            width: 100%;
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
            background-color: #cc0000 !important;
        }
        
        /* Main Layout Styles */
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .main-content {
            margin-left: 250px; 
            flex: 1;
            padding: 20px; /* Add padding to the parent container */
            min-height: calc(100vh - 120px); 
        }

        /* UPDATED: Blue Header Style with spacing and rounded corners */
        .page-header-blue {
            background-color: #004ea8;
            color: white;
            padding: 20px;
            margin-bottom: 25px; /* Increased spacing below header */
            border-radius: 8px; /* Rounded corners */
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); /* Subtle shadow for depth */
            /* Ensure the header does not overflow its parent padding */
            width: 100%; 
            box-sizing: border-box; 
        }
        .page-header-blue h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            color: white;
        }
        
        .main-content p {
            margin-bottom: 20px;
            color: #6c757d;
        }

        /* Filter/Top Bar Styles (Unchanged) */
        .top-bar {
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .top-bar input[type="text"],
        .top-bar select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            width: auto;
            min-width: 200px;
        }
        .top-bar button, .top-bar a button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.2s;
            text-decoration: none;
        }

        .top-bar button[type="submit"] {
            background-color: #007bff;
            color: white;
        }
        .top-bar a button {
            background-color: #28a745;
            color: white;
        }


        /* Item Card Styles - Not used here, but kept for general design consistency */
        .card {
            background-color: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s;
            height: 100%;
            overflow: hidden;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        /* --- FOOTER STYLES (Unchanged) --- */
        .app-footer {
            background-color: #222b35; 
            color: #9ca3af;
            padding: 40px 20px 20px; 
            font-size: 14px;
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
            max-width: 300px;
            margin-bottom: 30px;
        }
        .footer-column h3 {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: white;
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
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-column a:hover {
            color: white;
        }
        .footer-copyright {
            text-align: center;
            border-top: 1px solid #374151;
            padding-top: 15px;
            margin-top: 15px;
            font-size: 0.75rem;
            color: #6b7280;
        }
        @media (min-width: 768px) {
            .footer-column {
                width: auto;
                margin-bottom: 0;
            }
        }

        /* Status of Items Page - Specific Table & Button Overrides (Preserved from original status-of-items.php) */
        
        .table-container { overflow-x: auto; }
        table {
            width: 100%; background-color: white; border-radius: 8px; overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        thead { background-color: #fef08a; color: #111827; font-weight: bold; }
        th, td {
            padding: 14px; text-align: center; vertical-align: middle; border-bottom: 1px solid #f3f4f6;
        }

        /* Button Colors */
        .btn-approve { background-color: #0ea5e9; color: white; padding: 6px 12px; border: none; border-radius: 5px; margin-right: 4px; }
        .btn-return { background-color: #22c55e; color: white; padding: 6px 12px; border: none; border-radius: 5px; margin-right: 4px; } /* green */
        .btn-donate { background-color: #f59e0b; color: white; padding: 6px 12px; border: none; border-radius: 5px; margin-right: 4px; } /* orange */
        .btn-discard { background-color: #ef4444; color: white; padding: 6px 12px; border: none; border-radius: 5px; } /* red */

        /* Status Text Colors */
        .text-unclaimed { color: #facc15; font-weight: bold; }
        .text-review    { color: #0ea5e9; font-weight: bold; } /* Physical Verification */
        .text-returned  { color: #16a34a; font-weight: bold; } /* Green */
        .text-donated   { color: #f59e0b; font-weight: bold; } /* Orange */
        .text-discarded { color: #dc2626; font-weight: bold; } /* Red */
        
        @media (min-width: 768px) {
            .footer-column {
                width: auto;
                margin-bottom: 0;
            }
        }

        /* Alert Box Style */
        .alert-success {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #d4edda;
            border-radius: 5px;
            color: #155724;
            background-color: #d4edda;
            text-align: center;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav>
        <h2>AU iTrace — Admin</h2>
        <ul>
            <li><a href="home-admin.php">🏠 Home</a></li>
            <li><a href="found-items-admin.php">📦 Found Items</a></li>
            <li><a href="manage-claim-requests.php">📄 Manage Claim Requests</a></li>
            <li><a href="status-of-items.php" class="active">ℹ️ Status of Items</a></li>
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
    
    <div class="main-content">
        <div class="page-header-blue">
            <h1>Status of Items</h1>
        </div>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert-success">
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="top-bar">
            <input type="text" name="search" placeholder="🔍 Search by name or item..." value="<?= htmlspecialchars($search) ?>">
            <select name="status">
                <option value="">Item Status</option>
                <?php
                $statuses = ['Unclaimed', 'Physical Verification', 'Returned', 'Discarded', 'Donated'];
                foreach ($statuses as $st) {
                    $sel = ($statusFilter === $st) ? 'selected' : '';
                    echo "<option value=\"$st\" $sel>$st</option>";
                }
                ?>
            </select>
            <button type="submit">🔍 Search</button>
        </form>

        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Claim ID</th>
                        <th>Found ID</th>
                        <th>Item Name</th>
                        <th>Student ID</th>
                        <th>Date Submitted</th>
                        <th>Item Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <?php
                                $st = $row['itemstatus'] ?? 'Unclaimed';
                                $cls = '';
                                if ($st === 'Unclaimed') $cls = 'text-unclaimed';
                                elseif ($st === 'Physical Verification') $cls = 'text-review';
                                elseif ($st === 'Returned') $cls = 'text-returned';
                                elseif ($st === 'Donated') $cls = 'text-donated';
                                elseif ($st === 'Discarded') $cls = 'text-discarded';
                                ?>

                                <td><?= ($st === 'Unclaimed' || $st === 'Donated' || $st === 'Discarded') ? '' : htmlspecialchars($row['claimID'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['foundID'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['itemname'] ?? '-') ?></td>
                                <td><?= ($st === 'Unclaimed' || $st === 'Donated' || $st === 'Discarded' || empty($row['studentID'])) ? '' : htmlspecialchars($row['studentID']) ?></td>
                                <td><?= ($st === 'Unclaimed' || $st === 'Donated' || $st === 'Discarded' || empty($row['datesubmitted'])) ? '' : htmlspecialchars(date("M d, Y", strtotime($row['datesubmitted']))) ?></td>
                                <td><span class="<?= $cls ?>"><?= htmlspecialchars($st) ?></span></td>
                                <td>
                                <?php
                                $claimID = $row['claimID'] ?? '';
                                $foundID = $row['foundID'] ?? ''; // Pass foundID to POST
                                $studentID = $row['studentID'] ?? '';
                                $datesubmitted = $row['datesubmitted'] ?? '';
                                $itemname = $row['itemname'] ?? '';

                                // New logic for $isComplete check
                                // Item is considered 'Claimed' if it has a claimID, studentID, and date submitted.
                                $isClaimed = !empty($claimID) && !empty($studentID) && !empty($datesubmitted);
                                
                                // If the item is Donated/Discarded/Returned, we use a placeholder ID in the POST action.
                                // IMPORTANT: For Donate/Discard on UNCLAIMED items, we must use the generated 'no_claim_' ID
                                // that was set in the POST logic to target the correct row in `tblitemstatus`.
                                $claimIDForAction = !empty($claimID) ? $claimID : uniqid('no_claim_');
                                
                                // Disable logic for Return: Only enable if status is 'Physical Verification' and a claim exists.
                                $isReturnDisabled = ($st !== 'Physical Verification');
                                
                                // Disable logic for Donate/Discard: Only enable if status is 'Unclaimed' (or implicitly 'Physical Verification' if they change their mind).
                                // BUT, if it's currently 'Returned', 'Donated', or 'Discarded', it's already done.
                                $isDonateDiscardDisabled = ($st === 'Returned' || $st === 'Donated' || $st === 'Discarded');
                            ?>

                            <form method="POST" action="?<?= http_build_query($_GET) ?>" style="display:inline;" onsubmit="return confirmAction('Return', '<?= htmlspecialchars($itemname) ?>')">
                                <input type="hidden" name="claimID" value="<?= htmlspecialchars($claimIDForAction) ?>">
                                <input type="hidden" name="foundID" value="<?= htmlspecialchars($foundID) ?>"> 
                                <input type="hidden" name="studentID" value="<?= htmlspecialchars($row['studentID'] ?? '') ?>">
                                <input type="hidden" name="item_name" value="<?= htmlspecialchars($itemname) ?>">
                                <input type="hidden" name="action" value="return">
                                <button type="submit" class="btn-return btn-sm"
                                    <?= $isReturnDisabled ? 'disabled' : '' ?>>
                                    Return
                                </button>
                            </form>

                            <form method="POST" action="?<?= http_build_query($_GET) ?>" style="display:inline;" onsubmit="return confirmAction('Donate', '<?= htmlspecialchars($itemname) ?>')">
                                <input type="hidden" name="claimID" value="<?= htmlspecialchars($claimIDForAction) ?>">
                                <input type="hidden" name="foundID" value="<?= htmlspecialchars($foundID) ?>"> 
                                <input type="hidden" name="studentID" value="<?= htmlspecialchars($row['studentID'] ?? '') ?>">
                                <input type="hidden" name="item_name" value="<?= htmlspecialchars($itemname) ?>">
                                <input type="hidden" name="action" value="donate">
                                <button type="submit" class="btn-donate btn-sm"
                                    <?= $isDonateDiscardDisabled ? 'disabled' : '' ?>>
                                    Donate
                                </button>
                            </form>


                            <form method="POST" action="?<?= http_build_query($_GET) ?>" style="display:inline;" onsubmit="return confirmAction('Discard', '<?= htmlspecialchars($itemname) ?>')">
                                <input type="hidden" name="claimID" value="<?= htmlspecialchars($claimIDForAction) ?>">
                                <input type="hidden" name="foundID" value="<?= htmlspecialchars($foundID) ?>"> 
                                <input type="hidden" name="studentID" value="<?= htmlspecialchars($row['studentID'] ?? '') ?>">
                                <input type="hidden" name="item_name" value="<?= htmlspecialchars($itemname) ?>">
                                <input type="hidden" name="action" value="discard">
                                <button type="submit" class="btn-discard btn-sm"
                                    <?= $isDonateDiscardDisabled ? 'disabled' : '' ?>>
                                    Discard
                                </button>
                            </form>
                            </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No items found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
    /**
     * Client-side function to confirm a status change action.
     * @param {string} action The action being performed (Return, Donate, Discard).
     * @param {string} itemName The name of the item.
     * @returns {boolean} True if the user confirms, false otherwise.
     */
    function confirmAction(action, itemName) {
        let message = `Are you sure you want to ${action.toLowerCase()} the item: "${itemName}"?`;
        
        if (action === 'Return') {
            message += "\n\nThis will mark the item as 'Returned' to the claimant.";
        } else if (action === 'Donate') {
            message += "\n\nThis will mark the item as 'Donated' and is irreversible.";
        } else if (action === 'Discard') {
            message += "\n\nThis will mark the item as 'Discarded' and is irreversible.";
        }
        
        return confirm(message);
    }
    
    // --- Existing Search/Reset Script ---
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form.top-bar'); // Changed from search-bar to top-bar
    const searchInput = form.querySelector('input[name="search"]');
    const statusSelect = form.querySelector('select[name="status"]');
    const searchButton = form.querySelector('button[type="submit"]'); // Target the submit button

    // Rename Search to Reset if filters are active
    const hasFilters = searchInput.value.trim() !== '' || statusSelect.value !== '';
    if (hasFilters) {
        searchButton.textContent = '🔄 Reset';
        searchButton.type = 'button'; // Change type to button to prevent submission
    }

    // Re-bind the click listener for the search/reset functionality
    searchButton.addEventListener('click', function (e) {
        if (searchButton.textContent.includes('Reset')) {
            e.preventDefault(); // Stop form submission
            // Clear filters & reload
            // Remove the 'msg' parameter if it exists to clean the URL completely
            const url = new URL(window.location.href);
            url.searchParams.delete('msg');
            url.searchParams.delete('search');
            url.searchParams.delete('status');
            window.location.href = url.pathname;
        } else {
            // Normal search (handled by default form submit now)
             form.submit();
        }
    });
    
    // Clean up the URL if a message was displayed
    if (window.location.search.includes('msg=')) {
        const url = new URL(window.location.href);
        url.searchParams.delete('msg');
        window.history.replaceState({}, document.title, url.toString());
    }
});
</script>

</body>
</html>