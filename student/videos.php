<?php
/**
 * EduMate - Student: Video library (with embeddable player)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$pdo = db();

$subject = $_GET['subject'] ?? '';
$open    = (int)($_GET['v'] ?? 0);

$uStmt = $pdo->prepare('SELECT class, board FROM users WHERE id = :id');
$uStmt->execute([':id' => $user['id']]);
$uInfo = $uStmt->fetch();
$class = $uInfo['class'] ?? null;
$board = $uInfo['board'] ?? null;

// List videos from teachers of courses the student is enrolled in
$sql = 'SELECT v.*, c.title AS course_title
          FROM videos v 
          LEFT JOIN courses c ON c.id = v.course_id
         WHERE v.teacher_id IN (
             SELECT DISTINCT c2.teacher_id 
             FROM courses c2 
             JOIN enrollments e ON c2.id = e.course_id 
             WHERE e.student_id = :uid
         )';

$params = [':uid' => $user['id']];

if ($subject !== '' && $subject !== 'all') {
    $sql .= ' AND v.subject = :s';
    $params[':s'] = $subject;
}
$sql .= ' ORDER BY v.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$videos = $stmt->fetchAll();

// Distinct subjects for the filter based on the new logic
$subjStmt = $pdo->prepare(
    'SELECT DISTINCT v.subject FROM videos v 
      WHERE v.teacher_id IN (
          SELECT DISTINCT c2.teacher_id 
          FROM courses c2 
          JOIN enrollments e ON c2.id = e.course_id 
          WHERE e.student_id = :uid
      ) ORDER BY v.subject'
);
$subjStmt->execute([':uid' => $user['id']]);
$subjects = $subjStmt->fetchAll(PDO::FETCH_COLUMN);

// Currently opened video
$current = null;
if ($open > 0) {
    foreach ($videos as $v) {
        if ((int)$v['id'] === $open) { $current = $v; break; }
    }
    if (!$current) {
        $cs = $pdo->prepare('SELECT v.*, c.title AS course_title FROM videos v LEFT JOIN courses c ON c.id = v.course_id WHERE v.id = :id');
        $cs->execute([':id' => $open]);
        $current = $cs->fetch();
    }
    if ($current) {
        record_activity($user['id'], 'fa-video', 'Watched a video: ' . $current['title'], null);
    }
}

render_dashboard_header(
    'Videos',
    student_nav(),
    'videos',
    ['Home', 'Videos']
);
?>
<section class="panel">
    <form method="GET" action="videos.php" style="display:flex; gap:8px; align-items:center; margin-bottom:14px;">
        <strong style="font-size:14px;">Subject:</strong>
        <select name="subject" class="form-control" style="width:auto; height:38px;" data-auto-submit>
            <option value="all">All subjects</option>
            <?php foreach ($subjects as $s): ?>
                <option value="<?php echo htmlspecialchars($s, ENT_QUOTES); ?>" <?php echo $subject === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
        </select>
        <span style="margin-left:auto; color:var(--text-light); font-size:13px;"><?php echo count($videos); ?> video<?php echo count($videos) === 1 ? '' : 's'; ?></span>
    </form>

    <?php if ($current): ?>
        <div style="margin-bottom:18px;">
            <h2 style="font-size:20px; margin-bottom:4px;"><?php echo htmlspecialchars($current['title'], ENT_QUOTES); ?></h2>
            <p style="color:var(--text-secondary); font-size:13px;">
                <span class="tag"><?php echo htmlspecialchars($current['subject'], ENT_QUOTES); ?></span>
                <ion-icon name="time-outline"></ion-icon> <?php echo (int)$current['duration_min']; ?> min
                <?php if (!empty($current['course_title'])): ?>
                    · <ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($current['course_title'], ENT_QUOTES); ?>
                <?php endif; ?>
            </p>
            <div style="position:relative; padding-top:56.25%; border-radius:14px; overflow:hidden; margin-top:12px; box-shadow: var(--shadow-md);">
                <iframe src="<?php echo htmlspecialchars($current['embed_url'], ENT_QUOTES); ?>"
                        style="position:absolute; inset:0; width:100%; height:100%; border:0;"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen></iframe>
            </div>
            <p style="margin-top:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($current['description'], ENT_QUOTES); ?></p>
            <a class="btn btn-ghost btn-sm" href="videos.php<?php echo $subject ? '?subject=' . urlencode($subject) : ''; ?>" style="margin-top:8px;">
                <ion-icon name="arrow-back-outline"></ion-icon> Back to library
            </a>
        </div>
    <?php endif; ?>

    <?php if (empty($videos)): ?>
        <div class="empty"><div class="ico"><ion-icon name="videocam-outline"></ion-icon></div><h3>No videos</h3><p>The library is empty for this subject.</p></div>
    <?php else: ?>
        <div class="grid-cards">
            <?php foreach ($videos as $v): ?>
                <?php if ($current && (int)$current['id'] === (int)$v['id']) continue; ?>
                <article class="tile">
                    <div class="cover" style="background:linear-gradient(135deg, #4facfe, #00f2fe);">
                        <ion-icon name="help-circle-outline"></ion-icon>
                        <span class="pill"><?php echo (int)$v['duration_min']; ?> min</span>
                    </div>
                    <div class="body">
                        <h3><?php echo htmlspecialchars($v['title'], ENT_QUOTES); ?></h3>
                        <p><?php echo htmlspecialchars($v['description'], ENT_QUOTES); ?></p>
                        <div class="meta">
                            <span><ion-icon name="help-circle-outline"></ion-icon> <?php echo htmlspecialchars($v['subject'], ENT_QUOTES); ?></span>
                            <?php if (!empty($v['course_title'])): ?>
                                <span><ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($v['course_title'], ENT_QUOTES); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="footer">
                        <a class="btn btn-primary btn-sm" href="videos.php?v=<?php echo (int)$v['id']; ?>&subject=<?php echo urlencode($subject ?: 'all'); ?>">
                            <ion-icon name="play-circle-outline"></ion-icon> Watch
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_dashboard_footer(); ?>
