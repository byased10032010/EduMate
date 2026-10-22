<?php
/**
 * EduMate - Database connection (PDO)
 *
 * Auto-detects a working MySQL/MariaDB connection on this host.
 * - Tries `host=localhost` first (uses the named pipe on Windows MariaDB,
 *   or the Unix socket on Linux/macOS). Most reliable on XAMPP.
 * - Falls back to `host=127.0.0.1` over TCP for systems that disable pipes.
 * - Tries common ports: 3306 (default), then 3307 (some XAMPP installs).
 *
 * Adjust DB_USER / DB_PASS below if your MySQL has credentials.
 */

declare(strict_types=1);

const DB_NAME = 'edumate';
const DB_USER = 'root';
const DB_PASS = '';

// Gemini API Key for the AI Tutor
const AI_API_KEY = 'AQ.Ab8RN6IUO-C6NbTONEYx1FMXbvSNT943ZBTeT3sFRNdUGRZrCg'; // Replace with actual key

// Default higher Gemini model (gemini-3.8-flash, gemini-3.6-flash, gemini-3.5-flash, gemini-3.1-pro-preview, gemini-2.5-flash)
const AI_MODEL = 'gemini-3.6-flash';


function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $candidates = [
        // XAMPP / Windows MariaDB: pipe-based "localhost" usually wins
        ['host' => 'localhost', 'port' => '3306'],
        ['host' => '127.0.0.1', 'port' => '3306'],
        // Some XAMPP installs use 3307
        ['host' => 'localhost', 'port' => '3307'],
        ['host' => '127.0.0.1', 'port' => '3307'],
    ];

    $lastError = null;
    foreach ($candidates as $c) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $c['host'], $c['port'], DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            // 1049 = unknown database; remember the *connection* error if any
            if ($e->getCode() !== '1049') {
                $lastError = $e;
            } else {
                // Connected to the server but the database is missing.
                // Try to create it on the fly using the server-only DSN.
                if (db_ensure_database($c['host'], $c['port'])) {
                    try {
                        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]);
                        return $pdo;
                    } catch (PDOException $e2) {
                        $lastError = $e2;
                    }
                } else {
                    $lastError = $e;
                }
            }
        }
    }

    error_log('DB connection failed: ' . ($lastError ? $lastError->getMessage() : 'unknown'));
    db_friendly_error($lastError);
    exit;
}

function db_ensure_database(string $host, string $port): bool
{
    try {
        $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
        $tmp = new PDO($serverDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $tmp->exec(
            'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        return true;
    } catch (Throwable $e) {
        error_log('db_ensure_database failed: ' . $e->getMessage());
        return false;
    }
}

function db_friendly_error(?Throwable $e): void
{
    http_response_code(500);
    $err = $e ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : '';
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>Database connection failed · EduMate</title>
<style>
  body{font-family:Poppins,system-ui,sans-serif;background:#f8f9fe;color:#1a1a2e;
       margin:0;padding:48px;display:flex;align-items:center;justify-content:center;min-height:100vh}
  .card{background:#fff;border-radius:16px;padding:32px;max-width:640px;
        box-shadow:0 20px 60px rgba(102,126,234,.18);border:1px solid rgba(102,126,234,.12)}
  h1{font-family:'Bodoni Moda',serif;margin:0 0 8px;font-size:28px}
  p{line-height:1.6;color:#4a4a6a}
  code{background:#f0f0fa;padding:2px 6px;border-radius:4px;font-size:13px}
  ol{color:#4a4a6a;line-height:1.8;padding-left:20px}
  .err{background:#ffe7ea;color:#b21f3a;border:1px solid #f4b5c0;
       border-radius:10px;padding:12px 14px;font-size:13px;margin-top:14px}
</style></head><body>
<div class="card">
  <h1>Database connection failed</h1>
  <p>PHP couldn't reach MySQL on this machine. Quick checklist:</p>
  <ol>
    <li>Open the <strong>XAMPP Control Panel</strong> and click <em>Start</em> next to <strong>MySQL</strong>.</li>
    <li>Make sure the <code>edumate</code> database exists. The app will create it automatically on first run if MySQL is reachable.</li>
    <li>If your MySQL has a root password, edit <code>config/db.php</code> and set <code>DB_PASS</code>.</li>
    <li>If your MySQL runs on a non-default port, edit the <code>\$candidates</code> array in <code>config/db.php</code>.</li>
  </ol>
HTML;
    if ($err !== '') {
        echo '<div class="err"><strong>MySQL says:</strong> ' . $err . '</div>';
    }
    echo '</div></body></html>';
}
