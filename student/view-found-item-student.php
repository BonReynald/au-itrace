<?php
// view-found-item-student.php
session_start();
require_once '../config.php';

if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

// Get student info
$username = $_SESSION['username'];
$sql = "SELECT userID, studentID FROM tblsystemusers WHERE username = ? AND usertype = 'STUDENT'";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "s", $username);
mysqli_stmt_execute($stmt);
$resUser = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($resUser);
$studentID = $user['studentID'];
$userID = $user['userID'];
mysqli_stmt_close($stmt);

// --- NOTIFICATION LOGIC ---
$notifCount = 0;
$notifications = [];
$sqlNotifCount = "SELECT COUNT(*) AS count FROM tblnotifications WHERE userID = ? AND isread = 0";
$stmtNotif = mysqli_prepare($link, $sqlNotifCount);
mysqli_stmt_bind_param($stmtNotif, "i", $userID);
mysqli_stmt_execute($stmtNotif);
$resNotif = mysqli_stmt_get_result($stmtNotif);
if ($rowN = mysqli_fetch_assoc($resNotif)) { $notifCount = $rowN['count']; }

$sqlNotifList = "SELECT adminmessage as notif_title, datecreated FROM tblnotifications WHERE userID = ? ORDER BY datecreated DESC LIMIT 5";
$stmtList = mysqli_prepare($link, $sqlNotifList);
mysqli_stmt_bind_param($stmtList, "i", $userID);
mysqli_stmt_execute($stmtList);
$resList = mysqli_stmt_get_result($stmtList);
while ($rowL = mysqli_fetch_assoc($resList)) { $notifications[] = $rowL; }

// --- FETCH SPECIFIC ITEM ---
if (!isset($_GET['foundID']) || empty($_GET['foundID'])) {
    header("Location: found-items-student.php");
    exit;
}

$foundID = $_GET['foundID']; 

$query = "SELECT fi.*, 
          (SELECT COUNT(*) FROM tblclaimrequests WHERE foundID = fi.foundID AND studentID = ? AND status = 'Pending') as has_pending_claim
          FROM tblfounditems fi WHERE fi.foundID = ?";

$stmt = mysqli_prepare($link, $query);
mysqli_stmt_bind_param($stmt, "ss", $studentID, $foundID); 
mysqli_stmt_execute($stmt);
$item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$item) {
    header("Location: found-items-student.php");
    exit;
}

// Split images into an array for the Carousel
$images = !empty($item['image']) ? explode(',', $item['image']) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>View Item - AU iTrace</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet' />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f3f4f6; margin: 0; }
        nav.sidebar { width: 250px; background-color: #004ea8; color: white; padding: 20px 0 70px 0; display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; z-index: 100; }
        nav.sidebar h2 { padding: 0 20px; font-size: 20px; margin-bottom: 30px; }
        nav.sidebar ul { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        nav.sidebar ul li a { display: flex; align-items: center; padding: 12px 20px; color: white; text-decoration: none; }
        nav.sidebar ul li a:hover, nav.sidebar ul li a.active { background-color: #2980b9; text-decoration: none; color: white; }
        .logout-container { position: fixed; bottom: 20px; left: 0; width: 250px; padding: 0 20px; }
        form.logout-form button { width: 100%; background-color: #ef4444; border: none; color: white; padding: 12px 0; border-radius: 0.375rem; font-size: 1rem; cursor: pointer; font-weight: 700; }
        .main-content { margin-left: 250px; flex: 1; padding: 1rem 2rem; min-height: 100vh; }
        .topnav { background-color: #004ea8; padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; color: white; font-weight: 700; font-size: 1.5rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .content-wrapper { background-color: white; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        
        /* Carousel Customization */
        .carousel-container { background: #000; border-radius: 8px; overflow: hidden; }
        .carousel-item img { height: 450px; object-fit: contain; background-color: #1a1a1a; }
        
        .notif-btn { background: none; border: none; cursor: pointer; font-size: 1.75rem; position: relative; color: white; }
        .notif-badge { position: absolute; top: -6px; right: -10px; background-color: #ef4444; color: white; border-radius: 99px; padding: 0 6px; font-size: 0.75rem; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 60px; width: 320px; background: white; border-radius: 0.5rem; box-shadow: 0 8px 16px rgba(0,0,0,0.1); z-index: 1000; color: #333; font-weight: normal; }
        .notif-dropdown.show { display: block; }
    </style>
</head>
<body>

<div class="main-wrapper flex">
    <nav class="sidebar">
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
            <form method="POST" action="../logout.php" class="logout-form">
                <button type="submit">Logout</button>
            </form>
        </div>
    </nav>

    <div class="main-content">
        <div class="topnav">
            <div>Item Details</div>
            <div style="position: relative;">
                <button class="notif-btn" onclick="toggleDropdown()">
                    <i class='bx bxs-bell'></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-badge"><?php echo $notifCount; ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <h4 class="p-3 border-b font-bold bg-blue-50">🔔 Notifications</h4>
                    <?php if(empty($notifications)): ?>
                        <p class="p-3 text-sm text-gray-500">No new notifications</p>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="p-3 border-b text-sm">
                                <strong><?php echo htmlspecialchars($notif['notif_title']); ?></strong>
                                <p class="text-xs text-gray-500"><?php echo date("M d, Y", strtotime($notif['datecreated'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="content-wrapper">
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
                    <h1 class="text-3xl font-bold text-gray-800 mb-2"><?= htmlspecialchars($item['itemname']) ?></h1>
                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-semibold mb-4 inline-block">
                        <?= htmlspecialchars($item['category']) ?>
                    </span>

                    <div class="space-y-4 mt-4">
                        <div class="border-b pb-2">
                            <p class="text-gray-500 text-xs uppercase font-bold">Description</p>
                            <p class="text-gray-800"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
                        </div>
                        <div class="border-b pb-2">
                            <p class="text-gray-500 text-xs uppercase font-bold">Location Found</p>
                            <p class="text-gray-800">📍 <?= htmlspecialchars($item['locationfound']) ?></p>
                        </div>
                        <div class="border-b pb-2">
                            <p class="text-gray-500 text-xs uppercase font-bold">Date Reported</p>
                            <p class="text-gray-800">📅 <?= date("F j, Y", strtotime($item['datefound'])) ?></p>
                        </div>
                        <div class="border-b pb-2">
                            <p class="text-gray-500 text-xs uppercase font-bold">Found ID</p>
                            <p class="text-gray-400 text-sm">#<?= htmlspecialchars($item['foundID']) ?></p>
                        </div>
                    </div>

                    <div class="mt-8 flex flex-col gap-3">
                        <?php if ($item['has_pending_claim'] > 0): ?>
                            <button class="bg-yellow-500 text-white font-bold py-3 px-6 rounded-lg cursor-not-allowed opacity-75 w-full flex items-center justify-center gap-2" disabled>
                                <i class='bx bx-time-five'></i> Claim Already Submitted
                            </button>
                        <?php else: ?>
                            <a href="claim-request.php?foundID=<?= urlencode($item['foundID']) ?>" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200 text-center w-full shadow-md text-decoration-none">
                                Claim Item
                            </a>
                        <?php endif; ?>

                        <a href="found-items-student.php" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-3 px-6 rounded-lg transition duration-200 text-center w-full text-decoration-none">
                            Back to Items Page
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleDropdown() {
        document.getElementById('notifDropdown').classList.toggle('show');
    }
    window.onclick = function(event) {
        if (!event.target.matches('.notif-btn') && !event.target.matches('.bx-bell')) {
            var dropdowns = document.getElementsByClassName("notif-dropdown");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }
</script>
</body>
</html>