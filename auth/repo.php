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
define('DB_NAME_NOTIFICATIONS', 'notifications');

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

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $repoName = trim($_POST['repo_name']);
    $description = trim($_POST['description']);
    $visibility = $_POST['visibility'];
    $collaborators = isset($_POST['collaborators']) ? explode(',', $_POST['collaborators']) : [];
    $collaborators = array_map('trim', $collaborators);
    $collaborators = array_filter($collaborators); // Remove empty values
    
    // Validate repository name
    if (empty($repoName)) {
        $error = "Repository name is required!";
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $repoName)) {
        $error = "Repository name can only contain letters, numbers, hyphens, and underscores!";
    } else {
        try {
            // Generate unique repo ID
            $repoId = generateRepoId($repoName, $_SESSION['user_id']);
            
            // Create repository database
            if (createRepoDatabase($repoId)) {
                // Add repository to user's repo table as owner
                if (addRepoToUserTable($user['username'], $_SESSION['user_id'], $repoId, $visibility, 'owner', $repoName, $description)) {
                    // Add initial commit
                    createInitialCommit($repoId, $_SESSION['user_id'], $user['first_name'] . ' ' . $user['last_name'], $repoName);
                    
                    // Add collaborators if any
                    if (!empty($collaborators)) {
                        $inviteResults = [];
                        foreach ($collaborators as $collaboratorUserId) {
                            $result = sendCollaborationInvite($collaboratorUserId, $repoId, $repoName, $_SESSION['user_id'], $user['first_name'] . ' ' . $user['last_name'], $visibility);
                            $inviteResults[] = $result;
                        }
                        
                        $successCount = count(array_filter($inviteResults));
                        if ($successCount > 0) {
                            $success = "Repository '$repoName' created successfully! Invitations sent to $successCount collaborator(s).";
                        } else {
                            $success = "Repository '$repoName' created successfully! But failed to send invitations to collaborators.";
                        }
                    } else {
                        $success = "Repository '$repoName' created successfully!";
                    }
                    
                    // Redirect to repository view after successful creation
                    header("Location: repo_view.php?repo=$repoId");
                    exit();
                } else {
                    $error = "Failed to add repository to your account!";
                }
            } else {
                $error = "Failed to create repository database!";
            }
        } catch (Exception $e) {
            $error = "Error creating repository: " . $e->getMessage();
        }
    }
}

// Generate unique repository ID
function generateRepoId($repoName, $userId) {
    $timestamp = time();
    $random = bin2hex(random_bytes(3));
    return strtolower($repoName) . '_' . substr($userId, 0, 3) . '_' . $timestamp . '_' . $random;
}

// Create repository database and tables
function createRepoDatabase($repoId) {
    // Create database
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed");
    }
    
    // Create database
    $createDbSQL = "CREATE DATABASE IF NOT EXISTS `$repoId`";
    if (!$conn->query($createDbSQL)) {
        throw new Exception("Failed to create database: " . $conn->error);
    }
    
    $conn->select_db($repoId);
    
    // Create files table
    $createFilesTable = "CREATE TABLE IF NOT EXISTS `files` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        folder VARCHAR(500) DEFAULT '',
        file_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(50) NOT NULL,
        content LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_by VARCHAR(50) NOT NULL,
        last_modified_by VARCHAR(50),
        UNIQUE KEY unique_file_per_folder (folder, file_name)
    )";
    
    if (!$conn->query($createFilesTable)) {
        throw new Exception("Failed to create files table: " . $conn->error);
    }
    
    // Create file_versions table for version control
    $createVersionsTable = "CREATE TABLE IF NOT EXISTS `file_versions` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_id INT NOT NULL,
        version INT NOT NULL,
        content LONGTEXT,
        created_by VARCHAR(50) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
    )";
    
    if (!$conn->query($createVersionsTable)) {
        throw new Exception("Failed to create file_versions table: " . $conn->error);
    }
    
    // Create commits table
    $createCommitsTable = "CREATE TABLE IF NOT EXISTS `commits` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commit_hash VARCHAR(64) NOT NULL UNIQUE,
        author_id VARCHAR(50) NOT NULL,
        author_name VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        changes TEXT,
        parent_hash VARCHAR(64)
    )";
    
    if (!$conn->query($createCommitsTable)) {
        throw new Exception("Failed to create commits table: " . $conn->error);
    }
    
    // Create collaborators table for repository-specific permissions
    $createCollaboratorsTable = "CREATE TABLE IF NOT EXISTS `collaborators` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(50) NOT NULL UNIQUE,
        username VARCHAR(255) NOT NULL,
        permission_level ENUM('read', 'write', 'admin') DEFAULT 'write',
        added_by VARCHAR(50) NOT NULL,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('active', 'pending', 'removed') DEFAULT 'pending'
    )";
    
    if (!$conn->query($createCollaboratorsTable)) {
        throw new Exception("Failed to create collaborators table: " . $conn->error);
    }

    // Create share_links table
    $createShareLinksTable = "CREATE TABLE IF NOT EXISTS `share_links` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(32) UNIQUE NOT NULL,
        permission ENUM('read', 'write') NOT NULL,
        expiry DATETIME,
        created_by VARCHAR(50) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_active BOOLEAN DEFAULT TRUE
    )";
    
    if (!$conn->query($createShareLinksTable)) {
        throw new Exception("Failed to create share_links table: " . $conn->error);
    }
    
    $conn->close();
    return true;
}

// Function to add version table to existing repositories
function addVersionTableToExistingRepo($repoId) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
    if ($conn->connect_error) {
        return false;
    }
    
    // Check if file_versions table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'file_versions'");
    if ($tableCheck->num_rows === 0) {
        // Create file_versions table
        $createVersionsTable = "CREATE TABLE `file_versions` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            version INT NOT NULL,
            content LONGTEXT,
            created_by VARCHAR(50) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
        )";
        
        if ($conn->query($createVersionsTable)) {
            error_log("Added file_versions table to repository: $repoId");
        } else {
            error_log("Failed to add file_versions table to repository: $repoId - " . $conn->error);
        }
    }
    
    $conn->close();
    return true;
}

// Function to add share_links table to existing repositories
function addShareLinksTableToExistingRepo($repoId) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
    if ($conn->connect_error) {
        return false;
    }
    
    // Check if share_links table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'share_links'");
    if ($tableCheck->num_rows === 0) {
        // Create share_links table
        $createShareLinksTable = "CREATE TABLE `share_links` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(32) UNIQUE NOT NULL,
            permission ENUM('read', 'write') NOT NULL,
            expiry DATETIME,
            created_by VARCHAR(50) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE
        )";
        
        if ($conn->query($createShareLinksTable)) {
            error_log("Added share_links table to repository: $repoId");
        } else {
            error_log("Failed to add share_links table to repository: $repoId - " . $conn->error);
        }
    }
    
    $conn->close();
    return true;
}

// Add repository to user's repository table
function addRepoToUserTable($username, $userId, $repoId, $visibility, $role, $repoName, $description) {
    $conn = getDBConnection(DB_NAME_USER_REPOS);
    $tableName = $username . '_' . $userId;
    
    // Check if table exists, create if not
    $tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
    if ($tableCheck->num_rows === 0) {
        $createTable = "CREATE TABLE IF NOT EXISTS `$tableName` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            repo_id VARCHAR(255) NOT NULL UNIQUE,
            repo_name VARCHAR(255) NOT NULL,
            description TEXT,
            visibility ENUM('public', 'private') DEFAULT 'private',
            repo_access VARCHAR(50),
            access_start DATETIME,
            role VARCHAR(50),
            permission_level ENUM('read', 'write', 'admin') DEFAULT 'admin'
        )";
        
        if (!$conn->query($createTable)) {
            throw new Exception("Failed to create user repository table: " . $conn->error);
        }
    } else {
        // Check if new columns exist and add them if not
        $result = $conn->query("DESCRIBE `$tableName`");
        $existingColumns = [];
        while ($row = $result->fetch_assoc()) {
            $existingColumns[$row['Field']] = true;
        }
        
        $alterSql = [];
        if (!isset($existingColumns['repo_name'])) {
            $alterSql[] = "ADD COLUMN repo_name VARCHAR(255) NOT NULL DEFAULT 'repository'";
        }
        if (!isset($existingColumns['description'])) {
            $alterSql[] = "ADD COLUMN description TEXT";
        }
        if (!isset($existingColumns['permission_level'])) {
            $alterSql[] = "ADD COLUMN permission_level ENUM('read', 'write', 'admin') DEFAULT 'admin'";
        }
        if (!isset($existingColumns['visibility'])) {
            $alterSql[] = "ADD COLUMN visibility ENUM('public', 'private') DEFAULT 'private'";
        }
        
        if (!empty($alterSql)) {
            $alterQuery = "ALTER TABLE `$tableName` " . implode(', ', $alterSql);
            if (!$conn->query($alterQuery)) {
                error_log("Failed to alter table: " . $conn->error);
            }
        }
    }
    
    // Insert repository record
    $accessStart = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("INSERT INTO `$tableName` (repo_id, repo_name, description, visibility, repo_access, access_start, role, permission_level) VALUES (?, ?, ?, ?, ?, ?, ?, 'admin')");
    $stmt->bind_param("sssssss", $repoId, $repoName, $description, $visibility, $visibility, $accessStart, $role);
    
    $result = $stmt->execute();
    
    if (!$result) {
        error_log("Insert failed: " . $stmt->error);
    }
    
    $stmt->close();
    $conn->close();
    return $result;
}

// Create initial commit
function createInitialCommit($repoId, $userId, $userName, $repoName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
    if ($conn->connect_error) {
        throw new Exception("Failed to connect to repository database");
    }
    
    $commitHash = bin2hex(random_bytes(16));
    $message = "Initial commit - Created repository '$repoName'";
    
    $stmt = $conn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $commitHash, $userId, $userName, $message);
    $result = $stmt->execute();
    
    $conn->close();
    return $result;
}

// Send collaboration invite
function sendCollaborationInvite($collaboratorUserId, $repoId, $repoName, $inviterId, $inviterName, $visibility) {
    // Check if user exists
    $conn = getDBConnection(DB_NAME_CODESPHERE);
    $stmt = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE userId = ?");
    $stmt->bind_param("s", $collaboratorUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $collaborator = $result->fetch_assoc();
        
        // Create notification in collaborator's notification table
        $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_NOTIFICATIONS);
        if ($notifConn->connect_error) {
            error_log("Notifications database connection failed: " . $notifConn->connect_error);
            $conn->close();
            return false;
        }
        
        $notifTableName = $collaboratorUserId;
        
        // Check if notification table exists, create if not
        $tableCheck = $notifConn->query("SHOW TABLES LIKE '$notifTableName'");
        if ($tableCheck->num_rows === 0) {
            $createNotifTable = "CREATE TABLE IF NOT EXISTS `$notifTableName` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                link VARCHAR(500),
                type VARCHAR(50),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_read TINYINT(1) DEFAULT 0
            )";
            if (!$notifConn->query($createNotifTable)) {
                error_log("Failed to create notification table: " . $notifConn->error);
                $notifConn->close();
                $conn->close();
                return false;
            }
        }
        
        // Add collaboration invite notification
        $message = "$inviterName invited you to collaborate on repository '$repoName'";
        $link = "collaboration_response.php?repo=$repoId&inviter=$inviterId";
        $type = "collaboration_invite";
        
        $stmt = $notifConn->prepare("INSERT INTO `$notifTableName` (message, link, type) VALUES (?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare failed: " . $notifConn->error);
            $notifConn->close();
            $conn->close();
            return false;
        }
        
        $stmt->bind_param("sss", $message, $link, $type);
        $result = $stmt->execute();
        
        if ($result) {
            error_log("SUCCESS: Notification sent to $collaboratorUserId for repo $repoName");
        } else {
            error_log("FAILED: Notification to $collaboratorUserId - " . $stmt->error);
        }
        
        $stmt->close();
        $notifConn->close();
        $conn->close();
        
        return $result;
    } else {
        error_log("User with ID '$collaboratorUserId' not found!");
        $conn->close();
        return false;
    }
}

// Get suggested collaborators
function getSuggestedCollaborators($userId) {
    $conn = getDBConnection(DB_NAME_CODESPHERE);
    $suggested = [];
    
    $stmt = $conn->prepare("SELECT userId, username, first_name, last_name FROM users WHERE userId != ? LIMIT 10");
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $suggested[] = $row;
    }
    
    $conn->close();
    return $suggested;
}

$suggestedCollaborators = getSuggestedCollaborators($_SESSION['user_id']);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Repository - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --error: #ef4444;
            --dark: #0f172a;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --radius: 8px;
            --radius-lg: 12px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-glow: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
        }

        .dark-mode {
            --primary: #818cf8;
            --primary-dark: #6366f1;
            --primary-light: #3730a3;
            --secondary: #a78bfa;
            --accent: #fbbf24;
            --dark: #f1f5f9;
            --light: #0f172a;
            --gray: #94a3b8;
            --light-gray: #1e293b;
            --card-bg: #1e293b;
            --border: #334155;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            transition: var(--transition);
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.1) 0%, transparent 20%),
                radial-gradient(circle at 50% 50%, rgba(236, 72, 153, 0.05) 0%, transparent 50%);
            z-index: -1;
            pointer-events: none;
        }

        .floating-particles {
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
            width: 4px;
            height: 4px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0.3;
            animation: float 15s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(0) translateX(0);
                opacity: 0.3;
            }
            50% {
                transform: translateY(-20px) translateX(10px);
                opacity: 0.6;
            }
            100% {
                transform: translateY(0) translateX(0);
                opacity: 0.3;
            }
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            animation: slideDown 0.6s ease-out;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            background: var(--gradient-light);
        }

        .back-btn:hover {
            color: var(--primary-dark);
            transform: translateX(-5px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);
        }

        .theme-toggle {
            background: var(--gradient-light);
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .theme-toggle:hover {
            transform: rotate(15deg);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .create-repo-card {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: relative;
            animation: cardAppear 0.8s ease-out;
            transition: var(--transition);
        }

        @keyframes cardAppear {
            0% { opacity: 0; transform: scale(0.95); }
            100% { opacity: 1; transform: scale(1); }
        }

        .create-repo-card:hover {
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            transform: translateY(-5px);
        }

        .card-header {
            background: var(--gradient-glow);
            color: white;
            padding: 2.5rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .card-header h1 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
            position: relative;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .card-header p {
            opacity: 0.9;
            font-size: 1.1rem;
            position: relative;
        }

        .card-body {
            padding: 2.5rem;
        }

        .form-group {
            margin-bottom: 2rem;
            position: relative;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 1rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            transform: translateY(-2px);
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        .visibility-options {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .visibility-option {
            flex: 1;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem 1rem;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .visibility-option::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-light);
            opacity: 0;
            transition: var(--transition);
        }

        .visibility-option:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.15);
        }

        .visibility-option:hover::before {
            opacity: 1;
        }

        .visibility-option.selected {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.05);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.2);
        }

        .visibility-option.selected::before {
            opacity: 1;
        }

        .visibility-icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .visibility-option > div {
            position: relative;
            z-index: 1;
        }

        .collaborators-section {
            border: 2px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            background: var(--light);
            transition: var(--transition);
        }

        .collaborators-section:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .collaborators-input {
            position: relative;
        }

        .tag-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .tag {
            background: var(--gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: tagAppear 0.3s ease-out;
            box-shadow: 0 4px 6px rgba(99, 102, 241, 0.3);
        }

        @keyframes tagAppear {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }

        .tag-remove {
            background: rgba(255, 255, 255, 0.3);
            border: none;
            color: white;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            transition: var(--transition);
        }

        .tag-remove:hover {
            background: rgba(255, 255, 255, 0.5);
            transform: scale(1.1);
        }

        .suggested-collaborators {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        .suggested-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        .suggested-user {
            background: var(--light-gray);
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border: 1px solid transparent;
        }

        .suggested-user:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(99, 102, 241, 0.2);
            border-color: var(--primary);
        }

        .user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1rem;
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
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient-glow);
            color: white;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(99, 102, 241, 0.5);
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        .alert {
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideIn 0.5s ease-out;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border-left-color: var(--error);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left-color: var(--success);
        }

        .form-footer {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--gray);
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            position: relative;
        }

        .form-footer a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--gradient);
            transition: width 0.3s ease;
        }

        .form-footer a:hover {
            color: var(--primary-dark);
        }

        .form-footer a:hover::after {
            width: 100%;
        }

        .glitter {
            position: absolute;
            width: 10px;
            height: 10px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            pointer-events: none;
            z-index: 10;
            animation: glitter 1.5s ease-out forwards;
        }

        @keyframes glitter {
            0% {
                transform: scale(0) rotate(0deg);
                opacity: 1;
            }
            100% {
                transform: scale(1) rotate(180deg);
                opacity: 0;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .visibility-options {
                flex-direction: column;
            }
            
            .card-header {
                padding: 2rem 1.5rem;
            }
            
            .card-header h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="floating-particles" id="particles"></div>
    
    <div class="container">
        <div class="header">
            <a href="dashboard.php" class="back-btn">
                <ion-icon name="arrow-back-outline"></ion-icon>
                Back to Dashboard
            </a>
            <button class="theme-toggle" id="themeToggle">
                <i class="fas fa-moon"></i>
            </button>
        </div>

        <div class="create-repo-card">
            <div class="card-header">
                <h1>Create New Repository</h1>
                <p>Start collaborating on your code with CodeSphere</p>
            </div>

            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="createRepoForm">
                    <div class="form-group">
                        <label for="repo_name">Repository Name *</label>
                        <input type="text" id="repo_name" name="repo_name" class="form-control" placeholder="my-awesome-project" required value="<?php echo isset($_POST['repo_name']) ? htmlspecialchars($_POST['repo_name']) : ''; ?>">
                        <div class="form-text">
                            Only letters, numbers, hyphens, and underscores are allowed
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description (Optional)</label>
                        <textarea id="description" name="description" class="form-control" placeholder="A brief description of your repository" rows="3"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Visibility</label>
                        <div class="visibility-options">
                            <div class="visibility-option selected" data-value="public">
                                <div class="visibility-icon">
                                    <i class="fas fa-globe"></i>
                                </div>
                                <div>
                                    <strong>Public</strong>
                                    <div class="form-text">Anyone can see this repository</div>
                                </div>
                                <input type="radio" name="visibility" value="public" checked style="display: none;">
                            </div>
                            <div class="visibility-option" data-value="private">
                                <div class="visibility-icon">
                                    <ion-icon name="lock-closed-outline"></ion-icon>
                                </div>
                                <div>
                                    <strong>Private</strong>
                                    <div class="form-text">Only you and collaborators can see this</div>
                                </div>
                                <input type="radio" name="visibility" value="private" style="display: none;">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Collaborators</label>
                        <div class="collaborators-section">
                            <div class="collaborators-input">
                                <input type="text" id="collaborators_input" class="form-control" placeholder="Enter user IDs separated by commas (e.g., A1B2C, X3Y4Z)">
                                <div class="form-text">
                                    Add collaborators by their User ID. They will receive an invitation to collaborate.
                                </div>
                                <div class="tag-container" id="collaboratorsTags"></div>
                                <input type="hidden" name="collaborators" id="collaborators">
                            </div>
                            <style>
                                /* Prevent textarea resizing */
#description {
    resize: none;
}

/* Or for all textareas globally */
textarea {
    resize: none;
}
/* Remove blue background from autofilled inputs */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 1000px var(--light) inset !important;
    -webkit-text-fill-color: var(--dark) !important;
    transition: background-color 5000s ease-in-out 0s;
}

/* For dark mode support */
.dark-mode input:-webkit-autofill,
.dark-mode input:-webkit-autofill:hover,
.dark-mode input:-webkit-autofill:focus,
.dark-mode input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 1000px var(--light) inset !important;
    -webkit-text-fill-color: var(--dark) !important;
}

/* Prevent textarea resizing */
#description {
    resize: none;
}

/* Remove blue background from autofilled inputs */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 1000px var(--light) inset !important;
    -webkit-text-fill-color: var(--dark) !important;
    transition: background-color 5000s ease-in-out 0s;
}

.dark-mode input:-webkit-autofill,
.dark-mode input:-webkit-autofill:hover,
.dark-mode input:-webkit-autofill:focus,
.dark-mode input:-webkit-autofill:active {
    -webkit-box-shadow: 0 0 0 1000px var(--light) inset !important;
    -webkit-text-fill-color: var(--dark) !important;
}
                            </style>

                            <?php if (!empty($suggestedCollaborators)): ?>
                                <div class="suggested-collaborators">
                                    <div class="form-text">Suggested collaborators:</div>
                                    <div class="suggested-list">
                                        <?php foreach ($suggestedCollaborators as $suggested): ?>
                                            <div class="suggested-user" data-user-id="<?php echo htmlspecialchars($suggested['userId']); ?>" data-username="<?php echo htmlspecialchars($suggested['username']); ?>">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($suggested['first_name'], 0, 1)) . strtoupper(substr($suggested['last_name'], 0, 1)); ?>
                                                </div>
                                                <?php echo htmlspecialchars($suggested['username']); ?> (<?php echo htmlspecialchars($suggested['first_name'] . ' ' . $suggested['last_name']); ?>)
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="submitBtn">
                        <ion-icon name="add-outline"></ion-icon>
                        Create Repository
                    </button>
                </form>

                <div class="form-footer">
                    <p>By creating a repository, you agree to our <a href="tac.php">Terms of Service</a></p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Create floating particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 30;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random position
                const left = Math.random() * 100;
                const top = Math.random() * 100;
                particle.style.left = `${left}%`;
                particle.style.top = `${top}%`;
                
                // Random size
                const size = Math.random() * 4 + 2;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random color
                const colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.background = color;
                
                // Random animation delay and duration
                const delay = Math.random() * 15;
                const duration = 15 + Math.random() * 10;
                particle.style.animationDelay = `${delay}s`;
                particle.style.animationDuration = `${duration}s`;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Create glitter effect
        function createGlitter(x, y) {
            const glitter = document.createElement('div');
            glitter.classList.add('glitter');
            
            // Position the glitter at the click location
            glitter.style.left = `${x - 5}px`;
            glitter.style.top = `${y - 5}px`;
            
            // Random size
            const size = Math.random() * 8 + 4;
            glitter.style.width = `${size}px`;
            glitter.style.height = `${size}px`;
            
            // Random color
            const colors = ['#ffffff', '#c7d2fe', '#e0e7ff', '#fef3c7'];
            const color = colors[Math.floor(Math.random() * colors.length)];
            glitter.style.background = color;
            
            document.body.appendChild(glitter);
            
            // Remove glitter after animation completes
            setTimeout(() => {
                glitter.remove();
            }, 1500);
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
            
            // Add glitter effect on theme toggle
            const rect = themeToggle.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            for (let i = 0; i < 5; i++) {
                setTimeout(() => {
                    createGlitter(x, y);
                }, i * 100);
            }
        });

        // Visibility Selection
        const visibilityOptions = document.querySelectorAll('.visibility-option');
        visibilityOptions.forEach(option => {
            option.addEventListener('click', () => {
                visibilityOptions.forEach(opt => opt.classList.remove('selected'));
                option.classList.add('selected');
                const radio = option.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Add glitter effect on selection
                const rect = option.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top + rect.height / 2;
                createGlitter(x, y);
            });
        });

        // Collaborators Management
        const collaboratorsInput = document.getElementById('collaborators_input');
        const collaboratorsTags = document.getElementById('collaboratorsTags');
        const collaboratorsHidden = document.getElementById('collaborators');
        let collaborators = [];

        function updateCollaborators() {
            // Update tags display
            collaboratorsTags.innerHTML = '';
            collaborators.forEach((userId, index) => {
                const tag = document.createElement('div');
                tag.className = 'tag';
                tag.innerHTML = `
                    ${userId}
                    <button type="button" class="tag-remove" data-index="${index}">
                        <ion-icon name="close-outline"></ion-icon>
                    </button>
                `;
                collaboratorsTags.appendChild(tag);
            });

            // Update hidden input
            collaboratorsHidden.value = collaborators.join(',');
        }

        // Add collaborator on comma or enter
        collaboratorsInput.addEventListener('keydown', (e) => {
            if (e.key === ',' || e.key === 'Enter') {
                e.preventDefault();
                const userId = collaboratorsInput.value.trim();
                if (userId && !collaborators.includes(userId)) {
                    collaborators.push(userId);
                    updateCollaborators();
                    
                    // Add glitter effect
                    const rect = collaboratorsInput.getBoundingClientRect();
                    const x = rect.left + rect.width / 2;
                    const y = rect.top + rect.height / 2;
                    createGlitter(x, y);
                    
                    collaboratorsInput.value = '';
                }
            }
        });

        // Remove collaborator
        collaboratorsTags.addEventListener('click', (e) => {
            if (e.target.classList.contains('tag-remove') || e.target.closest('.tag-remove')) {
                const button = e.target.classList.contains('tag-remove') ? e.target : e.target.closest('.tag-remove');
                const index = parseInt(button.getAttribute('data-index'));
                collaborators.splice(index, 1);
                updateCollaborators();
            }
        });

        // Suggested collaborators click handler
        document.querySelectorAll('.suggested-user').forEach(user => {
            user.addEventListener('click', () => {
                const userId = user.getAttribute('data-user-id');
                if (userId && !collaborators.includes(userId)) {
                    collaborators.push(userId);
                    updateCollaborators();
                    
                    // Add glitter effect
                    const rect = user.getBoundingClientRect();
                    const x = rect.left + rect.width / 2;
                    const y = rect.top + rect.height / 2;
                    createGlitter(x, y);
                }
            });
        });

        // Form validation
        const form = document.getElementById('createRepoForm');
        const submitBtn = document.getElementById('submitBtn');
        
        form.addEventListener('submit', (e) => {
            const repoName = document.getElementById('repo_name').value.trim();
            if (!repoName) {
                e.preventDefault();
                alert('Repository name is required!');
                return;
            }

            if (!/^[a-zA-Z0-9_-]+$/.test(repoName)) {
                e.preventDefault();
                alert('Repository name can only contain letters, numbers, hyphens, and underscores!');
                return;
            }
            
            // Add glitter effect on form submission
            const rect = submitBtn.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            for (let i = 0; i < 10; i++) {
                setTimeout(() => {
                    createGlitter(x, y);
                }, i * 50);
            }
        });

        // Add glitter effect on input focus
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', () => {
                const rect = input.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top;
                createGlitter(x, y);
            });
        });

        // Auto-focus on repository name input
        document.getElementById('repo_name').focus();
        
        // Initialize particles
        createParticles();
    </script>
</body>
</html>