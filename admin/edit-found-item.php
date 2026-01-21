<?php
session_start(); // Added session start
require_once '../config.php';

// Function to log admin actions to tbladminlogs
function log_admin_action($link, $admin_username, $action_type, $found_item_id) {
    $page = 'Found Items'; 
    $datetime = date('Y-m-d H:i:s'); 
    
    // Check if the ID is empty/null and should be NULL in the DB (only for DELETE logs)
    if ($action_type == 'DELETE ITEM' || empty($found_item_id)) {
        // Log action with the ID in the message, but explicitly bind NULL to the foundID column
        $action_message = $action_type;
        if (!empty($found_item_id)) {
            // Add ID to the message if it was provided
            $action_message .= " (ID: {$found_item_id})";
        }
        
        // Use a query with NULL explicitly for the foundID column
        $log_query = "INSERT INTO tbladminlogs (username, action, page, foundID, date_time) VALUES (?, ?, ?, NULL, ?)";
        
        if ($stmt_log = $link->prepare($log_query)) {
            // Bind only the four non-NULL values
            $stmt_log->bind_param("ssss", $admin_username, $action_message, $page, $datetime);
        } else {
             error_log("Logging error (NULL ID): Failed to prepare statement for tbladminlogs: " . $link->error);
             return false;
        }

    } else {
        // Log action where the item *exists* (ADD/EDIT) - bind the actual foundID string
        $action_message = $action_type;
        $log_query = "INSERT INTO tbladminlogs (username, action, page, foundID, date_time) VALUES (?, ?, ?, ?, ?)";
        
        if ($stmt_log = $link->prepare($log_query)) {
            // Bind all five values as strings
            $stmt_log->bind_param("sssss", $admin_username, $action_message, $page, $found_item_id, $datetime);
        } else {
             error_log("Logging error (VALID ID): Failed to prepare statement for tbladminlogs: " . $link->error);
             return false;
        }
    }

    $stmt_log->execute();
    $stmt_log->close();
    return true;
}


// 🔐 SECURITY: Admin-only page and session check (Added full security block)
if (
    !isset($_SESSION['username'], $_SESSION['usertype']) ||
    $_SESSION['usertype'] !== 'ADMINISTRATOR'
) {
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

$username = $_SESSION['username'];

// Check admin status against the database
$sql_check = "SELECT username FROM tblsystemusers WHERE username = ? AND usertype = 'ADMINISTRATOR' AND status = 'ACTIVE' LIMIT 1";
$stmt_check = $link->prepare($sql_check);
if (!$stmt_check) {
    error_log("Database error: Failed to prepare statement for user check.");
    die("Access denied due to system error.");
}
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
// End of security check


// Redirect if no foundID is provided
if (!isset($_REQUEST['foundID'])) { // Changed to REQUEST to handle GET (initial load) and POST (submission)
    header("Location: found-items-admin.php");
    exit();
}

$foundID = mysqli_real_escape_string($link, $_REQUEST['foundID']);
$message = "";

// Fetch existing data
$sql = "SELECT * FROM tblfounditems WHERE foundID = '$foundID'";
$res = mysqli_query($link, $sql);
if (!$res || mysqli_num_rows($res) == 0) {
    die("Item not found.");
}
$item = mysqli_fetch_assoc($res);

// Handle form submission (update)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    
    // Use the foundID from the URL/GET, or the hidden input
    $foundID = mysqli_real_escape_string($link, $_POST['foundID_display'] ?? $_REQUEST['foundID']); 

    $studentname = mysqli_real_escape_string($link, $_POST['studentname']);
    $studentnumber = mysqli_real_escape_string($link, $_POST['studentnumber']);
    $contactnumber = mysqli_real_escape_string($link, $_POST['contactnumber']);
    $itemname = mysqli_real_escape_string($link, $_POST['itemname']);
    $category = mysqli_real_escape_string($link, $_POST['category']);
    $description = mysqli_real_escape_string($link, $_POST['description']);
    $locationfound = mysqli_real_escape_string($link, $_POST['locationfound']);
    $datefound = mysqli_real_escape_string($link, $_POST['datefound']);
    
    $existingImages = !empty($item['image']) ? explode(',', $item['image']) : [];
    $newImages = [];

    // handle new images (multiple)
    if (!empty($_FILES['images']['name'][0])) {
        $targetDir = "../fitems_admin/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        foreach ($_FILES['images']['name'] as $key => $val) {
            if ($key >= 5) break; // Limit to 5
            
            $uniqueFilename = uniqid() . '_' . basename($_FILES["images"]["name"][$key]);
            $targetFile = $targetDir . $uniqueFilename;

            if (move_uploaded_file($_FILES["images"]["tmp_name"][$key], $targetFile)) {
                $newImages[] = $uniqueFilename;
            }
        }
        // If new images uploaded, we replace. Otherwise keep old.
        $image = implode(',', $newImages);
    } else {
        $image = $item['image'];
    }

    $updateSql = "UPDATE tblfounditems SET
                      studentname = '$studentname',
                      studentnumber = '$studentnumber',
                      contactnumber = '$contactnumber',
                      itemname = '$itemname',
                      category = '$category',
                      description = '$description',
                      locationfound = '$locationfound',
                      datefound = '$datefound',
                      image = '$image'
                  WHERE foundID = '$foundID'";

    if (mysqli_query($link, $updateSql)) {
        // 🌟 LOGGING: Log the EDIT action upon successful update
        log_admin_action($link, $username, 'EDIT ITEM', $foundID);
        
        header("Location: found-items-admin.php?updated=1");
        exit();
    } else {
        $message = "❌ Failed to update: " . mysqli_error($link);
        
        // Re-fetch item data in case of error
        $res = mysqli_query($link, $sql);
        $item = mysqli_fetch_assoc($res);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Found Item</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .wrapper {
            display: flex;
        }
        nav {
            width: 250px;
            background-color: #3b82c4;
            color: white;
            min-height: 100vh;
        }
        nav h2 {
            padding: 20px;
            font-size: 18px;
        }
        nav ul {
            list-style: none;
            padding: 0;
        }
        nav ul li a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
        }
        nav ul li a:hover,
        nav ul li a.active {
            background-color: #1e4e79;
        }
        .content {
            flex: 1;
            padding: 40px;
        }

        h2 {
            font-size: 24px;
        }

        form {
            max-width: 700px;
            margin-top: 20px;
        }

        form input[type="text"],
        form input[type="date"],
        form select,
        form textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 14px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        form textarea {
            height: 100px;
        }

        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        .form-actions button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .form-actions .submit {
            background-color: #059669;
            color: white;
        }

        .form-actions .cancel {
            background-color: #dc2626;
            color: white;
        }

        .found-id {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .found-id input {
            flex: 1;
            font-weight: bold;
            font-size: 1.1em;
        }

        .upload-box {
            border: 2px dashed #ccc;
            padding: 20px;
            text-align: center;
            margin-bottom: 14px;
        }

        .image-preview-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .image-preview-item {
            text-align: center;
        }
        .image-preview-item img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <nav>
        <h2>AU iTrace — Admin</h2>
        <ul>
            <li><a href="#">🏠 Home</a></li>
            <li><a href="found-items-admin.php" class="active">📦 Found Items</a></li>
            <li><a href="#">📄 Manage Claim Requests</a></li>
            <li><a href="#">ℹ️ Status of Items</a></li>
            <li><a href="#">🔒 User Account</a></li>
        </ul>
    </nav>

    <div class="content">
        <h2>✏️ Edit Found Item</h2>
        <?php if (!empty($message)): ?>
            <p style="color:red; font-weight:bold;"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <label>Found Item ID:</label>
            <div class="found-id">
                <input type="text" name="foundID_display" value="<?= htmlspecialchars($foundID) ?>" readonly>
            </div>

            <label>Student Name:</label>
            <input type="text" name="studentname" value="<?= htmlspecialchars($item['studentname']) ?>" required>

            <label>Student Number:</label>
            <input type="text" name="studentnumber" value="<?= htmlspecialchars($item['studentnumber']) ?>" required>

            <label>Contact Number:</label>
            <input type="text" name="contactnumber" value="<?= htmlspecialchars($item['contactnumber']) ?>" required>

            <label>Item Name:</label>
            <input type="text" name="itemname" value="<?= htmlspecialchars($item['itemname']) ?>" required>

            <label>Category:</label>
            <select name="category" required>
                <?php
                    $categories = ['Electronics','Books','Clothing','Accessories','Others'];
                    foreach ($categories as $cat):
                ?>
                    <option value="<?= $cat ?>" <?= $item['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                <?php endforeach; ?>
            </select>

            <label>Description:</label>
            <textarea name="description" required><?= htmlspecialchars($item['description']) ?></textarea>

            <label>Location Found:</label>
            <input type="text" name="locationfound" value="<?= htmlspecialchars($item['locationfound']) ?>" required>

            <label>Date Found:</label>
            <input type="date" name="datefound" value="<?= htmlspecialchars($item['datefound']) ?>" required>

            <label>Update Photos (Max 5):</label>
            <div class="upload-box">
                <input type="file" name="images[]" accept="image/*" multiple>
                <p style="font-size: 0.8em; color: #666;">Selecting new photos will replace current ones.</p>
            </div>

            <?php 
                $images = !empty($item['image']) ? explode(',', $item['image']) : [];
                if (count($images) > 0): 
            ?>
                <p>Current Photos:</p>
                <div class="image-preview-container">
                    <?php foreach ($images as $img): ?>
                        <div class="image-preview-item">
                            <img src="../fitems_admin/<?= htmlspecialchars(trim($img)) ?>" alt="Item Image">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="submit">✅ Save Changes</button>
                <button type="button" class="cancel" onclick="window.location.href='found-items-admin.php'">❌ Cancel</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>