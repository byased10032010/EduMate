<?php
/**
 * EduMate - Student: Live Classes (join live / browse past)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Join by code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (csrf_check(is_string($token) ? $token : null)) {
        if ($action === 'join_by_code') {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $stmt = $pdo->prepare('SELECT ls.* FROM live_sessions ls 
                                    JOIN courses c ON c.id = ls.course_id 
                                    JOIN enrollments e ON e.course_id = c.id 
                                    WHERE ls.join_code = :c AND e.student_id = :uid LIMIT 1');
            $stmt->execute([':c' => $code, ':uid' => $uid]);
            $s = $stmt->fetch();
            if (!$s) flash_set('error', 'No session with that code or you are not enrolled in the course.');
            else { header('Location: live.php?session=' . (int)$s['id']); exit; }
        } elseif ($action === 'join') {
            $sid = (int)($_POST['session_id'] ?? 0);
            // Check enrollment first
            $checkStmt = $pdo->prepare('SELECT ls.id FROM live_sessions ls 
                                        JOIN courses c ON c.id = ls.course_id 
                                        JOIN enrollments e ON e.course_id = c.id 
                                        WHERE ls.id = :sid AND e.student_id = :uid');
            $checkStmt->execute([':sid' => $sid, ':uid' => $uid]);
            if (!$checkStmt->fetch()) {
                flash_set('error', 'You are not enrolled in this course.');
                header('Location: live.php'); exit;
            }
            $pdo->prepare('INSERT IGNORE INTO live_attendance (session_id, user_id) VALUES (:s, :u)')
                ->execute([':s' => $sid, ':u' => $uid]);
            header('Location: live.php?session=' . $sid); exit;
        } elseif ($action === 'leave') {
            $sid = (int)($_POST['session_id'] ?? 0);
            $pdo->prepare('UPDATE live_attendance SET left_at = NOW() WHERE session_id = :s AND user_id = :u')
                ->execute([':s' => $sid, ':u' => $uid]);
            flash_set('success', 'Left the session.');
            header('Location: live.php'); exit;
        }
    } else {
        flash_set('error', 'Session expired.');
    }
    header('Location: live.php'); exit;
}

$sessionId = (int)($_GET['session'] ?? 0);

if ($sessionId > 0) {
    $stmt = $pdo->prepare(
        'SELECT ls.*, u.full_name AS host_name, c.title AS course_title
           FROM live_sessions ls
           JOIN users u ON u.id = ls.host_id
           JOIN courses c ON c.id = ls.course_id
           JOIN enrollments e ON e.course_id = c.id
          WHERE ls.id = :id AND e.student_id = :uid'
    );
    $stmt->execute([':id' => $sessionId, ':uid' => $uid]);
    $session = $stmt->fetch();

    if (!$session) { flash_set('error', 'Session not found or you are not enrolled in the course.'); header('Location: live.php'); exit; }

    // Auto-mark attendance
    $pdo->prepare('INSERT IGNORE INTO live_attendance (session_id, user_id) VALUES (:s, :u)')
        ->execute([':s' => $sessionId, ':u' => $uid]);

    render_dashboard_header($session['title'] . ' · Live', student_nav(), 'live', ['Home', 'Live', $session['title']]);
    ?>
    <?php if ($session['status'] === 'live'): ?>
        <div class="live-banner">
            <span class="dot"></span>
            LIVE now — hosted by <?php echo htmlspecialchars($session['host_name'], ENT_QUOTES); ?>
            <a href="live.php">Back to list</a>
        </div>
    <?php endif; ?>

    <div class="live-room">
        <?php if ($session['status'] === 'live' && !empty($session['stream_url'])): ?>
            <span class="live-status"><span class="dot"></span> LIVE</span>
            <div class="placeholder" style="text-align:center; padding:40px;">
                <ion-icon name="videocam-outline" style="font-size:48px; color:var(--primary); margin-bottom:16px;"></ion-icon>
                <h3>Live Class is Active!</h3>
                <p style="margin-bottom:20px;">Click the button below to join the live session</p>
                <a href="<?php echo htmlspecialchars($session['stream_url'], ENT_QUOTES); ?>" target="_blank" class="btn btn-primary" style="font-size:16px; padding:12px 32px;">
                    <ion-icon name="open-outline"></ion-icon> Join Live Session
                </a>
            </div>
        <?php elseif ($session['status'] === 'live'): ?>
            <span class="live-status"><span class="dot"></span> LIVE</span>
            <div class="placeholder">
                <ion-icon name="radio-outline"></ion-icon>
                <h3>The host hasn't attached a stream yet</h3>
                <p>Hang tight! The teacher will connect shortly. Join code: <code style="background:rgba(255,255,255,0.1); padding:2px 6px; border-radius:4px;"><?php echo htmlspecialchars($session['join_code'], ENT_QUOTES); ?></code></p>
            </div>
        <?php elseif ($session['status'] === 'scheduled'): ?>
            <div class="placeholder">
                <ion-icon name="time-outline"></ion-icon>
                <h3>This class hasn't started yet</h3>
                <p>Scheduled for <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($session['scheduled_for'])), ENT_QUOTES); ?></p>
            </div>
        <?php else: ?>
            <div class="placeholder">
                <ion-icon name="videocam-off-outline"></ion-icon>
                <h3>This class has ended</h3>
                <p>Host: <?php echo htmlspecialchars($session['host_name'], ENT_QUOTES); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <section class="panel">
        <h2><?php echo htmlspecialchars($session['title'], ENT_QUOTES); ?></h2>
        <p style="color:var(--text-secondary);"><?php echo nl2br(htmlspecialchars($session['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
        <div style="margin-top:8px; font-size:12px; color:var(--text-light);">
            <?php if (!empty($session['course_title'])): ?>
                <ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($session['course_title'], ENT_QUOTES); ?> &middot;
            <?php endif; ?>
            <ion-icon name="key-outline"></ion-icon> Code: <code style="background:var(--bg-secondary); padding:2px 6px; border-radius:4px;"><?php echo htmlspecialchars($session['join_code'], ENT_QUOTES); ?></code>
        </div>
        <p style="margin-top:14px;">
            <a class="btn btn-ghost" href="live.php"><ion-icon name="arrow-back-outline"></ion-icon> Back to live classes</a>
        </p>
    </section>
    <?php
    render_dashboard_footer();
    return;
}

// Browse list
$liveNow = $pdo->prepare(
    'SELECT ls.*, u.full_name AS host_name, c.title AS course_title
       FROM live_sessions ls
       JOIN users u ON u.id = ls.host_id
       JOIN courses c ON c.id = ls.course_id
       JOIN enrollments e ON e.course_id = c.id
      WHERE ls.status = "live" AND e.student_id = :uid
      ORDER BY ls.started_at DESC LIMIT 20'
);
$liveNow->execute([':uid' => $uid]);
$liveNow = $liveNow->fetchAll();

$upcoming = $pdo->prepare(
    'SELECT ls.*, u.full_name AS host_name, c.title AS course_title
       FROM live_sessions ls
       JOIN users u ON u.id = ls.host_id
       JOIN courses c ON c.id = ls.course_id
       JOIN enrollments e ON e.course_id = c.id
      WHERE ls.status = "scheduled" AND e.student_id = :uid
      ORDER BY ls.scheduled_for ASC LIMIT 20'
);
$upcoming->execute([':uid' => $uid]);
$upcoming = $upcoming->fetchAll();

$past = $pdo->prepare(
    'SELECT ls.*, u.full_name AS host_name, c.title AS course_title
       FROM live_sessions ls
       JOIN users u ON u.id = ls.host_id
       JOIN courses c ON c.id = ls.course_id
       JOIN enrollments e ON e.course_id = c.id
      WHERE ls.status IN ("ended","cancelled") AND e.student_id = :uid
      ORDER BY COALESCE(ls.ended_at, ls.scheduled_for) DESC LIMIT 20'
);
$past->execute([':uid' => $uid]);
$past = $past->fetchAll();

render_dashboard_header('Live Classes', student_nav(), 'live', ['Home', 'Live']);
?>
<section class="panel">
    <form method="POST" action="live.php" style="display:flex; gap:8px; align-items:center; margin-bottom:14px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="join_by_code">
        <strong style="font-size:14px;">Join code:</strong>
        <input class="form-control" type="text" name="code" maxlength="12" placeholder="ABC123" style="width:180px; text-transform:uppercase;">
        <button class="btn btn-primary" type="submit"><ion-icon name="log-in-outline"></ion-icon> Join</button>
    </form>

    <h2 style="margin-top:20px;">Happening now</h2>
    <?php if (empty($liveNow)): ?>
        <div class="empty"><div class="ico"><ion-icon name="radio-outline"></ion-icon></div><h3>No live classes right now</h3><p>Check back later or use a join code above.</p></div>
    <?php else: ?>
        <?php foreach ($liveNow as $s): ?>
            <div class="live-banner">
                <span class="dot"></span>
                <strong><?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?></strong>
                &nbsp;by <?php echo htmlspecialchars($s['host_name'], ENT_QUOTES); ?>
                &nbsp;<span class="batch-chip"><?php echo htmlspecialchars($s['join_code'], ENT_QUOTES); ?></span>
                <a href="live.php?session=<?php echo (int)$s['id']; ?>">Join now &rarr;</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 style="margin-top:20px;">Upcoming</h2>
    <?php if (empty($upcoming)): ?>
        <p style="color:var(--text-light); font-size:13px;">No upcoming classes.</p>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Title</th><th>Host</th><th>When</th><th>Code</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($upcoming as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?></strong></td>
                        <td><?php echo htmlspecialchars($s['host_name'], ENT_QUOTES); ?></td>
                        <td><?php echo $s['scheduled_for'] ? htmlspecialchars(date('M j, g:i A', strtotime($s['scheduled_for'])), ENT_QUOTES) : '—'; ?></td>
                        <td><code style="background:var(--bg-secondary); padding:2px 6px; border-radius:4px;"><?php echo htmlspecialchars($s['join_code'], ENT_QUOTES); ?></code></td>
                        <td><a class="btn btn-ghost btn-sm" href="live.php?session=<?php echo (int)$s['id']; ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 style="margin-top:20px;">Recent recordings / past</h2>
    <?php if (empty($past)): ?>
        <p style="color:var(--text-light); font-size:13px;">No past sessions.</p>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Title</th><th>Host</th><th>Ended</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($past as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?></strong></td>
                        <td><?php echo htmlspecialchars($s['host_name'], ENT_QUOTES); ?></td>
                        <td><?php echo $s['ended_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($s['ended_at'])), ENT_QUOTES) : '—'; ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="live.php?session=<?php echo (int)$s['id']; ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_dashboard_footer(); ?>


