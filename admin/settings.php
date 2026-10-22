<?php
/**
 * EduMate - Admin: Settings (site-wide)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/ai_functions.php';
require_role('admin');

$availableModels = get_supported_ai_models();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: settings.php'); exit;
    }
    $siteName  = trim((string)($_POST['site_name'] ?? ''));
    $allowReg  = !empty($_POST['allow_registration']) ? '1' : '0';
    $aiModel   = trim((string)($_POST['ai_model'] ?? 'gemini-3.6-flash'));
    if (!isset($availableModels[$aiModel])) {
        $aiModel = 'gemini-3.6-flash';
    }

    // Persist to a tiny file-backed JSON store
    $cfgFile = __DIR__ . '/../config/site.json';
    @file_put_contents($cfgFile, json_encode([
        'site_name'          => $siteName !== '' ? $siteName : 'EduMate',
        'allow_registration' => $allowReg,
        'ai_model'           => $aiModel,
        'updated_at'         => date('c'),
    ], JSON_PRETTY_PRINT));
    flash_set('success', 'Settings saved.');
    header('Location: settings.php');
    exit;
}

$cfgFile = __DIR__ . '/../config/site.json';
$cfg = file_exists($cfgFile) ? (json_decode((string)file_get_contents($cfgFile), true) ?: []) : [
    'site_name'          => 'EduMate',
    'allow_registration' => '1',
    'ai_model'           => get_default_ai_model()
];
$selectedAiModel = $cfg['ai_model'] ?? get_default_ai_model();

render_dashboard_header(
    'Settings',
    admin_nav(),
    'settings',
    ['Home', 'Settings']
);
?>
<section class="panel" style="max-width:680px;">
    <h2>Site Settings</h2>
    <form method="POST" action="settings.php">
        <?php echo csrf_field(); ?>
        <div class="form-grid">
            <div class="form-group span-2">
                <label>Site name</label>
                <input class="form-control" name="site_name" value="<?php echo htmlspecialchars($cfg['site_name'] ?? 'EduMate', ENT_QUOTES); ?>">
            </div>
            <div class="form-group span-2">
                <label class="remember" style="font-size:14px;">
                    <input type="checkbox" name="allow_registration" value="1" <?php echo !empty($cfg['allow_registration']) ? 'checked' : ''; ?>>
                    Allow new students/teachers to sign up
                </label>
            </div>

            <div class="form-group span-2" style="border-top:1px solid var(--border-color); padding-top:16px; margin-top:8px;">
                <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
                    <ion-icon name="hardware-chip-outline" style="color:var(--gradient-info);"></ion-icon>
                    Platform Default Gemini AI Model
                </label>
                <p style="font-size:12px; color:var(--text-light); margin-bottom:10px;">
                    Powers AI Flashcards, Multimodal AI Tutor, Quiz Generator, Assignment Creator, and Summaries across EduMate.
                </p>
                <select name="ai_model" class="form-control" style="background:var(--bg-card); color:var(--text-primary); font-weight:500;">
                    <?php foreach ($availableModels as $mKey => $mInfo): ?>
                        <option value="<?php echo htmlspecialchars($mKey, ENT_QUOTES); ?>" <?php echo $selectedAiModel === $mKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mInfo['name'] . ' (' . $mInfo['tag'] . ')', ENT_QUOTES); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px;"><ion-icon name="save-outline"></ion-icon> Save settings</button>
    </form>
    <p style="margin-top:14px; color:var(--text-light); font-size:13px;">
        <i class="fas fa-info-circle"></i> Settings are saved to <code>config/site.json</code>. Individual tools like AI Tutor & AI Flashcards also let students dynamically switch models on the fly.
    </p>
</section>
<?php render_dashboard_footer(); ?>

