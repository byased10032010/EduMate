<?php
/**
 * EduMate - Teacher: Classes (roster per course)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();
$cid  = (int)($_GET['course_id'] ?? 0);

// Make sure the course belongs to this teacher
if ($cid > 0) {
    $own = $pdo->prepare('SELECT * FROM courses WHERE id = :id AND teacher_id = :u');
    $own->execute([':id' => $cid, ':u' => $uid]);
    $course = $own->fetch();
    if (!$course) { flash_set('error', 'Course not found.'); header('Location: classes.php'); exit; }
}

// Unenroll POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unenroll') {
    $token = $_POST['csrf_token'] ?? '';
    $sid = (int)($_POST['student_id'] ?? 0);
    $cidPost = (int)($_POST['course_id'] ?? 0);
    if (!csrf_check(is_string($token) ? $token : null) || $cidPost <= 0) {
        flash_set('error', 'Invalid request.');
    } else {
        $own = $pdo->prepare('SELECT 1 FROM courses WHERE id = :id AND teacher_id = :u');
        $own->execute([':id' => $cidPost, ':u' => $uid]);
        if (!$own->fetch()) { flash_set('error', 'Not your course.'); }
        else {
            $pdo->prepare('DELETE FROM enrollments WHERE student_id = :s AND course_id = :c')
                ->execute([':s' => $sid, ':c' => $cidPost]);
            flash_set('success', 'Student removed from course.');
        }
    }
    header('Location: classes.php?course_id=' . $cidPost);
    exit;
}

if ($cid > 0) {
    // Per-course roster
    $stmt = $pdo->prepare(
        'SELECT s.id, s.full_name, s.email, s.last_login, e.progress, e.enrolled_at, e.last_active
           FROM enrollments e
           JOIN users s ON s.id = e.student_id
          WHERE e.course_id = :c
          ORDER BY s.full_name ASC'
    );
    $stmt->execute([':c' => $cid]);
    $roster = $stmt->fetchAll();

    render_dashboard_header(
        'Roster · ' . $course['title'],
        teacher_nav(),
        'classes',
        ['Home', 'My Classes', $course['title']]
    );
    ?>
    <section class="panel">
        <h2><?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?> <span class="sub"><?php echo count($roster); ?> students</span></h2>
        <p style="color:var(--text-light); font-size:13px;">Subject: <strong><?php echo htmlspecialchars($course['subject'], ENT_QUOTES); ?></strong> · Level: <?php echo htmlspecialchars(ucfirst($course['level']), ENT_QUOTES); ?></p>
        <?php if (empty($roster)): ?>
            <div class="empty"><div class="ico"><ion-icon name="people-outline"></ion-icon></div><h3>No students enrolled yet</h3><p>Share your course with students to fill the roster.</p></div>
        <?php else: ?>
            <table class="simple">
                <thead><tr><th>Student</th><th>Progress</th><th>Enrolled</th><th>Last active</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($roster as $r): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES); ?></strong>
                            </td>
                            <td>
                                <div class="progress-bar" style="width:140px;"><span style="width:<?php echo (int)$r['progress']; ?>%"></span></div>
                                <span style="font-size:12px; color:var(--text-light);"><?php echo (int)$r['progress']; ?>%</span>
                            </td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($r['enrolled_at'])), ENT_QUOTES); ?></td>
                            <td><?php echo $r['last_active'] ? htmlspecialchars(date('M j, g:i A', strtotime($r['last_active'])), ENT_QUOTES) : '<span style="color:var(--text-light)">Never</span>'; ?></td>
                            <td>
                                <form method="POST" action="classes.php?course_id=<?php echo $cid; ?>" data-confirm="Remove this student from the course?" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="unenroll">
                                    <input type="hidden" name="course_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo (int)$r['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="person-remove-outline"></ion-icon> Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p style="margin-top:14px;"><a class="btn btn-ghost" href="classes.php"><ion-icon name="arrow-back-outline"></ion-icon> All classes</a></p>
    </section>
    <?php
    render_dashboard_footer();
    return;
}

// Course list with enrollment counts
$list = $pdo->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled
       FROM courses c WHERE c.teacher_id = :u ORDER BY c.created_at DESC'
);
$list->execute([':u' => $uid]);
$rows = $list->fetchAll();

render_dashboard_header(
    'My Classes',
    teacher_nav(),
    'classes',
    ['Home', 'My Classes']
);
?>
<section class="panel">
    <h2>Pick a class to manage</h2>
    <?php if (empty($rows)): ?>
        <div class="empty"><div class="ico"><ion-icon name="easel-outline"></ion-icon></div><h3>No classes yet</h3><p>Create a course first.</p></div>
    <?php else: ?>
        <div class="grid-cards">
            <?php foreach ($rows as $c): ?>
                <article class="tile">
                    <div class="cover" style="background:linear-gradient(135deg, <?php echo htmlspecialchars($c['cover_color'], ENT_QUOTES); ?>, #1a1a2e);">
                        <ion-icon name="people-outline"></ion-icon>
                        <span class="pill"><?php echo htmlspecialchars($c['subject'], ENT_QUOTES); ?></span>
                    </div>
                    <div class="body">
                        <h3><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></h3>
                        <p><?php echo htmlspecialchars($c['description'], ENT_QUOTES); ?></p>
                        <div class="meta">
                            <span><ion-icon name="people-outline"></ion-icon> <?php echo (int)$c['enrolled']; ?> students</span>
                        </div>
                    </div>
                    <div class="footer">
                        <a class="btn btn-primary btn-sm" href="classes.php?course_id=<?php echo (int)$c['id']; ?>">View roster</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_dashboard_footer(); ?>
