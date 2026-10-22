<?php
/**
 * EduMate - Register / Sign Up (front-end + backend)
 * - Validates input (name, email, password strength, role)
 * - Hashes password with password_hash()
 * - Creates user in DB, auto-logs in, redirects to role dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: ' . role_redirect($_SESSION['user']['role']));
    exit;
}

$name  = '';
$email = '';
$role  = 'student';
$errors = [];
$flashes = flash_pull();

$validRoles = ['student', 'teacher']; // admin cannot self-register

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token         = $_POST['csrf_token'] ?? '';
    $name          = trim((string)($_POST['full_name'] ?? ''));
    $email         = trim((string)($_POST['email'] ?? ''));
    $password      = (string)($_POST['password'] ?? '');
    $confirm       = (string)($_POST['confirm_password'] ?? '');
    $role          = (string)($_POST['role'] ?? 'student');
    $agreed        = !empty($_POST['terms']);

    if (!csrf_check(is_string($token) ? $token : null)) {
        $errors[] = 'Session expired. Please refresh and try again.';
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $errors[] = 'Please enter your full name (2–120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!in_array($role, $validRoles, true)) {
        $errors[] = 'Invalid role selected.';
    }
    // Password strength: min 8, upper, lower, digit
    if (mb_strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$agreed) {
        $errors[] = 'You must agree to the terms and privacy policy.';
    }

    $class = null;
    $board = null;
    if ($role === 'student') {
        $class = isset($_POST['class']) ? (int)$_POST['class'] : null;
        $board = isset($_POST['board']) ? trim((string)$_POST['board']) : null;
        if ($class === null || $class < 5 || $class > 10) {
            $errors[] = 'Please select a valid class (5-10).';
        }
        if (empty($board) || !in_array($board, get_all_boards(), true)) {
            $errors[] = 'Please select a valid educational board.';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = db()->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, class, board) VALUES (:n, :e, :p, :r, :cl, :bo)'
                );
                $ins->execute([
                    ':n' => $name,
                    ':e' => $email,
                    ':p' => $hash,
                    ':r' => $role,
                    ':cl' => $class,
                    ':bo' => $board,
                ]);
                $newId = (int)db()->lastInsertId();

                $user = [
                    'id'        => $newId,
                    'full_name' => $name,
                    'email'     => $email,
                    'role'      => $role,
                ];
                login_user($user);
                flash_set('success', 'Welcome to EduMate, ' . $name . '!');
                header('Location: ' . role_redirect($role));
                exit;
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'users') && str_contains($e->getMessage(), 'email')) {
                $errors[] = 'An account with that email address already exists.';
            } else {
                error_log('Register error: ' . $e->getMessage());
                $errors[] = 'Could not create the account. Please try again.';
            }
        } catch (Throwable $e) {
            error_log('Register error: ' . $e->getMessage());
            $errors[] = 'Could not create the account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up · EduMate</title>
    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
    <meta name="view-transition" content="same-origin">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..900;1,6..96,400..900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=8">
    <link rel="stylesheet" href="assets/css/custom-select.css?v=1">
    <link rel="stylesheet" href="assets/css/theme-switch.css?v=6">
</head>
<body>
    <a class="back-home" href="index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
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
        <aside class="auth-aside">
            <div class="brand">
                <span class="logo-dot">
                    <img src="favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">
                </span>
                EduMate
            </div>
            <div class="aside-hero">
                <h1>Start learning smarter today.</h1>
                <p>Create a free account in seconds and unlock your personal AI study companion.</p>
                <ul class="aside-features">
                    <li><i class="fas fa-rocket"></i> Set up in under a minute</li>
                    <li><i class="fas fa-shield-halved"></i> Your data stays private & secure</li>
                    <li><i class="fas fa-gift"></i> Free tier with no credit card</li>
                </ul>
            </div>
            <div class="aside-foot">© <?php echo date('Y'); ?> EduMate. Learning, amplified.</div>
        </aside>

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
                <a href="login.php">Login</a>
                <a href="register.php" class="active">Sign Up</a>
            </div>

            <div class="auth-header">
                <h2>Create your account</h2>
                <p>Choose a role and start your journey.</p>
            </div>

            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?php echo htmlspecialchars($f['type']); ?>">
                    <i class="fas fa-info-circle"></i>
                    <span><?php echo htmlspecialchars($f['message']); ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($errors)): ?>
                <div class="flash flash-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <ul class="flash-list">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" novalidate>
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label>I am a…</label>
                    <div class="role-picker">
                        <input type="radio" id="role-student" name="role" value="student" <?php echo $role === 'student' ? 'checked' : ''; ?>>
                        <label for="role-student">
                            <i class="fas fa-user-graduate"></i>
                            Student
                        </label>

                        <input type="radio" id="role-teacher" name="role" value="teacher" <?php echo $role === 'teacher' ? 'checked' : ''; ?>>
                        <label for="role-teacher">
                            <i class="fas fa-chalkboard-teacher"></i>
                            Teacher
                        </label>
                    </div>
                </div>

                <div class="form-group" id="studentFields" style="<?php echo $role === 'student' ? '' : 'display: none;'; ?>">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label for="class">Class</label>
                            <div class="custom-select" data-name="class">
                                <input type="hidden" name="class" id="class" value="<?php echo isset($_POST['class']) ? htmlspecialchars($_POST['class'], ENT_QUOTES) : ''; ?>">
                                <button type="button" class="custom-select__trigger" aria-haspopup="listbox">
                                    <i class="fas fa-layer-group field-icon"></i>
                                    <span class="custom-select__label"><?php echo isset($_POST['class']) ? 'Class ' . htmlspecialchars($_POST['class'], ENT_QUOTES) : 'Select Class'; ?></span>
                                    <svg class="custom-select__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
                                <ul class="custom-select__options" role="listbox">
                                    <?php for ($c = 5; $c <= 10; $c++): ?>
                                        <li role="option" data-value="<?php echo $c; ?>" <?php echo (isset($_POST['class']) && (int)$_POST['class'] === $c) ? 'class="selected"' : ''; ?>>Class <?php echo $c; ?></li>
                                    <?php endfor; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="board">Board</label>
                            <div class="custom-select" data-name="board">
                                <input type="hidden" name="board" id="board" value="<?php echo isset($_POST['board']) ? htmlspecialchars($_POST['board'], ENT_QUOTES) : ''; ?>">
                                <button type="button" class="custom-select__trigger" aria-haspopup="listbox">
                                    <i class="fas fa-university field-icon"></i>
                                    <span class="custom-select__label"><?php echo isset($_POST['board']) ? htmlspecialchars($_POST['board'], ENT_QUOTES) : 'Select Board'; ?></span>
                                    <svg class="custom-select__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
                                <ul class="custom-select__options" role="listbox">
                                    <?php foreach (get_all_boards() as $b): ?>
                                        <li role="option" data-value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>" <?php echo (isset($_POST['board']) && $_POST['board'] === $b) ? 'class="selected"' : ''; ?>><?php echo htmlspecialchars($b, ENT_QUOTES); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <div class="input-wrap">
                        <i class="fas fa-user field-icon"></i>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            class="form-control"
                            placeholder="Jane Doe"
                            value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                            autocomplete="name"
                            maxlength="120"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope field-icon"></i>
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
                        <i class="fas fa-lock field-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="At least 8 characters"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                        <button type="button" class="toggle-password" aria-label="Show password" data-target="password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="pw-strength" id="pwStrength" aria-hidden="true">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <div class="pw-hint">Use 8+ characters with uppercase, lowercase, and a number.</div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock field-icon"></i>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-control"
                            placeholder="Re-enter your password"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                        <button type="button" class="toggle-password" aria-label="Show password" data-target="confirm_password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-row">
                    <label class="remember">
                        <div class="cbx-container">
                            <input type="checkbox" id="cbx-terms" name="terms" value="1" required style="display: none;">
                            <label for="cbx-terms" class="check">
                                <svg width="18px" height="18px" viewBox="0 0 18 18">
                                    <path d="M1,9 L1,3.5 C1,2 2,1 3.5,1 L14.5,1 C16,1 17,2 17,3.5 L17,14.5 C17,16 16,17 14.5,17 L3.5,17 C2,17 1,16 1,14.5 L1,9 Z"></path>
                                    <polyline points="1 9 7 14 15 4"></polyline>
                                </svg>
                            </label>
                        </div>
                        I agree to the <a href="#">Terms</a> &amp; <a href="#">Privacy Policy</a>
                    </label>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>

                <p class="auth-foot">
                    Already have an account? <a href="login.php">Sign in</a>
                </p>
            </form>
        </section>
    </main>

    <script>
    (function () {
        // Show/hide password
        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('data-target'));
                if (!target) return;
                var isPwd = target.type === 'password';
                target.type = isPwd ? 'text' : 'password';
                btn.innerHTML = isPwd ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
            });
        });

        // Password strength meter
        var pw = document.getElementById('password');
        var meter = document.getElementById('pwStrength');
        if (pw && meter) {
            pw.addEventListener('input', function () {
                var v = pw.value;
                var score = 0;
                if (v.length >= 8) score++;
                if (/[A-Z]/.test(v)) score++;
                if (/[a-z]/.test(v)) score++;
                if (/\d/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v) && v.length >= 10) score++;
                meter.classList.remove('s1','s2','s3','s4');
                if (score >= 4) meter.classList.add('s4');
                else if (score === 3) meter.classList.add('s3');
                else if (score === 2) meter.classList.add('s2');
                else if (score >= 1) meter.classList.add('s1');
            });
        }

        // Dynamically toggle student-only fields
        var roleStudent = document.getElementById('role-student');
        var roleTeacher = document.getElementById('role-teacher');
        var studentFields = document.getElementById('studentFields');
        if (roleStudent && roleTeacher && studentFields) {
            function toggleFields() {
                if (roleStudent.checked) {
                    studentFields.style.display = 'block';
                    document.getElementById('class').setAttribute('required', 'required');
                    document.getElementById('board').setAttribute('required', 'required');
                } else {
                    studentFields.style.display = 'none';
                    document.getElementById('class').removeAttribute('required');
                    document.getElementById('board').removeAttribute('required');
                }
            }
            roleStudent.addEventListener('change', toggleFields);
            roleTeacher.addEventListener('change', toggleFields);
            // Run on initial load
            toggleFields();
        }
    })();
    </script>
    <script src="assets/js/custom-select.js"></script>
    <script src="assets/js/theme-toggle.js"></script>
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</body>
</html>
