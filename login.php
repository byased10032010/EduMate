<?php
/**
 * EduMate - Login (front-end + backend)
 * - Validates email + password against users table
 * - Logs attempt, throttles after 5 failed tries (15 min window)
 * - Redirects to role-specific dashboard on success
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// If already logged in, go to dashboard
if (is_logged_in()) {
    header('Location: ' . role_redirect($_SESSION['user']['role']));
    exit;
}

$email = '';
$errors = [];
$flashes = flash_pull();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? '';
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if (!csrf_check(is_string($token) ? $token : null)) {
        $errors[] = 'Session expired. Please refresh and try again.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors) && is_login_throttled($email)) {
        $errors[] = 'Too many failed attempts. Please try again in a few minutes.';
    }

    if (empty($errors)) {
        try {
            $stmt = db()->prepare('SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, (string)$user['password_hash'])) {
                record_login_attempt($email, false);
                $errors[] = 'Invalid email or password.';
            } else {
                // Optional rehash if cost changed
                if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
                    $upd->execute([':h' => $newHash, ':id' => $user['id']]);
                }
                record_login_attempt($email, true);
                login_user($user, $remember);
                flash_set('success', 'Welcome back, ' . $user['full_name'] . '!');
                header('Location: ' . role_redirect((string)$user['role']));
                exit;
            }
        } catch (Throwable $e) {
            error_log('Login error: ' . $e->getMessage());
            $errors[] = 'Something went wrong. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · EduMate</title>
    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
    <meta name="view-transition" content="same-origin">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..900;1,6..96,400..900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=7">
    <link rel="stylesheet" href="assets/css/theme-switch.css?v=6">
</head>
<body>
    <a class="back-home" href="index.php"><ion-icon name="arrow-back-outline"></ion-icon> Back to Home</a>
    <div style="position: fixed; top: 18px; right: 18px; z-index: 100;">
        <label class="theme-switch" aria-label="Toggle dark mode">
            <input type="checkbox" class="theme-switch__checkbox">
            <div class="theme-switch__container">
                <div class="theme-switch__clouds"></div>
                <div class="theme-switch__stars-container"><svg viewBox="0 0 144 55" fill="none"><path d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688Z" fill="currentColor"/></svg></div>
                <div class="theme-switch__circle-container"><div class="theme-switch__sun-moon-container"><div class="theme-switch__moon"><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div></div></div></div>
            </div>
        </label>
    </div>

    <main class="auth-shell">
        <!-- LEFT: marketing aside -->
        <aside class="auth-aside">
            <div class="brand">
                <span class="logo-dot">
                    <img src="favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
                </span>
                EduMate
            </div>

            <div class="aside-hero">
                <h1>Welcome back to your study hub.</h1>
                <p>Log in to pick up where you left off — your AI companion, courses, and progress are all waiting.</p>
                <ul class="aside-features">
                    <li><ion-icon name="bulb-outline"></ion-icon> Personalized AI study plans</li>
                    <li><ion-icon name="chatbubbles-outline"></ion-icon> 24/7 doubt-solving chat</li>
                    <li><ion-icon name="trending-up-outline"></ion-icon> Track progress in real time</li>
                </ul>
            </div>

            <div class="aside-foot">© <?php echo date('Y'); ?> EduMate. Learning, amplified.</div>
        </aside>

        <!-- RIGHT: form -->
        <section class="auth-main">
            <div style="text-align: center; margin-bottom: 24px;">
                <a href="index.php" class="logo" aria-label="EduMate home" style="display:inline-flex; align-items:center; gap:12px; font-family:'Bodoni Moda', serif; font-size:32px; font-weight:700; color:var(--text-primary); text-decoration:none;">
                    <span class="logo-dot" style="width:36px; height:36px; border-radius:10px; background:var(--text-primary); display:grid; place-items:center;">
                        <img src="favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
                    </span>
                    EduMate
                </a>
            </div>
            
            <div class="auth-tabs" style="align-self: center;">
                <a href="login.php" class="active">Login</a>
                <a href="register.php">Sign Up</a>
            </div>

            <div class="auth-header">
                <h2>Sign in to your account</h2>
                <p>Enter your credentials to access your dashboard.</p>
            </div>

            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?php echo htmlspecialchars($f['type']); ?>">
                    <ion-icon name="information-circle-outline"></ion-icon>
                    <span><?php echo htmlspecialchars($f['message']); ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($errors)): ?>
                <div class="flash flash-error">
                    <ion-icon name="alert-circle-outline"></ion-icon>
                    <ul class="flash-list">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrap">
                        <ion-icon name="mail-outline" class="field-icon"></ion-icon>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="you@example.com"
                            value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                            autocomplete="email"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <ion-icon name="lock-closed-outline" class="field-icon"></ion-icon>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="toggle-password" aria-label="Show password" data-target="password">
                            <ion-icon name="eye-outline"></ion-icon>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="cbx-container">
                        <input type="checkbox" name="remember" id="remember" value="1" style="display: none;">
                        <label for="remember" class="check">
                            <svg width="18px" height="18px" viewBox="0 0 18 18">
                                <path d="M1,9 L1,3.5 C1,2 2,1 3.5,1 L14.5,1 C16,1 17,2 17,3.5 L17,14.5 C17,16 16,17 14.5,17 L3.5,17 C2,17 1,16 1,14.5 L1,9 Z"></path>
                                <polyline points="1 9 7 14 15 4"></polyline>
                            </svg>
                        </label>
                        <label for="remember" style="font-size: 13px; color: var(--text-primary); cursor: pointer; user-select: none; margin-left: 8px;">
                            Remember me
                        </label>
                    </div>
                    <a href="#">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary">
                    <ion-icon name="log-in-outline"></ion-icon> Sign In
                </button>


                <p class="auth-foot">
                    New to EduMate? <a href="register.php">Create an account</a>
                </p>
            </form>
        </section>
    </main>

    <script>
    (function () {
        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-target'));
                if (!target) return;
                var isPwd = target.type === 'password';
                target.type = isPwd ? 'text' : 'password';
                btn.innerHTML = isPwd ? '<ion-icon name="eye-off-outline"></ion-icon>' : '<ion-icon name="eye-outline"></ion-icon>';
            });
        });
    })();
    </script>
    <script src="assets/js/theme-toggle.js"></script>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</body>
</html>
