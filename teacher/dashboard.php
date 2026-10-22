<?php
/**
 * EduMate - Teacher Dashboard (Overview)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

$courseCount    = (int)$pdo->prepare('SELECT COUNT(*) FROM courses WHERE teacher_id = :u')->execute([':u' => $uid]) ? 0 : 0;
$cs = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE teacher_id = :u');
$cs->execute([':u' => $uid]);
$courseCount = (int)$cs->fetchColumn();

$enrolledTotal = (int)$pdo->prepare(
    'SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = :u'
)->execute([':u' => $uid]) ? 0 : 0;
$es = $pdo->prepare(
    'SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = :u'
);
$es->execute([':u' => $uid]);
$enrolledTotal = (int)$es->fetchColumn();

$assignmentCount = (int)$pdo->prepare('SELECT COUNT(*) FROM assignments WHERE teacher_id = :u')->execute([':u' => $uid]) ? 0 : 0;
$as = $pdo->prepare('SELECT COUNT(*) FROM assignments WHERE teacher_id = :u');
$as->execute([':u' => $uid]);
$assignmentCount = (int)$as->fetchColumn();

$submissionCount = (int)$pdo->prepare(
    'SELECT COUNT(*) FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.teacher_id = :u'
)->execute([':u' => $uid]) ? 0 : 0;
$ss = $pdo->prepare(
    'SELECT COUNT(*) FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.teacher_id = :u'
);
$ss->execute([':u' => $uid]);
$submissionCount = (int)$ss->fetchColumn();

// Recent activity
$act = $pdo->prepare(
    'SELECT icon, title, detail, created_at FROM activities WHERE user_id = :u ORDER BY created_at DESC LIMIT 8'
);
$act->execute([':u' => $uid]);
$activities = $act->fetchAll();

render_dashboard_header(
    'Overview',
    teacher_nav(),
    'overview',
    ['Home', 'Overview']
);
?>
<section class="cards">
    <div class="card">
        <div class="ico"><ion-icon name="school-outline"></ion-icon></div>
        <div class="label">Courses</div>
        <div class="value"><?php echo $courseCount; ?></div>
    </div>
    <div class="card s2">
        <div class="ico"><ion-icon name="people-outline"></ion-icon></div>
        <div class="label">Students Enrolled</div>
        <div class="value"><?php echo $enrolledTotal; ?></div>
    </div>
    <div class="card s3">
        <div class="ico"><ion-icon name="reader-outline"></ion-icon></div>
        <div class="label">Assignments</div>
        <div class="value"><?php echo $assignmentCount; ?></div>
    </div>
    <div class="card s4">
        <div class="ico"><ion-icon name="mail-outline"></ion-icon></div>
        <div class="label">Submissions</div>
        <div class="value"><?php echo $submissionCount; ?></div>
    </div>
</section>

<div class="panel-grid">
    <section class="panel">
        <h2>Your courses</h2>
        <?php
        $myCourses = $pdo->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled
               FROM courses c WHERE c.teacher_id = :u ORDER BY c.created_at DESC LIMIT 6'
        );
        $myCourses->execute([':u' => $uid]);
        $rows = $myCourses->fetchAll();
        ?>
        <?php if (empty($rows)): ?>
            <div class="empty"><div class="ico"><ion-icon name="easel-outline"></ion-icon></div><h3>No courses yet</h3><p>Create your first course from the Courses page.</p></div>
        <?php else: ?>
            <table class="simple">
                <thead><tr><th>Course</th><th>Subject</th><th>Enrolled</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $c): ?>
                        <tr>
                            <td>
                                <a href="course_edit.php?id=<?php echo (int)$c['id']; ?>" style="font-weight:600;">
                                    <?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>
                                </a>
                                <div style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($c['level'], ENT_QUOTES); ?></div>
                            </td>
                            <td><span class="tag"><?php echo htmlspecialchars($c['subject'], ENT_QUOTES); ?></span></td>
                            <td><?php echo (int)$c['enrolled']; ?></td>
                            <td><a class="btn btn-ghost btn-sm" href="classes.php?course_id=<?php echo (int)$c['id']; ?>">View class</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Recent activity</h2>
        <?php if (empty($activities)): ?>
            <div class="empty"><div class="ico"><ion-icon name="flash-outline"></ion-icon></div><h3>No activity yet</h3><p>Updates from your classes will show up here.</p></div>
        <?php else: ?>
            <div class="activity">
                <?php $i = 1; foreach ($activities as $a): $cls = 's' . (($i % 4) + 1); $i++; ?>
                    <div class="item">
                        <div class="ico <?php echo $cls; ?>"><ion-icon name="<?php echo htmlspecialchars(dashboard_activity_icon_name((string)$a['icon']), ENT_QUOTES); ?>"></ion-icon></div>
                        <div class="body">
                            <strong><?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?></strong>
                            <?php if (!empty($a['detail'])): ?><div style="color:var(--text-secondary); font-size:12px;"><?php echo htmlspecialchars($a['detail'], ENT_QUOTES); ?></div><?php endif; ?>
                            <div class="when"><?php echo htmlspecialchars(date('M j, g:i A', strtotime($a['created_at'])), ENT_QUOTES); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php render_dashboard_footer(); ?>
