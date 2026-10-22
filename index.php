<?php
session_start();
// Auto-redirect if logged in
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';
    $redirect = [
        'admin' => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/dashboard.php'
    ];
    header("Location: " . ($redirect[$role] ?? 'student/dashboard.php'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduMate · Study Smarter Together</title>
    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@600;700&family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- Page loader -->
    <div class="page-loader" id="pageLoader" aria-hidden="true">
        <div class="page-loader-inner">
            <div class="loader">
                <svg id="pegtopone" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <defs>
                        <filter id="shine-one"><feGaussianBlur stdDeviation="3"/></filter>
                        <mask id="mask-one"><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="white"/></mask>
                        <radialGradient id="g1-one" cx="50" cy="66" r="30" gradientTransform="translate(0 35) scale(1 0.5)"><stop offset="0%" stop-color="black" stop-opacity="0.3"/><stop offset="50%" stop-color="black" stop-opacity="0.1"/><stop offset="100%" stop-color="black" stop-opacity="0"/></radialGradient>
                        <radialGradient id="g2-one" cx="55" cy="20" r="30"><stop offset="0%" stop-color="white" stop-opacity="0.3"/><stop offset="50%" stop-color="white" stop-opacity="0.1"/><stop offset="100%" stop-color="white" stop-opacity="0"/></radialGradient>
                    </defs>
                    <g><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="currentColor"/><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="url(#g1-one)"/><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="url(#g2-one)"/></g>
                </svg>
                <svg id="pegtoptwo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <defs>
                        <filter id="shine-two"><feGaussianBlur stdDeviation="3"/></filter>
                        <mask id="mask-two"><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="white"/></mask>
                        <radialGradient id="g1-two" cx="50" cy="66" r="30" gradientTransform="translate(0 35) scale(1 0.5)"><stop offset="0%" stop-color="black" stop-opacity="0.3"/><stop offset="50%" stop-color="black" stop-opacity="0.1"/><stop offset="100%" stop-color="black" stop-opacity="0"/></radialGradient>
                        <radialGradient id="g2-two" cx="55" cy="20" r="30"><stop offset="0%" stop-color="white" stop-opacity="0.3"/><stop offset="50%" stop-color="white" stop-opacity="0.1"/><stop offset="100%" stop-color="white" stop-opacity="0"/></radialGradient>
                    </defs>
                    <g><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="currentColor"/><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="url(#g1-two)"/><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="url(#g2-two)"/></g>
                </svg>
                <svg id="pegtopthree" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
                    <defs>
                        <filter id="shine-three"><feGaussianBlur stdDeviation="3"/></filter>
                        <mask id="mask-three"><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="white"/></mask>
                        <radialGradient id="g1-three" cx="50" cy="66" r="30" gradientTransform="translate(0 35) scale(1 0.5)"><stop offset="0%" stop-color="black" stop-opacity="0.3"/><stop offset="50%" stop-color="black" stop-opacity="0.1"/><stop offset="100%" stop-color="black" stop-opacity="0"/></radialGradient>
                        <radialGradient id="g2-three" cx="55" cy="20" r="30"><stop offset="0%" stop-color="white" stop-opacity="0.3"/><stop offset="50%" stop-color="white" stop-opacity="0.1"/><stop offset="100%" stop-color="white" stop-opacity="0"/></radialGradient>
                    </defs>
                    <g><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="currentColor"/><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="url(#g1-three)"/><path d="M63,37c-6.7-4-4-27-13-27s-6.3,23-13,27-27,4-27,13,20.3,9,27,13,4,27,13,27,6.3-23,13-27,27-4,27-13-20.3-9-27-13Z" fill="url(#g2-three)"/></g>
                </svg>
            </div>
            <span class="page-loader-label">Loading your study space 📚</span>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <nav class="navbar" aria-label="Main navigation">
            <a href="index.php" class="logo" aria-label="EduMate home">
                <span class="logo-mark">
                    <img src="favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;">
                </span>
                <span class="logo-wordmark">EduMate</span>
            </a>
            <div class="nav-links">
                <a href="#boards">Boards</a>
                <a href="#features">Features</a>
                <a href="#app">App</a>
                <a href="#subscriptions">Plans</a>
                <a href="#privacy">Privacy</a>
                <a href="#faq">FAQ</a>
            </div>
            <div class="icon-links">
                <label class="theme-switch" aria-label="Toggle dark mode">
                    <input type="checkbox" class="theme-switch__checkbox">
                    <div class="theme-switch__container">
                        <div class="theme-switch__clouds"></div>
                        <div class="theme-switch__stars-container"><svg viewBox="0 0 144 55" fill="none"><path d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688Z" fill="currentColor"/></svg></div>
                        <div class="theme-switch__circle-container"><div class="theme-switch__sun-moon-container"><div class="theme-switch__moon"><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div></div></div></div>
                    </div>
                </label>
                <a href="login.php" class="icon-button" aria-label="Login"><i class="fas fa-user"></i></a>
                <a href="register.php" class="play-btn">Start free</a>
            </div>
            <button type="button" class="nav-toggle" aria-label="Open menu" aria-expanded="false">
                <span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span>
            </button>
        </nav>
    </header>

    <!-- Mobile drawer -->
    <div id="navOverlay" class="nav-overlay" hidden></div>
    <aside id="mobileDrawer" class="mobile-drawer" aria-label="Mobile menu" aria-hidden="true" inert>
        <div class="mobile-drawer-head">
            <a href="index.php" class="logo"><span class="logo-mark"><img src="favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;"></span><span class="logo-wordmark">EduMate</span></a>
            <button type="button" class="mobile-drawer-close" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <nav class="mobile-drawer-links">
            <a href="#boards">🎓 Boards</a>
            <a href="#features">✨ Features</a>
            <a href="#app">📱 App</a>
            <a href="#subscriptions">📋 Plans</a>
            <a href="#privacy">🔒 Privacy</a>
            <a href="#faq">❓ FAQ</a>
        </nav>
        <div class="mobile-drawer-actions">
            <label class="theme-switch theme-switch--mobile" aria-label="Toggle dark mode">
                <input type="checkbox" class="theme-switch__checkbox">
                <div class="theme-switch__container"><div class="theme-switch__clouds"></div><div class="theme-switch__stars-container"><svg viewBox="0 0 144 55" fill="none"><path d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688Z" fill="currentColor"/></svg></div><div class="theme-switch__circle-container"><div class="theme-switch__sun-moon-container"><div class="theme-switch__moon"><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div></div></div></div></div>
            </label>
            <a href="login.php" class="mobile-drawer-btn mobile-drawer-btn--ghost"><i class="fas fa-user"></i> Sign in</a>
            <a href="register.php" class="mobile-drawer-btn mobile-drawer-btn--primary"><i class="fa-solid fa-rocket"></i> Start studying</a>
        </div>
    </aside>

    <main>
        <!-- Hero Section -->
        <section class="hero" id="signup">
            <div class="hero-content">
                <p class="eyebrow">📘 Study smarter, together</p>
                <h1 class="typewriter">
                    Master every board,<br>
                    <span class="typewriter-text"></span><span class="typewriter-caret">▌</span>
                </h1>
                <p class="hero-copy"><strong>EduMate</strong> turns your notes into playful flashcards, hosts group study rooms, and keeps you on track with a tiny pomodoro timer that doesn't yell at you. Built for students across all major Indian boards.</p>
                <div class="study-tabs">
                    <button class="study-tab active" type="button">📚 Flashcards</button>
                    <button class="study-tab" type="button">🎧 Study Rooms</button>
                    <button class="study-tab" type="button">🍅 Focus</button>
                </div>
                <div class="hero-actions">
                    <a href="register.php" class="play-btn play-btn--coral"><i class="fa-solid fa-rocket"></i><span>Start studying free</span></a>
                    <a href="#features" class="play-btn play-btn--ghost"><i class="fa-solid fa-circle-play"></i><span>See how it works</span></a>
                </div>
                <div class="streak-row">
                    <span class="is-fire" title="Mon"></span><span class="is-fire" title="Tue"></span><span class="is-fire" title="Wed"></span><span class="is-fire" title="Thu"></span><span class="is-on" title="Today"></span><span title="Sat"></span><span title="Sun"></span>
                    <em style="font-style:normal; color:var(--muted); font-size:0.78rem; font-weight:600; margin-left:6px;">5 day streak 🔥</em>
                </div>
            </div>
            <!-- Study Widget -->
            <div class="sw-wrapper">
                <div class="sw-decor sw-decor--tl" aria-hidden="true"><span>📚</span> CBSE</div>
                <div class="sw-decor sw-decor--tr" aria-hidden="true"><span>🔥</span> 5-day streak</div>
                <div class="sw-decor sw-decor--bl" aria-hidden="true"><span>⭐</span> 1,240 XP</div>
                <div class="sw-decor sw-decor--br" aria-hidden="true"><span>🍅</span> Focus mode</div>
                <div class="sw-decor sw-decor--mid-l" aria-hidden="true"><span>✏️</span> Quiz time!</div>
                <div class="sw-decor sw-decor--mid-r" aria-hidden="true"><span>💡</span> New card</div>
                <span class="sw-dot sw-dot--1"></span>
                <span class="sw-dot sw-dot--2"></span>
                <span class="sw-dot sw-dot--3"></span>
                <span class="sw-dot sw-dot--4"></span>

                <div class="study-widget" id="studyWidget" aria-label="Interactive study dashboard">
                    <span class="sw-orb sw-orb--violet"></span>
                    <span class="sw-orb sw-orb--coral"></span>
                    <span class="sw-orb sw-orb--teal"></span>

                    <div class="sw-topbar">
                        <div class="sw-topbar__left">
                            <span class="sw-live-dot"></span>
                            <span class="sw-topbar__label">EduMate Study Room</span>
                        </div>
                        <div class="sw-topbar__avatars">
                            <span class="sw-av" style="background:var(--violet);">A</span>
                            <span class="sw-av" style="background:var(--coral);">M</span>
                            <span class="sw-av" style="background:var(--teal);">R</span>
                            <span class="sw-av sw-av--more">+9</span>
                        </div>
                        <span class="sw-topbar__badge"><i class="fa-solid fa-circle-dot"></i> 12 online</span>
                    </div>

                    <div class="sw-tabs" role="tablist">
                        <button class="sw-tab active" data-sw-tab="flashcard" role="tab" aria-selected="true">📚 Flashcard</button>
                        <button class="sw-tab" data-sw-tab="quiz" role="tab" aria-selected="false">🧠 Quiz</button>
                        <button class="sw-tab" data-sw-tab="focus" role="tab" aria-selected="false">🍅 Focus</button>
                    </div>

                    <!-- Flashcard Panel -->
                    <div class="sw-panel" id="sw-panel-flashcard">
                        <div class="sw-flashcard" id="swFlashcard" tabindex="0" aria-label="Flip flashcard">
                            <div class="sw-flashcard__inner">
                                <div class="sw-flashcard__face sw-flashcard__face--front">
                                    <span class="sw-fc-label">Question</span>
                                    <p class="sw-fc-text" id="swFcQuestion">What is Newton's Second Law?</p>
                                    <span class="sw-fc-hint">Click to reveal answer ✨</span>
                                </div>
                                <div class="sw-flashcard__face sw-flashcard__face--back">
                                    <span class="sw-fc-label">Answer</span>
                                    <p class="sw-fc-text" id="swFcAnswer">F = ma — Force equals mass times acceleration.</p>
                                    <span class="sw-fc-hint">Next card →</span>
                                </div>
                            </div>
                        </div>
                        <div class="sw-fc-controls">
                            <button class="sw-fc-btn" id="swFcPrev" aria-label="Previous card"><i class="fa-solid fa-chevron-left"></i></button>
                            <span class="sw-fc-counter" id="swFcCounter">1 / 5</span>
                            <button class="sw-fc-btn" id="swFcNext" aria-label="Next card"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <div class="sw-fc-progress">
                            <div class="sw-fc-progress__bar" id="swFcProgressBar" style="width:20%"></div>
                        </div>
                    </div>

                    <!-- Quiz Panel -->
                    <div class="sw-panel" id="sw-panel-quiz" hidden>
                        <p class="sw-quiz__q" id="swQuizQ">Which board follows the NCERT syllabus?</p>
                        <div class="sw-quiz__options" id="swQuizOptions">
                            <button class="sw-quiz__opt" data-correct="false">ICSE</button>
                            <button class="sw-quiz__opt" data-correct="true">CBSE</button>
                            <button class="sw-quiz__opt" data-correct="false">WBBSE</button>
                            <button class="sw-quiz__opt" data-correct="false">Maharashtra Board</button>
                        </div>
                        <div class="sw-quiz__feedback" id="swQuizFeedback" hidden></div>
                        <button class="sw-quiz__next play-btn play-btn--ghost" id="swQuizNext" hidden>Next Question <i class="fa-solid fa-arrow-right"></i></button>
                    </div>

                    <!-- Focus Panel -->
                    <div class="sw-panel" id="sw-panel-focus" hidden>
                        <div class="sw-pomodoro">
                            <svg class="sw-pom-ring" viewBox="0 0 120 120" aria-hidden="true">
                                <circle class="sw-pom-track" cx="60" cy="60" r="52"/>
                                <circle class="sw-pom-progress" id="swPomProgress" cx="60" cy="60" r="52"/>
                            </svg>
                            <div class="sw-pom-inner">
                                <span class="sw-pom-time" id="swPomTime">25:00</span>
                                <span class="sw-pom-label" id="swPomLabel">Focus</span>
                            </div>
                        </div>
                        <div class="sw-pom-controls">
                            <button class="sw-pom-btn" id="swPomToggle"><i class="fa-solid fa-play"></i> Start</button>
                            <button class="sw-pom-btn sw-pom-btn--ghost" id="swPomReset"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                        </div>
                        <div class="sw-pom-sessions">
                            <span id="swPomSessionDots"></span>
                            <em>Session <span id="swPomSessionNum">1</span> of 4</em>
                        </div>
                    </div>

                    <!-- Bottom stats -->
                    <div class="sw-stats">
                        <div class="sw-stat">
                            <span class="sw-stat__emoji">🔥</span>
                            <strong class="sw-stat__num" id="swStreakNum">5</strong>
                            <span class="sw-stat__label">day streak</span>
                        </div>
                        <div class="sw-stat">
                            <span class="sw-stat__emoji">⭐</span>
                            <strong class="sw-stat__num" id="swXpNum">1240</strong>
                            <span class="sw-stat__label">XP earned</span>
                        </div>
                        <div class="sw-stat">
                            <span class="sw-stat__emoji">📖</span>
                            <strong class="sw-stat__num" id="swCardsNum">48</strong>
                            <span class="sw-stat__label">cards done</span>
                        </div>
                    </div>

                    <div class="sw-burst-layer" id="swBurstLayer" aria-hidden="true"></div>
                </div>
            </div>
        </section>

        <!-- Boards Section -->
        <section class="boards-section reveal" id="boards">
            <div class="section-heading">
                <p class="eyebrow" style="--accent:var(--amber);">🎓 Supported Boards</p>
                <h2>Pick your <span class="highlight highlight--mint">board</span>, start studying.</h2>
                <p class="section-sub">EduMate has curated content, past papers, and study rooms tailored to every major Indian curriculum.</p>
            </div>
            <div class="boards-grid">
                <article class="board-card board-card--icse">
                    <div class="board-card__top">
                        <span class="board-emoji">📘</span>
                        <div>
                            <h3 class="board-card__name">ICSE</h3>
                            <span class="board-card__full">Indian Certificate of Secondary Education</span>
                        </div>
                        <span class="board-badge board-badge--blue">Class 1–10</span>
                    </div>
                    <p class="board-card__desc">Comprehensive English-medium curriculum by CISCE. Strong focus on analytical thinking, literature, and science practicals.</p>
                    <ul class="board-card__subjects">
                        <li><i class="fa-solid fa-flask"></i> Physics, Chemistry, Biology</li>
                        <li><i class="fa-solid fa-calculator"></i> Mathematics</li>
                        <li><i class="fa-solid fa-book-open"></i> English Literature &amp; Language</li>
                        <li><i class="fa-solid fa-globe"></i> History &amp; Geography</li>
                    </ul>
                    <div class="board-card__foot">
                        <span><i class="fa-solid fa-file-lines"></i> Past papers</span>
                        <span><i class="fa-solid fa-users"></i> Study rooms</span>
                        <a href="#" class="play-btn play-btn--ghost board-card__cta">Explore ICSE <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </article>

                <article class="board-card board-card--cbse">
                    <div class="board-card__top">
                        <span class="board-emoji">📗</span>
                        <div>
                            <h3 class="board-card__name">CBSE</h3>
                            <span class="board-card__full">Central Board of Secondary Education</span>
                        </div>
                        <span class="board-badge board-badge--green">Class 1–12</span>
                    </div>
                    <p class="board-card__desc">India's most widely followed national board. NCERT-aligned syllabus with a strong emphasis on competitive exam readiness (JEE, NEET).</p>
                    <ul class="board-card__subjects">
                        <li><i class="fa-solid fa-atom"></i> Science (PCM / PCB)</li>
                        <li><i class="fa-solid fa-calculator"></i> Mathematics &amp; Applied Maths</li>
                        <li><i class="fa-solid fa-landmark"></i> Social Science</li>
                        <li><i class="fa-solid fa-language"></i> Hindi &amp; English Core</li>
                    </ul>
                    <div class="board-card__foot">
                        <span><i class="fa-solid fa-file-lines"></i> Past papers</span>
                        <span><i class="fa-solid fa-users"></i> Study rooms</span>
                        <a href="#" class="play-btn play-btn--mint board-card__cta">Explore CBSE <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </article>

                <article class="board-card board-card--wbbse">
                    <div class="board-card__top">
                        <span class="board-emoji">📙</span>
                        <div>
                            <h3 class="board-card__name">WBBSE</h3>
                            <span class="board-card__full">West Bengal Board of Secondary Education</span>
                        </div>
                        <span class="board-badge board-badge--amber">Class 5–10</span>
                    </div>
                    <p class="board-card__desc">State board for West Bengal with bilingual (Bengali &amp; English) content. Covers Madhyamik and Higher Secondary levels with regional context.</p>
                    <ul class="board-card__subjects">
                        <li><i class="fa-solid fa-flask"></i> Life Science &amp; Physical Science</li>
                        <li><i class="fa-solid fa-calculator"></i> Mathematics</li>
                        <li><i class="fa-solid fa-pen-nib"></i> Bengali &amp; English</li>
                        <li><i class="fa-solid fa-earth-asia"></i> History &amp; Geography</li>
                    </ul>
                    <div class="board-card__foot">
                        <span><i class="fa-solid fa-file-lines"></i> Past papers</span>
                        <span><i class="fa-solid fa-users"></i> Study rooms</span>
                        <a href="#" class="play-btn play-btn--ghost board-card__cta">Explore WBBSE <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </article>

                <article class="board-card board-card--maha">
                    <div class="board-card__top">
                        <span class="board-emoji">📕</span>
                        <div>
                            <h3 class="board-card__name">Maharashtra Board</h3>
                            <span class="board-card__full">Maharashtra State Board of Secondary &amp; Higher Secondary Education</span>
                        </div>
                        <span class="board-badge board-badge--coral">Class 1–12</span>
                    </div>
                    <p class="board-card__desc">State board for Maharashtra with SSC (Class 10) and HSC (Class 12) streams. Bilingual Marathi &amp; English medium with strong vocational options.</p>
                    <ul class="board-card__subjects">
                        <li><i class="fa-solid fa-flask"></i> Science &amp; Technology</li>
                        <li><i class="fa-solid fa-calculator"></i> Algebra &amp; Geometry</li>
                        <li><i class="fa-solid fa-pen-nib"></i> Marathi &amp; English</li>
                        <li><i class="fa-solid fa-landmark"></i> History &amp; Political Science</li>
                    </ul>
                    <div class="board-card__foot">
                        <span><i class="fa-solid fa-file-lines"></i> Past papers</span>
                        <span><i class="fa-solid fa-users"></i> Study rooms</span>
                        <a href="#" class="play-btn play-btn--coral board-card__cta">Explore Maha Board <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </article>
            </div>
        </section>

        <!-- Features Section -->
        <section class="feature-band reveal" id="features">
            <div class="features-hero">
                <div class="features-hero-text">
                    <p class="eyebrow">Features</p>
                    <h2>Everything a modern study platform needs.</h2>
                    <p class="section-sub">From flashcards to focus timers, EduMate bundles the essentials every student and study group relies on — built on a foundation of trust.</p>
                    <div class="features-hero-actions" style="margin-top: 3rem;">
                        <a href="#" class="btn primary"><i class="fa-solid fa-rocket"></i> Start free</a>
                        <a href="#" class="btn secondary"><i class="fa-solid fa-circle-play"></i> Watch demo</a>
                    </div>
                </div>
                <div class="features-hero-stats">
                    <div class="fh-stat"><strong data-counter="4">0</strong><span>K+</span><em>Enrolled Students</em></div>
                    <div class="fh-divider"></div>
                    <div class="fh-stat"><strong data-counter="120">0</strong><span>+</span><em>Courses Offered</em></div>
                    <div class="fh-divider"></div>
                    <div class="fh-stat"><strong data-counter="99">0</strong><span>%</span><em>Completion Rate</em></div>
                    <div class="fh-divider"></div>
                    <div class="fh-stat"><strong data-counter="35">0</strong><span>K+</span><em>Hours of Content</em></div>
                </div>
            </div>

            <div class="features-grid">
                <article class="fx-card fx-card--flashcards">
                    <div class="fx-card-head">
                        <span class="fx-icon fx-icon--violet"><i class="fa-solid fa-layer-group"></i></span>
                        <div class="fx-card-titles"><h3>Smart Flashcards</h3><span class="fx-sub">Master concepts with adaptive spaced repetition.</span></div>
                    </div>
                    <div class="fx-flashcard">
                        <div class="fx-flashcard-inner">
                            <div class="fx-flashcard-face fx-flashcard-front"><span class="fx-fc-label">Question</span><strong>What is mitochondria?</strong><em class="fx-fc-counter">3 / 12</em></div>
                            <div class="fx-flashcard-face fx-flashcard-back"><span class="fx-fc-label fx-fc-label--back">Answer</span><strong>Powerhouse of the cell</strong><em>Tap to reveal</em></div>
                        </div>
                    </div>
                    <div class="fx-foot fx-foot--center"><span><i class="fa-solid fa-rotate"></i> Spaced Rep.</span><span><i class="fa-solid fa-bullseye"></i> Targeted</span></div>
                </article>

                <article class="fx-card fx-card--focus">
                    <div class="fx-card-head">
                        <span class="fx-icon fx-icon--teal"><i class="fa-solid fa-stopwatch"></i></span>
                        <div class="fx-card-titles"><h3>Focus Timer</h3><span class="fx-sub">Pomodoro sessions with break reminders.</span></div>
                    </div>
                    <div class="fx-timer-ring">
                        <div class="fx-timer-inner">
                            <strong>25:00</strong>
                            <em>Focus session</em>
                        </div>
                    </div>
                    <div class="fx-timer-actions"><span class="fx-timer-pill"><i class="fa-solid fa-play"></i> Start</span><span class="fx-timer-pill"><i class="fa-solid fa-mug-hot"></i> 5 min</span><span class="fx-timer-pill"><i class="fa-solid fa-mug-saucer"></i> 15 min</span></div>
                </article>

                <article class="fx-card fx-card--notes">
                    <div class="fx-card-head">
                        <span class="fx-icon fx-icon--amber"><i class="fa-solid fa-note-sticky"></i></span>
                        <div class="fx-card-titles"><h3>Collaborative Notes</h3><span class="fx-sub">Write, share, and revise notes with peers.</span></div>
                    </div>
                    <ul class="fx-notes-list">
                        <li><i class="fa-solid fa-bookmark"></i> Chapter 4: Cell Biology</li>
                        <li><i class="fa-solid fa-bookmark"></i> Key formulas to remember</li>
                        <li><i class="fa-solid fa-bookmark"></i> Practice exam questions</li>
                    </ul>
                    <div class="fx-foot fx-foot--center"><span><i class="fa-solid fa-pen-to-square"></i> Edit</span><span><i class="fa-solid fa-share-from-square"></i> Share</span></div>
                </article>
            </div>

            <div class="pricing-calculator-card study-plan-card">
                <div class="calc-header"><i class="fa-solid fa-bullseye text-violet"></i><div><h3>Build Your Weekly Study Plan</h3><p>Slide to choose how many hours per day you can study — we'll recommend a Pomodoro rhythm, daily XP target, and a weekly streak goal tailored just for you.</p></div></div>
                <div class="calc-body">
                    <div class="calc-slider-group">
                        <label for="studyHoursRange" class="calc-label">Daily Study Hours: <strong id="studyHoursDisplay" class="text-violet">2 hrs / day</strong></label>
                        <input type="range" id="studyHoursRange" min="1" max="8" value="2" step="1" class="calc-slider">
                        <div class="slider-marks"><span>1 hr</span><span>2 hrs</span><span>3 hrs</span><span>4 hrs</span><span>5 hrs</span><span>6 hrs</span><span>7 hrs</span><span>8 hrs</span></div>
                    </div>
                    <div class="calc-results">
                        <div class="calc-result-stat"><span>Pomodoro Rhythm</span><strong id="planPomodoro">4 × 25 min</strong></div>
                        <div class="calc-result-stat"><span>Daily XP Target</span><strong id="planXP">200 XP</strong></div>
                        <div class="calc-result-stat"><span>Weekly Goal</span><strong id="planGoal">12 sessions</strong></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- App Download Section -->
        <section class="app-download-section reveal" id="app">
            <div class="app-download-inner">
                <div class="app-download-text">
                    <p class="eyebrow" style="--accent:var(--coral);">📱 Study on the go</p>
                    <h2>Your pocket<br><span class="highlight highlight--pink">study buddy</span></h2>
                    <p class="section-sub">Flashcards, quizzes, focus timers, and live study rooms — all in your pocket. Available free on Android and iOS.</p>
                    <ul class="app-feature-list">
                        <li><i class="fa-solid fa-circle-check"></i> Offline flashcard access — study without Wi-Fi</li>
                        <li><i class="fa-solid fa-circle-check"></i> Push notifications for streak reminders</li>
                        <li><i class="fa-solid fa-circle-check"></i> Sync progress across all your devices</li>
                        <li><i class="fa-solid fa-circle-check"></i> Dark mode &amp; dyslexia-friendly fonts</li>
                    </ul>
                    <div class="app-download-btns">
                        <a href="#" class="app-store-btn" aria-label="Download on the App Store">
                            <i class="fa-brands fa-apple"></i>
                            <div>
                                <small>Download on the</small>
                                <strong>App Store</strong>
                            </div>
                        </a>
                        <a href="#" class="app-store-btn app-store-btn--android" aria-label="Get it on Google Play">
                            <i class="fa-brands fa-google-play"></i>
                            <div>
                                <small>Get it on</small>
                                <strong>Google Play</strong>
                            </div>
                        </a>
                    </div>
                    <div class="app-rating-row">
                        <div class="app-rating-stars">
                            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                        </div>
                        <span>4.8 · 12,000+ reviews</span>
                        <span class="app-rating-sep">·</span>
                        <span>50K+ downloads</span>
                    </div>
                </div>
                <div class="app-download-visual">
                    <div class="app-chip app-chip--tl"><span>🔥</span> 7-day streak!</div>
                    <div class="app-chip app-chip--tr"><span>⭐</span> 2,400 XP</div>
                    <div class="app-chip app-chip--bl"><span>📖</span> 120 cards done</div>
                    <div class="app-chip app-chip--br"><span>🍅</span> Focus on!</div>

                    <div class="app-phone">
                        <div class="app-phone__frame">
                            <div class="app-phone__notch"></div>
                            <div class="app-phone__screen">
                                <div class="app-screen-status">
                                    <span>9:41</span>
                                    <div class="app-screen-status__icons">
                                        <i class="fa-solid fa-signal"></i>
                                        <i class="fa-solid fa-wifi"></i>
                                        <i class="fa-solid fa-battery-full"></i>
                                    </div>
                                </div>
                                <div class="app-screen-header">
                                    <div class="app-screen-logo">
                                        <svg viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="14" fill="#5b43d6"/><path d="M18 20L32 28L46 20M18 20V44L32 52L46 44V20M32 28V52" stroke="white" stroke-width="3" stroke-linejoin="round"/><circle cx="32" cy="20" r="4" fill="white"/></svg>
                                    </div>
                                    <span>EduMate</span>
                                    <div class="app-screen-notif"><i class="fa-solid fa-bell"></i><span class="app-notif-dot"></span></div>
                                </div>
                                <div class="app-screen-streak">
                                    <span>🔥</span>
                                    <div>
                                        <strong>7-day streak!</strong>
                                        <em>Keep it up — you're on fire</em>
                                    </div>
                                    <span class="app-streak-badge">+50 XP</span>
                                </div>
                                <div class="app-screen-section-label">Today's Plan</div>
                                <div class="app-screen-tasks">
                                    <div class="app-task app-task--done">
                                        <span class="app-task__icon" style="background:color-mix(in srgb,var(--green) 15%,transparent);color:var(--green);"><i class="fa-solid fa-check"></i></span>
                                        <div><strong>Flashcards</strong><em>20 cards · CBSE Science</em></div>
                                        <span class="app-task__xp">+40 XP</span>
                                    </div>
                                    <div class="app-task">
                                        <span class="app-task__icon" style="background:color-mix(in srgb,var(--violet) 15%,transparent);color:var(--violet);"><i class="fa-solid fa-brain"></i></span>
                                        <div><strong>Quiz</strong><em>10 questions · Maths</em></div>
                                        <span class="app-task__xp">+60 XP</span>
                                    </div>
                                    <div class="app-task">
                                        <span class="app-task__icon" style="background:color-mix(in srgb,var(--coral) 15%,transparent);color:var(--coral);"><i class="fa-solid fa-stopwatch"></i></span>
                                        <div><strong>Focus Session</strong><em>25 min Pomodoro</em></div>
                                        <span class="app-task__xp">+25 XP</span>
                                    </div>
                                </div>
                                <div class="app-screen-nav">
                                    <button class="app-nav-item app-nav-item--active"><i class="fa-solid fa-house"></i><span>Home</span></button>
                                    <button class="app-nav-item"><i class="fa-solid fa-layer-group"></i><span>Cards</span></button>
                                    <button class="app-nav-item"><i class="fa-solid fa-users"></i><span>Rooms</span></button>
                                    <button class="app-nav-item"><i class="fa-solid fa-chart-line"></i><span>Progress</span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section class="faq-band reveal" id="faq" aria-label="Frequently asked questions">
            <div class="faq-card">
                <div class="faq-decor"><span class="faq-orb faq-orb--violet"></span><span class="faq-orb faq-orb--teal"></span></div>
                <div class="faq-content">
                    <header class="faq-header">
                        <p class="eyebrow"><i class="fa-solid fa-circle-question"></i> Frequently Asked Questions</p>
                        <h2>Got questions? We've got answers.</h2>
                        <p class="section-sub">Type a question, a keyword, or browse the topics below. The list filters instantly so you can find what you need in seconds.</p>
                    </header>
                    <form class="faq-search" role="search" onsubmit="return false;" aria-label="Search FAQ">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" id="faqSearchInput" data-faq-search placeholder="Type your question here — e.g. boards, flashcards, study rooms, AI doubt solver…" autocomplete="off" aria-controls="faqList">
                        <button type="button" class="faq-clear" data-faq-clear aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                    <div class="faq-tags" role="tablist">
                        <button type="button" class="faq-tag active" data-faq-tag="all">All</button>
                        <button type="button" class="faq-tag" data-faq-tag="general"><i class="fa-solid fa-graduation-cap"></i> General</button>
                        <button type="button" class="faq-tag" data-faq-tag="ai"><i class="fa-solid fa-brain"></i> AI Doubt Solver</button>
                        <button type="button" class="faq-tag" data-faq-tag="study-rooms"><i class="fa-solid fa-users"></i> Study Rooms</button>
                        <button type="button" class="faq-tag" data-faq-tag="pricing"><i class="fa-solid fa-tag"></i> Pricing</button>
                    </div>
                    <div class="faq-list" id="faqList" data-faq-list role="region" aria-live="polite">
                        <details class="faq-item" data-faq-item data-tags="general"><summary><span class="faq-accent"></span><span class="faq-q">What is EduMate and who is it built for?</span><span class="faq-toggle"><span class="faq-toggle-icon"></span></span></summary><div class="faq-a"><p>EduMate is an AI-powered study companion built specifically for Class 5–10 students studying under CBSE, ICSE, and state boards. It combines smart flashcards, a pomodoro focus timer, collaborative study rooms, and an AI tutor to help students master their syllabus.</p></div></details>
                        <details class="faq-item" data-faq-item data-tags="general"><summary><span class="faq-accent"></span><span class="faq-q">Which school boards are supported?</span><span class="faq-toggle"><span class="faq-toggle-icon"></span></span></summary><div class="faq-a"><p>We support major Indian boards, including CBSE, ICSE, WBBSE (West Bengal), and Maharashtra State Board. Our quizzes, practice papers, and concept cards are curated according to their respective syllabi.</p></div></details>
                        <details class="faq-item" data-faq-item data-tags="ai"><summary><span class="faq-accent"></span><span class="faq-q">How does the AI doubt-solver work?</span><span class="faq-toggle"><span class="faq-toggle-icon"></span></span></summary><div class="faq-a"><p>The AI assistant is trained on school textbook curricula. You can ask it to explain difficult concepts, solve science or math doubts, provide definitions, or generate custom mock questions 24/7.</p></div></details>
                        <details class="faq-item" data-faq-item data-tags="study-rooms"><summary><span class="faq-accent"></span><span class="faq-q">What are EduMate Study Rooms?</span><span class="faq-toggle"><span class="faq-toggle-icon"></span></span></summary><div class="faq-a"><p>Study Rooms are collaborative areas where you can invite friends and classmates. You can share custom flashcards, study together using a shared Pomodoro focus timer, test each other with live quizzes, or just chat.</p></div></details>
                        <details class="faq-item" data-faq-item data-tags="pricing"><summary><span class="faq-accent"></span><span class="faq-q">Is EduMate free?</span><span class="faq-toggle"><span class="faq-toggle-icon"></span></span></summary><div class="faq-a"><p>Yes! EduMate is completely free to get started. You get access to smart flashcards, focus timers, and basic AI assistant queries. Our Pro plan offers unlimited AI questions and premium board practice papers.</p></div></details>
                    </div>
                    <p class="faq-empty" data-faq-empty hidden><i class="fa-solid fa-face-meh"></i> No questions match your search keywords. Try a different keyword or <button type="button" class="faq-empty-reset" data-faq-clear>clear the search</button>.</p>
                    <div class="faq-cta"><div><strong>Still curious?</strong><span>Our support team replies within minutes, day or night.</span></div><div class="faq-cta-actions"><a href="#" class="btn small-btn"><i class="fa-solid fa-headset"></i> Contact support</a><a href="#" class="btn small-btn"><i class="fa-solid fa-comments"></i> Open chat</a></div></div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-top">
            <div class="footer-brand">
                <a href="index.php" class="logo footer-logo">
                    <span class="logo-mark">
                        <img src="favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;">
                    </span>
                    <span class="logo-wordmark footer-logo__wordmark">EduMate</span>
                </a>
                <p class="footer-tagline">Calm, secure study chat for friends, communities, and the people you actually learn with.</p>
                <div class="footer-social"><a href="#" aria-label="Twitter"><i class="fa-brands fa-x-twitter"></i></a><a href="#" aria-label="GitHub"><i class="fa-brands fa-github"></i></a><a href="#" aria-label="Discord"><i class="fa-brands fa-discord"></i></a><a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a></div>
            </div>
            <nav class="footer-cols">
                <div class="footer-col"><h4>Product</h4><ul><li><a href="#features">Features</a></li><li><a href="#subscriptions">Subscriptions</a></li><li><a href="#">Download apps</a></li><li><a href="#">What's new</a></li></ul></div>
                <div class="footer-col"><h4>Company</h4><ul><li><a href="#">About us</a></li><li><a href="#">Careers <span class="footer-tag">Hiring</span></a></li><li><a href="#">Press kit</a></li><li><a href="#">Contact</a></li></ul></div>
                <div class="footer-col"><h4>Resources</h4><ul><li><a href="#">Help Center</a></li><li><a href="#">Community forum</a></li><li><a href="#">Developer API</a></li><li><a href="#">Status page</a></li></ul></div>
                <div class="footer-col"><h4>Legal</h4><ul><li><a href="#privacy">Privacy policy</a></li><li><a href="#">Terms of service</a></li><li><a href="#">Cookie settings</a></li><li><a href="#">GDPR</a></li></ul></div>
            </nav>
        </div>
        <div class="footer-bottom"><span class="footer-copy">© 2026 EduMate Labs Inc. · Made with care for clearer conversations.</span><div class="footer-status"><span class="footer-status-dot"></span> All systems operational</div></div>
    </footer>

    <button type="button" id="scrollTopBtn" class="scroll-top-btn" aria-label="Scroll to top"><span class="scroll-top-icon"><i class="fa-solid fa-arrow-up"></i></span></button>

    <script src="script.js"></script>
</body>
</html>