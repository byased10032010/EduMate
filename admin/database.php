<?php
/**
 * EduMate - Admin: Database overview
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('admin');

$pdo = db();
$tables = $pdo->query(
    "SELECT table_name, table_rows
       FROM information_schema.tables
      WHERE table_schema = DATABASE()
      ORDER BY table_name"
)->fetchAll();

$rowCount = 0;
foreach ($tables as $t) $rowCount += (int)$t['table_rows'];

render_dashboard_header(
    'Database',
    admin_nav(),
    'database',
    ['Home', 'Database']
);
?>
<section class="cards">
    <div class="card">
        <div class="ico"><i class="fas fa-table"></i></div>
        <div class="label">Tables</div>
        <div class="value"><?php echo count($tables); ?></div>
    </div>
    <div class="card s2">
        <div class="ico"><i class="fas fa-database"></i></div>
        <div class="label">Approx. row count</div>
        <div class="value"><?php echo number_format($rowCount); ?></div>
    </div>
    <div class="card s3">
        <div class="ico"><i class="fas fa-server"></i></div>
        <div class="label">Server</div>
        <div class="value" style="font-size:16px;"><?php echo htmlspecialchars($pdo->query('SELECT VERSION()')->fetchColumn(), ENT_QUOTES); ?></div>
    </div>
    <div class="card s4">
        <div class="ico"><i class="fas fa-circle-check"></i></div>
        <div class="label">Connection</div>
        <div class="value" style="font-size:16px;">OK</div>
    </div>
</section>
<section class="panel">
    <h2>Schema overview</h2>
    <table class="simple">
        <thead><tr><th>Table</th><th>Approx. rows</th></tr></thead>
        <tbody>
            <?php foreach ($tables as $t): ?>
                <tr>
                    <td><code style="font-size:13px;"><?php echo htmlspecialchars($t['table_name'], ENT_QUOTES); ?></code></td>
                    <td><?php echo number_format((int)$t['table_rows']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:14px; color:var(--text-light); font-size:13px;">
        <i class="fas fa-info-circle"></i> Schema lives in <code>database.sql</code>. Re-running the file is safe — it uses <code>CREATE TABLE IF NOT EXISTS</code>.
    </p>
</section>
<?php render_dashboard_footer(); ?>
