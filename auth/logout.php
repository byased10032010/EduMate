<?php
/**
 * Logout Handler
 */
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME_CODESPHERE', 'codesphere');
define('DB_NAME_USER_REPOS', 'user_repos');
define('DB_NAME_NOTIFICATIONS', 'notifications');

// Database connection function
function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Log logout activity
if (isset($_SESSION['user_id'])) {
    try {
        $userId = $_SESSION['user_id'];
        
        // Log activity in main database
        $mainDB = getDBConnection(DB_NAME_CODESPHERE);
        $stmt = $mainDB->prepare("
            INSERT INTO global_activity (user_id, activity_type, visibility)
            VALUES (?, 'user_logout', 'private')
        ");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $stmt->close();
        
        // Mark session as expired in user_repos database
        $usersDB = getDBConnection(DB_NAME_USER_REPOS);
        $sessionId = session_id();
        $stmt = $usersDB->prepare("
            UPDATE user_sessions SET expires_at = NOW() WHERE session_id = ?
        ");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $stmt->close();
        
        // Close database connections
        $mainDB->close();
        $usersDB->close();
        
    } catch (Exception $e) {
        error_log("Logout error: " . $e->getMessage());
    }
}

// Clear session
session_unset();
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login
header('Location: auth.php');
exit();
?>