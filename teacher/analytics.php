<?php
/**
 * EduMate - Teacher: Analytics
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Per-course enrollment + average progress
$rows = $pdo->prepare(
    'SELECT c.id, c.title, c.subject, c.cover_color,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons,
            COALESCE((SELECT AVG(progress) FROM enrollments e WHERE e.course_id = c.id), 0) AS avg_progress
       FROM courses c WHERE c.teacher_id = :u ORDER BY c.created_at DESC'
);
$rows->execute([':u' => $uid]);
$courses = $rows->fetchAll();

// Quiz performance across my courses
$quizStats = $pdo->prepare(
    'SELECT q.title, q.subject, COUNT(qa.id) AS attempts, COALESCE(AVG(qa.score/NULLIF(qa.total,0)*100), 0) AS avg_pct
       FROM quizzes q
       LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id
      WHERE q.course_id IN (SELECT id FROM courses WHERE teacher_id = :u)
      GROUP BY q.id
      ORDER BY avg_pct DESC'
);
$quizStats->execute([':u' => $uid]);
$quizzes = $quizStats->fetchAll();

// Submission stats
$subStats = $pdo->prepare(
    'SELECT a.title, COUNT(s.id) AS submissions, AVG(s.grade) AS avg_grade
       FROM assignments a
       LEFT JOIN submissions s ON s.assignment_id = a.id
      WHERE a.teacher_id = :u
      GROUP BY a.id
      ORDER BY a.created_at DESC'
);
$subStats->execute([':u' => $uid]);
$subs = $subStats->fetchAll();

$totalStudents = (int)$pdo->prepare(
    'SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
       JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = :u'
)->execute([':u' => $uid]) ? 0 : 0;
$ts = $pdo->prepare(
    'SELECT COUNT(DISTINCT e.student_id) FROM enrollments e
       JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = :u'
);
$ts->execute([':u' => $uid]);
$totalStudents = (int)$ts->fetchColumn();

render_dashboard_header(
    'Analytics',
    teacher_nav(),
    'analytics',
    ['Home', 'Analytics']
);
?>
<section class="cards">
    <div class="card">
        <div class="ico"><ion-icon name="easel-outline"></ion-icon></div>
        <div class="label">Courses</div>
        <div class="value"><?php echo count($courses); ?></div>
    </div>
    <div class="card s2">
        <div class="ico"><ion-icon name="people-outline"></ion-icon></div>
        <div class="label">Unique Students</div>
        <div class="value"><?php echo $totalStudents; ?></div>
    </div>
    <div class="card s3">
        <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
        <div class="label">Quizzes</div>
        <div class="value"><?php echo count($quizzes); ?></div>
    </div>
    <div class="card s4">
        <div class="ico"><ion-icon name="inbox-outline"></ion-icon></div>
        <div class="label">Assignments</div>
        <div class="value"><?php echo count($subs); ?></div>
    </div>
</section>

<section class="panel">
    <h2>Course engagement</h2>
    <?php if (empty($courses)): ?>
        <div class="empty"><div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div><h3>No data yet</h3><p>Create a course to see analytics here.</p></div>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Course</th><th>Subject</th><th>Enrolled</th><th>Lessons</th><th>Avg progress</th></tr></thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                    <tr>
                        <td><a href="course_edit.php?id=<?php echo (int)$c['id']; ?>" style="font-weight:600;"><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></a></td>
                        <td><span class="tag"><?php echo htmlspecialchars($c['subject'], ENT_QUOTES); ?></span></td>
                        <td><?php echo (int)$c['enrolled']; ?></td>
                        <td><?php echo (int)$c['lessons']; ?></td>
                        <td>
                            <div class="progress-bar" style="width:140px;"><span style="width:<?php echo (int)$c['avg_progress']; ?>%"></span></div>
                            <span style="font-size:12px; color:var(--text-light);"><?php echo (int)$c['avg_progress']; ?>%</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<div class="panel-grid">
    <section class="panel">
        <h2>Quiz performance</h2>
        <?php if (empty($quizzes)): ?>
            <div class="empty"><p>No quizzes yet.</p></div>
        <?php else: ?>
            <table class="simple">
                <thead><tr><th>Quiz</th><th>Attempts</th><th>Avg score</th></tr></thead>
                <tbody>
                    <?php foreach ($quizzes as $q): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?><br><span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($q['subject'], ENT_QUOTES); ?></span></td>
                            <td><?php echo (int)$q['attempts']; ?></td>
                            <td>
                                <div class="progress-bar" style="width:120px;"><span style="width:<?php echo (int)$q['avg_pct']; ?>%"></span></div>
                                <span style="font-size:12px; color:var(--text-light);"><?php echo (int)$q['avg_pct']; ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Assignment submissions</h2>
        <?php if (empty($subs)): ?>
            <div class="empty"><p>No assignments yet.</p></div>
        <?php else: ?>
            <table class="simple">
                <thead><tr><th>Assignment</th><th>Submissions</th><th>Avg grade</th></tr></thead>
                <tbody>
                    <?php foreach ($subs as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?></td>
                            <td><?php echo (int)$s['submissions']; ?></td>
                            <td>
                                <?php if ($s['avg_grade'] !== null): ?>
                                    <span class="tag success"><?php echo (int)$s['avg_grade']; ?>%</span>
                                <?php else: ?>
                                    <span class="tag warn">No grades</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php render_dashboard_footer(); ?>
