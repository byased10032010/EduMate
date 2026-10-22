<?php
/**
 * Run this once after importing database.sql to create (or reset)
 * the default admin account.
 *
 * Usage (browser):
 *   http://localhost/WEBPAGES/eduMate/scripts/create_admin.php
 *
 * After running, DELETE this file for safety.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// CHANGE THESE if you want different credentials
$email    = 'admin@edumate.local';
$password = 'Admin@12345';
$name     = 'System Admin';

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = db()->prepare(
        'INSERT INTO users (full_name, email, password_hash, role)
         VALUES (:n, :e, :p, "admin")
         ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name),
            password_hash = VALUES(password_hash),
            is_active = 1'
    );
    $stmt->execute([':n' => $name, ':e' => $email, ':p' => $hash]);

    echo "<h2>✅ Admin account ready</h2>";
    echo "<p><strong>Email:</strong> {$email}</p>";
    echo "<p><strong>Password:</strong> {$password}</p>";
    echo "<p style='color:#b21f3a'>⚠ Delete <code>scripts/create_admin.php</code> from your server after running this once.</p>";
} catch (Throwable $e) {
    error_log('create_admin failed: ' . $e->getMessage());
    http_response_code(500);
    echo "<h2>Failed to create admin</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
