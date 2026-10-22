<?php
/**
 * EduMate - Student: My Courses
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];

// Handle enroll / unenroll POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $courseId = (int)($_POST['course_id'] ?? 0);
    if (!csrf_check(is_string($token) ? $token : null) || $courseId <= 0) {
        flash_set('error', 'Invalid request.');
    } elseif ($action === 'enroll') {
        try {
            db()->prepare(
                'INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (:u, :c)'
            )->execute([':u' => $uid, ':c' => $courseId]);
            record_activity($uid, 'fa-book-open', 'Enrolled in a new course', null);
            flash_set('success', 'Enrolled! You can find the course in your dashboard.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not enroll: ' . $e->getMessage());
        }
    } elseif ($action === 'unenroll') {
        try {
            db()->prepare(
                'DELETE FROM enrollments WHERE student_id = :u AND course_id = :c'
            )->execute([':u' => $uid, ':c' => $courseId]);
            flash_set('success', 'You have been unenrolled from the course.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not unenroll. Please try again.');
        }
    }
    header('Location: courses.php');
    exit;
}

$filter = $_GET['filter'] ?? 'enrolled';
if (!in_array($filter, ['enrolled', 'all', 'available'], true)) {
    $filter = 'enrolled';
}

$pdo = db();

// Fetch student details for default filters
$uStmt = $pdo->prepare('SELECT class, board FROM users WHERE id = :id');
$uStmt->execute([':id' => $uid]);
$uInfo = $uStmt->fetch();
$studentClass = $uInfo['class'] ?? null;
$studentBoard = $uInfo['board'] ?? null;

$filterClass = isset($_GET['class']) ? ($_GET['class'] === 'all' ? 'all' : (int)$_GET['class']) : 'default';
$filterBoard = isset($_GET['board']) ? ($_GET['board'] === 'all' ? 'all' : trim((string)$_GET['board'])) : 'default';

$selectedClass = ($filterClass === 'default') ? $studentClass : (($filterClass === 'all') ? null : $filterClass);
$selectedBoard = ($filterBoard === 'default') ? $studentBoard : (($filterBoard === 'all') ? null : $filterBoard);

$whereClause = '';
$params = [':u' => $uid];

if ($selectedClass !== null) {
    $whereClause .= ' AND c.class = :class';
    $params[':class'] = $selectedClass;
}
if ($selectedBoard !== null && $selectedBoard !== '') {
    $whereClause .= ' AND c.board = :board';
    $params[':board'] = $selectedBoard;
}

if ($filter === 'enrolled') {
    $stmt = $pdo->prepare(
        'SELECT c.*, e.progress, e.last_active, t.full_name AS teacher_name, 1 AS is_enrolled
           FROM enrollments e
           JOIN courses c ON c.id = e.course_id
           JOIN users t    ON t.id = c.teacher_id
          WHERE e.student_id = :u AND c.is_published = 1' . $whereClause . '
          ORDER BY e.last_active DESC, e.enrolled_at DESC'
    );
    $stmt->execute($params);
} elseif ($filter === 'available') {
    $stmt = $pdo->prepare(
        'SELECT c.*, 0 AS progress, NULL AS last_active, t.full_name AS teacher_name, 0 AS is_enrolled
           FROM courses c
           JOIN users t ON t.id = c.teacher_id
          WHERE c.is_published = 1
            AND c.id NOT IN (SELECT course_id FROM enrollments WHERE student_id = :u)' . $whereClause . '
          ORDER BY c.created_at DESC'
    );
    $stmt->execute($params);
} else { // all
    $stmt = $pdo->prepare(
        'SELECT c.*, COALESCE(e.progress, 0) AS progress, e.last_active, t.full_name AS teacher_name, (e.course_id IS NOT NULL) AS is_enrolled
           FROM courses c
           JOIN users t ON t.id = c.teacher_id
           LEFT JOIN enrollments e ON e.course_id = c.id AND e.student_id = :u
          WHERE c.is_published = 1' . $whereClause . '
          ORDER BY c.created_at DESC'
    );
    $stmt->execute($params);
}
$courses = $stmt->fetchAll();

render_dashboard_header(
    'My Courses',
    student_nav(),
    'courses',
    ['Home', 'My Courses']
);
?>

<section class="panel">
    <form method="GET" action="courses.php" style="display:flex; gap:8px; align-items:center; margin-bottom:18px; flex-wrap: wrap;">
        <strong style="font-size:14px;">Filter:</strong>
        <select name="filter" class="form-control" style="width:auto; height:38px;" data-auto-submit>
            <option value="enrolled"  <?php echo $filter === 'enrolled'  ? 'selected' : ''; ?>>My Enrolled Courses</option>
            <option value="available" <?php echo $filter === 'available' ? 'selected' : ''; ?>>Available to Join</option>
            <option value="all"       <?php echo $filter === 'all'       ? 'selected' : ''; ?>>All Courses</option>
        </select>
        
        <select name="class" class="form-control" style="width:auto; height:38px;" data-auto-submit>
            <option value="all" <?php echo $filterClass === 'all' ? 'selected' : ''; ?>>All Classes</option>
            <?php for ($c = 5; $c <= 10; $c++): ?>
                <option value="<?php echo $c; ?>" <?php echo (($filterClass !== 'all' && $filterClass !== 'default' && (int)$filterClass === $c) || ($filterClass === 'default' && $studentClass === $c)) ? 'selected' : ''; ?>>Class <?php echo $c; ?></option>
            <?php endfor; ?>
        </select>

        <select name="board" class="form-control" style="width:auto; height:38px;" data-auto-submit>
            <option value="all" <?php echo $filterBoard === 'all' ? 'selected' : ''; ?>>All Boards</option>
            <?php foreach (get_all_boards() as $b): ?>
                <option value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>" <?php echo (($filterBoard !== 'all' && $filterBoard !== 'default' && $filterBoard === $b) || ($filterBoard === 'default' && $studentBoard === $b)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
        </select>

        <span style="color:var(--text-light); font-size:13px; margin-left:auto;"><?php echo count($courses); ?> course<?php echo count($courses) === 1 ? '' : 's'; ?></span>
    </form>

    <?php if (empty($courses)): ?>
        <div class="empty">
            <div class="ico"><ion-icon name="book-outline"></ion-icon></div>
            <h3>Nothing here yet</h3>
            <p>Try switching the filter to "Available to Join".</p>
        </div>
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
                            <span><ion-icon name="help-circle-outline"></ion-icon> <?php echo htmlspecialchars($c['teacher_name'], ENT_QUOTES); ?></span>
                            <span><ion-icon name="cellular-outline"></ion-icon> <?php echo htmlspecialchars(ucfirst($c['level']), ENT_QUOTES); ?></span>
                            <?php if ($c['class']): ?>
                                <span><ion-icon name="school-outline"></ion-icon> Class <?php echo (int)$c['class']; ?></span>
                            <?php endif; ?>
                            <?php if ($c['board']): ?>
                                <span><ion-icon name="business-outline"></ion-icon> <?php echo htmlspecialchars($c['board'], ENT_QUOTES); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ((int)$c['progress'] > 0 || $filter !== 'available'): ?>
                            <div class="progress"><span style="width: <?php echo (int)$c['progress']; ?>%"></span></div>
                            <div class="meta"><span><?php echo (int)$c['progress']; ?>% complete</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="footer">
                        <?php if (!empty($c['is_enrolled'])): ?>
                            <a class="btn btn-primary btn-sm" href="course.php?id=<?php echo (int)$c['id']; ?>">Open</a>
                            <form method="POST" action="courses.php" data-confirm="Unenroll from this course?" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="unenroll">
                                <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">Unenroll</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="courses.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="enroll">
                                <input type="hidden" name="course_id" value="<?php echo (int)$c['id']; ?>">
                                <button class="btn btn-primary btn-sm" type="submit">Enroll</button>
                            </form>
                            <a class="btn btn-ghost btn-sm" href="course.php?id=<?php echo (int)$c['id']; ?>">Preview</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php render_dashboard_footer(); ?>
