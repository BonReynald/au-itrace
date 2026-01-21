<?php
session_start();
require_once '../config.php'; // Ensure $link = new mysqli(...) is configured here

if (!isset($_SESSION['username'])) {
    die("You must be logged in to access this page.");
}

$username = $_SESSION['username'];
$message = '';

// Fetch admin data
$stmt = $link->prepare("SELECT * FROM tblsystemusers WHERE username = ? AND usertype = 'ADMINISTRATOR'");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Administrator not found or invalid access.");
}

$admin = $result->fetch_assoc();
$userID = $admin['userID'];
$fullname = $admin['fullname'] ?? 'N/A';
$email = $admin['email'] ?? 'N/A';

// Handle password update
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if ($current && $new && $new === $confirm) {
        $hashedPassword = $admin['password'];

        if (password_verify($current, $hashedPassword) || $current === $hashedPassword) {
            $newHashed = password_hash($new, PASSWORD_DEFAULT);

            $update = $link->prepare("UPDATE tblsystemusers SET password = ? WHERE userID = ? AND usertype = 'ADMINISTRATOR'");
            $update->bind_param("si", $newHashed, $userID);

            if ($update->execute()) {
                $message = "Password updated successfully!";
            } else {
                $message = "Failed to update password.";
            }
        } else {
            $message = "Current password is incorrect.";
        }
    } else {
        $message = "Passwords do not match or are empty.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Profile</title>
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

        /* Logout Button in Sidebar */
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
            padding: 20px; 
            min-height: calc(100vh - 120px); 
        }

        /* Blue Header Style */
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

        /* Item Card Styles */
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
            transform: translateY(-1px); /* Slight lift on hover */
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
        
        /* Profile Specific Styles - Adapted to look like the content cards */
        .profile-container {
            display: flex;
            gap: 25px; /* Consistent spacing */
            flex-wrap: wrap;
        }
        .profile-card { /* New class to replace .box */
            background: white;
            padding: 25px;
            border-radius: 8px;
            flex: 1 1 400px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); /* Card shadow */
            border: 1px solid #e0e0e0;
        }
        .profile-card h3 {
             color: #004ea8;
             border-bottom: 2px solid #004ea8;
             padding-bottom: 10px;
             margin-bottom: 20px;
        }
        .info p {
            margin: 15px 0;
            font-size: 16px;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }
        .info p strong {
            display: block;
            color: #555;
            font-weight: 600;
            font-size: 0.9em;
        }
        /* Form/Input Styles */
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box; /* Include padding/border in width */
        }
        .update-btn {
            background: #007bff; /* Use primary blue color */
            color: white;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 5px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .update-btn:hover {
            background: #0056b3;
        }

        /* Message Styling */
        .message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-weight: 500;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        @keyframes fadeOut {
            0% { opacity: 1; }
            90% { opacity: 1; }
            100% { opacity: 0; display: none; }
        }

        /* --- FOOTER STYLES --- */
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
            <li><a href="admin-accounts.php">🛡️ Admin Accounts</a></li>
            <li><a href="admin-profile.php" class="active">👤 Admin Profile</a></li>
        </ul>
        <div class="sidebar-logout">
            <form method="POST" action="../logout.php">
                <button type="submit">Logout 🚪</button>
            </form>
        </div>
        </nav>

    <div class="main-content">
        <div class="page-header-blue">
            <h1>Admin Profile</h1>
        </div>

        <div class="profile-container">

            <div class="profile-card">
                <h3>Account Information</h3>
                <div class="info">
                    <p><strong>Full Name:</strong> <?= htmlspecialchars($fullname) ?></p>
                    <p><strong>Username:</strong> <?= htmlspecialchars($username) ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
                    <p><strong>User Type:</strong> <?= htmlspecialchars($admin['usertype']) ?></p>
                </div>
            </div>

            <div class="profile-card">
                <h3>Change Password</h3>
                <?php if (!empty($message)): ?>
                    <div class="message <?= strpos($message, 'success') !== false ? 'success' : 'error' ?>" id="message">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required>

                    <label>New Password</label>
                    <input type="password" name="new_password" required>

                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" required>

                    <button type="submit" name="update_password" class="update-btn">Update Password</button>
                </form>
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
<script>
    // Script to auto-fade messages, using the new class structure
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(() => {
            const msg = document.getElementById("message");
            if (msg) {
                // Add a basic fade-out effect for a smoother transition
                msg.style.transition = "opacity 0.5s ease 0s";
                msg.style.opacity = "0";
                setTimeout(() => {
                    msg.style.display = "none";
                }, 500); // Wait for fade-out to complete
            }
        }, 3000);
    });
</script>

</body>
</html>