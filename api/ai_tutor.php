<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ai_functions.php';

header('Content-Type: application/json');

require_role('student');
$user = current_user();
$uid = (int)$user['id'];
$studentClass = !empty($user['class']) ? $user['class'] : 'General';
$classContext = "IMPORTANT CONTEXT: The student you are tutoring is in Class/Grade: {$studentClass}. You MUST tailor the difficulty, vocabulary, and depth of your response to be appropriate for a student in this grade level. ";

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

$pdo = db();

// Ensure tables exist (basic migration)
$pdo->exec("CREATE TABLE IF NOT EXISTS ai_doubts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS ai_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    content TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

try {
    $pdo->exec("ALTER TABLE quizzes MODIFY course_id INT UNSIGNED NULL");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE quizzes ADD COLUMN created_by INT UNSIGNED NULL DEFAULT NULL AFTER description");
} catch (Throwable $e) {}
try {
    $pdo->exec("ALTER TABLE quizzes ADD CONSTRAINT fk_quizzes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE");
} catch (Throwable $e) {}

try {
    if ($action === 'ask') {
        $q = trim((string)($input['question'] ?? ''));
        if ($q === '') throw new Exception('Empty question');
        
        $q = $classContext . "\n\nStudent's Question: " . $q;
        
        $parts = [['text' => $q]];
        
        // Handle file upload (Base64)
        if (!empty($input['fileData']) && !empty($input['mimeType'])) {
            $base64Data = preg_replace('#^data:image/\w+;base64,#i', '', $input['fileData']);
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $input['mimeType'],
                    'data' => $base64Data
                ]
            ];
        }

        $messages = [
            ['role' => 'user', 'parts' => $parts]
        ];
        
        $answer = call_gemini_api($messages);
        $answerHtml = parse_markdown_to_html($answer);
        
        echo json_encode(['answer' => $answerHtml, 'raw' => $answer]);
        exit;
    }

    if ($action === 'save_doubt') {
        $q = trim((string)($input['question'] ?? ''));
        $a = trim((string)($input['answer'] ?? ''));
        if ($q === '') throw new Exception('Empty doubt');
        $stmt = $pdo->prepare('INSERT INTO ai_doubts (user_id, question, answer) VALUES (:u, :q, :a)');
        $stmt->execute([':u' => $uid, ':q' => $q, ':a' => $a]);
        echo json_encode(['message' => 'Doubt saved to your Progress history!']);
        exit;
    }

    if ($action === 'create_assignment') {
        $title = trim((string)($input['title'] ?? 'Untitled'));
        $desc  = trim((string)($input['description'] ?? ''));
        $courses = trim((string)($input['courses'] ?? ''));
        
        $prompt = $classContext . "Create a comprehensive assignment titled '{$title}'. Context: {$desc}. Courses: {$courses}. Include clear instructions, 3-5 thought-provoking questions, and grading criteria. Format nicely in Markdown.";
        $messages = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        
        $content = call_gemini_api($messages);
        $contentHtml = parse_markdown_to_html($content);

        $stmt = $pdo->prepare('INSERT INTO ai_assignments (user_id, title, description, content) VALUES (:u, :t, :d, :c)');
        $stmt->execute([':u' => $uid, ':t' => $title, ':d' => $desc, ':c' => $content]);
        echo json_encode(['assignment' => $contentHtml, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'generate_timetable') {
        $courses = trim((string)($input['courses'] ?? ''));
        if (empty($courses)) throw new Exception('No courses provided');

        $prompt = $classContext . "Generate a weekly study timetable for a student enrolled in: {$courses}. Create a beautifully formatted Markdown table with columns: Day, Morning, Afternoon, Evening. Do not include extra conversational text, just the Markdown table.";
        $messages = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        
        $markdown = call_gemini_api($messages);
        $html = parse_markdown_to_html($markdown);

        echo json_encode(['html' => $html, 'markdown' => $markdown]);
        exit;
    }
    
    if ($action === 'generate_quiz') {
        $topic = trim((string)($input['topic'] ?? 'General Science'));
        $count = (int)($input['count'] ?? 5);
        if ($count < 1) $count = 5;

        $prompt = $classContext . "Generate a high-quality, highly challenging {$count}-question multiple choice quiz on the topic of \"{$topic}\". The questions MUST test deep conceptual understanding, critical thinking, and advanced application rather than basic rote memorization. Make the distractors (wrong options) highly plausible to ensure a rigorous standard. Format EXACTLY as a JSON array wrapped in a code block. DO NOT output any conversational text before or after the JSON. Example format:
        ```json
        [{\"question\":\"...\",\"options\":[\"...\",\"...\",\"...\",\"...\"],\"answerIndex\":0,\"explanation\":\"...\"}]
        ```";
        
        $messages = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        $answer = call_gemini_api($messages);
        
        $jsonStr = '';
        if (preg_match('/```(?:json-quiz|json)?\s*([\s\S]*?)\s*```/i', $answer, $matches)) {
            $jsonStr = trim($matches[1]);
        } elseif (preg_match('/(\[\s*\{[\s\S]*\}\s*\])/', $answer, $matches)) {
            $jsonStr = trim($matches[1]);
        }
        
        $quizData = json_decode($jsonStr, true);
        if (is_array($quizData) && count($quizData) > 0) {
                $stmt = $pdo->prepare('INSERT INTO quizzes (course_id, title, subject, description, created_by) VALUES (NULL, :title, :subject, :desc, :user)');
                $stmt->execute([
                    ':title' => "AI Quiz: " . substr($topic, 0, 100),
                    ':subject' => "AI Generated",
                    ':desc' => "A {$count}-question quiz generated by the AI tutor on the topic of {$topic}.",
                    ':user' => $uid
                ]);
                $quizId = (int)$pdo->lastInsertId();
                
                $qStmt = $pdo->prepare('INSERT INTO quiz_questions (quiz_id, prompt, option_a, option_b, option_c, option_d, correct, sort_order) VALUES (:qid, :prompt, :a, :b, :c, :d, :correct, :sort)');
                $sort = 1;
                $optionsMap = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];
                foreach ($quizData as $idx => $q) {
                    $correct = $optionsMap[$q['answerIndex']] ?? 'a';
                    $qStmt->execute([
                        ':qid' => $quizId,
                        ':prompt' => $q['question'],
                        ':a' => $q['options'][0] ?? '',
                        ':b' => $q['options'][1] ?? '',
                        ':c' => $q['options'][2] ?? '',
                        ':d' => $q['options'][3] ?? '',
                        ':correct' => $correct,
                        ':sort' => $sort++
                    ]);
                    $quizData[$idx]['db_id'] = (int)$pdo->lastInsertId();
                }
                
                echo json_encode(['quiz_id' => $quizId, 'data' => $quizData]);
                exit;
            }
        throw new Exception("AI failed to return the correct quiz format. Debug Info: \n" . $answer);
    }
    
    if ($action === 'summarize') {
        $text = trim((string)($input['text'] ?? ''));
        if ($text === '') throw new Exception('No text provided');
        
        $prompt = $classContext . "Summarize the following lecture notes/text concisely. Provide 3 key takeaways and recommend 2 topics for further reading: \n\n" . $text;
        $messages = [['role' => 'user', 'parts' => [['text' => $prompt]]]];
        
        $summary = call_gemini_api($messages);
        $summaryHtml = parse_markdown_to_html($summary);
        
        echo json_encode(['summary' => $summaryHtml]);
        exit;
    }
    
    if ($action === 'save_quiz_attempt') {
        $qid = (int)($input['quiz_id'] ?? 0);
        $score = (int)($input['score'] ?? 0);
        $total = (int)($input['total'] ?? 0);
        $responses = $input['responses'] ?? [];
        
        $pdo->prepare('INSERT INTO quiz_attempts (student_id, quiz_id, score, total, responses) VALUES (:u, :q, :s, :t, :r)')
            ->execute([':u' => $uid, ':q' => $qid, ':s' => $score, ':t' => $total, ':r' => json_encode($responses)]);
            
        record_activity($uid, 'fa-clipboard-check', "Completed a quiz: scored {$score}/{$total}", null);
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
