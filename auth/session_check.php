<?php
session_start();

// Check if session exists but user has been inactive for 5 days
if (isset($_SESSION['last_activity'])) {
    $inactive = 432000; // 5 days in seconds
    $session_life = time() - $_SESSION['last_activity'];
    
    if ($session_life > $inactive) {
        // Session expired after 5 days of inactivity
        session_unset();
        session_destroy();
        header("Location: auth.php?error=session_expired");
        exit();
    }
    
    // Update last activity time stamp
    $_SESSION['last_activity'] = time();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.php");
    exit();
}
?>