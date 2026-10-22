<?php
/**
 * EduMate - AI Tutor (Student) - Premium Block UI
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/ai_functions.php';
require_role('student');

$user = current_user();
$supportedModels = get_supported_ai_models();
$defaultModelKey = get_default_ai_model();

render_dashboard_header('AI Tutor', student_nav(), 'ai-tutor', ['AI Tutor'], 'AI Tutor');
?>

<style>
    /* AI Engine Banner */
    .ai-engine-banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(168, 85, 247, 0.05) 100%);
        border: 1px solid rgba(124, 92, 255, 0.22);
        border-radius: 16px;
        padding: 16px 22px;
        margin-bottom: 24px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    }
    .ai-engine-info {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .ai-engine-icon-wrap {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, #6366f1, #a855f7);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        flex-shrink: 0;
    }
    .ai-engine-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .model-badge-chip {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 3px 9px;
        border-radius: 999px;
        background: rgba(124, 92, 255, 0.18);
        color: var(--violet, #7c5cff);
        border: 1px solid rgba(124, 92, 255, 0.35);
    }
    .ai-engine-desc {
        font-size: 13px;
        color: var(--text-light);
        margin-top: 2px;
    }
    .tutor-model-select {
        padding: 10px 16px;
        border-radius: 12px;
        background: var(--bg-card);
        border: 1.5px solid var(--border-color);
        color: var(--text-primary);
        font-size: 13px;
        font-weight: 600;
        outline: none;
        cursor: pointer;
        transition: all 0.2s ease;
        min-width: 310px;
        box-shadow: var(--shadow-xs);
    }
    .tutor-model-select:focus {
        border-color: #7c5cff;
        box-shadow: 0 0 0 3px rgba(124, 92, 255, 0.2);
    }

    .result-box {
        margin-top: 16px;
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        padding: 20px;
        border: 1px solid var(--border-color);
        display: none;
        font-size: 14px;
        line-height: 1.6;

        color: var(--text-primary);
    }
    .result-box.active { display: block; animation: fadeIn 0.4s ease; }
    
    /* Quiz UI */
    .quiz-question { font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 16px; }
    .quiz-option {
        padding: 14px 20px;
        border: 2px solid var(--border-color);
        border-radius: 12px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: var(--transition);
        font-weight: 500;
        display: flex; align-items: center; gap: 12px;
        background: var(--bg-card);
    }
    .quiz-option:hover { border-color: var(--border-strong); }
    .quiz-option.correct { border-color: var(--gradient-success); background: rgba(74, 222, 128, 0.1); color: var(--text-primary); }
    .quiz-option.wrong { border-color: #ef4444; background: rgba(239, 68, 68, 0.1); color: var(--text-primary); }
    
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    .loading-state { animation: pulse 1.5s infinite; color: var(--text-light); font-weight: 600; display:flex; align-items:center; gap:8px;}
    /* Doubt Input Box (Minimalist Embedded UI) */
    .doubt-input-wrapper {
        position: relative;
        background: var(--bg-card, var(--paper, #1e1b4b));
        border: 1.5px solid var(--border-color, rgba(255, 255, 255, 0.12));
        border-radius: 16px;
        padding: 14px 14px 10px 14px;
        display: flex;
        flex-direction: column;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        margin-bottom: 16px;
    }
    .doubt-input-wrapper:focus-within {
        border-color: var(--violet, #7c5cff);
        box-shadow: 0 0 0 3px rgba(124, 92, 255, 0.18), 0 4px 20px rgba(0, 0, 0, 0.15);
    }
    .doubt-textarea {
        width: 100%;
        background: transparent;
        border: none !important;
        outline: none !important;
        resize: vertical;
        min-height: 75px;
        font-family: inherit;
        font-size: 14px;
        line-height: 1.6;
        color: var(--text-primary, inherit);
        padding: 0;
        margin-bottom: 8px;
        box-shadow: none !important;
    }
    .doubt-textarea::placeholder {
        color: var(--text-light, #94a3b8);
        opacity: 0.7;
    }
    .doubt-input-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 10px;
        border-top: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));
    }
    .doubt-toolbar-left, .doubt-toolbar-right {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .icon-tool-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.04);
        color: var(--text-light, #94a3b8);
        border: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 20px;
        margin: 0;
    }
    .icon-tool-btn ion-icon, .icon-send-btn ion-icon {
        pointer-events: none;
    }
    .icon-tool-btn:hover {
        background: rgba(124, 92, 255, 0.15);
        color: var(--violet, #a78bff);
        border-color: rgba(124, 92, 255, 0.3);
        transform: translateY(-1px);
    }
    .icon-tool-btn.listening {
        color: #7c5cff !important;
        background: rgba(124, 92, 255, 0.15) !important;
        border-color: rgba(124, 92, 255, 0.35) !important;
    }
    .icon-send-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        background: linear-gradient(135deg, var(--violet, #7c5cff), #6366f1);
        color: #ffffff;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 18px;
        box-shadow: 0 4px 12px rgba(124, 92, 255, 0.3);
    }
    .icon-send-btn:hover {
        transform: translateY(-1px) scale(1.05);
        box-shadow: 0 6px 16px rgba(124, 92, 255, 0.45);
        background: linear-gradient(135deg, #8b5cf6, #4f46e5);
    }
    .icon-send-btn:active {
        transform: translateY(0) scale(0.96);
    }
    .file-name-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 500;
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.25);
        padding: 4px 10px;
        border-radius: 20px;
        max-width: 200px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .file-name-chip:empty {
        display: none;
    }
    .doubt-input-field {
        width: 100%;
        background: transparent;
        border: none !important;
        outline: none !important;
        font-family: inherit;
        font-size: 14px;
        color: var(--text-primary, inherit);
        padding: 4px 0;
        box-shadow: none !important;
    }
    .doubt-input-field::placeholder {
        color: var(--text-light, #94a3b8);
        opacity: 0.7;
    }
    /* Thumbnail Preview Chip */
    .file-preview-chip {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: rgba(124, 92, 255, 0.1);
        border: 1px solid rgba(124, 92, 255, 0.25);
        padding: 5px 10px 5px 6px;
        border-radius: 12px;
        font-size: 13px;
        color: var(--text-primary, inherit);
        max-width: 100%;
        animation: fadeIn 0.25s ease;
    }
    .file-preview-thumb {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        object-fit: cover;
        border: 1px solid rgba(255, 255, 255, 0.15);
        background: #0f172a;
    }
    .file-preview-icon {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(124, 92, 255, 0.15);
        color: var(--violet, #7c5cff);
        font-size: 20px;
    }
    .file-preview-info {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .file-preview-name {
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 200px;
        font-size: 13px;
    }
    .file-preview-size {
        font-size: 11px;
        color: var(--text-light, #94a3b8);
    }
    .file-preview-remove {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        color: var(--text-light, #94a3b8);
        border: none;
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
        transition: all 0.2s ease;
        margin-left: 6px;
    }
    .file-preview-remove:hover {
        background: rgba(239, 68, 68, 0.25);
        color: #ef4444;
        transform: scale(1.1);
    }
    /* Calm & Simple Broad Live Audio Visualizer Container */
    .voice-broad-visualizer {
        width: 100%;
        background: rgba(124, 92, 255, 0.06);
        border: 1px solid rgba(124, 92, 255, 0.2);
        border-radius: 12px;
        padding: 8px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 8px 0;
        backdrop-filter: blur(6px);
        animation: broadSlideDown 0.25s ease;
    }
    @keyframes broadSlideDown {
        from { opacity: 0; transform: translateY(-6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .broad-voice-status {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    .rec-live-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #7c5cff;
        box-shadow: 0 0 6px rgba(124, 92, 255, 0.6);
        animation: calmDotPulse 1.2s infinite alternate;
    }
    @keyframes calmDotPulse {
        0% { opacity: 0.5; transform: scale(0.9); }
        100% { opacity: 1; transform: scale(1.1); }
    }
    .broad-voice-title {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-primary, #6366f1);
        white-space: nowrap;
    }
    .broad-wave-track {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        flex: 1;
        height: 28px;
        padding: 0 10px;
        overflow: hidden;
    }
    .broad-wave-bar {
        flex: 1;
        min-width: 3px;
        max-width: 5px;
        height: 4px;
        background: linear-gradient(to top, #7c5cff, #6366f1);
        border-radius: 999px;
        transition: height 0.06s ease, opacity 0.1s ease;
        animation: calmRipple 0.9s ease-in-out infinite alternate;
    }
    @keyframes calmRipple {
        0% { height: 4px; opacity: 0.3; }
        50% { height: 24px; opacity: 0.9; }
        100% { height: 6px; opacity: 0.5; }
    }
    .broad-voice-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        color: var(--text-light, #94a3b8);
        border: 1px solid rgba(255, 255, 255, 0.1);
        cursor: pointer;
        font-size: 18px;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }
    .broad-voice-close:hover {
        background: rgba(255, 255, 255, 0.15);
        color: var(--text-primary, #ffffff);
        transform: scale(1.08);
    }
</style>

<!-- AI Engine Model Selector Bar -->

<div class="ai-engine-banner">
    <div class="ai-engine-info">
        <div class="ai-engine-icon-wrap">
            <ion-icon name="sparkles"></ion-icon>
        </div>
        <div>
            <div class="ai-engine-title">
                AI Engine Model
                <span id="active-model-badge" class="model-badge-chip">⚡ REASONING</span>
            </div>
            <div class="ai-engine-desc">Elevate your tutor with Google's next-generation reasoning, multimodal math analysis, and high-rigor Olympiad models.</div>
        </div>
    </div>
    <div class="ai-engine-picker">
        <select id="tutor-model-select" class="tutor-model-select" onchange="onModelChange(this.value)">
            <?php foreach ($supportedModels as $mKey => $mInfo): ?>
                <option value="<?php echo htmlspecialchars($mKey, ENT_QUOTES); ?>">
                    <?php echo htmlspecialchars($mInfo['name'] . ' (' . $mInfo['tag'] . ')', ENT_QUOTES); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="panel-grid">

    <!-- Doubt Solver Block -->
    <section class="panel">
        <h2 style="display: flex; align-items: center; gap: 8px;"><ion-icon name="chatbubble-outline" style="color: var(--gradient-info);"></ion-icon> Multimodal Doubt Solver</h2>
        <p style="color: var(--text-light); margin-bottom: 16px; font-size: 14px;">Ask anything or upload a photo of your math/science problem.</p>
        
        <div class="doubt-input-wrapper">
            <textarea id="ai-question" class="doubt-textarea" rows="3" placeholder="E.g., Can you explain Newton's second law with an example?"></textarea>
            
            <!-- Broad Live Audio Visualizer Container -->
            <div id="broad-voice-container" class="voice-broad-visualizer" style="display: none;">
                <div class="broad-voice-status">
                    <span class="rec-live-dot"></span>
                    <span class="broad-voice-title">Listening...</span>
                </div>
                
                <div class="broad-wave-track" id="broad-wave-bars">
                    <!-- Dynamic wave bars -->
                </div>

                <button type="button" id="broad-voice-stop-btn" class="broad-voice-close" title="Close Dictation">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>

            <div id="file-preview-container" style="display: none; margin-bottom: 8px;"></div>

            <div class="doubt-input-toolbar">
                <div class="doubt-toolbar-left">
                    <label class="icon-tool-btn" title="Upload Image / PDF">
                        <ion-icon name="image-outline"></ion-icon>
                        <input type="file" id="ai-file" accept="image/*,application/pdf" style="display:none;">
                    </label>
                    <button type="button" id="voice-btn" class="icon-tool-btn" title="Voice to Text">
                        <ion-icon id="voice-icon" name="mic-outline"></ion-icon>
                    </button>
                    <span id="file-name-display" class="file-name-chip"></span>
                </div>
                <div class="doubt-toolbar-right">
                    <button type="button" id="save-doubt-btn" class="icon-tool-btn" title="Save Doubt">
                        <ion-icon name="bookmark-outline"></ion-icon>
                    </button>
                    <button type="button" id="ask-btn" class="icon-send-btn" title="Ask AI">
                        <ion-icon name="paper-plane-outline"></ion-icon>
                    </button>
                </div>
            </div>
        </div>
        
        <div id="ai-answer" class="result-box"></div>
    </section>

    <!-- Live Quiz Block -->
    <section class="panel">
        <h2 style="display: flex; align-items: center; gap: 8px;"><ion-icon name="game-controller-outline" style="color: var(--gradient-secondary);"></ion-icon> Interactive Quizzes</h2>
        <p style="color: var(--text-light); margin-bottom: 16px; font-size: 14px;">Test your knowledge with an AI-generated live quiz.</p>
        
        <div class="doubt-input-wrapper">
            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 4px;">
                <input type="text" id="quiz-course" class="doubt-input-field" placeholder="Topic (e.g., Algebra)" style="flex: 2;">
                <div style="display: flex; align-items: center; gap: 4px; background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 8px; border: 1px solid var(--border-color, rgba(255,255,255,0.08));">
                    <span style="font-size: 11px; color: var(--text-light, #94a3b8); font-weight: 600; text-transform: uppercase;">Count:</span>
                    <input type="number" id="quiz-count" class="doubt-input-field" placeholder="5" value="5" min="1" max="20" style="width: 35px; text-align: center; font-weight: 600;">
                </div>
            </div>
            
            <div class="doubt-input-toolbar">
                <div class="doubt-toolbar-left">
                    <span style="font-size: 12px; color: var(--text-light, #94a3b8);"><ion-icon name="flash-outline" style="vertical-align: middle; color: var(--gradient-secondary);"></ion-icon> Enter topic & count</span>
                </div>
                <div class="doubt-toolbar-right">
                    <button type="button" id="start-quiz-btn" class="icon-send-btn" title="Generate Quiz">
                        <ion-icon name="flash-outline"></ion-icon>
                    </button>
                </div>
            </div>
        </div>
        
        <div id="quiz-area" class="result-box"></div>
    </section>

    <!-- Assignments Block -->
    <section class="panel">
        <h2 style="display: flex; align-items: center; gap: 8px;"><ion-icon name="document-text-outline" style="color: var(--gradient-warning);"></ion-icon> Custom Assignments</h2>
        <p style="color: var(--text-light); margin-bottom: 16px; font-size: 14px;">Generate practice tasks and essays based on your syllabus.</p>
        
        <div class="doubt-input-wrapper">
            <input type="text" id="assign-title" class="doubt-input-field" placeholder="Assignment Title (e.g., Photosynthesis)" style="margin-bottom: 6px; font-weight: 600;">
            <textarea id="assign-desc" class="doubt-textarea" rows="2" placeholder="Briefly describe what you want to practice..." style="min-height: 50px; margin-bottom: 4px;"></textarea>

            <div class="doubt-input-toolbar">
                <div class="doubt-toolbar-left">
                    <span style="font-size: 12px; color: var(--text-light, #94a3b8);"><ion-icon name="color-wand-outline" style="vertical-align: middle; color: var(--gradient-warning);"></ion-icon> Custom practice tasks</span>
                </div>
                <div class="doubt-toolbar-right">
                    <button type="button" id="create-assign-btn" class="icon-send-btn" title="Create Assignment">
                        <ion-icon name="color-wand-outline"></ion-icon>
                    </button>
                </div>
            </div>
        </div>
        
        <div id="assign-result" class="result-box"></div>
    </section>

    <!-- Timetable Block -->
    <section class="panel">
        <h2 style="display: flex; align-items: center; gap: 8px;"><ion-icon name="calendar-outline" style="color: var(--gradient-success);"></ion-icon> Study Planner</h2>
        <p style="color: var(--text-light); margin-bottom: 16px; font-size: 14px;">Create a balanced, beautiful Markdown study schedule.</p>
        
        <div class="doubt-input-wrapper">
            <input type="text" id="tt-courses" class="doubt-input-field" placeholder="Comma-separated subjects (e.g., Math, Science, History)" style="margin-bottom: 12px;">
            
            <div class="doubt-input-toolbar">
                <div class="doubt-toolbar-left">
                    <span style="font-size: 12px; color: var(--text-light, #94a3b8);"><ion-icon name="grid-outline" style="vertical-align: middle; color: var(--gradient-success);"></ion-icon> Subject list</span>
                </div>
                <div class="doubt-toolbar-right">
                    <button type="button" id="gen-tt-btn" class="icon-send-btn" title="Generate Study Planner">
                        <ion-icon name="grid-outline"></ion-icon>
                    </button>
                </div>
            </div>
        </div>
        
        <div id="tt-result" class="result-box"></div>
    </section>
    
    <!-- Summarizer Block -->
    <section class="panel" style="grid-column: 1 / -1;">
        <h2 style="display: flex; align-items: center; gap: 8px;"><ion-icon name="scan-outline" style="color: var(--gradient-primary);"></ion-icon> Lecture Summarizer</h2>
        <p style="color: var(--text-light); margin-bottom: 16px; font-size: 14px;">Paste long notes or text to extract key takeaways and study resources.</p>
        
        <div class="doubt-input-wrapper">
            <textarea id="lecture-text" class="doubt-textarea" rows="4" placeholder="Paste your lecture transcript or long text here..."></textarea>
            
            <div class="doubt-input-toolbar">
                <div class="doubt-toolbar-left">
                    <span style="font-size: 12px; color: var(--text-light, #94a3b8);"><ion-icon name="layers-outline" style="vertical-align: middle; color: var(--gradient-primary);"></ion-icon> AI text summarization</span>
                </div>
                <div class="doubt-toolbar-right">
                    <button type="button" id="summarize-btn" class="icon-send-btn" title="Summarize Content">
                        <ion-icon name="layers-outline"></ion-icon>
                    </button>
                </div>
            </div>
        </div>
        
        <div id="summary-result" class="result-box"></div>
    </section>
</div>

<script src="../assets/js/voice-recognition.js"></script>
<script>
// Base64 File Handling & Thumbnail Preview
let currentFileData = null;
let currentMimeType = null;
let currentFileRaw = null;

function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

window.removeSelectedFile = function() {
    currentFileData = null;
    currentMimeType = null;
    currentFileRaw = null;
    const fileInput = document.getElementById('ai-file');
    if (fileInput) fileInput.value = '';
    const container = document.getElementById('file-preview-container');
    if (container) {
        container.style.display = 'none';
        container.innerHTML = '';
    }
};

document.getElementById('ai-file').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if(!file) return;
    
    currentFileRaw = file;
    currentMimeType = file.type;
    
    const container = document.getElementById('file-preview-container');
    const isImage = file.type.startsWith('image/');
    
    const reader = new FileReader();
    reader.onload = function(evt) {
        currentFileData = evt.target.result;
        
        if (container) {
            container.style.display = 'block';
            let mediaPreview = '';
            if (isImage) {
                mediaPreview = `<img src="${evt.target.result}" class="file-preview-thumb" alt="Thumbnail Preview">`;
            } else {
                mediaPreview = `<div class="file-preview-icon"><ion-icon name="document-text-outline"></ion-icon></div>`;
            }
            
            container.innerHTML = `
                <div class="file-preview-chip">
                    ${mediaPreview}
                    <div class="file-preview-info">
                        <span class="file-preview-name">${file.name}</span>
                        <span class="file-preview-size">${formatFileSize(file.size)}</span>
                    </div>
                    <button type="button" class="file-preview-remove" onclick="removeSelectedFile()" title="Remove file">&times;</button>
                </div>
            `;
        }
    };
    reader.readAsDataURL(file);
});

// Initialize Vendor Voice Recognition Engine
if (window.VoiceRecognitionEngine) {
    const voiceEngine = new VoiceRecognitionEngine();
    voiceEngine.attach({
        button: document.getElementById('voice-btn'),
        icon: document.getElementById('voice-icon'),
        textarea: document.getElementById('ai-question'),
        indicator: document.getElementById('file-name-display')
    });
}

// AI Engine Model State & Switcher
const supportedAiModels = <?php echo json_encode($supportedModels); ?>;
const defaultAiModelKey = <?php echo json_encode($defaultModelKey); ?>;

function getSelectedAiModel() {
    const sel = document.getElementById('tutor-model-select');
    if (sel && sel.value) return sel.value;
    return localStorage.getItem('edumate_ai_model') || defaultAiModelKey;
}

function onModelChange(val) {
    localStorage.setItem('edumate_ai_model', val);
    const meta = supportedAiModels[val];
    const badge = document.getElementById('active-model-badge');
    if (badge && meta) {
        badge.textContent = meta.badge;
    }
}

// Restore saved AI Model selection on load
document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('edumate_ai_model') || defaultAiModelKey;
    const sel = document.getElementById('tutor-model-select');
    if (sel) {
        sel.value = saved;
        onModelChange(saved);
    }
});

async function apiCall(action, payload) {
    payload.action = action;
    payload.model = getSelectedAiModel();
    try {
        const res = await fetch('../api/ai_tutor.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        return await res.json();
    } catch(e) {
        return {error: e.message};
    }
}

function showLoading(elementId, text) {
    const el = document.getElementById(elementId);
    el.classList.add('active');
    const curModel = getSelectedAiModel();
    const modelName = (supportedAiModels[curModel] && supportedAiModels[curModel].name) || 'Gemini AI';
    el.innerHTML = `<div class="loading-state"><ion-icon name="sync-outline" class="fa-spin"></ion-icon> ${text} <span style="font-size:11px; opacity:0.8; margin-left:6px;">(${modelName})</span></div>`;
}

// 1. Doubt Solver
let lastAnswerHTML = '';
document.getElementById('ask-btn').addEventListener('click', async () => {
    const q = document.getElementById('ai-question').value.trim();
    if(!q && !currentFileData) return window.showToast('Please enter a question or upload a photo.', 'warning', 'Input Required');
    
    showLoading('ai-answer', 'Analyzing your question...');
    
    const payload = { question: q };
    if (currentFileData) {
        payload.fileData = currentFileData;
        payload.mimeType = currentMimeType;
    }
    
    const res = await apiCall('ask', payload);
    const box = document.getElementById('ai-answer');
    
    if(res.error) {
        box.innerHTML = `<span style="color:#ef4444;"><ion-icon name="warning-outline"></ion-icon> ${res.error}</span>`;
    } else {
        lastAnswerHTML = res.answer;
        const usedModelKey = res.model || getSelectedAiModel();
        const modelMeta = supportedAiModels[usedModelKey] || { name: usedModelKey, badge: 'AI' };
        
        box.innerHTML = res.answer + `
            <div style="margin-top:16px; padding-top:10px; border-top:1px dashed var(--border-color); display:flex; align-items:center; justify-content:space-between; font-size:12px; color:var(--text-light);">
                <span><ion-icon name="sparkles" style="color:#7c5cff; vertical-align:middle;"></ion-icon> Answered by <strong>${modelMeta.name}</strong></span>
                <span class="model-badge-chip" style="font-size:9px;">${modelMeta.badge}</span>
            </div>
        `;
    }
});

document.getElementById('ai-question').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        document.getElementById('ask-btn').click();
    }
});

document.getElementById('save-doubt-btn').addEventListener('click', async () => {
    const q = document.getElementById('ai-question').value.trim();
    if(!q || !lastAnswerHTML) return window.showToast('Ask a question first before saving!', 'warning', 'No Answer Available');
    
    const res = await apiCall('save_doubt', { question: q, answer: lastAnswerHTML });
    window.showToast(res.message || res.error || 'Saved!', 'success', 'Doubt Saved');
});

// 2. Interactive Live Quiz
let quizState = { data: [], currentIdx: 0, score: 0 };
document.getElementById('start-quiz-btn').addEventListener('click', async () => {
    const topic = document.getElementById('quiz-course').value.trim() || 'General Science';
    const count = document.getElementById('quiz-count').value || 5;
    
    showLoading('quiz-area', 'Generating interactive quiz...');
    
    const res = await apiCall('generate_quiz', { topic, count });
    const box = document.getElementById('quiz-area');
    
    if(res.error) {
        box.innerHTML = `<span style="color:#ef4444;"><ion-icon name="warning-outline"></ion-icon> ${res.error}</span>`;
        return;
    }
    
    if(res.data && res.quiz_id) {
        quizState.data = res.data;
        quizState.quiz_id = res.quiz_id;
        quizState.currentIdx = 0;
        quizState.score = 0;
        quizState.responses = {};
        renderQuizQuestion();
    } else {
        box.innerHTML = "AI failed to return the correct quiz format. Try again.";
    }
});

function renderQuizQuestion() {
    const box = document.getElementById('quiz-area');
    if (quizState.currentIdx >= quizState.data.length) {
        
        // Save the attempt before showing completion UI
        apiCall('save_quiz_attempt', {
            quiz_id: quizState.quiz_id,
            score: quizState.score,
            total: quizState.data.length,
            responses: quizState.responses
        });
        
        box.innerHTML = `
            <div style="text-align:center; padding: 20px 0;">
                <div style="font-size:48px; color:#10b981; margin-bottom:10px;"><ion-icon name="trophy-outline"></ion-icon></div>
                <h3>Quiz Complete!</h3>
                <p style="font-size:18px;">You scored <strong>${quizState.score} / ${quizState.data.length}</strong>.</p>
                <div style="margin-top:16px; display:flex; gap:12px; justify-content:center;">
                    <button class="btn btn-primary" onclick="document.getElementById('start-quiz-btn').click()"><ion-icon name="refresh-outline"></ion-icon> Try Another Topic</button>
                    <a class="btn btn-ghost" href="quizzes.php"><ion-icon name="folder-open-outline"></ion-icon> View in Quizzes</a>
                </div>
            </div>
        `;
        return;
    }
    
    const q = quizState.data[quizState.currentIdx];
    let html = `<div class="quiz-question"><span style="color:#6366f1;">Q${quizState.currentIdx + 1}:</span> ${q.question}</div>`;
    
    q.options.forEach((opt, idx) => {
        html += `<div class="quiz-option" onclick="handleQuizAnswer(${idx}, ${q.answerIndex}, '${q.explanation.replace(/'/g, "\\'")}')">
            <div style="width:24px; height:24px; border-radius:50%; background:#e2e8f0; color:#1e293b; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700;">${String.fromCharCode(65+idx)}</div>
            ${opt}
        </div>`;
    });
    
    html += `<div id="quiz-feedback" style="margin-top:16px; display:none; padding:12px; border-radius:8px;"></div>`;
    html += `<button id="quiz-next-btn" class="btn btn-primary" style="margin-top:16px; display:none;" onclick="nextQuizQuestion()">Next Question <ion-icon name="arrow-forward-outline"></ion-icon></button>`;
    
    box.innerHTML = html;
}

window.handleQuizAnswer = function(selectedIdx, correctIdx, explanation) {
    const options = document.querySelectorAll('.quiz-option');
    // Disable all options
    options.forEach(opt => opt.style.pointerEvents = 'none');
    
    const feedback = document.getElementById('quiz-feedback');
    feedback.style.display = 'block';
    
    // Save response locally for the database
    const currentQ = quizState.data[quizState.currentIdx];
    const letters = ['a','b','c','d'];
    if (currentQ.db_id) {
        quizState.responses[currentQ.db_id] = letters[selectedIdx] || 'a';
    }
    
    if(selectedIdx === correctIdx) {
        options[selectedIdx].classList.add('correct');
        feedback.style.background = '#ecfdf5';
        feedback.innerHTML = `<strong style="color:#065f46;"><ion-icon name="checkmark-circle-outline"></ion-icon> Correct!</strong><br><span style="color:#047857; font-size:14px; margin-top:6px; display:block;">${explanation}</span>`;
        quizState.score++;
    } else {
        options[selectedIdx].classList.add('wrong');
        options[correctIdx].classList.add('correct');
        feedback.style.background = '#fef2f2';
        feedback.innerHTML = `<strong style="color:#991b1b;"><ion-icon name="close-circle-outline"></ion-icon> Incorrect.</strong><br><span style="color:#b91c1c; font-size:14px; margin-top:6px; display:block;">${explanation}</span>`;
    }
    
    document.getElementById('quiz-next-btn').style.display = 'inline-flex';
};

window.nextQuizQuestion = function() {
    quizState.currentIdx++;
    renderQuizQuestion();
};

// 3. Assignments
document.getElementById('create-assign-btn').addEventListener('click', async () => {
    const title = document.getElementById('assign-title').value.trim();
    if(!title) return window.showToast('Please enter an assignment title.', 'warning', 'Missing Title');
    
    showLoading('assign-result', 'Drafting comprehensive assignment...');
    const res = await apiCall('create_assignment', {
        title: title,
        description: document.getElementById('assign-desc').value.trim()
    });
    
    const box = document.getElementById('assign-result');
    box.innerHTML = res.assignment || res.error || 'Failed to generate.';
});

// 4. Timetable
document.getElementById('gen-tt-btn').addEventListener('click', async () => {
    const courses = document.getElementById('tt-courses').value.trim();
    if(!courses) return window.showToast('Please enter courses or subjects.', 'warning', 'Missing Subjects');
    
    showLoading('tt-result', 'Designing optimal study plan...');
    const res = await apiCall('generate_timetable', { courses });
    
    const box = document.getElementById('tt-result');
    box.innerHTML = res.html || res.error || 'Failed to generate planner.';
});

// 5. Summarizer
document.getElementById('summarize-btn').addEventListener('click', async () => {
    const text = document.getElementById('lecture-text').value.trim();
    if(!text) return window.showToast('Please paste lecture notes or text to summarize.', 'warning', 'Missing Text');
    
    showLoading('summary-result', 'Extracting key insights...');
    const res = await apiCall('summarize', { text });
    
    const box = document.getElementById('summary-result');
    box.innerHTML = res.summary || res.error || 'Failed to summarize.';
});

// Enter / Ctrl+Enter shortcuts for all AI Tutor inputs
['quiz-course', 'quiz-count'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('start-quiz-btn').click();
            }
        });
    }
});

['assign-title', 'assign-desc'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey || id === 'assign-title')) {
                e.preventDefault();
                document.getElementById('create-assign-btn').click();
            }
        });
    }
});

const ttEl = document.getElementById('tt-courses');
if (ttEl) {
    ttEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('gen-tt-btn').click();
        }
    });
}

const sumEl = document.getElementById('lecture-text');
if (sumEl) {
    sumEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            document.getElementById('summarize-btn').click();
        }
    });
}

</script>

<?php render_dashboard_footer(); ?>

