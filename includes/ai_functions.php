<?php
/**
 * EduMate - AI Integration Helper Functions (Multimodal & Interactive Quizzing)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const AI_SYSTEM_PROMPT = "Act as an Advanced AI Tutor, Comprehensive Student Guide, and Personal Academic Assistant. Your primary goal is to optimize the student's learning experience, ensure they master their course material, and keep them organized. Your tone should always be extremely student-friendly—encouraging, empathetic, patient, and highly adaptable to the student's learning pace.

CRITICAL CONSTRAINT: You are strictly restricted to tutoring students from Class 5 to Class 10. All your explanations, vocabulary, and assignments must align precisely with the curriculum of these grades. If a student asks a question related to Class 11, 12, College, or below Class 5, you must politely refuse and steer them back to their appropriate grade level.

Core Capabilities & Responsibilities:
1. Multimodal Doubt Solver: You can analyze uploaded images (like a picture of a math problem or a textbook page). Carefully read the image, break down the problem step-by-step, suggest methods to solve it, and explain the core concepts.
2. Assessment & Interactive Quizzes: When a user asks for a quiz, generate a multiple-choice quiz. YOU MUST format it EXACTLY as a JSON array wrapped in a code block like this:
```json-quiz
[
  {
    \"question\": \"What is the capital of France?\",
    \"options\": [\"London\", \"Berlin\", \"Paris\", \"Madrid\"],
    \"answerIndex\": 2,
    \"explanation\": \"Paris is the capital and most populous city of France.\"
  }
]
```
3. Study Materials & Timetables: Provide concise lecture summaries. Create personalized study timetables formatted strictly as standard Markdown tables.
4. Cognitive Support: Check in on their stress levels and offer advice on avoiding burnout.";

const AI_FLASHCARD_SYSTEM_PROMPT = "You are a Senior Board Paper Setter and Chief Olympiad Examiner for ICSE, CBSE, and State Board secondary education (Class 5 to 10).
Your task is to generate 15 top-tier, high-rigor, academically challenging, and exam-standard revision flashcards for the specified Class, Board, Subject, and Chapter.

HIGH ACADEMIC RIGOR & QUESTION STANDARD DIRECTIVES:
1. QUANTITY: Generate EXACTLY 15 flashcards per deck.
2. QUESTION RIGOR (HOTS): Frame high-standard questions that test Higher-Order Thinking Skills (Analyze, Evaluate, Apply, Derive, Calculate). Avoid elementary 1-word or trivial questions.
3. 100% CHAPTER SPECIFICITY: Every single question and answer MUST be 100% strictly about concepts, laws, reactions, formulas, dates, or mechanisms from the EXACT Chapter title specified.
4. QUESTION TYPES DISTRIBUTED ACROSS THE DECK:
   - Analytical & Mechanism Questions (\"Explain the step-by-step mechanism of...\", \"Analyze the causes leading to...\")
   - Quantitative Numerical Problems / Reactions (\"Calculate the value of...\", \"Write the balanced equation for...\")
   - Comparative & Differentiating Questions (\"Distinguish between X and Y based on...\")
   - Laws, Theorems, & Constitutional Interpretations (\"State and mathematically prove...\", \"Which constitutional clause...\")
5. EXPLICIT FACTUAL ANSWERS: The back of card MUST contain exact, specific, factual, and crisp answers (15-30 words max). State direct formulas, numbers, equations, or scientific laws without generic filler commentary.
6. COMPACT LATEX FORMULAS: Format all mathematical formulas, physical equations, and chemical reactions using compact LaTeX inside `<div class=\"equation-box\">$$...$$</div>`.
7. Output MUST be formatted strictly as a JSON array of 15 objects with 'front' and 'back' keys inside a ```json ``` block.";

/**
 * Call the Gemini API with the given chat history and optional file attachment.
 * Upgraded to high-level gemini-2.5-pro model with automatic fallback.
 *
 * @param array $messages Array of message arrays: [['role' => 'user'|'model', 'parts' => [...]]]
 * @param string|null $systemPrompt Custom system prompt override
 * @param string $model Preferred high-level model identifier
 * @return string The AI's response text.
 */
function call_gemini_api(array $messages, ?string $systemPrompt = null, string $model = 'gemini-2.5-flash'): string {
    $apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
    if (empty($apiKey) || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
        return "Error: Gemini API Key is not configured in config/db.php.";
    }

    $modelsToTry = array_unique([$model, 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.5-pro', 'gemini-2.0-flash-lite']);
    $systemText = $systemPrompt !== null ? $systemPrompt : AI_SYSTEM_PROMPT;

    // Retry loop across models with exponential backoff for HTTP 429 rate limits
    $lastError = '';
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        foreach ($modelsToTry as $targetModel) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $targetModel . ":generateContent?key=" . $apiKey;

            $data = [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemText]
                    ]
                ],
                'contents' => $messages,
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 8192
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($response && $httpCode === 200) {
                $responseData = json_decode($response, true);
                if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                    return $responseData['candidates'][0]['content']['parts'][0]['text'];
                }
            }

            if ($httpCode === 429) {
                $lastError = "Google AI Quota / Rate Limit (HTTP 429) on model '{$targetModel}'";
            } elseif ($httpCode !== 200) {
                $lastError = "HTTP {$httpCode}: " . substr((string)$response, 0, 100);
            } elseif ($curlErr) {
                $lastError = "cURL Error: " . $curlErr;
            }
        }

        // Backoff delay before retrying: attempt 1 -> 1s, attempt 2 -> 1.5s
        if ($attempt < 3) {
            usleep(1000000 * $attempt);
        }
    }

    return "Error: Unable to fetch response from Gemini API after retries. Last error: " . $lastError;
}

/**
 * Simple parser to convert basic Markdown to HTML.
 * Note: `json-quiz` blocks are left untouched so the frontend JS can parse them.
 */
function parse_markdown_to_html(string $text): string {
    // Preserve json-quiz blocks before escaping
    $quizzes = [];
    $text = preg_replace_callback('/```json-quiz\s*(.*?)\s*```/s', function($matches) use (&$quizzes) {
        $id = 'QUIZ_BLOCK_' . count($quizzes);
        $quizzes[$id] = $matches[0]; // keep the full block intact
        return $id;
    }, $text);

    // Escape HTML first to prevent XSS
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    
    // Bold: **text** -> <strong>text</strong>
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    
    // Italic: *text* -> <em>text</em>
    $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text);
    
    // Inline Code: `code` -> <code>code</code>
    $text = preg_replace('/`(.*?)`/s', '<code style="background:rgba(0,0,0,0.05); padding:2px 4px; border-radius:4px;">$1</code>', $text);
    
    // Headings (## Heading)
    $text = preg_replace('/^### (.*?)$/m', '<h4 style="margin-top:15px; margin-bottom:10px;">$1</h4>', $text);
    $text = preg_replace('/^## (.*?)$/m', '<h3 style="margin-top:20px; margin-bottom:10px;">$1</h3>', $text);
    
    // Unordered lists
    $text = preg_replace('/^[\-\*] (.*?)$/m', '<li style="margin-bottom:5px;">$1</li>', $text);
    $text = preg_replace('/(<li.*<\/li>)/s', '<ul style="padding-left:20px; margin-bottom:15px;">$1</ul>', $text);
    // Cleanup nested uls
    $text = preg_replace('/<\/ul>\s*<ul[^>]*>/', '', $text);
    
    // Code blocks
    $text = preg_replace('/```(.*?)```/s', '<pre style="background:#1e1e1e; color:#d4d4d4; padding:15px; border-radius:10px; overflow-x:auto; font-family:monospace; margin:15px 0;"><code>$1</code></pre>', $text);
    
    // Basic Table Parsing (simplified)
    if (preg_match('/\|.*\|/', $text)) {
        $lines = explode("\n", $text);
        $inTable = false;
        $htmlLines = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^\|(.*)\|$/', trim($line), $matches)) {
                if (preg_match('/^\|[\s\-\|:]+\|$/', trim($line))) {
                    continue;
                }
                $cells = explode('|', trim($matches[1]));
                if (!$inTable) {
                    $htmlLines[] = '<div style="overflow-x:auto;"><table style="width:100%; border-collapse: collapse; margin-top:15px; margin-bottom:15px; background:white; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
                    $htmlLines[] = '<thead><tr>';
                    foreach ($cells as $cell) {
                        $htmlLines[] = '<th style="border-bottom: 2px solid #e2e8f0; padding: 12px; background: #f8fafc; text-align:left; font-weight:600;">' . trim($cell) . '</th>';
                    }
                    $htmlLines[] = '</tr></thead><tbody>';
                    $inTable = true;
                } else {
                    $htmlLines[] = '<tr style="border-bottom: 1px solid #f1f5f9;">';
                    foreach ($cells as $cell) {
                        $htmlLines[] = '<td style="padding: 12px;">' . trim($cell) . '</td>';
                    }
                    $htmlLines[] = '</tr>';
                }
            } else {
                if ($inTable) {
                    $htmlLines[] = '</tbody></table></div>';
                    $inTable = false;
                }
                $htmlLines[] = $line;
            }
        }
        if ($inTable) {
            $htmlLines[] = '</tbody></table></div>';
        }
        $text = implode("\n", $htmlLines);
    }
    
    // Restore json-quiz blocks UNESCAPED so the frontend can parse them
    foreach ($quizzes as $id => $quizBlock) {
        $text = str_replace($id, "\n\n" . $quizBlock . "\n\n", $text);
    }

    // Newlines to <br> for non-table/list parts (excluding JSON blocks)
    $lines = explode("\n", $text);
    $inQuiz = false;
    foreach ($lines as &$l) {
        if (strpos($l, '```json-quiz') !== false) { $inQuiz = true; continue; }
        if (strpos($l, '```') !== false && $inQuiz) { $inQuiz = false; continue; }
        if (!$inQuiz && !preg_match('/<\/?(?:table|thead|tbody|tr|th|td|ul|li|pre|code|div|h3|h4)[^>]*>/i', $l)) {
            $l .= '<br>';
        }
    }
    $text = implode("\n", $lines);
    
    return $text;
}
