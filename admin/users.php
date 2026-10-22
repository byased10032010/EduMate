<?php
/**
 * EduMate - Admin: User management
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('admin');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: users.php'); exit;
    }

    if ($action === 'create') {
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = $_POST['role'] ?? 'student';
        $pw    = (string)($_POST['password'] ?? '');
        $errors = [];
        if (mb_strlen($name) < 2) $errors[] = 'Name too short.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
        if (!in_array($role, ['student','teacher','admin'], true)) $errors[] = 'Invalid role.';
        if (strlen($pw) < 8) $errors[] = 'Password must be 8+ chars.';
        if ($errors) flash_set('error', $errors);
        else {
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = :e');
            $exists->execute([':e' => $email]);
            if ($exists->fetch()) flash_set('error', 'Email already in use.');
            else {
                $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (:n, :e, :p, :r)')
                    ->execute([':n' => $name, ':e' => $email, ':p' => password_hash($pw, PASSWORD_DEFAULT), ':r' => $role]);
                flash_set('success', 'User created.');
            }
        }
    } elseif ($action === 'toggle_active') {
        $tid = (int)($_POST['target_id'] ?? 0);
        if ($tid === $uid) { flash_set('error', 'You cannot deactivate yourself.'); }
        else {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $tid]);
            flash_set('success', 'User status updated.');
        }
    } elseif ($action === 'promote' || $action === 'demote') {
        $tid = (int)($_POST['target_id'] ?? 0);
        $newRole = $action === 'promote' ? 'admin' : 'student';
        $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $newRole, ':id' => $tid]);
        flash_set('success', 'Role updated.');
    } elseif ($action === 'delete') {
        $tid = (int)($_POST['target_id'] ?? 0);
        if ($tid === $uid) { flash_set('error', 'You cannot delete yourself.'); }
        else {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $tid]);
            flash_set('success', 'User deleted.');
        }
    }
    header('Location: users.php' . (!empty($_POST['redirect_to']) ? '?' . $_POST['redirect_to'] : ''));
    exit;
}

$role   = $_GET['role'] ?? '';
$search = trim((string)($_GET['search'] ?? ''));

$where = []; $params = [];
if (in_array($role, ['student','teacher','admin'], true)) { $where[] = 'role = :r'; $params[':r'] = $role; }
if ($search !== '') { $where[] = '(full_name LIKE :s OR email LIKE :s)'; $params[':s'] = '%' . $search . '%'; }
$whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT u.*, (SELECT COUNT(*) FROM enrollments e WHERE e.student_id = u.id) AS enrolled_count
        FROM users u $whereSql ORDER BY u.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

render_dashboard_header(
    'Users',
    admin_nav(),
    'users',
    ['Home', 'Users']
);
?>
<section class="panel">
    <form method="GET" action="users.php" style="display:flex; gap:8px; align-items:center; margin-bottom:14px; flex-wrap:wrap;">
        <input class="form-control" type="text" name="search" placeholder="Search by name or email" value="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>" style="width:280px;">
        <select class="form-control" name="role" style="width:auto;" data-auto-submit>
            <option value="">All roles</option>
            <option value="student" <?php echo $role==='student'?'selected':''; ?>>Students</option>
            <option value="teacher" <?php echo $role==='teacher'?'selected':''; ?>>Teachers</option>
            <option value="admin"   <?php echo $role==='admin'  ?'selected':''; ?>>Admins</option>
        </select>
        <button class="btn btn-ghost" type="submit">Apply</button>
        <span style="margin-left:auto; color:var(--text-light); font-size:13px;"><?php echo count($users); ?> user<?php echo count($users)===1?'':'s'; ?></span>
        <button class="btn btn-primary" type="button" data-modal-open="#modal-new-user"><ion-icon name="add-outline"></ion-icon> New user</button>
    </form>

    <table class="simple">
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Joined</th><th>Last login</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?></strong><br>
                        <span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($u['email'], ENT_QUOTES); ?></span>
                    </td>
                    <td><span class="tag"><?php echo htmlspecialchars(ucfirst($u['role']), ENT_QUOTES); ?></span></td>
                    <td><?php echo $u['is_active'] ? '<span class="tag success">Active</span>' : '<span class="tag warn">Disabled</span>'; ?></td>
                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($u['created_at'])), ENT_QUOTES); ?></td>
                    <td><?php echo $u['last_login'] ? htmlspecialchars(date('M j, g:i A', strtotime($u['last_login'])), ENT_QUOTES) : '<span style="color:var(--text-light)">Never</span>'; ?></td>
                    <td>
                        <?php if ((int)$u['id'] !== $uid): ?>
                            <form method="POST" action="users.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="target_id" value="<?php echo (int)$u['id']; ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" title="Toggle active"><?php echo $u['is_active'] ? 'Disable' : 'Enable'; ?></button>
                            </form>
                            <?php if ($u['role'] !== 'admin'): ?>
                                <form method="POST" action="users.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="promote">
                                    <input type="hidden" name="target_id" value="<?php echo (int)$u['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Promote</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="users.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="demote">
                                    <input type="hidden" name="target_id" value="<?php echo (int)$u['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Demote</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="users.php" data-confirm="Delete this user permanently?" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="target_id" value="<?php echo (int)$u['id']; ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                            </form>
                        <?php else: ?>
                            <span class="tag info">You</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<div class="modal-backdrop" id="modal-new-user">
    <div class="modal">
        <header>
            <h2>Create a new user</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="users.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full name</label>
                        <input class="form-control" name="full_name" required minlength="2">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select class="form-control" name="role">
                            <option value="student">Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label>Email</label>
                        <input class="form-control" type="email" name="email" required>
                    </div>
                    <div class="form-group span-2">
                        <label>Initial password</label>
                        <input class="form-control" type="text" name="password" minlength="8" required placeholder="At least 8 characters">
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Create user</button>
            </div>
        </form>
    </div>
</div>
<?php render_dashboard_footer(); ?>

