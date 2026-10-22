<?php
/**
 * EduMate - Student: Quizzes
 *  - GET ?id=N         -> show a single quiz, allow submission
 *  - GET (no id)       -> list all quizzes
 *  - POST (submit)     -> score it, save attempt, redirect to results
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$quizId = (int)($_GET['id'] ?? 0);

// POST: submit answers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    $token = $_POST['csrf_token'] ?? '';
    $qid   = (int)($_POST['quiz_id'] ?? 0);
    if (!csrf_check(is_string($token) ? $token : null) || $qid <= 0) {
        flash_set('error', 'Invalid submission.');
        header('Location: quizzes.php');
        exit;
    }
    $pdo = db();
    $correct = 0; $total = 0;
    $qs = $pdo->prepare('SELECT id, correct FROM quiz_questions WHERE quiz_id = :q');
    $qs->execute([':q' => $qid]);
    $responses = [];
    foreach ($qs as $q) {
        $total++;
        $given = $_POST['q'][$q['id']] ?? '';
        $responses[$q['id']] = $given;
        if ($given === $q['correct']) $correct++;
    }
    $responsesJson = json_encode($responses);
    $pdo->prepare('INSERT INTO quiz_attempts (student_id, quiz_id, score, total, responses) VALUES (:u, :q, :s, :t, :r)')
        ->execute([':u' => $uid, ':q' => $qid, ':s' => $correct, ':t' => $total, ':r' => $responsesJson]);
    record_activity($uid, 'fa-clipboard-check', "Completed a quiz: scored {$correct}/{$total}", null);
    flash_set($correct === $total ? 'success' : 'info', "You scored {$correct} out of {$total}.");
    header('Location: quiz_result.php?quiz_id=' . $qid);
    exit;
}

if ($quizId > 0) {
    $pdo = db();
    $quizStmt = $pdo->prepare(
        'SELECT q.*, COALESCE(c.title, \'AI Generated Quiz\') AS course_title
           FROM quizzes q LEFT JOIN courses c ON c.id = q.course_id
          WHERE q.id = :q AND (q.course_id IS NOT NULL OR q.created_by = :u)'
    );
    $quizStmt->execute([':q' => $quizId, ':u' => $uid]);
    $quiz = $quizStmt->fetch();
    if (!$quiz) {
        flash_set('error', 'Quiz not found.');
        header('Location: quizzes.php');
        exit;
    }
    $qs = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = :q ORDER BY sort_order ASC, id ASC');
    $qs->execute([':q' => $quizId]);
    $questions = $qs->fetchAll();

    render_dashboard_header(
        $quiz['title'],
        student_nav(),
        'quizzes',
        ['Home', 'Quizzes', $quiz['title']]
    );
    ?>
    <section class="panel">
        <h2><?php echo htmlspecialchars($quiz['title'], ENT_QUOTES); ?>
            <span class="sub"><?php echo htmlspecialchars($quiz['subject'], ENT_QUOTES); ?> · <?php echo count($questions); ?> questions</span>
        </h2>
        <p style="color:var(--text-secondary); margin-bottom:14px;"><?php echo htmlspecialchars($quiz['description'], ENT_QUOTES); ?></p>

        <form method="POST" action="quizzes.php?id=<?php echo $quizId; ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="quiz_id" value="<?php echo $quizId; ?>">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <?php foreach ($questions as $i => $q): ?>
                    <div class="form-group">
                        <label><?php echo ($i + 1) . '. ' . htmlspecialchars($q['prompt'], ENT_QUOTES); ?></label>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <?php foreach (['a' => 'option_a', 'b' => 'option_b', 'c' => 'option_c', 'd' => 'option_d'] as $letter => $col): ?>
                                <label style="display:flex; gap:8px; align-items:center; font-weight:500; cursor:pointer; padding:8px 12px; border:1px solid var(--border-color); border-radius:10px;">
                                    <input type="radio" name="q[<?php echo (int)$q['id']; ?>]" value="<?php echo $letter; ?>" required>
                                    <span><?php echo htmlspecialchars($q[$col], ENT_QUOTES); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:16px; display:flex; gap:8px;">
                <button class="btn btn-primary" type="submit"><ion-icon name="send-outline"></ion-icon> Submit answers</button>
                <a class="btn btn-ghost" href="quizzes.php">Back to quizzes</a>
            </div>
        </form>
    </section>
    <?php
    render_dashboard_footer();
    return;
}

// List view
$pdo = db();
$list = $pdo->prepare(
    'SELECT q.*, COALESCE(c.title, \'AI Generated Quiz\') AS course_title,
            (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = :u) AS attempt_count,
            (SELECT MAX(score) FROM quiz_attempts qa2 WHERE qa2.quiz_id = q.id AND qa2.student_id = :u2) AS best_score,
            (SELECT total FROM quiz_attempts qa3 WHERE qa3.quiz_id = q.id AND qa3.student_id = :u3 ORDER BY qa3.attempted_at DESC LIMIT 1) AS last_total
       FROM quizzes q
       LEFT JOIN courses c ON c.id = q.course_id
       LEFT JOIN enrollments e ON e.course_id = c.id AND e.student_id = :u5
      WHERE (e.student_id IS NOT NULL) OR (q.created_by = :u4)
      ORDER BY q.created_at DESC'
);
$list->execute([':u' => $uid, ':u2' => $uid, ':u3' => $uid, ':u4' => $uid, ':u5' => $uid]);
$quizzes = $list->fetchAll();

render_dashboard_header(
    'Quizzes',
    student_nav(),
    'quizzes',
    ['Home', 'Quizzes']
);
?>
<section class="panel">
    <h2>All Quizzes <span class="sub">Test what you know</span></h2>
    <?php if (empty($quizzes)): ?>
        <div class="empty"><div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div><h3>No quizzes available</h3><p>Once your teachers publish quizzes, they'll show up here.</p></div>
    <?php else: ?>
        <div class="grid-cards">
            <?php foreach ($quizzes as $q): ?>
                <article class="tile">
                    <div class="cover" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                        <ion-icon name="help-circle-outline"></ion-icon>
                        <span class="pill"><?php echo htmlspecialchars($q['subject'], ENT_QUOTES); ?></span>
                    </div>
                    <div class="body">
                        <h3><?php echo htmlspecialchars($q['title'], ENT_QUOTES); ?></h3>
                        <p><?php echo htmlspecialchars($q['description'], ENT_QUOTES); ?></p>
                        <div class="meta">
                            <span><ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($q['course_title'], ENT_QUOTES); ?></span>
                            <span><ion-icon name="help-circle-outline"></ion-icon> <?php echo (int)$q['attempt_count']; ?> attempt<?php echo $q['attempt_count'] == 1 ? '' : 's'; ?></span>
                            <?php if ($q['best_score'] !== null): ?>
                                <span><ion-icon name="help-circle-outline"></ion-icon> Best: <?php echo (int)$q['best_score']; ?>/<?php echo (int)$q['last_total']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="footer">
                        <a class="btn btn-primary btn-sm" href="quizzes.php?id=<?php echo (int)$q['id']; ?>">
                            <?php echo $q['attempt_count'] > 0 ? 'Retake' : 'Start'; ?>
                        </a>
                        <a class="btn btn-ghost btn-sm" href="quiz_result.php?quiz_id=<?php echo (int)$q['id']; ?>">History</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_dashboard_footer(); ?>
