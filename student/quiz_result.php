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
    $stmt = $pdo->prepare('SELECT q.*, COALESCE(c.title, "General Academic Quiz") AS course_title FROM quizzes q LEFT JOIN courses c ON c.id = q.course_id WHERE q.id = :q');
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

    // Compute summary stats
    $totalAttempts = count($rows);
    $bestScore = 0;
    $bestPct = 0;
    $latestScore = 0;
    $latestTotal = count($questions);
    $latestPct = 0;

    if ($totalAttempts > 0) {
        foreach ($rows as $r) {
            $p = $r['total'] > 0 ? (int)round(($r['score'] * 100) / $r['total']) : 0;
            if ($p >= $bestPct) {
                $bestPct = $p;
                $bestScore = (int)$r['score'];
            }
        }
        $latestScore = (int)$rows[0]['score'];
        $latestTotal = (int)$rows[0]['total'];
        $latestPct = $rows[0]['total'] > 0 ? (int)round(($rows[0]['score'] * 100) / $rows[0]['total']) : 0;
    }

    render_dashboard_header(
        $quiz['title'] . ' · Attempt History',
        student_nav(),
        'quizzes',
        ['Home', 'Quizzes', $quiz['title'], 'Attempt History']
    );
    ?>

    <style>
        /* ========================================================
           EduMate - Quiz Attempt History & Response Review Theme
           ======================================================== */

        /* Hero Banner */
        .history-hero {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.09) 0%, rgba(168, 85, 247, 0.06) 100%);
            border: 1.5px solid rgba(124, 92, 255, 0.24);
            border-radius: 20px;
            padding: 26px 28px;
            margin-bottom: 26px;
            box-shadow: 0 4px 20px rgba(31, 17, 71, 0.04);
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .history-hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .history-title-area {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .history-icon-badge {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.35);
            flex-shrink: 0;
        }
        .history-title-area h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.25;
            margin-bottom: 4px;
        }
        .history-meta-tags {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .history-tag {
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(124, 92, 255, 0.12);
            color: var(--gradient-primary, #7c5cff);
            border: 1px solid rgba(124, 92, 255, 0.25);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .history-hero-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Stats Ribbon */
        .history-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }
        .history-stat-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-xs);
            transition: all 0.2s ease;
        }
        .history-stat-card:hover {
            border-color: rgba(124, 92, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .history-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .history-stat-icon.purple {
            background: rgba(124, 92, 255, 0.12);
            color: #7c5cff;
        }
        .history-stat-icon.green {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }
        .history-stat-icon.blue {
            background: rgba(59, 130, 246, 0.12);
            color: #3b82f6;
        }
        .history-stat-icon.amber {
            background: rgba(245, 158, 11, 0.12);
            color: #f59e0b;
        }
        .history-stat-info {
            display: flex;
            flex-direction: column;
        }
        .history-stat-num {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.2;
        }
        .history-stat-lbl {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* Attempts List Section */
        .attempts-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding: 0 4px;
        }
        .attempts-section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .attempts-container {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Attempt Collapsible Card */
        .attempt-card {
            border-radius: 16px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            box-shadow: var(--shadow-xs);
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .attempt-card:hover {
            border-color: rgba(124, 92, 255, 0.4);
            box-shadow: 0 8px 24px rgba(31, 17, 71, 0.08);
        }
        .attempt-card[open] {
            border-color: rgba(124, 92, 255, 0.35);
            box-shadow: 0 10px 30px rgba(31, 17, 71, 0.09);
        }

        .attempt-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 22px;
            background: var(--bg-card);
            cursor: pointer;
            list-style: none;
            outline: none;
            transition: background 0.2s ease;
            user-select: none;
        }
        .attempt-summary::-webkit-details-marker {
            display: none;
        }
        .attempt-card[open] .attempt-summary {
            background: linear-gradient(135deg, rgba(124, 92, 255, 0.06) 0%, transparent 100%);
            border-bottom: 1.5px solid var(--border-color);
        }

        .attempt-sum-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .attempt-index-pill {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 8px;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }
        .attempt-date-time {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .attempt-date-time ion-icon {
            font-size: 16px;
            color: var(--gradient-primary, #7c5cff);
        }

        .attempt-sum-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .score-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.3px;
        }
        .score-badge.perfect {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);
        }
        .score-badge.high {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: #fff;
            box-shadow: 0 3px 10px rgba(6, 182, 212, 0.3);
        }
        .score-badge.passed {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: #fff;
            box-shadow: 0 3px 10px rgba(99, 102, 241, 0.3);
        }
        .score-badge.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #fff;
            box-shadow: 0 3px 10px rgba(245, 158, 11, 0.3);
        }
        .score-badge.low {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #fff;
            box-shadow: 0 3px 10px rgba(239, 68, 68, 0.3);
        }

        .attempt-score-fraction {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .attempt-chevron {
            font-size: 18px;
            color: var(--text-light);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .attempt-card[open] .attempt-chevron {
            transform: rotate(180deg);
            color: var(--gradient-primary, #7c5cff);
        }

        /* Attempt Body (Previous Responses) */
        .attempt-body {
            padding: 22px;
            background: var(--bg-card);
        }

        /* Mini Progress Bar within Attempt */
        .attempt-progress-banner {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: var(--bg-secondary);
            padding: 14px 18px;
            border-radius: 13px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        .attempt-progress-stats {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .attempt-stat-group {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
        }
        .stat-pill.correct {
            background: rgba(16, 185, 129, 0.14);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        .stat-pill.incorrect {
            background: rgba(239, 68, 68, 0.14);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }

        .attempt-segmented-bar {
            width: 100%;
            height: 8px;
            background: rgba(239, 68, 68, 0.18);
            border-radius: 999px;
            overflow: hidden;
            display: flex;
        }
        .attempt-bar-correct {
            background: linear-gradient(90deg, #10b981, #059669);
            height: 100%;
            border-radius: 999px 0 0 999px;
            transition: width 0.4s ease;
        }

        /* Question Response Review Cards */
        .resp-questions-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .resp-question-card {
            background: var(--bg-secondary);
            border: 1.5px solid var(--border-color);
            border-radius: 15px;
            padding: 18px 20px;
            transition: all 0.2s ease;
        }
        .resp-question-card:hover {
            border-color: rgba(124, 92, 255, 0.35);
            box-shadow: var(--shadow-xs);
        }
        .resp-question-card.is-correct-card {
            border-left: 4.5px solid #10b981;
        }
        .resp-question-card.is-incorrect-card {
            border-left: 4.5px solid #ef4444;
        }

        .resp-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .resp-qnum-badge {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .resp-status-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .resp-status-pill.is-correct {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .resp-status-pill.is-incorrect {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .resp-qprompt {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.5;
            margin-bottom: 14px;
        }

        /* Options Grid */
        .resp-options-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .resp-option-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 11px;
            font-size: 13px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            transition: all 0.15s ease;
        }
        .resp-option-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }
        .resp-letter-bubble {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .resp-option-text {
            color: var(--text-primary);
            font-weight: 500;
            line-height: 1.4;
            flex: 1;
        }

        /* Option States */
        .resp-option-row.is-user-correct {
            background: rgba(16, 185, 129, 0.1);
            border-color: #10b981;
        }
        .resp-option-row.is-user-correct .resp-letter-bubble {
            background: #10b981;
            color: #fff;
            border-color: #10b981;
        }
        .resp-option-row.is-user-correct .resp-option-text {
            font-weight: 600;
            color: #065f46;
        }

        .resp-option-row.is-user-wrong {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
        }
        .resp-option-row.is-user-wrong .resp-letter-bubble {
            background: #ef4444;
            color: #fff;
            border-color: #ef4444;
        }
        .resp-option-row.is-user-wrong .resp-option-text {
            font-weight: 600;
            color: #991b1b;
        }

        .resp-option-row.is-missed-correct {
            background: rgba(16, 185, 129, 0.05);
            border: 1.5px dashed #10b981;
        }
        .resp-option-row.is-missed-correct .resp-letter-bubble {
            background: rgba(16, 185, 129, 0.18);
            color: #10b981;
            border-color: #10b981;
        }

        .resp-option-tag {
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .resp-option-tag.correct {
            background: #10b981;
            color: #fff;
        }
        .resp-option-tag.wrong {
            background: #ef4444;
            color: #fff;
        }
        .resp-option-tag.actual {
            background: rgba(16, 185, 129, 0.18);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.4);
        }

        /* Dark Theme Overrides */
        html[data-theme="dark"] .history-hero {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.18) 0%, rgba(168, 85, 247, 0.12) 100%);
            border-color: rgba(124, 92, 255, 0.4);
        }
        html[data-theme="dark"] .attempt-card {
            background: rgba(38, 19, 72, 0.55);
            border-color: rgba(255, 255, 255, 0.1);
        }
        html[data-theme="dark"] .attempt-summary {
            background: rgba(38, 19, 72, 0.55);
        }
        html[data-theme="dark"] .attempt-card[open] .attempt-summary {
            background: rgba(46, 24, 84, 0.9);
            border-color: rgba(255, 255, 255, 0.1);
        }
        html[data-theme="dark"] .attempt-body {
            background: rgba(26, 11, 54, 0.65);
        }
        html[data-theme="dark"] .attempt-progress-banner {
            background: rgba(38, 19, 72, 0.65);
            border-color: rgba(255, 255, 255, 0.08);
        }
        html[data-theme="dark"] .resp-question-card {
            background: rgba(38, 19, 72, 0.65);
            border-color: rgba(255, 255, 255, 0.08);
        }
        html[data-theme="dark"] .resp-option-row {
            background: rgba(26, 11, 54, 0.85);
            border-color: rgba(255, 255, 255, 0.08);
        }
        html[data-theme="dark"] .resp-option-row.is-user-correct .resp-option-text {
            color: #a7f3d0;
        }
        html[data-theme="dark"] .resp-option-row.is-user-wrong .resp-option-text {
            color: #fca5a5;
        }

        @media (max-width: 768px) {
            .history-hero { padding: 18px; }
            .history-stats-grid { grid-template-columns: 1fr; }
            .attempt-summary { flex-direction: column; align-items: flex-start; gap: 10px; }
            .attempt-sum-right { width: 100%; justify-content: space-between; }
        }
    </style>

    <!-- Attempt History Hero Banner -->
    <div class="history-hero">
        <div class="history-hero-top">
            <div class="history-title-area">
                <div class="history-icon-badge">
                    <ion-icon name="time"></ion-icon>
                </div>
                <div>
                    <h1>Attempt History</h1>
                    <div class="history-meta-tags">
                        <span class="history-tag"><ion-icon name="clipboard-outline"></ion-icon> <?php echo htmlspecialchars($quiz['title'], ENT_QUOTES); ?></span>
                        <span class="history-tag"><ion-icon name="book-outline"></ion-icon> <?php echo htmlspecialchars($quiz['course_title'], ENT_QUOTES); ?></span>
                        <span class="history-tag"><ion-icon name="help-circle-outline"></ion-icon> <?php echo count($questions); ?> Questions</span>
                    </div>
                </div>
            </div>
            <div class="history-hero-actions">
                <a class="btn btn-primary" href="quizzes.php?id=<?php echo $quizId; ?>">
                    <ion-icon name="refresh-outline"></ion-icon> Retake Quiz
                </a>
                <a class="btn btn-ghost" href="quizzes.php">
                    <ion-icon name="arrow-back-outline"></ion-icon> All Quizzes
                </a>
            </div>
        </div>

        <!-- Quick Performance Stats Ribbon -->
        <div class="history-stats-grid">
            <div class="history-stat-card">
                <div class="history-stat-icon purple">
                    <ion-icon name="repeat-outline"></ion-icon>
                </div>
                <div class="history-stat-info">
                    <span class="history-stat-num"><?php echo $totalAttempts; ?></span>
                    <span class="history-stat-lbl">Attempts Made</span>
                </div>
            </div>

            <div class="history-stat-card">
                <div class="history-stat-icon green">
                    <ion-icon name="trophy-outline"></ion-icon>
                </div>
                <div class="history-stat-info">
                    <span class="history-stat-num"><?php echo $bestPct; ?>%</span>
                    <span class="history-stat-lbl">Best Score (<?php echo $bestScore; ?> pts)</span>
                </div>
            </div>

            <div class="history-stat-card">
                <div class="history-stat-icon blue">
                    <ion-icon name="speedometer-outline"></ion-icon>
                </div>
                <div class="history-stat-info">
                    <span class="history-stat-num"><?php echo $latestPct; ?>%</span>
                    <span class="history-stat-lbl">Latest Score (<?php echo $latestScore; ?>/<?php echo $latestTotal; ?>)</span>
                </div>
            </div>

            <div class="history-stat-card">
                <div class="history-stat-icon amber">
                    <ion-icon name="ribbon-outline"></ion-icon>
                </div>
                <div class="history-stat-info">
                    <span class="history-stat-num"><?php echo $bestPct === 100 ? 'Mastered' : ($bestPct >= 75 ? 'Proficient' : 'In Progress'); ?></span>
                    <span class="history-stat-lbl">Mastery Level</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Attempt Details List -->
    <div class="attempts-section-header">
        <h2 class="attempts-section-title">
            <ion-icon name="list-outline" style="color:var(--gradient-primary);"></ion-icon>
            Detailed Attempt Breakdown & Responses
        </h2>
        <span style="font-size:12.5px; color:var(--text-light); font-weight:600;">
            Showing <?php echo $totalAttempts; ?> <?php echo $totalAttempts === 1 ? 'attempt' : 'attempts'; ?>
        </span>
    </div>

    <?php if (empty($rows)): ?>
        <div class="empty">
            <div class="ico"><ion-icon name="time-outline"></ion-icon></div>
            <h3>No attempts recorded yet</h3>
            <p>Take this quiz to generate instant evaluation and comprehensive question-by-question reviews.</p>
            <a class="btn btn-primary" href="quizzes.php?id=<?php echo $quizId; ?>" style="margin-top:14px;">
                <ion-icon name="play-outline"></ion-icon> Take Quiz Now
            </a>
        </div>
    <?php else: ?>
        <div class="attempts-container">
            <?php foreach ($rows as $attemptIdx => $r): 
                $pct = $r['total'] > 0 ? (int)round(($r['score'] * 100) / $r['total']) : 0;
                $scoreBadgeClass = 'low';
                $scoreBadgeText = $pct . '% · Review Needed';
                if ($pct === 100) {
                    $scoreBadgeClass = 'perfect';
                    $scoreBadgeText = '🏆 100% Perfect';
                } elseif ($pct >= 80) {
                    $scoreBadgeClass = 'high';
                    $scoreBadgeText = '⭐ ' . $pct . '% High Mastery';
                } elseif ($pct >= 60) {
                    $scoreBadgeClass = 'passed';
                    $scoreBadgeText = '🎯 ' . $pct . '% Passed';
                } elseif ($pct >= 40) {
                    $scoreBadgeClass = 'warning';
                    $scoreBadgeText = '⚠️ ' . $pct . '% Needs Work';
                }

                $resp = json_decode((string)($r['responses'] ?? '[]'), true) ?? [];
                
                // Calculate correct / incorrect count
                $correctCount = 0;
                $incorrectCount = 0;
                foreach ($questions as $q) {
                    $given = $resp[$q['id']] ?? null;
                    if ($given === $q['correct']) {
                        $correctCount++;
                    } else {
                        $incorrectCount++;
                    }
                }
                $attemptNumber = count($rows) - $attemptIdx;
            ?>
                <details class="attempt-card" <?php echo $attemptIdx === 0 ? 'open' : ''; ?>>
                    <summary class="attempt-summary">
                        <div class="attempt-sum-left">
                            <span class="attempt-index-pill">Attempt #<?php echo $attemptNumber; ?></span>
                            <span class="attempt-date-time">
                                <ion-icon name="calendar-outline"></ion-icon>
                                <?php echo htmlspecialchars(date('M j, Y · g:i A', strtotime($r['attempted_at'])), ENT_QUOTES); ?>
                            </span>
                        </div>
                        <div class="attempt-sum-right">
                            <span class="score-badge <?php echo $scoreBadgeClass; ?>">
                                <?php echo $scoreBadgeText; ?>
                            </span>
                            <span class="attempt-score-fraction">
                                <?php echo (int)$r['score']; ?> / <?php echo (int)$r['total']; ?> pts
                            </span>
                            <ion-icon name="chevron-down-outline" class="attempt-chevron"></ion-icon>
                        </div>
                    </summary>

                    <div class="attempt-body">
                        <!-- Mini Breakdown Banner -->
                        <div class="attempt-progress-banner">
                            <div class="attempt-progress-stats">
                                <div class="attempt-stat-group">
                                    <span class="stat-pill correct"><ion-icon name="checkmark-circle"></ion-icon> <?php echo $correctCount; ?> Correct</span>
                                    <span class="stat-pill incorrect"><ion-icon name="close-circle"></ion-icon> <?php echo $incorrectCount; ?> Incorrect</span>
                                </div>
                                <span style="color:var(--text-light); font-size:12px;">Accuracy: <strong style="color:var(--text-primary);"><?php echo $pct; ?>%</strong></span>
                            </div>
                            <div class="attempt-segmented-bar">
                                <div class="attempt-bar-correct" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                        </div>

                        <!-- Previous Responses Per Question -->
                        <div class="resp-questions-list">
                            <?php foreach ($questions as $qIdx => $q): 
                                $given = $resp[$q['id']] ?? null;
                                $isCorrect = ($given === $q['correct']);
                                $cardClass = $isCorrect ? 'is-correct-card' : 'is-incorrect-card';
                            ?>
                                <div class="resp-question-card <?php echo $cardClass; ?>">
                                    <div class="resp-card-header">
                                        <span class="resp-qnum-badge">
                                            <ion-icon name="help-circle-outline"></ion-icon>
                                            Question <?php echo ($qIdx + 1); ?> of <?php echo count($questions); ?>
                                        </span>
                                        <?php if ($isCorrect): ?>
                                            <span class="resp-status-pill is-correct">
                                                <ion-icon name="checkmark-circle"></ion-icon> Correct (+1 pt)
                                            </span>
                                        <?php else: ?>
                                            <span class="resp-status-pill is-incorrect">
                                                <ion-icon name="close-circle"></ion-icon> Incorrect (0 pts)
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="resp-qprompt">
                                        <?php echo htmlspecialchars($q['prompt'], ENT_QUOTES); ?>
                                    </div>

                                    <ul class="resp-options-grid">
                                        <?php 
                                        $optionsMap = [
                                            'a' => 'option_a',
                                            'b' => 'option_b',
                                            'c' => 'option_c',
                                            'd' => 'option_d'
                                        ];
                                        foreach ($optionsMap as $letter => $col): 
                                            if (empty($q[$col])) continue;
                                            $isGiven = ($given === $letter);
                                            $isActualCorrect = ($q['correct'] === $letter);

                                            $rowStateClass = 'is-neutral';
                                            if ($isGiven && $isActualCorrect) {
                                                $rowStateClass = 'is-user-correct';
                                            } elseif ($isGiven && !$isActualCorrect) {
                                                $rowStateClass = 'is-user-wrong';
                                            } elseif (!$isGiven && $isActualCorrect) {
                                                $rowStateClass = 'is-missed-correct';
                                            }
                                        ?>
                                            <li class="resp-option-row <?php echo $rowStateClass; ?>">
                                                <div class="resp-option-left">
                                                    <span class="resp-letter-bubble"><?php echo strtoupper($letter); ?></span>
                                                    <span class="resp-option-text"><?php echo htmlspecialchars($q[$col], ENT_QUOTES); ?></span>
                                                </div>

                                                <div class="resp-option-badges">
                                                    <?php if ($isGiven && $isActualCorrect): ?>
                                                        <span class="resp-option-tag correct">
                                                            <ion-icon name="checkmark-circle"></ion-icon> Your Answer (Correct)
                                                        </span>
                                                    <?php elseif ($isGiven && !$isActualCorrect): ?>
                                                        <span class="resp-option-tag wrong">
                                                            <ion-icon name="close-circle"></ion-icon> Your Answer
                                                        </span>
                                                    <?php elseif (!$isGiven && $isActualCorrect): ?>
                                                        <span class="resp-option-tag actual">
                                                            <ion-icon name="checkmark-done-circle"></ion-icon> Correct Answer
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
    render_dashboard_footer();
    return;
}

// -------------------------------------------------------------
// No quiz_id specified: show all history across all quizzes
// -------------------------------------------------------------
$all = $pdo->prepare(
    'SELECT qa.*, q.title AS quiz_title, q.subject, COALESCE(c.title, "General Academic Quiz") AS course_title
       FROM quiz_attempts qa
       JOIN quizzes q ON q.id = qa.quiz_id
       LEFT JOIN courses c ON c.id = q.course_id
      WHERE qa.student_id = :u
      ORDER BY qa.attempted_at DESC LIMIT 60'
);
$all->execute([':u' => $uid]);
$rows = $all->fetchAll();

// Global stats
$totalTaken = count($rows);
$perfectCount = 0;
$totalScoreAccum = 0;
$totalPossibleAccum = 0;

foreach ($rows as $r) {
    if ($r['total'] > 0 && $r['score'] === $r['total']) {
        $perfectCount++;
    }
    $totalScoreAccum += (int)$r['score'];
    $totalPossibleAccum += (int)$r['total'];
}
$overallAccuracy = $totalPossibleAccum > 0 ? (int)round(($totalScoreAccum * 100) / $totalPossibleAccum) : 0;

render_dashboard_header(
    'Quiz History',
    student_nav(),
    'quizzes',
    ['Home', 'Quizzes', 'History']
);
?>

<style>
    .all-history-hero {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.09) 0%, rgba(168, 85, 247, 0.06) 100%);
        border: 1.5px solid rgba(124, 92, 255, 0.24);
        border-radius: 20px;
        padding: 26px 28px;
        margin-bottom: 26px;
        box-shadow: 0 4px 20px rgba(31, 17, 71, 0.04);
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .all-history-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
    }
    .all-history-title {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .all-history-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 6px 16px rgba(99, 102, 241, 0.35);
        flex-shrink: 0;
    }
    .all-history-title h1 {
        font-size: 22px;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.25;
        margin-bottom: 2px;
    }
    .all-history-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
    }
    .table-card {
        background: var(--bg-card);
        border: 1.5px solid var(--border-color);
        border-radius: 18px;
        padding: 8px 16px;
        box-shadow: var(--shadow-xs);
        overflow-x: auto;
    }
    .review-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 8px;
        background: rgba(124, 92, 255, 0.1);
        color: var(--gradient-primary, #7c5cff);
        border: 1px solid rgba(124, 92, 255, 0.25);
        transition: all 0.2s ease;
    }
    .review-btn:hover {
        background: var(--gradient-primary, #7c5cff);
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(124, 92, 255, 0.25);
    }
</style>

<div class="all-history-hero">
    <div class="all-history-top">
        <div class="all-history-title">
            <div class="all-history-icon">
                <ion-icon name="file-tray-full"></ion-icon>
            </div>
            <div>
                <h1>Comprehensive Quiz History</h1>
                <p style="font-size:13px; color:var(--text-light); margin:0;">
                    Complete record of all self-assessments, subject tests, and per-question answer reviews.
                </p>
            </div>
        </div>
        <div>
            <a class="btn btn-primary" href="quizzes.php">
                <ion-icon name="grid-outline"></ion-icon> Explore All Quizzes
            </a>
        </div>
    </div>

    <div class="all-history-stats">
        <div class="history-stat-card">
            <div class="history-stat-icon purple">
                <ion-icon name="checkmark-done-outline"></ion-icon>
            </div>
            <div class="history-stat-info">
                <span class="history-stat-num"><?php echo $totalTaken; ?></span>
                <span class="history-stat-lbl">Quizzes Attempted</span>
            </div>
        </div>

        <div class="history-stat-card">
            <div class="history-stat-icon green">
                <ion-icon name="ribbon-outline"></ion-icon>
            </div>
            <div class="history-stat-info">
                <span class="history-stat-num"><?php echo $perfectCount; ?></span>
                <span class="history-stat-lbl">100% Perfect Scores</span>
            </div>
        </div>

        <div class="history-stat-card">
            <div class="history-stat-icon blue">
                <ion-icon name="speedometer-outline"></ion-icon>
            </div>
            <div class="history-stat-info">
                <span class="history-stat-num"><?php echo $overallAccuracy; ?>%</span>
                <span class="history-stat-lbl">Cumulative Accuracy</span>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <?php if (empty($rows)): ?>
        <div class="empty" style="padding: 40px 20px;">
            <div class="ico"><ion-icon name="help-circle-outline"></ion-icon></div>
            <h3>No quiz attempts recorded</h3>
            <p>Complete a subject quiz to track your progress and review detailed answer breakdowns.</p>
            <a class="btn btn-primary" href="quizzes.php" style="margin-top:12px;">Browse Quizzes</a>
        </div>
    <?php else: ?>
        <table class="simple" style="margin: 8px 0;">
            <thead>
                <tr>
                    <th>Quiz Title</th>
                    <th>Date & Time</th>
                    <th>Score</th>
                    <th>Performance</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): 
                    $pct = $r['total'] > 0 ? (int)round(($r['score'] * 100) / $r['total']) : 0;
                ?>
                    <tr>
                        <td>
                            <strong style="color:var(--text-primary); font-size:13.5px;"><?php echo htmlspecialchars($r['quiz_title'], ENT_QUOTES); ?></strong>
                            <div style="font-size:11.5px; color:var(--text-light); margin-top:2px; display:flex; gap:6px;">
                                <span class="tag" style="font-size:10px; padding:2px 7px;"><?php echo htmlspecialchars($r['subject'], ENT_QUOTES); ?></span>
                                <span>&middot; <?php echo htmlspecialchars($r['course_title'], ENT_QUOTES); ?></span>
                            </div>
                        </td>
                        <td style="font-size:12.5px; color:var(--text-secondary); white-space:nowrap;">
                            <?php echo htmlspecialchars(date('M j, Y · g:i A', strtotime($r['attempted_at'])), ENT_QUOTES); ?>
                        </td>
                        <td style="font-weight:700; font-size:13px; color:var(--text-primary);">
                            <?php echo (int)$r['score']; ?> / <?php echo (int)$r['total']; ?>
                        </td>
                        <td>
                            <?php if ($pct === 100): ?>
                                <span class="score-badge perfect" style="font-size:11px; padding:3px 9px;">🏆 100%</span>
                            <?php elseif ($pct >= 75): ?>
                                <span class="score-badge high" style="font-size:11px; padding:3px 9px;">⭐ <?php echo $pct; ?>%</span>
                            <?php elseif ($pct >= 50): ?>
                                <span class="score-badge passed" style="font-size:11px; padding:3px 9px;">🎯 <?php echo $pct; ?>%</span>
                            <?php else: ?>
                                <span class="score-badge low" style="font-size:11px; padding:3px 9px;">⚠️ <?php echo $pct; ?>%</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <a class="review-btn" href="quiz_result.php?quiz_id=<?php echo (int)$r['quiz_id']; ?>">
                                <ion-icon name="eye-outline"></ion-icon> Review Responses
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php render_dashboard_footer(); ?>
