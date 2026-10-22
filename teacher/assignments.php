<?php
/**
 * EduMate - Teacher: Assignments (CRUD + submissions review)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();
$aid  = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: assignments.php'); exit;
    }

    if ($action === 'create' || $action === 'update') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $instr = trim((string)($_POST['instructions'] ?? ''));
        $due   = $_POST['due_date'] ?? null;
        $errors = [];
        $own = $pdo->prepare('SELECT 1 FROM courses WHERE id = :id AND teacher_id = :u');
        $own->execute([':id' => $cid, ':u' => $uid]);
        if (!$own->fetch()) $errors[] = 'Pick one of your courses.';
        if (mb_strlen($title) < 3) $errors[] = 'Title must be at least 3 chars.';
        if ($errors) flash_set('error', $errors);
        elseif ($action === 'create') {
            $pdo->prepare(
                'INSERT INTO assignments (course_id, teacher_id, title, instructions, due_date)
                 VALUES (:c, :t, :ti, :i, :d)'
            )->execute([':c' => $cid, ':t' => $uid, ':ti' => $title, ':i' => $instr, ':d' => $due ?: null]);
            record_activity($uid, 'fa-tasks', 'Posted assignment: ' . $title, null);
            flash_set('success', 'Assignment posted.');
        } else {
            $aidPost = (int)($_POST['assignment_id'] ?? 0);
            $ownA = $pdo->prepare('SELECT 1 FROM assignments WHERE id = :id AND teacher_id = :u');
            $ownA->execute([':id' => $aidPost, ':u' => $uid]);
            if (!$ownA->fetch()) flash_set('error', 'Assignment not found.');
            else {
                $pdo->prepare(
                    'UPDATE assignments SET course_id=:c, title=:ti, instructions=:i, due_date=:d
                     WHERE id=:id AND teacher_id=:u'
                )->execute([':c' => $cid, ':ti' => $title, ':i' => $instr, ':d' => $due ?: null, ':id' => $aidPost, ':u' => $uid]);
                flash_set('success', 'Assignment updated.');
            }
        }
    } elseif ($action === 'delete') {
        $aidPost = (int)($_POST['assignment_id'] ?? 0);
        $ownA = $pdo->prepare('SELECT 1 FROM assignments WHERE id = :id AND teacher_id = :u');
        $ownA->execute([':id' => $aidPost, ':u' => $uid]);
        if (!$ownA->fetch()) flash_set('error', 'Assignment not found.');
        else {
            $pdo->prepare('DELETE FROM assignments WHERE id = :id AND teacher_id = :u')->execute([':id' => $aidPost, ':u' => $uid]);
            flash_set('success', 'Assignment removed.');
        }
    } elseif ($action === 'grade') {
        $sid = (int)($_POST['submission_id'] ?? 0);
        $grade = max(0, min(100, (int)($_POST['grade'] ?? 0)));
        $fb    = trim((string)($_POST['feedback'] ?? ''));
        $ownS = $pdo->prepare(
            'SELECT s.id FROM submissions s
               JOIN assignments a ON a.id = s.assignment_id
              WHERE s.id = :sid AND a.teacher_id = :u'
        );
        $ownS->execute([':sid' => $sid, ':u' => $uid]);
        if (!$ownS->fetch()) flash_set('error', 'Submission not found.');
        else {
            $pdo->prepare('UPDATE submissions SET grade = :g, feedback = :f, graded_at = NOW() WHERE id = :id')
                ->execute([':g' => $grade, ':f' => $fb, ':id' => $sid]);
            flash_set('success', 'Grade saved.');
        }
    }
    $back = $_POST['redirect_to'] ?? ('assignments.php' . ($aid ? '?id=' . $aid : ''));
    header('Location: ' . $back);
    exit;
}

if ($aid > 0) {
    $aStmt = $pdo->prepare(
        'SELECT a.*, c.title AS course_title FROM assignments a
           JOIN courses c ON c.id = a.course_id
          WHERE a.id = :id AND a.teacher_id = :u'
    );
    $aStmt->execute([':id' => $aid, ':u' => $uid]);
    $a = $aStmt->fetch();
    if (!$a) { flash_set('error', 'Assignment not found.'); header('Location: assignments.php'); exit; }

    $subs = $pdo->prepare(
        'SELECT s.*, st.full_name AS student_name
           FROM submissions s
           JOIN users st ON st.id = s.student_id
          WHERE s.assignment_id = :a
          ORDER BY s.submitted_at DESC'
    );
    $subs->execute([':a' => $aid]);
    $subs = $subs->fetchAll();

    render_dashboard_header(
        $a['title'],
        teacher_nav(),
        'assignments',
        ['Home', 'Assignments', $a['title']]
    );
    ?>
    <section class="panel">
        <h2><?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?> <span class="sub"><?php echo htmlspecialchars($a['course_title'], ENT_QUOTES); ?></span></h2>
        <p style="color:var(--text-secondary);"><?php echo nl2br(htmlspecialchars($a['instructions'], ENT_QUOTES)); ?></p>
        <p style="color:var(--text-light); font-size:12px; margin-top:8px;">
            <?php if ($a['due_date']): ?>Due: <strong><?php echo htmlspecialchars(date('M j, Y', strtotime($a['due_date'])), ENT_QUOTES); ?></strong> · <?php endif; ?>
            Created: <?php echo htmlspecialchars(date('M j, Y', strtotime($a['created_at'])), ENT_QUOTES); ?>
        </p>
    </section>
    <section class="panel">
        <h2>Submissions <span class="sub"><?php echo count($subs); ?></span></h2>
        <?php if (empty($subs)): ?>
            <div class="empty"><div class="ico"><ion-icon name="inbox-outline"></ion-icon></div><h3>No submissions yet</h3><p>Once students submit, they will appear here.</p></div>
        <?php else: ?>
            <?php foreach ($subs as $s): ?>
                <div style="border:1px solid var(--border-color); border-radius:12px; padding:14px; margin-bottom:10px; background:var(--bg-secondary);">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:var(--gradient-primary); color:#fff; display:grid; place-items:center; font-weight:700;"><?php echo strtoupper(substr($s['student_name'], 0, 1)); ?></div>
                        <div style="flex:1;">
                            <strong><?php echo htmlspecialchars($s['student_name'], ENT_QUOTES); ?></strong>
                            <div style="font-size:11px; color:var(--text-light);">Submitted <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($s['submitted_at'])), ENT_QUOTES); ?></div>
                        </div>
                        <?php if ($s['grade'] !== null): ?>
                            <span class="tag success">Graded: <?php echo (int)$s['grade']; ?>%</span>
                        <?php else: ?>
                            <span class="tag warn">Pending</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px; color:var(--text-secondary); margin:6px 0 10px; white-space:pre-wrap;"><?php echo htmlspecialchars($s['body'], ENT_QUOTES); ?></div>
                    
                    <?php if ($s['attachment_path']): ?>
                        <div style="margin-bottom:12px;">
                            <a href="../<?php echo htmlspecialchars($s['attachment_path'], ENT_QUOTES); ?>" class="btn btn-sm btn-ghost" style="color:#667eea;" target="_blank" rel="noopener noreferrer">
                                <ion-icon name="document-attach-outline"></ion-icon> View Attachment
                            </a>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="assignments.php?id=<?php echo $aid; ?>" class="form-grid" style="grid-template-columns:120px 1fr auto; align-items:end;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="grade">
                        <input type="hidden" name="submission_id" value="<?php echo (int)$s['id']; ?>">
                        <div class="form-group">
                            <label>Grade (0–100)</label>
                            <input class="form-control" type="number" name="grade" min="0" max="100" value="<?php echo $s['grade'] !== null ? (int)$s['grade'] : ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Feedback</label>
                            <input class="form-control" name="feedback" value="<?php echo htmlspecialchars($s['feedback'] ?? '', ENT_QUOTES); ?>" maxlength="500">
                        </div>
                        <div><button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Save</button></div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <p><a class="btn btn-ghost" href="assignments.php"><ion-icon name="arrow-back-outline"></ion-icon> All assignments</a></p>
    <?php
    render_dashboard_footer();
    return;
}

// List view
$list = $pdo->prepare(
    'SELECT a.*, c.title AS course_title,
            (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS submission_count
       FROM assignments a JOIN courses c ON c.id = a.course_id
      WHERE a.teacher_id = :u ORDER BY a.created_at DESC'
);
$list->execute([':u' => $uid]);
$assignments = $list->fetchAll();

$myCourses = $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id = :u ORDER BY title');
$myCourses->execute([':u' => $uid]);
$myCourses = $myCourses->fetchAll();

render_dashboard_header(
    'Assignments',
    teacher_nav(),
    'assignments',
    ['Home', 'Assignments']
);
?>
<section class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">All assignments</h2>
        <?php if (!empty($myCourses)): ?>
            <button class="btn btn-primary" data-modal-open="#modal-new-asn"><ion-icon name="add-outline"></ion-icon> New assignment</button>
        <?php endif; ?>
    </div>

    <?php if (empty($assignments)): ?>
        <div class="empty"><div class="ico"><ion-icon name="checkbox-outline"></ion-icon></div><h3>No assignments yet</h3><p>Create an assignment to start collecting work.</p></div>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Title</th><th>Course</th><th>Due</th><th>Submissions</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><a href="assignments.php?id=<?php echo (int)$a['id']; ?>" style="font-weight:600;"><?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?></a></td>
                        <td><?php echo htmlspecialchars($a['course_title'], ENT_QUOTES); ?></td>
                        <td><?php echo $a['due_date'] ? htmlspecialchars(date('M j, Y', strtotime($a['due_date'])), ENT_QUOTES) : '<span style="color:var(--text-light)">No due date</span>'; ?></td>
                        <td><span class="tag"><?php echo (int)$a['submission_count']; ?></span></td>
                        <td style="display: flex; gap: 8px; align-items: center; justify-content: flex-end;">
                            <a class="btn btn-ghost btn-sm" href="assignments.php?id=<?php echo (int)$a['id']; ?>">Open</a>
                            <form method="POST" action="assignments.php" data-confirm="Delete this assignment?" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if (!empty($myCourses)): ?>
<div class="modal-backdrop" id="modal-new-asn">
    <div class="modal">
        <header>
            <h2>New assignment</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="assignments.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Course</label>
                        <select class="form-control" name="course_id" required>
                            <option value="">Select a course</option>
                            <?php foreach ($myCourses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label>Title</label>
                        <input class="form-control" name="title" required minlength="3">
                    </div>
                    <div class="form-group span-2">
                        <label>Instructions</label>
                        <textarea class="form-control" name="instructions" rows="4"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Due date (optional)</label>
                        <input class="form-control" type="date" name="due_date">
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Post assignment</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php render_dashboard_footer(); ?>
