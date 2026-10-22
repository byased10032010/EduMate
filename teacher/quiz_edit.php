<?php
/**
 * EduMate - Teacher: Create/Edit Quiz (Google Forms Style)
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

    if ($action === 'save') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $qid = (int)($_POST['quiz_id'] ?? 0);

        $cStmt = $pdo->prepare('SELECT subject FROM courses WHERE id = :id AND teacher_id = :u');
        $cStmt->execute([':id' => $cid, ':u' => $uid]);
        $course = $cStmt->fetch();

        if (!$course) {
            flash_set('error', 'Invalid course selection.');
        } elseif (mb_strlen($title) < 3) {
            flash_set('error', 'Title must be at least 3 characters.');
        } else {
            $subject = $course['subject'];
            $pdo->beginTransaction();
            try {
                if ($qid > 0) {
                    $qStmt = $pdo->prepare('SELECT 1 FROM quizzes WHERE id = :id AND created_by = :u');
                    $qStmt->execute([':id' => $qid, ':u' => $uid]);
                    if (!$qStmt->fetch()) throw new Exception('Quiz not found.');

                    $pdo->prepare('UPDATE quizzes SET course_id = :c, title = :t, description = :d, subject = :s WHERE id = :id')
                        ->execute([':c' => $cid, ':t' => $title, ':d' => $description, ':s' => $subject, ':id' => $qid]);
                    
                    $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = :id')->execute([':id' => $qid]);
                } else {
                    $pdo->prepare('INSERT INTO quizzes (course_id, title, subject, description, created_by) VALUES (:c, :t, :s, :d, :u)')
                        ->execute([':c' => $cid, ':t' => $title, ':s' => $subject, ':d' => $description, ':u' => $uid]);
                    $qid = (int)$pdo->lastInsertId();
                    record_activity($uid, 'fa-clipboard-check', 'Created quiz: ' . $title, null);
                }

                $questions = $_POST['questions'] ?? [];
                if (empty($questions)) {
                    throw new Exception('A quiz must have at least one question.');
                }

                $qInsert = $pdo->prepare('INSERT INTO quiz_questions (quiz_id, prompt, option_a, option_b, option_c, option_d, correct, sort_order) VALUES (:q, :p, :a, :b, :c, :d, :ans, :so)');
                $sort = 0;
                foreach ($questions as $q) {
                    $prompt = trim($q['prompt'] ?? '');
                    if (!$prompt) continue;
                    $qInsert->execute([
                        ':q' => $qid,
                        ':p' => $prompt,
                        ':a' => trim($q['options']['a'] ?? ''),
                        ':b' => trim($q['options']['b'] ?? ''),
                        ':c' => trim($q['options']['c'] ?? ''),
                        ':d' => trim($q['options']['d'] ?? ''),
                        ':ans' => in_array($q['correct'] ?? '', ['a','b','c','d']) ? $q['correct'] : 'a',
                        ':so' => $sort++
                    ]);
                }
                
                $pdo->commit();
                flash_set('success', 'Quiz saved successfully.');
                header('Location: quizzes.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                flash_set('error', $e->getMessage());
            }
        }
    }
}

$qid = (int)($_GET['id'] ?? 0);
$quiz = ['course_id' => '', 'title' => '', 'description' => ''];
$questions = [];

if ($qid > 0) {
    $qStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = :id AND created_by = :u');
    $qStmt->execute([':id' => $qid, ':u' => $uid]);
    $quiz = $qStmt->fetch();
    if (!$quiz) {
        flash_set('error', 'Quiz not found.');
        header('Location: quizzes.php');
        exit;
    }
    
    $qqStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = :id ORDER BY sort_order ASC');
    $qqStmt->execute([':id' => $qid]);
    $questions = $qqStmt->fetchAll();
}

$myCourses = $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id = :u ORDER BY title');
$myCourses->execute([':u' => $uid]);
$myCourses = $myCourses->fetchAll();

if (empty($myCourses)) {
    flash_set('error', 'You must create a course before you can create a quiz.');
    header('Location: courses.php');
    exit;
}

render_dashboard_header(
    $qid > 0 ? 'Edit Quiz' : 'New Quiz',
    teacher_nav(),
    'quizzes',
    ['Home', 'Quizzes', $qid > 0 ? 'Edit' : 'New']
);
?>

<style>
.question-block {
    position: relative;
    padding: 24px;
    border-left: 4px solid var(--gradient-primary);
    margin-bottom: 24px;
    transition: box-shadow 0.2s;
}
.question-block:focus-within {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.btn-remove-q {
    position: absolute;
    top: 16px;
    right: 16px;
    z-index: 10;
    color: var(--text-light);
}
.btn-remove-q:hover {
    color: #b21f3a;
    background: rgba(178, 31, 58, 0.1);
}
.options-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 16px;
}
.option-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg-secondary);
    padding: 8px 12px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-light);
}
.option-row:focus-within {
    border-color: var(--gradient-primary);
}
.option-row input[type="radio"] {
    transform: scale(1.2);
    accent-color: var(--gradient-primary);
    cursor: pointer;
}
.option-row input[type="text"] {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    font-size: 14px;
}
.drag-handle {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    cursor: grab;
    color: var(--text-light);
    padding: 4px 20px;
    opacity: 0.5;
}
.drag-handle:hover {
    opacity: 1;
}
</style>

<form method="POST" action="quiz_edit.php" id="quiz-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="quiz_id" value="<?php echo $qid; ?>">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h2 style="margin:0;"><?php echo $qid > 0 ? 'Edit Quiz' : 'Create New Quiz'; ?></h2>
        <div>
            <a href="quizzes.php" class="btn btn-ghost" style="margin-right:8px;">Cancel</a>
            <button type="submit" class="btn btn-primary"><ion-icon name="save-outline"></ion-icon> Save Quiz</button>
        </div>
    </div>

    <div class="panel" style="margin-bottom: 32px; border-top: 8px solid var(--gradient-primary);">
        <div class="form-group">
            <input class="form-control" name="title" placeholder="Quiz Title" style="font-size: 24px; font-weight: 600; padding: 12px 16px; border:none; border-bottom: 1px solid var(--border-light); border-radius:0; background:transparent;" required value="<?php echo htmlspecialchars($quiz['title'], ENT_QUOTES); ?>">
        </div>
        <div class="form-group">
            <textarea class="form-control" name="description" placeholder="Quiz Description (optional)" rows="2" style="border:none; border-bottom: 1px solid var(--border-light); border-radius:0; background:transparent; resize:vertical;"><?php echo htmlspecialchars($quiz['description'], ENT_QUOTES); ?></textarea>
        </div>
        <div class="form-group" style="max-width: 400px;">
            <label>Associated Course</label>
            <select class="form-control" name="course_id" required>
                <option value="">Select a course</option>
                <?php foreach ($myCourses as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ($quiz['course_id'] == $c['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="questions-container">
        <!-- Render existing questions if any -->
        <?php foreach ($questions as $index => $q): ?>
            <div class="panel question-block">
                <button type="button" class="btn btn-ghost btn-sm btn-remove-q" title="Delete Question"><ion-icon name="trash"></ion-icon></button>
                <div class="form-group">
                    <input class="form-control" name="questions[<?php echo $index; ?>][prompt]" placeholder="Question prompt" style="font-size: 16px; font-weight: 500; border:none; background: var(--bg-secondary); padding-right: 40px;" required value="<?php echo htmlspecialchars($q['prompt'], ENT_QUOTES); ?>">
                </div>
                <div style="font-size: 12px; color: var(--text-light); margin-top: 12px; margin-bottom: -8px; font-weight: 500;">
                    <ion-icon name="information-circle-outline" style="vertical-align: middle;"></ion-icon> Select the radio button next to the correct answer
                </div>
                <div class="options-grid">
                    <?php foreach (['a','b','c','d'] as $opt): ?>
                        <div class="option-row">
                            <input type="radio" name="questions[<?php echo $index; ?>][correct]" value="<?php echo $opt; ?>" <?php echo $q['correct'] === $opt ? 'checked' : ''; ?> required title="Mark as correct answer">
                            <span style="font-weight:600; color:var(--text-light); text-transform:uppercase;"><?php echo $opt; ?>.</span>
                            <input type="text" name="questions[<?php echo $index; ?>][options][<?php echo $opt; ?>]" placeholder="Option text" required value="<?php echo htmlspecialchars($q['option_'.$opt], ENT_QUOTES); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="text-align: center; padding: 20px 0 60px 0;">
        <button type="button" class="btn btn-ghost" id="add-question-btn" style="border: 1px dashed var(--border-strong); padding: 12px 24px; border-radius: 999px;">
            <ion-icon name="add-circle-outline" style="font-size: 20px; vertical-align: middle; margin-right: 8px;"></ion-icon> Add Question
        </button>
    </div>
</form>

<template id="question-template">
    <div class="panel question-block" style="display:none;">
        <button type="button" class="btn btn-ghost btn-sm btn-remove-q" title="Delete Question"><ion-icon name="trash"></ion-icon></button>
        <div class="form-group">
            <input class="form-control" name="questions[{INDEX}][prompt]" placeholder="Question prompt" style="font-size: 16px; font-weight: 500; border:none; background: var(--bg-secondary); padding-right: 40px;" required>
        </div>
        <div style="font-size: 12px; color: var(--text-light); margin-top: 12px; margin-bottom: -8px; font-weight: 500;">
            <ion-icon name="information-circle-outline" style="vertical-align: middle;"></ion-icon> Select the radio button next to the correct answer
        </div>
        <div class="options-grid">
            <div class="option-row">
                <input type="radio" name="questions[{INDEX}][correct]" value="a" required title="Mark as correct answer">
                <span style="font-weight:600; color:var(--text-light);">A.</span>
                <input type="text" name="questions[{INDEX}][options][a]" placeholder="Option text" required>
            </div>
            <div class="option-row">
                <input type="radio" name="questions[{INDEX}][correct]" value="b" required title="Mark as correct answer">
                <span style="font-weight:600; color:var(--text-light);">B.</span>
                <input type="text" name="questions[{INDEX}][options][b]" placeholder="Option text" required>
            </div>
            <div class="option-row">
                <input type="radio" name="questions[{INDEX}][correct]" value="c" required title="Mark as correct answer">
                <span style="font-weight:600; color:var(--text-light);">C.</span>
                <input type="text" name="questions[{INDEX}][options][c]" placeholder="Option text" required>
            </div>
            <div class="option-row">
                <input type="radio" name="questions[{INDEX}][correct]" value="d" required title="Mark as correct answer">
                <span style="font-weight:600; color:var(--text-light);">D.</span>
                <input type="text" name="questions[{INDEX}][options][d]" placeholder="Option text" required>
            </div>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('questions-container');
    const template = document.getElementById('question-template').innerHTML;
    const addBtn = document.getElementById('add-question-btn');
    
    // Start index higher than existing questions
    let questionIndex = <?php echo count($questions); ?>;

    function addQuestion() {
        const div = document.createElement('div');
        div.innerHTML = template.replace(/{INDEX}/g, questionIndex++);
        const block = div.firstElementChild;
        container.appendChild(block);
        
        // Add slide down animation
        block.style.display = 'block';
        block.style.opacity = '0';
        block.style.transform = 'translateY(20px)';
        
        // Trigger reflow
        void block.offsetWidth; 
        
        block.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        block.style.opacity = '1';
        block.style.transform = 'translateY(0)';
        
        // Focus the new prompt input
        setTimeout(() => block.querySelector('input[name$="[prompt]"]').focus(), 300);
    }

    // Add first question if empty
    if (questionIndex === 0) {
        addQuestion();
    }

    addBtn.addEventListener('click', addQuestion);

    container.addEventListener('click', e => {
        const btn = e.target.closest('.btn-remove-q');
        if (btn) {
            if (container.querySelectorAll('.question-block').length <= 1) {
                alert('A quiz must have at least one question.');
                return;
            }
            if (confirm('Are you sure you want to delete this question?')) {
                const block = btn.closest('.question-block');
                block.style.opacity = '0';
                block.style.transform = 'scale(0.95)';
                setTimeout(() => block.remove(), 300);
            }
        }
    });
});
</script>

<?php render_dashboard_footer(); ?>
