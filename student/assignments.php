<?php
/**
 * EduMate - Student: Assignments
 * - List assignments from enrolled courses
 * - View assignment details and submit answers
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

$aid = (int)($_GET['id'] ?? 0);

// POST: Submit assignment response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    $token = $_POST['csrf_token'] ?? '';
    $assignId = (int)($_POST['assignment_id'] ?? 0);
    $body = trim((string)($_POST['body'] ?? ''));

    if (!csrf_check(is_string($token) ? $token : null) || $assignId <= 0) {
        flash_set('error', 'Invalid request.');
    } elseif ($body === '') {
        flash_set('error', 'Submission text cannot be empty.');
    } else {
        try {
            // Verify student is enrolled in the course for this assignment
            $check = $pdo->prepare(
                'SELECT 1 FROM assignments a
                   JOIN enrollments e ON e.course_id = a.course_id
                  WHERE a.id = :aid AND e.student_id = :uid'
            );
            $check->execute([':aid' => $assignId, ':uid' => $uid]);
            if (!$check->fetch()) {
                flash_set('error', 'You are not authorized for this assignment.');
            } else {
                $attachmentPath = null;
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../assets/uploads/assignments/';
                    $fileName = preg_replace('/[^A-Za-z0-9.\-_]/', '', basename($_FILES['attachment']['name']));
                    $newFileName = time() . '_' . $uid . '_' . $fileName;
                    $destPath = $uploadDir . $newFileName;
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $destPath)) {
                        $attachmentPath = 'assets/uploads/assignments/' . $newFileName;
                    }
                }

                // Upsert submission
                if ($attachmentPath) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO submissions (assignment_id, student_id, body, attachment_path, submitted_at, grade, feedback, graded_at)
                         VALUES (:aid, :uid, :body, :att, NOW(), NULL, NULL, NULL)
                         ON DUPLICATE KEY UPDATE body = VALUES(body), attachment_path = VALUES(attachment_path), submitted_at = NOW(), grade = NULL, feedback = NULL, graded_at = NULL'
                    );
                    $stmt->execute([':aid' => $assignId, ':uid' => $uid, ':body' => $body, ':att' => $attachmentPath]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO submissions (assignment_id, student_id, body, submitted_at, grade, feedback, graded_at)
                         VALUES (:aid, :uid, :body, NOW(), NULL, NULL, NULL)
                         ON DUPLICATE KEY UPDATE body = VALUES(body), submitted_at = NOW(), grade = NULL, feedback = NULL, graded_at = NULL'
                    );
                    $stmt->execute([':aid' => $assignId, ':uid' => $uid, ':body' => $body]);
                }

                // Update student learning streak
                update_streak($uid);
                record_activity($uid, 'fa-tasks', 'Submitted assignment response', mb_substr($body, 0, 80));
                flash_set('success', 'Assignment submitted successfully!');
                header('Location: assignments.php?id=' . $assignId);
                exit;
            }
        } catch (Throwable $e) {
            error_log('Assignment submit error: ' . $e->getMessage());
            flash_set('error', 'Failed to submit assignment.');
        }
    }
    header('Location: assignments.php' . ($assignId ? '?id=' . $assignId : ''));
    exit;
}

if ($aid > 0) {
    // Single view
    $stmt = $pdo->prepare(
        'SELECT a.*, c.title AS course_title, t.full_name AS teacher_name,
                s.body AS sub_body, s.attachment_path, s.grade, s.feedback, s.submitted_at, s.graded_at
           FROM assignments a
           JOIN courses c ON c.id = a.course_id
           JOIN users t ON t.id = a.teacher_id
           LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = :uid
          WHERE a.id = :aid AND c.id IN (SELECT course_id FROM enrollments WHERE student_id = :uid2)'
    );
    $stmt->execute([':uid' => $uid, ':aid' => $aid, ':uid2' => $uid]);
    $asn = $stmt->fetch();

    if (!$asn) {
        flash_set('error', 'Assignment not found or not enrolled.');
        header('Location: assignments.php');
        exit;
    }

    render_dashboard_header(
        $asn['title'],
        student_nav(),
        'assignments',
        ['Home', 'Assignments', $asn['title']]
    );
    ?>
    <section class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2 style="margin:0;"><?php echo htmlspecialchars($asn['title'], ENT_QUOTES); ?></h2>
            <a class="btn btn-ghost btn-sm" href="assignments.php"><ion-icon name="arrow-back-outline"></ion-icon> Back</a>
        </div>
        <p style="font-size:13px; color:var(--text-light); margin-bottom:14px;">
            Course: <strong><?php echo htmlspecialchars($asn['course_title'], ENT_QUOTES); ?></strong> &middot; 
            Teacher: <strong><?php echo htmlspecialchars($asn['teacher_name'], ENT_QUOTES); ?></strong>
        </p>

        <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:16px; line-height:1.6; margin-bottom:20px;">
            <h4 style="margin:0 0 8px; font-weight:600;">Instructions:</h4>
            <div style="color:var(--text-secondary); white-space:pre-wrap;"><?php echo htmlspecialchars($asn['instructions'], ENT_QUOTES); ?></div>
        </div>

        <p style="font-size:13px; color:var(--text-light); margin-bottom:20px;">
            <?php if ($asn['due_date']): ?>
                Due Date: <strong style="color:#b21f3a;"><?php echo htmlspecialchars(date('M j, Y', strtotime($asn['due_date'])), ENT_QUOTES); ?></strong>
            <?php else: ?>
                No due date.
            <?php endif; ?>
        </p>
    </section>

    <div class="panel-grid">
        <section class="panel">
            <h2>Your Submission</h2>
            <?php if ($asn['submitted_at']): ?>
                <div style="margin-bottom:12px; font-size:12px; color:var(--text-light);">
                    Submitted on: <strong><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($asn['submitted_at'])), ENT_QUOTES); ?></strong>
                </div>
                <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:14px; white-space:pre-wrap; color:var(--text-secondary); margin-bottom:14px;">
                    <?php echo htmlspecialchars($asn['sub_body'], ENT_QUOTES); ?>
                </div>
                <?php if ($asn['attachment_path']): ?>
                    <div style="margin-bottom:14px;">
                        <a href="../<?php echo htmlspecialchars($asn['attachment_path'], ENT_QUOTES); ?>" class="btn btn-sm btn-ghost" target="_blank" rel="noopener noreferrer">
                            <ion-icon name="document-attach-outline"></ion-icon> View Uploaded File
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($asn['grade'] !== null): ?>
                    <div style="border-top:1px solid var(--border-color); padding-top:14px; margin-top:14px;">
                        <h4 style="margin:0 0 6px; font-weight:600;">Teacher Grade &amp; Feedback:</h4>
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                            <span class="tag success" style="font-size:13px; font-weight:700;">Score: <?php echo (int)$asn['grade']; ?>%</span>
                            <span style="font-size:11px; color:var(--text-light);">Graded on <?php echo htmlspecialchars(date('M j, Y', strtotime($asn['graded_at'])), ENT_QUOTES); ?></span>
                        </div>
                        <p style="color:var(--text-secondary); font-style:italic; margin:0;">"<?php echo htmlspecialchars($asn['feedback'] ?? 'No feedback provided.', ENT_QUOTES); ?>"</p>
                    </div>
                <?php else: ?>
                    <span class="tag warn">Pending Grading</span>
                <?php endif; ?>

                <h4 style="margin-top:20px;">Resubmit Answer</h4>
            <?php endif; ?>

            <form method="POST" action="assignments.php?id=<?php echo $aid; ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="assignment_id" value="<?php echo $aid; ?>">
                <div class="form-group">
                    <label for="body">Write your response / notes below:</label>
                    <textarea class="form-control" id="body" name="body" rows="8" placeholder="Enter your response here..." required></textarea>
                </div>
                <div class="form-group" style="margin-top:14px;">
                    <label style="display:block; margin-bottom:6px;">Upload File Attachment (optional):</label>
                    <style>
                        .custom-file-btn {
                            display: inline-flex; align-items: center; gap: 6px; cursor: pointer; 
                            border: 1px dashed var(--primary-color); color: var(--primary-color); 
                            background: rgba(102, 126, 234, 0.05); padding: 8px 16px;
                            border-radius: 8px; font-weight: 600; font-size: 13px;
                            transition: transform .2s cubic-bezier(0.34, 1.56, 0.64, 1), background 0.3s ease, box-shadow 0.2s ease;
                        }
                        .custom-file-btn:hover {
                            background: rgba(102, 126, 234, 0.15);
                            transform: translate(-2px, -2px);
                            box-shadow: 4px 4px 0 0 rgba(102, 126, 234, 0.2);
                        }
                        .custom-file-btn:active {
                            transform: translateY(0);
                            box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.2);
                        }
                    </style>
                    <div style="position: relative; display: inline-block;">
                        <input type="file" id="attachment" name="attachment" style="opacity: 0; width: 0.1px; height: 0.1px; position: absolute; z-index: -1;">
                        <label for="attachment" class="custom-file-btn">
                            <ion-icon name="cloud-upload-outline" style="font-size:18px;"></ion-icon>
                            <span id="file-name-display">Choose a file...</span>
                        </label>
                    </div>
                    <small style="display:block; margin-top:8px; color:var(--text-light); font-size:12px;">Supported formats: PDF, DOCX, PNG, JPG, ZIP (Max 10MB)</small>
                </div>
                
                <script>
                    document.getElementById('attachment').addEventListener('change', function(e) {
                        const fileName = e.target.files[0] ? e.target.files[0].name : 'Choose a file...';
                        document.getElementById('file-name-display').textContent = fileName;
                    });
                </script>
                <button class="btn btn-primary" type="submit" style="margin-top:14px;">
                    <ion-icon name="send-outline"></ion-icon> <?php echo $asn['submitted_at'] ? 'Resubmit' : 'Submit response'; ?>
                </button>
            </form>
        </section>
    </div>
    <?php
    render_dashboard_footer();
    return;
}

// List view
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'pending', 'completed'], true)) {
    $filter = 'all';
}

$query = 'SELECT a.*, c.title AS course_title, t.full_name AS teacher_name,
                 s.submitted_at, s.grade
            FROM assignments a
            JOIN courses c ON c.id = a.course_id
            JOIN users t ON t.id = a.teacher_id
            LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = :uid
           WHERE c.id IN (SELECT course_id FROM enrollments WHERE student_id = :uid2)';

if ($filter === 'pending') {
    $query .= ' AND s.submitted_at IS NULL';
} elseif ($filter === 'completed') {
    $query .= ' AND s.submitted_at IS NOT NULL';
}
$query .= ' ORDER BY a.due_date ASC, a.created_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute([':uid' => $uid, ':uid2' => $uid]);
$assignments = $stmt->fetchAll();

render_dashboard_header(
    'Assignments',
    student_nav(),
    'assignments',
    ['Home', 'Assignments']
);
?>
<section class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
        <h2 style="margin:0;">Interactive Assignments</h2>
        <div style="display:flex; gap:6px;">
            <a class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-ghost'; ?>" href="assignments.php?filter=all">All</a>
            <a class="btn btn-sm <?php echo $filter === 'pending' ? 'btn-primary' : 'btn-ghost'; ?>" href="assignments.php?filter=pending">Pending</a>
            <a class="btn btn-sm <?php echo $filter === 'completed' ? 'btn-primary' : 'btn-ghost'; ?>" href="assignments.php?filter=completed">Completed</a>
        </div>
    </div>

    <?php if (empty($assignments)): ?>
        <div class="empty">
            <div class="ico"><ion-icon name="checkbox-outline"></ion-icon></div>
            <h3>No assignments found</h3>
            <p>You have no <?php echo $filter !== 'all' ? $filter : ''; ?> assignments in your enrolled courses.</p>
        </div>
    <?php else: ?>
        <div class="grid-cards">
            <?php foreach ($assignments as $a): ?>
                <article class="tile">
                    <div class="cover" style="background:linear-gradient(135deg, #f5576c, #f093fb);">
                        <ion-icon name="checkbox-outline"></ion-icon>
                        <span class="pill"><?php echo htmlspecialchars($a['course_title'], ENT_QUOTES); ?></span>
                    </div>
                    <div class="body">
                        <h3><?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?></h3>
                        <p style="font-size:12px; color:var(--text-light);">Taught by <?php echo htmlspecialchars($a['teacher_name'], ENT_QUOTES); ?></p>
                        
                        <div class="meta" style="margin-top:10px;">
                            <?php if ($a['due_date']): ?>
                                <span><ion-icon name="help-circle-outline"></ion-icon> Due: <?php echo htmlspecialchars(date('M j', strtotime($a['due_date'])), ENT_QUOTES); ?></span>
                            <?php endif; ?>
                            <?php if ($a['submitted_at']): ?>
                                <?php if ($a['grade'] !== null): ?>
                                    <span class="tag success" style="font-weight:600;"><ion-icon name="help-circle-outline"></ion-icon> Graded: <?php echo (int)$a['grade']; ?>%</span>
                                <?php else: ?>
                                    <span class="tag info" style="font-weight:600;"><ion-icon name="help-circle-outline"></ion-icon> Submitted</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="tag warn" style="font-weight:600;"><ion-icon name="time-outline"></ion-icon> Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="footer">
                        <a class="btn btn-primary btn-sm" href="assignments.php?id=<?php echo (int)$a['id']; ?>">
                            <?php echo $a['submitted_at'] ? 'View Response' : 'Start Assignment'; ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_dashboard_footer(); ?>
