<?php
/**
 * EduMate - Student: AI Flashcards Hub
 * Step 1: Browse Subjects for prescribed Class & Board
 * Step 2: Click a Subject to view all Chapters
 * Step 3: Click a Chapter to open the 3D AI Flashcard Revision Deck
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('student');

$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();

// Fetch latest Class & Board directly from database profile settings
$stmt = $pdo->prepare('SELECT class, board FROM users WHERE id = :id');
$stmt->execute([':id' => $uid]);
$profile = $stmt->fetch();

$userClass = (int)($profile['class'] ?? $user['class'] ?? 8);
$userBoard = (string)($profile['board'] ?? $user['board'] ?? 'CBSE');

render_dashboard_header(
    'AI Flashcards',
    student_nav(),
    'flashcards',
    ['Home', 'AI Flashcards']
);
?>

<style>
    /* Premium Design System with Bodoni Moda Typography */
    .flashcard-header-banner {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #9333ea 100%);
        border-radius: 20px;
        padding: 32px 36px;
        color: #ffffff;
        margin-bottom: 24px;
        box-shadow: 0 14px 35px rgba(124, 58, 237, 0.22);
        position: relative;
        overflow: hidden;
    }
    .flashcard-header-banner h1 {
        font-family: 'Bodoni Moda', Georgia, serif;
        color: #ffffff;
        font-size: 32px;
        font-weight: 700;
        letter-spacing: -0.5px;
        margin: 0 0 10px 0;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .flashcard-header-banner p {
        font-family: 'Poppins', sans-serif;
        margin: 0;
        opacity: 0.95;
        font-size: 15px;
        max-width: 720px;
        line-height: 1.6;
    }
    .profile-badge {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        padding: 8px 18px;
        border-radius: 99px;
        font-size: 13px;
        font-weight: 600;
        margin-top: 16px;
        border: 1px solid rgba(255, 255, 255, 0.35);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }
    .profile-badge strong {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 14px;
        letter-spacing: 0.3px;
    }

    /* Section Headers */
    .section-title-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .section-title-bar h2 {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 24px;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Subject Cards Grid (Step 1) */
    .subjects-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .subject-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 18px;
        padding: 24px;
        cursor: pointer;
        transition: transform 0.25s cubic-bezier(.4, 0, .2, 1), box-shadow 0.25s cubic-bezier(.4, 0, .2, 1);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: var(--shadow-sm);
        position: relative;
        overflow: hidden;
    }
    .subject-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-md);
        border-color: #6366f1;
    }
    .subject-card-icon {
        width: 50px;
        height: 50px;
        border-radius: 14px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #ffffff;
        display: grid;
        place-items: center;
        font-size: 24px;
        margin-bottom: 16px;
    }
    .subject-card h3 {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 20px;
        font-weight: 700;
        margin: 0 0 6px 0;
        color: var(--text-primary);
    }
    .subject-card p {
        font-size: 13px;
        color: var(--text-light);
        margin: 0 0 16px 0;
    }
    .subject-card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
        color: #6366f1;
        padding-top: 12px;
        border-top: 1px solid var(--border-color);
    }

    /* Chapter List Grid (Step 2) */
    .chapters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 16px;
        margin-bottom: 30px;
    }
    .chapter-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 20px;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: var(--shadow-xs);
    }
    .chapter-card:hover {
        border-color: #6366f1;
        background: var(--bg-secondary);
        transform: translateX(4px);
    }
    .chapter-num {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: rgba(99, 102, 241, 0.12);
        color: #6366f1;
        font-family: 'Bodoni Moda', Georgia, serif;
        font-weight: 700;
        font-size: 16px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .chapter-title {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
        line-height: 1.4;
        flex: 1;
    }

    /* 3D Flip Card Stage (Step 3) */
    .flashcard-stage {
        perspective: 1200px;
        width: 100%;
        max-width: 680px;
        height: 400px;
        margin: 0 auto 24px auto;
        position: relative;
    }
    .flashcard-inner {
        width: 100%;
        height: 100%;
        position: relative;
        transform-style: preserve-3d;
        transition: transform 0.6s cubic-bezier(0.4, 0.2, 0.2, 1);
        cursor: pointer;
        border-radius: 24px;
    }
    .flashcard-stage.flipped .flashcard-inner {
        transform: rotateY(180deg);
    }

    .card-face {
        position: absolute;
        width: 100%;
        height: 100%;
        backface-visibility: hidden;
        -webkit-backface-visibility: hidden;
        border-radius: 24px;
        padding: 40px 36px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 16px 40px rgba(31, 17, 71, 0.10);
        border: 2px solid var(--border-color);
        background: var(--bg-card);
    }

    /* Front styling */
    .card-front {
        background: var(--bg-card);
        color: var(--text-primary);
        border: 2px solid var(--border-color);
    }
    /* Back styling */
    .card-back {
        transform: rotateY(180deg);
        background: linear-gradient(145deg, #1e1b4b 0%, #312e81 100%);
        color: #f8fafc;
        border-color: #4338ca;
    }
    .card-back .card-tag {
        background: rgba(255, 255, 255, 0.18);
        color: #a5b4fc;
    }

    .card-tag {
        font-family: 'Bodoni Moda', Georgia, serif;
        align-self: flex-start;
        padding: 6px 14px;
        border-radius: 99px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.5px;
        background: rgba(99, 102, 241, 0.12);
        color: #6366f1;
    }
    
    /* Front Question: Bodoni Moda */
    .card-front .card-body-text {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 22px;
        font-weight: 600;
        line-height: 1.6;
        text-align: center;
        margin: auto 0;
        color: var(--text-primary);
        letter-spacing: 0.2px;
    }

    /* Back Answer: Poppins (Brief, Crisp) */
    .card-back .card-body-text {
        font-family: 'Poppins', sans-serif;
        font-size: 15px;
        font-weight: 400;
        line-height: 1.6;
        text-align: left;
        margin: auto 0;
        color: #f8fafc;
    }

    /* Special Equation Box for Math & Formulas (General) */
    .equation-box {
        display: inline-block;
        max-width: 100%;
        border-radius: 8px;
        padding: 4px 12px;
        margin: 6px auto;
        text-align: center;
        letter-spacing: 0.3px;
        font-size: 15px;
        line-height: 1.3;
    }

    /* Compact KaTeX Display Margins & Vertical Spacing */
    .katex-display {
        margin: 2px 0 !important;
        padding: 0 !important;
        display: inline-block !important;
    }
    .katex {
        font-size: 1.05em !important;
        line-height: 1.2 !important;
    }
    .equation-box .katex-display {
        margin: 0 !important;
    }

    /* Light Theme Equation Box (Front Card Question) */
    .card-front .equation-box {
        background: rgba(99, 102, 241, 0.08);
        border: 1px dashed rgba(99, 102, 241, 0.35);
        color: #4f46e5;
    }
    .card-front .equation-box .katex {
        color: #4f46e5 !important;
    }

    /* Dark Theme Overrides for Front Cards */
    html[data-theme="dark"] .card-front {
        background: linear-gradient(145deg, #1e1b4b 0%, #0f172a 100%);
        border-color: #4338ca;
        color: #f8fafc;
    }
    html[data-theme="dark"] .card-front .card-body-text {
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .card-front .card-tag {
        background: rgba(99, 102, 241, 0.25);
        color: #a5b4fc;
    }
    html[data-theme="dark"] .card-front .equation-box {
        background: rgba(99, 102, 241, 0.22);
        border-color: rgba(165, 180, 252, 0.5);
        color: #38f9d7;
    }
    html[data-theme="dark"] .card-front .equation-box .katex {
        color: #38f9d7 !important;
    }

    /* Dark Theme Equation Box (Back Card Answer) */
    .card-back .equation-box {
        background: rgba(99, 102, 241, 0.25);
        border: 1px dashed rgba(165, 180, 252, 0.5);
        color: #38f9d7;
    }
    .card-back .equation-box .katex {
        color: #38f9d7 !important;
    }

    .card-footer-hint {
        font-family: 'Poppins', sans-serif;
        font-size: 12px;
        color: var(--text-light);
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-weight: 500;
    }
    .card-back .card-footer-hint {
        color: #94a3b8;
    }

    /* Controls & Stats Bar */
    .flashcard-controls {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 24px;
    }
    .deck-counter {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        background: var(--bg-card);
        padding: 8px 18px;
        border-radius: 99px;
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-xs);
    }
    .progress-bar-container {
        width: 100%;
        max-width: 680px;
        margin: 0 auto 20px auto;
        background: var(--bg-secondary);
        height: 10px;
        border-radius: 99px;
        overflow: hidden;
        border: 1px solid var(--border-color);
    }
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #38f9d7, #4facfe);
        width: 0%;
        transition: width 0.4s ease;
    }

    /* Loading Indicator */
    .cards-loading {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-light);
    }
    .cards-loading ion-icon {
        font-size: 44px;
        animation: spin 1.2s infinite linear;
        color: #6366f1;
        margin-bottom: 12px;
    }
    .cards-loading h3 {
        font-family: 'Bodoni Moda', Georgia, serif;
        font-size: 22px;
        color: var(--text-primary);
    }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<!-- Container Views -->
<div id="flashcard-app">

    <!-- STEP 1: Subjects View -->
    <div id="view-subjects">
        <div class="section-title-bar">
            <h2><ion-icon name="cube-outline" style="color:#6366f1;"></ion-icon> Prescribed Subjects</h2>
            <span style="font-size:13px; color:var(--text-light);">Class <?php echo $userClass; ?> &middot; <?php echo htmlspecialchars($userBoard, ENT_QUOTES); ?> Curriculum</span>
        </div>
        <div class="subjects-grid" id="subjects-grid"></div>
    </div>

    <!-- STEP 2: Chapters View -->
    <div id="view-chapters" style="display:none;">
        <div class="section-title-bar">
            <h2>
                <button id="btn-back-to-subjects" class="btn btn-ghost btn-sm" style="margin-right:8px;"><ion-icon name="arrow-back-outline"></ion-icon> Subjects</button>
                <span id="chapters-subject-title">Subject Chapters</span>
            </h2>
            <span style="font-size:13px; color:var(--text-light);" id="chapters-count-subtitle">0 Chapters</span>
        </div>
        <div class="chapters-grid" id="chapters-grid"></div>
    </div>

    <!-- STEP 3: Flashcard Deck View -->
    <div id="view-deck" style="display:none;">
        <div class="section-title-bar" style="flex-wrap:wrap; gap:12px;">
            <h2>
                <button id="btn-back-to-chapters" class="btn btn-ghost btn-sm" style="margin-right:8px;"><ion-icon name="arrow-back-outline"></ion-icon> Chapters</button>
                <span id="deck-chapter-title">Chapter Revision</span>
            </h2>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:6px; background:var(--bg-card); padding:5px 12px; border-radius:12px; border:1px solid var(--border-color); box-shadow:var(--shadow-xs);">
                    <ion-icon name="hardware-chip-outline" style="color:var(--gradient-info); font-size:16px;"></ion-icon>
                    <span style="font-size:12px; font-weight:600; color:var(--text-light);">AI Model:</span>
                    <select id="flashcard-model-select" style="background:transparent; border:none; color:var(--text-primary); font-size:12px; font-weight:600; outline:none; cursor:pointer;" onchange="onFlashcardModelChange(this.value)">
                    </select>
                </div>
                <button id="btn-regenerate-deck" class="btn btn-ghost btn-sm" style="color:var(--gradient-warning);"><ion-icon name="refresh-outline"></ion-icon> Regenerate Deck</button>
            </div>
        </div>


        <!-- Progress Bar -->
        <div class="progress-bar-container">
            <div id="deck-progress-fill" class="progress-bar-fill"></div>
        </div>

        <!-- Loading indicator -->
        <div id="deck-loading" class="cards-loading" style="display:none;">
            <ion-icon name="sync-outline"></ion-icon>
            <h3>Generating AI Flashcards...</h3>
            <p>Curating key concepts, formulas, and step-by-step revision points for this chapter.</p>
        </div>

        <!-- Interactive 3D Card Stage -->
        <div id="deck-stage" style="display:none;">
            <div class="flashcard-stage" id="card-stage">
                <div class="flashcard-inner">
                    <!-- Front -->
                    <div class="card-face card-front">
                        <span class="card-tag" id="card-tag-front">Question / Concept</span>
                        <div class="card-body-text" id="card-front-text">Front content...</div>
                        <div class="card-footer-hint">
                            <ion-icon name="swap-horizontal-outline"></ion-icon> Click card or press Space to flip answer
                        </div>
                    </div>
                    <!-- Back -->
                    <div class="card-face card-back">
                        <span class="card-tag" id="card-tag-back">Explanation / Key Points</span>
                        <div class="card-body-text" id="card-back-text">Back content...</div>
                        <div class="card-footer-hint">
                            <ion-icon name="checkmark-done-circle-outline"></ion-icon> Mastered this point? Mark below!
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls Bar -->
            <div class="flashcard-controls">
                <button id="btn-prev-card" class="btn btn-ghost"><ion-icon name="arrow-back-outline"></ion-icon> Previous</button>
                
                <button id="btn-master-card" class="btn btn-secondary"><ion-icon name="checkmark-circle-outline"></ion-icon> Mark Mastered</button>

                <span class="deck-counter" id="deck-counter">Card 1 / 8</span>

                <button id="btn-next-card" class="btn btn-primary">Next <ion-icon name="arrow-forward-outline"></ion-icon></button>

                <button id="btn-shuffle-deck" class="btn btn-ghost" title="Shuffle Deck"><ion-icon name="shuffle-outline"></ion-icon> Shuffle</button>
            </div>
        </div>
    </div>

</div>

<script>
(function() {
    let curriculumData = null;
    let selectedSubject = '';
    let selectedChapter = '';
    let currentCards = [];
    let currentIdx = 0;
    let isFlipped = false;

    // View Containers
    const viewSubjects = document.getElementById('view-subjects');
    const viewChapters = document.getElementById('view-chapters');
    const viewDeck = document.getElementById('view-deck');

    const subjectsGrid = document.getElementById('subjects-grid');
    const chaptersGrid = document.getElementById('chapters-grid');
    const chaptersSubjectTitle = document.getElementById('chapters-subject-title');
    const chaptersCountSubtitle = document.getElementById('chapters-count-subtitle');
    const deckChapterTitle = document.getElementById('deck-chapter-title');

    const deckLoading = document.getElementById('deck-loading');
    const deckStage = document.getElementById('deck-stage');
    const cardStage = document.getElementById('card-stage');

    const frontText = document.getElementById('card-front-text');
    const backText = document.getElementById('card-back-text');
    const tagFront = document.getElementById('card-tag-front');
    const tagBack = document.getElementById('card-tag-back');
    const deckCounter = document.getElementById('deck-counter');
    const progressFill = document.getElementById('deck-progress-fill');

    const btnBackToSubjects = document.getElementById('btn-back-to-subjects');
    const btnBackToChapters = document.getElementById('btn-back-to-chapters');
    const btnPrev = document.getElementById('btn-prev-card');
    const btnNext = document.getElementById('btn-next-card');
    const btnMaster = document.getElementById('btn-master-card');
    const btnShuffle = document.getElementById('btn-shuffle-deck');
    const btnRegenerate = document.getElementById('btn-regenerate-deck');

    const subjectIcons = {
        'Mathematics': 'calculator-outline',
        'Science': 'flask-outline',
        'Physics': 'magnet-outline',
        'Chemistry': 'bonfire-outline',
        'Biology': 'leaf-outline',
        'Social Studies': 'globe-outline',
        'History': 'fa-solid fa-landmark-flag',
        'Civics': 'fa-solid fa-scale-balanced',
        'Political Science': 'fa-solid fa-building-columns',
        'Social & Political Life': 'fa-solid fa-scale-balanced',
        'Geography': 'fa-solid fa-earth-americas',
        'Computer Applications': 'fa-solid fa-laptop-code',
        'Computer Science': 'fa-solid fa-desktop',
        'English': 'book-outline'
    };

    // 1. Initialize Curriculum & Render Subjects (STEP 1)
    async function initCurriculum() {
        try {
            const res = await fetch('../api/ai_flashcards.php?action=get_curriculum');
            curriculumData = await res.json();
            
            // Populate AI Model Selector
            populateModelSelector();
            
            renderSubjects();
        } catch (e) {
            console.error('Failed to load curriculum', e);
        }
    }

    function populateModelSelector() {
        const sel = document.getElementById('flashcard-model-select');
        if (!sel || !curriculumData.models) return;
        
        const saved = localStorage.getItem('edumate_ai_model') || curriculumData.defaultModel || 'gemini-3.6-flash';
        sel.innerHTML = Object.entries(curriculumData.models).map(([key, info]) => `
            <option value="${key}" ${key === saved ? 'selected' : ''}>
                ${info.name} (${info.badge})
            </option>
        `).join('');
    }

    window.getSelectedFlashcardModel = function() {
        const sel = document.getElementById('flashcard-model-select');
        if (sel && sel.value) return sel.value;
        return localStorage.getItem('edumate_ai_model') || (curriculumData && curriculumData.defaultModel) || 'gemini-3.6-flash';
    };

    window.onFlashcardModelChange = function(val) {
        localStorage.setItem('edumate_ai_model', val);
    };

    function renderSubjects() {
        const profileCurr = curriculumData.profileCurriculum || {};
        const subjects = Object.keys(profileCurr);

        if (subjects.length === 0) {
            subjectsGrid.innerHTML = '<div class="empty"><h3>No subjects found</h3></div>';
            return;
        }

        subjectsGrid.innerHTML = subjects.map(sub => {
            const chapters = profileCurr[sub] || [];
            const icon = subjectIcons[sub] || 'cube-outline';
            const iconHTML = icon.startsWith('fa-') 
                ? `<i class="${icon}"></i>` 
                : `<ion-icon name="${icon}"></ion-icon>`;

            return `
                <div class="subject-card" onclick="openSubject('${sub.replace(/'/g, "\\'")}')">
                    <div class="subject-card-icon">${iconHTML}</div>
                    <div>
                        <h3>${sub}</h3>
                        <p>${chapters.length} prescribed revision chapters</p>
                    </div>
                    <div class="subject-card-footer">
                        <span>Explore Chapters</span>
                        <ion-icon name="arrow-forward-outline"></ion-icon>
                    </div>
                </div>
            `;
        }).join('');

        showView('subjects');
    }

    // 2. Open Subject & Render Chapters (STEP 2)
    window.openSubject = function(sub) {
        selectedSubject = sub;
        const profileCurr = curriculumData.profileCurriculum || {};
        const chapters = profileCurr[sub] || [];

        chaptersSubjectTitle.textContent = sub;
        chaptersCountSubtitle.textContent = `${chapters.length} Chapters Prescribed`;

        chaptersGrid.innerHTML = chapters.map((chap, idx) => `
            <div class="chapter-card" onclick="openChapter('${chap.replace(/'/g, "\\'")}')">
                <div class="chapter-num">${idx + 1}</div>
                <div class="chapter-title">${chap}</div>
                <ion-icon name="chevron-forward-outline" style="color:var(--text-light);"></ion-icon>
            </div>
        `).join('');

        showView('chapters');
    };

    // 3. Open Chapter & Load Flashcard Deck (STEP 3)
    window.openChapter = function(chap) {
        selectedChapter = chap;
        deckChapterTitle.textContent = `${selectedSubject} · ${chap}`;
        showView('deck');
        loadCards(false);
    };

    // 4. Navigation View Switcher
    function showView(viewName) {
        viewSubjects.style.display = viewName === 'subjects' ? 'block' : 'none';
        viewChapters.style.display = viewName === 'chapters' ? 'block' : 'none';
        viewDeck.style.display = viewName === 'deck' ? 'block' : 'none';
    }

    btnBackToSubjects.addEventListener('click', () => showView('subjects'));
    btnBackToChapters.addEventListener('click', () => showView('chapters'));

    // 5. Fetch / Generate Flashcards
    async function loadCards(regenerate = false) {
        const cls = curriculumData.userClass;
        const brd = curriculumData.userBoard;
        const sub = selectedSubject;
        const chap = selectedChapter;

        const selectedModel = getSelectedFlashcardModel();
        deckStage.style.display = 'none';
        deckLoading.style.display = 'block';
        const loadingP = deckLoading.querySelector('p');
        if (loadingP) {
            loadingP.textContent = `Curating high-rigor revision points and exam formulas using ${selectedModel}...`;
        }

        try {
            const res = await fetch('../api/ai_flashcards.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'get_cards',
                    class: cls,
                    board: brd,
                    subject: sub,
                    chapter: chap,
                    regenerate: regenerate,
                    model: selectedModel
                })
            });

            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (pErr) {
                console.error("Server API Raw Output:", rawText);
                throw new Error("Server returned non-JSON response.");
            }

            deckLoading.style.display = 'none';

            if (data.cards && data.cards.length > 0) {
                currentCards = data.cards;
                currentIdx = 0;
                deckStage.style.display = 'block';
                renderCard();
            } else {
                alert(data.error || 'No cards could be loaded.');
            }
        } catch (e) {
            deckLoading.style.display = 'none';
            alert('Error loading cards: ' + e.message);
        }
    }

    // 6. Render Card UI
    function renderCard() {
        if (currentCards.length === 0) return;
        const card = currentCards[currentIdx];

        isFlipped = false;
        cardStage.classList.remove('flipped');

        tagFront.textContent = `${selectedSubject} · Card ${currentIdx + 1} of ${currentCards.length} · Question`;
        tagBack.textContent = `${selectedSubject} · Card ${currentIdx + 1} of ${currentCards.length} · Answer Key`;

        frontText.innerHTML = card.front;
        backText.innerHTML = card.back;

        // Render mathematical equations via KaTeX on BOTH Front (Question) and Back (Answer)
        if (typeof renderMathInElement === 'function') {
            const mathOpts = {
                delimiters: [
                    {left: '$$', right: '$$', display: true},
                    {left: '$', right: '$', display: false},
                    {left: '\\(', right: '\\)', display: false},
                    {left: '\\[', right: '\\]', display: true}
                ],
                throwOnError: false
            };
            renderMathInElement(frontText, mathOpts);
            renderMathInElement(backText, mathOpts);
        }

        // Dynamic font size scaling for brief, readable answers
        const backLen = (card.back || '').length;
        if (backLen > 240) {
            backText.style.fontSize = '13px';
        } else if (backLen > 130) {
            backText.style.fontSize = '14px';
        } else {
            backText.style.fontSize = '15px';
        }

        deckCounter.textContent = `${currentIdx + 1} / ${currentCards.length}`;

        if (card.user_status === 'mastered') {
            btnMaster.className = 'btn btn-success';
            btnMaster.innerHTML = '<ion-icon name="checkmark-done-circle-outline"></ion-icon> Mastered ✓';
        } else {
            btnMaster.className = 'btn btn-secondary';
            btnMaster.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> Mark Mastered';
        }

        const masteredCount = currentCards.filter(c => c.user_status === 'mastered').length;
        const pct = Math.round((masteredCount / currentCards.length) * 100);
        progressFill.style.width = pct + '%';
    }

    // 7. Card Interactivity & Key Controls
    cardStage.addEventListener('click', () => {
        isFlipped = !isFlipped;
        cardStage.classList.toggle('flipped', isFlipped);
    });

    document.addEventListener('keydown', (e) => {
        if (viewDeck.style.display === 'none') return;
        if (e.code === 'Space') {
            e.preventDefault();
            isFlipped = !isFlipped;
            cardStage.classList.toggle('flipped', isFlipped);
        } else if (e.code === 'ArrowRight') {
            e.preventDefault();
            nextCard();
        } else if (e.code === 'ArrowLeft') {
            e.preventDefault();
            prevCard();
        }
    });

    function nextCard() {
        if (currentCards.length === 0) return;
        currentIdx = (currentIdx + 1) % currentCards.length;
        renderCard();
    }

    function prevCard() {
        if (currentCards.length === 0) return;
        currentIdx = (currentIdx - 1 + currentCards.length) % currentCards.length;
        renderCard();
    }

    btnNext.addEventListener('click', nextCard);
    btnPrev.addEventListener('click', prevCard);

    btnMaster.addEventListener('click', async () => {
        if (currentCards.length === 0) return;
        const card = currentCards[currentIdx];
        const newStatus = card.user_status === 'mastered' ? 'review' : 'mastered';
        card.user_status = newStatus;
        renderCard();

        try {
            await fetch('../api/ai_flashcards.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'toggle_mastery',
                    card_id: card.id,
                    status: newStatus
                })
            });
        } catch (e) {
            console.error('Failed to toggle card mastery', e);
        }
    });

    btnShuffle.addEventListener('click', () => {
        if (currentCards.length === 0) return;
        currentCards.sort(() => Math.random() - 0.5);
        currentIdx = 0;
        renderCard();
    });

    btnRegenerate.addEventListener('click', () => {
        if (confirm(`Regenerate a fresh AI flashcard deck for chapter "${selectedChapter}"?`)) {
            loadCards(true);
        }
    });

    // Start App
    initCurriculum();
})();
</script>

<?php render_dashboard_footer(); ?>
