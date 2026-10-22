<?php
// Quick check script for live sessions
require_once __DIR__ . '/../includes/auth.php';

$pdo = db();

// Check live_sessions structure
echo "Checking live_sessions table...\n";
try {
    $cols = $pdo->query("DESCRIBE live_sessions")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nChecking live_attendance table...\n";
try {
    $cols = $pdo->query("DESCRIBE live_attendance")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>