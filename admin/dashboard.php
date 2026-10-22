<?php
/**
 * EduMate - Admin Dashboard (Overview)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_role('admin');

$user = current_user();
$pdo  = db();

$totalUsers    = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$students      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$teachers      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='teacher'")->fetchColumn();
$admins        = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$totalCourses  = (int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$totalEnrolled = (int)$pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();
$totalLogins   = (int)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success=1")->fetchColumn();
$totalAttempts = (int)$pdo->query('SELECT COUNT(*) FROM quiz_attempts')->fetchColumn();
$totalVideos   = (int)$pdo->query('SELECT COUNT(*) FROM videos')->fetchColumn();
$totalMessages = (int)$pdo->query("SELECT COUNT(*) FROM tutor_messages WHERE role='user'")->fetchColumn();

$recent = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.role, u.created_at, u.last_login
       FROM users u ORDER BY u.created_at DESC LIMIT 8"
)->fetchAll();

$loginTrend = array_fill(0, 7, 0);
$today = new DateTimeImmutable('today');
$trend = $pdo->query(
    "SELECT DATE(attempted_at) AS d, COUNT(*) AS c
       FROM login_attempts
      WHERE success=1 AND attempted_at >= (NOW() - INTERVAL 7 DAY)
      GROUP BY DATE(attempted_at)"
);
foreach ($trend as $row) {
    $d = new DateTimeImmutable($row['d']);
    $idx = 6 - (int)$today->diff($d)->format('%r%a');
    if ($idx >= 0 && $idx < 7) $loginTrend[$idx] = (int)$row['c'];
}
$trendMax = max($loginTrend ?: [1]);

render_dashboard_header(
    'Overview',
    admin_nav(),
    'overview',
    ['Home', 'Overview']
);
?>
<section class="cards">
    <div class="card">
        <div class="ico"><ion-icon name="people-outline"></ion-icon></div>
        <div class="label">Total Users</div>
        <div class="value"><?php echo $totalUsers; ?></div>
        <div class="delta"><?php echo $students; ?> students · <?php echo $teachers; ?> teachers · <?php echo $admins; ?> admins</div>
    </div>
    <div class="card s2">
        <div class="ico"><ion-icon name="school-outline"></ion-icon></div>
        <div class="label">Courses</div>
        <div class="value"><?php echo $totalCourses; ?></div>
        <div class="delta"><?php echo $totalEnrolled; ?> enrollments</div>
    </div>
    <div class="card s3">
        <div class="ico"><ion-icon name="play-circle-outline"></ion-icon></div>
        <div class="label">Videos & Quizzes</div>
        <div class="value"><?php echo $totalVideos; ?> <span style="font-size:14px; color:var(--text-light);">videos</span></div>
        <div class="delta"><?php echo $totalAttempts; ?> quiz attempts</div>
    </div>
    <div class="card s4">
        <div class="ico"><ion-icon name="log-in-outline"></ion-icon></div>
        <div class="label">Successful Logins (7d)</div>
        <div class="value"><?php echo array_sum($loginTrend); ?></div>
        <div class="delta"><?php echo $totalMessages; ?> AI tutor questions</div>
    </div>
</section>

<div class="panel-grid">
    <section class="panel">
        <h2>Recent users</h2>
        <table class="simple">
            <thead><tr><th>Name</th><th>Role</th><th>Joined</th><th>Last login</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES); ?></strong><br><span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($r['email'], ENT_QUOTES); ?></span></td>
                        <td><span class="tag"><?php echo htmlspecialchars(ucfirst($r['role']), ENT_QUOTES); ?></span></td>
                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime($r['created_at'])), ENT_QUOTES); ?></td>
                        <td><?php echo $r['last_login'] ? htmlspecialchars(date('M j, g:i A', strtotime($r['last_login'])), ENT_QUOTES) : '<span style="color:var(--text-light)">Never</span>'; ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="users.php?search=<?php echo urlencode($r['email']); ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2>Logins (last 7 days)</h2>
        <p style="font-size:12px; color:var(--text-light); margin-bottom:8px;">Successful sign-ins per day</p>
        <div style="display:flex; align-items:flex-end; gap:6px; height:120px;">
            <?php $labels = []; for ($i = 6; $i >= 0; $i--) $labels[] = (new DateTimeImmutable("-$i days"))->format('D'); ?>
            <?php foreach ($loginTrend as $i => $count): $h = $trendMax > 0 ? max(8, (int)round($count * 100 / $trendMax)) : 8; ?>
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;">
                    <div title="<?php echo $count; ?> logins" style="width:100%; height:<?php echo $h; ?>%; background: linear-gradient(180deg, #43e97b, #38f9d7); border-radius:6px;"></div>
                    <span style="font-size:11px; color:var(--text-light);"><?php echo htmlspecialchars($labels[$i] ?? '', ENT_QUOTES); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php render_dashboard_footer(); ?>
