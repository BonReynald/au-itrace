<?php
session_start();
require_once '../config.php';

// ✅ SESSION VALIDATION
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    header("Location: ../au_itrace_portal.php?tab=login&error=noaccess");
    exit;
}
if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    die("Access denied. Only administrators can access this page.");
}
$username = $_SESSION['username'];
$conn = $link;

// ✅ LOGGING FUNCTION
function logAdminAction($conn, $username, $action, $page, $foundID = null) {
    try {
        $foundID = (!empty($foundID) && is_numeric($foundID)) ? intval($foundID) : null;
        $sql = "INSERT INTO tbladminlogs (username, action, page, foundID) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("sssi", $username, $action, $page, $foundID);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (Exception $e) {
        error_log("[Admin Log Error] " . $e->getMessage());
        return false;
    }
}

// ✅ CREATE ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    $fullname = trim($_POST['fullname']);
    $employeeID = trim($_POST['employeeID']);
    $username_new = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $createdby = $username;
    $datecreated = date('Y-m-d H:i:s');

    $check = $conn->prepare("SELECT userID FROM tblsystemusers WHERE username=?");
    $check->bind_param("s", $username_new);
    $check->execute();
    $res = $check->get_result();
    $check->close();

    if ($res->num_rows > 0) {
        $_SESSION['error_message'] = "Username '$username_new' already exists.";
        logAdminAction($conn, $username, "FAILED to create Admin '$username_new' (Duplicate Username)", "Admin Accounts");
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO tblsystemusers 
        (fullname, employeeID, username, email, password, usertype, createdby, datecreated, status)
        VALUES (?, ?, ?, ?, ?, 'ADMINISTRATOR', ?, ?, 'ACTIVE')");
    $stmt->bind_param("sssssss", $fullname, $employeeID, $username_new, $email, $password, $createdby, $datecreated);
    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        $_SESSION['success_message'] = "Admin '$username_new' created successfully.";
        logAdminAction($conn, $username, "CREATED Admin account: $fullname ($username_new)", "Admin Accounts");
    } else {
        $_SESSION['error_message'] = "Error creating admin account.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ✅ EDIT ADMIN (accurate logging)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_admin'])) {
    $userID = intval($_POST['user_id']);
    $newData = [
        'fullname' => trim($_POST['edit_fullname']),
        'employeeID' => trim($_POST['edit_employeeID']),
        'username' => trim($_POST['edit_username']),
        'email' => trim($_POST['edit_email']),
        'status' => trim($_POST['edit_status'])
    ];

    // Fetch old data
    $fetch = $conn->prepare("SELECT fullname, employeeID, username, email, status FROM tblsystemusers WHERE userID=?");
    $fetch->bind_param("i", $userID);
    $fetch->execute();
    $old = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$old) {
        $_SESSION['error_message'] = "Admin not found.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Update record
    $stmt = $conn->prepare("UPDATE tblsystemusers 
        SET fullname=?, employeeID=?, username=?, email=?, status=? 
        WHERE userID=? AND usertype='ADMINISTRATOR'");
    $stmt->bind_param(
        "sssssi",
        $newData['fullname'],
        $newData['employeeID'],
        $newData['username'],
        $newData['email'],
        $newData['status'],
        $userID
    );
    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        // Detect actual changed fields
        $changes = [];
        foreach ($newData as $field => $value) {
            if ($old[$field] !== $value) {
                $changes[] = ucfirst($field) . " changed from '{$old[$field]}' to '{$value}'";
            }
        }
        $changeText = !empty($changes) ? implode(", ", $changes) : "No changes made";
        $_SESSION['success_message'] = "Admin updated successfully.";
        logAdminAction($conn, $username, "EDITED Admin ID $userID: $changeText", "Admin Accounts");
    } else {
        $_SESSION['error_message'] = "Error updating admin.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ✅ DELETE ADMIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $userID = intval($_POST['user_id']);
    $fetch = $conn->prepare("SELECT fullname, username FROM tblsystemusers WHERE userID=?");
    $fetch->bind_param("i", $userID);
    $fetch->execute();
    $res = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if ($res) {
        $stmt = $conn->prepare("DELETE FROM tblsystemusers WHERE userID=? AND usertype='ADMINISTRATOR'");
        $stmt->bind_param("i", $userID);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            $_SESSION['success_message'] = "Admin '{$res['username']}' deleted successfully.";
            logAdminAction($conn, $username, "DELETED Admin '{$res['username']}' (ID: $userID)", "Admin Accounts");
        } else {
            $_SESSION['error_message'] = "Error deleting admin.";
        }
    } else {
        $_SESSION['error_message'] = "Admin not found.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ✅ FETCH ADMINS
$result = $conn->query("SELECT userID, fullname, employeeID, username, email, datecreated, status 
                        FROM tblsystemusers WHERE usertype='ADMINISTRATOR'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Accounts</title>
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

        /* Filter/Top Bar Styles (Used for the 'Add Admin' button) */
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

        .top-bar button[type="submit"], .top-bar button:not([type]) {
            background-color: #007bff;
            color: white;
        }
        .top-bar a button {
            background-color: #28a745;
            color: white;
        }


        /* Item Card Styles (Not used for table, but kept for future proofing) */
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

        .status-active { background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
        .status-inactive { background-color: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
        

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
            <li><a href="status-of-items.php">ℹ️ Status of Items</a></li>
            <li><a href="user-accounts.php">🔒 User Account</a></li>
            <li><a href="admin-accounts.php" class="active">🛡️ Admin Accounts</a></li>
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
            <h1>Admin Accounts</h1>
        </div>

        <div>
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success_message']); ?></div>
                <?php unset($_SESSION['success_message']); endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error_message']); ?></div>
                <?php unset($_SESSION['error_message']); endif; ?>
            
            <div class="top-bar justify-content-end">
                <button class="btn btn-primary" data-toggle="modal" data-target="#createAdminModal">➕ Add Admin</button>
            </div>

            <div class="card" style="padding: 15px; margin-bottom: 30px;">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Name</th><th>Employee ID</th><th>Username</th><th>Email</th><th>Date Created</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): while($row=$result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['fullname']); ?></td>
                                <td><?= htmlspecialchars($row['employeeID']); ?></td>
                                <td><?= htmlspecialchars($row['username']); ?></td>
                                <td><?= htmlspecialchars($row['email']); ?></td>
                                <td><?= date('m/d/Y', strtotime($row['datecreated'])); ?></td>
                                <td>
                                    <?php if(strtolower($row['status'])=='active'): ?>
                                        <span class="status-active">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="status-inactive">INACTIVE</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info edit-btn"
                                        data-toggle="modal"
                                        data-target="#editAdminModal"
                                        data-id="<?= $row['userID'] ?>"
                                        data-fullname="<?= htmlspecialchars($row['fullname']) ?>"
                                        data-employeeid="<?= htmlspecialchars($row['employeeID']) ?>"
                                        data-username="<?= htmlspecialchars($row['username']) ?>"
                                        data-email="<?= htmlspecialchars($row['email']) ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>">✏️ Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete <?= htmlspecialchars($row['fullname']) ?> permanently?');">
                                        <input type="hidden" name="delete_admin" value="1">
                                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($row['userID']) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="7" class="text-center">No administrator accounts found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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
<div class="modal fade" id="createAdminModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <div class="modal-header"><h5 class="modal-title">Add Admin</h5></div>
      <div class="modal-body">
        <input type="hidden" name="create_admin" value="1">
        <div class="form-group"><label>Full Name</label><input type="text" name="fullname" class="form-control" required></div>
        <div class="form-group"><label>Employee ID</label><input type="text" name="employeeID" class="form-control" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="username" class="form-control" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" required></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" class="form-control" required></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="editAdminModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <div class="modal-header"><h5 class="modal-title">Edit Admin</h5></div>
      <div class="modal-body">
        <input type="hidden" name="edit_admin" value="1">
        <input type="hidden" name="user_id" id="edit_user_id">
        <div class="form-group"><label>Full Name</label><input type="text" name="edit_fullname" id="edit_fullname" class="form-control" required></div>
        <div class="form-group"><label>Employee ID</label><input type="text" name="edit_employeeID" id="edit_employeeID" class="form-control" required></div>
        <div class="form-group"><label>Username</label><input type="text" name="edit_username" id="edit_username" class="form-control" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="edit_email" id="edit_email" class="form-control" required></div>
        <div class="form-group">
            <label>Status</label>
            <select name="edit_status" id="edit_status" class="form-control" required>
                <option value="ACTIVE">ACTIVE</option>
                <option value="INACTIVE">INACTIVE</option>
            </select>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Save Changes</button></div>
    </form>
  </div></div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ✅ Auto-fill Edit Modal
$('.edit-btn').on('click', function(){
    $('#edit_user_id').val($(this).data('id'));
    $('#edit_fullname').val($(this).data('fullname'));
    $('#edit_employeeID').val($(this).data('employeeid'));
    $('#edit_username').val($(this).data('username'));
    $('#edit_email').val($(this).data('email'));
    $('#edit_status').val($(this).data('status'));
});
</script>
</body>
</html>