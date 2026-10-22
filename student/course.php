<?php
/**
 * EduMate - Student: Course detail (PW-like Structured Classroom)
 * - Lectures (Videos)
 * - Notes & Material (Lessons)
 * - DPPs (Daily Practice Problems)
 * - Quizzes
 * - Announcements
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$courseId = (int)($_GET['id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'lectures'; // default to lectures

if (!in_array($activeTab, ['lectures', 'notes', 'dpps', 'quizzes', 'announcements'], true)) {
    $activeTab = 'lectures';
}

if ($courseId <= 0) {
    flash_set('error', 'Invalid course.');
    header('Location: courses.php');
    exit;
}

$pdo = db();

// POST: mark lesson complete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $lessonId = (int)($_POST['lesson_id'] ?? 0);

    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired. Please refresh and try again.');
    } elseif ($action === 'complete' && $lessonId > 0) {
        try {
            // Verify this lesson belongs to a course the student is enrolled in
            $check = $pdo->prepare(
                'SELECT 1 FROM lessons l
                   JOIN enrollments e ON e.course_id = l.course_id
                  WHERE l.id = :lid AND e.student_id = :u'
            );
            $check->execute([':lid' => $lessonId, ':u' => $uid]);
            if ($check->fetch()) {
                $pdo->prepare(
                    'INSERT IGNORE INTO lesson_completions (student_id, lesson_id) VALUES (:u, :l)'
                )->execute([':u' => $uid, ':l' => $lessonId]);
                
                // Recompute progress
                $tot = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE course_id = $courseId")->fetchColumn();
                
                $done = $pdo->prepare(
                    'SELECT COUNT(*) FROM lesson_completions lc
                       JOIN lessons l ON l.id = lc.lesson_id
                      WHERE lc.student_id = :u AND l.course_id = :cid'
                );
                $done->execute([':u' => $uid, ':cid' => $courseId]);
                $completed = (int)$done->fetchColumn();
                $progress = $tot > 0 ? (int)round($completed * 100 / $tot) : 0;
                
                $pdo->prepare(
                    'UPDATE enrollments SET progress = :p, last_active = NOW() WHERE student_id = :u AND course_id = :cid'
                )->execute([':p' => $progress, ':u' => $uid, ':cid' => $courseId]);

                update_streak($uid);
                record_activity($uid, 'fa-check-circle', 'Completed lesson', null);
                flash_set('success', 'Lesson marked complete. Progress: ' . $progress . '%');
            } else {
                flash_set('error', 'You are not enrolled in this course.');
            }
        } catch (Throwable $e) {
            error_log('complete lesson: ' . $e->getMessage());
            flash_set('error', 'Could not update progress.');
        }
    }
    header('Location: course.php?id=' . $courseId . '&tab=' . $activeTab);
    exit;
}

// Course details
$courseStmt = $pdo->prepare(
    'SELECT c.*, t.full_name AS teacher_name
       FROM courses c
       JOIN users t ON t.id = c.teacher_id
      WHERE c.id = :c'
);
$courseStmt->execute([':c' => $courseId]);
$course = $courseStmt->fetch();

if (!$course) {
    flash_set('error', 'Course not found.');
    header('Location: courses.php');
    exit;
}

// Fetch checking enrollment
$enrolledStmt = $pdo->prepare('SELECT progress FROM enrollments WHERE student_id = :u AND course_id = :c');
$enrolledStmt->execute([':u' => $uid, ':c' => $courseId]);
$progress = $enrolledStmt->fetchColumn();
$isEnrolled = $progress !== false;
$progress = $isEnrolled ? (int)$progress : 0;

// Fetch elements for structured tabs
// 1. Videos
$videoStmt = $pdo->prepare('SELECT * FROM videos WHERE course_id = :cid ORDER BY created_at DESC');
$videoStmt->execute([':cid' => $courseId]);
$videos = $videoStmt->fetchAll();

// 2. Lessons
$lessonsStmt = $pdo->prepare(
    'SELECT l.*, lc.id AS completion_id
       FROM lessons l
       LEFT JOIN lesson_completions lc ON lc.lesson_id = l.id AND lc.student_id = :u
      WHERE l.course_id = :c
      ORDER BY l.sort_order ASC, l.id ASC'
);
$lessonsStmt->execute([':c' => $courseId, ':u' => $uid]);
$lessons = $lessonsStmt->fetchAll();

// 3. DPPs
$dppStmt = $pdo->prepare('SELECT * FROM dpps WHERE course_id = :cid ORDER BY created_at DESC');
$dppStmt->execute([':cid' => $courseId]);
$dpps = $dppStmt->fetchAll();

// 4. Quizzes
$quizStmt = $pdo->prepare(
    'SELECT q.*, 
            (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS qcount,
            (SELECT MAX(qa.score/qa.total * 100) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = :uid) AS high_score
       FROM quizzes q 
      WHERE q.course_id = :cid 
      ORDER BY q.created_at DESC'
);
$quizStmt->execute([':cid' => $courseId, ':uid' => $uid]);
$quizzes = $quizStmt->fetchAll();

// 5. Notices
$noticeStmt = $pdo->prepare('SELECT * FROM notices WHERE course_id = :cid ORDER BY created_at DESC');
$noticeStmt->execute([':cid' => $courseId]);
$notices = $noticeStmt->fetchAll();

render_dashboard_header(
    $course['title'],
    student_nav(),
    'courses',
    ['Home', 'My Courses', $course['title']]
);
?>

<!-- Header Info Banner -->
<section class="panel" style="position:relative; overflow:hidden; margin-bottom:20px;">
    <div style="background:linear-gradient(135deg, <?php echo htmlspecialchars($course['cover_color'], ENT_QUOTES); ?>, #1a1a2e); position:absolute; top:0; left:0; right:0; height:6px;"></div>
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
        <div>
            <span class="tag" style="background:#e0e7ff; color:#4338ca; margin-bottom:8px; font-weight:600; text-transform:uppercase; font-size:11px;">
                <?php echo htmlspecialchars($course['subject'], ENT_QUOTES); ?> &middot; Class <?php echo $course['class']; ?> &middot; <?php echo htmlspecialchars($course['board'], ENT_QUOTES); ?>
            </span>
            <h2 style="margin:4px 0 6px 0; font-size:22px; font-weight:800;"><?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?></h2>
            <p style="color:var(--text-secondary); margin:0; font-size:13px; max-width:600px;"><?php echo htmlspecialchars($course['description'], ENT_QUOTES); ?></p>
            <div style="font-size:12px; color:var(--text-light); margin-top:12px;">
                <ion-icon name="help-circle-outline"></ion-icon> Master Instructor: <strong><?php echo htmlspecialchars($course['teacher_name'], ENT_QUOTES); ?></strong>
            </div>
        </div>

        <div style="min-width:180px;">
            <?php if (!$isEnrolled): ?>
                <form method="POST" action="enroll.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                    <button class="btn btn-primary" style="width:100%; text-align:center;"><ion-icon name="add-outline"></ion-icon> Enroll Now</button>
                </form>
            <?php else: ?>
                <div style="text-align:right;">
                    <div style="font-size:13px; font-weight:600; color:var(--text-secondary); margin-bottom:4px;">Syllabus Progress</div>
                    <div class="progress-bar" style="width:180px; height:8px;"><span style="width: <?php echo $progress; ?>%"></span></div>
                    <span style="font-size:12px; color:var(--text-light); font-weight:700;"><?php echo $progress; ?>% Complete</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- structured PW application-like classroom tabs -->
<div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:8px; border-bottom:1px solid var(--border-color); margin-bottom:20px;">
    <a class="btn btn-sm <?php echo $activeTab === 'lectures' ? 'btn-primary' : 'btn-ghost'; ?>" href="course.php?id=<?php echo $courseId; ?>&tab=lectures">
        <ion-icon name="videocam-outline"></ion-icon> Lectures (<?php echo count($videos); ?>)
    </a>
    <a class="btn btn-sm <?php echo $activeTab === 'notes' ? 'btn-primary' : 'btn-ghost'; ?>" href="course.php?id=<?php echo $courseId; ?>&tab=notes">
        <ion-icon name="book-outline"></ion-icon> Notes &amp; Materials (<?php echo count($lessons); ?>)
    </a>
    <a class="btn btn-sm <?php echo $activeTab === 'dpps' ? 'btn-primary' : 'btn-ghost'; ?>" href="course.php?id=<?php echo $courseId; ?>&tab=dpps">
        <ion-icon name="help-circle-outline"></ion-icon> DPPs (<?php echo count($dpps); ?>)
    </a>
    <a class="btn btn-sm <?php echo $activeTab === 'quizzes' ? 'btn-primary' : 'btn-ghost'; ?>" href="course.php?id=<?php echo $courseId; ?>&tab=quizzes">
        <ion-icon name="help-circle-outline"></ion-icon> Quizzes (<?php echo count($quizzes); ?>)
    </a>
    <a class="btn btn-sm <?php echo $activeTab === 'announcements' ? 'btn-primary' : 'btn-ghost'; ?>" href="course.php?id=<?php echo $courseId; ?>&tab=announcements">
        <ion-icon name="megaphone-outline"></ion-icon> Notices (<?php echo count($notices); ?>)
    </a>
</div>

<!-- Tab Contents -->
<div class="classroom-content">
    <?php if ($activeTab === 'lectures'): ?>
        <section class="panel">
            <h2>Course Lectures &amp; Videos</h2>
            <?php if (empty($videos)): ?>
                <div class="empty">
                    <div class="ico"><ion-icon name="play-circle-outline"></ion-icon></div>
                    <h3>No lectures uploaded</h3>
                    <p>No video lectures have been linked to this course yet.</p>
                </div>
            <?php else: ?>
                <div class="grid-cards" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
                    <?php foreach ($videos as $v): ?>
                        <article class="tile" style="margin-bottom:10px;">
                            <div class="cover" style="background:linear-gradient(135deg, #4facfe, #00f2fe); height:110px; padding:10px;">
                                <ion-icon name="help-circle-outline"></ion-icon>
                                <span class="pill"><?php echo (int)$v['duration_min']; ?> mins</span>
                            </div>
                            <div class="body" style="padding:12px;">
                                <h4 style="margin:0 0 6px; font-size:14px; font-weight:700;"><?php echo htmlspecialchars($v['title'], ENT_QUOTES); ?></h4>
                                <p style="font-size:12px; color:var(--text-light); margin-bottom:8px;"><?php echo htmlspecialchars($v['description'], ENT_QUOTES); ?></p>
                            </div>
                            <div class="footer" style="padding:10px 12px;">
                                <a class="btn btn-primary btn-sm" style="width:100%; text-align:center;" href="videos.php?v=<?php echo (int)$v['id']; ?>">
                                    <ion-icon name="play-circle-outline"></ion-icon> Watch Lecture
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($activeTab === 'notes'): ?>
        <section class="panel">
            <h2>Notes &amp; Chapter Materials</h2>
            <?php if (empty($lessons)): ?>
                <div class="empty">
                    <div class="ico"><ion-icon name="book-outline"></ion-icon></div>
                    <h3>No chapter notes</h3>
                    <p>Notes/lessons will be uploaded by the teacher soon.</p>
                </div>
            <?php else: ?>
                <div class="activity">
                    <?php foreach ($lessons as $i => $l): ?>
                        <div class="item" style="padding:14px; border-bottom:1px solid var(--border-color);">
                            <div class="ico <?php echo $l['completion_id'] ? 's3' : ''; ?>" style="<?php echo $l['completion_id'] ? 'background: linear-gradient(135deg, #43e97b, #38f9d7);' : ''; ?>">
                                <i class="fas <?php echo $l['completion_id'] ? 'fa-check' : 'fa-play'; ?>"></i>
                            </div>
                            <div class="body" style="margin-left:14px;">
                                <strong style="font-size:15px;"><?php echo ($i + 1) . '. ' . htmlspecialchars($l['title'], ENT_QUOTES); ?></strong>
                                <div style="color:var(--text-secondary); font-size:12px; margin-top:4px;"><?php echo htmlspecialchars($l['summary'], ENT_QUOTES); ?></div>
                                <div style="font-size:11px; color:var(--text-light); margin-top:6px;"><ion-icon name="time-outline"></ion-icon> Duration: <?php echo (int)$l['duration_min']; ?> mins</div>
                                
                                <?php if (!empty($l['body'])): ?>
                                    <details style="margin-top:10px;">
                                        <summary style="cursor:pointer; color:#667eea; font-size:12px; font-weight:600;">View Lesson Text &amp; Formulae</summary>
                                        <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-top:8px; color:var(--text-secondary); font-size:13px; line-height:1.6; white-space:pre-wrap;"><?php echo htmlspecialchars($l['body'], ENT_QUOTES); ?></div>
                                    </details>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($isEnrolled && !$l['completion_id']): ?>
                                <form method="POST" action="course.php?id=<?php echo $courseId; ?>&tab=notes">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="lesson_id" value="<?php echo (int)$l['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><ion-icon name="checkmark-outline"></ion-icon> Complete</button>
                                </form>
                            <?php elseif ($l['completion_id']): ?>
                                <span class="tag success" style="font-size:11px; font-weight:700;">Completed</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($activeTab === 'dpps'): ?>
        <section class="panel">
            <h2>Daily Practice Problems (DPP)</h2>
            <?php if (empty($dpps)): ?>
                <div class="empty">
                    <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
                    <h3>No DPPs uploaded</h3>
                    <p>Daily practice sheets are empty for this course.</p>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($dpps as $d): ?>
                        <div style="border:1px solid var(--border-color); border-radius:12px; padding:14px; background:var(--bg-secondary);">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <h4 style="margin:0; font-size:15px; font-weight:700;"><ion-icon name="help-circle-outline"></ion-icon> <?php echo htmlspecialchars($d['title'], ENT_QUOTES); ?></h4>
                                <span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars(date('M j, Y', strtotime($d['created_at'])), ENT_QUOTES); ?></span>
                            </div>
                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer; font-size:12px; color:#667eea; font-weight:600;">Open DPP Questions</summary>
                                <div style="background:#fff; border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-top:8px; font-size:13px; line-height:1.6; white-space:pre-wrap; color:var(--text-secondary);"><?php echo htmlspecialchars($d['body'], ENT_QUOTES); ?></div>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($activeTab === 'quizzes'): ?>
        <section class="panel">
            <h2>Course Quizzes</h2>
            <?php if (empty($quizzes)): ?>
                <div class="empty">
                    <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
                    <h3>No quizzes available</h3>
                    <p>No tests or practice quizzes are linked to this course.</p>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($quizzes as $q): ?>
                        <div style="border:1px solid var(--border-color); border-radius:12px; padding:14px; background:var(--bg-secondary); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <div>
                                <h4 style="margin:0 0 4px; font-size:15px; font-weight:700;"><?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?></h4>
                                <span style="font-size:11px; color:var(--text-light);"><?php echo $q['qcount']; ?> questions &middot; Created <?php echo htmlspecialchars(date('M j, Y', strtotime($q['created_at'])), ENT_QUOTES); ?></span>
                            </div>
                            <div>
                                <?php if ($q['high_score'] !== null): ?>
                                    <span class="tag success" style="margin-right:8px; font-weight:700;">Best Score: <?php echo (int)$q['high_score']; ?>%</span>
                                <?php endif; ?>
                                <a class="btn btn-primary btn-sm" href="quizzes.php?id=<?php echo $q['id']; ?>">
                                    <?php echo $q['high_score'] !== null ? 'Retake Quiz' : 'Start Quiz'; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($activeTab === 'announcements'): ?>
        <section class="panel">
            <h2>Announcements &amp; Bulletins</h2>
            <?php if (empty($notices)): ?>
                <div class="empty">
                    <div class="ico"><ion-icon name="megaphone-outline"></ion-icon></div>
                    <h3>No notices</h3>
                    <p>No announcements have been posted for this course.</p>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($notices as $n): ?>
                        <div style="border:1px solid var(--border-color); border-radius:12px; padding:14px; background:#fff7ed; border-left:4px solid #c2410c;">
                            <h4 style="margin:0 0 6px; font-size:15px; font-weight:700; color:#c2410c;"><?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?></h4>
                            <div style="font-size:13px; color:var(--text-secondary); line-height:1.5; white-space:pre-wrap;"><?php echo htmlspecialchars($n['content'], ENT_QUOTES); ?></div>
                            <span style="font-size:10px; color:var(--text-light); display:block; margin-top:8px;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at'])), ENT_QUOTES); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php render_dashboard_footer(); ?>
