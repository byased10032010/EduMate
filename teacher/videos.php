<?php
/**
 * EduMate - Teacher: Video library (CRUD with ownership guards and dynamic metadata)
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
    $token  = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: videos.php'); exit;
    }
    
    // Ownership checks for edit/delete
    if ($action === 'update' || $action === 'delete') {
        $vid = (int)($_POST['video_id'] ?? 0);
        $check = $pdo->prepare('SELECT teacher_id FROM videos WHERE id = :id');
        $check->execute([':id' => $vid]);
        $ownerId = $check->fetchColumn();
        if ($ownerId !== false && (int)$ownerId !== $uid && !is_admin_user()) {
            flash_set('error', 'You are not authorized to manage this video.');
            header('Location: videos.php');
            exit;
        }
    }

    if ($action === 'create' || $action === 'update') {
        $title   = trim((string)($_POST['title'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $desc    = trim((string)($_POST['description'] ?? ''));
        $embed   = trim((string)($_POST['embed_url'] ?? ''));
        $cid     = (int)($_POST['course_id'] ?? 0) ?: null;
        $mins    = max(1, (int)($_POST['duration_min'] ?? 10));

        // Auto-convert standard YouTube links to embed format
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $embed, $matches)) {
            $embed = 'https://www.youtube.com/embed/' . $matches[1];
        }
        
        $class   = null;
        $board   = null;

        // Auto-inherit class and board if linked to a course
        if ($cid !== null && $cid > 0) {
            $cinfo = $pdo->prepare('SELECT class, board, subject FROM courses WHERE id = ?');
            $cinfo->execute([$cid]);
            $cRow = $cinfo->fetch();
            if ($cRow) {
                $class = (int)$cRow['class'];
                $board = $cRow['board'];
                if ($subject === '') {
                    $subject = $cRow['subject'];
                }
            }
        } else {
            $class = isset($_POST['class']) && $_POST['class'] !== '' ? (int)$_POST['class'] : null;
            $board = isset($_POST['board']) && $_POST['board'] !== '' ? trim((string)$_POST['board']) : null;
        }

        $errors = [];
        if ($title === '')   $errors[] = 'Title required.';
        if ($subject === '') $errors[] = 'Subject required.';
        if ($embed === '' || !filter_var($embed, FILTER_VALIDATE_URL)) $errors[] = 'Valid embed URL required.';
        
        if ($errors) {
            flash_set('error', $errors);
        } else {
            if ($action === 'create') {
                $pdo->prepare(
                    'INSERT INTO videos (course_id, teacher_id, title, subject, description, embed_url, duration_min, class, board)
                     VALUES (:c, :tid, :t, :s, :d, :u, :m, :class, :board)'
                )->execute([
                    ':c' => $cid,
                    ':tid' => $uid,
                    ':t' => $title,
                    ':s' => $subject,
                    ':d' => $desc,
                    ':u' => $embed,
                    ':m' => $mins,
                    ':class' => $class,
                    ':board' => $board
                ]);
                record_activity($uid, 'fa-video', 'Added video: ' . $title, null);
                flash_set('success', 'Video added successfully.');
            } else {
                $vid = (int)($_POST['video_id'] ?? 0);
                $pdo->prepare(
                    'UPDATE videos 
                        SET course_id=:c, title=:t, subject=:s, description=:d, embed_url=:u, duration_min=:m, class=:class, board=:board 
                      WHERE id=:id'
                )->execute([
                    ':c' => $cid,
                    ':t' => $title,
                    ':s' => $subject,
                    ':d' => $desc,
                    ':u' => $embed,
                    ':m' => $mins,
                    ':class' => $class,
                    ':board' => $board,
                    ':id' => $vid
                ]);
                flash_set('success', 'Video updated successfully.');
            }
        }
    } elseif ($action === 'delete') {
        $vid = (int)($_POST['video_id'] ?? 0);
        $pdo->prepare('DELETE FROM videos WHERE id = :id')->execute([':id' => $vid]);
        flash_set('success', 'Video removed.');
    }
    header('Location: videos.php');
    exit;
}

// Display teacher's own videos & allow managing
$videos = $pdo->prepare(
    'SELECT v.*, c.title AS course_title 
       FROM videos v 
  LEFT JOIN courses c ON c.id = v.course_id 
      WHERE v.teacher_id = :uid OR v.teacher_id IS NULL
   ORDER BY v.created_at DESC'
);
$videos->execute([':uid' => $uid]);
$videos = $videos->fetchAll();

$myCourses = $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id = :u ORDER BY title');
$myCourses->execute([':u' => $uid]);
$myCourses = $myCourses->fetchAll();

// Dynamic subjects selection for auto-fill helper
$subjList = $pdo->query('SELECT DISTINCT name FROM course_subjects ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);

render_dashboard_header(
    'Video Library',
    teacher_nav(),
    'videos',
    ['Home', 'Video Library']
);
?>
<section class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">My Uploaded Videos</h2>
        <button class="btn btn-primary" data-modal-open="#modal-new-video"><ion-icon name="add-outline"></ion-icon> Add Video</button>
    </div>

    <?php if (empty($videos)): ?>
        <div class="empty">
            <div class="ico"><ion-icon name="videocam-outline"></ion-icon></div>
            <h3>No videos yet</h3>
            <p>Add curriculum videos or connect lectures to your courses.</p>
        </div>
    <?php else: ?>
        <table class="simple">
            <thead>
                <tr>
                    <th>Title &amp; Description</th>
                    <th>Subject</th>
                    <th>Class / Board</th>
                    <th>Course</th>
                    <th>Duration</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($videos as $v): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($v['title'], ENT_QUOTES); ?></strong>
                            <br><span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($v['description'], ENT_QUOTES); ?></span>
                        </td>
                        <td><span class="tag" style="background:#e0e7ff; color:#4338ca;"><?php echo htmlspecialchars($v['subject'], ENT_QUOTES); ?></span></td>
                        <td>
                            <?php if ($v['class'] || $v['board']): ?>
                                <span class="tag" style="background:#ecfdf5; color:#047857; font-size:11px;">Class <?php echo $v['class']; ?></span>
                                <span class="tag" style="background:#fff7ed; color:#c2410c; font-size:11px;"><?php echo htmlspecialchars($v['board'] ?? '', ENT_QUOTES); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-light)">Global</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $v['course_title'] ? htmlspecialchars($v['course_title'], ENT_QUOTES) : '<span style="color:var(--text-light)">Standalone</span>'; ?></td>
                        <td><?php echo (int)$v['duration_min']; ?> mins</td>
                        <td style="text-align:right;">
                            <button class="btn btn-ghost btn-sm btn-edit-video" 
                                    data-id="<?php echo $v['id']; ?>"
                                    data-title="<?php echo htmlspecialchars($v['title'], ENT_QUOTES); ?>"
                                    data-subject="<?php echo htmlspecialchars($v['subject'], ENT_QUOTES); ?>"
                                    data-duration="<?php echo $v['duration_min']; ?>"
                                    data-course="<?php echo $v['course_id'] ?? ''; ?>"
                                    data-class="<?php echo $v['class'] ?? ''; ?>"
                                    data-board="<?php echo htmlspecialchars($v['board'] ?? '', ENT_QUOTES); ?>"
                                    data-desc="<?php echo htmlspecialchars($v['description'], ENT_QUOTES); ?>"
                                    data-embed="<?php echo htmlspecialchars($v['embed_url'], ENT_QUOTES); ?>"
                                    data-modal-open="#modal-new-video"
                                    style="color: #667eea; margin-right: 4px;">
                                <ion-icon name="create-outline"></ion-icon>
                            </button>
                            <form method="POST" action="videos.php" data-confirm="Are you sure you want to delete this video?" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="video_id" value="<?php echo (int)$v['id']; ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<!-- Modal: Create / Update Video -->
<div class="modal-backdrop" id="modal-new-video">
    <div class="modal">
        <header>
            <h2>Add a video</h2>
            <button class="close" data-modal-close>&times;</button>
        </header>
        <form method="POST" action="videos.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="body">
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label>Title</label>
                        <input class="form-control" name="title" required placeholder="e.g. Chemical Reactions - Intro">
                    </div>
                    
                    <div class="form-group">
                        <label>Subject</label>
                        <select class="form-control" name="subject" required>
                            <option value="" disabled selected>Select Subject</option>
                            <?php foreach ($subjList as $sub): ?>
                                <option value="<?php echo htmlspecialchars($sub, ENT_QUOTES); ?>"><?php echo htmlspecialchars($sub, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Duration (mins)</label>
                        <input class="form-control" type="number" name="duration_min" min="1" value="15">
                    </div>

                    <div class="form-group span-2">
                        <label>Course (optional &mdash; auto-inherits class and board)</label>
                        <select class="form-control" name="course_id">
                            <option value="">No Course (Standalone video)</option>
                            <?php foreach ($myCourses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Standing fields (displayed and editable if course is standalone) -->
                    <div class="form-group">
                        <label>Class (For Standalone Video)</label>
                        <select class="form-control" name="class">
                            <option value="">None (Global)</option>
                            <?php for ($c = 5; $c <= 10; $c++): ?>
                                <option value="<?php echo $c; ?>">Class <?php echo $c; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Board (For Standalone Video)</label>
                        <select class="form-control" name="board">
                            <option value="">None (Global)</option>
                            <?php foreach (get_all_boards() as $b): ?>
                                <option value="<?php echo htmlspecialchars($b, ENT_QUOTES); ?>"><?php echo htmlspecialchars($b, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group span-2">
                        <label>Description</label>
                        <input class="form-control" name="description" maxlength="500" placeholder="Brief video overview">
                    </div>

                    <div class="form-group span-2">
                        <label>Embed URL (YouTube embed link)</label>
                        <input class="form-control" type="url" name="embed_url" required placeholder="https://www.youtube.com/embed/...">
                    </div>
                </div>
            </div>
            <div class="footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-primary" type="submit"><ion-icon name="save-outline"></ion-icon> Add Video</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Edit Video handler
    document.querySelectorAll('.btn-edit-video').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = document.querySelector('#modal-new-video');
            modal.querySelector('h2').textContent = 'Edit Video';
            modal.querySelector('[name="action"]').value = 'update';
            
            let vidInput = modal.querySelector('[name="video_id"]');
            if (!vidInput) {
                vidInput = document.createElement('input');
                vidInput.type = 'hidden';
                vidInput.name = 'video_id';
                modal.querySelector('form').appendChild(vidInput);
            }
            vidInput.value = btn.dataset.id;
            
            modal.querySelector('[name="title"]').value = btn.dataset.title;
            modal.querySelector('[name="subject"]').value = btn.dataset.subject;
            modal.querySelector('[name="duration_min"]').value = btn.dataset.duration;
            modal.querySelector('[name="course_id"]').value = btn.dataset.course;
            modal.querySelector('[name="class"]').value = btn.dataset.class;
            modal.querySelector('[name="board"]').value = btn.dataset.board;
            modal.querySelector('[name="description"]').value = btn.dataset.desc;
            modal.querySelector('[name="embed_url"]').value = btn.dataset.embed;
            modal.querySelector('button[type="submit"]').innerHTML = '<ion-icon name="save-outline"></ion-icon> Save Changes';
        });
    });

    // Add Video button resets modal action
    document.querySelector('[data-modal-open="#modal-new-video"]:not(.btn-edit-video)').addEventListener('click', () => {
        const modal = document.querySelector('#modal-new-video');
        modal.querySelector('h2').textContent = 'Add a video';
        modal.querySelector('[name="action"]').value = 'create';
        const vidInput = modal.querySelector('[name="video_id"]');
        if (vidInput) vidInput.remove();
        
        modal.querySelector('form').reset();
        modal.querySelector('button[type="submit"]').innerHTML = '<ion-icon name="save-outline"></ion-icon> Add Video';
    });
});
</script>

<?php render_dashboard_footer(); ?>
