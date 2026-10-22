<?php
/**
 * EduMate - Student: Notices (announcements from teachers)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (csrf_check(is_string($token) ? $token : null) && $action === 'reply') {
        $nid = (int)($_POST['notice_id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($nid > 0 && $body !== '') {
            $pdo->prepare('INSERT INTO notice_replies (notice_id, author_id, body) VALUES (:n, :a, :b)')
                ->execute([':n' => $nid, ':a' => $uid, ':b' => $body]);
            flash_set('success', 'Reply posted.');
        }
    } elseif (csrf_check(is_string($token) ? $token : null) && $action === 'acknowledge') {
        $nid = (int)($_POST['notice_id'] ?? 0);
        if ($nid > 0) {
            try {
                $pdo->prepare('INSERT INTO notice_acknowledgements (notice_id, user_id) VALUES (:n, :u)')
                    ->execute([':n' => $nid, ':u' => $uid]);
                flash_set('success', 'Acknowledged.');
            } catch (Throwable $e) {
                flash_set('info', 'Already acknowledged.');
            }
        }
    } else {
        flash_set('error', 'Session expired.');
    }
    header('Location: notices.php'); exit;
}

$list = $pdo->prepare(
    'SELECT n.*, c.title AS course_title, u.full_name AS author_name,
            (SELECT COUNT(*) FROM notice_replies nr WHERE nr.notice_id = n.id) AS reply_count,
            (SELECT 1 FROM notice_acknowledgements na WHERE na.notice_id = n.id AND na.user_id = :u) AS is_acked
       FROM notices n
       JOIN courses c ON c.id = n.course_id
       JOIN users u ON u.id = n.author_id
      WHERE c.id IN (SELECT course_id FROM enrollments WHERE student_id = :u2)
         OR n.course_id IS NULL
      ORDER BY n.is_pinned DESC, n.created_at DESC
      LIMIT 100'
);
$list->execute([':u' => $uid, ':u2' => $uid]);
$notices = $list->fetchAll();

render_dashboard_header('Notices', student_nav(), 'notices', ['Home', 'Notices']);
?>
<?php if (empty($notices)): ?>
    <section class="panel">
        <div class="empty"><div class="ico"><ion-icon name="megaphone-outline"></ion-icon></div><h3>No notices yet</h3><p>Teachers will post announcements here when they have news for you.</p></div>
    </section>
<?php else: ?>
    <?php foreach ($notices as $n): ?>
        <div class="notice <?php echo htmlspecialchars($n['priority'] ?? 'normal', ENT_QUOTES); ?>">
            <div class="head">
                <h3><?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?></h3>
                <span class="priority-badge priority-<?php echo htmlspecialchars($n['priority'] ?? 'normal', ENT_QUOTES); ?>"><?php echo htmlspecialchars(strtoupper($n['priority'] ?? 'normal'), ENT_QUOTES); ?></span>
            </div>
            <div class="body"><?php echo nl2br(htmlspecialchars($n['content'], ENT_QUOTES, 'UTF-8')); ?></div>
            <div class="meta">
                <ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($n['course_title'], ENT_QUOTES); ?>
                &middot; <ion-icon name="person-outline"></ion-icon> <?php echo htmlspecialchars($n['author_name'], ENT_QUOTES); ?>
                &middot; <ion-icon name="time-outline"></ion-icon> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at'])), ENT_QUOTES); ?>
                <?php if (!empty($n['is_acked'])): ?>
                    &middot; <span class="tag success">Acknowledged</span>
                <?php endif; ?>
            </div>
            <div style="margin-top:10px; display:flex; gap:6px; flex-wrap:wrap;">
                <?php if (empty($n['is_acked'])): ?>
                    <form method="POST" action="notices.php" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="acknowledge">
                        <input type="hidden" name="notice_id" value="<?php echo (int)$n['id']; ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><ion-icon name="checkmark-outline"></ion-icon> Acknowledge</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php
            $replies = $pdo->prepare('SELECT nr.*, u.full_name AS author_name FROM notice_replies nr JOIN users u ON u.id = nr.author_id WHERE nr.notice_id = :n ORDER BY nr.created_at ASC');
            $replies->execute([':n' => $n['id']]);
            $replies = $replies->fetchAll();
            ?>
            <?php if (!empty($replies)): ?>
                <div style="margin-top:12px; padding-top:10px; border-top:1px dashed var(--border-color);">
                    <?php foreach ($replies as $r): ?>
                        <div class="reply">
                            <div class="head">
                                <strong style="font-size:13px;"><?php echo htmlspecialchars($r['author_name'], ENT_QUOTES); ?></strong>
                                <span style="font-size:11px; color:var(--text-light); margin-left:6px;"><?php echo htmlspecialchars(date('M j, g:i A', strtotime($r['created_at'])), ENT_QUOTES); ?></span>
                            </div>
                            <div style="font-size:13px; color:var(--text-secondary);"><?php echo nl2br(htmlspecialchars($r['body'], ENT_QUOTES, 'UTF-8')); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <details style="margin-top:10px;">
                <summary style="cursor:pointer; color:#667eea; font-size:13px; font-weight:600;">+ Add a reply</summary>
                <form method="POST" action="notices.php" style="margin-top:8px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="notice_id" value="<?php echo (int)$n['id']; ?>">
                    <textarea class="form-control" name="body" rows="2" required placeholder="Your reply..."></textarea>
                    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:6px;"><ion-icon name="return-up-back-outline"></ion-icon> Post reply</button>
                </form>
            </details>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php render_dashboard_footer(); ?>
