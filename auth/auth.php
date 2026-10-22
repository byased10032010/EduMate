<?php
// MOVE SESSION CONFIGURATION BEFORE session_start()

// Set session configuration for 5-day persistence
ini_set('session.gc_maxlifetime', 432000); // 5 days in seconds (5*24*60*60)

// Set session cookie parameters for 5 days
session_set_cookie_params([
    'lifetime' => 432000,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// NOW start the session
session_start();

// Regenerate session ID for security
session_regenerate_id(true);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME_CODESPHERE', 'codesphere');
define('DB_NAME_USER_REPOS', 'user_repos');
define('DB_NAME_USER_FRIENDS', 'user_friends');

// Database connection function
function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Generate unique 5-digit alphanumeric user ID
function generateUserId() {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $userId = '';
    for ($i = 0; $i < 5; $i++) {
        $userId .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $userId;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['signup'])) {
        // Normal signup - NO PASSWORD HASHING
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password']; // Store as plain text
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $captcha = trim($_POST['captcha']);
        
        // Verify captcha
        if (!isset($_SESSION['captcha_code']) || strtoupper($_SESSION['captcha_code']) !== strtoupper($captcha)) {
            $error = "Invalid captcha code! Please try again.";
        } else {
            $conn = getDBConnection(DB_NAME_CODESPHERE);
            
            // Check if user exists
            $stmt = $conn->prepare("SELECT userId FROM users WHERE email = ? OR username = ?");
            $stmt->bind_param("ss", $email, $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "User already exists with this email or username!";
            } else {
                $userId = generateUserId();
                $createdAt = date('Y-m-d H:i:s');
                
                // Insert user with PLAIN TEXT PASSWORD (NO HASHING)
                $stmt = $conn->prepare("INSERT INTO users (userId, username, email, password, auth_provider, first_name, last_name, created_at, updated_at, last_login, is_active) VALUES (?, ?, ?, ?, 'normal', ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("sssssssss", $userId, $username, $email, $password, $firstName, $lastName, $createdAt, $createdAt, $createdAt);
                
                if ($stmt->execute()) {
                    // Create user_repos table
                    $reposConn = getDBConnection(DB_NAME_USER_REPOS);
                    $tableName = $username . '_' . $userId;
                    $createReposTable = "CREATE TABLE IF NOT EXISTS `$tableName` (
                        repo_id VARCHAR(255) PRIMARY KEY,
                        repo_access VARCHAR(50),
                        access_start DATETIME,
                        role VARCHAR(50)
                    )";
                    $reposConn->query($createReposTable);
                    
                    // Create user_friends table
                    $friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);
                    $friendsTable = $userId;
                    $createFriendsTable = "CREATE TABLE IF NOT EXISTS `$friendsTable` (
                        friend_id VARCHAR(50),
                        friend_name VARCHAR(255),
                        status VARCHAR(50)
                    )";
                    $friendsConn->query($createFriendsTable);
                    
                    // Create notifications database and table
                    $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS);
                    if (!$notifConn->connect_error) {
                        // Create notifications database if it doesn't exist
                        $notifConn->query("CREATE DATABASE IF NOT EXISTS notifications");
                        $notifConn->select_db('notifications');
                        
                        $notifTableName = $userId;
                        $createNotifTable = "CREATE TABLE IF NOT EXISTS `$notifTableName` (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            message TEXT NOT NULL,
                            link VARCHAR(500),
                            type VARCHAR(50),
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            is_read TINYINT(1) DEFAULT 0
                        )";
                        
                        if ($notifConn->query($createNotifTable)) {
                            error_log("SUCCESS: Notification table created for user: $userId");
                        } else {
                            error_log("ERROR: Failed to create notification table for $userId: " . $notifConn->error);
                        }
                        
                        $notifConn->close();
                    } else {
                        error_log("ERROR: Cannot connect to database for notifications: " . $notifConn->connect_error);
                    }
                    
                    // ADDED: Session tracking for 5-day persistence
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $_SESSION['last_activity'] = time();
                    $_SESSION['created'] = time();
                    
                    $_SESSION['success'] = "Account created successfully! Welcome to CodeSphere!";
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $error = "Error creating user: " . $conn->error;
                }
            }
            $conn->close();
        }
    } elseif (isset($_POST['login'])) {
        // Normal login - PLAIN TEXT PASSWORD COMPARISON
        $email = trim($_POST['login_email']);
        $password = $_POST['login_password']; // Compare as plain text
        $captcha = trim($_POST['login_captcha']);
        
        // Verify captcha
        if (!isset($_SESSION['captcha_code']) || strtoupper($_SESSION['captcha_code']) !== strtoupper($captcha)) {
            $error = "Invalid captcha code! Please try again.";
        } else {
            $conn = getDBConnection(DB_NAME_CODESPHERE);
            
            // Get user with PLAIN TEXT PASSWORD
            $stmt = $conn->prepare("SELECT userId, username, email, password FROM users WHERE email = ? AND auth_provider = 'normal'");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // DIRECT PASSWORD COMPARISON (NO HASHING)
                if ($password === $user['password']) {
                    // Update last login
                    $updateStmt = $conn->prepare("UPDATE users SET last_login = ? WHERE userId = ?");
                    $currentTime = date('Y-m-d H:i:s');
                    $updateStmt->bind_param("ss", $currentTime, $user['userId']);
                    $updateStmt->execute();
                    
                    // ADDED: Session tracking for 5-day persistence
                    $_SESSION['user_id'] = $user['userId'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['created'] = time();
                    
                    $_SESSION['success'] = "Welcome back! Login successful.";
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $error = "Invalid password! Please try again.";
                }
            } else {
                $error = "User not found! Please check your email or sign up.";
            }
            $conn->close();
        }
    }
}

// Generate captcha only if not already set or after form submission
if (!isset($_SESSION['captcha_code']) || isset($_POST['signup']) || isset($_POST['login'])) {
    $captchaCode = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);
    $_SESSION['captcha_code'] = $captchaCode;
} else {
    $captchaCode = $_SESSION['captcha_code'];
}

// Simple OAuth simulation for demo purposes
if (isset($_GET['oauth_demo'])) {
    $provider = $_GET['oauth_demo'];
    
    if ($provider === 'google') {
        // Simulate Google OAuth user
        $email = "demo.google@gmail.com";
        $firstName = "Google";
        $lastName = "User";
        $avatar = "";
        $providerId = "google_12345";
        handleOAuthUser($email, $firstName, $lastName, $avatar, 'google', $providerId);
    } elseif ($provider === 'github') {
        // Simulate GitHub OAuth user
        $email = "demo.github@gmail.com";
        $firstName = "GitHub";
        $lastName = "User";
        $avatar = "";
        $providerId = "github_12345";
        handleOAuthUser($email, $firstName, $lastName, $avatar, 'github', $providerId);
    }
}

function handleOAuthUser($email, $firstName, $lastName, $avatar, $provider, $providerId) {
    $conn = getDBConnection(DB_NAME_CODESPHERE);
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT userId, username FROM users WHERE email = ? AND auth_provider = ?");
    $stmt->bind_param("ss", $email, $provider);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        // User exists, log them in
        $user = $result->fetch_assoc();
        $updateStmt = $conn->prepare("UPDATE users SET last_login = ? WHERE userId = ?");
        $currentTime = date('Y-m-d H:i:s');
        $updateStmt->bind_param("ss", $currentTime, $user['userId']);
        $updateStmt->execute();
        
        // ADDED: Session tracking for 5-day persistence
        $_SESSION['user_id'] = $user['userId'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $email;
        $_SESSION['last_activity'] = time();
        $_SESSION['created'] = time();
        
        $_SESSION['success'] = "Welcome back! {$provider} login successful.";
        header('Location: dashboard.php');
        exit();
    } else {
        // Create new user - EMPTY PASSWORD FOR OAUTH USERS
        $userId = generateUserId();
        $username = strtolower($firstName) . $userId;
        $createdAt = date('Y-m-d H:i:s');
        
        // OAuth users have empty password field
        $stmt = $conn->prepare("INSERT INTO users (userId, username, email, password, auth_provider, provider_id, first_name, last_name, avatar_url, created_at, updated_at, last_login, is_active) VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssssssssss", $userId, $username, $email, $provider, $providerId, $firstName, $lastName, $avatar, $createdAt, $createdAt, $createdAt);
        
        if ($stmt->execute()) {
            // Create user tables
            $reposConn = getDBConnection(DB_NAME_USER_REPOS);
            $tableName = $username . '_' . $userId;
            $createReposTable = "CREATE TABLE IF NOT EXISTS `$tableName` (
                repo_id VARCHAR(255) PRIMARY KEY,
                repo_access VARCHAR(50),
                access_start DATETIME,
                role VARCHAR(50)
            )";
            $reposConn->query($createReposTable);
            
            $friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);
            $friendsTable = $userId;
            $createFriendsTable = "CREATE TABLE IF NOT EXISTS `$friendsTable` (
                friend_id VARCHAR(50),
                friend_name VARCHAR(255),
                status VARCHAR(50)
            )";
            $friendsConn->query($createFriendsTable);
            
            // Create notifications database and table for OAuth users
            $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS);
            if (!$notifConn->connect_error) {
                $notifConn->query("CREATE DATABASE IF NOT EXISTS notifications");
                $notifConn->select_db('notifications');
                
                $notifTableName = $userId;
                $createNotifTable = "CREATE TABLE IF NOT EXISTS `$notifTableName` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message TEXT NOT NULL,
                    link VARCHAR(500),
                    type VARCHAR(50),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    is_read TINYINT(1) DEFAULT 0
                )";
                
                if ($notifConn->query($createNotifTable)) {
                    error_log("SUCCESS: Notification table created for OAuth user: $userId");
                } else {
                    error_log("ERROR: Failed to create notification table for OAuth user $userId: " . $notifConn->error);
                }
                
                $notifConn->close();
            }
            
            // ADDED: Session tracking for 5-day persistence
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['last_activity'] = time();
            $_SESSION['created'] = time();
            
            // Get user theme preference
            $themeStmt = $conn->prepare("SELECT theme FROM profiles WHERE user_id = ?");
            $themeStmt->bind_param("s", $userId);
            $themeStmt->execute();
            $themeResult = $themeStmt->get_result();

            if ($themeResult->num_rows > 0) {
                $themeData = $themeResult->fetch_assoc();
                $_SESSION['theme'] = $themeData['theme'] ?? 'light';
            } else {
                $_SESSION['theme'] = 'light'; // Default theme
            }

            // Set cookie for theme
            setcookie('user_theme', $_SESSION['theme'], time() + (30 * 24 * 60 * 60), "/");
            
            $_SESSION['success'] = "Account created successfully with {$provider}! Welcome to CodeSphere!";
            header('Location: dashboard.php');
            exit();
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeSphere | Authentication</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #a5b4fc;
            --secondary: #8b5cf6;
            --accent: #f59e0b;
            --accent-hover: #eab308;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --card-bg: rgba(255, 255, 255, 0.95);
            --glass: rgba(255, 255, 255, 0.1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 25px 50px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 35px 60px rgba(0, 0, 0, 0.2);
            --radius: 14px;
            --radius-lg: 24px;
            --radius-xl: 32px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, var(--primary-light) 0%, var(--secondary) 100%);
            --neon-glow: 0 0 10px rgba(99, 102, 241, 0.7), 0 0 20px rgba(99, 102, 241, 0.5), 0 0 30px rgba(99, 102, 241, 0.3);
            --neon-glow-accent: 0 0 10px rgba(245, 158, 11, 0.7), 0 0 20px rgba(245, 158, 11, 0.5), 0 0 30px rgba(245, 158, 11, 0.3);
            --border: #e2e8f0;
            --input-bg: #ffffff;
            --input-text: #0f172a;
        }

        .dark-mode {
            --primary: #818cf8;
            --primary-dark: #6366f1;
            --primary-light: #a5b4fc;
            --secondary: #a78bfa;
            --accent: #fbbf24;
            --accent-hover: #f59e0b;
            --dark: #f1f5f9;
            --darker: #0f172a;
            --light: #0f172a;
            --gray: #94a3b8;
            --light-gray: #1e293b;
            --card-bg: rgba(30, 41, 59, 0.95);
            --glass: rgba(15, 23, 42, 0.4);
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            --shadow-lg: 0 25px 50px rgba(0, 0, 0, 0.35);
            --shadow-xl: 0 35px 60px rgba(0, 0, 0, 0.4);
            --neon-glow: 0 0 10px rgba(129, 140, 248, 0.7), 0 0 20px rgba(129, 140, 248, 0.5), 0 0 30px rgba(129, 140, 248, 0.3);
            --neon-glow-accent: 0 0 10px rgba(251, 191, 36, 0.7), 0 0 20px rgba(251, 191, 36, 0.5), 0 0 30px rgba(251, 191, 36, 0.3);
            --border: #334155;
            --input-bg: #1e293b;
            --input-text: #f1f5f9;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--light) 0%, var(--light-gray) 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            transition: var(--transition-slow);
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 50% 50%, rgba(245, 158, 11, 0.05) 0%, transparent 30%);
            z-index: -1;
        }

        .dark-mode body::before {
            background: 
                radial-gradient(circle at 10% 20%, rgba(129, 140, 248, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(167, 139, 250, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 50% 50%, rgba(251, 191, 36, 0.05) 0%, transparent 30%);
        }

        .auth-container {
            width: 100%;
            max-width: 480px;
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl), var(--neon-glow);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: var(--transition-slow);
            position: relative;
            z-index: 1;
            animation: containerFloat 8s ease-in-out infinite;
        }

        @keyframes containerFloat {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-5px) rotate(0.5deg); }
            50% { transform: translateY(-10px) rotate(-0.5deg); }
            75% { transform: translateY(-5px) rotate(0.5deg); }
        }

        .auth-header {
            background: var(--gradient);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow: var(--neon-glow);
        }

        .auth-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.5);
        }

        .logo i {
            margin-right: 0.75rem;
            font-size: 2.2rem;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
            animation: logoPulse 2s infinite alternate;
        }

        @keyframes logoPulse {
            from { transform: scale(1); }
            to { transform: scale(1.1); }
        }

        .auth-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
            font-weight: 400;
        }

        .auth-tabs {
            display: flex;
            background: var(--light-gray);
            position: relative;
            z-index: 1;
        }

        .auth-tab {
            flex: 1;
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            position: relative;
            overflow: hidden;
        }

        .auth-tab::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: var(--gradient);
            transition: var(--transition);
        }

        .auth-tab.active {
            background: var(--card-bg);
            color: var(--primary);
        }

        .auth-tab.active::before {
            width: 80%;
        }

        .auth-forms {
            padding: 2.5rem;
        }

        .auth-form {
            display: none;
        }

        .auth-form.active {
            display: block;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: 1.75rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 500;
            color: var(--dark);
            transition: var(--transition);
        }

        .form-control {
            width: 100%;
            padding: 1rem 3rem 1rem 1.25rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            background: var(--input-bg);
            color: var(--input-text);
            font-size: 1rem;
            line-height: 1.5;
            height: auto;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2), var(--neon-glow);
        }

        .dark-mode .form-control:focus {
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2), var(--neon-glow);
        }

        /* FIXED PASSWORD TOGGLE ALIGNMENT */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        .password-toggle:hover {
            color: var(--primary);
            background: none;
            transform: translateY(-50%) scale(1.1);
        }

        .password-toggle i {
            font-size: 1.1rem;
            line-height: 1;
            display: block;
        }

        .captcha-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: var(--light-gray);
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            border: 1px solid var(--border);
        }

        .captcha-code {
            font-family: 'Courier New', monospace;
            font-size: 1.4rem;
            font-weight: bold;
            letter-spacing: 3px;
            background: var(--input-bg);
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            color: var(--input-text);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
            user-select: none;
            min-width: 120px;
            text-align: center;
            animation: captchaPulse 2s infinite alternate;
            border: 1px solid var(--border);
        }

        .dark-mode .captcha-code {
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid #334155;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.3), 0 0 10px rgba(129, 140, 248, 0.3);
        }

        @keyframes captchaPulse {
            from { transform: scale(1); }
            to { transform: scale(1.05); }
        }

        .refresh-captcha {
            background: none;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition);
            padding: 0.5rem;
            border-radius: var(--radius);
        }

        .refresh-captcha:hover {
            background: var(--primary);
            color: white;
            transform: rotate(90deg);
        }

        .dark-mode .refresh-captcha {
            color: var(--primary-light);
            background: rgba(129, 140, 248, 0.1);
        }

        .dark-mode .refresh-captcha:hover {
            background: var(--primary);
            color: white;
            transform: rotate(90deg);
        }

        .btn {
            width: 100%;
            padding: 1.25rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 1.05rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow), var(--neon-glow);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg), var(--neon-glow-accent);
        }

        .oauth-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .oauth-btn {
            flex: 1;
            padding: 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--card-bg);
            color: var(--dark);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-decoration: none;
            font-weight: 500;
        }

        .oauth-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .oauth-btn.google { 
            border-color: #DB4437; 
            color: #DB4437; 
        }
        
        .oauth-btn.github { 
            border-color: #333; 
            color: #333; 
        }

        .dark-mode .oauth-btn.github {
            border-color: #f1f5f9;
            color: #f1f5f9;
        }

        .dark-mode .oauth-btn {
            background: var(--card-bg);
            color: var(--dark);
        }

        /* Alert Messages */
        .alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }

        .alert {
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 1rem;
            transform: translateX(400px);
            transition: transform 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            backdrop-filter: blur(10px);
            border-left: 4px solid;
        }

        .alert.show {
            transform: translateX(0);
        }

        .alert.success {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success);
            color: var(--success);
            box-shadow: var(--neon-glow);
        }

        .alert.error {
            background: rgba(239, 68, 68, 0.1);
            border-left-color: var(--error);
            color: var(--error);
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.5);
        }

        .alert.warning {
            background: rgba(245, 158, 11, 0.1);
            border-left-color: var(--warning);
            color: var(--warning);
            box-shadow: var(--neon-glow-accent);
        }

        .alert .icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .alert .content {
            flex: 1;
        }

        .alert .title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        /* Hide scrollbars but keep scrolling functionality */
html, body {
    overflow: auto;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE and Edge */
}

/* Webkit browsers (Chrome, Safari, Opera) */
html::-webkit-scrollbar,
body::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.profile::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.modal-body::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}

/* Ensure proper sizing */
body {
    margin: 0;
    padding: 0;
}

.main-content {
    height: 100vh;
    overflow: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.main-content::-webkit-scrollbar {
    display: none;
}
        .alert .message {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .alert .close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 1.1rem;
            opacity: 0.7;
            transition: var(--transition);
            padding: 0.25rem;
            border-radius: 4px;
        }

        .alert .close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.1);
            transform: scale(1.1);
        }

        .alert-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: currentColor;
            opacity: 0.3;
            border-radius: 0 0 0 var(--radius);
            animation: progress 5s linear forwards;
        }

        @keyframes progress {
            from { width: 100%; }
            to { width: 0%; }
        }

        .form-message {
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.5s ease;
        }

        .form-message.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border-left: 4px solid var(--error);
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.3);
        }

        .form-message.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
            box-shadow: var(--neon-glow);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .btn.loading::after {
            content: '';
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 0.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .password-strength {
            margin-top: 0.5rem;
            height: 6px;
            border-radius: 3px;
            background: var(--light-gray);
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            border-radius: 3px;
            transition: var(--transition);
        }

        .strength-weak { background: var(--error); width: 33%; }
        .strength-medium { background: var(--warning); width: 66%; }
        .strength-strong { background: var(--success); width: 100%; }

        .form-footer {
            margin-top: 1.5rem;
            text-align: center;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .form-footer a:hover {
            text-decoration: underline;
            color: var(--accent);
        }

        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }

        .shape {
            position: absolute;
            opacity: 0.1;
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        .shape-1 {
            width: 150px;
            height: 150px;
            background: var(--primary);
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 100px;
            height: 100px;
            background: var(--secondary);
            top: 70%;
            left: 80%;
            animation-delay: -5s;
        }

        .shape-3 {
            width: 200px;
            height: 200px;
            background: var(--accent);
            top: 40%;
            left: 70%;
            animation-delay: -10s;
        }

        .shape-4 {
            width: 120px;
            height: 120px;
            background: var(--primary);
            top: 80%;
            left: 10%;
            animation-delay: -15s;
        }

        @keyframes float {
            0% {
                transform: translate(0, 0) rotate(0deg);
            }
            25% {
                transform: translate(20px, 50px) rotate(90deg);
            }
            50% {
                transform: translate(0, 100px) rotate(180deg);
            }
            75% {
                transform: translate(-20px, 50px) rotate(270deg);
            }
            100% {
                transform: translate(0, 0) rotate(360deg);
            }
        }

        .theme-toggle {
            position: fixed;
            top: 2rem;
            right: 2rem;
            background: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            width: 55px;
            height: 55px;
            border-radius: 50%;
            color: var(--dark);
            font-size: 1.3rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow), var(--neon-glow);
            z-index: 100;
            animation: themeToggleFloat 4s ease-in-out infinite;
        }

        @keyframes themeToggleFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .theme-toggle:hover {
            transform: rotate(15deg) scale(1.1);
            box-shadow: var(--shadow-lg), var(--neon-glow-accent);
        }

        .particles-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: var(--primary);
            opacity: 0.3;
            animation: particleFloat 15s infinite linear;
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 0.3;
            }
            90% {
                opacity: 0.3;
            }
            100% {
                transform: translateY(-100px) rotate(360deg);
                opacity: 0;
            }
        }

        .glow-effect {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.3;
            z-index: -1;
            animation: glowMove 15s infinite alternate;
        }

        .glow-1 {
            background: var(--primary);
            top: 10%;
            left: 10%;
        }

        .glow-2 {
            background: var(--secondary);
            bottom: 10%;
            right: 10%;
        }

        @keyframes glowMove {
            0% {
                transform: translate(0, 0) scale(1);
            }
            100% {
                transform: translate(50px, 50px) scale(1.2);
            }
        }

        /* FIXED AUTOFILL STYLES */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-background-clip: text;
            -webkit-text-fill-color: var(--input-text) !important;
            background-color: var(--input-bg) !important;
            border: 1px solid var(--border) !important;
            box-shadow: inset 0 0 0 1000px var(--input-bg) !important;
            transition: background-color 5000s ease-in-out 0s !important;
            caret-color: var(--input-text) !important;
        }

        .dark-mode input:-webkit-autofill,
        .dark-mode input:-webkit-autofill:hover,
        .dark-mode input:-webkit-autofill:focus,
        .dark-mode input:-webkit-autofill:active {
            -webkit-text-fill-color: #f1f5f9 !important;
            background-color: #1e293b !important;
            border: 1px solid #334155 !important;
            box-shadow: inset 0 0 0 1000px #1e293b !important;
            caret-color: #f1f5f9 !important;
        }

        /* For Firefox */
        input:-moz-autofill,
        input:-moz-autofill:hover,
        input:-moz-autofill:focus,
        input:-moz-autofill:active {
            background-color: var(--input-bg) !important;
            color: var(--input-text) !important;
            border: 1px solid var(--border) !important;
        }

        .dark-mode input:-moz-autofill,
        .dark-mode input:-moz-autofill:hover,
        .dark-mode input:-moz-autofill:focus,
        .dark-mode input:-moz-autofill:active {
            background-color: #1e293b !important;
            color: #f1f5f9 !important;
            border: 1px solid #334155 !important;
        }

        /* Enhanced focus states for autofilled inputs */
        input:-webkit-autofill:focus {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2), var(--neon-glow) !important;
        }

        .dark-mode input:-webkit-autofill:focus {
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2), var(--neon-glow) !important;
        }

        @media (max-width: 576px) {
            body {
                padding: 1rem;
            }
            
            .auth-container {
                max-width: 100%;
            }
            
            .auth-forms {
                padding: 2rem 1.5rem;
            }
            
            .oauth-buttons {
                flex-direction: column;
            }

            .theme-toggle {
                top: 1rem;
                right: 1rem;
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="particles-container" id="particlesContainer"></div>
    
    <div class="glow-effect glow-1"></div>
    <div class="glow-effect glow-2"></div>

    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
    </div>

    <!-- Alert Container -->
    <div class="alert-container" id="alertContainer"></div>

    <button class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon"></i>
    </button>

    <div class="auth-container">
        <div class="auth-header">
            <div class="logo">
                <i class="fas fa-code"></i>
                CodeSphere
            </div>
            <p>Join the future of code collaboration</p>
        </div>

        <div class="auth-tabs">
            <div class="auth-tab active" data-tab="login">Login</div>
            <div class="auth-tab" data-tab="signup">Sign Up</div>
        </div>

        <div class="auth-forms">
            <?php if (isset($error)): ?>
                <div class="form-message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="form-message success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="auth-form active" id="loginForm" method="POST">
                <div class="form-group">
                    <label for="login_email">Email</label>
                    <input type="email" id="login_email" name="login_email" class="form-control" placeholder="Enter your email" required value="<?php echo isset($_POST['login_email']) ? htmlspecialchars($_POST['login_email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="login_password">Password</label>
                    <input type="password" id="login_password" name="login_password" class="form-control" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" id="loginPasswordToggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <div class="form-group">
                    <label>Captcha Verification</label>
                    <div class="captcha-container">
                        <div class="captcha-code" id="loginCaptcha"><?php echo $captchaCode; ?></div>
                        <button type="button" class="refresh-captcha" id="refreshLoginCaptcha" title="Refresh Captcha">
                            <i class="fas fa-redo"></i>
                        </button>
                        <input type="text" name="login_captcha" class="form-control" placeholder="Enter captcha" required value="<?php echo isset($_POST['login_captcha']) ? htmlspecialchars($_POST['login_captcha']) : ''; ?>">
                    </div>
                    <small style="color: var(--gray); font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                        <i class="fas fa-info-circle"></i> Enter the code shown above
                    </small>
                </div>

                <button type="submit" name="login" class="btn btn-primary" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    Login to Account
                </button>

                <div class="form-footer">
                    <a href="forgot_password.php" class="forgot-password">Forgot your password?</a>
                </div>

                <div class="oauth-buttons">
                    <a href="?oauth_demo=google" class="oauth-btn google">
                        <i class="fab fa-google"></i>
                        Google
                    </a>
                    <a href="?oauth_demo=github" class="oauth-btn github">
                        <i class="fab fa-github"></i>
                        GitHub
                    </a>
                </div>
            </form>

            <!-- Signup Form -->
            <form class="auth-form" id="signupForm" method="POST">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Choose a username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="form-control" placeholder="Enter your first name" required value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="form-control" placeholder="Enter your last name" required value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Create a strong password" required>
                    <button type="button" class="password-toggle" id="signupPasswordToggle">
                        <i class="fas fa-eye"></i>
                    </button>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Captcha Verification</label>
                    <div class="captcha-container">
                        <div class="captcha-code" id="signupCaptcha"><?php echo $captchaCode; ?></div>
                        <button type="button" class="refresh-captcha" id="refreshSignupCaptcha" title="Refresh Captcha">
                            <i class="fas fa-redo"></i>
                        </button>
                        <input type="text" name="captcha" class="form-control" placeholder="Enter captcha" required value="<?php echo isset($_POST['captcha']) ? htmlspecialchars($_POST['captcha']) : ''; ?>">
                    </div>
                    <small style="color: var(--gray); font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                        <i class="fas fa-info-circle"></i> Enter the code shown above
                    </small>
                </div>

                <button type="submit" name="signup" class="btn btn-primary" id="signupBtn">
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </button>

                <div class="form-footer">
                    By signing up, you agree to our 
                    <a href="tac.php" target="_blank" style="color: var(--primary); text-decoration: none; font-weight: 600; transition: var(--transition);">
                        Terms of Service
                    </a> 
                    and 
                    <a href="tac.php" target="_blank" style="color: var(--primary); text-decoration: none; font-weight: 600; transition: var(--transition);">
                        Privacy Policy
                    </a>
                </div>

                <div class="oauth-buttons">
                    <a href="?oauth_demo=google" class="oauth-btn google">
                        <i class="fab fa-google"></i>
                        Google
                    </a>
                    <a href="?oauth_demo=github" class="oauth-btn github">
                        <i class="fab fa-github"></i>
                        GitHub
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Alert System
        function showAlert(title, message, type = 'success', duration = 5000) {
            const alertContainer = document.getElementById('alertContainer');
            const alertId = 'alert-' + Date.now();
            
            const alertHTML = `
                <div class="alert ${type} show" id="${alertId}">
                    <div class="icon">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'}"></i>
                    </div>
                    <div class="content">
                        <div class="title">${title}</div>
                        <div class="message">${message}</div>
                    </div>
                    <button class="close" onclick="closeAlert('${alertId}')">
                        <ion-icon name="close-outline"></ion-icon>
                    </button>
                    <div class="alert-progress"></div>
                </div>
            `;
            
            alertContainer.insertAdjacentHTML('beforeend', alertHTML);
            
            setTimeout(() => {
                closeAlert(alertId);
            }, duration);
        }

        function closeAlert(alertId) {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.classList.remove('show');
                setTimeout(() => {
                    alert.remove();
                }, 500);
            }
        }

        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
        const currentTheme = localStorage.getItem('theme');

        if (currentTheme === 'dark' || (!currentTheme && prefersDarkScheme.matches)) {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
                localStorage.setItem('theme', 'dark');
                showAlert('Theme Changed', 'Dark mode activated', 'success');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
                localStorage.setItem('theme', 'light');
                showAlert('Theme Changed', 'Light mode activated', 'success');
            }
        });

        // Tab Switching
        const tabs = document.querySelectorAll('.auth-tab');
        const forms = document.querySelectorAll('.auth-form');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const targetTab = tab.getAttribute('data-tab');
                
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                forms.forEach(form => {
                    form.classList.remove('active');
                    if (form.id === targetTab + 'Form') {
                        form.classList.add('active');
                    }
                });
            });
        });

        // Password Toggle Visibility - FIXED
        const loginPasswordToggle = document.getElementById('loginPasswordToggle');
        const loginPasswordInput = document.getElementById('login_password');
        
        const signupPasswordToggle = document.getElementById('signupPasswordToggle');
        const signupPasswordInput = document.getElementById('password');

        function togglePasswordVisibility(toggle, input) {
            toggle.addEventListener('click', () => {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                const icon = toggle.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
        }

        togglePasswordVisibility(loginPasswordToggle, loginPasswordInput);
        togglePasswordVisibility(signupPasswordToggle, signupPasswordInput);

        // Password Strength Indicator
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('passwordStrengthBar');

        passwordInput.addEventListener('input', () => {
            const password = passwordInput.value;
            let strength = 0;
            
            if (password.length >= 6) strength += 1;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 1;
            if (password.match(/\d/)) strength += 1;
            if (password.match(/[^a-zA-Z\d]/)) strength += 1;
            
            strengthBar.className = 'password-strength-bar';
            
            if (password.length === 0) {
                strengthBar.style.width = '0';
            } else if (strength <= 1) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 2) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        });

        // Refresh Captcha
        function refreshCaptcha(formType) {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            let captcha = '';
            for (let i = 0; i < 6; i++) {
                captcha += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            // Update the captcha display
            const captchaElement = document.getElementById(formType + 'Captcha');
            if (captchaElement) {
                captchaElement.textContent = captcha;
            }
            
            // Send AJAX request to update session
            fetch('refresh_captcha.php?captcha=' + captcha)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Captcha updated in session');
                    }
                })
                .catch(error => {
                    console.error('Error updating captcha:', error);
                });
        }

        // Add refresh buttons
        document.getElementById('refreshLoginCaptcha').addEventListener('click', () => {
            refreshCaptcha('login');
            showAlert('Captcha Refreshed', 'New security code generated', 'success');
        });

        document.getElementById('refreshSignupCaptcha').addEventListener('click', () => {
            refreshCaptcha('signup');
            showAlert('Captcha Refreshed', 'New security code generated', 'success');
        });

        // Form submission
        const signupForm = document.getElementById('signupForm');
        const loginForm = document.getElementById('loginForm');
        const signupBtn = document.getElementById('signupBtn');
        const loginBtn = document.getElementById('loginBtn');

        signupForm.addEventListener('submit', (e) => {
            const password = passwordInput.value;
            if (password.length < 6) {
                e.preventDefault();
                showAlert('Validation Error', 'Password must be at least 6 characters long', 'error');
                passwordInput.focus();
            } else {
                signupBtn.classList.add('loading');
                signupBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
            }
        });

        loginForm.addEventListener('submit', (e) => {
            const email = document.getElementById('login_email').value;
            const password = document.getElementById('login_password').value;
            
            if (!email || !password) {
                e.preventDefault();
                showAlert('Validation Error', 'Please fill in all fields', 'error');
            } else {
                loginBtn.classList.add('loading');
                loginBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
            }
        });

        // Create floating particles
        function createParticles() {
            const container = document.getElementById('particlesContainer');
            const colors = ['#6366f1', '#8b5cf6', '#f59e0b', '#10b981'];
            
            for (let i = 0; i < 30; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random properties
                const size = Math.random() * 8 + 2;
                const color = colors[Math.floor(Math.random() * colors.length)];
                const left = Math.random() * 100;
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 5;
                
                // Apply styles
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.background = color;
                particle.style.left = `${left}%`;
                particle.style.animationDuration = `${duration}s`;
                particle.style.animationDelay = `${delay}s`;
                
                container.appendChild(particle);
            }
        }

        // Initialize particles
        createParticles();

        // Show welcome message
        window.addEventListener('load', () => {
            // showAlert('Welcome to CodeSphere!', 'Please login or create an account to continue', 'success');
        });
    </script>
</body>
</html>