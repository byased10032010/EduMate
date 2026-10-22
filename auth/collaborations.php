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
define('DB_NAME_MESSAGES', 'messages');
define('DB_NAME_USER_FRIENDS', 'user_friends');
define('DB_NAME_FRIEND_CHATS', 'friend_chats');
define('DB_NAME_COLLABORATION_INVITATIONS', 'collaboration_invitations');

function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Initialize databases if they don't exist
function initializeDatabases() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create collaboration_invitations database and table
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME_COLLABORATION_INVITATIONS);
    $conn->select_db(DB_NAME_COLLABORATION_INVITATIONS);
    
    $conn->query("CREATE TABLE IF NOT EXISTS invitations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        repo_id VARCHAR(100) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        receiver_id VARCHAR(50) NOT NULL,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (sender_id),
        INDEX (receiver_id),
        INDEX (status),
        INDEX (repo_id)
    )");
    
    $conn->close();
}

// Initialize databases
initializeDatabases();

// Theme preference handling
if (!isset($_SESSION['theme'])) {
    // Create database connection for theme check
    $themeConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_CODESPHERE);
    
    if (!$themeConn->connect_error) {
        // Try to get theme from profiles table
        $stmt = $themeConn->prepare("SELECT theme FROM profiles WHERE user_id = ?");
        $stmt->bind_param("s", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $profile = $result->fetch_assoc();
            $_SESSION['theme'] = $profile['theme'] ?? 'light';
        } else {
            $_SESSION['theme'] = 'light'; // Default
        }
        
        $themeConn->close();
    } else {
        // Fallback to cookie or default
        $_SESSION['theme'] = $_COOKIE['user_theme'] ?? 'light';
    }
    
    // Set cookie for future sessions
    setcookie('user_theme', $_SESSION['theme'], time() + (30 * 24 * 60 * 60), "/");
}

$conn = getDBConnection(DB_NAME_CODESPHERE);
$stmt = $conn->prepare("SELECT username, email, first_name, last_name FROM users WHERE userId = ?");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$reposConn = getDBConnection(DB_NAME_USER_REPOS);
$tableName = $user['username'] . '_' . $_SESSION['user_id'];
$userRepos = [];

$tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
if ($tableCheck->num_rows > 0) {
    $repoStmt = $reposConn->prepare("SELECT * FROM `$tableName` ORDER BY access_start DESC");
    $repoStmt->execute();
    $userRepos = $repoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get collaborations (repos where user is a collaborator)
$collaborations = [];
foreach ($userRepos as $repo) {
    if ($repo['role'] === 'collaborator') {
        $collaborations[] = $repo;
    }
}

// Get pending collaboration invitations
$pendingInvitations = [];
$collaborationInvitationsConn = getDBConnection(DB_NAME_COLLABORATION_INVITATIONS);

$invitationTableCheck = $collaborationInvitationsConn->query("SHOW TABLES LIKE 'invitations'");

if ($invitationTableCheck->num_rows > 0) {
    $invitationStmt = $collaborationInvitationsConn->prepare("SELECT * FROM invitations WHERE receiver_id = ? AND status = 'pending'");
    $invitationStmt->bind_param("s", $_SESSION['user_id']);
    $invitationStmt->execute();
    $pendingInvitations = $invitationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Handle collaboration actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accept_invitation'])) {
        $invitationId = $_POST['invitation_id'];
        
        // Update invitation status
        $updateStmt = $collaborationInvitationsConn->prepare("UPDATE invitations SET status = 'accepted' WHERE id = ? AND receiver_id = ?");
        $updateStmt->bind_param("is", $invitationId, $_SESSION['user_id']);
        
        if ($updateStmt->execute()) {
            // Add repository to user's repo table
            $invitationStmt = $collaborationInvitationsConn->prepare("SELECT * FROM invitations WHERE id = ?");
            $invitationStmt->bind_param("i", $invitationId);
            $invitationStmt->execute();
            $invitation = $invitationStmt->get_result()->fetch_assoc();
            
            if ($invitation) {
                $insertStmt = $reposConn->prepare("INSERT INTO `$tableName` (repo_id, repo_access, role, access_start) VALUES (?, 'private', 'collaborator', NOW())");
                $insertStmt->bind_param("s", $invitation['repo_id']);
                $insertStmt->execute();
                
                $successMessage = "Invitation accepted! You now have access to the repository.";
                
                // Refresh collaborations list
                $collaborations = [];
                foreach ($userRepos as $repo) {
                    if ($repo['role'] === 'collaborator') {
                        $collaborations[] = $repo;
                    }
                }
                
                // Refresh pending invitations
                $invitationStmt = $collaborationInvitationsConn->prepare("SELECT * FROM invitations WHERE receiver_id = ? AND status = 'pending'");
                $invitationStmt->bind_param("s", $_SESSION['user_id']);
                $invitationStmt->execute();
                $pendingInvitations = $invitationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }
    }
    
    if (isset($_POST['reject_invitation'])) {
        $invitationId = $_POST['invitation_id'];
        
        $updateStmt = $collaborationInvitationsConn->prepare("UPDATE invitations SET status = 'rejected' WHERE id = ? AND receiver_id = ?");
        $updateStmt->bind_param("is", $invitationId, $_SESSION['user_id']);
        
        if ($updateStmt->execute()) {
            $successMessage = "Invitation rejected.";
            
            // Refresh pending invitations
            $invitationStmt = $collaborationInvitationsConn->prepare("SELECT * FROM invitations WHERE receiver_id = ? AND status = 'pending'");
            $invitationStmt->bind_param("s", $_SESSION['user_id']);
            $invitationStmt->execute();
            $pendingInvitations = $invitationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }
    
    if (isset($_POST['leave_collaboration'])) {
        $repoId = $_POST['repo_id'];
        
        // Remove repository from user's repo table
        $deleteStmt = $reposConn->prepare("DELETE FROM `$tableName` WHERE repo_id = ? AND role = 'collaborator'");
        $deleteStmt->bind_param("s", $repoId);
        
        if ($deleteStmt->execute()) {
            $successMessage = "You have left the collaboration.";
            
            // Refresh collaborations list
            $collaborations = [];
            foreach ($userRepos as $repo) {
                if ($repo['role'] === 'collaborator') {
                    $collaborations[] = $repo;
                }
            }
        }
    }
    
    if (isset($_POST['invite_collaborator'])) {
        $repoId = $_POST['repo_id'];
        $collaboratorId = $_POST['collaborator_id'];
        
        // Check if user owns the repository
        $checkStmt = $reposConn->prepare("SELECT * FROM `$tableName` WHERE repo_id = ? AND role = 'owner'");
        $checkStmt->bind_param("s", $repoId);
        $checkStmt->execute();
        $isOwner = $checkStmt->get_result()->num_rows > 0;
        
        if ($isOwner) {
            // Check if invitation already exists
            $invitationCheckStmt = $collaborationInvitationsConn->prepare("SELECT * FROM invitations WHERE repo_id = ? AND receiver_id = ? AND status = 'pending'");
            $invitationCheckStmt->bind_param("ss", $repoId, $collaboratorId);
            $invitationCheckStmt->execute();
            
            if ($invitationCheckStmt->get_result()->num_rows === 0) {
                // Create invitation
                $insertStmt = $collaborationInvitationsConn->prepare("INSERT INTO invitations (repo_id, sender_id, receiver_id, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $insertStmt->bind_param("sss", $repoId, $_SESSION['user_id'], $collaboratorId);
                
                if ($insertStmt->execute()) {
                    $successMessage = "Invitation sent successfully!";
                } else {
                    $errorMessage = "Failed to send invitation.";
                }
            } else {
                $errorMessage = "Invitation already sent to this user.";
            }
        } else {
            $errorMessage = "You can only invite collaborators to repositories you own.";
        }
    }
}

// Get user's owned repositories for inviting collaborators
$ownedRepos = [];
foreach ($userRepos as $repo) {
    if ($repo['role'] === 'owner') {
        $ownedRepos[] = $repo;
    }
}

// Get friends for inviting to collaborate
$friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);
$friendsStmt = $friendsConn->prepare("
    SELECT u.userId, u.username, u.first_name, u.last_name, u.email
    FROM codesphere.users u
    WHERE u.userId IN (
        SELECT 
            CASE 
                WHEN sender_id = ? THEN receiver_id
                ELSE sender_id
            END as friend_id
        FROM friend_requests 
        WHERE (sender_id = ? OR receiver_id = ?) 
        AND status = 'accepted'
    )
    ORDER BY u.first_name, u.last_name
");
$friendsStmt->bind_param("sss", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$friendsStmt->execute();
$friends = $friendsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();
$reposConn->close();
if (isset($collaborationInvitationsConn)) $collaborationInvitationsConn->close();
$friendsConn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collaborations - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1; --primary-dark: #4f46e5; --primary-light: #c7d2fe;
            --secondary: #8b5cf6; --accent: #f59e0b; --success: #10b981; --warning: #f59e0b;
            --error: #ef4444; --info: #06b6d4; --dark: #0f172a; --darker: #020617;
            --light: #f8fafc; --gray: #64748b; --light-gray: #e2e8f0; --sidebar-bg: #ffffff;
            --sidebar-hover: #f1f5f9; --card-bg: #ffffff; --border: #e2e8f0;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); --transition-slow: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1); --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15); --shadow-2xl: 0 25px 50px rgba(0, 0, 0, 0.25);
            --radius: 8px; --radius-lg: 12px; --radius-xl: 16px; --radius-2xl: 20px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-hover: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            --neon-glow: 0 0 20px rgba(99, 102, 241, 0.4), 0 0 40px rgba(99, 102, 241, 0.2), 0 0 60px rgba(99, 102, 241, 0.1);
        }
        .dark-mode {
            --primary: #818cf8; --primary-dark: #6366f1; --primary-light: #3730a3;
            --secondary: #a78bfa; --accent: #fbbf24; --dark: #f1f5f9; --darker: #0f172a;
            --light: #0f172a; --gray: #94a3b8; --light-gray: #1e293b; --sidebar-bg: #1e293b;
            --sidebar-hover: #334155; --card-bg: #1e293b; --border: #334155;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3); --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.3);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.4); --shadow-2xl: 0 25px 50px rgba(0, 0, 0, 0.5);
            --gradient-light: linear-gradient(135deg, rgba(129, 140, 248, 0.1) 0%, rgba(167, 139, 250, 0.1) 100%);
            --neon-glow: 0 0 20px rgba(129, 140, 248, 0.4), 0 0 40px rgba(129, 140, 248, 0.2), 0 0 60px rgba(129, 140, 248, 0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        a { text-decoration: none; color: inherit; }
        body { font-family: 'Inter', sans-serif; background: var(--light); color: var(--dark); line-height: 1.6; min-height: 100vh; display: flex; transition: var(--transition-slow); overflow-x: hidden; }
        
        .sidebar { width: 280px; background: rgba(255, 255, 255, 0.9); border-right: 1px solid var(--border); height: 100vh; position: fixed; left: 0; top: 0; transition: var(--transition-slow); z-index: 1000; display: flex; flex-direction: column; overflow: hidden; box-shadow: var(--shadow-xl); backdrop-filter: blur(20px); }
        .dark-mode .sidebar { background: rgba(30, 41, 59, 0.9); }
        .sidebar.collapsed { width: 80px; }
        .sidebar-header { padding: 1.5rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 0.75rem; flex-shrink: 0; background: var(--gradient); color: white; position: relative; overflow: hidden; }
        .sidebar-header::before { content: ''; position: absolute; top: -50%; right: -10%; width: 120px; height: 120px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 8s infinite ease-in-out; }
        .sidebar.collapsed .sidebar-header { justify-content: center; padding: 1.5rem 0.75rem; }
        .logo { display: flex; align-items: center; font-size: 1.25rem; font-weight: 700; color: white; position: relative; z-index: 1; transition: var(--transition); }
        .logo:hover { transform: scale(1.05); }
        .logo-icon { width: 32px; height: 32px; background: rgba(255, 255, 255, 0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1rem; backdrop-filter: blur(10px); transition: var(--transition); animation: bounceRotate 3s infinite ease-in-out; }
        .logo:hover .logo-icon { transform: rotate(15deg); }
        .logo-text { margin-left: 0.75rem; font-weight: 700; }
        .sidebar.collapsed .logo-text { display: none; }
        .sidebar-toggle { background: none; border: none; color: white; cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); margin-left: auto; position: relative; z-index: 1; }
        .sidebar-toggle:hover { background: rgba(255, 255, 255, 0.1); transform: rotate(180deg); }
        .sidebar.collapsed .sidebar-toggle { margin-left: 0; transform: rotate(180deg); }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 1rem 0; scrollbar-width: none; -ms-overflow-style: none; }
        .sidebar-nav::-webkit-scrollbar { display: none; }
        .nav-section { margin-bottom: 1.5rem; }
        .nav-title { padding: 0.5rem 1.25rem; font-size: 0.75rem; font-weight: 600; color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px; transition: var(--transition); }
        .sidebar.collapsed .nav-title { display: none; }
        .nav-item { display: flex; align-items: center; padding: 0.75rem 1.25rem; color: var(--dark); transition: var(--transition); border-left: 3px solid transparent; margin: 0.125rem 0; position: relative; overflow: hidden; }
        .nav-item::before { content: ''; position: absolute; left: -100%; top: 0; width: 100%; height: 100%; background: var(--gradient); transition: var(--transition); opacity: 0.1; }
        .nav-item:hover { background: var(--sidebar-hover); color: var(--primary); transform: translateX(4px); }
        .nav-item:hover::before { left: 0; }
        .nav-item.active { background: var(--sidebar-hover); color: var(--primary); border-left-color: var(--primary); font-weight: 500; }
        .nav-item.active::before { left: 0; }
        .nav-icon { width: 20px; text-align: center; font-size: 1.1rem; position: relative; z-index: 1; transition: var(--transition); animation: bounceUp 4s infinite ease-in-out; }
        .nav-item:hover .nav-icon { transform: scale(1.1); }
        .nav-text { margin-left: 0.75rem; font-size: 0.9rem; font-weight: 500; position: relative; z-index: 1; }
        .sidebar.collapsed .nav-text { display: none; }
        .sidebar.collapsed .nav-item { justify-content: center; padding: 0.75rem; }
        .nav-badge { margin-left: auto; background: var(--primary); color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600; min-width: 20px; text-align: center; position: relative; z-index: 1; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
        @keyframes bounceUp { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
        @keyframes bounceRotate { 0%, 100% { transform: rotate(0deg) translateY(0); } 25% { transform: rotate(5deg) translateY(-3px); } 50% { transform: rotate(0deg) translateY(-5px); } 75% { transform: rotate(-5deg) translateY(-3px); } }
        .sidebar.collapsed .nav-badge { display: none; }
        
        .main-content { flex: 1; margin-left: 280px; transition: var(--transition-slow); min-height: 100vh; display: flex; flex-direction: column; }
        .main-content.expanded { margin-left: 80px; }
        
        header { background: rgba(255, 255, 255, 0.8); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 999; backdrop-filter: blur(20px); box-shadow: var(--shadow); transition: var(--transition-slow); }
        .dark-mode header { background: rgba(30, 41, 59, 0.8); }
        .top-nav { display: flex; align-items: center; padding: 1rem 2rem; gap: 1.5rem; }
        .mobile-menu-btn { display: none; background: none; border: none; color: var(--dark); font-size: 1.2rem; cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); }
        .mobile-menu-btn:hover { background: var(--sidebar-hover); transform: scale(1.1); }
        .search-bar { flex: 1; max-width: 500px; }
        .search-container { position: relative; }
        .search-input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; transition: var(--transition); backdrop-filter: blur(10px); }
        .search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); transform: translateY(-1px); }
        .search-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--gray); }
        .nav-right { display: flex; align-items: center; gap: 0.75rem; }
        .nav-btn { background: none; border: none; width: 40px; height: 40px; border-radius: var(--radius); color: var(--dark); font-size: 1.1rem; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; position: relative; backdrop-filter: blur(10px); animation: bounceDown 3s infinite ease-in-out; }
        .nav-btn:hover { background: var(--sidebar-hover); transform: translateY(-4px); animation: none; }
        @keyframes bounceDown { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
        .notification-badge, .message-badge, .friend-badge { position: absolute; top: -2px; right: -2px; background: var(--error); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: 600; animation: pulse 1.5s infinite; }
        .message-badge { background: var(--success); }
        .friend-badge { background: var(--info); }
        .user-menu { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; border-radius: var(--radius); cursor: pointer; transition: var(--transition); backdrop-filter: blur(10px); }
        .user-menu:hover { background: var(--sidebar-hover); transform: translateY(-2px); }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--gradient); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.9rem; transition: var(--transition); position: relative; }
        .user-avatar::before { content: ''; position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; background: var(--gradient); border-radius: 50%; z-index: -1; opacity: 0; transition: var(--transition); }
        .user-menu:hover .user-avatar::before { opacity: 1; animation: rotate 2s linear infinite; }
        .user-menu:hover .user-avatar { transform: scale(1.1); }
        .user-info { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; font-size: 0.9rem; }
        .user-role { font-size: 0.8rem; color: var(--gray); }
        
        .collaborations { flex: 1; padding: 2rem; }
        .alert { padding: 1rem 1.5rem; border-radius: var(--radius); margin-bottom: 1rem; animation: slideInUp 0.5s ease-out; }
        .alert-success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #10b981; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #ef4444; }
        
        .welcome-section { margin-bottom: 2.5rem; background: var(--gradient); color: white; padding: 3rem; border-radius: var(--radius-2xl); box-shadow: var(--shadow-2xl), var(--neon-glow); position: relative; overflow: hidden; animation: slideInUp 0.8s ease-out; }
        .welcome-section::before { content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 15s infinite ease-in-out; }
        .welcome-section::after { content: ''; position: absolute; bottom: -30%; left: -10%; width: 200px; height: 200px; background: rgba(255, 255, 255, 0.05); border-radius: 50%; animation: float 12s infinite ease-in-out reverse; }
        .welcome-content { position: relative; z-index: 1; }
        .welcome-section h1 { font-size: 2.5rem; margin-bottom: 0.5rem; font-weight: 800; position: relative; display: flex; align-items: center; gap: 0.75rem; text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2); }
        .welcome-icon { font-size: 2.5rem; animation: bounce 2s infinite, glow 3s infinite alternate; }
        .welcome-section p { opacity: 0.9; font-size: 1.2rem; position: relative; max-width: 600px; text-shadow: 0 1px 5px rgba(0, 0, 0, 0.2); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { background: var(--card-bg); padding: 2rem; border-radius: var(--radius-xl); border: 1px solid var(--border); transition: var(--transition); position: relative; overflow: hidden; box-shadow: var(--shadow-lg); animation: fadeInUp 0.6s ease-out; backdrop-filter: blur(10px); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--gradient); transition: var(--transition); }
        .stat-card:hover { transform: translateY(-8px) scale(1.02); box-shadow: var(--shadow-2xl), var(--neon-glow); }
        .stat-card:hover::before { width: 8px; }
        .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
        .stat-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; transition: var(--transition); box-shadow: var(--shadow-lg); animation: bounceUp 5s infinite ease-in-out; }
        .stat-card:hover .stat-icon { transform: scale(1.1) rotate(5deg); }
        .stat-icon.collaborations { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .stat-icon.invitations { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-icon.owned { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-icon.friends { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .stat-trend { font-size: 0.8rem; font-weight: 600; padding: 0.25rem 0.75rem; border-radius: 12px; transition: var(--transition); }
        .stat-trend.positive { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .stat-card h3 { font-size: 2.5rem; margin-bottom: 0.5rem; color: var(--dark); font-weight: 800; transition: var(--transition); background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .stat-card p { color: var(--gray); font-size: 0.95rem; transition: var(--transition); }
        
        .invite-collaborator { background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); padding: 2rem; margin-bottom: 2rem; box-shadow: var(--shadow-lg); animation: fadeInUp 0.8s ease-out 0.8s both; backdrop-filter: blur(10px); }
        .invite-collaborator h3 { margin-bottom: 1rem; font-weight: 600; }
        .invite-form { display: flex; gap: 1rem; }
        .invite-input { flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; transition: var(--transition); }
        .invite-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        
        /* Hide scrollbars but keep scrolling functionality */
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

        .invitations-section, .collaborations-section { background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); margin-bottom: 2rem; overflow: hidden; box-shadow: var(--shadow-lg); backdrop-filter: blur(10px); }
        .invitations-section { animation: fadeInUp 0.8s ease-out 0.6s both; }
        .collaborations-section { animation: fadeInUp 0.8s ease-out 0.4s both; }
        
        .section-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--gradient-light); }
        .section-header h2 { font-size: 1.5rem; color: var(--dark); font-weight: 700; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; position: relative; overflow: hidden; }
        .btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: var(--transition); }
        .btn:hover::before { left: 100%; }
        .btn-primary { background: var(--gradient); color: white; box-shadow: var(--shadow-lg); }
        .btn-primary:hover { transform: translateY(-2px) scale(1.05); box-shadow: var(--shadow-xl), var(--neon-glow); }
        .btn-secondary { background: var(--light-gray); color: var(--dark); }
        .btn-secondary:hover { background: var(--gray); color: white; transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; transform: translateY(-2px); }
        .btn-error { background: var(--error); color: white; }
        .btn-error:hover { background: #dc2626; transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--dark); }
        .btn-outline:hover { background: var(--sidebar-hover); transform: translateY(-2px); }
        .btn-sm { padding: 0.5rem 1rem; font-size: 0.8rem; }
        
        .invitation-item { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; transition: var(--transition); }
        .invitation-item:hover { background: var(--sidebar-hover); }
        .invitation-item:last-child { border-bottom: none; }
        .invitation-info { display: flex; align-items: center; gap: 1rem; }
        .invitation-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--gradient); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1rem; }
        .invitation-details h4 { font-weight: 600; margin-bottom: 0.25rem; }
        .invitation-details p { font-size: 0.8rem; color: var(--gray); }
        .invitation-actions { display: flex; gap: 0.5rem; }
        
        .collaborations-grid { padding: 1.5rem 2rem; }
        .collaboration-card { padding: 1.5rem; border: 1px solid var(--border); border-radius: var(--radius-lg); transition: var(--transition); margin-bottom: 1rem; cursor: pointer; position: relative; overflow: hidden; backdrop-filter: blur(10px); }
        .collaboration-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: var(--gradient-light); transition: var(--transition); }
        .collaboration-card:last-child { margin-bottom: 0; }
        .collaboration-card:hover { border-color: var(--primary); box-shadow: var(--shadow-lg); transform: translateY(-4px); }
        .collaboration-card:hover::before { left: 0; }
        .collaboration-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; position: relative; z-index: 1; }
        .collaboration-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--primary); font-size: 1.1rem; transition: var(--transition); }
        .collaboration-card:hover .collaboration-name { color: var(--primary-dark); }
        .collaboration-icon { color: var(--gray); transition: var(--transition); }
        .collaboration-card:hover .collaboration-icon { color: var(--primary); transform: scale(1.1); }
        .collaboration-badge { padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; transition: var(--transition); }
        .collaboration-badge.public { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .collaboration-badge.private { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); }
        .collaboration-badge.collaborator { background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid rgba(99, 102, 241, 0.2); }
        .collaboration-description { color: var(--gray); margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.5; position: relative; z-index: 1; }
        .collaboration-actions { display: flex; align-items: center; gap: 1.5rem; font-size: 0.8rem; color: var(--gray); position: relative; z-index: 1; }
        .collaboration-action { display: flex; align-items: center; gap: 0.375rem; transition: var(--transition); }
        .collaboration-card:hover .collaboration-action { color: var(--dark); }
        
        .empty-state { text-align: center; padding: 3rem 2rem; color: var(--gray); }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.5; animation: bounce 2s infinite; }
        .empty-state h3 { font-size: 1.3rem; margin-bottom: 0.5rem; color: var(--dark); }
        
        .floating-shapes { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: -1; }
        .shape { position: absolute; opacity: 0.03; border-radius: 50%; animation: float 25s infinite linear; }
        .shape-1 { width: 150px; height: 150px; background: var(--primary); top: 10%; left: 5%; animation-delay: 0s; }
        .shape-2 { width: 100px; height: 100px; background: var(--secondary); top: 70%; left: 80%; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--accent); top: 40%; left: 70%; animation-delay: -16s; }
        .shape-4 { width: 80px; height: 80px; background: var(--success); top: 20%; left: 85%; animation-delay: -12s; }
        .shape-5 { width: 120px; height: 120px; background: var(--error); top: 80%; left: 10%; animation-delay: -4s; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideInUp { from { opacity: 0; transform: translateY(50px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); } 40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); } }
        @keyframes float { 0%, 100% { transform: translateY(0) rotate(0deg); } 33% { transform: translateY(-20px) rotate(120deg); } 66% { transform: translateY(20px) rotate(240deg); } }
        @keyframes glow { from { filter: drop-shadow(0 0 5px rgba(255, 255, 255, 0.5)); } to { filter: drop-shadow(0 0 20px rgba(255, 255, 255, 0.8)); } }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .nav-text, .sidebar .logo-text, .sidebar .nav-title, .sidebar .nav-badge { display: none; }
            .main-content { margin-left: 80px; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .top-nav { padding: 1rem; }
            .search-bar, .user-info { display: none; }
            .mobile-menu-btn { display: block; }
            .stats-grid { grid-template-columns: 1fr; }
            .collaborations { padding: 1rem; }
            .welcome-section h1 { font-size: 2rem; }
            .welcome-section p { font-size: 1rem; }
            .invite-form { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="#" class="logo">
                <div class="logo-icon"><i class="fas fa-code"></i></div>
                <span class="logo-text">CodeSphere</span>
            </a>
            <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-chevron-left"></i></button>
        </div>
        <div class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Navigation</div>
                <a href="dashboard.php" class="nav-item"><i class="fas fa-home nav-icon"></i><span class="nav-text">Dashboard</span></a>
                <a href="all_repos.php" class="nav-item"><i class="fas fa-code-branch nav-icon"></i><span class="nav-text">All Repositories</span></a>
                <a href="repo.php" class="nav-item"><i class="fas fa-plus-circle nav-icon"></i><span class="nav-text">New Repository</span></a>
                <a href="code_editor.php" class="nav-item"><i class="fas fa-code nav-icon"></i><span class="nav-text">Code Editor</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Collaborate</div>
                <a href="collaborations.php" class="nav-item active"><i class="fas fa-users nav-icon"></i><span class="nav-text">Collaborations</span><span class="nav-badge"><?php echo count($collaborations); ?></span></a>
                <a href="notifications.php" class="nav-item"><i class="fas fa-bell nav-icon"></i><span class="nav-text">Notifications</span></a>
                <a href="messages.php" class="nav-item"><i class="fas fa-envelope nav-icon"></i><span class="nav-text">Messages</span></a>
                <a href="friends.php" class="nav-item"><i class="fas fa-user-friends nav-icon"></i><span class="nav-text">Friends</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Insights</div>
                <a href="analytics.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i><span class="nav-text">Analytics</span></a>
                <a href="contributions.php" class="nav-item"><i class="fas fa-fire-flame-curved nav-icon"></i><span class="nav-text">Contributions</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Account</div>
                <a href="profile.php" class="nav-item"><i class="fas fa-user-circle nav-icon"></i><span class="nav-text">Profile</span></a>
                <a href="settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i><span class="nav-text">Settings</span></a>
                <a href="logout.php" class="nav-item"><i class="fas fa-sign-out-alt nav-icon"></i><span class="nav-text">Logout</span></a>
            </div>
        </div>
    </div>

    <div class="main-content" id="mainContent">
        <header>
            <div class="top-nav">
                <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
                <div class="search-bar">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" id="searchInput" placeholder="Search collaborations...">
                    </div>
                </div>
                <div class="nav-right">
                    <button class="nav-btn" title="Friends"><i class="fas fa-user-friends"></i></button>
                    <button class="nav-btn" title="Messages"><i class="fas fa-envelope"></i></button>
                    <button class="nav-btn" title="Notifications"><i class="fas fa-bell"></i></button>
                    <button class="nav-btn theme-toggle" id="themeToggle" title="Toggle theme"><i class="fas fa-moon"></i></button>
                    <div class="user-menu" onclick="window.location.href='profile.php'">
                        <div class="user-avatar"><?php echo strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)); ?></div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div class="user-role">Developer</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <section class="collaborations">
            <?php if (isset($successMessage)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $successMessage; ?></div><?php endif; ?>
            <?php if (isset($errorMessage)): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?></div><?php endif; ?>

            <div class="welcome-section">
                <div class="welcome-content">
                    <h1><i class="fas fa-users welcome-icon"></i>Collaborations</h1>
                    <p>Manage your shared repositories and collaborate with other developers.</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-header"><div class="stat-icon collaborations"><ion-icon name="people-outline"></ion-icon></div><span class="stat-trend positive">+<?php echo count($collaborations) > 0 ? round(count($collaborations) * 0.1) : 0; ?></span></div><h3><?php echo count($collaborations); ?></h3><p>Active Collaborations</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon invitations"><i class="fas fa-envelope"></i></div><span class="stat-trend positive">+<?php echo count($pendingInvitations) > 0 ? round(count($pendingInvitations) * 0.1) : 0; ?></span></div><h3><?php echo count($pendingInvitations); ?></h3><p>Pending Invitations</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon owned"><i class="fas fa-crown"></i></div><span class="stat-trend positive">+<?php echo count($ownedRepos) > 0 ? round(count($ownedRepos) * 0.1) : 0; ?></span></div><h3><?php echo count($ownedRepos); ?></h3><p>Owned Repositories</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon friends"><i class="fas fa-user-friends"></i></div><span class="stat-trend positive">+<?php echo count($friends) > 0 ? round(count($friends) * 0.1) : 0; ?></span></div><h3><?php echo count($friends); ?></h3><p>Friends</p></div>
            </div>

            <?php if (count($ownedRepos) > 0 && count($friends) > 0): ?>
            <div class="invite-collaborator">
                <h3>Invite Collaborator</h3>
                <form method="POST" class="invite-form">
                    <select class="invite-input" name="repo_id" required>
                        <option value="">Select Repository...</option>
                        <?php foreach ($ownedRepos as $repo): ?>
                            <option value="<?php echo $repo['repo_id']; ?>"><?php echo htmlspecialchars($repo['repo_id']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="invite-input" name="collaborator_id" required>
                        <option value="">Select Friend...</option>
                        <?php foreach ($friends as $friend): ?>
                            <option value="<?php echo $friend['userId']; ?>"><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?> (@<?php echo htmlspecialchars($friend['username']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="invite_collaborator" class="btn btn-primary"><i class="fas fa-user-plus"></i>Send Invitation</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if (count($pendingInvitations) > 0): ?>
            <div class="invitations-section">
                <div class="section-header"><h2>Pending Invitations</h2><span class="nav-badge"><?php echo count($pendingInvitations); ?></span></div>
                <?php foreach ($pendingInvitations as $invitation): ?>
                <div class="invitation-item">
                    <div class="invitation-info">
                        <div class="invitation-avatar"><i class="fas fa-user"></i></div>
                        <div class="invitation-details">
                            <h4>Invitation to collaborate on <?php echo htmlspecialchars($invitation['repo_id']); ?></h4>
                            <p>Sent by User ID: <?php echo htmlspecialchars($invitation['sender_id']); ?></p>
                        </div>
                    </div>
                    <div class="invitation-actions">
                        <form method="POST" style="display: inline;"><input type="hidden" name="invitation_id" value="<?php echo $invitation['id']; ?>"><button type="submit" name="accept_invitation" class="btn btn-success btn-sm"><ion-icon name="checkmark-outline"></ion-icon>Accept</button></form>
                        <form method="POST" style="display: inline;"><input type="hidden" name="invitation_id" value="<?php echo $invitation['id']; ?>"><button type="submit" name="reject_invitation" class="btn btn-error btn-sm"><ion-icon name="close-outline"></ion-icon>Reject</button></form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="collaborations-section">
                <div class="section-header">
                    <h2>Your Collaborations</h2>
                    <div><span class="nav-badge"><?php echo count($collaborations); ?></span></div>
                </div>
                <div class="collaborations-grid">
                    <?php if (count($collaborations) > 0): ?>
                        <?php foreach ($collaborations as $collaboration): ?>
                            <div class="collaboration-card" onclick="window.location.href='repo_view.php?repo=<?php echo $collaboration['repo_id']; ?>'">
                                <div class="collaboration-header">
                                    <div class="collaboration-name"><i class="fas fa-code-branch collaboration-icon"></i><?php echo htmlspecialchars($collaboration['repo_id']); ?></div>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <span class="collaboration-badge <?php echo $collaboration['repo_access'] ?? 'private'; ?>"><?php echo ucfirst($collaboration['repo_access'] ?? 'private'); ?></span>
                                        <span class="collaboration-badge collaborator">Collaborator</span>
                                    </div>
                                </div>
                                <p class="collaboration-description">Shared with you on <?php echo date('M j, Y', strtotime($collaboration['access_start'] ?? 'now')); ?></p>
                                <div class="collaboration-actions">
                                    <span class="collaboration-action"><i class="fas fa-code"></i>View Repository</span>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to leave this collaboration?');">
                                        <input type="hidden" name="repo_id" value="<?php echo $collaboration['repo_id']; ?>">
                                        <button type="submit" name="leave_collaboration" class="collaboration-action" style="background: none; border: none; color: inherit; cursor: pointer;"><i class="fas fa-sign-out-alt"></i>Leave Collaboration</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><ion-icon name="people-outline"></ion-icon><h3>No collaborations yet</h3><p>You haven't joined any collaborations yet. Accept an invitation or ask a friend to share a repository with you.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <script>
    // Theme Toggle with Persistent Storage
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = themeToggle.querySelector('i');

    // Initialize theme based on priority: Session > Cookie > System Preference
    function initializeTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme') || 'light';
        
        // Update UI to match current theme
        if (currentTheme === 'dark') {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        } else {
            document.body.classList.remove('dark-mode');
            themeIcon.classList.remove('fa-sun');
            themeIcon.classList.add('fa-moon');
        }
    }

    // Call on page load
    initializeTheme();

    themeToggle.addEventListener('click', () => {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        // Update HTML attribute
        html.setAttribute('data-theme', newTheme);
        
        // Update body class
        document.body.classList.toggle('dark-mode');
        
        // Update icon
        if (newTheme === 'dark') {
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        } else {
            themeIcon.classList.remove('fa-sun');
            themeIcon.classList.add('fa-moon');
        }
        
        // Save preference via AJAX
        updateThemePreference(newTheme);
        
        // Store in localStorage for immediate future pages
        localStorage.setItem('user_theme', newTheme);
    });

    function updateThemePreference(theme) {
        const formData = new FormData();
        formData.append('theme', theme);
        formData.append('update_preferences', 'true');
        
        fetch('settings.php', {
            method: 'POST',
            body: formData
        }).then(response => {
            // Update session and cookie via the response
            document.cookie = `user_theme=${theme}; path=/; max-age=${30 * 24 * 60 * 60}`;
        }).catch(err => {
            console.log('Theme update failed, but local changes applied');
        });
    }

    // Apply theme from localStorage on page load (for immediate feedback)
    document.addEventListener('DOMContentLoaded', function() {
        const savedTheme = localStorage.getItem('user_theme');
        if (savedTheme) {
            document.documentElement.setAttribute('data-theme', savedTheme);
            initializeTheme();
        }
    });

    // Existing JavaScript code continues...
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
    });
    mobileMenuBtn.addEventListener('click', () => sidebar.classList.toggle('mobile-open'));
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) sidebar.classList.remove('mobile-open');
    });

    document.addEventListener('DOMContentLoaded', () => {
        const cards = document.querySelectorAll('.stat-card');
        cards.forEach((card, index) => card.style.animationDelay = `${index * 0.1}s`);
    });
    </script>
</body>
</html>