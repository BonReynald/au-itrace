<?php  
session_start();

if (isset($_POST['btnlogin'])) { 
    require_once "config.php";  

    // Sanitize input
    $username = trim(htmlspecialchars($_POST['txtusername'])); 
    $password = trim($_POST['txtpassword']); // Password should not be altered except trimmed

    // Prepare statement to get user info by username only and active status
    $sql = "SELECT * FROM tblsystemusers WHERE username = ? AND status = 'ACTIVE'";

    if ($stmt = mysqli_prepare($link, $sql)) { 
        mysqli_stmt_bind_param($stmt, "s", $username); 

        if (mysqli_stmt_execute($stmt)) { 
            $result = mysqli_stmt_get_result($stmt); 

            if (mysqli_num_rows($result) > 0) { 
                $user = mysqli_fetch_assoc($result); 

                // Plain text password comparison (fix for your DB)
                if ($password === $user['password']) {
                    $_SESSION['username'] = $user['username']; 
                    $_SESSION['usertype'] = $user['usertype']; 

                    switch ($user['usertype']) { 
                        case 'ADMINISTRATOR': 
                            header("Location: admin/home-admin.php"); 
                            exit;
                        case 'STUDENT': 
                            header("Location: student/home-student.php"); 
                            exit;
                        default: 
                            echo "<script>alert('Unauthorized usertype.');</script>"; 
                            break; 
                    } 
                    
                } else {
                    echo "<script>alert('Incorrect login details or inactive account.');</script>"; 
                }

            } else { 
                echo "<script>alert('Incorrect login details or inactive account.');</script>"; 
            } 

        } else { 
            echo "<script>alert('Failed to execute login query.');</script>"; 
        } 

        mysqli_stmt_close($stmt); 
    } else { 
        echo "<script>alert('Database error: Unable to prepare statement.');</script>"; 
    } 

    mysqli_close($link); 
} 
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login Page</title>
    <style>
        body {
            font-family: Arial;
            background: #f4f4f4;
            display: flex;
            height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            background: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.2);
        }
        .login-box h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .login-box input[type="text"],
        .login-box input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }
        .login-box input[type="submit"] {
            width: 100%;
            background: royalblue;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .login-box input[type="submit"]:hover {
            background: darkblue;
        }
    </style>
</head>
<body>

<div class="login-box">
    <h2>Login</h2>
    <form method="POST" action="">
        <input type="text" name="txtusername" placeholder="Username" required>
        <input type="password" name="txtpassword" placeholder="Password" required>
        <input type="submit" name="btnlogin" value="Login">
    </form>
</div>

</body>
</html>
