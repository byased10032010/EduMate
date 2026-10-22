<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME_CODESPHERE', 'codesphere');
define('DB_NAME_USER_REPOS', 'user_repos');

// Database connection function
function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Get user data
$conn = getDBConnection(DB_NAME_CODESPHERE);
$stmt = $conn->prepare("SELECT username, email, first_name, last_name FROM users WHERE userId = ?");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get user repositories from user_repos database
$reposConn = getDBConnection(DB_NAME_USER_REPOS);
$tableName = $user['username'] . '_' . $_SESSION['user_id'];
$userRepos = [];

// Check if the user's repository table exists
$tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
if ($tableCheck->num_rows > 0) {
    $repoStmt = $reposConn->prepare("SELECT * FROM `$tableName` ORDER BY access_start DESC");
    $repoStmt->execute();
    $userRepos = $repoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get detailed statistics for each repository
$reposWithStats = [];
$totalCommits = 0;
$totalFiles = 0;
$totalCollaborators = 0;

foreach ($userRepos as $repo) {
    $repoDb = $repo['repo_id'];
    $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
    
    if (!$repoConn->connect_error) {
        // Get commit count
        $commitStmt = $repoConn->prepare("SELECT COUNT(*) as commit_count FROM commits WHERE author_id = ?");
        $commitStmt->bind_param("s", $_SESSION['user_id']);
        $commitStmt->execute();
        $commitResult = $commitStmt->get_result()->fetch_assoc();
        $commitCount = $commitResult['commit_count'] ?? 0;
        $totalCommits += $commitCount;
        
        // Get file count
        $fileStmt = $repoConn->prepare("SELECT COUNT(*) as file_count FROM files");
        $fileStmt->execute();
        $fileResult = $fileStmt->get_result()->fetch_assoc();
        $fileCount = $fileResult['file_count'] ?? 0;
        $totalFiles += $fileCount;
        
        // Get last commit date
        $lastCommitStmt = $repoConn->prepare("SELECT timestamp FROM commits WHERE author_id = ? ORDER BY timestamp DESC LIMIT 1");
        $lastCommitStmt->bind_param("s", $_SESSION['user_id']);
        $lastCommitStmt->execute();
        $lastCommitResult = $lastCommitStmt->get_result()->fetch_assoc();
        $lastCommit = $lastCommitResult['timestamp'] ?? null;
        
        // Get collaborators count
        $collabStmt = $repoConn->prepare("SELECT COUNT(DISTINCT author_id) as collab_count FROM commits");
        $collabStmt->execute();
        $collabResult = $collabStmt->get_result()->fetch_assoc();
        $collabCount = $collabResult['collab_count'] ?? 1;
        $totalCollaborators += $collabCount;
        
        $repoConn->close();
        
        $reposWithStats[] = array_merge($repo, [
            'commit_count' => $commitCount,
            'file_count' => $fileCount,
            'last_commit' => $lastCommit,
            'collaborators_count' => $collabCount,
            'activity_level' => $commitCount > 50 ? 'high' : ($commitCount > 10 ? 'medium' : 'low')
        ]);
    } else {
        $reposWithStats[] = array_merge($repo, [
            'commit_count' => 0,
            'file_count' => 0,
            'last_commit' => null,
            'collaborators_count' => 1,
            'activity_level' => 'none'
        ]);
    }
}

// Get notifications
$notifications = [];
$unreadNotificationCount = 0;

$notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
if (!$notifConn->connect_error) {
    $notifTableName = $_SESSION['user_id'];
    $notifTableCheck = $notifConn->query("SHOW TABLES LIKE '$notifTableName'");
    
    if ($notifTableCheck->num_rows > 0) {
        $notifStmt = $notifConn->prepare("SELECT * FROM `$notifTableName` ORDER BY created_at DESC LIMIT 10");
        $notifStmt->execute();
        $notifications = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $unreadStmt = $notifConn->prepare("SELECT COUNT(*) as count FROM `$notifTableName` WHERE is_read = 0");
        $unreadStmt->execute();
        $unreadResult = $unreadStmt->get_result()->fetch_assoc();
        $unreadNotificationCount = $unreadResult['count'];
    }
}

// Handle search and filter
$searchQuery = $_GET['search'] ?? '';
$filterRole = $_GET['role'] ?? 'all';
$filterAccess = $_GET['access'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'recent';

// Filter repositories
$filteredRepos = $reposWithStats;

if ($searchQuery) {
    $filteredRepos = array_filter($filteredRepos, function($repo) use ($searchQuery) {
        return stripos($repo['repo_id'], $searchQuery) !== false;
    });
}

if ($filterRole !== 'all') {
    $filteredRepos = array_filter($filteredRepos, function($repo) use ($filterRole) {
        return $repo['role'] === $filterRole;
    });
}

if ($filterAccess !== 'all') {
    $filteredRepos = array_filter($filteredRepos, function($repo) use ($filterAccess) {
        return ($repo['repo_access'] ?? 'public') === $filterAccess;
    });
}

// Sort repositories
switch ($sortBy) {
    case 'name':
        usort($filteredRepos, function($a, $b) {
            return strcmp($a['repo_id'], $b['repo_id']);
        });
        break;
    case 'commits':
        usort($filteredRepos, function($a, $b) {
            return $b['commit_count'] - $a['commit_count'];
        });
        break;
    case 'activity':
        usort($filteredRepos, function($a, $b) {
            return strtotime($b['last_commit'] ?? 0) - strtotime($a['last_commit'] ?? 0);
        });
        break;
    case 'recent':
    default:
        usort($filteredRepos, function($a, $b) {
            return strtotime($b['access_start']) - strtotime($a['access_start']);
        });
        break;
}

// Calculate statistics
$stats = [
    'total_repos' => count($userRepos),
    'owner_repos' => count(array_filter($userRepos, function($repo) { return $repo['role'] === 'owner'; })),
    'collaborator_repos' => count(array_filter($userRepos, function($repo) { return $repo['role'] === 'collaborator'; })),
    'public_repos' => count(array_filter($userRepos, function($repo) { return ($repo['repo_access'] ?? 'public') === 'public'; })),
    'private_repos' => count(array_filter($userRepos, function($repo) { return ($repo['repo_access'] ?? 'public') === 'private'; })),
    'total_commits' => $totalCommits,
    'total_files' => $totalFiles,
    'total_collaborators' => $totalCollaborators
];

// Handle notification actions
if (isset($_POST['mark_as_read'])) {
    $notificationId = $_POST['notification_id'];
    if (isset($notifConn) && !$notifConn->connect_error) {
        $updateStmt = $notifConn->prepare("UPDATE `$notifTableName` SET is_read = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $notificationId);
        $updateStmt->execute();
        header('Location: all_repos.php');
        exit();
    }
}

// Close connections
$conn->close();
$reposConn->close();
if (isset($notifConn)) $notifConn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme']; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Repositories - CodeSphere</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        html, body {
    overflow: auto;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE and Edge */
}

/* Webkit browsers (Chrome, Safari, Opera) */
html::-webkit-scrollbar,
body::-webkit-scrollbar,
.main-content::-webkit-scrollbar,
.profile::-webkit-scrollbar,
.sidebar-nav::-webkit-scrollbar,
.modal-body::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}

/* Ensure proper sizing */
body {
    margin: 0;
    padding: 0;
}

.main-content {
    height: 100vh;
    overflow: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.main-content::-webkit-scrollbar {
    display: none;
}
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #c7d2fe;
            --secondary: #8b5cf6;
            --accent: #f59e0b;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --dark: #0f172a;
            --darker: #020617;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --sidebar-bg: #ffffff;
            --sidebar-hover: #f1f5f9;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            --glass-bg: rgba(255, 255, 255, 0.8);
            --glass-border: rgba(255, 255, 255, 0.2);
        }

        .dark-mode {
            --primary: #818cf8;
            --primary-dark: #6366f1;
            --primary-light: #3730a3;
            --secondary: #a78bfa;
            --accent: #fbbf24;
            --dark: #f1f5f9;
            --darker: #0f172a;
            --light: #0f172a;
            --gray: #94a3b8;
            --light-gray: #1e293b;
            --sidebar-bg: #1e293b;
            --sidebar-hover: #334155;
            --card-bg: #1e293b;
            --border: #334155;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.3);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.4);
            --gradient-light: linear-gradient(135deg, rgba(129, 140, 248, 0.1) 0%, rgba(167, 139, 250, 0.1) 100%);
            --glass-bg: rgba(30, 41, 59, 0.8);
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            transition: var(--transition);
            overflow-x: hidden;
        }

        /* Enhanced Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .sidebar.collapsed {
            width: 72px;
        }

        .sidebar-header {
            padding: 1.5rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-shrink: 0;
            background: var(--gradient);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .sidebar.collapsed .sidebar-header {
            justify-content: center;
            padding: 1.5rem 0.75rem;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
            position: relative;
            z-index: 1;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            backdrop-filter: blur(10px);
        }

        .logo-text {
            margin-left: 0.75rem;
            font-weight: 700;
        }

        .sidebar.collapsed .logo-text {
            display: none;
        }

        .sidebar-toggle {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
            margin-left: auto;
            position: relative;
            z-index: 1;
        }

        .sidebar-toggle:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(180deg);
        }

        .sidebar.collapsed .sidebar-toggle {
            margin-left: 0;
            transform: rotate(180deg);
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .sidebar-nav::-webkit-scrollbar {
            display: none;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-title {
            padding: 0.5rem 1.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: var(--transition);
        }

        .sidebar.collapsed .nav-title {
            display: none;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            color: var(--dark);
            transition: var(--transition);
            border-left: 3px solid transparent;
            margin: 0.125rem 0;
            position: relative;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: -100%;
            top: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            transition: var(--transition);
            opacity: 0.1;
        }

        .nav-item:hover {
            background: var(--sidebar-hover);
            color: var(--primary);
            transform: translateX(4px);
        }

        .nav-item:hover::before {
            left: 0;
        }

        .nav-item.active {
            background: var(--sidebar-hover);
            color: var(--primary);
            border-left-color: var(--primary);
            font-weight: 500;
        }

        .nav-item.active::before {
            left: 0;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }

        .nav-item:hover .nav-icon {
            transform: scale(1.1);
        }

        .nav-text {
            margin-left: 0.75rem;
            font-size: 0.9rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .sidebar.collapsed .nav-text {
            display: none;
        }

        .sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 0.75rem;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
            position: relative;
            z-index: 1;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .sidebar.collapsed .nav-badge {
            display: none;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: var(--transition);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content.expanded {
            margin-left: 72px;
        }

        /* Enhanced Header with Animated Icons */
        header {
            background: var(--glass-bg);
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: var(--shadow);
        }

        .top-nav {
            display: flex;
            align-items: center;
            padding: 1rem 2rem;
            gap: 1.5rem;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .mobile-menu-btn:hover {
            background: var(--sidebar-hover);
            transform: scale(1.1);
        }

        .search-bar {
            flex: 1;
            max-width: 500px;
        }

        .search-container {
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-size: 0.9rem;
            transition: var(--transition);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1), 0 4px 20px rgba(99, 102, 241, 0.15);
            transform: translateY(-2px);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            transition: var(--transition);
        }

        .search-input:focus + .search-icon {
            color: var(--primary);
            transform: translateY(-50%) scale(1.1);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-btn {
            background: none;
            border: none;
            width: 44px;
            height: 44px;
            border-radius: var(--radius);
            color: var(--dark);
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
        }

        .nav-btn:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--error);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            animation: pulse 1.5s infinite;
            border: 2px solid var(--glass-bg);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
        }

        .user-menu:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .user-avatar::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        .user-menu:hover .user-avatar {
            transform: scale(1.1) rotate(5deg);
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Animated Header Icons */
        .header-icon {
            position: relative;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            overflow: hidden;
        }

        .header-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: var(--transition);
        }

        .header-icon:hover::before {
            left: 100%;
        }

        .header-icon:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: var(--primary);
        }

        .icon-animation {
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-5px);
            }
            60% {
                transform: translateY(-3px);
            }
        }

        /* Enhanced Welcome Section */
        .welcome-section {
            margin-bottom: 2.5rem;
            background: var(--gradient);
            color: white;
            padding: 3rem;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.8s ease-out;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 15s infinite ease-in-out;
        }

        .welcome-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            animation: float 12s infinite ease-in-out reverse;
        }

        .welcome-content {
            position: relative;
            z-index: 1;
        }

        .welcome-section h1 {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            font-weight: 800;
            position: relative;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .welcome-icon {
            font-size: 2.5rem;
            animation: bounce 2s infinite, rotate 10s infinite linear;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .welcome-section p {
            opacity: 0.9;
            font-size: 1.2rem;
            position: relative;
            max-width: 600px;
            line-height: 1.6;
        }

        /* Completely Redesigned Filter Section */
        .filter-section {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            margin-bottom: 2.5rem;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            animation: fadeInUp 0.6s ease-out 0.2s both;
            position: relative;
            overflow: hidden;
        }

        .filter-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient);
        }

        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .filter-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .filter-title i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-label i {
            color: var(--primary);
            font-size: 0.8rem;
        }

        .filter-select {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-size: 0.9rem;
            transition: var(--transition);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1rem;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        .search-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-actions .search-container {
            flex: 1;
            min-width: 300px;
        }

        .btn {
            padding: 0.875rem 1.75rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: var(--transition);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--dark);
        }

        .btn-outline:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        /* Enhanced Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.75rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow);
            animation: fadeInUp 0.6s ease-out;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: var(--gradient);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            width: 10px;
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.4rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-icon::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.15) rotate(5deg);
        }

        .stat-icon.repository { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .stat-icon.owner { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-icon.collaborator { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-icon.commit { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
        .stat-icon.file { background: linear-gradient(135deg, #ec4899, #db2777); }
        .stat-icon.collab { background: linear-gradient(135deg, #06b6d4, #0ea5e9); }

        .stat-trend {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            transition: var(--transition);
        }

        .stat-trend.positive {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .stat-card h3 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 800;
            transition: var(--transition);
        }

        .stat-card:hover h3 {
            color: var(--primary);
        }

        .stat-card p {
            color: var(--gray);
            font-size: 1rem;
            transition: var(--transition);
        }

        /* Completely Redesigned Repositories Section */
        .repos-section {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            margin-bottom: 2.5rem;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: fadeInUp 0.8s ease-out 0.4s both;
        }

        .section-header {
            padding: 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
        }

        .section-header h2 {
            font-size: 1.5rem;
            color: var(--dark);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-header h2 i {
            color: var(--primary);
        }

        .view-controls {
            display: flex;
            gap: 0.5rem;
            background: var(--light);
            padding: 0.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .view-btn {
            background: none;
            border: none;
            color: var(--gray);
            padding: 0.75rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .view-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 10px rgba(99, 102, 241, 0.3);
        }

        .view-btn:hover:not(.active) {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
        }

        .repos-grid {
            padding: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 2rem;
        }

        /* Enhanced Repository Cards */
        .repo-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 2rem;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.6s ease-out;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }

        .repo-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--gradient-light);
            transition: var(--transition);
        }

        .repo-card:hover {
            border-color: var(--primary);
            box-shadow: 0 12px 40px rgba(99, 102, 241, 0.15);
            transform: translateY(-8px) scale(1.02);
        }

        .repo-card:hover::before {
            left: 0;
        }

        .repo-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .repo-name {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-weight: 700;
            color: var(--primary);
            font-size: 1.3rem;
            transition: var(--transition);
        }

        .repo-card:hover .repo-name {
            color: var(--primary-dark);
        }

        .repo-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .repo-icon::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        .repo-card:hover .repo-icon {
            transform: scale(1.15) rotate(5deg);
        }

        .repo-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .repo-badge {
            padding: 0.4rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .repo-badge.public {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border-color: rgba(16, 185, 129, 0.3);
        }

        .repo-badge.private {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border-color: rgba(245, 158, 11, 0.3);
        }

        .repo-badge.owner {
            background: rgba(99, 102, 241, 0.15);
            color: var(--primary);
            border-color: rgba(99, 102, 241, 0.3);
        }

        .repo-badge.collaborator {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border-color: rgba(245, 158, 11, 0.3);
        }

        .repo-badge.activity-high {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border-color: rgba(16, 185, 129, 0.3);
        }

        .repo-badge.activity-medium {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border-color: rgba(245, 158, 11, 0.3);
        }

        .repo-badge.activity-low {
            background: rgba(100, 116, 139, 0.15);
            color: var(--gray);
            border-color: rgba(100, 116, 139, 0.3);
        }

        .repo-description {
            color: var(--gray);
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        .repo-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .repo-stat {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            text-align: center;
            padding: 1rem;
            background: var(--gradient-light);
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .repo-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--dark);
            transition: var(--transition);
        }

        .repo-card:hover .stat-value {
            color: var(--primary);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 500;
        }

        .repo-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 1;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .last-commit {
            font-size: 0.85rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .repo-actions {
            display: flex;
            gap: 0.75rem;
        }

        .action-btn {
            background: none;
            border: 2px solid var(--border);
            color: var(--gray);
            padding: 0.75rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-3px) scale(1.1);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
            animation: bounce 2s infinite;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: var(--dark);
            font-weight: 600;
        }

        /* Enhanced Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 3rem;
        }

        .pagination-btn {
            background: var(--card-bg);
            border: 2px solid var(--border);
            color: var(--dark);
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
        }

        .pagination-btn:hover:not(:disabled) {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Completely Redesigned Notification Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            backdrop-filter: blur(8px);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .notification-modal {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 520px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            transform: translateY(30px) scale(0.9);
            transition: var(--transition);
            border: 1px solid var(--border);
        }

        .modal-overlay.active .notification-modal {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            padding: 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--gradient);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .modal-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.3rem;
            padding: 0.75rem;
            border-radius: var(--radius);
            transition: var(--transition);
            position: relative;
            z-index: 1;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(90deg) scale(1.1);
        }

        .modal-body {
            max-height: 60vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary) var(--border);
        }

        .modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: var(--border);
            border-radius: 3px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 3px;
        }

        .notification-item {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 1.25rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .notification-item::before {
            content: '';
            position: absolute;
            left: -100%;
            top: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-light);
            transition: var(--transition);
        }

        .notification-item:hover {
            background: var(--sidebar-hover);
            transform: translateX(5px);
        }

        .notification-item:hover::before {
            left: 0;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background: rgba(99, 102, 241, 0.05);
            border-left: 4px solid var(--primary);
        }

        .notification-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            transition: var(--transition);
            font-size: 1.2rem;
        }

        .notification-item:hover .notification-icon {
            transform: scale(1.1);
            background: var(--primary);
            color: white;
        }

        .notification-content {
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .notification-message {
            margin-bottom: 0.5rem;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .notification-meta {
            font-size: 0.8rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notification-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        .notification-action {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 0.85rem;
            cursor: pointer;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            transition: var(--transition);
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid var(--border);
        }

        .notification-action:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
        }

        .btn-sm {
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
        }

        /* New Animations and Effects */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0.1;
            animation: float-particle 20s infinite linear;
        }

        @keyframes float-particle {
            0% {
                transform: translateY(100vh) rotate(0deg);
            }
            100% {
                transform: translateY(-100px) rotate(360deg);
            }
        }

        .glow-effect {
            position: relative;
        }

        .glow-effect::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: inherit;
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.3);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .glow-effect:hover::after {
            opacity: 1;
        }

        .typewriter {
            overflow: hidden;
            border-right: 2px solid white;
            white-space: nowrap;
            margin: 0 auto;
            animation: typing 3.5s steps(40, end), blink-caret 0.75s step-end infinite;
        }

        @keyframes typing {
            from { width: 0 }
            to { width: 100% }
        }

        @keyframes blink-caret {
            from, to { border-color: transparent }
            50% { border-color: white }
        }

        .shimmer {
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            background-size: 200% 100%;
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .ripple {
            position: relative;
            overflow: hidden;
        }

        .ripple::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .ripple:active::after {
            width: 300px;
            height: 300px;
        }

        .morph {
            animation: morph 8s ease-in-out infinite;
            border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
        }

        @keyframes morph {
            0% {
                border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
            }
            50% {
                border-radius: 30% 60% 70% 40% / 50% 60% 30% 60%;
            }
            100% {
                border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 72px;
            }

            .sidebar .nav-text,
            .sidebar .logo-text,
            .sidebar .nav-title,
            .sidebar .nav-badge {
                display: none;
            }

            .main-content {
                margin-left: 72px;
            }

            .repos-grid {
                grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .top-nav {
                padding: 1rem;
            }

            .search-bar {
                display: none;
            }

            .user-info {
                display: none;
            }

            .mobile-menu-btn {
                display: block;
            }

            .stats-overview {
                grid-template-columns: 1fr;
            }

            .repos-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .search-actions {
                flex-direction: column;
            }

            .search-actions .search-container {
                min-width: 100%;
            }

            .dashboard {
                padding: 1rem;
            }

            .welcome-section h1 {
                font-size: 2rem;
            }

            .welcome-section p {
                font-size: 1.1rem;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            33% {
                transform: translateY(-20px) rotate(120deg);
            }
            66% {
                transform: translateY(20px) rotate(240deg);
            }
        }

        /* Floating elements */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }

        .shape {
            position: absolute;
            opacity: 0.03;
            border-radius: 50%;
            animation: float 25s infinite linear;
        }

        .shape-1 {
            width: 150px;
            height: 150px;
            background: var(--primary);
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 100px;
            height: 100px;
            background: var(--secondary);
            top: 70%;
            left: 80%;
            animation-delay: -8s;
        }

        .shape-3 {
            width: 200px;
            height: 200px;
            background: var(--accent);
            top: 40%;
            left: 70%;
            animation-delay: -16s;
        }

        .shape-4 {
            width: 80px;
            height: 80px;
            background: var(--success);
            top: 20%;
            left: 85%;
            animation-delay: -12s;
        }

        .shape-5 {
            width: 120px;
            height: 120px;
            background: var(--error);
            top: 80%;
            left: 10%;
            animation-delay: -4s;
        }
    </style>
</head>
<body>
    <!-- Floating Background Elements -->
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>

    <!-- Animated Particles -->
    <div class="particles" id="particles"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="#" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-code"></i>
                </div>
                <span class="logo-text">CodeSphere</span>
            </a>
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-chevron-left"></i>
            </button>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Navigation</div>
                <a href="dashboard.php" class="nav-item">
                    <i class="fas fa-home nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="all_repos.php" class="nav-item active">
                    <i class="fas fa-code-branch nav-icon"></i>
                    <span class="nav-text">All Repositories</span>
                    <span class="nav-badge"><?php echo $stats['total_repos']; ?></span>
                </a>
                <a href="repo.php" class="nav-item">
                    <i class="fas fa-plus-circle nav-icon"></i>
                    <span class="nav-text">New Repository</span>
                </a>
                <a href="code_editor.php" class="nav-item">
                    <i class="fas fa-code nav-icon"></i>
                    <span class="nav-text">Code Editor</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Collaborate</div>
                <a href="collaborations.php" class="nav-item">
                    <i class="fas fa-users nav-icon"></i>
                    <span class="nav-text">Collaborations</span>
                    <span class="nav-badge"><?php echo $stats['collaborator_repos']; ?></span>
                </a>
                <a href="notifications.php" class="nav-item">
                    <i class="fas fa-bell nav-icon"></i>
                    <span class="nav-text">Notifications</span>
                    <span class="nav-badge" id="notificationCount"><?php echo $unreadNotificationCount; ?></span>
                </a>
                <!-- Updated: Share Repository now points to repo_view with tab parameter -->
                <a href="repo_view.php?tab=share" class="nav-item">
                    <i class="fas fa-share-alt nav-icon"></i>
                    <span class="nav-text">Share Repository</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Insights</div>
                <a href="analytics.php" class="nav-item">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span class="nav-text">Analytics</span>
                </a>
                <a href="contributions.php" class="nav-item">
                    <i class="fas fa-fire-flame-curved nav-icon"></i>
                    <span class="nav-text">Contributions</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Account</div>
                <a href="profile.php" class="nav-item">
                    <i class="fas fa-user-circle nav-icon"></i>
                    <span class="nav-text">Profile</span>
                </a>
                <!-- Updated: Settings now points to repo_view with tab parameter -->
                <a href="repo_view.php?tab=settings" class="nav-item">
                    <i class="fas fa-cog nav-icon"></i>
                    <span class="nav-text">Settings</span>
                </a>
                <a href="#" class="nav-item" onclick="showLogoutModal()"><i class="fas fa-sign-out-alt nav-icon"></i><span class="nav-text">Logout</span></a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Enhanced Header with Animated Icons -->
        <header>
            <div class="top-nav">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="search-bar">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" placeholder="Search repositories, projects, or teams...">
                    </div>
                </div>

                <div class="nav-right">
                    <!-- Animated Header Icons -->
                    <div class="header-icon" title="Activity Feed">
                        <i class="fas fa-stream icon-animation"></i>
                    </div>
                    <div class="header-icon" title="Code Templates">
                        <i class="fas fa-code-branch icon-animation" style="animation-delay: 0.2s;"></i>
                    </div>
                    <div class="header-icon" title="Collaborate">
                        <i class="fas fa-users icon-animation" style="animation-delay: 0.4s;"></i>
                    </div>
                    
                    <button class="nav-btn" title="Notifications" id="notificationBtn">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <span class="notification-badge" id="headerNotificationCount"><?php echo $unreadNotificationCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="nav-btn theme-toggle" id="themeToggle" title="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="user-menu" onclick="window.location.href='profile.php'">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div class="user-role">Developer</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <section class="dashboard">
            <div class="welcome-section">
                <div class="welcome-content">
                    <h1>
                        <i class="fas fa-code-branch welcome-icon"></i>
                        All Repositories
                    </h1>
                    <p>Manage and explore all your repositories in one place. <?php echo count($filteredRepos); ?> repositories found.</p>
                </div>
            </div>

            <!-- Completely Redesigned Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-header">
                        <div class="filter-title">
                            <i class="fas fa-filter"></i>
                            Filter & Sort Repositories
                        </div>
                    </div>
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-search"></i>
                                Search Repositories
                            </label>
                            <div class="search-container">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" name="search" class="search-input" placeholder="Type to search repositories..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-user-tag"></i>
                                Your Role
                            </label>
                            <select name="role" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="all" <?php echo $filterRole === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                <option value="owner" <?php echo $filterRole === 'owner' ? 'selected' : ''; ?>>Owner</option>
                                <option value="collaborator" <?php echo $filterRole === 'collaborator' ? 'selected' : ''; ?>>Collaborator</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">
                                <ion-icon name="lock-closed-outline"></ion-icon>
                                Access Type
                            </label>
                            <select name="access" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="all" <?php echo $filterAccess === 'all' ? 'selected' : ''; ?>>All Access</option>
                                <option value="public" <?php echo $filterAccess === 'public' ? 'selected' : ''; ?>>Public</option>
                                <option value="private" <?php echo $filterAccess === 'private' ? 'selected' : ''; ?>>Private</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">
                                <i class="fas fa-sort"></i>
                                Sort By
                            </label>
                            <select name="sort" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                                <option value="recent" <?php echo $sortBy === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                                <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                                <option value="commits" <?php echo $sortBy === 'commits' ? 'selected' : ''; ?>>Most Commits</option>
                                <option value="activity" <?php echo $sortBy === 'activity' ? 'selected' : ''; ?>>Recent Activity</option>
                            </select>
                        </div>
                    </div>
                    <div class="search-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                        <a href="all_repos.php" class="btn btn-outline">
                            <ion-icon name="close-outline"></ion-icon>
                            Clear Filters
                        </a>
                        <a href="repo.php" class="btn btn-primary" style="margin-left: auto;">
                            <i class="fas fa-plus-circle"></i>
                            New Repository
                        </a>
                    </div>
                </form>
            </div>

            <!-- Enhanced Statistics Overview -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon repository">
                            <i class="fas fa-code-branch"></i>
                        </div>
                        <span class="stat-trend positive">+<?php echo $stats['total_repos'] > 0 ? round($stats['total_repos'] * 0.1) : 0; ?></span>
                    </div>
                    <h3><?php echo $stats['total_repos']; ?></h3>
                    <p>Total Repositories</p>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon owner">
                            <i class="fas fa-crown"></i>
                        </div>
                        <span class="stat-trend positive">+<?php echo $stats['owner_repos'] > 0 ? round($stats['owner_repos'] * 0.1) : 0; ?></span>
                    </div>
                    <h3><?php echo $stats['owner_repos']; ?></h3>
                    <p>Owned Repositories</p>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon commit">
                            <i class="fas fa-code-commit"></i>
                        </div>
                        <span class="stat-trend positive">+<?php echo $stats['total_commits'] > 0 ? round($stats['total_commits'] * 0.05) : 0; ?></span>
                    </div>
                    <h3><?php echo $stats['total_commits']; ?></h3>
                    <p>Total Commits</p>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon file">
                            <i class="fas fa-file-code"></i>
                        </div>
                        <span class="stat-trend positive">+<?php echo $stats['total_files'] > 0 ? round($stats['total_files'] * 0.05) : 0; ?></span>
                    </div>
                    <h3><?php echo $stats['total_files']; ?></h3>
                    <p>Total Files</p>
                </div>
            </div>

            <!-- Completely Redesigned Repositories Section -->
            <div class="repos-section">
                <div class="section-header">
                    <h2>
                        <i class="fas fa-code-branch"></i>
                        Your Repositories (<?php echo count($filteredRepos); ?>)
                    </h2>
                    <div class="view-controls">
                        <button class="view-btn active" title="Grid View">
                            <i class="fas fa-th"></i>
                        </button>
                        <button class="view-btn" title="List View">
                            <ion-icon name="list-outline"></ion-icon>
                        </button>
                    </div>
                </div>
                <div class="repos-grid">
                    <?php if (count($filteredRepos) > 0): ?>
                        <?php foreach ($filteredRepos as $repo): ?>
                            <div class="repo-card" onclick="window.location.href='repo_view.php?repo=<?php echo $repo['repo_id']; ?>'">
                                <div class="repo-header">
                                    <div class="repo-name">
                                        <div class="repo-icon">
                                            <ion-icon name="book-outline"></ion-icon>
                                        </div>
                                        <?php echo htmlspecialchars($repo['repo_id']); ?>
                                    </div>
                                    <div class="repo-badges">
                                        <span class="repo-badge <?php echo $repo['repo_access'] ?? 'public'; ?>">
                                            <?php echo ucfirst($repo['repo_access'] ?? 'public'); ?>
                                        </span>
                                        <span class="repo-badge <?php echo $repo['role'] ?? 'owner'; ?>">
                                            <?php echo ucfirst($repo['role'] ?? 'Owner'); ?>
                                        </span>
                                        <span class="repo-badge activity-<?php echo $repo['activity_level']; ?>">
                                            <?php echo ucfirst($repo['activity_level']); ?> Activity
                                        </span>
                                    </div>
                                </div>
                                <p class="repo-description">
                                    Repository <?php echo $repo['role'] === 'collaborator' ? 'shared with you' : 'created'; ?> on 
                                    <?php echo date('M j, Y', strtotime($repo['access_start'] ?? 'now')); ?>
                                </p>
                                <div class="repo-stats">
                                    <div class="repo-stat">
                                        <span class="stat-value"><?php echo $repo['commit_count']; ?></span>
                                        <span class="stat-label">Commits</span>
                                    </div>
                                    <div class="repo-stat">
                                        <span class="stat-value"><?php echo $repo['file_count']; ?></span>
                                        <span class="stat-label">Files</span>
                                    </div>
                                    <div class="repo-stat">
                                        <span class="stat-value"><?php echo $repo['collaborators_count']; ?></span>
                                        <span class="stat-label">Collaborators</span>
                                    </div>
                                    <div class="repo-stat">
                                        <span class="stat-value"><?php echo $repo['activity_level'] === 'high' ? '🔥' : ($repo['activity_level'] === 'medium' ? '⚡' : '🌱'); ?></span>
                                        <span class="stat-label">Activity</span>
                                    </div>
                                </div>
                                <div class="repo-footer">
                                    <div class="last-commit">
                                        <i class="fas fa-history"></i>
                                        <?php if ($repo['last_commit']): ?>
                                            Last commit: <?php echo date('M j, Y', strtotime($repo['last_commit'])); ?>
                                        <?php else: ?>
                                            No commits yet
                                        <?php endif; ?>
                                    </div>
                                    <div class="repo-actions">
                                        <button class="action-btn" title="View Repository" onclick="event.stopPropagation(); window.location.href='repo_view.php?repo=<?php echo $repo['repo_id']; ?>'">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <!-- Updated: Share button now points to repo_view with tab parameter -->
                                        <button class="action-btn" title="Share Repository" onclick="event.stopPropagation(); window.location.href='repo_view.php?repo=<?php echo $repo['repo_id']; ?>&tab=share'">
                                            <i class="fas fa-share-alt"></i>
                                        </button>
                                        <?php if ($repo['role'] === 'owner'): ?>
                                            <!-- Updated: Settings button now points to repo_view with tab parameter -->
                                            <button class="action-btn" title="Settings" onclick="event.stopPropagation(); window.location.href='repo_view.php?repo=<?php echo $repo['repo_id']; ?>&tab=settings'">
                                                <i class="fas fa-cog"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-code-branch"></i>
                            <h3>No repositories found</h3>
                            <p><?php echo $searchQuery || $filterRole !== 'all' || $filterAccess !== 'all' ? 'Try adjusting your filters' : 'Get started by creating your first repository'; ?></p>
                            <a href="repo.php" class="btn btn-primary" style="margin-top: 1.5rem;">
                                <i class="fas fa-plus-circle"></i>
                                Create Repository
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                /* Updated Repo Header Styles */
.repo-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 1;
}

.repo-name {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-weight: 700;
    color: var(--primary);
    font-size: 1.3rem;
    transition: var(--transition);
    width: 100%;
}

.repo-badges {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    width: 100%;
    align-items: center;
}

.repo-card:hover .repo-name {
    color: var(--primary-dark);
}
            </style>

            <!-- Enhanced Pagination -->
            <?php if (count($filteredRepos) > 0): ?>
                <div class="pagination">
                    <button class="pagination-btn" disabled>
                        <i class="fas fa-chevron-left"></i>
                        Previous
                    </button>
                    <span class="pagination-info">
                        Showing <?php echo count($filteredRepos); ?> of <?php echo count($filteredRepos); ?> repositories
                    </span>
                    <button class="pagination-btn" disabled>
                        Next
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Completely Redesigned Notification Modal -->
    <div class="modal-overlay" id="notificationModal">
        <div class="notification-modal">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-bell"></i>
                    Notifications
                </h3>
                <button class="modal-close" id="modalClose">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <div class="modal-body">
                <?php if (count($notifications) > 0): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                            <div class="notification-icon">
                                <?php 
                                $icon = 'fas fa-bell';
                                if (strpos($notification['message'], 'commit') !== false) {
                                    $icon = 'fas fa-code-commit';
                                } elseif (strpos($notification['message'], 'collaborator') !== false) {
                                    $icon = 'fas fa-user-plus';
                                } elseif (strpos($notification['message'], 'merge') !== false) {
                                    $icon = 'fas fa-code-branch';
                                }
                                ?>
                                <i class="<?php echo $icon; ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-message">
                                    <?php echo htmlspecialchars($notification['message']); ?>
                                </div>
                                <div class="notification-meta">
                                    <ion-icon name="time-outline"></ion-icon>
                                    <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                </div>
                                <?php if ($notification['link']): ?>
                                    <div class="notification-actions">
                                        <a href="<?php echo $notification['link']; ?>" class="notification-action">
                                            <ion-icon name="open-outline"></ion-icon>
                                            View Details
                                        </a>
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                <button type="submit" name="mark_as_read" class="notification-action">
                                                    <ion-icon name="checkmark-outline"></ion-icon>
                                                    Mark as Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 3rem;">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No notifications</h3>
                        <p>You're all caught up! Check back later for updates.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (count($notifications) > 0): ?>
                <div class="modal-footer">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_all_read" class="btn btn-outline btn-sm">
                            <i class="fas fa-check-double"></i>
                            Mark All as Read
                        </button>
                    </form>
                    <a href="notifications.php" class="btn btn-primary btn-sm">
                        <ion-icon name="list-outline"></ion-icon>
                        View All Notifications
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('mobile-open');
        });

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && 
                !sidebar.contains(e.target) && 
                !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });
        function confirmLogout() {
    Swal.fire({
        title: 'Are you sure?',
        text: "You'll be logged out of CodeSphere!",
        icon: 'warning',
        iconColor: '#6366f1',
        showCancelButton: true,
        confirmButtonColor: '#6366f1',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, logout!',
        cancelButtonText: 'Cancel',
        background: 'var(--card-bg)',
        color: 'var(--dark)',
        backdrop: 'rgba(0,0,0,0.4)',
        customClass: {
            popup: 'logout-swal'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'logout.php';
        }
    });
}
        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        if (localStorage.getItem('theme') === 'dark' || 
            (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
                localStorage.setItem('theme', 'light');
            }
        });

        // Notification Modal
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationModal = document.getElementById('notificationModal');
        const modalClose = document.getElementById('modalClose');

        notificationBtn.addEventListener('click', () => {
            notificationModal.classList.add('active');
        });

        modalClose.addEventListener('click', () => {
            notificationModal.classList.remove('active');
        });

        // Close modal when clicking outside
        notificationModal.addEventListener('click', (e) => {
            if (e.target === notificationModal) {
                notificationModal.classList.remove('active');
            }
        });

        // View Controls
        const viewBtns = document.querySelectorAll('.view-btn');
        const reposGrid = document.querySelector('.repos-grid');

        viewBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                viewBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                if (btn.querySelector('.fa-list')) {
                    reposGrid.style.gridTemplateColumns = '1fr';
                    document.querySelectorAll('.repo-card').forEach(card => {
                        card.style.maxWidth = 'none';
                    });
                } else {
                    reposGrid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(380px, 1fr))';
                    document.querySelectorAll('.repo-card').forEach(card => {
                        card.style.maxWidth = 'none';
                    });
                }
            });
        });

        // Add staggered animations
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.stat-card, .repo-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Auto-submit filter form on changes
        const filterSelects = document.querySelectorAll('.filter-select');
        filterSelects.forEach(select => {
            select.addEventListener('change', () => {
                document.getElementById('filterForm').submit();
            });
        });

        // Enhanced search with debounce
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');
        
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        });

        // Session keep-alive
        setInterval(() => {
            fetch('all_repos.php').catch(err => console.log('Session refresh failed'));
        }, 30 * 60 * 1000);

        // Enhanced hover effects for repo cards
        const repoCards = document.querySelectorAll('.repo-card');
        repoCards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Create animated particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 30;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random properties
                const size = Math.random() * 10 + 5;
                const left = Math.random() * 100;
                const animationDuration = Math.random() * 20 + 10;
                const animationDelay = Math.random() * 20;
                const opacity = Math.random() * 0.1 + 0.05;
                
                // Apply styles
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.left = `${left}%`;
                particle.style.animationDuration = `${animationDuration}s`;
                particle.style.animationDelay = `${animationDelay}s`;
                particle.style.opacity = opacity;
                
                // Random color
                const colors = ['#6366f1', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444'];
                const randomColor = colors[Math.floor(Math.random() * colors.length)];
                particle.style.background = randomColor;
                
                particlesContainer.appendChild(particle);
            }
        }

        // Initialize particles
        createParticles();

        // Add ripple effect to buttons
        const buttons = document.querySelectorAll('.btn, .nav-btn, .action-btn, .header-icon');
        buttons.forEach(button => {
            button.classList.add('ripple');
            
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = `${size}px`;
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;
                ripple.classList.add('ripple-effect');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add CSS for ripple effect
        const rippleStyle = document.createElement('style');
        rippleStyle.textContent = `
            .ripple-effect {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.6);
                transform: scale(0);
                animation: ripple-animation 0.6s linear;
            }
            
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
            
            .ripple {
                position: relative;
                overflow: hidden;
            }
        `;
        document.head.appendChild(rippleStyle);

        // Add typing animation to welcome text
        const welcomeText = document.querySelector('.welcome-section h1');
        const originalText = welcomeText.textContent;
        welcomeText.innerHTML = '';
        
        let i = 0;
        function typeWriter() {
            if (i < originalText.length) {
                welcomeText.innerHTML += originalText.charAt(i);
                i++;
                setTimeout(typeWriter, 50);
            } else {
                // Add blinking cursor
                const cursor = document.createElement('span');
                cursor.classList.add('cursor');
                cursor.innerHTML = '|';
                cursor.style.animation = 'blink 1s infinite';
                welcomeText.appendChild(cursor);
                
                // Add CSS for blinking cursor
                const cursorStyle = document.createElement('style');
                cursorStyle.textContent = `
                    @keyframes blink {
                        0%, 100% { opacity: 1; }
                        50% { opacity: 0; }
                    }
                `;
                document.head.appendChild(cursorStyle);
            }
        }
        
        // Start typing animation after a short delay
        setTimeout(typeWriter, 1000);

        // Add parallax effect to floating shapes
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const shapes = document.querySelectorAll('.shape');
            
            shapes.forEach((shape, index) => {
                const rate = scrolled * -0.5 * (index + 1) / 10;
                shape.style.transform = `translateY(${rate}px) rotate(${rate}deg)`;
            });
        });

        // Add loading animation for repository cards
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'fadeInUp 0.6s ease-out forwards';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Observe repository cards
        document.querySelectorAll('.repo-card').forEach(card => {
            observer.observe(card);
        });

        // Enhanced notification interactions
        const notificationItems = document.querySelectorAll('.notification-item');
        notificationItems.forEach(item => {
            item.addEventListener('click', (e) => {
                if (!e.target.closest('.notification-action')) {
                    const link = item.querySelector('a');
                    if (link) {
                        link.click();
                    }
                }
            });
        });

        // Animated counter for statistics
        function animateCounter(element, target, duration = 2000) {
            let start = 0;
            const increment = target / (duration / 16);
            const timer = setInterval(() => {
                start += increment;
                if (start >= target) {
                    element.textContent = target;
                    clearInterval(timer);
                } else {
                    element.textContent = Math.floor(start);
                }
            }, 16);
        }

        // Animate stats counters when they come into view
        const statObservers = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const valueElement = entry.target.querySelector('h3');
                    const target = parseInt(valueElement.textContent);
                    animateCounter(valueElement, target);
                    statObservers.unobserve(entry.target);
                }
            });
        });

        document.querySelectorAll('.stat-card').forEach(card => {
            statObservers.observe(card);
        });
    </script>
    <!-- Logout Confirmation Modal -->
<div class="modal-overlay" id="logoutModal">
    <div class="logout-modal">
        <div class="modal-header" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
            <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
            <button class="modal-close" id="logoutModalClose"><ion-icon name="close-outline"></ion-icon></button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 2rem;">
            <div class="logout-icon">
                <i class="fas fa-door-open"></i>
            </div>
            <h4 style="margin: 1rem 0; color: var(--dark);">Ready to leave?</h4>
            <p style="color: var(--gray); margin-bottom: 1.5rem;">You'll be logged out of your CodeSphere account. Make sure to save any unsaved work.</p>
            <div style="display: flex; gap: 1rem; justify-content: center;">
                <button class="btn btn-secondary" id="cancelLogout">Stay Logged In</button>
                <button class="btn btn-error" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>
</div>

<style>
.logout-modal {
    background: var(--card-bg);
    border-radius: var(--radius-2xl);
    width: 90%;
    max-width: 420px;
    overflow: hidden;
    box-shadow: var(--shadow-2xl);
    transform: translateY(20px) scale(0.95);
    transition: var(--transition);
    backdrop-filter: blur(20px);
}

.modal-overlay.active .logout-modal {
    transform: translateY(0) scale(1);
}

.logout-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 1rem;
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: #ef4444;
    border: 2px solid rgba(239, 68, 68, 0.2);
}

.btn-error {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius);
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.btn-error:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
}
</style>

<script>
// Add this JavaScript
const logoutModal = document.getElementById('logoutModal');
const logoutModalClose = document.getElementById('logoutModalClose');
const cancelLogout = document.getElementById('cancelLogout');
const confirmLogoutBtn = document.getElementById('confirmLogout');

function showLogoutModal() {
    logoutModal.classList.add('active');
}

function hideLogoutModal() {
    logoutModal.classList.remove('active');
}

// Event listeners
logoutModalClose.addEventListener('click', hideLogoutModal);
cancelLogout.addEventListener('click', hideLogoutModal);
confirmLogoutBtn.addEventListener('click', () => {
    window.location.href = 'logout.php';
});

logoutModal.addEventListener('click', (e) => {
    if (e.target === logoutModal) hideLogoutModal();
});

// Update your logout link to use this modal
// Change the logout link in sidebar to:
// <a href="#" class="nav-item" onclick="showLogoutModal()"><i class="fas fa-sign-out-alt nav-icon"></i><span class="nav-text">Logout</span></a>
</script>
</body>
</html>