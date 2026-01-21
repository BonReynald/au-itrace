<?php
// Set secure session cookie parameters
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
require_once '../config.php';

// 1. Basic Session Check
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    // Redirect for missing session
    session_destroy();
    header("Location: ../login.php"); 
    exit;
}

if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    http_response_code(403);
    die("Access Denied. User type is not ADMINISTRATOR.");
}

$username = trim($_SESSION['username']);

// 2. Database Validation Check
if (!$link || $link->connect_error) {
    die("Database connection failed: " . ($link ? $link->connect_error : "DB link not set."));
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
$stmt->close(); // Close the first statement

if (!$user) {
    // If user not found, destroy session and redirect
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Now check usertype and status explicitly using the fetched $user variable
if (strcasecmp($user['usertype'], 'ADMINISTRATOR') !== 0) {
    http_response_code(403);
    die("Access Denied. User type is not ADMINISTRATOR.");
}

if (strcasecmp($user['status'], 'ACTIVE') !== 0) {
    // Inactive user, force logout
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// --- CRITICAL FIX END ---


// ----------------------------------------------------
// 📦 FOUND ITEMS QUERY LOGIC (FIXED)
// ----------------------------------------------------

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location = isset($_GET['location']) ? trim($_GET['location']) : '';

$fixedLocations = ['Plaridel Hall', 'Bursars Office', 'FCH', 'Canteen'];
$allLocations = $fixedLocations;

$query = "SELECT * FROM tblfounditems WHERE 1=1";
$params = [];
$types = "";

// SECURITY FIX: Build query using prepared statement logic
if (!empty($search)) {
    // Add placeholder for LIKE search
    $query .= " AND itemname LIKE ?";
    $params[] = '%' . $search . '%';
    $types .= "s";
}

if (!empty($location)) {
    // Add placeholder for exact location match
    $query .= " AND locationfound = ?";
    $params[] = $location;
    $types .= "s";
}

// STACKING FIX: Order by the chronological foundID string (newest on top)
$query .= " ORDER BY foundID DESC";

// Prepare the items query statement
$stmt_items = $link->prepare($query);

if (!$stmt_items) {
    // Close connection before dying
    if ($link) $link->close();
    die("Failed to prepare items statement: " . $link->error);
}

// Bind parameters dynamically if filters were used
if (!empty($types)) {
    $bind_names = [$types];
    for ($i = 0; $i < count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    // Call bind_param dynamically with the correct number of references
    call_user_func_array([$stmt_items, 'bind_param'], $bind_names);
}

$stmt_items->execute();
$result = $stmt_items->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt_items->close(); // Close the statement after fetching results

// Close the database connection
if ($link) {
    $link->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Found Items - Admin</title>
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


        /* Item Card Styles (UPDATED FOR CLICKABILITY) */
        .card {
            background-color: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s;
            height: 100%;
            overflow: hidden;
            cursor: pointer; /* Change cursor to pointer for the whole card */
            position: relative;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        /* New card-link styles to wrap content */
        .card-link {
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .card-link:hover {
            color: inherit;
        }

        .card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        .card-body {
            padding: 15px;
            flex-grow: 1;
        }
        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }
        .card-body p {
            font-size: 0.9rem;
            margin-bottom: 6px;
            color: #555;
        }
        .card-footer {
            background-color: #f8f9fa;
            border-top: 1px solid #eee;
            padding: 10px 15px;
            text-align: center;
            position: relative; /* Keep footer on top of card link */
            z-index: 2;
        }

        .actions button, .actions a {
            background: none;
            border: none;
            cursor: pointer;
            margin: 0 8px;
            font-size: 20px;
            text-decoration: none;
            display: inline-block;
            position: relative; /* Ensure buttons are clickable over the card link */
            z-index: 3;
        }

        .actions .edit {
            color: #ffc107;
        }
        .actions .delete {
            color: #dc3545;
        }
        .actions form {
            display: inline;
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
            <h1>Found Items</h1>
        </div>

        <div>
            <p>These items were recently reported and are awaiting claim.</p>

            <form method="GET" class="top-bar">
                <input type="text" name="search" placeholder="🔍 Search by item name..." value="<?= htmlspecialchars($search) ?>">
                <select name="location">
                    <option value="">📍 Filter by location</option>
                    <?php foreach ($allLocations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>" <?= $loc === $location ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Search</button>
                <a href="report-found-items.php"><button type="button">➕ Add Found Item</button></a>
            </form>

            <div class="container-fluid">
                <div class="row">
                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                                // Handle multiple images by splitting string and taking the first one
                                $images = !empty($item['image']) ? explode(',', $item['image']) : [];
                                $firstImage = !empty($images) ? trim($images[0]) : '';
                                $imagePath = !empty($firstImage) ? "../fitems_admin/" . $firstImage : "https://via.placeholder.com/300x200?text=No+Image";
                            ?>
                            <div class="col-6 col-md-4 col-lg-3 mb-4">
                                <div class="card h-100">
                                    <a href="view-found-item.php?foundID=<?= urlencode($item['foundID']) ?>" class="card-link">
                                        <img src="<?= htmlspecialchars($imagePath) ?>" alt="Item Image">
                                        <div class="card-body">
                                            <h5 class="card-title"><?= htmlspecialchars($item['itemname']) ?></h5>
                                            <p><strong>Category:</strong> <?= htmlspecialchars($item['category']) ?></p>
                                            <p style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%;">
                                                <?= htmlspecialchars($item['description']) ?>
                                            </p>
                                            <p>📍 <strong>Location:</strong> <?= htmlspecialchars($item['locationfound']) ?></p>
                                            <p>📅 <strong>Found on:</strong> <?= htmlspecialchars($item['datefound']) ?></p>
                                        </div>
                                    </a>
                                    <div class="card-footer">
                                        <div class="actions">
                                            <a href="edit-found-item.php?foundID=<?= urlencode($item['foundID']) ?>" class="edit" title="Edit">✏️</a>
                                            <form method="POST" action="delete-found-item.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this item?');">
                                                <input type="hidden" name="foundID" value="<?= htmlspecialchars($item['foundID']) ?>">
                                                <button type="submit" class="delete" title="Delete">🗑️</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No items found matching the current filters.</p>
                    <?php endif; ?>
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
</body>
</html>