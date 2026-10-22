<?php
/**
 * EduMate - Sample data seeder.
 *
 * Adds demo teacher/student accounts, courses, lessons, quizzes, videos,
 * and a small amount of activity/progress data so the dashboards have
 * something to show.
 *
 * Safe to re-run (uses INSERT IGNORE / ON DUPLICATE KEY UPDATE patterns).
 *
 * Run:  php scripts/seed.php
 * Or:   browse to /eduMate/scripts/seed.php   (then delete this file)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// === Demo accounts ===
$demo = [
    'admin' => [
        'admin@edumate.local',
        'Admin@12345',
        'System Admin',
    ],
    'teacher_math' => [
        'ananya.rao@edumate.local',
        'Teacher@123',
        'Ananya Rao',
    ],
    'teacher_science' => [
        'vikram.iyer@edumate.local',
        'Teacher@123',
        'Vikram Iyer',
    ],
    'student_demo' => [
        'subhrajit@edumate.local',
        'Student@123',
        'Subhrajit Halder',
    ],
];

$pdo = db();

// Upsert users
$userIds = [];
foreach ($demo as $key => [$email, $pw, $name]) {
    $role = $key === 'admin' ? 'admin' : (str_starts_with($key, 'teacher_') ? 'teacher' : 'student');
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    
    $class = null;
    $board = null;
    $streak = 0;
    $lastActiveDate = null;
    if ($role === 'student') {
        $class = 8;
        $board = 'CBSE';
        $streak = 12;
        $lastActiveDate = date('Y-m-d', strtotime('yesterday'));
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role, class, board, streak_count, last_active_date)
         VALUES (:n, :e, :p, :r, :cl, :bo, :st, :la)
         ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name),
            password_hash = VALUES(password_hash),
            class = VALUES(class),
            board = VALUES(board),
            streak_count = VALUES(streak_count),
            last_active_date = VALUES(last_active_date),
            is_active = 1'
    );
    $stmt->execute([
        ':n' => $name, 
        ':e' => $email, 
        ':p' => $hash, 
        ':r' => $role,
        ':cl' => $class,
        ':bo' => $board,
        ':st' => $streak,
        ':la' => $lastActiveDate
    ]);
    $userIds[$key] = (int)$pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($email))->fetchColumn();
}
echo "Users ready:\n";
foreach ($userIds as $k => $id) echo "  $k -> id=$id\n";

// === Courses ===
$teacherMathId    = $userIds['teacher_math'];
$teacherScienceId = $userIds['teacher_science'];
$studentId        = $userIds['student_demo'];

$courses = [
    [
        'id'          => 'math-101',
        'teacher_id'  => $teacherMathId,
        'title'       => 'Algebra Foundations',
        'subject'     => 'Mathematics',
        'level'       => 'beginner',
        'description' => 'Master linear equations, inequalities, and the building blocks of algebra.',
        'cover_color' => '#667eea',
        'class'       => 8,
        'board'       => 'CBSE'
    ],
    [
        'id'          => 'math-201',
        'teacher_id'  => $teacherMathId,
        'title'       => 'Calculus I: Limits & Derivatives',
        'subject'     => 'Mathematics',
        'level'       => 'intermediate',
        'description' => 'Take the limit, then take the derivative. A gentle intro to calculus.',
        'cover_color' => '#764ba2',
        'class'       => 10,
        'board'       => 'ICSE'
    ],
    [
        'id'          => 'sci-101',
        'teacher_id'  => $teacherScienceId,
        'title'       => 'Physics: Motion & Forces',
        'subject'     => 'Physics',
        'level'       => 'beginner',
        'description' => 'Newton\'s laws, kinematics, and how to not get pushed around by physics.',
        'cover_color' => '#4facfe',
        'class'       => 9,
        'board'       => 'CBSE'
    ],
    [
        'id'          => 'sci-201',
        'teacher_id'  => $teacherScienceId,
        'title'       => 'Chemistry: The Atom',
        'subject'     => 'Chemistry',
        'level'       => 'intermediate',
        'description' => 'Protons, neutrons, electrons, and the periodic table explained.',
        'cover_color' => '#43e97b',
        'class'       => 9,
        'board'       => 'ICSE'
    ],
    [
        'id'          => 'cs-101',
        'teacher_id'  => $teacherMathId,
        'title'       => 'Intro to Programming with Python',
        'subject'     => 'Computer Science',
        'level'       => 'beginner',
        'description' => 'Your first program in 30 minutes. Variables, loops, and functions.',
        'cover_color' => '#f5576c',
        'class'       => 8,
        'board'       => 'CBSE'
    ],
];
$courseIds = [];
foreach ($courses as $c) {
    $pdo->prepare(
        'INSERT INTO courses (teacher_id, title, subject, level, description, cover_color, class, board)
         VALUES (:t, :ti, :s, :l, :d, :c, :cl, :bo)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            cover_color = VALUES(cover_color),
            class = VALUES(class),
            board = VALUES(board),
            is_published = 1'
    )->execute([
        ':t' => $c['teacher_id'], ':ti' => $c['title'], ':s' => $c['subject'],
        ':l' => $c['level'], ':d' => $c['description'], ':c' => $c['cover_color'],
        ':cl' => $c['class'], ':bo' => $c['board']
    ]);
    $courseIds[$c['id']] = (int)$pdo->query("SELECT id FROM courses WHERE teacher_id = " . (int)$c['teacher_id'] . " AND title = " . $pdo->quote($c['title']))->fetchColumn();
}
echo "Courses ready: " . count($courseIds) . "\n";

// === Lessons ===
$lessonsByCourse = [
    'math-101' => [
        ['Variables & Expressions', 'Learn what variables are and how to combine them.', 12],
        ['Solving Linear Equations', 'Find x with confidence.', 18],
        ['Inequalities', 'When "=" becomes ">" or "<".', 14],
        ['Word Problems', 'Translate English into algebra.', 20],
    ],
    'math-201' => [
        ['What is a Limit?', 'Approach without arriving.', 16],
        ['Derivative Basics', 'The slope of the tangent line.', 18],
        ['Rules of Differentiation', 'Product, quotient, chain.', 22],
    ],
    'sci-101' => [
        ['Position, Velocity, Acceleration', 'The three musketeers of motion.', 15],
        ['Newton\'s First Law', 'Objects in motion stay in motion.', 10],
        ['Newton\'s Second Law (F=ma)', 'Force equals mass times acceleration.', 14],
    ],
    'sci-201' => [
        ['Atomic Structure', 'A guided tour of the atom.', 12],
        ['The Periodic Table', 'Rows, columns, and what they mean.', 16],
    ],
    'cs-101' => [
        ['Hello, World!', 'Your first Python program.', 8],
        ['Variables & Types', 'Numbers, strings, lists.', 12],
        ['Loops & Conditionals', 'Make decisions and repeat.', 16],
    ],
];
$lessonIds = [];
foreach ($lessonsByCourse as $courseKey => $lessons) {
    $cid = $courseIds[$courseKey];
    $order = 0;
    foreach ($lessons as [$title, $summary, $minutes]) {
        $order++;
        $body = "# $title\n\n$summary\n\n## Key takeaways\n\n- Concept 1\n- Concept 2\n- Practice exercise below.";
        $pdo->prepare(
            'INSERT INTO lessons (course_id, title, summary, body, duration_min, sort_order)
             VALUES (:c, :t, :s, :b, :d, :o)
             ON DUPLICATE KEY UPDATE
                summary = VALUES(summary),
                body = VALUES(body),
                duration_min = VALUES(duration_min)'
        )->execute([':c' => $cid, ':t' => $title, ':s' => $summary, ':b' => $body, ':d' => $minutes, ':o' => $order]);
        $lid = (int)$pdo->query("SELECT id FROM lessons WHERE course_id = $cid AND title = " . $pdo->quote($title))->fetchColumn();
        $lessonIds[$courseKey][] = $lid;
    }
}
echo "Lessons ready: " . array_sum(array_map('count', $lessonIds)) . "\n";

// === Enrollments ===
foreach (['math-101', 'sci-101', 'cs-101'] as $courseKey) {
    $cid = $courseIds[$courseKey];
    $pdo->prepare(
        'INSERT IGNORE INTO enrollments (student_id, course_id, progress, last_active)
         VALUES (:s, :c, :p, NOW())'
    )->execute([':s' => $studentId, ':c' => $cid, ':p' => 35 + (int)array_search($courseKey, ['math-101','sci-101','cs-101']) * 20]);
}
echo "Enrollments ready for demo student.\n";

// === Quizzes ===
$quizzes = [
    ['cs-101', 'Python Basics', 'Computer Science', 'Test your Python fundamentals.'],
    ['math-101', 'Algebra Pop Quiz', 'Mathematics', 'A quick check on linear equations.'],
    ['sci-101', 'Motion Check', 'Physics', 'Are you moving in the right direction?'],
];
$quizIds = [];
foreach ($quizzes as [$courseKey, $title, $subject, $desc]) {
    $cid = $courseIds[$courseKey];
    $pdo->prepare(
        'INSERT INTO quizzes (course_id, title, subject, description)
         VALUES (:c, :t, :s, :d)
         ON DUPLICATE KEY UPDATE description = VALUES(description)'
    )->execute([':c' => $cid, ':t' => $title, ':s' => $subject, ':d' => $desc]);
    $qid = (int)$pdo->query("SELECT id FROM quizzes WHERE course_id = $cid AND title = " . $pdo->quote($title))->fetchColumn();
    $quizIds[$title] = $qid;
}
echo "Quizzes ready: " . count($quizIds) . "\n";

// === Quiz questions ===
$questions = [
    'Python Basics' => [
        ['What is the output of print(2 ** 3)?', '6', '8', '9', '23', 'b'],
        ['Which keyword defines a function?', 'func', 'def', 'function', 'lambda', 'b'],
        ['Which is a valid list?', '(1, 2, 3)', '{1, 2, 3}', '[1, 2, 3]', '<1, 2, 3>', 'c'],
    ],
    'Algebra Pop Quiz' => [
        ['Solve 2x + 4 = 10', 'x=2', 'x=3', 'x=4', 'x=5', 'b'],
        ['If 5x = 25, then x =', '3', '4', '5', '6', 'c'],
        ['The slope of y = 2x + 1 is', '1', '2', '3', 'x', 'b'],
    ],
    'Motion Check' => [
        ['Speed is a ___ quantity.', 'vector', 'scalar', 'tensor', 'matrix', 'b'],
        ['F = m × a is which law?', 'First', 'Second', 'Third', 'Zeroth', 'b'],
    ],
];
foreach ($questions as $quizTitle => $qs) {
    $qid = $quizIds[$quizTitle];
    // Wipe and re-insert to keep things simple
    $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = :q')->execute([':q' => $qid]);
    $order = 0;
    foreach ($qs as [$prompt, $a, $b, $c, $d, $correct]) {
        $order++;
        $pdo->prepare(
            'INSERT INTO quiz_questions (quiz_id, prompt, option_a, option_b, option_c, option_d, correct, sort_order)
             VALUES (:q, :p, :a, :b, :c, :d, :k, :o)'
        )->execute([':q' => $qid, ':p' => $prompt, ':a' => $a, ':b' => $b, ':c' => $c, ':d' => $d, ':k' => $correct, ':o' => $order]);
    }
}
echo "Quiz questions ready.\n";

// === Videos ===
$videos = [
    ['course' => 'cs-101',  'title' => 'Python in 100 Seconds',         'subject' => 'Computer Science', 'desc' => 'A whirlwind tour of Python for absolute beginners.', 'embed' => 'https://www.youtube.com/embed/x7X9w_GIm1s', 'mins' => 2],
    ['course' => 'math-101', 'title' => 'Algebra Basics — 10 min primer','subject' => 'Mathematics',      'desc' => 'All the algebra you need before calculus.',           'embed' => 'https://www.youtube.com/embed/NybHckSEQBI', 'mins' => 10],
    ['course' => 'sci-101',  'title' => 'Newton\'s Laws, Explained',     'subject' => 'Physics',          'desc' => 'Why things keep moving (or stop).',                 'embed' => 'https://www.youtube.com/embed/kKKM8Y-u7ds', 'mins' => 8],
    ['course' => 'cs-101',  'title' => 'Loops Visualized',               'subject' => 'Computer Science', 'desc' => 'For, while, and when to pick which.',              'embed' => 'https://www.youtube.com/embed/twRTusrMFjw', 'mins' => 6],
    ['course' => 'math-201', 'title' => 'Why Limits Matter',              'subject' => 'Mathematics',      'desc' => 'The intuition behind limits in 8 minutes.',        'embed' => 'https://www.youtube.com/embed/W0t8D9C1dJ4', 'mins' => 8],
];
foreach ($videos as $v) {
    $cid = $v['course'] ? ($courseIds[$v['course']] ?? null) : null;
    $pdo->prepare(
        'INSERT INTO videos (course_id, title, subject, description, embed_url, duration_min)
         VALUES (:c, :t, :s, :d, :u, :m)
         ON DUPLICATE KEY UPDATE
            description = VALUES(description),
            embed_url = VALUES(embed_url),
            duration_min = VALUES(duration_min)'
    )->execute([':c' => $cid, ':t' => $v['title'], ':s' => $v['subject'], ':d' => $v['desc'], ':u' => $v['embed'], ':m' => $v['mins']]);
}
echo "Videos ready: " . count($videos) . "\n";

// === Activity ===
$activities = [
    [$userIds['student_demo'], 'fa-book-open',      'Started Algebra Foundations',     'Welcome to the course!'],
    [$userIds['student_demo'], 'fa-clipboard-check', 'Completed quiz: Python Basics', 'Scored 2 / 3 — nice work!'],
    [$userIds['student_demo'], 'fa-video',           'Watched: Python in 100 Seconds', null],
    [$userIds['student_demo'], 'fa-chart-line',      'Reached 12-day learning streak!', null],
    [$userIds['teacher_math'],  'fa-chalkboard',     'New assignment posted',          'Python Basics — Functions lab'],
    [$userIds['teacher_science'], 'fa-users',        '47 new students joined this week', null],
];
foreach ($activities as [$uid, $icon, $title, $detail]) {
    $pdo->prepare(
        'INSERT INTO activities (user_id, icon, title, detail) VALUES (:u, :i, :t, :d)'
    )->execute([':u' => $uid, ':i' => $icon, ':t' => $title, ':d' => $detail]);
}
echo "Activities ready.\n";

echo "\n✅ Seed complete.\n";
echo "Demo accounts (use these to log in):\n";
echo "  admin@edumate.local      / Admin@12345  (admin)\n";
echo "  ananya.rao@edumate.local  / Teacher@123  (teacher)\n";
echo "  vikram.iyer@edumate.local / Teacher@123  (teacher)\n";
echo "  subhrajit@edumate.local   / Student@123  (student)\n";
