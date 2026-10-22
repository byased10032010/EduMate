<?php
/**
 * EduMate - Teacher: Edit course + manage lessons + manage quizzes
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();
$cid  = (int)($_GET['id'] ?? 0);
if ($cid <= 0) { header('Location: courses.php'); exit; }

$own = $pdo->prepare('SELECT * FROM courses WHERE id = :id AND teacher_id = :u');
$own->execute([':id' => $cid, ':u' => $uid]);
$course = $own->fetch();
if (!$course) { flash_set('error', 'Course not found.'); header('Location: courses.php'); exit; }

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: course_edit.php?id=' . $cid); exit;
    }

    if ($action === 'update_course') {
        $title  = trim((string)($_POST['title'] ?? ''));
        $subject= trim((string)($_POST['subject'] ?? ''));
        $level  = $_POST['level'] ?? 'beginner';
        $color  = $_POST['cover_color'] ?? '#667eea';
        $desc   = trim((string)($_POST['description'] ?? ''));
        $class  = isset($_POST['class']) ? (int)$_POST['class'] : null;
        $board  = isset($_POST['board']) ? trim((string)$_POST['board']) : null;
        if (mb_strlen($title) < 3 || $subject === '') {
            flash_set('error', 'Title (3+ chars) and subject are required.');
        } else {
            $pdo->prepare(
                'UPDATE courses SET title=:ti, subject=:s, level=:l, description=:d, cover_color=:c, class=:cl, board=:bo
                 WHERE id=:id AND teacher_id=:u'
            )->execute([':ti' => $title, ':s' => $subject, ':l' => $level, ':d' => $desc, ':c' => $color, ':cl' => $class, ':bo' => $board, ':id' => $cid, ':u' => $uid]);
            flash_set('success', 'Course updated.');
        }
    } elseif ($action === 'add_lesson') {
        $title = trim((string)($_POST['title'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        $dur   = max(1, (int)($_POST['duration_min'] ?? 15));
        $video = trim((string)($_POST['video_url'] ?? ''));
        if ($title === '' || $summary === '') {
            flash_set('error', 'Title and summary are required.');
        } else {
            $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM lessons WHERE course_id = $cid")->fetchColumn();
            $pdo->prepare(
                'INSERT INTO lessons (course_id, title, summary, body, video_url, duration_min, sort_order)
                 VALUES (:c, :t, :s, :b, :v, :d, :o)'
            )->execute([':c' => $cid, ':t' => $title, ':s' => $summary, ':b' => $body, ':v' => $video ?: null, ':d' => $dur, ':o' => $next]);
            flash_set('success', 'Lesson added.');
        }
    } elseif ($action === 'delete_lesson') {
        $lid = (int)($_POST['lesson_id'] ?? 0);
        $pdo->prepare('DELETE FROM lessons WHERE id = :l AND course_id = :c')->execute([':l' => $lid, ':c' => $cid]);
        flash_set('success', 'Lesson removed.');
    } elseif ($action === 'add_dpp') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            flash_set('error', 'DPP title and body are required.');
        } else {
            $pdo->prepare('INSERT INTO dpps (course_id, title, body) VALUES (:c, :t, :b)')
                ->execute([':c' => $cid, ':t' => $title, ':b' => $body]);
            flash_set('success', 'DPP added.');
        }
    } elseif ($action === 'delete_dpp') {
        $dppId = (int)($_POST['dpp_id'] ?? 0);
        $pdo->prepare('DELETE FROM dpps WHERE id = :id AND course_id = :c')->execute([':id' => $dppId, ':c' => $cid]);
        flash_set('success', 'DPP removed.');
    } elseif ($action === 'add_notice') {
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($title === '' || $content === '') {
            flash_set('error', 'Notice title and content are required.');
        } else {
            $pdo->prepare('INSERT INTO notices (course_id, title, content) VALUES (:c, :t, :co)')
                ->execute([':c' => $cid, ':t' => $title, ':co' => $content]);
            flash_set('success', 'Announcement posted.');
        }
    } elseif ($action === 'delete_notice') {
        $noticeId = (int)($_POST['notice_id'] ?? 0);
        $pdo->prepare('DELETE FROM notices WHERE id = :id AND course_id = :c')->execute([':id' => $noticeId, ':c' => $cid]);
        flash_set('success', 'Announcement removed.');
    }

    header('Location: course_edit.php?id=' . $cid);
    exit;
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = :c ORDER BY sort_order ASC, id ASC');
$lessons->execute([':c' => $cid]);
$lessons = $lessons->fetchAll();

$dpps = $pdo->prepare('SELECT * FROM dpps WHERE course_id = :c ORDER BY created_at DESC');
$dpps->execute([':c' => $cid]);
$dpps = $dpps->fetchAll();

$notices = $pdo->prepare('SELECT * FROM notices WHERE course_id = :c ORDER BY created_at DESC');
$notices->execute([':c' => $cid]);
$notices = $notices->fetchAll();

render_dashboard_header(
    'Edit: ' . $course['title'],
    teacher_nav(),
    'courses',
    ['Home', 'Courses', $course['title']]
);
?>
<section class="panel">
    <h2>Course details</h2>
    <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update_course">
        <div class="form-grid">
            <div class="form-group span-2">
                <label>Title</label>
                <input class="form-control" name="title" value="<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>" required minlength="3" maxlength="180">
            </div>
            <div class="form-group">
                <label>Subject</label>
                <input class="form-control" name="subject" value="<?php echo htmlspecialchars($course['subject'], ENT_QUOTES); ?>" required maxlength="80">
            </div>
            <div class="form-group">
                <label>Level</label>
                <select class="form-control" name="level">
                    <option value="beginner"     <?php echo $course['level']==='beginner'?'selected':''; ?>>Beginner</option>
                    <option value="intermediate" <?php echo $course['level']==='intermediate'?'selected':''; ?>>Intermediate</option>
                    <option value="advanced"     <?php echo $course['level']==='advanced'?'selected':''; ?>>Advanced</option>
                </select>
            </div>
            <div class="form-group">
                <label>Class</label>
                <select class="form-control" name="class" required>
                    <?php for ($cl = 5; $cl <= 10; $cl++): ?>
                        <option value="<?php echo $cl; ?>" <?php echo ((int)$course['class'] === $cl) ? 'selected' : ''; ?>>Class <?php echo $cl; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Board</label>
                <select class="form-control" name="board" required>
                    <?php foreach (get_all_boards() as $b): ?>
                        <option value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>" <?php echo ($course['board'] === $b) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Cover color</label>
                <input class="form-control" type="color" name="cover_color" value="<?php echo htmlspecialchars($course['cover_color'], ENT_QUOTES); ?>">
            </div>
            <div class="form-group span-2">
                <label>Description</label>
                <textarea class="form-control" name="description"><?php echo htmlspecialchars($course['description'], ENT_QUOTES); ?></textarea>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:14px;"><ion-icon name="save-outline"></ion-icon> Save</button>
    </form>
</section>

<div class="panel-grid">
    <section class="panel">
        <h2>Lessons <span class="sub"><?php echo count($lessons); ?> total</span></h2>
        <?php foreach ($lessons as $l): ?>
            <div class="item" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border:1px solid var(--border-color); border-radius:10px; margin-bottom:8px; background:var(--bg-secondary);">
                <div class="ico" style="background: var(--gradient-accent);"><ion-icon name="book-outline"></ion-icon></div>
                <div style="flex:1;">
                    <strong><?php echo htmlspecialchars($l['title'], ENT_QUOTES); ?></strong>
                    <div style="font-size:12px; color:var(--text-light);"><?php echo htmlspecialchars($l['summary'], ENT_QUOTES); ?> · <?php echo (int)$l['duration_min']; ?> min</div>
                </div>
                <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>" data-confirm="Delete this lesson?" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_lesson">
                    <input type="hidden" name="lesson_id" value="<?php echo (int)$l['id']; ?>">
                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                </form>
            </div>
        <?php endforeach; ?>

        <details style="margin-top:14px;">
            <summary style="cursor:pointer; color:#667eea; font-weight:600;">+ Add a new lesson</summary>
            <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>" style="margin-top:10px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_lesson">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Title</label>
                        <input class="form-control" name="title" required>
                    </div>
                    <div class="form-group span-2">
                        <label>Summary</label>
                        <input class="form-control" name="summary" required maxlength="500">
                    </div>
                    <div class="form-group span-2">
                        <label>Body (markdown / text)</label>
                        <textarea class="form-control" name="body" rows="4"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Video URL (optional)</label>
                        <input class="form-control" name="video_url" type="url" placeholder="https://...">
                    </div>
                    <div class="form-group">
                        <label>Duration (min)</label>
                        <input class="form-control" name="duration_min" type="number" min="1" value="15">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit" style="margin-top:14px;"><ion-icon name="add-outline"></ion-icon> Add lesson</button>
            </form>
        </details>
    </section>
</div>

<div class="panel-grid" style="margin-top: 20px;">
    <!-- DPPs Panel -->
    <section class="panel">
        <h2>DPPs (Daily Practice Problems) <span class="sub"><?php echo count($dpps); ?> total</span></h2>
        <?php foreach ($dpps as $d): ?>
            <div style="border:1px solid var(--border-color); border-radius:10px; padding:10px 12px; margin-bottom:8px; background:var(--bg-secondary);">
                <div style="display:flex; align-items:center; gap:8px;">
                    <strong style="flex:1;"><?php echo htmlspecialchars($d['title'], ENT_QUOTES); ?></strong>
                    <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>" data-confirm="Remove this DPP?" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_dpp">
                        <input type="hidden" name="dpp_id" value="<?php echo (int)$d['id']; ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                    </form>
                </div>
                <details style="margin-top:6px;">
                    <summary style="cursor:pointer; font-size:12px; color:#667eea; font-weight: 600;">View content</summary>
                    <div style="background:#fff; border:1px solid var(--border-color); border-radius:6px; padding:10px; margin-top:6px; font-size:12px; white-space:pre-wrap; color:var(--text-secondary);"><?php echo htmlspecialchars($d['body'], ENT_QUOTES); ?></div>
                </details>
            </div>
        <?php endforeach; ?>

        <details style="margin-top:14px;">
            <summary style="cursor:pointer; color:#667eea; font-weight:600;">+ Upload a new DPP</summary>
            <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>" style="margin-top:10px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_dpp">
                <div class="form-group">
                    <label>DPP Title</label>
                    <input class="form-control" name="title" placeholder="e.g. Algebra DPP 1" required>
                </div>
                <div class="form-group">
                    <label>DPP Body / Questions</label>
                    <textarea class="form-control" name="body" rows="5" placeholder="Q1. Solve 2x + 5 = 15..." required></textarea>
                </div>
                <button class="btn btn-primary btn-sm" type="submit" style="margin-top:10px;"><ion-icon name="add-outline"></ion-icon> Upload DPP</button>
            </form>
        </details>
    </section>

    <!-- Notices Panel -->
    <section class="panel">
        <h2>Announcements &amp; Notices <span class="sub"><?php echo count($notices); ?> total</span></h2>
        <?php foreach ($notices as $n): ?>
            <div style="border:1px solid var(--border-color); border-radius:10px; padding:10px 12px; margin-bottom:8px; background:var(--bg-secondary);">
                <div style="display:flex; align-items:center; gap:8px;">
                    <strong style="flex:1;"><?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?></strong>
                    <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>" data-confirm="Remove announcement?" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_notice">
                        <input type="hidden" name="notice_id" value="<?php echo (int)$n['id']; ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                    </form>
                </div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:6px; white-space:pre-wrap;"><?php echo htmlspecialchars($n['content'], ENT_QUOTES); ?></div>
                <span style="font-size:10px; color:var(--text-light); display:block; margin-top:6px;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at'])), ENT_QUOTES); ?></span>
            </div>
        <?php endforeach; ?>

        <details style="margin-top:14px;">
            <summary style="cursor:pointer; color:#667eea; font-weight:600;">+ Post an announcement</summary>
            <form method="POST" action="course_edit.php?id=<?php echo $cid; ?>" style="margin-top:10px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_notice">
                <div class="form-group">
                    <label>Notice Title</label>
                    <input class="form-control" name="title" placeholder="e.g. Schedule Change" required>
                </div>
                <div class="form-group">
                    <label>Content</label>
                    <textarea class="form-control" name="content" rows="4" placeholder="Dear students, next week's live session is..." required></textarea>
                </div>
                <button class="btn btn-primary btn-sm" type="submit" style="margin-top:10px;"><ion-icon name="megaphone-outline"></ion-icon> Post Notice</button>
            </form>
        </details>
    </section>
</div>
<?php render_dashboard_footer(); ?>
