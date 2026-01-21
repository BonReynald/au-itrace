<?php
// Set secure session cookie parameters
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
require_once '../config.php';

// 1. Basic Session Check
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    session_destroy();
    header("Location: ../login.php"); 
    exit;
}

if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    http_response_code(403);
    die("Access Denied. User type is not ADMINISTRATOR.");
}

$username = trim($_SESSION['username']);

// 2. Database Validation
if (!$link || $link->connect_error) {
    die("Database connection failed.");
}

// Check item ID
if (!isset($_GET['foundID'])) {
    header("Location: found-items-admin.php");
    exit;
}

$foundID = $_GET['foundID'];

// Fetch Item Details
$sql_item = "SELECT * FROM tblfounditems WHERE foundID = ?";
$stmt_item = $link->prepare($sql_item);
$stmt_item->bind_param("s", $foundID);
$stmt_item->execute();
$item = $stmt_item->get_result()->fetch_assoc();
$stmt_item->close();

if (!$item) {
    die("Item not found.");
}

// Split images into an array
$images = !empty($item['image']) ? explode(',', $item['image']) : [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Item - <?= htmlspecialchars($item['itemname']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; color: #333; }
        
        /* SIDEBAR STYLES (Copied from yours) */
        nav { width: 250px; background-color: #004ea8; color: white; padding: 20px 0; display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; z-index: 1000; }
        nav h2 { padding: 0 20px; font-size: 20px; margin-bottom: 30px; }
        nav ul { list-style: none; padding: 0; flex-grow: 1; }
        nav ul li a { display: flex; align-items: center; padding: 12px 20px; color: white; text-decoration: none; transition: background-color 0.2s; }
        nav ul li a:hover { background-color: #1a6ab9; }
        .sidebar-logout { padding: 20px; border-top: 1px solid #1a6ab9; }
        .sidebar-logout button { width: 100%; background-color: #ff3333 !important; color: white !important; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }

        /* CONTENT STYLES */
        .main-content { margin-left: 250px; padding: 20px; min-height: 100vh; }
        .page-header-blue { background-color: #004ea8; color: white; padding: 20px; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; box-sizing: border-box; }
        .page-header-blue h1 { margin: 0; font-size: 28px; font-weight: 600; }

        /* CAROUSEL & DETAILS CUSTOM STYLES */
        .carousel-container { background: #000; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .carousel-item img { height: 500px; object-fit: contain; background-color: #1a1a1a; }
        .item-details-card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .detail-label { font-weight: bold; color: #004ea8; text-transform: uppercase; font-size: 0.8rem; display: block; margin-top: 15px; }
        .detail-value { font-size: 1.1rem; color: #333; }

        .app-footer { background-color: #222b35; color: #9ca3af; padding: 40px 20px 20px; margin-left: 250px; width: calc(100% - 250px); box-sizing: border-box; }
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
            <h1>Item Details: <?= htmlspecialchars($item['itemname']) ?></h1>
        </div>

        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div id="itemCarousel" class="carousel slide carousel-container" data-bs-ride="carousel">
                        <div class="carousel-inner">
                            <?php if (empty($images)): ?>
                                <div class="carousel-item active">
                                    <img src="https://via.placeholder.com/800x500?text=No+Images+Available" class="d-block w-100" alt="No Image">
                                </div>
                            <?php else: ?>
                                <?php foreach ($images as $index => $imgName): 
                                    $imgName = trim($imgName);
                                    $activeClass = ($index === 0) ? 'active' : '';
                                ?>
                                <div class="carousel-item <?= $activeClass ?>">
                                    <img src="../fitems_admin/<?= htmlspecialchars($imgName) ?>" class="d-block w-100" alt="Item Image">
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (count($images) > 1): ?>
                            <button class="carousel-control-prev" type="button" data-bs-target="#itemCarousel" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#itemCarousel" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="item-details-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <h2 class="mb-0"><?= htmlspecialchars($item['itemname']) ?></h2>
                            <span class="badge bg-primary"><?= htmlspecialchars($item['category']) ?></span>
                        </div>
                        <hr>
                        
                        <span class="detail-label">Description</span>
                        <p class="detail-value"><?= nl2br(htmlspecialchars($item['description'])) ?></p>

                        <span class="detail-label">Location Found</span>
                        <p class="detail-value">📍 <?= htmlspecialchars($item['locationfound']) ?></p>

                        <span class="detail-label">Date Reported</span>
                        <p class="detail-value">📅 <?= htmlspecialchars($item['datefound']) ?></p>

                        <span class="detail-label">Found ID</span>
                        <p class="detail-value text-muted">#<?= htmlspecialchars($item['foundID']) ?></p>

                        <div class="mt-4">
                            <a href="found-items-admin.php" class="btn btn-outline-secondary">Back to List</a>
                            <a href="edit-found-item.php?foundID=<?= urlencode($item['foundID']) ?>" class="btn btn-warning text-white">Edit Item</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="app-footer">
    <div class="footer-copyright text-center">
        <p>&copy; 2025 AU iTrace. All Rights Reserved.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>