<?php
/**
 * EduMate - Teacher: Courses (CRUD)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: courses.php');
        exit;
    }
    if ($action === 'create' || $action === 'update') {
        $title      = trim((string)($_POST['title'] ?? ''));
        $subject    = trim((string)($_POST['subject'] ?? ''));
        $level      = $_POST['level'] ?? 'beginner';
        $joinPolicy = $_POST['join_policy'] ?? 'request';
        $color      = $_POST['cover_color'] ?? '#667eea';
        $desc       = trim((string)($_POST['description'] ?? ''));
        $class      = isset($_POST['class']) ? (int)$_POST['class'] : null;
        $board      = isset($_POST['board']) ? trim((string)$_POST['board']) : null;
        $errors = [];
        if (mb_strlen($title) < 3) $errors[] = 'Title must be at least 3 chars.';
        if ($subject === '')        $errors[] = 'Subject is required.';
        if (!in_array($level, ['beginner','intermediate','advanced'], true)) $level = 'beginner';
        if (!in_array($joinPolicy, ['open','request','invite'], true)) $joinPolicy = 'request';
        if ($errors) {
            flash_set('error', $errors);
        } elseif ($action === 'create') {
            $pdo->prepare(
                'INSERT INTO courses (teacher_id, title, subject, level, join_policy, description, cover_color, class, board)
                 VALUES (:t, :ti, :s, :l, :jp, :d, :c, :cl, :bo)'
            )->execute([':t' => $uid, ':ti' => $title, ':s' => $subject, ':l' => $level, ':jp' => $joinPolicy, ':d' => $desc, ':c' => $color, ':cl' => $class, ':bo' => $board]);
            record_activity($uid, 'fa-book', 'Created a course: ' . $title, null);
            flash_set('success', 'Course created.');
        } else {
            $cid = (int)($_POST['course_id'] ?? 0);
            $own = $pdo->prepare('SELECT 1 FROM courses WHERE id = :id AND teacher_id = :u');
            $own->execute([':id' => $cid, ':u' => $uid]);
            if (!$own->fetch()) { flash_set('error', 'Course not found.'); }
            else {
                $pdo->prepare(
                    'UPDATE courses SET title=:ti, subject=:s, level=:l, join_policy=:jp, description=:d, cover_color=:c, class=:cl, board=:bo
                     WHERE id=:id AND teacher_id=:u'
                )->execute([':ti' => $title, ':s' => $subject, ':l' => $level, ':jp' => $joinPolicy, ':d' => $desc, ':c' => $color, ':cl' => $class, ':bo' => $board, ':id' => $cid, ':u' => $uid]);
                flash_set('success', 'Course updated.');
            }
        }
    } elseif ($action === 'delete') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $own = $pdo->prepare('SELECT 1 FROM courses WHERE id = :id AND teacher_id = :u');
        $own->execute([':id' => $cid, ':u' => $uid]);
        if (!$own->fetch()) { flash_set('error', 'Course not found.'); }
        else {
            $pdo->prepare('DELETE FROM courses WHERE id = :id AND teacher_id = :u')->execute([':id' => $cid, ':u' => $uid]);
            flash_set('success', 'Course deleted.');
        }
    }
    header('Location: courses.php');
    exit;
}

$list = $pdo->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons
       FROM courses c WHERE c.teacher_id = :u ORDER BY c.created_at DESC'
);
$list->execute([':u' => $uid]);
$courses = $list->fetchAll();

render_dashboard_header(
    'Courses',
    teacher_nav(),
    'courses',
    ['Home', 'Courses']
);
?>
<section class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">Your courses</h2>
        <button class="btn btn-primary" data-modal-open="#modal-new-course"><ion-icon name="add-outline"></ion-icon> New course</button>
    </div>

    <?php if (empty($courses)): ?>
        <div class="empty"><div class="ico"><ion-icon name="easel-outline"></ion-icon></div><h3>No courses yet</h3><p>Create your first course to get started.</p></div>
    <?php else: ?>
        <div class="grid-cards">
            <?php foreach ($courses as $c): ?>
                <article class="tile">
                    <div class="cover" style="background:linear-gradient(135deg, <?php echo htmlspecialchars($c['cover_color'], ENT_QUOTES); ?>, #1a1a2e);">
                        <ion-icon name="book-outline"></ion-icon>
                        <span class="pill"><?php echo htmlspecialchars($c['subject'], ENT_QUOTES); ?></span>
                    </div>
                    <div class="body">
                        <h3><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></h3>
                        <p><?php echo htmlspecialchars($c['description'], ENT_QUOTES); ?></p>
                        <div class="meta">
                            <span><ion-icon name="cellular-outline"></ion-icon> <?php echo htmlspecialchars(ucfirst($c['level']), ENT_QUOTES); ?></span>
                            <span><ion-icon name="lock-closed-outline"></ion-icon> <?php echo htmlspecialchars(ucfirst($c['join_policy']), ENT_QUOTES); ?></span>
                            <?php if ($c['class']): ?>
                                <span><ion-icon name="school-outline"></ion-icon> Class <?php echo (int)$c['class']; ?></span>
                            <?php endif; ?>
                            <?php if ($c['board']): ?>
                                <span><ion-icon name="business-outline"></ion-icon> <?php echo htmlspecialchars($c['board'], ENT_QUOTES); ?></span>
                            <?php endif; ?>
                            <span><ion-icon name="list-outline"></ion-icon> <?php echo (int)$c['lessons']; ?> lessons</span>
                            <span><ion-icon name="people-outline"></ion-icon> <?php echo (int)$c['enrolled']; ?> students</span>
                        </div>
                    </div>
                    <div class="footer" style="display: flex; gap: 8px; align-items: center;">
                        <a class="btn btn-primary btn-sm" href="course_edit.php?id=<?php echo (int)$c['id']; ?>"><ion-icon name="create-outline"></ion-icon> Edit</a>
                        <a class="btn btn-ghost btn-sm" href="classes.php?course_id=<?php echo (int)$c['id']; ?>">Roster</a>
                        <form method="POST" action="courses.php" data-confirm="Delete this course and all its lessons?" style="margin-left:auto; display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- New course modal -->
<div class="modal-backdrop" id="modal-new-course">
    <div class="modal">
        <header>
            <h2>Create a new course</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="courses.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Title</label>
                        <input class="form-control" name="title" required minlength="3" maxlength="180" placeholder="e.g. Algebra Foundations">
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input class="form-control" name="subject" required maxlength="80" placeholder="e.g. Mathematics">
                    </div>
                    <div class="form-group">
                        <label>Level</label>
                        <select class="form-control" name="level">
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Join policy</label>
                        <select class="form-control" name="join_policy">
                            <option value="open">Open to all</option>
                            <option value="request" selected>Request access</option>
                            <option value="invite">Invite only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Class</label>
                        <select class="form-control" name="class" required>
                            <option value="" disabled selected>Select Class</option>
                            <?php for ($cl = 5; $cl <= 10; $cl++): ?>
                                <option value="<?php echo $cl; ?>">Class <?php echo $cl; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Board</label>
                        <select class="form-control" name="board" required>
                            <option value="" disabled selected>Select Board</option>
                            <?php foreach (get_all_boards() as $b): ?>
                                <option value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>"><?php echo htmlspecialchars($b, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Cover color</label>
                        <input class="form-control" type="color" name="cover_color" value="#667eea">
                    </div>
                    <div class="form-group span-2">
                        <label>Description</label>
                        <textarea class="form-control" name="description" placeholder="What will students learn?"></textarea>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Create course</button>
            </div>
        </form>
    </div>
</div>
<?php render_dashboard_footer(); ?>

