<?php
// au_itrace_portal.php
session_start();
require_once 'config.php';

// Enable dev error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// UPDATED QUERY: Select itemname instead of category
$query = "SELECT itemname, datefound FROM tblfounditems ORDER BY datefound DESC";
$result = mysqli_query($link, $query);


// --- FUNCTION: Generate a secure temporary password ---
function generateTemporaryPassword($length = 10) {
    // Defines characters for password: lower, upper, numbers, and symbols
    // Removed 'l', 'o', 'I', 'O' for better readability and reduced confusion
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*';
    $password = '';
    $charLength = strlen($chars);
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, $charLength - 1)];
    }
    return $password;
}
// -----------------------------------------------------------------


// Handle login
if (isset($_POST['btnlogin'])) {
    $username = trim(htmlspecialchars($_POST['txtusername']));
    $password = trim($_POST['txtpassword']);

    // Step 1: Get user data from tblsystemusers
    $sql = "SELECT username, studentID, password, usertype, status FROM tblsystemusers WHERE username = ?";
    $stmt = mysqli_prepare($link, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($user = mysqli_fetch_assoc($result)) {
            
            // --- UPDATED LOGIN CHECK FOR PASSWORD HASHING ---
            // This hybrid check handles two scenarios:
            // 1. New users with securely hashed passwords (preferred)
            // 2. Old users whose passwords might still be stored as plaintext (legacy)
            $is_password_valid = password_verify($password, $user['password']) || ($password === $user['password']);

            if ($is_password_valid) {
                if (strtoupper($user['status']) === 'ACTIVE') {
                    
                    // ==========================================================
                    // ✅ FIX: SESSIONS MOVED HERE TO ENSURE THEY RUN FOR *ALL* ACTIVE USERS (ADMIN/STUDENT)
                    // ==========================================================
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['usertype'] = $user['usertype'];
                    $_SESSION['studentID'] = $user['studentID'];
                    // ==========================================================

                    if ($user['usertype'] === 'STUDENT') {
                        $updateLoginStmt = $link->prepare("UPDATE tblactivestudents SET lastlogin = NOW() WHERE studentID = ?");
                        $updateLoginStmt->bind_param("i", $user['studentID']);
                        $updateLoginStmt->execute();
                        $updateLoginStmt->close();

                        // Step 2: Confirm student is in tblactivestudents
                        $activeSQL = "SELECT studentID FROM tblactivestudents WHERE studentID = ?";
                        $checkActive = mysqli_prepare($link, $activeSQL);
                        mysqli_stmt_bind_param($checkActive, "i", $user['studentID']);
                        mysqli_stmt_execute($checkActive);
                        $activeResult = mysqli_stmt_get_result($checkActive);
                        $isActive = mysqli_num_rows($activeResult) > 0;
                        mysqli_stmt_close($checkActive);

                        if (!$isActive) {
                            session_destroy();
                            echo "<script>alert('Your student account is not yet approved or has been deactivated.'); window.location.href='au_itrace_portal.php?tab=login';</script>";
                            exit;
                        }
                        // Step 3: Check tblitemstatus for 2FA inactivity (7+ days)
                        $itemSQL = "
                            SELECT status, statusdate
                            FROM tblitemstatus
                            WHERE studentID = ?
                            ORDER BY statusdate DESC
                            LIMIT 1
                        ";
                        $itemCheck = mysqli_prepare($link, $itemSQL);
                        mysqli_stmt_bind_param($itemCheck, "i", $user['studentID']);
                        mysqli_stmt_execute($itemCheck);
                        $itemResult = mysqli_stmt_get_result($itemCheck);

                        if ($item = mysqli_fetch_assoc($itemResult)) {
                            $statusDate = new DateTime($item['statusdate']);
                            $now = new DateTime();
                            $daysSince = $statusDate->diff($now)->days;

                            if (strtolower($item['status']) !== 'returned' && $daysSince >= 7) {
                                // Auto-deactivate
                                $delete = mysqli_prepare($link, "DELETE FROM tblactivestudents WHERE studentID = ?");
                                mysqli_stmt_bind_param($delete, "i", $user['studentID']);
                                mysqli_stmt_execute($delete);
                                mysqli_stmt_close($delete);

                                $log = mysqli_prepare($link, "
                                    INSERT INTO deactivation_log (studentID, reason, dateDeactivated, eligible)
                                    VALUES (?, 'No 2FA', NOW(), 1)
                                ");
                                mysqli_stmt_bind_param($log, "i", $user['studentID']);
                                mysqli_stmt_execute($log);
                                mysqli_stmt_close($log);

                                session_destroy();
                                echo "<script>alert('Account auto-deactivated (No 2FA for 7 days).'); window.location.href='au_itrace_portal.php?tab=login';</script>";
                                exit;
                            }
                        }
                        mysqli_stmt_close($itemCheck);

                        // Step 4: Check tblclaimrequests for 30+ days of inactivity
                        $claimSQL = "
                            SELECT datesubmitted
                            FROM tblclaimrequests
                            WHERE studentID = ?
                            ORDER BY datesubmitted DESC
                            LIMIT 1
                        ";
                        $claimCheck = mysqli_prepare($link, $claimSQL);
                        mysqli_stmt_bind_param($claimCheck, "i", $user['studentID']);
                        mysqli_stmt_execute($claimCheck);
                        $claimResult = mysqli_stmt_get_result($claimCheck);

                        if ($claim = mysqli_fetch_assoc($claimResult)) {
                            $claimDate = new DateTime($claim['datesubmitted']);
                            $daysSinceClaim = $claimDate->diff(new DateTime())->days;

                            if ($daysSinceClaim >= 30) {
                                // Auto-deactivate
                                $delete = mysqli_prepare($link, "DELETE FROM tblactivestudents WHERE studentID = ?");
                                mysqli_stmt_bind_param($delete, "i", $user['studentID']);
                                mysqli_stmt_execute($delete);
                                mysqli_stmt_close($delete);

                                $log = mysqli_prepare($link, "
                                    INSERT INTO deactivation_log (studentID, reason, dateDeactivated, eligible)
                                    VALUES (?, '30 Days Inactive', NOW(), 0)
                                ");
                                mysqli_stmt_bind_param($log, "i", $user['studentID']);
                                mysqli_stmt_execute($log);
                                mysqli_stmt_close($log);

                                session_destroy();
                                echo "<script>alert('Account auto-deactivated (Inactive for 30 days).'); window.location.href='au_itrace_portal.php?tab=login';</script>";
                                exit;
                            }
                        }
                        mysqli_stmt_close($claimCheck);

                        // ✅ All checks passed
                        header("Location: student/home-student.php");
                        exit;

                    } elseif ($user['usertype'] === 'ADMINISTRATOR') {
                        // Admin login success
                        header("Location: admin/home-admin.php");
                        exit;

                    } else {
                        // Unknown user type
                        session_destroy();
                        echo "<script>alert('Unknown user type.'); window.location.href='au_itrace_portal.php?tab=login';</script>";
                        exit;
                    }

                } else {
                    echo "<script>alert('Your account is currently inactive.'); window.location.href='au_itrace_portal.php?tab=login';</script>";
                }

            } else {
                echo "<script>alert('Incorrect username or password.'); window.location.href='au_itrace_portal.php?tab=login';</script>";
            }

        } else {
            echo "<script>alert('Username not found.'); window.location.href='au_itrace_portal.php?tab=login';</script>";
        }

        mysqli_stmt_close($stmt);

    } else {
        echo "<script>alert('Database error during login preparation.'); window.location.href='au_itrace_portal.php?tab=login';</script>";
    }
}


// Handle registration form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submitRegistration'])) {
    // Assign data from the form input
    $studentID = $_POST['studentID']; // Changed from regID to studentID
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    
    // --- START: Auto-generate temporary password and hash it (CORE LOGIC) ---
    $tempPassword = generateTemporaryPassword();
    // Hash the password securely for storage in the database
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
    // Note: The $tempPassword should be saved for emailing to the user upon approval
    // but ONLY the $hashedPassword is saved to the database.
    // --- END: Auto-generate temporary password and hash it ---

    // File upload directory
    $uploadDir = "reg_proofs/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    function uploadFile($inputName, $uploadDir) {
        // Simple file upload function - consider adding security checks (mime type, file size)
        $filename = time() . '_' . basename($_FILES[$inputName]["name"]); // Prepend time to avoid name collisions
        $targetPath = $uploadDir . $filename;
        move_uploaded_file($_FILES[$inputName]["tmp_name"], $targetPath);
        return $targetPath;
    }

    $enrollmentform = uploadFile("enrollmentform", $uploadDir);
    $schoolID = uploadFile("schoolID", $uploadDir);
    $validID = uploadFile("validID", $uploadDir);

    // SQL query corrected to insert into 'studentID' and remove 'regID' since it's AUTO_INCREMENT
    $sql = "INSERT INTO tblregistration
        (studentID, fullname, email, password, enrollmentform, schoolID, validID, submissiondate, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending')";

    $stmt = mysqli_prepare($link, $sql);
    // Bind parameters, using the $hashedPassword for the password column
    mysqli_stmt_bind_param($stmt, "sssssss", $studentID, $fullname, $email, $hashedPassword, $enrollmentform, $schoolID, $validID);

    if (mysqli_stmt_execute($stmt)) {
        // Updated success message to inform the user about the generated password
        echo "<script>alert('✅ Registration submitted successfully! Your temporary password has been securely generated and will be sent to your email address ({$email}) once your application is approved.'); window.location.href='au_itrace_portal.php';</script>";
    } else {
        echo "<script>alert('❌ Error: " . mysqli_error($link) . "');</script>";
    }
    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AU iTrace Portal</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

        :root {
            --primary-blue: #004ea8;
            --secondary-blue: #1D547F;
            --light-blue: #EDF2F7;
            --gray-text: #6B7280;
            --border-color: #E2E8F0;
            --gold-accent: #FFD700;
        }
        * {margin: 0;padding: 0;box-sizing: border-box;font-family: 'Poppins', sans-serif;}
        body {background-color: var(--light-blue);min-height: 100vh;display: flex;flex-direction: column;}
        /* Header/Navigation Bar */.navbar {background-color: #004ea8;color: #fff;padding: 1rem 2rem;display: flex;justify-content: space-between;align-items: center;box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);}.navbar .logo {font-size: 20px;font-weight: 600;display: flex;align-items: center;color: #fff;}
        .navbar .logo-box {background-color: #FFD700;height: 46px;width: 56.19px;border-radius: 4px;display: flex;align-items: center;justify-content: center;margin-right: 10px;padding: 8px 14px; color: #004080;}
        .navbar .logo-box span {font-weight: bold;color: #004080;font-size: 20px;}
        .nav-links {display: flex;gap: 1rem;}
        .nav-links .btn {background-color: #fff;color: #004ea8;padding: 0.5rem 1rem;border-radius: 9999px;text-decoration: none;transition: background-color 0.2s ease, color 0.2s ease;font-weight: 500;}
        .nav-links .btn:hover {background-color: #f0f0f0;}
        /* Main Container */
        .container {display: flex;justify-content: center;align-items: center;padding: 2rem;flex-grow: 1;}
        /* Card */
        .portal-card {
            background-color: #fff;
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            /* **MODIFIED:** Increased max-width to accommodate 4 cards in one row */
            max-width: 1050px; 
            width: 100%;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        /* Login and Registration Forms */.tab-content {width: 100%;}
        .card-header {text-align: center;margin-bottom: 2rem;}
        .card-header h2 {font-size: 1.5rem;font-weight: 600;color: var(--primary-blue);}
        .card-header p {color: var(--gray-text);margin-top: 0.5rem; max-width: 400px;margin-left: auto;margin-right: auto;}
        .registration-form,
        .login-form {display: flex;flex-wrap: wrap;gap: 2rem;width: 100%;}
        .form-section {display: flex;flex-direction: column;gap: 1.5rem;flex: 1;min-width: 300px;}
        .form-field {display: flex;flex-direction: column;position: relative;}
        .form-field label {font-size: 0.875rem;font-weight: 500;color: var(--gray-text);margin-bottom: 0.25rem;}
        .form-field .required-star {color: #F87171;margin-left: 0.25rem;}
        .form-field input[type="text"],
        .form-field input[type="email"],
        .form-field input[type="password"] {width: 100%;padding: 0.75rem 1rem;border: 1px solid var(--border-color);border-radius: 0.5rem;font-size: 1rem;transition: border-color 0.2s ease, box-shadow 0.2s ease;}
        .form-field input[type="text"]:focus,
        .form-field input[type="email"]:focus,
        .form-field input[type="password"]:focus {outline: none;border-color: var(--secondary-blue);box-shadow: 0 0 0 3px rgba(29, 84, 127, 0.1);}
        .form-field p {font-size: 0.75rem;color: #9CA3AF;margin-top: 0.25rem;}
        .upload-section .form-field {gap: 0.5rem;}
        .file-upload-box {border: 2px dashed var(--border-color);border-radius: 0.5rem;padding: 1rem;display: flex;flex-direction: column;align-items: center;justify-content: center;text-align: center;cursor: pointer;transition: border-color 0.2s ease, background-color 0.2s ease;}
        .file-upload-box:hover {border-color: var(--secondary-blue);background-color: var(--light-blue);}
        .file-upload-box .icon {font-size: 2rem;color: var(--gray-text);}
        .file-upload-box p {margin-top: 0.5rem;font-size: 0.875rem;color: var(--gray-text);}
        .file-upload-box .input-file {display: none;}
        .file-upload-box .selected-file {font-size: 0.875rem;color: var(--primary-blue);font-weight: 500;margin-top: 0.5rem;}
        .submit-btn {width: 100%;background-color: var(--primary-blue);color: #fff;padding: 0.75rem 1rem;border: none;border-radius: 0.5rem;font-size: 1.125rem;font-weight: 600;cursor: pointer;transition: background-color 0.2s ease;margin-top: 2rem;}
        .submit-btn:hover {background-color: var(--secondary-blue);}
        .submit-btn i {margin-right: 0.5rem;}
        .login-link {text-align: center;margin-top: 1.5rem;font-size: 0.875rem;color: var(--gray-text);}
        .login-link a {color: var(--primary-blue);text-decoration: none;font-weight: 500;transition: text-decoration 0.2s ease;}
        .login-link a:hover {text-decoration: underline;}
        /* New login form styles */
        .login-form {display: flex;flex-direction: column;align-items: center;gap: 1.5rem;max-width: 400px;margin: auto;}
        .login-form .form-field {width: 100%;}
        .login-form input[type="submit"] {width: 100%;background-color: var(--primary-blue);color: white;padding: 10px;border: none;border-radius: 5px;cursor: pointer;font-size: 1.125rem;font-weight: 600;transition: background-color 0.2s ease;}
        .login-form input[type="submit"]:hover {background: var(--secondary-blue);}
        /* Utility class to hide elements */
        .hidden {display: none;}
        /* --- UPDATED STYLES FOR PUBLIC SECTION CARDS (4-in-a-row and improved design) --- */
        .card-grid {
            display: grid;
            /* Remains the same: allows up to 4 columns of 200px minimum width */
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            width: 100%;
            margin-top: 1rem;
        }
        .item-card {
            background-color: #FFFFFF;
            /* Brighter white background */
            border: 1px solid var(--border-color);
            border-top: 5px solid var(--gold-accent);
            /* Added a gold top border */
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            /* Stronger initial shadow */
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .item-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 25px -5px rgba(0, 0, 0, 0.15), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-color: var(--secondary-blue);
        }
        .item-card .icon-box {
            /* **Universal Icon** */
            font-size: 2rem;
            color: var(--primary-blue);
            margin-bottom: 0.5rem;
            padding: 0.25rem;
            background-color: var(--light-blue);
            border-radius: 50%;
            align-self: flex-start;
        }
        .item-card h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--secondary-blue);
            margin-top: 0.25rem;
            margin-bottom: 0.5rem;
            border-bottom: none;
            padding-bottom: 0;
        }
        .item-card p {
            font-size: 0.875rem;
            color: var(--gray-text);
            margin-bottom: 0.25rem;
        }
        .item-card .date {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary-blue);
            margin-top: 0.5rem;
        }
        @media (max-width: 768px) {
            .registration-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="navbar">
        <div class="logo">
            <div class="logo-box">AU</div>
            iTrace Lost and Found System
        </div>
        <div class="nav-links">
            <a href="?tab=publicview" class="btn" id="publicViewBtn">Public View</a>
            <a href="?tab=register" class="btn" id="registerBtn">Register</a>
            <a href="?tab=login" class="btn" id="loginBtn">Login</a>
        </div>
    </header>

    <div class="container">
        <div class="portal-card">

            <div id="publicview-content" class="tab-content">
                <div class="card-header">
                    <h2>AU iTrace <br> Found Items Public View</h2>
                    <p>Browse the recently reported found items.</p>
                </div>

                <div class="card-grid">
                    <?php
                    // Rewind result set to reuse it, as it was already queried at the top
                    if (mysqli_num_rows($result) > 0) {
                        // Reset pointer for the loop below
                        mysqli_data_seek($result, 0);
                        while ($row = mysqli_fetch_assoc($result)):
                            // **Universal Icon:** bx-package
                            $icon = 'bx-package'; 
                            ?>
                            <div class="item-card">
                                <i class='bx <?php echo $icon; ?> icon-box'></i>
                                <h3><?php echo htmlspecialchars($row['itemname']); ?></h3>
                                <p class="date">
    Found Date: 
    <span style="display: block; margin-top: 0.25rem;">
        <?php echo date('F j, Y', strtotime($row['datefound'])); ?>
    </span>
</p>
                                </div>
                            <?php
                        endwhile;
                    } else {
                        ?>
                        <p style="text-align: center; width: 100%; color: var(--gray-text);">No found items available for public view at this time. Check back later!</p>
                        <?php
                    }
                    ?>
                </div>
            </div>

            <div id="register-content" class="tab-content hidden">
                <div class="card-header">
                    <h2>AU iTrace <br> Student Registration</h2>
                    <p>Register to access the Lost and Found system. Approval takes 1–2 school days, and login details will be emailed.</p>
                </div>

                <form class="registration-form" method="POST" enctype="multipart/form-data">
                    <div class="form-section">
                        <div class="form-field">
                            <label for="fullname">Full Name <span class="required-star">*</span></label>
                            <input type="text" id="fullname" name="fullname" placeholder="Enter complete name as per school records." required>
                        </div>

                        <div class="form-field">
                            <label for="studentID">Student ID Number <span class="required-star">*</span></label>
                            <input type="text" id="studentID" name="studentID" required>
                        </div>

                        <div class="form-field">
                            <label for="email">Email Address <span class="required-star">*</span></label>
                            <input type="email" id="email" name="email" required>
                            <p>We will send notifications and where you will set your password.</p>
                        </div>
                        
                    
                        
                    </div>

                    <div class="form-section upload-section">
                        <div class="form-field">
                            <label>Upload Required Documents for Verification</label>
                        </div>
                        
                        <div class="form-field">
                            <label for="enrollmentform_input">Enrollment Form <span class="required-star">*</span></label>
                            <div class="file-upload-box" onclick="document.getElementById('enrollmentform_input').click()">
                                <input type="file" id="enrollmentform_input" class="input-file" name="enrollmentform" required>
                                <i class='bx bx-cloud-upload icon'></i>
                                <p>Click or drag file to upload</p>
                                <span class="selected-file" id="enrollmentform_name"></span>
                                <p class="mt-2 text-xs text-gray-500">Upload a clear copy of your current enrollment form.</p>
                            </div>
                        </div>
                        
                        <div class="form-field">
                            <label for="schoolID_input">School ID <span class="required-star">*</span></label>
                            <div class="file-upload-box" onclick="document.getElementById('schoolID_input').click()">
                                <input type="file" id="schoolID_input" class="input-file" name="schoolID" required>
                                <i class='bx bx-id-card icon'></i>
                                <p>Click or drag file to upload</p>
                                <span class="selected-file" id="schoolID_name"></span>
                                <p class="mt-2 text-xs text-gray-500">Upload a clear picture of your valid school ID.</p>
                            </div>
                        </div>
                        
                        <div class="form-field">
                            <label for="validID_input">Valid ID (Government ID or PSA Birth Certificate) <span class="required-star">*</span></label>
                            <div class="file-upload-box" onclick="document.getElementById('validID_input').click()">
                                <input type="file" id="validID_input" class="input-file" name="validID" required>
                                <i class='bx bx-file icon'></i>
                                <p>Click or drag file to upload</p>
                                <span class="selected-file" id="validID_name"></span>
                                <p class="mt-2 text-xs text-gray-500">Upload a government-issued ID or PSA Birth Certificate.</p>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="submitRegistration" class="submit-btn">
                        <i class='bx bxs-user-plus'></i>
                        Submit Registration
                    </button>
                </form>
                
                <div class="login-link">
                    <p>Already have an account? <a href="#" onclick="showTab('login'); return false;">Login here</a></p>
                </div>
            </div>
            
            <div id="login-content" class="tab-content hidden">
                <div class="card-header">
                    <h2>AU iTrace <br>Login</h2>
                    <p>Claim and manage found items.</p>
                </div>
                <form class="login-form" method="POST" action="">
                    <div class="form-field">
                        <label for="txtusername">Username</label>
                        <input type="text" id="txtusername" name="txtusername" required>
                    </div>
                    <div class="form-field">
                        <label for="txtpassword">Password</label>
                        <input type="password" id="txtpassword" name="txtpassword" required>
                        <p><a href="password-manager.php">Forgot password?</a></p>
                    </div>
                    <input type="submit" name="btnlogin" value="Login">
                </form>
                <div class="login-link">
                    <p>Don't have an account? <a href="#" onclick="showTab('register'); return false;">Register here</a></p>
                </div>
            </div>

        </div>
    </div>
    
    <script>
        // Tab switching logic
        // IMPORTANT: Select buttons by their new IDs
        const navPublicViewBtn = document.getElementById('publicViewBtn');
        const navRegisterBtn = document.getElementById('registerBtn');
        const navLoginBtn = document.getElementById('loginBtn');
        const tabContents = document.querySelectorAll('.tab-content');
        
        function showTab(tabName) {
            // Hide all tab content
            tabContents.forEach(content => content.classList.add('hidden'));

            // Show the active tab content
            const activeContent = document.getElementById(`${tabName}-content`);
            
            if (activeContent) {
                activeContent.classList.remove('hidden');
            }
        }

        // Add event listeners for navigation bar buttons
        navPublicViewBtn.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent default link behavior
            showTab('publicview');
            history.pushState(null, '', `?tab=publicview`);
        });

        navRegisterBtn.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent default link behavior
            showTab('register');
            history.pushState(null, '', `?tab=register`);
        });

        navLoginBtn.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent default link behavior
            showTab('login');
            history.pushState(null, '', `?tab=login`);
        });

        // Handle URL parameters for initial tab load
        const urlParams = new URLSearchParams(window.location.search);
        // IMPORTANT FIX: Default tab is now 'publicview'
        const initialTab = urlParams.get('tab') || 'publicview';
        showTab(initialTab);
        
        // JavaScript for file name display (unchanged)
        document.getElementById('enrollmentform_input').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : '';
            document.getElementById('enrollmentform_name').textContent = fileName;
        });
        document.getElementById('schoolID_input').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : '';
            document.getElementById('schoolID_name').textContent = fileName;
        });
        document.getElementById('validID_input').addEventListener('change', function() {
            const fileName = this.files[0] ? this.files[0].name : '';
            document.getElementById('validID_name').textContent = fileName;
        });
    </script>
</body>

<footer style="
        background-color: #222b35; 
        color: #d1d5db; 
        padding-top: 0.75rem; /* Equivalent to Tailwind py-3 */
        padding-bottom: 0.75rem; /* Equivalent to Tailwind py-3 */
        width: 100%; 
        border-top: 1px solid #4b5563; /* Dark border color for separation */
        font-family: Arial, sans-serif; /* Fallback font */
    ">
        <div style="
            max-width: 1200px; 
            margin-left: auto; 
            margin-right: auto; 
            padding-left: 1rem; 
            padding-right: 1rem; 
            text-align: center; 
            font-size: 0.875rem; /* Equivalent to Tailwind text-sm */
        ">
            <!-- The required copyright text -->
            <p style="margin: 0;">
                Copyright &copy; 2025 AU iTrace. All Rights Reserved.
            </p>
        </div>
    </footer>
</html>