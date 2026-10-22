<?php
/**
 * EduMate - Student/All: Boards & Subjects Directory
 * - Displays all educational boards
 * - Displays classes (5-10) and subjects registered under each board
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';

// Allows both students and teachers to view the directory (fallback logic)
$user = current_user();
$role = $user['role'] ?? 'student';
$nav  = $role === 'teacher' ? teacher_nav() : student_nav();

$pdo = db();

// Fetch all boards
$boards = $pdo->query('SELECT * FROM boards ORDER BY name ASC')->fetchAll();

// Selected Board
$selectedBoard = $_GET['board'] ?? ($boards[0]['name'] ?? '');

// Fetch all subjects for this board, grouped by Class
$subjectsByClass = [];
for ($c = 5; $c <= 10; $c++) {
    $subjectsByClass[$c] = [];
}

if (!empty($selectedBoard)) {
    $stmt = $pdo->prepare('SELECT * FROM course_subjects WHERE board = :board ORDER BY name ASC');
    $stmt->execute([':board' => $selectedBoard]);
    $subjectsList = $stmt->fetchAll();
    foreach ($subjectsList as $s) {
        $c = (int)$s['class'];
        if ($c >= 5 && $c <= 10) {
            $subjectsByClass[$c][] = $s;
        }
    }
}

render_dashboard_header(
    'Boards & Subjects Directory',
    $nav,
    'boards',
    ['Home', 'Boards Directory']
);
?>
<section class="panel" style="margin-bottom: 20px;">
    <h2>Boards Directory</h2>
    <p style="color:var(--text-light); font-size:12px; margin-bottom:14px;">Select an educational board to view its class-wise subjects and courses</p>
    
    <!-- Boards Navigation -->
    <div style="display:flex; gap:10px; flex-wrap:wrap; border-bottom:1px solid var(--border-color); padding-bottom:12px;">
        <?php foreach ($boards as $b): ?>
            <a class="btn <?php echo $selectedBoard === $b['name'] ? 'btn-primary' : 'btn-ghost'; ?>" href="boards.php?board=<?php echo urlencode($b['name']); ?>">
                <ion-icon name="help-circle-outline"></ion-icon> <?php echo htmlspecialchars($b['name'], ENT_QUOTES); ?> Board
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if (!empty($selectedBoard)): ?>
    <h3 style="margin-bottom:16px;">Classes &amp; Subjects for <strong><?php echo htmlspecialchars($selectedBoard, ENT_QUOTES); ?> Board</strong></h3>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
        <?php for ($c = 5; $c <= 10; $c++): 
            $subs = $subjectsByClass[$c];
        ?>
            <article class="panel" style="position:relative; border-top: 4px solid var(--text-primary); transition: transform 0.2s; box-shadow: var(--shadow-sm);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-primary);">Class <?php echo $c; ?></h3>
                    <span class="tag" style="background:#e0e7ff; color:#4338ca; font-size:11px; font-weight:600;"><?php echo count($subs); ?> Subjects</span>
                </div>

                <?php if (empty($subs)): ?>
                    <p style="font-size:12px; color:var(--text-light); font-style:italic;">No subjects configured yet for Class <?php echo $c; ?>.</p>
                <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:8px; margin-top:10px;">
                        <?php foreach ($subs as $s): ?>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:10px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <ion-icon name="help-circle-outline"></ion-icon>
                                    <span style="font-size:13px; font-weight:600; color:var(--text-secondary);"><?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?></span>
                                </div>
                                <?php if ($role === 'student'): ?>
                                    <a class="btn btn-ghost btn-sm" href="subjects.php?subject=<?php echo urlencode($s['name']); ?>" style="padding: 2px 8px; font-size:11px;">
                                        View Resources <ion-icon name="help-circle-outline"></ion-icon>
                                    </a>
                                <?php else: ?>
                                    <a class="btn btn-ghost btn-sm" href="../teacher/curriculum.php" style="padding: 2px 8px; font-size:11px;">
                                        Manage
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php render_dashboard_footer(); ?>
