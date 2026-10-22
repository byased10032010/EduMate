<?php
/**
 * EduMate - Student: Subjects Hub
 * - Lists subjects for the student's selected Class & Board
 * - Under each subject: lists courses, videos, and DPPs
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Fetch student's Class & Board
$uStmt = $pdo->prepare('SELECT class, board FROM users WHERE id = :id');
$uStmt->execute([':id' => $uid]);
$u = $uStmt->fetch();
$class = $u['class'] ?? null;
$board = $u['board'] ?? null;

if (!$class || !$board) {
    render_dashboard_header('Subjects Hub', student_nav(), 'subjects', ['Home', 'Subjects']);
    ?>
    <section class="panel">
        <div class="empty">
            <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
            <h3>Class and Board not set</h3>
            <p>Please update your Class and Board in your profile to view subjects and course materials.</p>
            <p style="margin-top:14px;"><a class="btn btn-primary" href="profile.php"><ion-icon name="help-circle-outline"></ion-icon> Edit Profile</a></p>
        </div>
    </section>
    <?php
    render_dashboard_footer();
    exit;
}

// Fetch all subjects for this student's Class & Board
$subStmt = $pdo->prepare('SELECT * FROM course_subjects WHERE class = :cl AND board = :bo ORDER BY name ASC');
$subStmt->execute([':cl' => $class, ':bo' => $board]);
$subjects = $subStmt->fetchAll();

// Active Subject Filter
$activeSub = $_GET['subject'] ?? ($subjects[0]['name'] ?? '');

render_dashboard_header(
    'Subjects Hub',
    student_nav(),
    'subjects',
    ['Home', 'Subjects', $activeSub ?: 'All Subjects']
);
?>
<section class="panel" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 style="margin:0;">Class <?php echo $class; ?> &middot; <?php echo htmlspecialchars($board, ENT_QUOTES); ?></h2>
            <p style="color:var(--text-light); font-size:12px; margin:4px 0 0 0;">Curriculum-aligned subjects and resources</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="profile.php"><ion-icon name="help-circle-outline"></ion-icon> Change Class/Board</a>
    </div>

    <!-- Subject Tabs -->
    <?php if (empty($subjects)): ?>
        <div style="margin-top:20px;" class="empty">
            <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
            <h3>No subjects configured</h3>
            <p>No subjects are listed for Class <?php echo $class; ?> under <?php echo htmlspecialchars($board, ENT_QUOTES); ?> Board.</p>
        </div>
    <?php else: ?>
        <div style="display:flex; gap:8px; overflow-x:auto; padding:10px 0; border-bottom:1px solid var(--border-color); margin-top:14px;">
            <?php foreach ($subjects as $s): ?>
                <a class="btn btn-sm <?php echo $activeSub === $s['name'] ? 'btn-primary' : 'btn-ghost'; ?>" href="subjects.php?subject=<?php echo urlencode($s['name']); ?>">
                    <?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($activeSub)): 
    // Fetch courses in this subject and class/board
    $cStmt = $pdo->prepare('SELECT c.*, u.full_name AS teacher_name,
                                  (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count
                             FROM courses c 
                             JOIN users u ON u.id = c.teacher_id
                            WHERE c.subject = :sub AND c.class = :cl AND c.board = :bo AND c.is_published = 1');
    $cStmt->execute([':sub' => $activeSub, ':cl' => $class, ':bo' => $board]);
    $courses = $cStmt->fetchAll();

    // Fetch videos in this subject
    $vStmt = $pdo->prepare('SELECT v.*, c.title AS course_title 
                             FROM videos v 
                             LEFT JOIN courses c ON c.id = v.course_id
                            WHERE v.subject = :sub 
                              AND (
                                 (c.class = :cl OR (c.class IS NULL AND (v.class = :vcl OR v.class IS NULL))) AND 
                                 (c.board = :bo OR (c.board IS NULL AND (v.board = :vbo OR v.board IS NULL)))
                              )');
    $vStmt->execute([':sub' => $activeSub, ':cl' => $class, ':vcl' => $class, ':bo' => $board, ':vbo' => $board]);
    $videos = $vStmt->fetchAll();

    // Fetch DPPs (Daily Practice Problems) in this subject's courses
    $dppStmt = $pdo->prepare('SELECT d.*, c.title AS course_title 
                               FROM dpps d
                               JOIN courses c ON c.id = d.course_id
                              WHERE c.subject = :sub AND c.class = :cl AND c.board = :bo');
    $dppStmt->execute([':sub' => $activeSub, ':cl' => $class, ':bo' => $board]);
    $dpps = $dppStmt->fetchAll();

    // Fetch notices for these courses
    $noticeStmt = $pdo->prepare('SELECT n.*, c.title AS course_title
                                   FROM notices n
                                   JOIN courses c ON c.id = n.course_id
                                  WHERE c.subject = :sub AND c.class = :cl AND c.board = :bo
                                  ORDER BY n.created_at DESC');
    $noticeStmt->execute([':sub' => $activeSub, ':cl' => $class, ':bo' => $board]);
    $notices = $noticeStmt->fetchAll();
?>

<div class="panel-grid">
    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Courses in this subject -->
        <section class="panel">
            <h3>Subject Courses <span class="sub"><?php echo count($courses); ?> available</span></h3>
            <?php if (empty($courses)): ?>
                <div class="empty" style="padding:20px 0;"><p>No courses published yet for this subject.</p></div>
            <?php else: ?>
                <div class="grid-cards">
                    <?php foreach ($courses as $c): ?>
                        <article class="tile" style="margin-bottom:10px;">
                            <div class="cover" style="background:linear-gradient(135deg, <?php echo htmlspecialchars($c['cover_color'], ENT_QUOTES); ?>, #1a1a2e); height:80px; padding:10px;">
                                <span class="pill" style="font-size:10px;"><?php echo htmlspecialchars($c['level'], ENT_QUOTES); ?></span>
                            </div>
                            <div class="body" style="padding:12px;">
                                <h4 style="margin:0 0 6px; font-weight:600; font-size:14px;"><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></h4>
                                <div style="font-size:11px; color:var(--text-light); margin-bottom:8px;"><ion-icon name="help-circle-outline"></ion-icon> <?php echo htmlspecialchars($c['teacher_name'], ENT_QUOTES); ?></div>
                                <div class="meta" style="font-size:11px;">
                                    <span><ion-icon name="list-outline"></ion-icon> <?php echo $c['lesson_count']; ?> Lessons</span>
                                </div>
                            </div>
                            <div class="footer" style="padding:10px 12px;">
                                <a class="btn btn-primary btn-sm" style="width:100%; text-align:center;" href="course.php?id=<?php echo (int)$c['id']; ?>">Start Learning</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Videos in this subject -->
        <section class="panel">
            <h3>Lecture Videos <span class="sub"><?php echo count($videos); ?> found</span></h3>
            <?php if (empty($videos)): ?>
                <div class="empty" style="padding:20px 0;"><p>No videos uploaded for this subject yet.</p></div>
            <?php else: ?>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:10px;">
                    <?php foreach ($videos as $v): ?>
                        <div style="border:1px solid var(--border-color); border-radius:12px; overflow:hidden; background:var(--bg-secondary); padding:12px;">
                            <h4 style="margin:0 0 4px; font-size:13px; font-weight:600;"><?php echo htmlspecialchars($v['title'], ENT_QUOTES); ?></h4>
                            <p style="font-size:11px; color:var(--text-light); margin:0 0 8px;"><?php echo htmlspecialchars($v['description'], ENT_QUOTES); ?></p>
                            
                            <!-- Video Embed modal or link -->
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; font-size:11px; color:var(--text-light);">
                                <span><ion-icon name="time-outline"></ion-icon> <?php echo (int)$v['duration_min']; ?> mins</span>
                                <a class="btn btn-primary btn-sm" href="videos.php?subject=<?php echo urlencode($activeSub); ?>"><ion-icon name="play-circle-outline"></ion-icon> Watch</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Notices Bulletin -->
        <section class="panel">
            <h3>Course Notices <span class="sub">📢</span></h3>
            <?php if (empty($notices)): ?>
                <p style="font-size:12px; color:var(--text-light); font-style:italic;">No active announcements for this subject.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($notices as $n): ?>
                        <div style="border:1px solid var(--border-color); border-radius:10px; padding:12px; background:#fff7ed; border-left:4px solid #c2410c;">
                            <h4 style="margin:0 0 4px; font-size:13px; font-weight:700; color:#c2410c;"><?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?></h4>
                            <p style="font-size:11px; color:var(--text-light); margin:0 0 6px;">Course: <?php echo htmlspecialchars($n['course_title'], ENT_QUOTES); ?></p>
                            <div style="font-size:12px; color:var(--text-secondary); line-height:1.4; white-space:pre-wrap;"><?php echo htmlspecialchars($n['content'], ENT_QUOTES); ?></div>
                            <span style="font-size:10px; color:var(--text-light); display:block; margin-top:6px;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at'])), ENT_QUOTES); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- DPPs & DPP Questions -->
        <section class="panel">
            <h3>DPPs &amp; Practice Problems <span class="sub">📝</span></h3>
            <?php if (empty($dpps)): ?>
                <p style="font-size:12px; color:var(--text-light); font-style:italic;">No daily practice papers uploaded for this subject yet.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($dpps as $d): ?>
                        <div style="border:1px solid var(--border-color); border-radius:10px; padding:12px; background:var(--bg-secondary);">
                            <h4 style="margin:0 0 4px; font-size:13px; font-weight:700;"><?php echo htmlspecialchars($d['title'], ENT_QUOTES); ?></h4>
                            <div style="font-size:11px; color:var(--text-light); margin-bottom:8px;">From course: <?php echo htmlspecialchars($d['course_title'], ENT_QUOTES); ?></div>
                            
                            <details style="margin-top:6px;">
                                <summary style="cursor:pointer; font-size:12px; color:#667eea; font-weight:600;">View DPP Sheet</summary>
                                <div style="background:#fff; border:1px solid var(--border-color); border-radius:6px; padding:10px; margin-top:8px; font-size:12px; color:var(--text-secondary); white-space:pre-wrap;"><?php echo htmlspecialchars($d['body'], ENT_QUOTES); ?></div>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php endif; ?>

<?php render_dashboard_footer(); ?>
