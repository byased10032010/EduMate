<?php
/**
 * EduMate - Student: AI Tutor (chat)
 *
 * GET  -> renders the chat UI
 * POST (application/json) -> returns a JSON reply
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';

$user = current_user();
$uid  = (int)($user['id'] ?? 0);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    $message = trim((string)($payload['message'] ?? ''));

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
        exit;
    }

    try {
        $reply = generate_tutor_reply($message, $uid);
        echo json_encode(['success' => true, 'reply' => $reply]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Tutor chat error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    exit;
}

if (!is_logged_in()) {
    echo '<!DOCTYPE html>', "\n";
    echo '<html lang="en">', "\n";
    echo '<head>', "\n";
    echo '  <meta charset="UTF-8">', "\n";
    echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0">', "\n";
    echo '  <title>eduBot · EduMate</title>', "\n";
    echo '  <style>body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:#0f172a;color:#f8fafc;} .wrap{max-width:1180px;margin:0 auto;padding:24px;} .card{background:#111827;border:1px solid #334155;border-radius:18px;padding:24px;box-shadow:0 12px 40px rgba(0,0,0,.25);} .card h2{margin:0 0 8px;} .card p{margin:0 0 16px; color:#cbd5e1;}</style>', "\n";
    echo '</head>', "\n";
    echo '<body>', "\n";
    echo '  <div class="wrap">', "\n";
    echo '    <div class="card">', "\n";
    echo '      <h2>eduBot</h2>', "\n";
    echo '      <p>Sign in to access the eduBot tutor.</p>', "\n";
    echo '    </div>', "\n";
    echo '  </div>', "\n";
    echo '</body>', "\n";
    echo '</html>', "\n";
    exit;
}

require_role('student');

render_dashboard_header(
    'eduBot',
    student_nav(),
    'tutor',
    ['Home', 'eduBot']
);
?>

<style>
.chat-card {
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface);
    display: flex;
    flex-direction: column;
    height: 720px;
    overflow: hidden;
}
.chat-window {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    background: #f8fafc;
}
.chat-bubble {
    max-width: 80%;
    margin-bottom: 12px;
    padding: 14px 16px;
    border-radius: 18px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}
.chat-bubble.user {
    margin-left: auto;
    background: #4f46e5;
    color: #fff;
}
.chat-bubble.bot {
    margin-right: auto;
    background: #e0e7ff;
    color: #1e293b;
}
.chat-form {
    display: flex;
    gap: 12px;
    padding: 16px;
    border-top: 1px solid rgba(100, 116, 139, 0.15);
    background: #fff;
}
.chat-form input[type="text"] {
    flex: 1;
    padding: 12px 14px;
    border: 1px solid rgba(100, 116, 139, 0.25);
    border-radius: 999px;
    background: #fff;
    color: inherit;
}
.chat-form button {
    min-width: 100px;
    padding: 12px 18px;
    border: none;
    border-radius: 999px;
    background: #4f46e5;
    color: #fff;
    cursor: pointer;
}
.chat-form button:disabled {
    background: #c7d2fe;
    cursor: not-allowed;
}
</style>

<section class="panel">
    <h2>eduBot <span class="sub">Ask anything. Be curious.</span></h2>
    <div class="chat-card">
        <div class="chat-window" id="chat-window" role="log" aria-live="polite"></div>
        <form id="chat-form" class="chat-form" autocomplete="off">
            <input id="chat-input" name="message" type="text" placeholder="Type your Doubt.." aria-label="Type your message" required />
            <button type="submit">Send</button>
        </form>
    </div>
</section>

<script>
(function() {
  const chatWindow = document.getElementById('chat-window');
  const chatForm = document.getElementById('chat-form');
  const chatInput = document.getElementById('chat-input');
  const endpoint = window.location.href;

  chatWindow.innerHTML = '';

  function appendMessage(text, role) {
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble ' + role;
    bubble.textContent = text;
    chatWindow.appendChild(bubble);
    chatWindow.scrollTop = chatWindow.scrollHeight;
  }

  function setLoading(isLoading) {
    chatInput.disabled = isLoading;
    chatForm.querySelector('button').disabled = isLoading;
    chatForm.querySelector('button').textContent = isLoading ? 'Sending…' : 'Send';
  }

  chatForm.addEventListener('submit', async function(event) {
    event.preventDefault();
    const message = chatInput.value.trim();
    if (!message) return;

    appendMessage(message, 'user');
    chatInput.value = '';
    setLoading(true);

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message }),
      });
      const result = await response.json();
      if (!result.success) {
        throw new Error(result.error || 'No reply received.');
      }
      appendMessage(result.reply, 'bot');
    } catch (error) {
      appendMessage('Sorry, something went wrong. Please try again later.', 'bot');
      console.error(error);
    } finally {
      setLoading(false);
      chatInput.focus();
    }
  });
})();
</script>

<?php
/**
 * Knowledge-grounded, deterministic tutor reply.
 * Pulls recent course titles + activity for context, and uses pattern matching
 * to give a helpful answer. No external AI service is called.
 */
function generate_tutor_reply(string $msg, int $uid): string
{
    $low = strtolower($msg);

    // Get the user's details (class/board)
    $pdo = db();
    $uStmt = $pdo->prepare('SELECT class, board, full_name FROM users WHERE id = :id');
    $uStmt->execute([':id' => $uid]);
    $uInfo = $uStmt->fetch();
    $class = $uInfo['class'] ?? 8;
    $board = $uInfo['board'] ?? 'CBSE';

    // Get the user's enrolled courses for context
    $courses = $pdo->prepare(
        'SELECT c.title, c.subject FROM enrollments e
           JOIN courses c ON c.id = e.course_id
          WHERE e.student_id = :u LIMIT 5'
    );
    $courses->execute([':u' => $uid]);
    $enrolled = $courses->fetchAll();
    $enrolledTitles = array_map(fn($c) => $c['title'], $enrolled);

    // Dynamic Syllabus Data for Class 5-10 boardwise
    $syllabi = [
        5 => [
            'CBSE' => "Class 5 CBSE Syllabus Outline:\n- Mathematics: Large Numbers, Roman Numerals, Decimals, Fractions, Profit & Loss, Angles.\n- Environmental Studies: Super Senses, From Tasting to Digesting, Seeds and Seeds, A Shelter so High!.",
            'ICSE' => "Class 5 ICSE Syllabus Outline:\n- Mathematics: Number System, Operations on Numbers, Factors and Multiples, Percentage, Geometry.\n- Science: Human Body (Circulatory/Skeletal), Plant Life, Work and Energy, Cleanliness & Health.",
            'State Board' => "Class 5 State Board Syllabus Outline:\n- Mathematics: Basic Geometry, Fractions, Decimal Fractions, Pattern and Symmetry.\n- General Science: Our Living World, Water Resources, Food and Nutrition."
        ],
        6 => [
            'CBSE' => "Class 6 CBSE Syllabus Outline:\n- Mathematics: Knowing Our Numbers, Whole Numbers, Playing with Numbers, Basic Geometrical Ideas, Integers, Algebra.\n- Science: Components of Food, Sorting Materials, Separation of Substances, Getting to Know Plants, Light, Electricity.",
            'ICSE' => "Class 6 ICSE Syllabus Outline:\n- Mathematics: Sets, Number System, Ratio & Proportion, Algebra, Geometry.\n- Physics: Physical Quantities, Force, Energy.\n- Chemistry: Introduction to Chemistry, Elements & Compounds.\n- Biology: Plant Life, Cell Structure.",
            'State Board' => "Class 6 State Board Syllabus Outline:\n- Mathematics: Integers, Equations, Bar Graphs, Symmetry.\n- Science: Natural Resources, Diversity in Living Things, Force and Types of Force."
        ],
        7 => [
            'CBSE' => "Class 7 CBSE Syllabus Outline:\n- Mathematics: Integers, Fractions and Decimals, Simple Equations, Triangles, Rational Numbers, Perimeter & Area.\n- Science: Nutrition in Plants/Animals, Heat, Acids, Bases & Salts, Physical & Chemical Changes, Respiration.",
            'ICSE' => "Class 7 ICSE Syllabus Outline:\n- Mathematics: Rational Numbers, Exponents, Percentage, Linear Equations.\n- Physics: Heat, Light, Sound, Electricity.\n- Chemistry: Matter, Chemical Reactions.\n- Biology: Tissue System, Kingdom Classification.",
            'State Board' => "Class 7 State Board Syllabus Outline:\n- Mathematics: Geometrical Constructions, Multiplication & Division of Integers, Indices.\n- Science: Food Safety, Static Electricity, Elements, Compounds and Mixtures."
        ],
        8 => [
            'CBSE' => "Class 8 CBSE Syllabus Outline:\n- Mathematics: Rational Numbers, Linear Equations in One Variable, Quadrilaterals, Algebraic Expressions, Mensuration.\n- Science: Crop Production, Microorganisms, Coal & Petroleum, Combustion, Cell Structure, Force & Pressure, Sound.",
            'ICSE' => "Class 8 ICSE Syllabus Outline:\n- Mathematics: Squares and Square Roots, Cubes, Direct/Inverse Variations, Factorisation.\n- Physics: Matter, Pressure, Heat Transfer, Sound, Light.\n- Chemistry: Atoms, Molecules, Chemical Reactions.\n- Biology: Transport in Plants, Circulatory/Nervous System.",
            'State Board' => "Class 8 State Board Syllabus Outline:\n- Mathematics: Rational and Irrational Numbers, Parallel Lines, Indices.\n- Science: Living World and Microbes, Health and Diseases, Force and Pressure."
        ],
        9 => [
            'CBSE' => "Class 9 CBSE Syllabus Outline:\n- Mathematics: Number Systems, Polynomials, Coordinate Geometry, Linear Equations, Lines & Angles, Triangles, Quadrilaterals, Circles, Heron's Formula.\n- Science: Matter in Our Surroundings, Is Matter Around Us Pure, Atoms and Molecules, Cell, Tissues, Motion, Force, Gravitation, Work & Energy, Sound.",
            'ICSE' => "Class 9 ICSE Syllabus Outline:\n- Mathematics: Rational/Irrational Numbers, Expansions, Factorisation, Simultaneous Equations, Triangles, Pythagoras Theorem.\n- Physics: Measurements, Motion in 1D, Laws of Motion, Fluids, Heat, Light.\n- Chemistry: Matter, Gas Laws, Atomic Structure.\n- Biology: Cell, Tissues, Flower, Nutrition.",
            'State Board' => "Class 9 State Board Syllabus Outline:\n- Mathematics: Sets, Real Numbers, Polynomials, Ratio and Proportion.\n- Science: Laws of Motion, Work and Energy, Current Electricity, Classification of Plants."
        ],
        10 => [
            'CBSE' => "Class 10 CBSE Syllabus Outline:\n- Mathematics: Real Numbers, Polynomials, Pair of Linear Equations, Quadratic Equations, Arithmetic Progressions, Triangles, Trigonometry, Circles.\n- Science: Chemical Reactions, Acids/Bases/Salts, Metals/Non-Metals, Carbon Compounds, Life Processes, Control/Coordination, Light, Electricity, Magnetic Effects.",
            'ICSE' => "Class 10 ICSE Syllabus Outline:\n- Mathematics: GST, Banking, Linear Inequations, Quadratic Equations, Ratio/Proportion, Matrices, Similarity, Trigonometry.\n- Physics: Force, Work/Energy/Power, Light Refraction, Sound, Electricity.\n- Chemistry: Periodic Table, Chemical Bonding, Electrolysis, Metallurgy.\n- Biology: Cell Division, Genetics, Transpiration, Nervous System.",
            'State Board' => "Class 10 State Board Syllabus Outline:\n- Mathematics: Linear Equations in Two Variables, Quadratic Equations, Arithmetic Progression.\n- Science: Gravitation, Periodic Classification, Chemical Reactions, Effects of Electric Current."
        ]
    ];

    // Default practice questions by class
    $practiceQuestions = [
        5 => ["Practice Question (Class 5 Math):\nWhat is the Roman Numeral for 50?\n(a) X\n(b) L\n(c) C\n(d) D\n\nReply with 'a', 'b', 'c', or 'd' to answer!", 'b'],
        6 => ["Practice Question (Class 6 Science):\nWhich of the following is a nutrient that provides quick energy?\n(a) Vitamin A\n(b) Calcium\n(c) Carbohydrates\n(d) Iron\n\nReply with 'a', 'b', 'c', or 'd' to answer!", 'c'],
        7 => ["Practice Question (Class 7 Math):\nSolve the equation: 3x - 5 = 10. What is x?\n(a) 3\n(b) 4\n(c) 5\n(d) 6\n\nReply with 'a', 'b', 'c', or 'd' to answer!", 'c'],
        8 => ["Practice Question (Class 8 Science):\nWhich cell organelle is known as the powerhouse of the cell?\n(a) Nucleus\n(b) Ribosome\n(c) Mitochondria\n(d) Chloroplast\n\nReply with 'a', 'b', 'c', or 'd' to answer!", 'c'],
        9 => ["Practice Question (Class 9 Math):\nIf p(x) = x² - 2x + 1, what is the value of p(1)?\n(a) 0\n(b) 1\n(c) 2\n(d) -1\n\nReply with 'a', 'b', 'c', or 'd' to answer!", 'a'],
        10 => ["Practice Question (Class 10 Science):\nWhich acid is present in tomato?\n(a) Acetic acid\n(b) Citric acid\n(c) Oxalic acid\n(d) Tartaric acid\n\nReply with 'a', 'b', 'c', or 'd' to answer!", 'c']
    ];

    // Pattern -> response
    $patterns = [
        '/\b(thanks|thank you|thx)\b/'             => "You're very welcome! Keep learning and pushing your streak! What else can I help with?",
        '/\b(syllabus|curriculum|chapters|topics|outline)\b/' => isset($syllabi[$class][$board]) ? $syllabi[$class][$board] : "I support Class 5 to 10 for CBSE, ICSE, and State Boards. Currently, you are configured for Class {$class} ({$board}). Let me know what subject you want details for!",
        '/\b(photo\w*)\b/'                          => "Photosynthesis is how plants turn sunlight + water + CO\u{2082} into glucose and oxygen. It happens in the chloroplasts, mostly in the leaves. The light reactions capture energy; the Calvin cycle builds sugar. Want a quick quiz on this?",
        '/\b(algebra|equation|linear)\b/'           => "Linear algebra is all about lines: y = mx + b. The slope (m) tells you steepness, b is where the line crosses the y-axis. To solve a linear equation, isolate x by doing the same thing to both sides. Which part trips you up?",
        '/\b(calculus|limit|derivative)\b/'         => "Calculus in one breath: a limit is what a function approaches as x gets close to something. A derivative is the rate of change at a point. d/dx (x\u00b2) = 2x. The chain rule, product rule, and quotient rule are your tools. Want me to walk through an example?",
        '/\b(physics|force|newton|motion)\b/'      => "Newton's three laws: 1) An object in motion stays in motion unless acted on. 2) F = m\u00d7a. 3) For every action, an equal and opposite reaction. These cover almost every mechanics problem. What scenario are you working on?",
        '/\b(chemistry|atom|periodic|element)\b/'  => "Atoms are made of protons (+), neutrons (0), and electrons (-). The number of protons defines the element. The periodic table organises them by atomic number and groups elements with similar properties. Want the trends cheat sheet?",
        '/\b(python|programming|code)\b/'           => "Python is friendly: variables hold values, lists store ordered collections, loops repeat, and functions package logic. def name(params): ... is the pattern. Try writing: def greet(n): return f'Hello, {n}!'. Want a small exercise?",
        '/\b(quiz|test me|question|practice|exercise)\b/' => isset($practiceQuestions[$class]) ? $practiceQuestions[$class][0] : "Sure! What is 3\u00b2 + 4\u00b2? Reply with your answer and I'll mark it.",
        '/\b(who are you|what are you)\b/'          => "I'm EduMate's AI Study Chatbot — your friendly learning companion configured for Class {$class} ({$board} Board). I can explain syllabus items, clarify concepts, and test your knowledge.",
        '/\b(study plan|schedule)\b/'              => "A good study plan: 1) Pick the topic. 2) Set a 25-min Pomodoro. 3) Read for 10, take notes for 10, practice for 5. 4) Review tomorrow. Want me to build one for " . (count($enrolledTitles) ? $enrolledTitles[0] : 'your current course') . "?",
    ];

    // Check if the user is answering a practice question
    if (isset($practiceQuestions[$class])) {
        $correctAnswer = $practiceQuestions[$class][1];
        if (trim(strtolower($msg)) === $correctAnswer) {
            return "🎉 Correct! Excellent job. You have mastered that Class {$class} practice question! Would you like another explanation or a new query?";
        } elseif (in_array(trim(strtolower($msg)), ['a', 'b', 'c', 'd'], true)) {
            return "❌ That's incorrect. The correct answer was '{$correctAnswer}'. Would you like me to explain this concept in detail?";
        }
    }

    foreach ($patterns as $re => $reply) {
        if (preg_match($re, $low)) {
            return $reply;
        }
    }

    // Fallback: acknowledge + offer context-aware next step
    $subjects = array_unique(array_map(fn($c) => $c['subject'], $enrolled));
    $subjHint = $subjects ? 'Your enrolled subjects: ' . implode(', ', $subjects) . '.' : 'Try enrolling in a course to get tailored help.';
    return "Great question. For Class {$class} ({$board} Board), this is an important topic. Could you clarify which subject this is from? {$subjHint}\n\nTip: You can also ask me to 'Show my curriculum' or 'Practice question'.";
}

render_dashboard_footer();
?>
