<?php
/**
 * EduMate - Teacher: Boards & Subjects Management
 *
 * Hierarchical control: you can only DELETE a board or subject you created.
 * Admins can override (delete any).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/permissions.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: curriculum.php');
        exit;
    }

    if ($action === 'create_board') {
        $boardName = trim((string)($_POST['board_name'] ?? ''));
        $desc      = trim((string)($_POST['description'] ?? ''));
        $color     = $_POST['cover_color'] ?? '#667eea';
        $isOpen    = !empty($_POST['is_open']) ? 1 : 0;

        if (mb_strlen($boardName) < 2 || mb_strlen($boardName) > 50) {
            flash_set('error', 'Board name must be between 2 and 50 characters.');
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO boards (name, description, cover_color, owner_id, is_open) VALUES (:n, :d, :c, :o, :io)'
                );
                $stmt->execute([
                    ':n'  => $boardName,
                    ':d'  => $desc,
                    ':c'  => $color,
                    ':o'  => $uid,
                    ':io' => $isOpen,
                ]);
                $rowId = (int)$pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO board_members (board_id, user_id, role, invited_by) VALUES (:b, :u, "owner", :inv)
                     ON DUPLICATE KEY UPDATE role = "owner"'
                )->execute([':b' => $rowId, ':u' => $uid, ':inv' => $uid]);
                record_activity($uid, 'fa-cubes', 'Created board: ' . $boardName, null);
                flash_set('success', 'Board created successfully.');
            } catch (Throwable $e) {
                flash_set('error', 'Board already exists or database error occurred.');
            }
        }
    } elseif ($action === 'delete_board') {
        $name = trim((string)($_POST['board_name'] ?? ''));
        if ($name === '') { flash_set('error', 'Missing board name.'); }
        else {
            $row = $pdo->prepare('SELECT row_id, owner_id FROM boards WHERE name = :n');
            $row->execute([':n' => $name]);
            $b = $row->fetch();
            if (!$b) flash_set('error', 'Board not found.');
            elseif (!is_admin_user() && (int)$b['owner_id'] !== $uid) {
                flash_set('error', 'You can only delete a board you created.');
            } else {
                $pdo->prepare('DELETE FROM boards WHERE name = :n')->execute([':n' => $name]);
                record_activity($uid, 'fa-trash', 'Deleted board: ' . $name, null);
                flash_set('success', 'Board deleted. All its courses, subjects, and content were removed too.');
            }
        }
    } elseif ($action === 'create_subject') {
        $subjectName = trim((string)($_POST['subject_name'] ?? ''));
        $boardName   = trim((string)($_POST['board_name'] ?? ''));
        $classNum    = (int)($_POST['class'] ?? 0);
        $desc        = trim((string)($_POST['description'] ?? ''));
        $color       = $_POST['cover_color'] ?? '#4facfe';

        if ($subjectName === '' || $boardName === '' || $classNum < 1 || $classNum > 12) {
            flash_set('error', 'Please fill all subject details correctly.');
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO course_subjects (name, board, class, description, cover_color, created_by)
                     VALUES (:name, :board, :class, :desc, :color, :uid)'
                );
                $stmt->execute([
                    ':name'  => $subjectName,
                    ':board' => $boardName,
                    ':class' => $classNum,
                    ':desc'  => $desc,
                    ':color' => $color,
                    ':uid'   => $uid,
                ]);
                record_activity($uid, 'fa-cube', "Added subject '{$subjectName}' for Class {$classNum} ({$boardName})", null);
                flash_set('success', 'Subject added successfully.');
            } catch (Throwable $e) {
                flash_set('error', 'Subject already exists for this Class & Board combination.');
            }
        }
    } elseif ($action === 'delete_subject') {
        $sid = (int)($_POST['subject_id'] ?? 0);
        if ($sid <= 0) { flash_set('error', 'Missing subject id.'); }
        else {
            $row = $pdo->prepare('SELECT id, name, board, class, created_by FROM course_subjects WHERE id = :id');
            $row->execute([':id' => $sid]);
            $s = $row->fetch();
            if (!$s) flash_set('error', 'Subject not found.');
            elseif (!is_admin_user() && (int)$s['created_by'] !== $uid) {
                flash_set('error', 'You can only delete subjects you created.');
            } else {
                $pdo->prepare('DELETE FROM course_subjects WHERE id = :id')->execute([':id' => $sid]);
                record_activity($uid, 'fa-trash', "Deleted subject: {$s['name']} ({$s['board']} Class {$s['class']})", null);
                flash_set('success', 'Subject removed.');
            }
        }
    } elseif ($action === 'approve_join') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $row   = $pdo->prepare(
            'SELECT bjr.*, b.owner_id, b.row_id AS board_row_id
               FROM board_join_requests bjr
               JOIN boards b ON b.row_id = bjr.board_id
              WHERE bjr.id = :id'
        );
        $row->execute([':id' => $reqId]);
        $r = $row->fetch();
        if (!$r) flash_set('error', 'Request not found.');
        elseif (!is_admin_user() && (int)$r['owner_id'] !== $uid) {
            flash_set('error', 'Only the board owner can approve join requests.');
        } else {
            $pdo->prepare('UPDATE board_join_requests SET status="approved", decided_at=NOW(), decided_by=:d WHERE id=:id')
                ->execute([':d' => $uid, ':id' => $reqId]);
            $pdo->prepare('INSERT IGNORE INTO board_members (board_id, user_id, role, invited_by) VALUES (:b, :u, "member", :inv)')
                ->execute([':b' => $r['board_row_id'], ':u' => $r['user_id'], ':inv' => $uid]);
            flash_set('success', 'Member added to board.');
        }
    } elseif ($action === 'reject_join') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $row   = $pdo->prepare(
            'SELECT bjr.*, b.owner_id FROM board_join_requests bjr JOIN boards b ON b.row_id = bjr.board_id WHERE bjr.id = :id'
        );
        $row->execute([':id' => $reqId]);
        $r = $row->fetch();
        if (!$r) flash_set('error', 'Request not found.');
        elseif (!is_admin_user() && (int)$r['owner_id'] !== $uid) {
            flash_set('error', 'Only the board owner can reject join requests.');
        } else {
            $pdo->prepare('UPDATE board_join_requests SET status="rejected", decided_at=NOW(), decided_by=:d WHERE id=:id')
                ->execute([':d' => $uid, ':id' => $reqId]);
            flash_set('success', 'Request rejected.');
        }
    }

    header('Location: curriculum.php');
    exit;
}

// Fetch all boards (with ownership info)
$boards = $pdo->query(
    'SELECT row_id, name, description, cover_color, owner_id, is_open,
            (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = row_id) AS member_count,
            (SELECT COUNT(*) FROM course_subjects cs WHERE cs.board = name) AS subject_count
       FROM boards ORDER BY name ASC'
)->fetchAll();

// Pending join requests for boards this user owns (or all if admin)
if (is_admin_user()) {
    $reqs = $pdo->query(
        'SELECT bjr.*, u.full_name AS user_name, b.name AS board_name
           FROM board_join_requests bjr
           JOIN users u ON u.id = bjr.user_id
           JOIN boards b ON b.row_id = bjr.board_id
          WHERE bjr.status = "pending"
          ORDER BY bjr.requested_at DESC'
    )->fetchAll();
} else {
    $reqs = $pdo->prepare(
        'SELECT bjr.*, u.full_name AS user_name, b.name AS board_name
           FROM board_join_requests bjr
           JOIN users u ON u.id = bjr.user_id
           JOIN boards b ON b.row_id = bjr.board_id
          WHERE bjr.status = "pending" AND b.owner_id = :u
          ORDER BY bjr.requested_at DESC'
    );
    $reqs->execute([':u' => $uid]);
    $reqs = $reqs->fetchAll();
}

// Fetch all subjects
$subjects = $pdo->query(
    'SELECT cs.*, u.full_name AS creator_name
       FROM course_subjects cs
       LEFT JOIN users u ON u.id = cs.created_by
      ORDER BY cs.board ASC, cs.class ASC, cs.name ASC'
)->fetchAll();

render_dashboard_header(
    'Boards & Subjects',
    teacher_nav(),
    'curriculum',
    ['Home', 'Curriculum Management']
);
?>

<div class="panel-grid">
    <section class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h2 style="margin:0;">Boards</h2>
            <button class="btn btn-primary btn-sm" data-modal-open="#modal-new-board"><ion-icon name="add-outline"></ion-icon> Add Board</button>
        </div>
        <p style="color:var(--text-light); font-size:12px; margin: 0 0 10px;">Only the creator (or an admin) can delete a board. Deleting cascades to all courses and subjects inside it.</p>

        <?php if (empty($boards)): ?>
            <div class="empty" style="padding:20px 0;"><p>No boards yet.</p></div>
        <?php else: ?>
            <table class="simple">
                <thead>
                    <tr>
                        <th>Board</th>
                        <th>Subjects</th>
                        <th>Members</th>
                        <th>Owner</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($boards as $b):
                        $canDelBoard = is_admin_user() || ((int)$b['owner_id'] === $uid);
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($b['name'], ENT_QUOTES); ?></strong>
                                <?php if ((int)$b['is_open'] === 0): ?>
                                    <span class="tag private" style="margin-left:6px;">Invite only</span>
                                <?php else: ?>
                                    <span class="tag public" style="margin-left:6px;">Open</span>
                                <?php endif; ?>
                                <div style="font-size:11px; color:var(--text-light); margin-top:2px;"><?php echo htmlspecialchars($b['description'] ?? '', ENT_QUOTES); ?></div>
                            </td>
                            <td><?php echo (int)$b['subject_count']; ?></td>
                            <td><?php echo (int)$b['member_count']; ?></td>
                            <td><?php echo $b['owner_id'] ? render_owner_label(['created_by' => $b['owner_id']]) : '<span style="color:var(--text-light)">—</span>'; ?></td>
                            <td style="text-align:right;">
                                <?php if ($canDelBoard): ?>
                                    <form method="POST" action="curriculum.php" style="display:inline;" data-confirm="Delete this board? This will also delete all of its subjects, courses, lessons, and content.">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_board">
                                        <input type="hidden" name="board_name" value="<?php echo htmlspecialchars($b['name'], ENT_QUOTES); ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                                    </form>
                                <?php else: ?>
                                    <span class="tag" style="color:var(--text-light);" title="Only the creator can delete this"><ion-icon name="lock-closed-outline"></ion-icon></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Join requests</h2>
        <?php if (empty($reqs)): ?>
            <div class="empty"><div class="ico"><ion-icon name="inbox-outline"></ion-icon></div><h3>No pending requests</h3><p>When students request to join a board you own, they appear here.</p></div>
        <?php else: ?>
            <?php foreach ($reqs as $r): ?>
                <div style="border:1px solid var(--border-color); border-radius:10px; padding:10px 12px; margin-bottom:8px; background:var(--bg-secondary);">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div style="flex:1;">
                            <strong><?php echo htmlspecialchars($r['user_name'], ENT_QUOTES); ?></strong>
                            <div style="font-size:12px; color:var(--text-light);">Wants to join: <span class="tag"><?php echo htmlspecialchars($r['board_name'], ENT_QUOTES); ?></span></div>
                            <?php if (!empty($r['message'])): ?>
                                <div style="font-size:12px; color:var(--text-secondary); margin-top:4px; font-style:italic;">&ldquo;<?php echo htmlspecialchars($r['message'], ENT_QUOTES); ?>&rdquo;</div>
                            <?php endif; ?>
                        </div>
                        <form method="POST" action="curriculum.php" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="approve_join">
                            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                            <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                        </form>
                        <form method="POST" action="curriculum.php" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="reject_join">
                            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h2 style="margin:0;">Subjects</h2>
        <button class="btn btn-primary btn-sm" data-modal-open="#modal-new-subject"><ion-icon name="add-outline"></ion-icon> Add Subject</button>
    </div>
    <p style="color:var(--text-light); font-size:12px; margin: 0 0 10px;">Each subject is bound to a Board + Class. You can only delete subjects you created.</p>

    <?php if (empty($subjects)): ?>
        <div class="empty" style="padding:20px 0;"><p>No subjects yet.</p></div>
    <?php else: ?>
        <table class="simple">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Board</th>
                    <th>Class</th>
                    <th>Created by</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $s):
                    $canDel = is_admin_user() || ((int)$s['created_by'] === $uid);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?></strong>
                            <?php if (!empty($s['description'])): ?>
                                <div style="font-size:11px; color:var(--text-light); margin-top:2px;"><?php echo htmlspecialchars($s['description'], ENT_QUOTES); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="tag" style="background:#e0e7ff; color:#4338ca;"><?php echo htmlspecialchars($s['board'], ENT_QUOTES); ?></span></td>
                        <td><span class="tag" style="background:#ecfdf5; color:#047857;">Class <?php echo (int)$s['class']; ?></span></td>
                        <td><?php echo render_owner_label($s); ?></td>
                        <td style="text-align:right;">
                            <?php if ($canDel): ?>
                                <form method="POST" action="curriculum.php" style="display:inline;" data-confirm="Delete this subject? This cannot be undone.">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_subject">
                                    <input type="hidden" name="subject_id" value="<?php echo (int)$s['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                                </form>
                            <?php else: ?>
                                <span class="tag" style="color:var(--text-light);" title="Only the creator can delete this"><ion-icon name="lock-closed-outline"></ion-icon></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- Modal: New Board -->
<div class="modal-backdrop" id="modal-new-board">
    <div class="modal">
        <header>
            <h2>Add Educational Board</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="curriculum.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_board">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Board Name</label>
                        <input class="form-control" name="board_name" required placeholder="e.g. CBSE, ICSE, State Board" maxlength="50">
                    </div>
                    <div class="form-group span-2">
                        <label>Description</label>
                        <textarea class="form-control" name="description" placeholder="What this board covers..." maxlength="500"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Cover color</label>
                        <input class="form-control" type="color" name="cover_color" value="#667eea">
                    </div>
                    <div class="form-group span-2">
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
                        <label for="board_is_open" class="brand-toggle-item" style="width:100%; box-sizing:border-box;">
                            <input type="checkbox" name="is_open" id="board_is_open" value="1" checked>
                            <span class="brand-toggle-slider"></span>
                            <span class="brand-toggle-text">Open to student join requests</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New Subject -->
<div class="modal-backdrop" id="modal-new-subject">
    <div class="modal">
        <header>
            <h2>Add Subject to Board/Class</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="curriculum.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create_subject">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Subject Name</label>
                        <input class="form-control" name="subject_name" required placeholder="e.g. Mathematics, Physics, History" maxlength="80">
                    </div>
                    <div class="form-group">
                        <label>Educational Board</label>
                        <select class="form-control" name="board_name" required>
                            <option value="" disabled selected>Select Board</option>
                            <?php foreach ($boards as $b): ?>
                                <option value="<?php echo htmlspecialchars($b['name'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($b['name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select class="form-control" name="class" required>
                            <option value="" disabled selected>Select Class</option>
                            <?php for ($c = 1; $c <= 10; $c++): ?>
                                <option value="<?php echo $c; ?>">Class <?php echo $c; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cover color</label>
                        <input class="form-control" type="color" name="cover_color" value="#4facfe">
                    </div>
                    <div class="form-group span-2">
                        <label>Description (optional)</label>
                        <textarea class="form-control" name="description" placeholder="What students will learn..."></textarea>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Save</button>
            </div>
        </form>
    </div>
</div>

<?php render_dashboard_footer(); ?>
