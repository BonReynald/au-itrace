<?php
session_start();
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

// 🔐 SECURITY: Admin-only page
if (
    !isset($_SESSION['username'], $_SESSION['usertype']) ||
    $_SESSION['usertype'] !== 'ADMINISTRATOR'
) {
    // Redirect to login or deny access for non-admins
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

$username = $_SESSION['username'];

// Prepare and execute query to check admin account by username, usertype, and status
$sql = "SELECT username FROM tblsystemusers WHERE username = ? AND usertype = 'ADMINISTRATOR' AND status = 'ACTIVE' LIMIT 1";

$stmt = $link->prepare($sql);
if (!$stmt) {
    error_log("Database error: Failed to prepare statement for user check.");
    die("Access denied due to system error.");
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    session_unset();
    session_destroy();
    // Redirect to login if user session is invalid/inactive
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}
$stmt->close();



// Generate unique alphanumeric ID prefixed with FI-
function generateFoundID($link) {
    do {
        // Using microtime to ensure greater uniqueness than just YmdHis
        $time_part = str_replace(['.', ' '], '', microtime());
        $id = 'FI-' . date('Ymd') . substr($time_part, -6); 
        $check = mysqli_query($link, "SELECT foundID FROM tblfounditems WHERE foundID = '$id'");
    } while (mysqli_num_rows($check) > 0);
    return $id;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $foundID = $_POST['foundID'] ?? generateFoundID($link);

    $studentname = mysqli_real_escape_string($link, $_POST['studentname']);
    $studentnumber = mysqli_real_escape_string($link, $_POST['studentnumber']);
    $contactnumber = mysqli_real_escape_string($link, $_POST['contactnumber']);
    $itemname = mysqli_real_escape_string($link, $_POST['itemname']);
    $category = mysqli_real_escape_string($link, $_POST['category']);
    $description = mysqli_real_escape_string($link, $_POST['description']);
    $locationfound = mysqli_real_escape_string($link, $_POST['locationfound']);
    $datefound = mysqli_real_escape_string($link, $_POST['datefound']);
    
    $uploaded_images = [];

    // UPDATED: Handle multiple image uploads (Max 5)
    if (!empty($_FILES['image']['name'][0])) {
        $targetDir = "../fitems_admin/"; 
        
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        foreach ($_FILES['image']['tmp_name'] as $key => $tmp_name) {
            if ($key >= 5) break; // Limit to 5 images

            if ($_FILES['image']['error'][$key] == 0) {
                $uniqueFilename = uniqid() . '_' . basename($_FILES["image"]["name"][$key]);
                $targetFile = $targetDir . $uniqueFilename;

                if (move_uploaded_file($tmp_name, $targetFile)) {
                    $uploaded_images[] = $uniqueFilename;
                }
            }
        }
    }
    // Store as comma-separated string
    $image = implode(',', $uploaded_images);

// Insert into database using prepared statement
$sql = "INSERT INTO tblfounditems 
    (foundID, itemname, category, description, locationfound, datefound, image, postedby, studentname, studentnumber, contactnumber, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 'admin', ?, ?, ?, 'Unclaimed')";

$stmt = mysqli_prepare($link, $sql);

if ($stmt === false) {
    echo "<div style='color: red; font-weight: bold;'>❌ Prepare failed: " . mysqli_error($link) . "</div>";
} else {
    mysqli_stmt_bind_param($stmt, 'ssssssssss',
    $foundID,
    $itemname,
    $category,
    $description,
    $locationfound,
    $datefound,
    $image,
    $studentname,
    $studentnumber,
    $contactnumber
);

    if (mysqli_stmt_execute($stmt)) {
        log_admin_action($link, $username, 'ADD ITEM', $foundID);
        mysqli_stmt_close($stmt); 
        header("Location: found-items-admin.php?success=1");
        exit();
    } else {
        echo "<div style='color: red; font-weight: bold;'>❌ Execute failed: " . mysqli_stmt_error($stmt) . "</div>";
        mysqli_stmt_close($stmt); 
    }
}

} else {
    $foundID = generateFoundID($link);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Report Found Item</title>
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
        
        /* Sidebar Styles */
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
        
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .main-content {
            margin-left: 250px; 
            flex: 1;
            padding: 20px; 
            min-height: calc(100vh - 120px); 
        }

        .page-header-blue {
            background-color: #004ea8;
            color: white;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 100%; 
            box-sizing: border-box; 
        }
        .page-header-blue h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            color: white;
        }

        .form-card {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0e0e0;
            max-width: 800px;
            margin: 0 auto;
        }
        
        form label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
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
            box-sizing: border-box;
        }
        form textarea {
            resize: vertical;
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
            color: #004ea8;
            background-color: #f7f7f7;
        }
        .found-id button {
            padding: 8px 15px;
            cursor: pointer;
            background-color: #004ea8;
            border: none;
            border-radius: 5px;
            color: white;
            font-size: 1.1em;
            transition: background-color 0.2s;
        }
        .found-id button:hover {
            background-color: #003a80;
        }

        .upload-box {
            border: 2px dashed #ccc;
            padding: 20px;
            text-align: center;
            margin-bottom: 14px;
            border-radius: 5px;
            background-color: #fcfcfc;
        }
        
        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .form-actions button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .form-actions .submit {
            background-color: #28a745; 
            color: white;
        }
        .form-actions .submit:hover {
            background-color: #1e7e34;
        }
        .form-actions .cancel {
            background-color: #dc3545; 
            color: white;
        }
        .form-actions .cancel:hover {
            background-color: #c82333;
        }

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
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav>
        <h2>AU iTrace — Admin</h2>
        <ul>
            <li><a href="home-admin.php">🏠 Home</a></li>
            <li><a href="found-items-admin.php" class="active">📦 Found Items</a></li>
            <li><a href="manage-claim-requests.php">📄 Manage Claim Requests</a></li>
            <li><a href="status-of-items.php">ℹ️ Status of Items</a></li>
            <li><a href="admin-profile.php">👤 Admin Profile</a></li>
            <li><a href="admin-accounts.php">🛡️ Admin Accounts</a></li>
        </ul>
        <div class="sidebar-logout">
            <form method="POST" action="../logout.php">
                <button type="submit">Logout 🚪</button>
            </form>
        </div>
        </nav>

    <div class="main-content">
        <div class="page-header-blue">
            <h1>📝 Report Found Item</h1>
        </div>
        
        <div class="form-card">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="foundID" value="<?= htmlspecialchars($foundID) ?>">

                <label>Found Item ID:</label>
                <div class="found-id">
                    <input type="text" name="foundID_display" value="<?= htmlspecialchars($foundID) ?>" readonly>
                    <button type="button" onclick="printFoundItem()">🖨️ Print Ref.</button>
                </div>

                <label>Student Name:</label>
                <input type="text" id="studentname" name="studentname" required>

                <label>Student Number:</label>
                <input type="text" id="studentnumber" name="studentnumber" required>

                <label>Contact Number:</label>
                <input type="text" id="contactnumber" name="contactnumber" required>

                <label>Item Name:</label>
                <input type="text" name="itemname" required>

                <label>Category:</label>
                <select name="category" required>
                    <option value="">Select Category</option>
                    <option value="Electronics">Electronics</option>
                    <option value="Books">Books</option>
                    <option value="Clothing">Clothing</option>
                    <option value="Accessories">Accessories</option>
                    <option value="Others">Others</option>
                </select>

                <label>Description:</label>
                <textarea name="description" placeholder="Detailed description of the found item..."></textarea>

                <label>Location Found:</label>
                <input type="text" name="locationfound" placeholder="e.g., Cafeteria Table 5" required>

                <label>Date Found:</label>
                <input type="date" name="datefound" required>

                <label>Upload Photo(s) (Max 5):</label>
                <div class="upload-box">
                    <input type="file" name="image[]" accept="image/*" multiple>
                    <p style="font-size: 0.8em; color: #777; margin-top: 5px;">You can select up to 5 images. Use Ctrl/Cmd to select multiple files.</p>
                </div>

                <div class="form-actions">
                    <button class="cancel" type="button" onclick="window.location.href='found-items-admin.php'">❌ Cancel</button>
                    <button class="submit" type="submit">✅ Post Found Item</button>
                </div>
            </form>
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
function printFoundItem() {
    const studentName = document.getElementById('studentname').value.trim() || 'N/A';
    const studentNumber = document.getElementById('studentnumber').value.trim() || 'N/A';
    const contactNumber = document.getElementById('contactnumber').value.trim() || 'N/A';
    const foundID = document.querySelector('input[name="foundID_display"]').value.trim() || 'N/A';

    const printContent = `
        <html>
        <head>
            <title>AU iTrace - Found Item Reference</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { text-align: center; font-size: 24px; margin-bottom: 40px; }
                .info {
                    font-size: 18px;
                    line-height: 1.6;
                    max-width: 400px;
                    margin: 0 auto;
                    border: 1px solid #333;
                    padding: 20px;
                    border-radius: 8px;
                }
                .label {
                    font-weight: bold;
                    margin-right: 10px;
                }
            </style>
        </head>
        <body>
            <h1>AU iTrace Lost & Found</h1>
            <div class="info">
                <p><span class="label">Reference Code (Found Item ID):</span> ${foundID}</p>
                <p><span class="label">Student Name:</span> ${studentName}</p>
                <p><span class="label">Student Number:</span> ${studentNumber}</p>
                <p><span class="label">Contact Number:</span> ${contactNumber}</p>
            </div>
        </body>
        </html>
    `;

    const printWindow = window.open('', '', 'width=500,height=400');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
    printWindow.close();
}
</script>

</body>
</html>