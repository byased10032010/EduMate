<?php
/**
 * EduMate - Admin: Settings (site-wide)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check(is_string($token) ? $token : null)) {
        flash_set('error', 'Session expired.');
        header('Location: settings.php'); exit;
    }
    $siteName  = trim((string)($_POST['site_name'] ?? ''));
    $allowReg  = !empty($_POST['allow_registration']) ? '1' : '0';
    // Persist to a tiny file-backed JSON store (no new schema needed for this demo)
    $cfgFile = __DIR__ . '/../config/site.json';
    @file_put_contents($cfgFile, json_encode([
        'site_name'          => $siteName !== '' ? $siteName : 'EduMate',
        'allow_registration' => $allowReg,
        'updated_at'         => date('c'),
    ], JSON_PRETTY_PRINT));
    flash_set('success', 'Settings saved.');
    header('Location: settings.php');
    exit;
}

$cfgFile = __DIR__ . '/../config/site.json';
$cfg = file_exists($cfgFile) ? (json_decode(file_get_contents($cfgFile), true) ?: []) : ['site_name' => 'EduMate', 'allow_registration' => '1'];

render_dashboard_header(
    'Settings',
    admin_nav(),
    'settings',
    ['Home', 'Settings']
);
?>
<section class="panel" style="max-width:640px;">
    <h2>Site settings</h2>
    <form method="POST" action="settings.php">
        <?php echo csrf_field(); ?>
        <div class="form-grid">
            <div class="form-group span-2">
                <label>Site name</label>
                <input class="form-control" name="site_name" value="<?php echo htmlspecialchars($cfg['site_name'], ENT_QUOTES); ?>">
            </div>
            <div class="form-group span-2">
                <label class="remember" style="font-size:14px;">
                    <input type="checkbox" name="allow_registration" value="1" <?php echo !empty($cfg['allow_registration']) ? 'checked' : ''; ?>>
                    Allow new students/teachers to sign up
                </label>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:14px;"><ion-icon name="save-outline"></ion-icon> Save settings</button>
    </form>
    <p style="margin-top:14px; color:var(--text-light); font-size:13px;">
        <i class="fas fa-info-circle"></i> Settings are saved to <code>config/site.json</code>. Useful as a starting point for further configuration.
    </p>
</section>
<?php render_dashboard_footer(); ?>

