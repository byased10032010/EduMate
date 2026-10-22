<?php
/**
 * End-to-end smoke test for the EduMate auth system.
 * Uses fsockopen (no curl) to simulate a real browser.
 *
 * Assumes the dev server is running on http://127.0.0.1:8765.
 *
 * Run: php scripts/e2e_test.php
 */

declare(strict_types=1);

const HOST = '127.0.0.1';
const PORT = 8765;

function http(string $method, string $path, ?array $form = null, ?array &$jar = null): array
{
    $body = $form !== null ? http_build_query($form) : '';
    $headers = [
        "$method $path HTTP/1.1",
        'Host: ' . HOST . ':' . PORT,
        'Connection: close',
        'Accept: text/html',
    ];
    if ($jar) {
        $cookie = [];
        foreach ($jar as $k => $v) {
            $cookie[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $headers[] = 'Cookie: ' . implode('; ', $cookie);
    }
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    $request = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    $errno = 0; $errstr = '';
    $fp = @fsockopen(HOST, PORT, $errno, $errstr, 10);
    if (!$fp) {
        return ['status' => 0, 'headers' => '', 'body' => "ERR $errstr"];
    }
    stream_set_timeout($fp, 10);
    fwrite($fp, $request);
    $raw = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        $raw .= $chunk;
    }
    fclose($fp);

    $parts = explode("\r\n\r\n", $raw, 2);
    $head  = $parts[0] ?? '';
    $b     = $parts[1] ?? '';
    if (stripos($head, 'Transfer-Encoding: chunked') !== false) {
        $decoded = '';
        $cur = $b;
        while (($pos = strpos($cur, "\r\n")) !== false) {
            $len = hexdec(trim(substr($cur, 0, $pos)));
            if ($len === 0) break;
            $decoded .= substr($cur, $pos + 2, $len);
            $cur = substr($cur, $pos + 2 + $len + 2);
        }
        $b = $decoded;
    }

    $status = 0;
    if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $head, $m)) {
        $status = (int)$m[1];
    }

    if ($jar !== null) {
        foreach (explode("\r\n", $head) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $first = explode(';', trim(substr($line, 11)), 2)[0];
                $kv = explode('=', $first, 2);
                if (count($kv) === 2) {
                    $jar[$kv[0]] = $kv[1];
                }
            }
        }
    }

    return ['status' => $status, 'headers' => $head, 'body' => $b];
}

function location_of(array $resp): ?string
{
    if (preg_match('/^Location:\s*([^\r\n]+)/mi', $resp['headers'], $m)) {
        return trim($m[1]);
    }
    return null;
}

function csrf_token_from(string $body): string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) {
        return $m[1];
    }
    return '';
}

function cookie_header_from(array $jar): string
{
    $cookie = [];
    foreach ($jar as $k => $v) {
        $cookie[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    return implode('; ', $cookie);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else     { $fail++; echo "  [FAIL] $label $detail\n"; }
    @ob_flush(); @flush();
}

echo "=== EduMate E2E smoke test ===\n\n";

// 1. GET login page
$jar = [];
$r = http('GET', '/login.php', null, $jar);
check('login.php GET returns 200', $r['status'] === 200, "got {$r['status']}");
check('login.php renders aside hero', str_contains($r['body'], 'Welcome back to your study hub'));
check('login.php does NOT show DB error', !str_contains($r['body'], 'Database connection failed'));
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
check('CSRF token present', $token !== '');

// 2. POST admin login
$r = http('POST', '/login.php', [
    'csrf_token' => $token,
    'email'      => 'admin@edumate.local',
    'password'   => 'Admin@12345',
], $jar);
$loc = location_of($r);
check('admin login returns 302', $r['status'] === 302, "got {$r['status']}");
check('admin login redirects to /admin/dashboard.php',
    $loc === '/admin/dashboard.php' || str_ends_with((string)$loc, '/admin/dashboard.php'),
    "got $loc");

// 3. GET admin dashboard
$r = http('GET', '/admin/dashboard.php', null, $jar);
check('admin dashboard returns 200', $r['status'] === 200, "got {$r['status']}");
check('admin dashboard renders Overview heading', str_contains($r['body'], 'System Overview') || str_contains($r['body'], 'Overview'));
check('admin dashboard requires role (not 403)', !str_contains($r['body'], '403'));

// 4. Logout
$r = http('GET', '/logout.php', null, $jar);
$loc = location_of($r);
check('logout returns 302', $r['status'] === 302);
check('logout redirects to /login.php',
    $loc === '/login.php' || str_ends_with((string)$loc, '/login.php'));

// 5. Register a new student
$jar = [];
$r = http('GET', '/register.php', null, $jar);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
$email = 'e2e_' . bin2hex(random_bytes(4)) . '@test.local';
$r = http('POST', '/register.php', [
    'csrf_token'       => $token,
    'full_name'        => 'E2E Tester',
    'email'            => $email,
    'password'         => 'Strong@123',
    'confirm_password' => 'Strong@123',
    'role'             => 'student',
    'class'            => '8',
    'board'            => 'CBSE',
    'terms'            => '1',
], $jar);
$loc = location_of($r);
check("register new student -> 302", $r['status'] === 302, "got {$r['status']}");
check('register redirects to /student/dashboard.php',
    $loc === '/student/dashboard.php' || str_ends_with((string)$loc, '/student/dashboard.php'),
    "got $loc");

$r = http('GET', '/student/dashboard.php', null, $jar);
check('student dashboard returns 200', $r['status'] === 200, "got {$r['status']}");
check('student dashboard greets the user', str_contains($r['body'], 'Hi, E2E') || str_contains($r['body'], 'Overview'));

// 6. Bad password rejected
$jar = [];
$r = http('GET', '/login.php', null, $jar);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
$r = http('POST', '/login.php', [
    'csrf_token' => $token,
    'email'      => 'admin@edumate.local',
    'password'   => 'WRONG',
], $jar);
check('bad password is rejected (no redirect)',
    $r['status'] === 200 && !preg_match('/^Location:\s*\/admin/mi', $r['headers']));
check('bad password shows error', str_contains($r['body'], 'Invalid email or password'));

// 7. CSRF rejection
$jar = [];
$r = http('GET', '/login.php', null, $jar);
$r = http('POST', '/login.php', [
    'csrf_token' => 'WRONG',
    'email'      => 'admin@edumate.local',
    'password'   => 'Admin@12345',
], $jar);
check('bad CSRF token is rejected', str_contains($r['body'], 'Session expired'));

// 8. Student dashboard pages (login as the demo student)
$jar = [];
$r = http('GET', '/login.php', null, $jar);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
$r = http('POST', '/login.php', [
    'csrf_token' => $token,
    'email'      => 'subhrajit@edumate.local',
    'password'   => 'Student@123',
], $jar);
check('student login -> 302', $r['status'] === 302);
foreach (['dashboard.php', 'courses.php', 'tutor.php', 'quizzes.php', 'assignments.php', 'videos.php', 'progress.php', 'weekly_report.php', 'boards.php', 'profile.php'] as $page) {
    $r = http('GET', '/student/' . $page, null, $jar);
    check("student $page -> 200", $r['status'] === 200, "got {$r['status']}");
    check("student $page does not show DB error", !str_contains($r['body'], 'Database connection failed'));
}
// Profile chip must show ONLY the avatar logo, no email
$r = http('GET', '/student/dashboard.php', null, $jar);
check('profile chip has NO email in sidebar', !preg_match('/profile-chip[^>]*>[\s\S]{0,400}@/', $r['body']));

// 9. Quiz flow: take the first quiz, submit, expect 302 to result page
$r = http('GET', '/student/quizzes.php', null, $jar);
preg_match('/href="quizzes\.php\?id=(\d+)"/', $r['body'], $m);
$qid = (int)($m[1] ?? 0);
if ($qid > 0) {
    $r = http('GET', '/student/quizzes.php?id=' . $qid, null, $jar);
    check("quiz $qid page renders", $r['status'] === 200 && str_contains($r['body'], 'Submit answers'));
    // Fetch the questions to build a payload of all-correct answers
    preg_match_all('/name="q\[(\d+)\]"/', $r['body'], $qm);
    $qids = $qm[1] ?? [];
    // We don't know the correct answers from the page, so we just submit blanks
    // (we're testing the wiring, not the answer).  Submit any answer keys.
    $form = ['action' => 'submit', 'csrf_token' => csrf_token_from($r['body']), 'quiz_id' => (string)$qid];
    foreach ($qids as $qq) $form["q[$qq]"] = 'a';
    $r = http('POST', '/student/quizzes.php?id=' . $qid, $form, $jar);
    check('quiz submit -> 302 to result', $r['status'] === 302, 'got ' . $r['status']);
}

// 10. AI tutor chat: POST a JSON message and check reply
$jsonBody = json_encode(['message' => 'explain photosynthesis']);
$body = '';
$status = 0;
$fp = @fsockopen(HOST, PORT, $e, $s, 10);
if ($fp) {
    $headers = [
        'POST /student/tutor.php HTTP/1.1',
        'Host: ' . HOST . ':' . PORT,
        'Connection: close',
        'Content-Type: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Content-Length: ' . strlen($jsonBody),
    ];
    $cookie = [];
    foreach ($jar as $k => $v) $cookie[] = rawurlencode($k) . '=' . rawurlencode($v);
    if ($cookie) $headers[] = 'Cookie: ' . implode('; ', $cookie);
    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $jsonBody);
    stream_set_timeout($fp, 10);
    while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false) break; $body .= $c; }
    fclose($fp);
    $parts = explode("\r\n\r\n", $body, 2);
    $raw_body = $parts[1] ?? '';
    if (stripos($parts[0] ?? '', 'Transfer-Encoding: chunked') !== false) {
        $decoded = ''; $cur = $raw_body;
        while (($pos = strpos($cur, "\r\n")) !== false) {
            $len = hexdec(trim(substr($cur, 0, $pos)));
            if ($len === 0) break;
            $decoded .= substr($cur, $pos + 2, $len);
            $cur = substr($cur, $pos + 2 + $len + 2);
        }
        $raw_body = $decoded;
    }
    $body = $raw_body;
    if (preg_match('#^HTTP/[\d.]+\s+(\d+)#', $parts[0] ?? '', $m)) $status = (int)$m[1];
}
$resp = json_decode($body, true);
check('AI tutor returns 200', $status === 200, "got $status");
check('AI tutor returns ok=true', is_array($resp) && ($resp['ok'] ?? false) === true);
check('AI tutor reply mentions photosynthesis',
    is_array($resp) && str_contains(strtolower($resp['reply'] ?? ''), 'photosynthesis'));

// 11. Teacher dashboard pages
$jar = [];
$r = http('GET', '/login.php', null, $jar);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
$r = http('POST', '/login.php', [
    'csrf_token' => $token,
    'email'      => 'ananya.rao@edumate.local',
    'password'   => 'Teacher@123',
], $jar);
check('teacher login -> 302', $r['status'] === 302);
foreach (['dashboard.php', 'classes.php', 'courses.php', 'curriculum.php', 'assignments.php', 'analytics.php', 'videos.php', 'profile.php'] as $page) {
    $r = http('GET', '/teacher/' . $page, null, $jar);
    check("teacher $page -> 200", $r['status'] === 200, "got {$r['status']}");
}

// 12. Admin dashboard pages
$jar = [];
$r = http('GET', '/login.php', null, $jar);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m);
$token = $m[1] ?? '';
$r = http('POST', '/login.php', [
    'csrf_token' => $token,
    'email'      => 'admin@edumate.local',
    'password'   => 'Admin@12345',
], $jar);
check('admin login -> 302', $r['status'] === 302);
foreach (['dashboard.php', 'users.php', 'security.php', 'database.php', 'settings.php', 'profile.php'] as $page) {
    $r = http('GET', '/admin/' . $page, null, $jar);
    check("admin $page -> 200", $r['status'] === 200, "got {$r['status']}");
}

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
