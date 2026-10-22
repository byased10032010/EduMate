<?php
/**
 * EduMate - Authentication helpers
 * - Session bootstrap
 * - CSRF token
 * - Login / logout
 * - Role guards
 * - Login attempt throttling
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Hardened session cookie params
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!is_logged_in() && !empty($_COOKIE['remember_token'])) {
    $parts = explode(':', (string)$_COOKIE['remember_token']);
    if (count($parts) === 2) {
        [$selector, $validator] = $parts;
        try {
            $stmt = db()->prepare('SELECT * FROM auth_tokens WHERE selector = :selector AND expires_at >= NOW() LIMIT 1');
            $stmt->execute([':selector' => $selector]);
            $token = $stmt->fetch();
            
            if ($token && hash_equals((string)$token['validator_hash'], hash('sha256', $validator))) {
                $stmt = db()->prepare('SELECT id, full_name, email, role, is_active FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $token['user_id']]);
                $user = $stmt->fetch();
                if ($user && (int)$user['is_active'] === 1) {
                    login_user($user, false);
                }
            }
        } catch (Throwable $e) {
            error_log('Remember token check failed: ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_check(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ------------------------------------------------------------
// Flash messages
// ------------------------------------------------------------
function flash_set(string $type, string|array $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ------------------------------------------------------------
// Current user
// ------------------------------------------------------------
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('error', 'Please log in to continue.');
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

function require_role(string ...$roles): void
{
    require_login();
    $current = $_SESSION['user']['role'] ?? '';
    if (!in_array($current, $roles, true)) {
        http_response_code(403);
        echo '<h2>403 — Forbidden</h2>';
        echo '<p>You do not have permission to view this page.</p>';
        echo '<p><a href="' . htmlspecialchars(base_url('index.php'), ENT_QUOTES) . '">Go home</a></p>';
        exit;
    }
}

// ------------------------------------------------------------
// Login / Logout
// ------------------------------------------------------------
function login_user(array $user, bool $remember = false): void
{
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];

    // Update last_login
    try {
        $stmt = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
        $stmt->execute([':id' => $user['id']]);
        if ($user['role'] === 'student') {
            update_streak((int)$user['id']);
        }

        if ($remember) {
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $validatorHash = hash('sha256', $validator);
            $expires = time() + (30 * 24 * 60 * 60); // 30 days
            
            $stmt = db()->prepare('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at) VALUES (:uid, :sel, :hash, :exp)');
            $stmt->execute([
                ':uid' => $user['id'],
                ':sel' => $selector,
                ':hash' => $validatorHash,
                ':exp' => date('Y-m-d H:i:s', $expires)
            ]);
            
            setcookie('remember_token', $selector . ':' . $validator, [
                'expires' => $expires,
                'path' => '/',
                'domain' => '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    } catch (Throwable $e) {
        error_log('last_login update failed: ' . $e->getMessage());
    }
}

function logout_user(): void
{
    if (!empty($_COOKIE['remember_token'])) {
        $parts = explode(':', (string)$_COOKIE['remember_token']);
        if (count($parts) === 2) {
            $selector = $parts[0];
            try {
                $stmt = db()->prepare('DELETE FROM auth_tokens WHERE selector = :selector');
                $stmt->execute([':selector' => $selector]);
            } catch (Throwable $e) {}
        }
        setcookie('remember_token', '', time() - 3600, '/');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ------------------------------------------------------------
// Role-based redirect
// ------------------------------------------------------------
function role_redirect(string $role): string
{
    $map = [
        'admin'   => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/dashboard.php',
    ];
    return base_url($map[$role] ?? 'student/dashboard.php');
}

// ------------------------------------------------------------
// URL helper
// ------------------------------------------------------------
function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = rtrim(dirname($script), '/');

    if ($base === '' || $base === '.') {
        $base = '';
    } elseif (in_array(basename($base), ['student', 'teacher', 'admin'], true)) {
        $base = dirname($base);
    }

    if ($path === '') {
        return ($base === '' ? '/' : $base . '/');
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return ($base === '' ? '' : $base) . $path;
}

// ------------------------------------------------------------
// Rate limiting (login attempts)
// ------------------------------------------------------------
function record_login_attempt(string $email, bool $success): void
{
    try {
        $stmt = db()->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, :ok)');
        $stmt->execute([
            ':email' => $email,
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ok'    => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log('record_login_attempt failed: ' . $e->getMessage());
    }
}

function is_login_throttled(string $email, int $maxAttempts = 5, int $windowSeconds = 900): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS c
               FROM login_attempts
              WHERE email = :email
                AND success = 0
                AND attempted_at >= (NOW() - INTERVAL :secs SECOND)'
        );
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':secs', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return ((int)($row['c'] ?? 0)) >= $maxAttempts;
    } catch (Throwable $e) {
        error_log('is_login_throttled failed: ' . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// Activity Logger
// ------------------------------------------------------------
if (!function_exists('record_activity')) {
    function record_activity(int $userId, string $icon, string $title, ?string $detail = null): void
    {
        try {
            $stmt = db()->prepare('INSERT INTO activities (user_id, icon, title, detail) VALUES (:u, :i, :t, :d)');
            $stmt->execute([
                ':u' => $userId,
                ':i' => $icon,
                ':t' => mb_substr($title, 0, 255),
                ':d' => $detail !== null ? mb_substr($detail, 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            error_log('record_activity failed: ' . $e->getMessage());
        }
    }
}

// ------------------------------------------------------------
// Student Streak Tracking
// ------------------------------------------------------------
function update_streak(int $student_id): int
{
    $pdo = db();
    try {
        $stmt = $pdo->prepare('SELECT streak_count, last_active_date FROM users WHERE id = :id');
        $stmt->execute([':id' => $student_id]);
        $row = $stmt->fetch();
        if (!$row) return 0;

        $streak = (int)$row['streak_count'];
        $lastActive = $row['last_active_date'];
        $today = date('Y-m-d');

        if (empty($lastActive)) {
            $streak = 1;
            $upd = $pdo->prepare('UPDATE users SET streak_count = :s, last_active_date = :d WHERE id = :id');
            $upd->execute([':s' => $streak, ':d' => $today, ':id' => $student_id]);
            $title = "Started a new learning streak!";
            $detail = "1 day active";
            if (function_exists('record_activity')) {
                record_activity($student_id, 'fa-fire', $title, $detail);
            } else {
                $pdo->prepare('INSERT INTO activities (user_id, icon, title, detail) VALUES (:u, "fa-fire", :t, :d)')
                    ->execute([':u' => $student_id, ':t' => $title, ':d' => $detail]);
            }
        } else {
            $lastTimestamp = strtotime($lastActive);
            $todayTimestamp = strtotime($today);
            $diffDays = (int)round(($todayTimestamp - $lastTimestamp) / 86400);

            if ($diffDays === 1) {
                $streak += 1;
                $upd = $pdo->prepare('UPDATE users SET streak_count = :s, last_active_date = :d WHERE id = :id');
                $upd->execute([':s' => $streak, ':d' => $today, ':id' => $student_id]);
                $title = "Extended learning streak to {$streak} days!";
                $detail = "Keep it up!";
                if (function_exists('record_activity')) {
                    record_activity($student_id, 'fa-fire', $title, $detail);
                } else {
                    $pdo->prepare('INSERT INTO activities (user_id, icon, title, detail) VALUES (:u, "fa-fire", :t, :d)')
                        ->execute([':u' => $student_id, ':t' => $title, ':d' => $detail]);
                }
            } elseif ($diffDays > 1) {
                $streak = 1;
                $upd = $pdo->prepare('UPDATE users SET streak_count = :s, last_active_date = :d WHERE id = :id');
                $upd->execute([':s' => $streak, ':d' => $today, ':id' => $student_id]);
                $title = "Started a new learning streak!";
                $detail = "1 day active";
                if (function_exists('record_activity')) {
                    record_activity($student_id, 'fa-fire', $title, $detail);
                } else {
                    $pdo->prepare('INSERT INTO activities (user_id, icon, title, detail) VALUES (:u, "fa-fire", :t, :d)')
                        ->execute([':u' => $student_id, ':t' => $title, ':d' => $detail]);
                }
            }
        }
        return $streak;
    } catch (Throwable $e) {
        error_log('update_streak failed: ' . $e->getMessage());
        return 0;
    }
}

function get_all_boards(): array
{
    try {
        return db()->query('SELECT name FROM boards ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN) ?: ['CBSE', 'ICSE', 'State Board'];
    } catch (Throwable $e) {
        return ['CBSE', 'ICSE', 'State Board'];
    }
}


