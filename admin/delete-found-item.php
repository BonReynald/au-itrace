<?php
session_start();
require_once '../config.php';

// Function to log admin actions to tbladminlogs
// MODIFIED: For 'DELETE ITEM', this function ensures the foundID field in tbladminlogs is NULL
function log_admin_action($link, $admin_username, $action_type, $found_item_id = null) {
    $page = 'Found Items'; 
    $datetime = date('Y-m-d H:i:s'); 
    
    // Handle DELETE: log the ID in the action message, but bind NULL to the foreign key column
    if ($action_type == 'DELETE ITEM') {
        $action_message = "DELETE ITEM (ID: {$found_item_id})";
        // Use a query with NULL explicitly for the foundID column
        $log_query = "INSERT INTO tbladminlogs (username, action, page, foundID, date_time) VALUES (?, ?, ?, NULL, ?)";
        
        if ($stmt_log = $link->prepare($log_query)) {
            // Bind only the four non-NULL values
            $stmt_log->bind_param("ssss", $admin_username, $action_message, $page, $datetime);
        } else {
             error_log("Logging error (DELETE): Failed to prepare statement for tbladminlogs: " . $link->error);
             return false;
        }
    } else {
        // Handle other actions (EDIT, ADD) - bind the actual foundID
        $action_message = $action_type;
        $log_query = "INSERT INTO tbladminlogs (username, action, page, foundID, date_time) VALUES (?, ?, ?, ?, ?)";
        
        if ($stmt_log = $link->prepare($log_query)) {
            $stmt_log->bind_param("sssss", $admin_username, $action_message, $page, $found_item_id, $datetime);
        } else {
             error_log("Logging error (OTHER): Failed to prepare statement for tbladminlogs: " . $link->error);
             return false;
        }
    }
    
    $stmt_log->execute();
    $stmt_log->close();
    return true;
}


// 🔐 SECURITY: Admin-only page and session check
// ... (rest of the security checks are retained for robustness)
if (
    !isset($_SESSION['username'], $_SESSION['usertype']) ||
    $_SESSION['usertype'] !== 'ADMINISTRATOR' ||
    $_SERVER["REQUEST_METHOD"] !== "POST"
) {
    header("Location: found-items-admin.php?error=noaccess");
    exit;
}

$username = $_SESSION['username'];

// Check admin status against the database
$sql_check = "SELECT username FROM tblsystemusers WHERE username = ? AND usertype = 'ADMINISTRATOR' AND status = 'ACTIVE' LIMIT 1";
$stmt_check = $link->prepare($sql_check);
// ... (error handling for prepare)
$stmt_check->bind_param("s", $username);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows !== 1) {
    session_unset();
    session_destroy();
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}
$stmt_check->close();

// --- Deletion Logic ---

if (isset($_POST['foundID'])) {
    $foundID = trim($_POST['foundID']);
    $deleted_image = null;

    // 1. Fetch image name before deleting
    $sql_fetch = "SELECT image FROM tblfounditems WHERE foundID = ?";
    if ($stmt_fetch = $link->prepare($sql_fetch)) {
        $stmt_fetch->bind_param("s", $foundID);
        $stmt_fetch->execute();
        $result_fetch = $stmt_fetch->get_result();
        if ($row = $result_fetch->fetch_assoc()) {
            $deleted_image = $row['image'];
        }
        $stmt_fetch->close();
    }


    // 2. Execute DELETE from tblfounditems
    $sql_delete = "DELETE FROM tblfounditems WHERE foundID = ?";
    $stmt_delete = $link->prepare($sql_delete);

    if ($stmt_delete === false) {
        error_log("Deletion prepare failed: " . $link->error);
        header("Location: found-items-admin.php?error=dbfail");
        exit();
    } 

    $stmt_delete->bind_param("s", $foundID);

    if ($stmt_delete->execute()) {
        
        // 3. 🌟 LOGGING: Log the DELETE action (now modified to set foundID to NULL in the log table)
        // This MUST happen AFTER deletion.
        log_admin_action($link, $username, 'DELETE ITEM', $foundID);
        
        // 4. Optional: Physically delete the image file
        if ($deleted_image && file_exists("../fitems_admin/" . $deleted_image)) {
            unlink("../fitems_admin/" . $deleted_image);
        }
        
        $stmt_delete->close();
        header("Location: found-items-admin.php?deleted=1");
        exit();
    } else {
        error_log("Deletion execute failed: " . $stmt_delete->error);
        $stmt_delete->close();
        header("Location: found-items-admin.php?error=deletefail");
        exit();
    }
} else {
    header("Location: found-items-admin.php?error=missingid");
    exit();
}
?>