<?php
/**
 * EduMate - Student: Progress dashboard
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Aggregate stats
$stats = [];
$enrolledStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = :u');
$enrolledStmt->execute([':u' => $uid]);
$stats['enrolled'] = (int)$enrolledStmt->fetchColumn();

$ld = $pdo->prepare('SELECT COUNT(*) FROM lesson_completions WHERE student_id = :u');
$ld->execute([':u' => $uid]);
$stats['lessons_done'] = (int)$ld->fetchColumn();

$qd = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE student_id = :u');
$qd->execute([':u' => $uid]);
$stats['quizzes'] = (int)$qd->fetchColumn();

$av = $pdo->prepare('SELECT COALESCE(AVG(score/NULLIF(total,0))*100, 0) FROM quiz_attempts WHERE student_id = :u');
$av->execute([':u' => $uid]);
$stats['avg_score'] = (int)round((float)$av->fetchColumn());

$done = $pdo->prepare(
    'SELECT COUNT(*) FROM enrollments e
       JOIN courses c ON c.id = e.course_id
      WHERE e.student_id = :u AND e.progress = 100'
);
$done->execute([':u' => $uid]);
$stats['courses_done'] = (int)$done->fetchColumn();

// Per-course progress
$perCourse = $pdo->prepare(
    'SELECT c.id, c.title, c.subject, c.cover_color, c.level,
            e.progress, e.enrolled_at, e.last_active,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS total_lessons,
            (SELECT COUNT(*) FROM lesson_completions lc
                JOIN lessons l2 ON l2.id = lc.lesson_id
                WHERE l2.course_id = c.id AND lc.student_id = :u) AS done_lessons
       FROM enrollments e
       JOIN courses c ON c.id = e.course_id
      WHERE e.student_id = :u2
      ORDER BY e.progress DESC, e.enrolled_at DESC'
);
$perCourse->execute([':u' => $uid, ':u2' => $uid]);
$courses = $perCourse->fetchAll();

// Weekly activity (last 7 days, lessons completed)
$weekly = array_fill(0, 7, 0);
$today = new DateTimeImmutable('today');
$wk = $pdo->prepare(
    "SELECT DATE(completed_at) AS d, COUNT(*) AS c
       FROM lesson_completions
      WHERE student_id = :u AND completed_at >= (NOW() - INTERVAL 7 DAY)
      GROUP BY DATE(completed_at)"
);
$wk->execute([':u' => $uid]);
foreach ($wk as $row) {
    $d = new DateTimeImmutable($row['d']);
    $diff = (int)$today->diff($d)->format('%r%a');
    $idx  = 6 - $diff;
    if ($idx >= 0 && $idx < 7) $weekly[$idx] = (int)$row['c'];
}
$weekMax = max($weekly ?: [1]);

// AI Doubt History
$doubtsStmt = $pdo->prepare('SELECT * FROM ai_doubts WHERE user_id = :u ORDER BY created_at DESC');
$doubtsStmt->execute([':u' => $uid]);
$doubts = $doubtsStmt->fetchAll();

render_dashboard_header(
    'My Progress',
    student_nav(),
    'progress',
    ['Home', 'My Progress']
);
?>
<section class="cards">
    <div class="card">
        <div class="ico"><ion-icon name="book-outline"></ion-icon></div>
        <div class="label">Enrolled</div>
        <div class="value"><?php echo $stats['enrolled']; ?></div>
    </div>
    <div class="card s2">
        <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
        <div class="label">Lessons Done</div>
        <div class="value"><?php echo $stats['lessons_done']; ?></div>
    </div>
    <div class="card s3">
        <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
        <div class="label">Quizzes Taken</div>
        <div class="value"><?php echo $stats['quizzes']; ?></div>
    </div>
    <div class="card s4">
        <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
        <div class="label">Avg Score</div>
        <div class="value"><?php echo $stats['avg_score']; ?>%</div>
    </div>
</section>

<div class="panel-grid">
    <section class="panel">
        <h2>Course progress</h2>
        <?php if (empty($courses)): ?>
            <div class="empty"><div class="ico"><ion-icon name="book-outline"></ion-icon></div><h3>No courses yet</h3><p>Enroll in a course to see progress here.</p></div>
        <?php else: ?>
            <?php foreach ($courses as $c): ?>
                <div style="margin-bottom:14px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                        <a href="course.php?id=<?php echo (int)$c['id']; ?>" style="font-weight:600;">
                            <?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>
                        </a>
                        <span style="font-size:13px; color:var(--text-light);">
                            <?php echo (int)$c['done_lessons']; ?> / <?php echo (int)$c['total_lessons']; ?> lessons · <?php echo (int)$c['progress']; ?>%
                        </span>
                    </div>
                    <div class="progress-bar"><span style="width: <?php echo (int)$c['progress']; ?>%"></span></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>This week</h2>
        <p style="font-size:12px; color:var(--text-light); margin-bottom:8px;">Lessons completed each day</p>
        <div style="display:flex; align-items:flex-end; gap:6px; height:120px;">
            <?php
            $labels = [];
            for ($i = 6; $i >= 0; $i--) {
                $labels[] = (new DateTimeImmutable("-$i days"))->format('D');
            }
            foreach ($weekly as $i => $count):
                $h = $weekMax > 0 ? max(8, (int)round($count * 100 / $weekMax)) : 8;
            ?>
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;">
                    <div title="<?php echo $count; ?> lessons" style="width:100%; height:<?php echo $h; ?>%; background: linear-gradient(180deg, #667eea, #764ba2); border-radius:6px;"></div>
                    <span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($labels[$i] ?? '', ENT_QUOTES); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="panel" style="margin-top:20px;">
    <h2>AI Doubt History</h2>
    <?php if (empty($doubts)): ?>
        <div class="empty">
            <div class="ico"><ion-icon name="chatbubbles-outline"></ion-icon></div>
            <h3>No saved doubts</h3>
            <p>Use the AI Tutor to ask questions and save them to your history.</p>
        </div>
    <?php else: ?>
        <div class="activity">
            <?php foreach ($doubts as $d): ?>
                <div class="item">
                    <div class="ico s4"><ion-icon name="hardware-chip-outline"></ion-icon></div>
                    <div class="body">
                        <strong>Q: <?php echo htmlspecialchars($d['question'], ENT_QUOTES); ?></strong>
                        <div style="font-size:13px; margin-top:8px; padding:12px; background:var(--bg-card); border-radius:8px; border:1px solid var(--border-color);">
                            <?php echo $d['answer']; // Output HTML from AI ?>
                        </div>
                        <div class="when" style="margin-top:6px;"><?php echo htmlspecialchars(date('M j, g:i A', strtotime($d['created_at'])), ENT_QUOTES); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_dashboard_footer(); ?>
