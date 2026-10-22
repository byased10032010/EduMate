<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// 1. Course completion & watch time
$courseStmt = $pdo->prepare('
    SELECT c.subject,
           COUNT(DISTINCT l.id) as total_topics,
           COUNT(DISTINCT lc.lesson_id) as completed_topics,
           SUM(CASE WHEN lc.lesson_id IS NOT NULL THEN l.duration_min * 60 ELSE 0 END) as total_watch_seconds
    FROM enrollments e
    JOIN courses c ON e.course_id = c.id
    LEFT JOIN lessons l ON c.id = l.course_id
    LEFT JOIN lesson_completions lc ON l.id = lc.lesson_id AND lc.student_id = :uid1
    WHERE e.student_id = :uid2
    GROUP BY c.subject
');
$courseStmt->execute([':uid1' => $uid, ':uid2' => $uid]);
$coursesData = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Quiz performance (includes AI Quizzes)
$quizStmt = $pdo->prepare('
    SELECT COALESCE(c.subject, qz.subject) as subject,
           AVG(CASE WHEN qa.total > 0 THEN (qa.score / qa.total) * 100 ELSE NULL END) as avg_quiz_score,
           SUM(CASE WHEN qa.total > 0 THEN qa.total - qa.score ELSE 0 END) as total_wrong_answers,
           SUM(qa.total) as total_questions
    FROM quiz_attempts qa
    JOIN quizzes qz ON qa.quiz_id = qz.id
    LEFT JOIN courses c ON qz.course_id = c.id
    WHERE qa.student_id = :uid
    GROUP BY COALESCE(c.subject, qz.subject)
');
$quizStmt->execute([':uid' => $uid]);
$quizzesData = $quizStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Assignment performance
$assignStmt = $pdo->prepare('
    SELECT c.subject,
           COUNT(DISTINCT s.id) as submitted_assignments,
           AVG(s.grade) as avg_assignment_grade
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN courses c ON a.course_id = c.id
    WHERE s.student_id = :uid
    GROUP BY c.subject
');
$assignStmt->execute([':uid' => $uid]);
$assignData = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

// Merge into $subjectStats
$subjectMap = [];
foreach ($coursesData as $row) {
    $subjectMap[$row['subject']] = $row;
}
foreach ($quizzesData as $row) {
    if (!isset($subjectMap[$row['subject']])) {
        $subjectMap[$row['subject']] = ['subject' => $row['subject'], 'total_topics' => 0, 'completed_topics' => 0, 'total_watch_seconds' => 0];
    }
    $subjectMap[$row['subject']]['avg_quiz_score'] = $row['avg_quiz_score'];
    $subjectMap[$row['subject']]['total_wrong_answers'] = $row['total_wrong_answers'];
    $subjectMap[$row['subject']]['total_questions'] = $row['total_questions'];
}
foreach ($assignData as $row) {
    if (!isset($subjectMap[$row['subject']])) {
        $subjectMap[$row['subject']] = ['subject' => $row['subject'], 'total_topics' => 0, 'completed_topics' => 0, 'total_watch_seconds' => 0];
    }
    $subjectMap[$row['subject']]['submitted_assignments'] = $row['submitted_assignments'];
    $subjectMap[$row['subject']]['avg_assignment_grade'] = $row['avg_assignment_grade'];
}

$subjectStats = array_values($subjectMap);

// Calculate overallStats manually to avoid complex DB joins
$overallStats = [
    'total_courses' => 0,
    'total_topics' => 0,
    'total_completed_lessons' => 0,
    'overall_avg_quiz_score' => 0,
    'total_wrong_answers' => 0,
    'total_questions' => 0,
    'total_watch_seconds' => 0,
    'submitted_assignments' => 0,
    'avg_assignment_grade' => 0
];

$courseCountStmt = $pdo->prepare('SELECT COUNT(DISTINCT course_id) FROM enrollments WHERE student_id = :uid');
$courseCountStmt->execute([':uid' => $uid]);
$overallStats['total_courses'] = $courseCountStmt->fetchColumn();

$quizSum = 0;
$quizCount = 0;
$assignSum = 0;
$assignCount = 0;

foreach ($subjectStats as $stat) {
    $overallStats['total_topics'] += $stat['total_topics'] ?? 0;
    $overallStats['total_completed_lessons'] += $stat['completed_topics'] ?? 0;
    $overallStats['total_wrong_answers'] += $stat['total_wrong_answers'] ?? 0;
    $overallStats['total_questions'] += $stat['total_questions'] ?? 0;
    $overallStats['total_watch_seconds'] += $stat['total_watch_seconds'] ?? 0;
    $overallStats['submitted_assignments'] += $stat['submitted_assignments'] ?? 0;
    
    if (isset($stat['avg_quiz_score']) && $stat['avg_quiz_score'] !== null) {
        $quizSum += $stat['avg_quiz_score'];
        $quizCount++;
    }
    if (isset($stat['avg_assignment_grade']) && $stat['avg_assignment_grade'] !== null) {
        $assignSum += $stat['avg_assignment_grade'];
        $assignCount++;
    }
}
if ($quizCount > 0) $overallStats['overall_avg_quiz_score'] = $quizSum / $quizCount;
if ($assignCount > 0) $overallStats['avg_assignment_grade'] = $assignSum / $assignCount;

// Determine student level based on performance
function getStudentLevel(float $avgScore): string {
    if ($avgScore >= 85) return 'advanced';
    if ($avgScore >= 60) return 'intermediate';
    return 'beginner';
}

// Get wrong percentage
function getWrongPercentage(int $wrong, int $total): float {
    if ($total === 0) return 0;
    return round(($wrong / $total) * 100, 1);
}

// Class-wise book suggestions for 5-10 with different levels
$bookSuggestions = [
    'Mathematics' => [
        'beginner' => [
            ['title' => 'NCERT Mathematics', 'author' => 'NCERT', 'reason' => 'Perfect for building strong fundamentals', 'classes' => '5-10'],
            ['title' => 'Mathematics for Everyone', 'author' => 'RS Aggarwal', 'reason' => 'Simple explanations with plenty of examples', 'classes' => '5-8'],
            ['title' => 'Foundation Mathematics', 'author' => 'Pearson', 'reason' => 'Step-by-step learning for beginners', 'classes' => '5-10']
        ],
        'intermediate' => [
            ['title' => 'Mathematics by RD Sharma', 'author' => 'RD Sharma', 'reason' => 'Excellent practice problems for school exams', 'classes' => '6-10'],
            ['title' => 'Secondary School Mathematics', 'author' => 'ML Aggarwal', 'reason' => 'Comprehensive coverage with varied difficulty', 'classes' => '6-10'],
            ['title' => 'Mathematics Olympiad Basics', 'author' => 'MTG', 'reason' => 'Great for developing problem-solving skills', 'classes' => '5-10']
        ],
        'advanced' => [
            ['title' => 'Challenge & Thrill of Pre-College Mathematics', 'author' => 'V Krishnamurthy', 'reason' => 'Advanced problem-solving for bright students', 'classes' => '8-10'],
            ['title' => 'Mathematics Olympiad', 'author' => 'TIFR', 'reason' => 'Competitive exam preparation', 'classes' => '8-10'],
            ['title' => 'Problem Primer for the Olympiad', 'author' => 'C R Pranesachar', 'reason' => 'Develops mathematical thinking', 'classes' => '8-10']
        ]
    ],
    'Science' => [
        'beginner' => [
            ['title' => 'NCERT Science', 'author' => 'NCERT', 'reason' => 'Essential for building scientific concepts', 'classes' => '5-10'],
            ['title' => 'Everyday Science', 'author' => 'Lakhmir Singh', 'reason' => 'Simple and engaging explanations', 'classes' => '5-8'],
            ['title' => 'Science Explorer', 'author' => 'Pearson', 'reason' => 'Interesting facts and experiments', 'classes' => '5-10']
        ],
        'intermediate' => [
            ['title' => 'Science by Lakhmir Singh', 'author' => 'Lakhmir Singh & Manjit Kaur', 'reason' => 'Detailed explanations with diagrams', 'classes' => '6-10'],
            ['title' => 'Foundation Science', 'author' => 'HC Verma', 'reason' => 'Strong foundation in science concepts', 'classes' => '7-10'],
            ['title' => 'Science for Everyone', 'author' => 'Arihant', 'reason' => 'Practice-based learning approach', 'classes' => '6-10']
        ],
        'advanced' => [
            ['title' => 'Science Olympiad Foundation', 'author' => 'SOF', 'reason' => 'Advanced scientific concepts and applications', 'classes' => '8-10'],
            ['title' => 'Foundation Course in Science', 'author' => 'Pearson', 'reason' => 'Prepares for competitive exams', 'classes' => '8-10'],
            ['title' => 'Advanced Science Problems', 'author' => 'MTG', 'reason' => 'Challenging problems for deep understanding', 'classes' => '8-10']
        ]
    ],
    'Physics' => [
        'beginner' => [
            ['title' => 'NCERT Physics', 'author' => 'NCERT', 'reason' => 'Best for understanding basic concepts', 'classes' => '9-10'],
            ['title' => 'Foundation Physics', 'author' => 'HC Verma', 'reason' => 'Simple introduction to physics', 'classes' => '8-10'],
            ['title' => 'Physics for Beginners', 'author' => 'Lakhmir Singh', 'reason' => 'Easy to understand with examples', 'classes' => '8-10']
        ],
        'intermediate' => [
            ['title' => 'Physics by Lakhmir Singh', 'author' => 'Lakhmir Singh & Manjit Kaur', 'reason' => 'Comprehensive coverage for class 9-10', 'classes' => '9-10'],
            ['title' => 'Understanding Physics', 'author' => 'DC Pandey', 'reason' => 'Foundation series for strong basics', 'classes' => '9-10'],
            ['title' => 'Physics Foundation', 'author' => 'Arihant', 'reason' => 'Good for building concepts', 'classes' => '9-10']
        ],
        'advanced' => [
            ['title' => 'Foundation Physics for Olympiad', 'author' => 'HC Verma', 'reason' => 'Advanced problems for physics enthusiasts', 'classes' => '9-10'],
            ['title' => 'Physics Olympiad', 'author' => 'SOF', 'reason' => 'Competitive exam preparation', 'classes' => '9-10'],
            ['title' => 'Challenging Physics Problems', 'author' => 'MTG', 'reason' => 'Develops analytical thinking', 'classes' => '9-10']
        ]
    ],
    'Chemistry' => [
        'beginner' => [
            ['title' => 'NCERT Chemistry', 'author' => 'NCERT', 'reason' => 'Essential for basic chemistry concepts', 'classes' => '9-10'],
            ['title' => 'Chemistry for Beginners', 'author' => 'Lakhmir Singh', 'reason' => 'Simple explanations with experiments', 'classes' => '9-10'],
            ['title' => 'Foundation Chemistry', 'author' => 'Pearson', 'reason' => 'Builds strong fundamentals', 'classes' => '9-10']
        ],
        'intermediate' => [
            ['title' => 'Chemistry by Lakhmir Singh', 'author' => 'Lakhmir Singh & Manjit Kaur', 'reason' => 'Detailed coverage of class 9-10 syllabus', 'classes' => '9-10'],
            ['title' => 'Foundation Chemistry', 'author' => 'OP Tandon', 'reason' => 'Good for conceptual understanding', 'classes' => '9-10'],
            ['title' => 'Chemistry Foundation', 'author' => 'Arihant', 'reason' => 'Practice-oriented approach', 'classes' => '9-10']
        ],
        'advanced' => [
            ['title' => 'Chemistry Olympiad Foundation', 'author' => 'SOF', 'reason' => 'Advanced chemistry concepts', 'classes' => '9-10'],
            ['title' => 'Foundation Course in Chemistry', 'author' => 'Pearson', 'reason' => 'Prepares for higher studies', 'classes' => '9-10'],
            ['title' => 'Challenging Chemistry Problems', 'author' => 'MTG', 'reason' => 'Develops chemical thinking', 'classes' => '9-10']
        ]
    ],
    'Biology' => [
        'beginner' => [
            ['title' => 'NCERT Biology', 'author' => 'NCERT', 'reason' => 'Best for understanding biology basics', 'classes' => '9-10'],
            ['title' => 'Biology for Beginners', 'author' => 'Lakhmir Singh', 'reason' => 'Simple explanations with diagrams', 'classes' => '9-10'],
            ['title' => 'Foundation Biology', 'author' => 'Pearson', 'reason' => 'Builds strong foundation', 'classes' => '9-10']
        ],
        'intermediate' => [
            ['title' => 'Biology by Lakhmir Singh', 'author' => 'Lakhmir Singh & Manjit Kaur', 'reason' => 'Comprehensive for class 9-10', 'classes' => '9-10'],
            ['title' => 'Foundation Biology', 'author' => 'Trueman', 'reason' => 'Good for conceptual clarity', 'classes' => '9-10'],
            ['title' => 'Biology Foundation', 'author' => 'Arihant', 'reason' => 'Practice-based learning', 'classes' => '9-10']
        ],
        'advanced' => [
            ['title' => 'Biology Olympiad Foundation', 'author' => 'SOF', 'reason' => 'Advanced biology concepts', 'classes' => '9-10'],
            ['title' => 'Foundation Course in Biology', 'author' => 'Pearson', 'reason' => 'Prepares for competitive exams', 'classes' => '9-10'],
            ['title' => 'Challenging Biology Problems', 'author' => 'MTG', 'reason' => 'Develops biological reasoning', 'classes' => '9-10']
        ]
    ],
    'English' => [
        'beginner' => [
            ['title' => 'NCERT English', 'author' => 'NCERT', 'reason' => 'Essential for English language skills', 'classes' => '5-10'],
            ['title' => 'English Grammar & Composition', 'author' => 'Wren & Martin', 'reason' => 'Best for grammar basics', 'classes' => '5-10'],
            ['title' => 'English Reader', 'author' => 'Pearson', 'reason' => 'Interesting stories and poems', 'classes' => '5-10']
        ],
        'intermediate' => [
            ['title' => 'High School English Grammar', 'author' => 'Wren & Martin', 'reason' => 'Comprehensive grammar guide', 'classes' => '6-10'],
            ['title' => 'English Literature Reader', 'author' => 'CBSE', 'reason' => 'Classic literature for students', 'classes' => '6-10'],
            ['title' => 'Writing Skills', 'author' => 'Arihant', 'reason' => 'Improves creative writing', 'classes' => '6-10']
        ],
        'advanced' => [
            ['title' => 'Advanced English Grammar', 'author' => 'Wren & Martin', 'reason' => 'Advanced grammar concepts', 'classes' => '8-10'],
            ['title' => 'English Olympiad', 'author' => 'SOF', 'reason' => 'Competitive English preparation', 'classes' => '8-10'],
            ['title' => 'Creative Writing', 'author' => 'Pearson', 'reason' => 'Develops advanced writing skills', 'classes' => '8-10']
        ]
    ]
];

// Learning pattern analysis based on data
function analyzeLearningPattern($stats, $overallStats): array {
    $avgScore = (float)($stats['avg_quiz_score'] ?? $overallStats['overall_avg_quiz_score'] ?? 0);
    $totalTopics = (int)($stats['total_topics'] ?? 0);
    $completedTopics = (int)($stats['completed_topics'] ?? 0);
    $completion = $totalTopics > 0 ? round(($completedTopics / $totalTopics) * 100) : 0;
    $watchSeconds = (int)($stats['total_watch_seconds'] ?? 0);
    $wrongPercentage = getWrongPercentage(
        (int)($stats['total_wrong_answers'] ?? 0),
        (int)($stats['total_questions'] ?? 0)
    );
    
    $pattern = [
        'type' => '',
        'description' => '',
        'strengths' => [],
        'improvements' => [],
        'brain_map' => [],
        'thought_process' => []
    ];
    
    // Determine learning type
    if ($completion >= 70 && $avgScore >= 70) {
        $pattern['type'] = 'Balanced Learner';
        $pattern['description'] = 'You maintain a good balance between understanding concepts and completing coursework.';
    } elseif ($completion >= 80 && $avgScore < 60) {
        $pattern['type'] = 'Quick Completer';
        $pattern['description'] = 'You finish lessons quickly but need to focus more on understanding and retention.';
    } elseif ($completion < 40 && $avgScore >= 80) {
        $pattern['type'] = 'Deep Thinker';
        $pattern['description'] = 'You understand concepts deeply but take more time to complete the curriculum.';
    } else {
        $pattern['type'] = 'Developing Learner';
        $pattern['description'] = 'You are building your learning style - focus on both completion and understanding.';
    }
    
    // Strengths
    if ($completion >= 70) {
        $pattern['strengths'][] = 'Excellent time management and course completion';
    }
    if ($avgScore >= 70) {
        $pattern['strengths'][] = 'Strong conceptual understanding';
    }
    if ($watchSeconds >= 7200) { // 2 hours
        $pattern['strengths'][] = 'High engagement with video content';
    }
    
    // Areas for improvement
    if ($wrongPercentage >= 40) {
        $pattern['improvements'][] = 'Need more practice to reduce errors';
    }
    if ($completion < 50) {
        $pattern['improvements'][] = 'Focus on completing more lessons';
    }
    if ($avgScore < 60) {
        $pattern['improvements'][] = 'Work on understanding concepts better';
    }
    
    // Brain map analysis
    $pattern['brain_map'] = [
        'memory_retention' => $avgScore >= 70 ? 'Strong' : ($avgScore >= 50 ? 'Developing' : 'Needs Work'),
        'problem_solving' => $wrongPercentage <= 30 ? 'Excellent' : ($wrongPercentage <= 50 ? 'Good' : 'Needs Practice'),
        'speed_learning' => $completion >= 70 ? 'Fast' : ($completion >= 40 ? 'Moderate' : 'Steady'),
        'attention_span' => $watchSeconds >= 7200 ? 'Long' : ($watchSeconds >= 3600 ? 'Medium' : 'Building'),
        'conceptual_understanding' => $avgScore >= 75 ? 'Deep' : ($avgScore >= 50 ? 'Growing' : 'Foundational')
    ];
    
    // Thought process analysis
    if ($wrongPercentage >= 50) {
        $pattern['thought_process'][] = 'You may be rushing through problems - take time to read questions carefully';
    }
    if ($avgScore < 50 && $completion >= 70) {
        $pattern['thought_process'][] = 'You might be memorizing instead of understanding - focus on concepts';
    }
    if ($avgScore >= 70 && $wrongPercentage <= 30) {
        $pattern['thought_process'][] = 'You have a methodical approach - keep up the good work!';
    }
    if ($completion < 50 && $avgScore >= 70) {
        $pattern['thought_process'][] = 'You think deeply about topics - try to maintain this while increasing pace';
    }
    
    // Default insights if none specific
    if (empty($pattern['thought_process'])) {
        $pattern['thought_process'][] = 'Your learning approach is developing steadily';
        $pattern['thought_process'][] = 'Focus on connecting new concepts with what you already know';
    }
    
    if (empty($pattern['strengths'])) {
        $pattern['strengths'][] = 'You are on your learning journey - keep going!';
    }
    
    if (empty($pattern['improvements'])) {
        $pattern['improvements'][] = 'Maintain your current momentum';
    }
    
    return $pattern;
}

$overallPattern = analyzeLearningPattern($overallStats, $overallStats);

render_dashboard_header(
    'AI Suggestions',
    student_nav(),
    'ai-suggestions',
    ['Home', 'AI Suggestions']
);
?>

<!-- Overall Stats -->
<section class="cards" style="margin-bottom: 24px;">
    <div class="card">
        <div class="ico"><ion-icon name="book-outline"></ion-icon></div>
        <div class="label">Courses Enrolled</div>
        <div class="value"><?php echo (int)($overallStats['total_courses'] ?? 0); ?></div>
    </div>
    <div class="card s2">
        <div class="ico"><ion-icon name="checkmark-circle-outline"></ion-icon></div>
        <div class="label">Lessons Completed</div>
        <div class="value"><?php echo (int)($overallStats['total_completed_lessons'] ?? 0); ?></div>
    </div>
    <div class="card s3">
        <div class="ico"><ion-icon name="stats-chart-outline"></ion-icon></div>
        <div class="label">Avg Quiz Score</div>
        <div class="value"><?php echo (int)round((float)($overallStats['overall_avg_quiz_score'] ?? 0)); ?>%</div>
    </div>
    <div class="card s4">
        <div class="ico"><ion-icon name="close-circle-outline"></ion-icon></div>
        <div class="label">Wrong Answers</div>
        <div class="value"><?php 
            $totalWrong = array_sum(array_column($subjectStats, 'total_wrong_answers'));
            $totalQuestions = array_sum(array_column($subjectStats, 'total_questions'));
            echo getWrongPercentage($totalWrong, $totalQuestions);
        ?>%</div>
    </div>
</section>

<div class="panel-grid">
    <!-- Left Column: Subject Analysis & Books -->
    <div>
        <section class="panel">
            <h2><ion-icon name="flask-outline" style="color: var(--gradient-primary); margin-right: 8px;"></ion-icon> Subject-wise Analysis</h2>
            
            <?php if (empty($subjectStats)): ?>
                <div style="text-align: center; padding: 40px;">
                    <ion-icon name="book-outline" style="font-size: 48px; color: var(--border-strong); margin-bottom: 16px;"></ion-icon>
                    <h3 style="color: var(--text-light); margin: 0 0 8px 0;">No subject data yet</h3>
                    <p style="color: var(--text-light); margin: 0;">Enroll in courses to see personalized analysis</p>
                </div>
            <?php else: ?>
                <?php foreach ($subjectStats as $subject): ?>
                    <?php 
                    $completion = $subject['total_topics'] > 0 ? round(($subject['completed_topics'] / $subject['total_topics']) * 100) : 0;
                    $quizScore = round((float)($subject['avg_quiz_score'] ?? 0));
                    $watchHours = round((int)($subject['total_watch_seconds'] ?? 0) / 3600, 1);
                    $wrongPercentage = getWrongPercentage(
                        (int)($subject['total_wrong_answers'] ?? 0),
                        (int)($subject['total_questions'] ?? 0)
                    );
                    $studentLevel = getStudentLevel((float)$quizScore);
                    
                    // Determine subject color
                    $colors = [
                        'Physics' => 'var(--gradient-primary)',
                        'Chemistry' => 'var(--gradient-secondary)',
                        'Mathematics' => 'var(--gradient-info)',
                        'Science' => 'var(--gradient-success)',
                        'Biology' => 'var(--gradient-success)',
                        'English' => 'var(--gradient-warning)'
                    ];
                    $color = $colors[$subject['subject']] ?? 'var(--gradient-primary)';
                    ?>
                    <div class="profile-card" style="margin-bottom: 16px;">
                        <div class="profile-card__top">
                            <div class="profile-card__avatar" style="background: <?php echo $color; ?>; color: #fff;">
                                <ion-icon name="flask-outline"></ion-icon>
                            </div>
                            <div class="profile-card__header">
                                <div class="profile-card__name"><?php echo htmlspecialchars($subject['subject'], ENT_QUOTES); ?></div>
                                <div class="profile-card__meta">
                                    <span class="tag <?php echo $completion >= 70 ? 'success' : ($completion >= 40 ? 'warn' : ''); ?>">
                                        <?php echo $completion; ?>% Complete
                                    </span>
                                    <span class="tag <?php echo $wrongPercentage <= 30 ? 'success' : ($wrongPercentage <= 50 ? 'warn' : ''); ?>">
                                        <?php echo $wrongPercentage; ?>% Wrong
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 12px; text-align: center;">
                            <div>
                                <div style="font-size: 20px; font-weight: 700;"><?php echo $completion; ?>%</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; font-weight: 600;">Completion</div>
                            </div>
                            <div>
                                <div style="font-size: 20px; font-weight: 700;"><?php echo $quizScore; ?>%</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; font-weight: 600;">Quiz Avg</div>
                            </div>
                            <div>
                                <div style="font-size: 20px; font-weight: 700;"><?php echo $watchHours; ?>h</div>
                                <div style="font-size: 11px; color: var(--text-light); text-transform: uppercase; font-weight: 600;">Watch Time</div>
                            </div>
                        </div>
                        
                        <div style="height: 6px; background: var(--bg-secondary); border-radius: 6px; overflow: hidden; margin-top: 8px;">
                            <div style="width: <?php echo $completion; ?>%; background: <?php echo $color; ?>; height: 100%;"></div>
                        </div>
                        
                        <div style="margin-top: 12px;">
                            <span class="tag info"><ion-icon name="school-outline"></ion-icon> <?php echo ucfirst($studentLevel); ?> Level</span>
                        </div>
                        
                        <?php if (isset($bookSuggestions[$subject['subject']]) && isset($bookSuggestions[$subject['subject']][$studentLevel])): ?>
                            <div style="margin-top: 16px;">
                                <h4 style="font-size: 14px; margin-bottom: 12px;"><ion-icon name="book-outline" style="color: <?php echo $color; ?>;"></ion-icon> Recommended Books (<?php echo ucfirst($studentLevel); ?>)</h4>
                                <?php foreach ($bookSuggestions[$subject['subject']][$studentLevel] as $book): ?>
                                    <div class="profile-card__line" style="flex-direction: column; align-items: flex-start; gap: 4px; margin-bottom: 8px;">
                                        <div style="font-weight: 600; color: var(--text-primary); font-size: 14px;"><?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?></div>
                                        <div style="font-size: 12px; color: var(--text-light);">by <?php echo htmlspecialchars($book['author'], ENT_QUOTES); ?></div>
                                        <div style="font-size: 12px; color: var(--text-primary); margin-top: 4px;"><ion-icon name="bulb-outline" style="color: var(--gradient-warning);"></ion-icon> <?php echo htmlspecialchars($book['reason'], ENT_QUOTES); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
    
    <!-- Right Column: Learning Pattern & Analysis -->
    <div>
        <section class="panel">
            <h2><ion-icon name="hardware-chip-outline" style="color: var(--gradient-primary); margin-right: 8px;"></ion-icon> Learning Pattern Analysis</h2>
            
            <div class="profile-card" style="margin-bottom: 20px; background: var(--gradient-primary); color: #fff;">
                <h3 style="margin-bottom: 8px; color: #fff;"><ion-icon name="person-circle-outline" style="margin-right: 8px;"></ion-icon> <?php echo $overallPattern['type']; ?></h3>
                <p style="opacity: 0.9; font-size: 14px;"><?php echo $overallPattern['description']; ?></p>
            </div>
            
            <h4 style="font-size: 14px; margin-bottom: 12px;"><ion-icon name="git-network-outline" style="color: var(--gradient-primary);"></ion-icon> Your Learning Brain Map</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 10px; margin-bottom: 24px;">
                <div class="profile-card__line" style="flex-direction: column; text-align: center; padding: 12px 8px;">
                    <ion-icon name="save-outline" style="font-size: 20px; color: var(--gradient-info); margin-bottom: 4px;"></ion-icon>
                    <span style="font-size: 10px;">Memory</span>
                    <span style="font-size: 12px; font-weight: 700;"><?php echo $overallPattern['brain_map']['memory_retention']; ?></span>
                </div>
                <div class="profile-card__line" style="flex-direction: column; text-align: center; padding: 12px 8px;">
                    <ion-icon name="extension-puzzle-outline" style="font-size: 20px; color: var(--gradient-warning); margin-bottom: 4px;"></ion-icon>
                    <span style="font-size: 10px;">Problem Solving</span>
                    <span style="font-size: 12px; font-weight: 700;"><?php echo $overallPattern['brain_map']['problem_solving']; ?></span>
                </div>
                <div class="profile-card__line" style="flex-direction: column; text-align: center; padding: 12px 8px;">
                    <ion-icon name="flash-outline" style="font-size: 20px; color: var(--gradient-secondary); margin-bottom: 4px;"></ion-icon>
                    <span style="font-size: 10px;">Speed</span>
                    <span style="font-size: 12px; font-weight: 700;"><?php echo $overallPattern['brain_map']['speed_learning']; ?></span>
                </div>
                <div class="profile-card__line" style="flex-direction: column; text-align: center; padding: 12px 8px;">
                    <ion-icon name="eye-outline" style="font-size: 20px; color: var(--gradient-success); margin-bottom: 4px;"></ion-icon>
                    <span style="font-size: 10px;">Attention</span>
                    <span style="font-size: 12px; font-weight: 700;"><?php echo $overallPattern['brain_map']['attention_span']; ?></span>
                </div>
                <div class="profile-card__line" style="flex-direction: column; text-align: center; padding: 12px 8px;">
                    <ion-icon name="bulb-outline" style="font-size: 20px; color: var(--gradient-primary); margin-bottom: 4px;"></ion-icon>
                    <span style="font-size: 10px;">Understanding</span>
                    <span style="font-size: 12px; font-weight: 700;"><?php echo $overallPattern['brain_map']['conceptual_understanding']; ?></span>
                </div>
            </div>
            
            <h4 style="font-size: 14px; margin-bottom: 12px;"><ion-icon name="chatbubble-ellipses-outline" style="color: var(--gradient-secondary);"></ion-icon> Your Thought Process</h4>
            <div style="margin-bottom: 24px;">
                <?php foreach ($overallPattern['thought_process'] as $index => $thought): ?>
                    <div class="profile-card__line" style="flex-direction: column; align-items: flex-start; margin-bottom: 8px;">
                        <div style="font-weight: 700; font-size: 13px; color: var(--text-primary);">
                            <ion-icon name="chatbubble-outline" style="color: var(--gradient-primary); margin-right: 4px;"></ion-icon> Insight <?php echo $index + 1; ?>
                        </div>
                        <div style="font-size: 13px; color: var(--text-light); margin-top: 4px;"><?php echo htmlspecialchars($thought, ENT_QUOTES); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <h4 style="font-size: 14px; margin-bottom: 12px;"><ion-icon name="star-outline" style="color: var(--gradient-warning);"></ion-icon> Strengths & Growth Areas</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px;">
                <div>
                    <h5 style="font-size: 12px; text-transform: uppercase; margin-bottom: 8px; color: var(--text-light);">Your Strengths</h5>
                    <?php foreach ($overallPattern['strengths'] as $strength): ?>
                        <div style="display: flex; gap: 8px; font-size: 12px; margin-bottom: 6px;">
                            <ion-icon name="checkmark-circle-outline" style="color: var(--gradient-success); margin-top: 2px;"></ion-icon>
                            <span><?php echo htmlspecialchars($strength, ENT_QUOTES); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <h5 style="font-size: 12px; text-transform: uppercase; margin-bottom: 8px; color: var(--text-light);">Areas to Grow</h5>
                    <?php foreach ($overallPattern['improvements'] as $improvement): ?>
                        <div style="display: flex; gap: 8px; font-size: 12px; margin-bottom: 6px;">
                            <ion-icon name="arrow-up-outline" style="color: var(--gradient-warning); margin-top: 2px;"></ion-icon>
                            <span><?php echo htmlspecialchars($improvement, ENT_QUOTES); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <h4 style="font-size: 14px; margin-bottom: 12px;"><ion-icon name="clipboard-outline" style="color: var(--gradient-success);"></ion-icon> Personalized Study Plan</h4>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div class="profile-card__line">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(102, 126, 234, 0.1); color: var(--gradient-primary); display: grid; place-items: center;"><ion-icon name="time-outline"></ion-icon></div>
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">Daily Study Time</div>
                            <div style="font-size: 12px; color: var(--text-light);">Aim for 3-5 hours of focused study with 10-min breaks</div>
                        </div>
                    </div>
                </div>
                <div class="profile-card__line">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(16, 185, 129, 0.1); color: var(--gradient-success); display: grid; place-items: center;"><ion-icon name="checkmark-done-outline"></ion-icon></div>
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">Practice Problems</div>
                            <div style="font-size: 12px; color: var(--text-light);">Solve 15-25 problems daily from recommended books</div>
                        </div>
                    </div>
                </div>
                <div class="profile-card__line">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(245, 158, 11, 0.1); color: var(--gradient-warning); display: grid; place-items: center;"><ion-icon name="help-circle-outline"></ion-icon></div>
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">Weekly Quizzes</div>
                            <div style="font-size: 12px; color: var(--text-light);">Take 1-2 quizzes per subject weekly</div>
                        </div>
                    </div>
                </div>
                <div class="profile-card__line">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); color: var(--gradient-secondary); display: grid; place-items: center;"><ion-icon name="sync-outline"></ion-icon></div>
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">Revision Strategy</div>
                            <div style="font-size: 12px; color: var(--text-light);">Revise topics every 2-3 days and create notes</div>
                        </div>
                    </div>
                </div>
                <div class="profile-card__line">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(139, 92, 246, 0.1); color: var(--gradient-primary); display: grid; place-items: center;"><ion-icon name="reader-outline"></ion-icon></div>
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">Book Reading</div>
                            <div style="font-size: 12px; color: var(--text-light);">Read at least 1 chapter weekly</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
<?php render_dashboard_footer(); ?>

