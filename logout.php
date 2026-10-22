<?php
/**
 * EduMate - Logout
 * Destroys the session and bounces back to the login page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

logout_user();

// Start a fresh session just to deliver a friendly flash message
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
flash_set('info', 'You have been signed out.');

header('Location: ' . base_url('login.php'));
exit;
