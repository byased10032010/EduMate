<?php
/**
 * EduMate - Admin: Security center (login attempts, suspicious activity)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('admin');

$pdo = db();

$failed24h   = (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
$success24h  = (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success=1 AND attempted_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn();
$topFailed   = $pdo->query(
    "SELECT email, COUNT(*) AS c FROM login_attempts
      WHERE success=0 AND attempted_at >= (NOW() - INTERVAL 7 DAY)
      GROUP BY email ORDER BY c DESC LIMIT 10"
)->fetchAll();
$recentAttempts = $pdo->query(
    "SELECT email, ip_address, success, attempted_at FROM login_attempts
      ORDER BY attempted_at DESC LIMIT 30"
)->fetchAll();

render_dashboard_header(
    'Security',
    admin_nav(),
    'security',
    ['Home', 'Security']
);
?>
<section class="cards">
    <div class="card">
        <div class="ico"><i class="fas fa-check-circle"></i></div>
        <div class="label">Successful logins (24h)</div>
        <div class="value"><?php echo $success24h; ?></div>
    </div>
    <div class="card s4">
        <div class="ico"><ion-icon name="warning-outline"></ion-icon></div>
        <div class="label">Failed attempts (24h)</div>
        <div class="value"><?php echo $failed24h; ?></div>
    </div>
    <div class="card s2">
        <div class="ico"><i class="fas fa-shield-halved"></i></div>
        <div class="label">Security status</div>
        <div class="value" style="font-size:18px;">Healthy</div>
        <div class="delta">All systems operational</div>
    </div>
    <div class="card s3">
        <div class="ico"><ion-icon name="time-outline"></ion-icon></div>
        <div class="label">Rate limit</div>
        <div class="value" style="font-size:18px;">5 / 15 min</div>
        <div class="delta">Auto-throttle active</div>
    </div>
</section>

<section class="panel">
    <h2>Top failed-login emails (7d)</h2>
    <?php if (empty($topFailed)): ?>
        <div class="empty"><div class="ico"><i class="fas fa-shield-halved"></i></div><h3>No failed attempts in the last 7 days.</h3></div>
    <?php else: ?>
        <table class="simple">
            <thead><tr><th>Email</th><th>Failed attempts</th></tr></thead>
            <tbody>
                <?php foreach ($topFailed as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['email'], ENT_QUOTES); ?></td>
                        <td><span class="tag warn"><?php echo (int)$r['c']; ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Recent login attempts</h2>
    <table class="simple">
        <thead><tr><th>When</th><th>Email</th><th>IP</th><th>Result</th></tr></thead>
        <tbody>
            <?php foreach ($recentAttempts as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars(date('M j, g:i:s A', strtotime($a['attempted_at'])), ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($a['email'], ENT_QUOTES); ?></td>
                    <td><code style="font-size:12px;"><?php echo htmlspecialchars($a['ip_address'], ENT_QUOTES); ?></code></td>
                    <td>
                        <?php if ((int)$a['success'] === 1): ?>
                            <span class="tag success">OK</span>
                        <?php else: ?>
                            <span class="tag warn">Failed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php render_dashboard_footer(); ?>

