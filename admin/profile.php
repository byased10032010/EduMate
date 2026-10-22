<?php
/**
 * EduMate - Admin profile (view + edit + change password)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('admin');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.'); header('Location: profile.php'); exit;
    }
    if ($action === 'update') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $bio   = trim((string)($_POST['bio'] ?? ''));
        if (mb_strlen($name) < 2) flash_set('error', 'Name too short.');
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) flash_set('error', 'Invalid email.');
        else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id');
            $check->execute([':e' => $email, ':id' => $uid]);
            if ($check->fetch()) flash_set('error', 'Email already in use.');
            else {
                $pdo->prepare('UPDATE users SET full_name=:n, email=:e, bio=:b WHERE id=:id')
                    ->execute([':n' => $name, ':e' => $email, ':b' => mb_substr($bio, 0, 500), ':id' => $uid]);
                $_SESSION['user']['full_name'] = $name;
                $_SESSION['user']['email'] = $email;
                flash_set('success', 'Profile updated.');
            }
        }
    } elseif ($action === 'password') {
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $cf  = (string)($_POST['confirm_password'] ?? '');
        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $row->execute([':id' => $uid]);
        $hash = $row->fetchColumn();
        if (!password_verify($cur, (string)$hash)) flash_set('error', 'Current password incorrect.');
        elseif (strlen($new) < 8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[a-z]/',$new) || !preg_match('/\d/',$new)) flash_set('error', 'Password must be 8+ chars with upper, lower, and a digit.');
        elseif ($new !== $cf) flash_set('error', 'Passwords do not match.');
        else {
            $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $uid]);
            flash_set('success', 'Password changed.');
        }
    }
    header('Location: profile.php'); exit;
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $uid]);
$u = $stmt->fetch();
[$c1, $c2] = user_avatar_gradient($uid);
$initials  = user_initials($u['full_name']);

render_dashboard_header('Profile', admin_nav(), 'profile', ['Home', 'Profile']);
?>
<div class="panel-grid">
    <section class="panel">
        <h2>Profile details</h2>
        <form method="POST" action="profile.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update">
            <div class="form-grid">
                <div class="form-group">
                    <label>Full name</label>
                    <input class="form-control" name="full_name" value="<?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input class="form-control" type="email" name="email" value="<?php echo htmlspecialchars($u['email'], ENT_QUOTES); ?>" required>
                </div>
                <div class="form-group span-2">
                    <label>Bio</label>
                    <textarea class="form-control" name="bio" maxlength="500"><?php echo htmlspecialchars($u['bio'] ?? '', ENT_QUOTES); ?></textarea>
                </div>
            </div>
            <button class="btn btn-primary" type="submit" style="margin-top:14px;"><ion-icon name="save-outline"></ion-icon> Save</button>
        </form>
    </section>
    <section class="panel">
        <h2>Identity</h2>
        <div style="display:flex; align-items:center; gap:14px;">
            <div style="width:64px; height:64px; border-radius:18px; background:linear-gradient(135deg, <?php echo $c1; ?>, <?php echo $c2; ?>); color:#fff; display:grid; place-items:center; font-size:22px; font-weight:700; box-shadow: 0 6px 18px rgba(0,0,0,.12);">
                <?php echo htmlspecialchars($initials, ENT_QUOTES); ?>
            </div>
            <div>
                <div style="font-weight:700; font-size:16px;"><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?></div>
                <div style="font-size:13px; color:var(--text-light);">Role: <span class="tag">Admin</span></div>
                <div style="font-size:13px; color:var(--text-light);">Joined: <?php echo htmlspecialchars(date('M j, Y', strtotime($u['created_at'])), ENT_QUOTES); ?></div>
            </div>
        </div>
    </section>
</div>
<section class="panel">
    <h2>Change password</h2>
    <form method="POST" action="profile.php" style="max-width:520px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="password">
        <div class="form-grid" style="grid-template-columns:1fr;">
            <div class="form-group"><label>Current password</label><input class="form-control" type="password" name="current_password" required></div>
            <div class="form-group"><label>New password</label><input class="form-control" type="password" name="new_password" minlength="8" required></div>
            <div class="form-group"><label>Confirm new password</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div>
        </div>
        <button class="btn btn-primary" type="submit"><ion-icon name="key-outline"></ion-icon> Update password</button>
    </form>
</section>
<?php render_dashboard_footer(); ?>

