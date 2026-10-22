<?php
/**
 * EduMate - Teacher: Live Classes
 * Schedule + start + end live sessions. Students join via /student/live.php.
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
        header('Location: live.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'schedule') {
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $cid   = (int)($_POST['course_id'] ?? 0);
        $when  = trim((string)($_POST['scheduled_for'] ?? ''));
        $stream= trim((string)($_POST['stream_url'] ?? ''));
        if ($title === '') flash_set('error', 'Title is required.');
        elseif ($cid <= 0) flash_set('error', 'Course selection is required.');
        else {
            $joinCode = generate_join_code();
            $scheduledFor = $when !== '' ? date('Y-m-d H:i:s', strtotime($when)) : null;
            $pdo->prepare(
                'INSERT INTO live_sessions (host_id, course_id, title, description, status, join_code, stream_url, scheduled_for)
                 VALUES (:h, :c, :t, :d, "scheduled", :code, :s, :when)'
            )->execute([
                ':h' => $uid, ':c' => $cid, ':t' => $title, ':d' => $desc,
                ':code' => $joinCode,
                ':s' => $stream ?: null,
                ':when' => $scheduledFor,
            ]);
            $sessionId = (int)$pdo->lastInsertId();
            
            // Send notice to course
            $noticeContent = "A new live class has been scheduled!\n\n" .
                "Title: {$title}\n" .
                ($desc ? "Description: {$desc}\n" : '') .
                ($when ? "Scheduled for: " . date('M j, Y g:i A', strtotime($when)) . "\n" : '') .
                "Join code: {$joinCode}\n\n" .
                "Don't miss it!";
            $pdo->prepare(
                'INSERT INTO notices (course_id, author_id, title, content, priority) VALUES (:c, :a, :t, :co, "high")'
            )->execute([
                ':c' => $cid, 
                ':a' => $uid, 
                ':t' => 'Live Class Scheduled: ' . $title, 
                ':co' => $noticeContent
            ]);
            
            record_activity($uid, 'fa-tower-broadcast', 'Scheduled a live class: ' . $title, null);
            flash_set('success', 'Live class scheduled. Notice sent to all students in the course.');
        }
    } elseif ($action === 'go_live') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $own = $pdo->prepare('SELECT host_id, course_id, title, stream_url FROM live_sessions WHERE id = :id');
        $own->execute([':id' => $sid]);
        $row = $own->fetch(PDO::FETCH_ASSOC);
        $h = $row['host_id'] ?? null;
        if ($h === false) flash_set('error', 'Session not found.');
        elseif (!is_admin_user() && (int)$h !== $uid) flash_set('error', 'You can only go live on your own sessions.');
        else {
            if (empty($row['stream_url'])) {
                $meet = generate_meet($row['course_id'] ?? 0, $row['title']);
                if ($meet) {
                    $pdo->prepare('UPDATE live_sessions SET stream_url = ?, status="live", started_at=NOW() WHERE id = :id')
                        ->execute([$meet, $sid]);
                    $meet_url = $meet;
                } else {
                    flash_set('error', 'Failed to create Meet link.');
                    header('Location: live.php'); exit;
                }
            } else {
                $meet_url = $row['stream_url'];
                $pdo->prepare('UPDATE live_sessions SET status="live", started_at=NOW() WHERE id = :id')->execute([':id' => $sid]);
            }
            flash_set('success', 'You are LIVE!');
            header('Location: ' . $meet_url);
            exit;
        }
    } elseif ($action === 'end_live') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $own = $pdo->prepare('SELECT host_id FROM live_sessions WHERE id = :id');
        $own->execute([':id' => $sid]);
        $h = $own->fetchColumn();
        if ($h === false) flash_set('error', 'Session not found.');
        elseif (!is_admin_user() && (int)$h !== $uid) flash_set('error', 'You can only end your own sessions.');
        else {
            $pdo->prepare('UPDATE live_sessions SET status="ended", ended_at=NOW() WHERE id = :id')->execute([':id' => $sid]);
            flash_set('success', 'Live class ended. Recording saved to your videos.');
            header('Location: live.php?ended_session=' . $sid); exit;
        }
    } elseif ($action === 'cancel') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $own = $pdo->prepare('SELECT host_id FROM live_sessions WHERE id = :id');
        $own->execute([':id' => $sid]);
        $h = $own->fetchColumn();
        if ($h === false) flash_set('error', 'Session not found.');
        elseif (!is_admin_user() && (int)$h !== $uid) flash_set('error', 'You can only cancel your own sessions.');
        else {
            $pdo->prepare('UPDATE live_sessions SET status="cancelled" WHERE id = :id')->execute([':id' => $sid]);
            flash_set('success', 'Session cancelled.');
        }
    }
    header('Location: live.php'); exit;
}

$sessions = $pdo->prepare(
    'SELECT ls.*, c.title AS course_title,
            (SELECT COUNT(*) FROM live_attendance la WHERE la.session_id = ls.id) AS viewer_count
       FROM live_sessions ls
       LEFT JOIN courses c ON c.id = ls.course_id
      WHERE ls.host_id = :u
      ORDER BY (ls.status = "live") DESC, ls.scheduled_for DESC, ls.created_at DESC
      LIMIT 100'
);
$sessions->execute([':u' => $uid]);
$sessions = $sessions->fetchAll();

$myCourses = $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id = :u OR creator_id = :u2 ORDER BY title');
$myCourses->execute([':u' => $uid, ':u2' => $uid]);
$myCourses = $myCourses->fetchAll();

render_dashboard_header('Live Classes', teacher_nav(), 'live', ['Home', 'Live']);
?>
<section class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">Your live classes</h2>
        <button class="btn btn-primary" data-modal-open="#modal-new-live"><ion-icon name="add-outline"></ion-icon> Schedule live class</button>
    </div>
    <?php if (empty($sessions)): ?>
        <div class="empty"><div class="ico"><ion-icon name="radio-outline"></ion-icon></div><h3>No live classes yet</h3><p>Schedule a class so students can join in real-time.</p></div>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Title</th><th>Course</th><th>Status</th><th>Scheduled</th><th>Join code</th><th>Viewers</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?></strong>
                            <?php if (!empty($s['description'])): ?>
                                <div style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($s['description'], ENT_QUOTES); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $s['course_title'] ? htmlspecialchars($s['course_title'], ENT_QUOTES) : '<span style="color:var(--text-light)">—</span>'; ?></td>
                        <td>
                            <?php if ($s['status'] === 'live'): ?>
                                <span class="tag live">LIVE</span>
                            <?php elseif ($s['status'] === 'scheduled'): ?>
                                <span class="tag info">Scheduled</span>
                            <?php elseif ($s['status'] === 'ended'): ?>
                                <span class="tag success">Ended</span>
                            <?php else: ?>
                                <span class="tag warn">Cancelled</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $s['scheduled_for'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($s['scheduled_for'])), ENT_QUOTES) : '<span style="color:var(--text-light)">Anytime</span>'; ?></td>
                        <td><code style="font-size:13px; background:var(--bg-secondary); padding:2px 6px; border-radius:6px;"><?php echo htmlspecialchars($s['join_code'], ENT_QUOTES); ?></code></td>
                        <td><?php echo (int)$s['viewer_count']; ?></td>
                        <td style="display: flex; gap: 8px; align-items: center; justify-content: flex-end;">
                            <?php if (!empty($s['stream_url'])): ?>
                                <a class="btn btn-success btn-sm" href="<?php echo htmlspecialchars($s['stream_url'], ENT_QUOTES); ?>" target="_blank">Open Meet</a>
                            <?php endif; ?>

                            <?php if ($s['status'] === 'scheduled'): ?>
                                <form method="POST" action="live.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="go_live">
                                    <input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                    <button class="btn btn-primary btn-sm" type="submit"><ion-icon name="radio-outline"></ion-icon> Go Live</button>
                                </form>
                            <?php elseif ($s['status'] === 'live'): ?>
                                <form method="POST" action="live.php" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="end_live">
                                    <input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">End</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($s['status'], ['scheduled','live'], true)): ?>
                                <form method="POST" action="live.php" data-confirm="Cancel this session?" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="close-outline"></ion-icon></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($_GET['ended_session'])): ?>
            <?php $endedId = (int)$_GET['ended_session']; ?>
            <form method="POST" action="upload_recording.php" enctype="multipart/form-data" style="margin-top:20px; padding:20px; background:#f9f9f9; border-radius:8px;">
                <?php echo csrf_field(); ?>
                <h4>Upload Recording for Session #<?php echo $endedId; ?></h4>
                <input type="hidden" name="session_id" value="<?php echo $endedId; ?>" />
                <div class="form-group">
                    <input type="file" name="recording" accept="video/mp4" required />
                </div>
                <button class="btn btn-primary" type="submit">Upload Recording</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<div class="modal-backdrop" id="modal-new-live">
    <div class="modal">
        <header>
            <h2>Schedule a live class</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="live.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="schedule">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Title</label>
                        <input class="form-control" name="title" required>
                    </div>
                    <div class="form-group">
                        <label>Course</label>
                        <select class="form-control" name="course_id">
                            <option value="">Select a course</option>
                            <?php foreach ($myCourses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Scheduled time (optional)</label>
                        <input class="form-control" type="datetime-local" name="scheduled_for">
                    </div>
                    <div class="form-group span-2">
                        <label>Stream URL (YouTube Live, Vimeo, Jitsi, Zoom...) <span style="color:var(--text-light); font-weight:400;">— optional</span></label>
                        <input class="form-control" type="url" name="stream_url" placeholder="https://...">
                    </div>
                    <div class="form-group span-2">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="calendar-outline"></ion-icon> Schedule</button>
            </div>
        </form>
    </div>
</div>
<?php render_dashboard_footer(); ?>

<?php
function generate_meet($courseId, $title) {
    // Use Jitsi Meet for instant, free, no-authentication live classes!
    $meetingId = 'edumate-live-' . uniqid() . '-' . bin2hex(random_bytes(4));
    return 'https://meet.jit.si/' . $meetingId;
}
?>

