<?php
/**
 * EduMate - Student: Profile (view + edit + change password)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: profile.php');
        exit;
    }

    if ($action === 'update') {
        // Name, Email, and Class are permanent for students.
        $bio   = trim((string)($_POST['bio'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $dob   = !empty($_POST['dob']) ? trim((string)$_POST['dob']) : null;
        $school = trim((string)($_POST['school'] ?? ''));
        
        $board = isset($_POST['board']) ? trim((string)$_POST['board']) : null;
        if (!in_array($board, get_all_boards(), true)) {
            flash_set('error', 'Invalid Board.');
        } else {
            $pdo->prepare('UPDATE users SET bio = :b, phone = :p, dob = :dob, school = :s, board = :bo WHERE id = :id')
                ->execute([':b' => mb_substr($bio, 0, 500), ':p' => mb_substr($phone, 0, 20), ':dob' => $dob, ':s' => mb_substr($school, 0, 255), ':bo' => $board, ':id' => $uid]);
            record_activity($uid, 'fa-user-gear', 'Updated profile', null);
            flash_set('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $cur  = (string)($_POST['current_password'] ?? '');
        $new  = (string)($_POST['new_password'] ?? '');
        $conf = (string)($_POST['confirm_password'] ?? '');
        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $row->execute([':id' => $uid]);
        $hash = $row->fetchColumn();
        if (!password_verify($cur, (string)$hash))                  flash_set('error', 'Current password is incorrect.');
        elseif (strlen($new) < 8)                                    flash_set('error', 'New password must be 8+ characters.');
        elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) flash_set('error', 'Password must have upper, lower, and a digit.');
        elseif ($new !== $conf)                                      flash_set('error', 'New passwords do not match.');
        else {
            $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $uid]);
            record_activity($uid, 'fa-lock', 'Changed account password', null);
            flash_set('success', 'Password changed.');
        }
    } elseif ($action === 'delete_account') {
        if (empty($_POST['confirm_delete'])) {
            flash_set('error', 'You must check the confirmation box to delete your account.');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
            session_unset();
            session_destroy();
            header('Location: ../login.php');
            exit;
        }
    }
    header('Location: profile.php');
    exit;
}

// Refresh user from DB to show latest values
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $uid]);
$u = $stmt->fetch();

[$c1, $c2] = user_avatar_gradient($uid);
$avatarBg  = $c1;
$initials  = user_initials($u['full_name']);

render_dashboard_header(
    'Profile',
    student_nav(),
    'profile',
    ['Home', 'Profile']
);
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
                    <input class="form-control" type="text" name="full_name" value="<?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>" readonly style="background:var(--bg-hover); color:var(--text-light); cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input class="form-control" type="email" name="email" value="<?php echo htmlspecialchars($u['email'], ENT_QUOTES); ?>" readonly style="background:var(--bg-hover); color:var(--text-light); cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>Phone number</label>
                    <input class="form-control" type="tel" name="phone" value="<?php echo htmlspecialchars($u['phone'] ?? '', ENT_QUOTES); ?>" placeholder="+1 (555) 000-0000" maxlength="20">
                </div>
                <div class="form-group">
                    <label>Date of birth</label>
                    <input class="form-control" type="date" name="dob" value="<?php echo htmlspecialchars($u['dob'] ?? '', ENT_QUOTES); ?>">
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select name="class" class="form-control" disabled style="background:var(--bg-hover); color:var(--text-light); cursor:not-allowed;">
                        <option value="<?php echo (int)$u['class']; ?>" selected>Class <?php echo (int)$u['class']; ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Board</label>
                    <select name="board" class="form-control" required>
                        <?php foreach (get_all_boards() as $b): ?>
                            <option value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>" <?php echo ($u['board'] === $b) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b, ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group span-2">
                    <label>School name</label>
                    <input class="form-control" type="text" name="school" value="<?php echo htmlspecialchars($u['school'] ?? '', ENT_QUOTES); ?>" placeholder="e.g. Springfield High" maxlength="255">
                </div>
                <div class="form-group span-2">
                    <label>Bio</label>
                    <textarea class="form-control" name="bio" maxlength="500" placeholder="Tell us something interesting about yourself..."><?php echo htmlspecialchars($u['bio'] ?? '', ENT_QUOTES); ?></textarea>
                </div>
            </div>
            <div style="margin-top:14px;"><button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Save changes</button></div>
        </form>
    </section>

    <section class="panel profile-card">
        <h2>Identity</h2>
        <div class="profile-card__top">
            <div class="profile-card__avatar" style="
                background: <?php echo htmlspecialchars($avatarBg, ENT_QUOTES); ?>;
                width: 64px; height: 64px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                color: #fff; font-weight: 400; font-size: 22px; letter-spacing: 0.02em;
                box-shadow: 0 0 0 1px rgba(126, 51, 255, 0.18), inset 0 0 0 1px rgba(255,255,255,0.22);
                border: 1px solid rgba(126, 51, 255, 0.40);
            ">
                <?php echo htmlspecialchars($initials, ENT_QUOTES); ?>
            </div>
            <div class="profile-card__header">
                <div class="profile-card__name"><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?></div>
                <div class="profile-card__meta">Student since <strong><?php echo htmlspecialchars(date('M j, Y', strtotime($u['created_at'])), ENT_QUOTES); ?></strong></div>
            </div>
        </div>
        <div class="profile-card__details">
            <div class="profile-card__line"><span>Role</span><span class="tag tag--blue"><?php echo htmlspecialchars(ucfirst($u['role']), ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Class</span><span class="tag tag--green">Class <?php echo (int)$u['class']; ?></span></div>
            <div class="profile-card__line"><span>Board</span><span class="tag tag--orange"><?php echo htmlspecialchars($u['board'] ?? '', ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Email</span><span><?php echo htmlspecialchars($u['email'], ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Phone</span><span><?php echo htmlspecialchars($u['phone'] ?? 'Not provided', ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Date of birth</span><span><?php echo !empty($u['dob']) ? htmlspecialchars(date('M j, Y', strtotime($u['dob'])), ENT_QUOTES) : 'Not provided'; ?></span></div>
            <div class="profile-card__line"><span>School</span><span><?php echo htmlspecialchars($u['school'] ?? 'Not provided', ENT_QUOTES); ?></span></div>
            <?php if (!empty($u['bio'])): ?>
                <div class="profile-card__bio">
                    <span>About me</span>
                    <p><?php echo nl2br(htmlspecialchars($u['bio'], ENT_QUOTES)); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="panel-grid">
    <section class="panel">
        <h2>Change password</h2>
        <form method="POST" action="profile.php" style="max-width:520px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="password">
            <div class="form-grid" style="grid-template-columns:1fr;">
                <div class="form-group">
                    <label>Current password</label>
                    <input class="form-control" type="password" name="current_password" placeholder="Your current password" required>
                </div>
                <div class="form-group">
                    <label>New password</label>
                    <input class="form-control" type="password" name="new_password" minlength="8" placeholder="Min. 8 chars, uppercase & digit" required>
                </div>
                <div class="form-group">
                    <label>Confirm new password</label>
                    <input class="form-control" type="password" name="confirm_password" minlength="8" placeholder="Re-enter your new password" required>
                </div>
            </div>
            <div style="margin-top:14px;"><button class="btn btn-primary" type="submit"><ion-icon name="key-outline"></ion-icon> Update password</button></div>
        </form>
    </section>

    <section class="panel" style="border: 1px solid rgba(178, 31, 58, 0.2); display: flex; flex-direction: column;">
        <h2 style="color: #b21f3a;">Delete account</h2>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 24px;">
            Permanently delete your account and all of your content. Once you delete your account, there is no going back. Please be certain.
        </p>
        <form method="POST" action="profile.php" style="display: flex; flex-direction: column; flex: 1;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="delete_account">
            <style>
                .uiverse-del-container {
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    gap: 12px;
                    user-select: none;
                    background: rgba(239, 68, 68, 0.05);
                    border: 1px solid rgba(239, 68, 68, 0.25);
                    padding: 12px 16px;
                    border-radius: 14px;
                    transition: all 0.25s ease;
                    margin-bottom: 12px;
                }
                .uiverse-del-container:hover {
                    background: rgba(239, 68, 68, 0.1);
                    border-color: #ef4444;
                }
                .uiverse-del-container input[type="checkbox"] {
                    display: none;
                }
                .uiverse-del-container svg {
                    overflow: visible;
                    flex-shrink: 0;
                }
                .uiverse-del-path {
                    fill: none;
                    stroke: #ef4444;
                    stroke-width: 6;
                    stroke-linecap: round;
                    stroke-linejoin: round;
                    transition: stroke-dasharray 0.5s ease, stroke-dashoffset 0.5s ease;
                    stroke-dasharray: 241 9999999;
                    stroke-dashoffset: 0;
                }
                .uiverse-del-container input:checked ~ svg .uiverse-del-path {
                    stroke-dasharray: 70.5096664428711 9999999;
                    stroke-dashoffset: -262.2723388671875;
                }
                .uiverse-del-container:has(input:checked) {
                    background: rgba(239, 68, 68, 0.15);
                    border-color: #ef4444;
                    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2);
                }
                .uiverse-del-text {
                    font-size: 13px;
                    font-weight: 600;
                    color: var(--text-primary);
                    line-height: 1.4;
                }
            </style>
            <label class="uiverse-del-container">
                <input type="checkbox" name="confirm_delete" id="confirm_delete" required>
                <svg viewBox="0 0 64 64" height="22px" width="22px">
                    <path d="M 0 16 V 56 A 8 8 90 0 0 8 64 H 56 A 8 8 90 0 0 64 56 V 8 A 8 8 90 0 0 56 0 H 8 A 8 8 90 0 0 0 8 V 16 L 32 48 L 64 16 V 8 A 8 8 90 0 0 56 0 H 8 A 8 8 90 0 0 0 8 V 56 A 8 8 90 0 0 8 64 H 56 A 8 8 90 0 0 64 56 V 16" pathLength="575.0541381835938" class="uiverse-del-path"></path>
                </svg>
                <span class="uiverse-del-text">I confirm that I want to permanently delete my account.</span>
            </label>
            <div style="margin-top: auto; padding-top: 32px;">
                <button class="btn btn-danger" type="submit"><ion-icon name="trash-outline"></ion-icon> Delete my account</button>
            </div>
        </form>
    </section>
</div>


<?php render_dashboard_footer(); ?>
