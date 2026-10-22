<?php
/**
 * EduMate - Teacher: Quizzes (CRUD list)
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
        header('Location: quizzes.php'); exit;
    }

    if ($action === 'delete') {
        $qidPost = (int)($_POST['quiz_id'] ?? 0);
        $ownQ = $pdo->prepare('SELECT 1 FROM quizzes WHERE id = :id AND created_by = :u');
        $ownQ->execute([':id' => $qidPost, ':u' => $uid]);
        if (!$ownQ->fetch()) flash_set('error', 'Quiz not found.');
        else {
            $pdo->prepare('DELETE FROM quizzes WHERE id = :id AND created_by = :u')->execute([':id' => $qidPost, ':u' => $uid]);
            flash_set('success', 'Quiz removed.');
        }
    }
    header('Location: quizzes.php');
    exit;
}

// List view
$list = $pdo->prepare(
    'SELECT q.*, c.title AS course_title,
            (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_count
       FROM quizzes q
       JOIN courses c ON c.id = q.course_id
      WHERE q.created_by = :u ORDER BY q.created_at DESC'
);
$list->execute([':u' => $uid]);
$quizzes = $list->fetchAll();

$myCourses = $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id = :u ORDER BY title');
$myCourses->execute([':u' => $uid]);
$myCourses = $myCourses->fetchAll();

render_dashboard_header(
    'Quizzes',
    teacher_nav(),
    'quizzes',
    ['Home', 'Quizzes']
);
?>
<section class="panel">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <h2 style="margin:0;">All Quizzes</h2>
        <?php if (!empty($myCourses)): ?>
            <a href="quiz_edit.php" class="btn btn-primary"><ion-icon name="add-outline"></ion-icon> New Quiz</a>
        <?php endif; ?>
    </div>

    <?php if (empty($quizzes)): ?>
        <div class="empty">
            <div class="ico"><ion-icon name="clipboard-outline"></ion-icon></div>
            <h3>No quizzes yet</h3>
            <p>Create a quiz to assess your students.</p>
        </div>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Title</th><th>Course</th><th>Questions</th><th>Attempts</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($quizzes as $q): ?>
                    <tr>
                        <td><a href="quiz_edit.php?id=<?php echo (int)$q['id']; ?>" style="font-weight:600;"><?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?></a></td>
                        <td><?php echo htmlspecialchars($q['course_title'], ENT_QUOTES); ?></td>
                        <td><span class="tag"><?php echo (int)$q['question_count']; ?></span></td>
                        <td><span class="tag"><?php echo (int)$q['attempt_count']; ?></span></td>
                        <td style="display: flex; gap: 8px; align-items: center; justify-content: flex-end;">
                            <a class="btn btn-ghost btn-sm" href="quiz_edit.php?id=<?php echo (int)$q['id']; ?>"><ion-icon name="create-outline"></ion-icon> Edit</a>
                            <form method="POST" action="quizzes.php" data-confirm="Delete this quiz? This will also delete all student attempts!" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="quiz_id" value="<?php echo (int)$q['id']; ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#b21f3a;"><ion-icon name="trash-outline"></ion-icon></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php render_dashboard_footer(); ?>
