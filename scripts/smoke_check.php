<?php
// Simulate a request to login.php and register.php through PHP's built-in server
// via the same include path. We just need to confirm the entry point doesn't
// trigger the "Database connection failed" page.

function load_page(string $script): array {
    // Start a clean session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_GET = $_POST = $_COOKIE = $_SERVER = [];
    // We can't actually run the page without sending HTTP; instead, just
    // require the file with a short-circuit: it will run db() via the helpers.
    // If db() returns without printing the error page, the test passes.
    ob_start();
    try {
        // We can't include the page directly because it sends headers & renders
        // full HTML. So we test the prerequisites: db() works and user lookup works.
        require_once __DIR__ . '/../includes/auth.php';
        $pdo = db();
        $pdo->query('SELECT 1')->fetch();
        return ['ok' => true, 'output' => ob_get_clean()];
    } catch (Throwable $e) {
        return ['ok' => false, 'output' => 'EXCEPTION: ' . $e->getMessage() . "\n" . ob_get_clean()];
    }
}

$r = load_page('login.php');
echo $r['ok'] ? "✅ login.php prerequisites OK (db() works)\n" : "❌ " . $r['output'];
$r = load_page('register.php');
echo $r['ok'] ? "✅ register.php prerequisites OK (db() works)\n" : "❌ " . $r['output'];
