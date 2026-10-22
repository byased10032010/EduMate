<?php
/**
 * EduMate - Teacher profile (view + edit)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

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
        // Name and Email are permanent for teachers.
        $phone = trim((string)($_POST['phone'] ?? ''));
        $dob   = !empty($_POST['dob']) ? trim((string)$_POST['dob']) : null;
        $bio   = trim((string)($_POST['bio'] ?? ''));
        
        $selectedBoards = $_POST['boards'] ?? [];
        $boardStr = implode(',', $selectedBoards);
        $pdo->prepare('UPDATE users SET phone=:p, dob=:dob, bio=:b, board=:bo WHERE id=:id')
            ->execute([':p' => mb_substr($phone, 0, 20), ':dob' => $dob, ':b' => mb_substr($bio, 0, 500), ':bo' => $boardStr, ':id' => $uid]);
        flash_set('success', 'Profile updated.');
    } elseif ($action === 'password') {
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $cf  = (string)($_POST['confirm_password'] ?? '');
        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $row->execute([':id' => $uid]);
        $hash = $row->fetchColumn();
        if (!password_verify($cur, (string)$hash))                                flash_set('error', 'Current password incorrect.');
        elseif (strlen($new) < 8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[a-z]/',$new) || !preg_match('/\d/',$new)) flash_set('error', 'Password must be 8+ chars with upper, lower, and a digit.');
        elseif ($new !== $cf)                                                     flash_set('error', 'Passwords do not match.');
        else {
            $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $uid]);
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

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => $uid]);
$u = $stmt->fetch();

[$c1, $c2] = user_avatar_gradient($uid);
$avatarBg  = $c1;
$initials  = user_initials($u['full_name']);

render_dashboard_header('Profile', teacher_nav(), 'profile', ['Home', 'Profile']);
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
                    <input class="form-control" name="full_name" value="<?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>" readonly style="background:var(--bg-hover); color:var(--text-light); cursor:not-allowed;">
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
                <style>
                    .brand-toggle-item {
                        display: inline-flex;
                        align-items: center;
                        gap: 10px;
                        background: var(--bg-secondary);
                        border: 1px solid var(--border-color);
                        padding: 8px 14px;
                        border-radius: 12px;
                        cursor: pointer;
                        user-select: none;
                        transition: all 0.2s ease;
                    }
                    .brand-toggle-item:hover {
                        border-color: #7c5cff;
                        background: var(--bg-card);
                    }
                    .brand-toggle-item input[type="checkbox"] {
                        opacity: 0;
                        width: 0;
                        height: 0;
                        position: absolute;
                    }
                    .brand-toggle-slider {
                        position: relative;
                        width: 38px;
                        height: 22px;
                        background-color: var(--border-strong, #cbd5e1);
                        border-radius: 22px;
                        transition: background-color 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                        display: inline-block;
                        flex-shrink: 0;
                    }
                    .brand-toggle-slider::before {
                        content: '';
                        position: absolute;
                        height: 16px;
                        width: 16px;
                        left: 3px;
                        bottom: 3px;
                        background-color: #ffffff;
                        border-radius: 50%;
                        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.18);
                    }
                    .brand-toggle-item input:checked + .brand-toggle-slider {
                        background: linear-gradient(135deg, #7c5cff 0%, #6366f1 100%);
                        box-shadow: 0 2px 10px rgba(124, 92, 255, 0.35);
                    }
                    .brand-toggle-item input:checked + .brand-toggle-slider::before {
                        transform: translateX(16px);
                    }
                    .brand-toggle-item:has(input:checked) {
                        border-color: rgba(124, 92, 255, 0.45);
                        background: rgba(124, 92, 255, 0.06);
                    }
                    .brand-toggle-text {
                        font-size: 13px;
                        font-weight: 600;
                        color: var(--text-primary);
                    }
                </style>
                <div class="form-group span-2">
                    <label style="font-weight:700; margin-bottom:8px; display:block;">Boards You Teach (Choose Multiple)</label>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 4px;">
                        <?php 
                        $allBoards = get_all_boards();
                        $teacherBoards = explode(',', $u['board'] ?? '');
                        foreach ($allBoards as $b): 
                            $checked = in_array($b, $teacherBoards, true) ? 'checked' : '';
                            $bId = 'board_' . md5($b);
                        ?>
                            <label for="<?php echo $bId; ?>" class="brand-toggle-item">
                                <input type="checkbox" name="boards[]" id="<?php echo $bId; ?>" value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>" <?php echo $checked; ?>>
                                <span class="brand-toggle-slider"></span>
                                <span class="brand-toggle-text"><?php echo htmlspecialchars($b, ENT_QUOTES); ?> Board</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
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
                <div class="profile-card__meta">Teacher since <strong><?php echo htmlspecialchars(date('M j, Y', strtotime($u['created_at'])), ENT_QUOTES); ?></strong></div>
            </div>
        </div>
        <div class="profile-card__details">
            <div class="profile-card__line"><span>Role</span><span class="tag tag--blue"><?php echo htmlspecialchars(ucfirst($u['role']), ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Email</span><span><?php echo htmlspecialchars($u['email'], ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Phone</span><span><?php echo htmlspecialchars($u['phone'] ?? 'Not provided', ENT_QUOTES); ?></span></div>
            <div class="profile-card__line"><span>Date of birth</span><span><?php echo !empty($u['dob']) ? htmlspecialchars(date('M j, Y', strtotime($u['dob'])), ENT_QUOTES) : 'Not provided'; ?></span></div>
            <div class="profile-card__line">
                <span>Boards</span>
                <div style="display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end;">
                <?php 
                $tBoards = explode(',', $u['board'] ?? '');
                foreach ($tBoards as $tb):
                    if (trim($tb) === '') continue;
                ?>
                    <span class="tag tag--orange"><?php echo htmlspecialchars(trim($tb), ENT_QUOTES); ?></span>
                <?php endforeach; ?>
                </div>
            </div>
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
