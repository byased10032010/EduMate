<?php
/**
 * EduMate - Student: Weekly Progress Report Module
 * - Generates performance analytics (strengths, weak areas)
 * - Offers personalized learning suggestions and links based on Class/Board
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Fetch Class/Board details
$uStmt = $pdo->prepare('SELECT class, board, streak_count FROM users WHERE id = :id');
$uStmt->execute([':id' => $uid]);
$uInfo = $uStmt->fetch();
$class = $uInfo['class'] ?? 8;
$board = $uInfo['board'] ?? 'CBSE';
$streak = (int)($uInfo['streak_count'] ?? 0);

// 1. Performance Analytics for the past 7 days
$ld = $pdo->prepare(
    'SELECT COUNT(*) FROM lesson_completions 
      WHERE student_id = :u AND completed_at >= (NOW() - INTERVAL 7 DAY)'
);
$ld->execute([':u' => $uid]);
$weeklyLessonsDone = (int)$ld->fetchColumn();

$qa = $pdo->prepare(
    'SELECT COUNT(*), COALESCE(AVG(score/NULLIF(total,0))*100, 0) 
       FROM quiz_attempts 
      WHERE student_id = :u AND attempted_at >= (NOW() - INTERVAL 7 DAY)'
);
$qa->execute([':u' => $uid]);
$quizStats = $qa->fetch(PDO::FETCH_NUM);
$weeklyQuizzesCount = (int)($quizStats[0] ?? 0);
$weeklyAvgScore = (int)round((float)($quizStats[1] ?? 0));

$sub = $pdo->prepare(
    'SELECT COUNT(*) FROM submissions 
      WHERE student_id = :u AND submitted_at >= (NOW() - INTERVAL 7 DAY)'
);
$sub->execute([':u' => $uid]);
$weeklySubmissionsCount = (int)$sub->fetchColumn();

// 2. Strengths Determination
// Fetch high quiz scores
$highQuizzes = $pdo->prepare(
    'SELECT COALESCE(c.title, q.title) AS title, MAX(qa.score/qa.total * 100) AS max_score
       FROM quiz_attempts qa
       JOIN quizzes q ON q.id = qa.quiz_id
       LEFT JOIN courses c ON c.id = q.course_id
      WHERE qa.student_id = :u
      GROUP BY q.id, c.title, q.title
     HAVING max_score >= 80'
);
$highQuizzes->execute([':u' => $uid]);
$strengthsList = $highQuizzes->fetchAll();

// 3. Weak Areas Determination
// Fetch low quiz scores or courses with no quiz attempts but enrolled
$lowQuizzes = $pdo->prepare(
    'SELECT COALESCE(c.title, q.title) AS title, MIN(qa.score/qa.total * 100) AS min_score
       FROM quiz_attempts qa
       JOIN quizzes q ON q.id = qa.quiz_id
       LEFT JOIN courses c ON c.id = q.course_id
      WHERE qa.student_id = :u
      GROUP BY q.id, c.title, q.title
     HAVING min_score < 70'
);
$lowQuizzes->execute([':u' => $uid]);
$weakList = $lowQuizzes->fetchAll();

// Find pending assignments (unsubmitted)
$pendingAsn = $pdo->prepare(
    'SELECT a.title, c.title AS course_title
       FROM assignments a
       JOIN courses c ON c.id = a.course_id
      WHERE c.id IN (SELECT course_id FROM enrollments WHERE student_id = :u)
        AND a.id NOT IN (SELECT assignment_id FROM submissions WHERE student_id = :u2)
      ORDER BY a.due_date ASC LIMIT 3'
);
$pendingAsn->execute([':u' => $uid, ':u2' => $uid]);
$unsubmittedAssignments = $pendingAsn->fetchAll();

// 4. Personalized Recommendations based on Class and Board
// Find standard books, notes and links references
$boardBookRecommendations = [
    'CBSE' => [
        'Mathematics' => "NCERT Class {$class} Mathematics Textbook",
        'Science' => "NCERT Class {$class} Science Textbook & Exemplar Solutions",
        'notes' => "CBSE academic notes portals (cbseacademic.nic.in)"
    ],
    'ICSE' => [
        'Mathematics' => "Selina Concise Mathematics for Class {$class}",
        'Science' => "Candid / S. Chand ICSE Class {$class} Science textbooks",
        'notes' => "CISCE curriculum guidelines for Class {$class}"
    ],
    'State Board' => [
        'Mathematics' => "State Board Class {$class} Mathematics Textbooks",
        'Science' => "State Board Class {$class} General Science series",
        'notes' => "State Balbharti / SCERT digital textbook repository"
    ]
];
$recs = $boardBookRecommendations[$board] ?? $boardBookRecommendations['CBSE'];

// Video library recommendations matching user's class/board courses
$vidRecs = $pdo->prepare(
    'SELECT v.title, v.subject, v.embed_url, c.title AS course_title
       FROM videos v
       JOIN courses c ON c.id = v.course_id
      WHERE c.class = :class AND c.board = :board
      LIMIT 3'
);
$vidRecs->execute([':class' => $class, ':board' => $board]);
$videoRecommendations = $vidRecs->fetchAll();

// If empty, fetch any 3 videos
if (empty($videoRecommendations)) {
    $videoRecommendations = $pdo->query('SELECT v.title, v.subject, v.embed_url, c.title AS course_title FROM videos v LEFT JOIN courses c ON c.id = v.course_id LIMIT 3')->fetchAll();
}

// 5. Recent Doubt History Recap (AI Tutor interactions)
$doubts = $pdo->prepare(
    'SELECT question AS content, created_at FROM ai_doubts 
      WHERE user_id = :u
      ORDER BY created_at DESC LIMIT 4'
);
$doubts->execute([':u' => $uid]);
$doubtHistory = $doubts->fetchAll();

// 6. Weekly Chart Data (Completed lessons per day for past 7 days)
$weeklyChart = array_fill(0, 7, 0);
$today = new DateTimeImmutable('today');
$wk = $pdo->prepare(
    "SELECT event_date AS d, SUM(c) AS c FROM (
        SELECT DATE(completed_at) AS event_date, COUNT(*) AS c
          FROM lesson_completions
         WHERE student_id = :u1 AND completed_at >= (NOW() - INTERVAL 7 DAY)
         GROUP BY DATE(completed_at)
        UNION ALL
        SELECT DATE(attempted_at) AS event_date, COUNT(*) AS c
          FROM quiz_attempts
         WHERE student_id = :u2 AND attempted_at >= (NOW() - INTERVAL 7 DAY)
         GROUP BY DATE(attempted_at)
    ) AS combined_activity
    GROUP BY event_date"
);
$wk->execute([':u1' => $uid, ':u2' => $uid]);
foreach ($wk as $row) {
    $d = new DateTimeImmutable($row['d']);
    $diff = (int)$today->diff($d)->format('%r%a');
    $idx  = 6 - $diff;
    if ($idx >= 0 && $idx < 7) $weeklyChart[$idx] = (int)$row['c'];
}
$weekMax = max($weeklyChart ?: [1]);
$labels = [];
for ($i = 6; $i >= 0; $i--) {
    $labels[] = (new DateTimeImmutable("-$i days"))->format('D');
}

render_dashboard_header(
    'Weekly Report',
    student_nav(),
    'report',
    ['Home', 'Weekly Progress Report']
);
?>
<div class="panel-grid" style="grid-template-columns: 2fr 1fr;">
    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Analytics Header -->
        <section class="panel" style="position:relative; overflow:hidden;">
            <div style="background:var(--gradient-primary); position:absolute; top:0; left:0; right:0; height:6px;"></div>
            <h2>Weekly Performance Analytics</h2>
            <p style="font-size:13px; color:var(--text-light); margin-bottom:18px;">
                Personalized report for <strong style="color:var(--text-primary);"><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?></strong> &middot; 
                Class <?php echo $class; ?> &middot; <?php echo htmlspecialchars($board, ENT_QUOTES); ?> Board
            </p>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:14px; margin-bottom:20px;">
                <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:12px; text-align:center;">
                    <div style="color:var(--text-light); font-size:11px; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Lessons Done</div>
                    <div style="font-size:24px; font-weight:700; color:#667eea;"><?php echo $weeklyLessonsDone; ?></div>
                    <span style="font-size:11px; color:var(--text-light);">past 7 days</span>
                </div>
                <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:12px; text-align:center;">
                    <div style="color:var(--text-light); font-size:11px; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Quizzes Taken</div>
                    <div style="font-size:24px; font-weight:700; color:#764ba2;"><?php echo $weeklyQuizzesCount; ?></div>
                    <span style="font-size:11px; color:var(--text-light);">Avg: <?php echo $weeklyAvgScore; ?>%</span>
                </div>
                <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:12px; text-align:center;">
                    <div style="color:var(--text-light); font-size:11px; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Assignments</div>
                    <div style="font-size:24px; font-weight:700; color:#f5576c;"><?php echo $weeklySubmissionsCount; ?></div>
                    <span style="font-size:11px; color:var(--text-light);">completed</span>
                </div>
                <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; padding:12px; text-align:center;">
                    <div style="color:var(--text-light); font-size:11px; text-transform:uppercase; font-weight:600; margin-bottom:4px;">Study Streak</div>
                    <div style="font-size:24px; font-weight:700; color:#38f9d7;"><?php echo $streak; ?> Days</div>
                    <span style="font-size:11px; color:var(--text-light);">Learning streak 🔥</span>
                </div>
            </div>

            <!-- Activity Chart -->
            <h4 style="margin:14px 0 8px; font-weight:600;">Daily Study Pattern (Completed Lessons)</h4>
            <div style="display:flex; align-items:flex-end; gap:8px; height:120px; border-bottom:1px solid var(--border-color); padding-bottom:6px; margin-bottom:6px;">
                <?php foreach ($weeklyChart as $i => $count):
                    $h = $weekMax > 0 ? max(8, (int)round($count * 100 / $weekMax)) : 8;
                ?>
                    <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; justify-content:flex-end;">
                        <div title="<?php echo $count; ?> lessons" style="width:100%; height:<?php echo $h; ?>%; background: linear-gradient(180deg, #667eea, #764ba2); border-radius:6px;"></div>
                        <span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($labels[$i] ?? '', ENT_QUOTES); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Strengths & Weaknesses -->
        <section class="panel">
            <h2>Core Competencies Report</h2>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:12px;">
                <!-- Strengths -->
                <div>
                    <h3 style="color:#047857; font-size:15px; margin-bottom:8px;"><ion-icon name="help-circle-outline"></ion-icon> Key Strengths</h3>
                    <?php if (empty($strengthsList)): ?>
                        <p style="font-size:12px; color:var(--text-light); font-style:italic;">Keep scoring high in quizzes to show your key academic strengths here!</p>
                    <?php else: ?>
                        <ul style="font-size:13px; color:var(--text-secondary); padding-left:16px; margin:0; line-height:1.6;">
                            <?php foreach ($strengthsList as $s): ?>
                                <li>Mastery in <strong><?php echo htmlspecialchars($s['title'], ENT_QUOTES); ?></strong> (Best Quiz: <?php echo (int)$s['max_score']; ?>%)</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <!-- Weak Areas -->
                <div>
                    <h3 style="color:#b21f3a; font-size:15px; margin-bottom:8px;"><ion-icon name="help-circle-outline"></ion-icon> Focus / Weak Areas</h3>
                    <ul style="font-size:13px; color:var(--text-secondary); padding-left:16px; margin:0; line-height:1.6;">
                        <?php foreach ($weakList as $w): ?>
                            <li>Practice needed in <strong><?php echo htmlspecialchars($w['title'], ENT_QUOTES); ?></strong> (Low score: <?php echo (int)$w['min_score']; ?>%)</li>
                        <?php endforeach; ?>
                        
                        <?php foreach ($unsubmittedAssignments as $ua): ?>
                            <li>Pending submission: <strong><?php echo htmlspecialchars($ua['title'], ENT_QUOTES); ?></strong> (<?php echo htmlspecialchars($ua['course_title'], ENT_QUOTES); ?>)</li>
                        <?php endforeach; ?>

                        <?php if (empty($weakList) && empty($unsubmittedAssignments)): ?>
                            <li style="font-style:italic; list-style-type:none; margin-left:-16px; color:var(--text-light); font-size:12px;">No weak areas identified this week. Great work!</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Syllabus / Concept Recommendations -->
        <section class="panel">
            <h2>Syllabus-Aligned Resources</h2>
            <p style="font-size:13px; color:var(--text-secondary); margin-bottom:12px;">
                Curated material matching your Class <?php echo $class; ?> &middot; <?php echo htmlspecialchars($board, ENT_QUOTES); ?> board:
            </p>
            <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
                <div style="border:1px solid var(--border-color); border-radius:10px; padding:12px; background:var(--bg-secondary); display:flex; gap:10px; align-items:center;">
                    <div style="width:40px; height:40px; border-radius:50%; background:#e0e7ff; color:#4338ca; display:grid; place-items:center; font-size:18px;"><ion-icon name="book-outline"></ion-icon></div>
                    <div>
                        <h4 style="margin:0; font-weight:600; font-size:14px;">Math Core Reading</h4>
                        <span style="font-size:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($recs['Mathematics'], ENT_QUOTES); ?></span>
                    </div>
                </div>
                <div style="border:1px solid var(--border-color); border-radius:10px; padding:12px; background:var(--bg-secondary); display:flex; gap:10px; align-items:center;">
                    <div style="width:40px; height:40px; border-radius:50%; background:#ecfdf5; color:#047857; display:grid; place-items:center; font-size:18px;"><ion-icon name="help-circle-outline"></ion-icon></div>
                    <div>
                        <h4 style="margin:0; font-weight:600; font-size:14px;">Science Core Reading</h4>
                        <span style="font-size:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($recs['Science'], ENT_QUOTES); ?></span>
                    </div>
                </div>
                <div style="border:1px solid var(--border-color); border-radius:10px; padding:12px; background:var(--bg-secondary); display:flex; gap:10px; align-items:center;">
                    <div style="width:40px; height:40px; border-radius:50%; background:#fff7ed; color:#c2410c; display:grid; place-items:center; font-size:18px;"><ion-icon name="link-outline"></ion-icon></div>
                    <div>
                        <h4 style="margin:0; font-weight:600; font-size:14px;">Curriculum Reference</h4>
                        <span style="font-size:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($recs['notes'], ENT_QUOTES); ?></span>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div style="display:flex; flex-direction:column; gap:20px;">
        <!-- Doubt History -->
        <section class="panel">
            <h2>Doubt History Recap</h2>
            <p style="font-size:12px; color:var(--text-light); margin-bottom:12px;">Your recent questions to the AI Study Chatbot:</p>
            <?php if (empty($doubtHistory)): ?>
                <div style="font-style:italic; color:var(--text-light); font-size:12px;">No doubt queries saved. Start talking to your AI Tutor to populate details here!</div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($doubtHistory as $d): ?>
                        <div style="border-left:2px solid #667eea; padding-left:10px; margin-bottom:6px;">
                            <div style="font-size:13px; color:var(--text-secondary); font-weight:500; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                                "<?php echo htmlspecialchars($d['content'], ENT_QUOTES); ?>"
                            </div>
                            <span style="font-size:10px; color:var(--text-light);"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($d['created_at'])), ENT_QUOTES); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Recommended Videos -->
        <section class="panel">
            <h2>Recommended Videos</h2>
            <p style="font-size:12px; color:var(--text-light); margin-bottom:12px;">Curriculum aligned lectures:</p>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($videoRecommendations as $vr): ?>
                    <div style="border:1px solid var(--border-color); border-radius:10px; overflow:hidden; background:var(--bg-secondary);">
                        <div style="padding:10px;">
                            <h4 style="margin:0 0 4px; font-weight:600; font-size:13px;"><?php echo htmlspecialchars($vr['title'], ENT_QUOTES); ?></h4>
                            <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-light);">
                                <span><?php echo htmlspecialchars($vr['subject'], ENT_QUOTES); ?></span>
                                <span><?php echo htmlspecialchars($vr['course_title'] ?? '', ENT_QUOTES); ?></span>
                            </div>
                            <div style="margin-top:8px;">
                                <a href="videos.php" class="btn btn-ghost btn-sm" style="width:100%; text-align:center;"><ion-icon name="play-circle-outline"></ion-icon> Watch video</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php render_dashboard_footer(); ?>
