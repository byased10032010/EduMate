<?php
/**
 * EduMate - Student: Quiz attempt history + per-question review
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$quizId = (int)($_GET['quiz_id'] ?? 0);

$pdo = db();
if ($quizId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = :q');
    $stmt->execute([':q' => $quizId]);
    $quiz = $stmt->fetch();
    if (!$quiz) {
        flash_set('error', 'Quiz not found.');
        header('Location: quizzes.php');
        exit;
    }
    $attempts = $pdo->prepare(
        'SELECT * FROM quiz_attempts WHERE quiz_id = :q AND student_id = :u ORDER BY attempted_at DESC'
    );
    $attempts->execute([':q' => $quizId, ':u' => $uid]);
    $rows = $attempts->fetchAll();

    $qsStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = :q ORDER BY sort_order ASC, id ASC');
    $qsStmt->execute([':q' => $quizId]);
    $questions = $qsStmt->fetchAll(PDO::FETCH_ASSOC);

    render_dashboard_header(
        $quiz['title'] . ' · History',
        student_nav(),
        'quizzes',
        ['Home', 'Quizzes', $quiz['title'], 'History']
    );
    ?>
    <section class="panel">
        <h2>Attempt history <span class="sub"><?php echo htmlspecialchars($quiz['title'], ENT_QUOTES); ?></span></h2>
        <?php if (empty($rows)): ?>
            <div class="empty"><div class="ico"><ion-icon name="time-outline"></ion-icon></div><h3>No attempts yet</h3><p><a href="quizzes.php?id=<?php echo $quizId; ?>">Take the quiz</a> to see your history.</p></div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($rows as $r): ?>
                    <?php $pct = $r['total'] > 0 ? round($r['score'] * 100 / $r['total']) : 0; ?>
                    <details class="quiz-attempt-details">
                        <summary>
                            <span><ion-icon name="time-outline"></ion-icon> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['attempted_at'])), ENT_QUOTES); ?></span>
                            <span>Score: <?php echo (int)$r['score']; ?> / <?php echo (int)$r['total']; ?> (<?php echo $pct; ?>%)</span>
                        </summary>
                        <div class="quiz-attempt-body">
                            <?php 
                            $resp = json_decode((string)($r['responses'] ?? '[]'), true) ?? [];
                            foreach ($questions as $i => $q): 
                                $given = $resp[$q['id']] ?? null;
                                $isCorrect = ($given === $q['correct']);
                                $boxClass = $isCorrect ? 'correct' : 'incorrect';
                            ?>
                                <div class="quiz-resp-box <?php echo $boxClass; ?>">
                                    <p><?php echo ($i + 1) . '. ' . htmlspecialchars($q['prompt'], ENT_QUOTES); ?></p>
                                    <ul>
                                        <?php foreach (['a' => 'option_a', 'b' => 'option_b', 'c' => 'option_c', 'd' => 'option_d'] as $letter => $col): 
                                            $isGiven = ($given === $letter);
                                            $isActualCorrect = ($q['correct'] === $letter);
                                            
                                            $liClass = '';
                                            if ($isGiven) {
                                                $liClass = $isActualCorrect ? 'is-actual-correct' : 'is-given-incorrect';
                                            }
                                        ?>
                                            <li class="<?php echo $liClass; ?>">
                                                <span><?php echo strtoupper($letter); ?>)</span> 
                                                <span><?php echo htmlspecialchars($q[$col], ENT_QUOTES); ?></span>
                                                <?php if ($isGiven && $isActualCorrect): ?> <ion-icon name="checkmark-circle"></ion-icon> <span style="font-size:12px;">(Your answer)</span> <?php endif; ?>
                                                <?php if ($isGiven && !$isActualCorrect): ?> <ion-icon name="close-circle"></ion-icon> <span style="font-size:12px;">(Your answer)</span> <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <p style="margin-top:20px;">
                <a class="btn btn-primary" href="quizzes.php?id=<?php echo $quizId; ?>"><ion-icon name="help-circle-outline"></ion-icon> Take again</a>
                <a class="btn btn-ghost" href="quizzes.php">All quizzes</a>
            </p>
        <?php endif; ?>
    </section>
    <?php
    render_dashboard_footer();
    return;
}

// No quiz_id: show all history across all quizzes
$all = $pdo->prepare(
    'SELECT qa.*, q.title AS quiz_title, q.subject
       FROM quiz_attempts qa
       JOIN quizzes q ON q.id = qa.quiz_id
      WHERE qa.student_id = :u
      ORDER BY qa.attempted_at DESC LIMIT 50'
);
$all->execute([':u' => $uid]);
$rows = $all->fetchAll();

render_dashboard_header(
    'Quiz History',
    student_nav(),
    'quizzes',
    ['Home', 'Quizzes', 'History']
);
?>
<section class="panel">
    <h2>All quiz attempts</h2>
    <?php if (empty($rows)): ?>
        <div class="empty"><div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div><h3>No attempts yet</h3><p>Take a quiz to see your results here.</p></div>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Quiz</th><th>Date</th><th>Score</th><th>Result</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r): $pct = $r['total'] > 0 ? round($r['score'] * 100 / $r['total']) : 0; ?>
                    <tr>
                        <td><a href="quiz_result.php?quiz_id=<?php echo (int)$r['quiz_id']; ?>"><?php echo htmlspecialchars($r['quiz_title'], ENT_QUOTES); ?></a><br><span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($r['subject'], ENT_QUOTES); ?></span></td>
                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['attempted_at'])), ENT_QUOTES); ?></td>
                        <td><?php echo (int)$r['score']; ?> / <?php echo (int)$r['total']; ?></td>
                        <td>
                            <?php if ($pct === 100): ?><span class="tag success">100%</span>
                            <?php elseif ($pct >= 60): ?><span class="tag info"><?php echo $pct; ?>%</span>
                            <?php else: ?><span class="tag warn"><?php echo $pct; ?>%</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php render_dashboard_footer(); ?>
