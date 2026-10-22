<?php
/**
 * EduMate - Teacher: Notices (course announcements)
 *
 * Each notice has a priority (high/medium/normal) and a target.
 * Only the author (or admin) can delete; any teacher in the course
 * can post. Students see them and can acknowledge.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/permissions.php';
require_role('teacher');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: notices.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $cid     = (int)($_POST['course_id'] ?? 0);
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $prio    = $_POST['priority'] ?? 'normal';
        if (!in_array($prio, ['high','medium','normal'], true)) $prio = 'normal';
        if (!can_manage_course($cid)) flash_set('error', 'You do not manage that course.');
        elseif ($title === '' || $content === '') flash_set('error', 'Title and content are required.');
        else {
            $pdo->prepare(
                'INSERT INTO notices (course_id, author_id, title, content, priority) VALUES (:c, :a, :t, :co, :p)'
            )->execute([':c' => $cid, ':a' => $uid, ':t' => $title, ':co' => $content, ':p' => $prio]);
            record_activity($uid, 'fa-bullhorn', 'Posted notice: ' . $title, null);
            flash_set('success', 'Notice posted.');
        }
    } elseif ($action === 'delete') {
        $nid = (int)($_POST['notice_id'] ?? 0);
        $r   = $pdo->prepare('SELECT n.id, n.course_id, n.author_id, c.teacher_id, c.creator_id FROM notices n JOIN courses c ON c.id = n.course_id WHERE n.id = :id');
        $r->execute([':id' => $nid]);
        $row = $r->fetch();
        if (!$row) flash_set('error', 'Notice not found.');
        elseif (!is_admin_user() && (int)$row['author_id'] !== $uid) {
            flash_set('error', 'You can only delete notices you posted.');
        } else {
            $pdo->prepare('DELETE FROM notices WHERE id = :id')->execute([':id' => $nid]);
            flash_set('success', 'Notice deleted.');
        }
    }
    header('Location: notices.php'); exit;
}

$list = $pdo->prepare(
    'SELECT n.*, c.title AS course_title, u.full_name AS author_name,
            (SELECT COUNT(*) FROM notice_replies nr WHERE nr.notice_id = n.id) AS reply_count
       FROM notices n
       JOIN courses c ON c.id = n.course_id
       JOIN users u ON u.id = n.author_id
      WHERE c.teacher_id = :u OR c.creator_id = :u2
      ORDER BY n.is_pinned DESC, n.created_at DESC
      LIMIT 100'
);
$list->execute([':u' => $uid, ':u2' => $uid]);
$notices = $list->fetchAll();

$myCourses = $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id = :u OR creator_id = :u2 ORDER BY title');
$myCourses->execute([':u' => $uid, ':u2' => $uid]);
$myCourses = $myCourses->fetchAll();

render_dashboard_header('Notices', teacher_nav(), 'notices', ['Home', 'Notices']);
?>
<section class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">Course notices</h2>
        <?php if (!empty($myCourses)): ?>
            <button class="btn btn-primary" data-modal-open="#modal-new-notice"><ion-icon name="add-outline"></ion-icon> Post notice</button>
        <?php endif; ?>
    </div>

    <?php if (empty($notices)): ?>
        <div class="empty"><div class="ico"><ion-icon name="megaphone-outline"></ion-icon></div><h3>No notices yet</h3><p>Post an announcement to your students.</p></div>
    <?php else: ?>
        <?php foreach ($notices as $n):
            $canDelN = is_admin_user() || ((int)$n['author_id'] === $uid);
        ?>
            <div class="notice <?php echo htmlspecialchars($n['priority'] ?? 'normal', ENT_QUOTES); ?>">
                <div class="head">
                    <h3><?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?></h3>
                    <span class="priority-badge priority-<?php echo htmlspecialchars($n['priority'] ?? 'normal', ENT_QUOTES); ?>"><?php echo htmlspecialchars(strtoupper($n['priority'] ?? 'normal'), ENT_QUOTES); ?></span>
                </div>
                <div class="body"><?php echo nl2br(htmlspecialchars($n['content'], ENT_QUOTES, 'UTF-8')); ?></div>
                <div class="meta">
                    <ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($n['course_title'], ENT_QUOTES); ?>
                    &middot; <ion-icon name="person-outline"></ion-icon> <?php echo render_owner_label($n); ?>
                    &middot; <ion-icon name="time-outline"></ion-icon> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at'])), ENT_QUOTES); ?>
                    &middot; <ion-icon name="chatbubbles-outline"></ion-icon> <?php echo (int)$n['reply_count']; ?> replies
                    <?php if ($canDelN): ?>
                        <form method="POST" action="notices.php" data-confirm="Delete this notice?" style="display:inline; margin-left:auto;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="notice_id" value="<?php echo (int)$n['id']; ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if (!empty($myCourses)): ?>
<div class="modal-backdrop" id="modal-new-notice">
    <div class="modal">
        <header>
            <h2>Post a notice</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="notices.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Course</label>
                        <select class="form-control" name="course_id" required>
                            <?php foreach ($myCourses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select class="form-control" name="priority">
                            <option value="normal">Normal</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label>Title</label>
                        <input class="form-control" name="title" required maxlength="180">
                    </div>
                    <div class="form-group span-2">
                        <label>Content</label>
                        <textarea class="form-control" name="content" required rows="5"></textarea>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="send-outline"></ion-icon> Post</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php render_dashboard_footer(); ?>
