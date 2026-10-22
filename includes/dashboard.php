<?php
/**
 * EduMate - Shared dashboard layout helpers.
 *
 * Renders the consistent sidebar / topbar / flash / footer for every
 * dashboard page across all three roles.
 *
 * Usage from a page:
 *
 *   $pageTitle  = 'Dashboard';
 *   $pageKey    = 'dashboard';  // matches the `data-page` value in nav items
 *   $roleNav    = student_nav();   // or teacher_nav() / admin_nav()
 *   $activeKey  = 'dashboard';
 *   $crumbs     = ['Home', 'Dashboard'];
 *   render_dashboard_header($pageTitle, $roleNav, $activeKey, $crumbs);
 *   ... page body ...
 *   render_dashboard_footer();
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Returns the navigation structure for a given role.
 * Each entry: key, label, icon, href, group (optional).
 */
function student_nav(): array
{
    return [
        'overview'    => ['label' => 'Overview',      'icon' => 'home-outline',          'href' => 'dashboard.php', 'group' => 'Learn'],
        'boards'      => ['label' => 'Boards Directory','icon' => 'globe-outline',         'href' => 'boards.php'],
        'subjects'    => ['label' => 'Subjects Hub',  'icon' => 'cube-outline',          'href' => 'subjects.php'],
        'courses'     => ['label' => 'My Courses',    'icon' => 'book-outline',          'href' => 'courses.php'],
        'live'        => ['label' => 'Live Classes',  'icon' => 'radio-outline',         'href' => 'live.php'],
        'notices'     => ['label' => 'Notices',       'icon' => 'megaphone-outline',     'href' => 'notices.php'],
        'quizzes'     => ['label' => 'Quizzes',       'icon' => 'clipboard-outline',     'href' => 'quizzes.php'],
        'assignments' => ['label' => 'Assignments',   'icon' => 'reader-outline',        'href' => 'assignments.php'],
        'videos'      => ['label' => 'Videos',        'icon' => 'play-circle-outline',   'href' => 'videos.php'],
        'progress'    => ['label' => 'My Progress',   'icon' => 'bar-chart-outline',     'href' => 'progress.php'],
        'report'      => ['label' => 'Weekly Report', 'icon' => 'pie-chart-outline',     'href' => 'weekly_report.php'],
        'ai-suggestions'=> ['label' => 'AI Suggestions', 'icon' => 'sparkles-outline',      'href' => 'ai_suggestions.php', 'group' => 'AI Tools'],
        'ai-tutor'    => ['label' => 'AI Tutor',      'icon' => 'hardware-chip-outline', 'href' => 'ai_tutor.php'],
        'flashcards'  => ['label' => 'AI Flashcards', 'icon' => 'card-outline',          'href' => 'flashcards.php'],
    ];
}

function teacher_nav(): array
{
    return [
        'overview'     => ['label' => 'Overview',       'icon' => 'home-outline',          'href' => 'dashboard.php', 'group' => 'Teach'],
        'boards'       => ['label' => 'Boards Directory','icon' => 'globe-outline',         'href' => '../student/boards.php'],
        'classes'      => ['label' => 'My Classes',     'icon' => 'school-outline',        'href' => 'classes.php'],
        'courses'      => ['label' => 'Courses',        'icon' => 'book-outline',          'href' => 'courses.php'],
        'curriculum'   => ['label' => 'Boards & Subjects','icon' => 'cube-outline',        'href' => 'curriculum.php'],
        'assignments'  => ['label' => 'Assignments',    'icon' => 'reader-outline',        'href' => 'assignments.php'],
        'quizzes'      => ['label' => 'Quizzes',        'icon' => 'clipboard-outline',     'href' => 'quizzes.php'],
        'live'         => ['label' => 'Live Classes',   'icon' => 'radio-outline',         'href' => 'live.php'],
        'notices'      => ['label' => 'Notices',        'icon' => 'megaphone-outline',     'href' => 'notices.php'],
        'analytics'    => ['label' => 'Analytics',      'icon' => 'bar-chart-outline',     'href' => 'analytics.php', 'group' => 'Insights'],
        'videos'       => ['label' => 'Video Library',  'icon' => 'play-circle-outline',   'href' => 'videos.php',    'group' => 'Library'],
    ];
}

function admin_nav(): array
{
    return [
        'overview'  => ['label' => 'Overview',    'icon' => 'home-outline',          'href' => 'dashboard.php', 'group' => 'Admin'],
        'users'     => ['label' => 'Users',       'icon' => 'people-outline',        'href' => 'users.php'],
        'security'  => ['label' => 'Security',    'icon' => 'shield-checkmark-outline', 'href' => 'security.php'],
        'database'  => ['label' => 'Database',    'icon' => 'server-outline',        'href' => 'database.php',  'group' => 'System'],
        'settings'  => ['label' => 'Settings',    'icon' => 'settings-outline',      'href' => 'settings.php'],
    ];
}

/**
 * Where each role's pages live. Used to build sidebar hrefs.
 */
function role_base(string $role): string
{
    return match ($role) {
        'admin'   => '../admin/',
        'teacher' => '../teacher/',
        'student' => '../student/',
        default   => '../',
    };
}

/**
 * Returns the two-letter initials for the avatar bubble.
 */
function user_initials(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/u', $name);
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = mb_substr(end($parts) ?: '', 0, 1);
    return mb_strtoupper($first . $last);
}

/**
 * Returns a deterministic gradient pair for the user's avatar bubble.
 * The same user always gets the same colors.
 */
function user_avatar_gradient(int $userId): array
{
    $palette = [
        ['#667eea', '#764ba2'],
        ['#f093fb', '#f5576c'],
        ['#4facfe', '#00f2fe'],
        ['#43e97b', '#38f9d7'],
        ['#fa709a', '#fee140'],
        ['#a18cd1', '#fbc2eb'],
        ['#ff9a9e', '#fad0c4'],
        ['#ffecd2', '#fcb69f'],
    ];
    return $palette[$userId % count($palette)];
}

function dashboard_activity_icon_name(string $icon): string
{
    return match ($icon) {
        'fa-book-open', 'fa-book' => 'book-outline',
        'fa-check-circle', 'fa-check-double' => 'checkmark-circle-outline',
        'fa-clipboard-question', 'fa-clipboard-check' => 'clipboard-outline',
        'fa-fire' => 'flame-outline',
        'fa-bolt' => 'flash-outline',
        'fa-chalkboard' => 'school-outline',
        'fa-user-graduate' => 'people-outline',
        'fa-tasks' => 'reader-outline',
        'fa-inbox' => 'mail-outline',
        'fa-video' => 'play-circle-outline',
        'fa-robot' => 'sparkles-outline',
        'fa-user-gear' => 'person-circle-outline',
        'fa-lock' => 'lock-closed-outline',
        'fa-paper-plane' => 'send-outline',
        'fa-comments' => 'chatbubble-ellipses-outline',
        'fa-bullhorn' => 'megaphone-outline',
        'fa-cubes', 'fa-cube' => 'cube-outline',
        'fa-globe' => 'globe-outline',
        'fa-tower-broadcast' => 'radio-outline',
        'fa-chart-line', 'fa-chart-column' => 'bar-chart-outline',
        'fa-plus' => 'add-outline',
        'fa-trash' => 'trash-outline',
        'fa-save' => 'save-outline',
        'fa-clock' => 'time-outline',
        'fa-signal' => 'stats-chart-outline',
        'fa-exclamation-circle' => 'alert-circle-outline',
        'fa-info-circle' => 'information-circle-outline',
        default => 'ellipse-outline',
    };
}

function render_flash_messages(): void
{
    foreach (flash_pull() as $f) {
        $type = htmlspecialchars($f['type'], ENT_QUOTES, 'UTF-8');
        $icon = match ($type) {
            'error'   => 'fa-exclamation-circle',
            'success' => 'fa-check-circle',
            default   => 'fa-info-circle',
        };
        $message = $f['message'];
        if (is_array($message)) {
            $content = '<ul class="flash-list">';
            foreach ($message as $line) {
                $content .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $content .= '</ul>';
        } else {
            $content = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }
        echo '<div class="flash flash-' . $type . '"><i class="fas ' . $icon . '"></i> ' . $content . '</div>';
    }
}

/**
 * Renders the entire <head>, sidebar, topbar. Caller echoes page body
 * and then calls render_dashboard_footer().
 */
function render_dashboard_header(
    string $title,
    array  $nav,
    string $activeKey,
    array  $crumbs = ['Home'],
    string $roleLabel = 'Dashboard'
): void {
    $user    = current_user();
    if (!$user) {
        require_role('admin', 'teacher', 'student');
        return; // never reached
    }
    $base    = role_base($user['role']);
    [$c1, $c2] = user_avatar_gradient((int)$user['id']);
    $avatarBg = $c1;
    $initials = user_initials($user['full_name']);
    $fullTitle = $title . ' · EduMate';

    // Build a friendly current path crumb
    $crumbHtml = '';
    foreach ($crumbs as $i => $c) {
        if ($i > 0) $crumbHtml .= ' <span class="sep">/</span> ';
        $crumbHtml .= '<span>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    echo '<!DOCTYPE html>', "\n";
    echo '<html lang="en">', "\n";
    echo '<head>', "\n";
    echo '  <meta charset="UTF-8">', "\n";
    echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0">', "\n";
    echo '  <title>' . htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') . '</title>', "\n";
    echo '  <link rel="apple-touch-icon" sizes="180x180" href="../favicon_io/apple-touch-icon.png">', "\n";
    echo '  <link rel="icon" type="image/png" sizes="32x32" href="../favicon_io/favicon-32x32.png">', "\n";
    echo '  <link rel="icon" type="image/png" sizes="16x16" href="../favicon_io/favicon-16x16.png">', "\n";
    echo '  <link rel="manifest" href="../favicon_io/site.webmanifest">', "\n";
    echo '  <link rel="preconnect" href="https://fonts.googleapis.com">', "\n";
    echo '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>', "\n";
    echo '  <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..900;1,6..96,400..900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">', "\n";
    echo '  <link rel="stylesheet" href="../assets/css/dashboard.css?v=5">', "\n";
    echo '  <link rel="stylesheet" href="../assets/css/theme-switch.css?v=3">', "\n";
    echo '  <link rel="stylesheet" href="../assets/css/custom-select.css?v=2">', "\n";
    echo '  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">', "\n";
    echo '  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">', "\n";
    echo '  <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>', "\n";
    echo '  <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>', "\n";
    echo '  <script type="module" src="https://unpkg.com/ionicons@7.1.2/dist/ionicons/ionicons.esm.js"></script>', "\n";
    echo '  <script nomodule src="https://unpkg.com/ionicons@7.1.2/dist/ionicons/ionicons.js"></script>', "\n";

    echo '</head>', "\n";
    echo '<body data-page="' . htmlspecialchars($activeKey, ENT_QUOTES, 'UTF-8') . '">', "\n";
    echo '<div class="dash">', "\n";

    // ----- Sidebar -----
    echo '  <aside class="sidebar">', "\n";
    echo '    <a class="brand" href="' . $base . 'dashboard.php">', "\n";
    echo '      <span class="brand-dot">', "\n";
    echo '          <img src="../favicon_io/apple-touch-icon.png" alt="EduMate Logo" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">', "\n";
    echo '      </span>', "\n";
    echo '      <span class="brand-title">EduMate</span>', "\n";
    echo '    </a>', "\n";

    // Render nav in the order defined. When a `group` key changes, emit
    // a section label first (but only on the FIRST item that introduces a
    // new group, to avoid duplicate labels).
    $lastGroup = null;
    foreach ($nav as $key => $item) {
        $group = $item['group'] ?? null;
        if ($group !== null && $group !== $lastGroup) {
            echo '    <div class="nav-group">', "\n";
            echo '      <div class="label">' . htmlspecialchars($group, ENT_QUOTES, 'UTF-8') . '</div>', "\n";
        } elseif ($group === null && $lastGroup !== null) {
            echo '    <div class="nav-group">', "\n";
        }
        $lastGroup = $group;

        $href   = $base . $item['href'];
        $active = $key === $activeKey ? ' active' : '';
        $icon   = $item['icon'];
        $label  = $item['label'];
        echo '      <a class="nav-item' . $active . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">', "\n";
        echo '        <ion-icon name="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></ion-icon>', "\n";
        echo '        <span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>', "\n";
        echo '      </a>', "\n";
    }
    // Close the last open nav-group div
    echo '    </div>', "\n";

    echo '    <div class="spacer"></div>', "\n";
    echo '    <a class="profile-chip" href="' . $base . 'profile.php" title="' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . '">', "\n";
    echo '      <span class="avatar" style="background:' . $avatarBg . ';">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</span>', "\n";
    echo '      <span class="profile-details">', "\n";
    echo '        <span class="profile-name">' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . '</span>', "\n";
    echo '        <span class="profile-email">' . htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') . '</span>', "\n";
    echo '      </span>', "\n";
    echo '    </a>', "\n";
    echo '    <form method="POST" action="../logout.php">', "\n";
    echo '      <button class="logout" type="submit"><ion-icon name="log-out-outline"></ion-icon> <span>Logout</span></button>', "\n";
    echo '    </form>', "\n";
    echo '  </aside>', "\n";

    // ----- Main column -----
    echo '  <main class="main">', "\n";
    echo '    <div class="topbar">', "\n";
    echo '      <div style="display: flex; align-items: center; gap: 16px;">', "\n";
    echo '        <button class="icon-btn sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><ion-icon name="menu-outline"></ion-icon></button>', "\n";
    echo '        <div>', "\n";
    echo '          <div class="crumb">' . $crumbHtml . '</div>', "\n";
    echo '          <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' <span class="badge-role">' . htmlspecialchars(ucfirst($user['role']), ENT_QUOTES, 'UTF-8') . '</span></h1>', "\n";
    echo '        </div>', "\n";
    echo '      </div>', "\n";
    echo '      <div class="topbar-actions" style="display:flex; align-items:center;">', "\n";
    echo '        <label class="theme-switch" aria-label="Toggle dark mode">', "\n";
    echo '            <input type="checkbox" class="theme-switch__checkbox">', "\n";
    echo '            <div class="theme-switch__container">', "\n";
    echo '                <div class="theme-switch__clouds"></div>', "\n";
    echo '                <div class="theme-switch__stars-container"><svg viewBox="0 0 144 55" fill="none"><path d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688Z" fill="currentColor"/></svg></div>', "\n";
    echo '                <div class="theme-switch__circle-container"><div class="theme-switch__sun-moon-container"><div class="theme-switch__moon"><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div><div class="theme-switch__spot"></div></div></div></div>', "\n";
    echo '            </div>', "\n";
    echo '        </label>', "\n";
    echo '      </div>', "\n";
    echo '    </div>', "\n";
    render_flash_messages();
}

function render_dashboard_footer(): void
{
    echo '  </main>', "\n";
    echo '</div>', "\n";
    echo '<script src="../assets/js/dashboard.js"></script>', "\n";
    echo '<script src="../assets/js/theme-toggle.js"></script>', "\n";
    echo '<script>
window.showToast = function(message, type = "info", title = "") {
    let container = document.getElementById("edumate-toast-container");
    if (!container) {
        container = document.createElement("div");
        container.id = "edumate-toast-container";
        container.style.cssText = "position:fixed; top:24px; right:24px; z-index:999999; display:flex; flex-direction:column; gap:10px; max-width:380px; width:calc(100% - 48px); pointer-events:none;";
        document.body.appendChild(container);
    }

    const toast = document.createElement("div");
    toast.className = "edumate-toast edumate-toast-" + type;
    toast.style.cssText = `
        pointer-events:auto;
        background: linear-gradient(135deg, rgba(30, 27, 75, 0.96), rgba(49, 46, 129, 0.96));
        border: 1px solid ${type === "error" ? "rgba(239, 68, 68, 0.5)" : type === "success" ? "rgba(16, 185, 129, 0.5)" : "rgba(129, 140, 248, 0.5)"};
        color: #ffffff;
        padding: 12px 16px;
        border-radius: 14px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 13.5px;
        line-height: 1.4;
        opacity: 0;
        transform: translateX(30px) scale(0.95);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    `;

    const iconName = type === "error" ? "alert-circle" : type === "success" ? "checkmark-circle" : type === "warning" ? "warning" : "information-circle";
    const iconColor = type === "error" ? "#ef4444" : type === "success" ? "#10b981" : type === "warning" ? "#f59e0b" : "#818cf8";

    toast.innerHTML = `
        <ion-icon name="${iconName}" style="color:${iconColor}; font-size:24px; flex-shrink:0;"></ion-icon>
        <div style="flex:1;">
            ${title ? `<div style="font-weight:700; margin-bottom:2px; font-size:13px; color:#fff;">${title}</div>` : ""}
            <div style="color:#c7d2fe;">${message}</div>
        </div>
        <button type="button" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:18px; padding:0; display:flex; align-items:center;" onclick="this.parentElement.remove()">&times;</button>
    `;

    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.style.opacity = "1";
        toast.style.transform = "translateX(0) scale(1)";
    });

    setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "translateX(30px) scale(0.95)";
        setTimeout(() => toast.remove(), 350);
    }, 4500);
};
</script>', "\n";
    echo '</body>', "\n";
    echo '</html>', "\n";
}

/**
 * Small helper: record a row in the activities feed.
 */
if (!function_exists('record_activity')) {
    function record_activity(int $userId, string $icon, string $title, ?string $detail = null): void
    {
        try {
            $stmt = db()->prepare(
                'INSERT INTO activities (user_id, icon, title, detail) VALUES (:u, :i, :t, :d)'
            );
            $stmt->execute([':u' => $userId, ':i' => $icon, ':t' => $title, ':d' => $detail]);
        } catch (Throwable $e) {
            error_log('record_activity: ' . $e->getMessage());
        }
    }
}
