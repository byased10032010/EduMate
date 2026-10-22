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

$repoId = $_GET['repo'] ?? '';
$filePath = $_GET['file'] ?? '';
$fileType = $_GET['type'] ?? '';

if (empty($repoId)) {
    header('Location: dashboard.php');
    exit();
}

// Get user data
$conn = getDBConnection(DB_NAME_CODESPHERE);
$stmt = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE userId = ?");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Check if user has access to this repository
$reposConn = getDBConnection(DB_NAME_USER_REPOS);
$tableName = $user['username'] . '_' . $_SESSION['user_id'];
$hasAccess = false;
$userPermission = 'read';

$tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
if ($tableCheck->num_rows > 0) {
    $accessStmt = $reposConn->prepare("SELECT * FROM `$tableName` WHERE repo_id = ?");
    $accessStmt->bind_param("s", $repoId);
    $accessStmt->execute();
    $repoResult = $accessStmt->get_result();
    
    if ($repoResult->num_rows > 0) {
        $hasAccess = true;
        $repoDetails = $repoResult->fetch_assoc();
        $userPermission = $repoDetails['permission_level'] ?? 'read';
    }
}

// Check collaborators table in repo database
if (!$hasAccess) {
    $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
    if (!$repoConn->connect_error) {
        // Add version table if it doesn't exist
        addVersionTableToExistingRepo($repoId);
        
        $collabStmt = $repoConn->prepare("SELECT permission_level FROM collaborators WHERE user_id = ? AND status = 'active'");
        $collabStmt->bind_param("s", $_SESSION['user_id']);
        $collabStmt->execute();
        $collabResult = $collabStmt->get_result();
        
        if ($collabResult->num_rows > 0) {
            $hasAccess = true;
            $collabData = $collabResult->fetch_assoc();
            $userPermission = $collabData['permission_level'];
        }
        $repoConn->close();
    }
}

if (!$hasAccess) {
    header('Location: dashboard.php');
    exit();
}

// Connect to repository database
$repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
if ($repoConn->connect_error) {
    die("Repository not found or access denied");
}

// Add version table if it doesn't exist
addVersionTableToExistingRepo($repoId);

// Handle file operations
$message = '';
$error = '';
$fileContent = '';
$currentFileId = null;
$hasUnsavedChanges = false;

// Initialize open tabs from session or create new
if (!isset($_SESSION['open_tabs'])) {
    $_SESSION['open_tabs'] = [];
}

// Add current file to open tabs if specified
if (!empty($filePath)) {
    $fileKey = $filePath . '|' . $fileType;
    if (!in_array($fileKey, $_SESSION['open_tabs'])) {
        $_SESSION['open_tabs'][] = $fileKey;
    }
}

// Handle tab closing via GET parameter
if (isset($_GET['close_tab'])) {
    $tabToClose = $_GET['close_tab'];
    $_SESSION['open_tabs'] = array_filter($_SESSION['open_tabs'], function($tab) use ($tabToClose) {
        return $tab !== $tabToClose;
    });
    
    // If we're closing the current tab, redirect to first available tab or editor home
    if ($filePath && $tabToClose === $filePath . '|' . $fileType) {
        if (!empty($_SESSION['open_tabs'])) {
            $firstTab = reset($_SESSION['open_tabs']);
            list($newFile, $newType) = explode('|', $firstTab);
            header("Location: code_editor.php?repo=$repoId&file=" . urlencode($newFile) . "&type=$newType");
            exit();
        } else {
            header("Location: code_editor.php?repo=$repoId");
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_file'])) {
        if ($userPermission === 'read') {
            $error = "You don't have write permission for this repository";
        } else {
            $fileId = $_POST['file_id'];
            $content = $_POST['content'];
            $commitMessage = trim($_POST['commit_message']) ?? 'Updated file';
            
            // Only save if there are actual changes
            $currentStmt = $repoConn->prepare("SELECT content FROM files WHERE id = ?");
            $currentStmt->bind_param("i", $fileId);
            $currentStmt->execute();
            $currentResult = $currentStmt->get_result()->fetch_assoc();
            $currentContent = $currentResult['content'] ?? '';
            
            if ($currentContent !== $content) {
                // Get file details
                $fileStmt = $repoConn->prepare("SELECT file_name, folder FROM files WHERE id = ?");
                $fileStmt->bind_param("i", $fileId);
                $fileStmt->execute();
                $fileResult = $fileStmt->get_result()->fetch_assoc();
                $fileName = $fileResult['file_name'];
                $fileFolder = $fileResult['folder'];
                
                // Save current version to file_versions
                $versionStmt = $repoConn->prepare("INSERT INTO file_versions (file_id, version, content, created_by) 
                                                 SELECT id, (SELECT COALESCE(MAX(version), 0) + 1 FROM file_versions WHERE file_id = files.id), content, ? 
                                                 FROM files WHERE id = ?");
                $versionStmt->bind_param("si", $_SESSION['user_id'], $fileId);
                $versionStmt->execute();
                
                // Update file content
                $updateStmt = $repoConn->prepare("UPDATE files SET content = ?, last_modified_by = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->bind_param("ssi", $content, $_SESSION['user_id'], $fileId);
                
                if ($updateStmt->execute()) {
                    // Add commit only if commit message is provided
                    if (!empty($commitMessage)) {
                        $commitHash = bin2hex(random_bytes(16));
                        $authorName = $user['first_name'] . ' ' . $user['last_name'];
                        
                        $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                        $changes = json_encode(['action' => 'edit', 'file' => $fileName, 'folder' => $fileFolder]);
                        $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                        $commitStmt->execute();
                    }
                    
                    $message = "File saved successfully!";
                    $fileContent = $content;
                    $hasUnsavedChanges = false;
                } else {
                    $error = "Failed to save file: " . $updateStmt->error;
                }
            } else {
                $message = "No changes to save.";
            }
        }
    }
    
    if (isset($_POST['create_file'])) {
        if ($userPermission === 'read') {
            $error = "You don't have write permission for this repository";
        } else {
            $fileName = trim($_POST['file_name']);
            $fileType = $_POST['file_type'];
            $folder = $_POST['folder'] ?? 'root';
            $content = $_POST['file_content'] ?? '';
            
            // Check for duplicate file name
            $checkStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_name = ?");
            $checkStmt->bind_param("ss", $folder, $fileName);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result()->fetch_assoc();
            
            if ($checkResult['count'] > 0) {
                $error = "A file with this name already exists in the selected folder";
            } else {
                $insertStmt = $repoConn->prepare("INSERT INTO files (folder, file_name, file_type, content, created_by) VALUES (?, ?, ?, ?, ?)");
                $insertStmt->bind_param("sssss", $folder, $fileName, $fileType, $content, $_SESSION['user_id']);
                
                if ($insertStmt->execute()) {
                    $currentFileId = $insertStmt->insert_id;
                    
                    // Add commit
                    $commitHash = bin2hex(random_bytes(16));
                    $authorName = $user['first_name'] . ' ' . $user['last_name'];
                    $commitMessage = "Created file: $fileName" . ($folder !== 'root' ? " in $folder" : "");
                    
                    $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                    $changes = json_encode(['action' => 'create', 'file' => $fileName, 'folder' => $folder]);
                    $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                    $commitStmt->execute();
                    
                    $message = "File created successfully!";
                    $filePath = $fileName;
                    
                    // Add to open tabs
                    $fileKey = $fileName . '|' . $fileType;
                    if (!in_array($fileKey, $_SESSION['open_tabs'])) {
                        $_SESSION['open_tabs'][] = $fileKey;
                    }
                    
                    // Redirect to the new file
                    header("Location: code_editor.php?repo=$repoId&file=" . urlencode($fileName) . "&type=$fileType");
                    exit();
                } else {
                    $error = "Failed to create file: " . $insertStmt->error;
                }
            }
        }
    }
    
    if (isset($_POST['create_folder'])) {
        if ($userPermission === 'read') {
            $error = "You don't have write permission for this repository";
        } else {
            $folderName = trim($_POST['folder_name']);
            $parentFolder = $_POST['parent_folder'] ?? 'root';
            
            $newFolder = $parentFolder === 'root' ? $folderName : $parentFolder . '/' . $folderName;
            
            // Check if folder already exists
            $checkStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_name = '.folder' AND file_type = 'folder'");
            $checkStmt->bind_param("s", $newFolder);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result()->fetch_assoc();
            
            if ($checkResult['count'] > 0) {
                $error = "A folder with this name already exists in the selected location";
            } else {
                $insertStmt = $repoConn->prepare("INSERT INTO files (folder, file_name, file_type, created_by) VALUES (?, '.folder', 'folder', ?)");
                $insertStmt->bind_param("ss", $newFolder, $_SESSION['user_id']);
                
                if ($insertStmt->execute()) {
                    // Add commit
                    $commitHash = bin2hex(random_bytes(16));
                    $authorName = $user['first_name'] . ' ' . $user['last_name'];
                    $commitMessage = "Created folder: $folderName" . ($parentFolder !== 'root' ? " in $parentFolder" : "");
                    
                    $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                    $changes = json_encode(['action' => 'create_folder', 'folder' => $folderName, 'path' => $newFolder]);
                    $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                    $commitStmt->execute();
                    
                    $message = "Folder created successfully!";
                    
                    // Refresh the page to show new folder
                    header("Location: code_editor.php?repo=$repoId" . ($filePath ? "&file=" . urlencode($filePath) : ""));
                    exit();
                } else {
                    $error = "Failed to create folder: " . $insertStmt->error;
                }
            }
        }
    }
}

// Get complete file structure for sidebar
$files = [];
$folders = [];

// Get all files and folders
$structureStmt = $repoConn->prepare("SELECT * FROM files ORDER BY folder, file_name");
$structureStmt->execute();
$structureResult = $structureStmt->get_result();

while ($item = $structureResult->fetch_assoc()) {
    if ($item['file_type'] === 'folder') {
        $folders[] = $item;
    } else {
        $files[] = $item;
    }
}

// Get file content if file is specified
if (!empty($filePath)) {
    // Search for the file in all folders
    $fileStmt = $repoConn->prepare("SELECT * FROM files WHERE file_name = ? AND file_type != 'folder'");
    $fileStmt->bind_param("s", $filePath);
    $fileStmt->execute();
    $fileResult = $fileStmt->get_result();
    
    if ($fileResult->num_rows > 0) {
        $fileData = $fileResult->fetch_assoc();
        $fileContent = $fileData['content'] ?? '';
        $currentFileId = $fileData['id'];
        $fileType = $fileData['file_type'];
        $filePath = $fileData['file_name'];
    }
}

// Get repository info
$repoInfoStmt = $reposConn->prepare("SELECT repo_name, description FROM `$tableName` WHERE repo_id = ?");
$repoInfoStmt->bind_param("s", $repoId);
$repoInfoStmt->execute();
$repoInfoResult = $repoInfoStmt->get_result();
$repoInfo = $repoInfoResult->fetch_assoc();

$conn->close();
$reposConn->close();
$repoConn->close();

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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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

function getFileIcon($fileType) {
    $iconMap = [
        'php' => 'fas fa-code text-purple-400',
        'js' => 'fab fa-js-square text-yellow-400',
        'html' => 'fab fa-html5 text-orange-500',
        'css' => 'fab fa-css3-alt text-blue-500',
        'py' => 'fab fa-python text-green-500',
        'java' => 'fab fa-java text-red-500',
        'json' => 'fas fa-brackets-curly text-amber-500',
        'md' => 'fas fa-markdown text-blue-400',
        'txt' => 'fas fa-file-alt text-gray-400',
        'default' => 'fas fa-file text-gray-400'
    ];
    
    return $iconMap[$fileType] ?? $iconMap['default'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeSphere Editor - <?php echo htmlspecialchars($repoId); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.33.0/min/vs/loader.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #c7d2fe;
            --secondary: #8b5cf6;
            --accent: #f59e0b;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --sidebar-bg: #f8fafc;
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.1);
            --shadow-2xl: 0 25px 50px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-hover: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            --glass: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
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
            --sidebar-bg: #0f172a;
            --glass: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
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
            overflow: hidden;
            transition: var(--transition);
        }

        .editor-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: var(--light);
        }

        /* Enhanced Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2rem;
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            z-index: 100;
            backdrop-filter: blur(10px);
            background: var(--glass);
            border-bottom: 1px solid var(--glass-border);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius);
            background: var(--glass);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .repo-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .repo-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 1.25rem;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .file-path {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .permission-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
        }

        .permission-read {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2) 0%, rgba(239, 68, 68, 0.1) 100%);
            color: var(--error);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .permission-write {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2) 0%, rgba(245, 158, 11, 0.1) 100%);
            color: var(--accent);
            border-color: rgba(245, 158, 11, 0.3);
        }

        .permission-admin {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(16, 185, 129, 0.1) 100%);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: var(--shadow-2xl);
            background: var(--gradient-hover);
        }

        .btn-secondary {
            background: var(--glass);
            color: var(--dark);
            box-shadow: var(--shadow);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: var(--shadow-2xl);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error) 0%, #dc2626 100%);
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .btn-danger:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: var(--shadow-2xl);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Enhanced Main Content */
        .main-content {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        /* Glass Sidebar */
        .sidebar {
            width: 320px;
            background: var(--glass);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            backdrop-filter: blur(20px);
            position: relative;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--glass) 0%, transparent 100%);
            pointer-events: none;
        }

        .sidebar.collapsed {
            width: 80px;
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--glass);
            backdrop-filter: blur(20px);
        }

        .sidebar-toggle {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--dark);
            cursor: pointer;
            padding: 0.75rem;
            border-radius: var(--radius);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .sidebar-toggle:hover {
            background: var(--primary);
            color: white;
            transform: rotate(180deg);
        }

        .file-explorer {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .file-explorer::-webkit-scrollbar {
            width: 6px;
        }

        .file-explorer::-webkit-scrollbar-track {
            background: var(--glass);
        }

        .file-explorer::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        .file-tree {
            list-style: none;
        }

        .file-item, .folder-item {
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .file-item::before, .folder-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, var(--primary-light), transparent);
            transition: left 0.5s;
            opacity: 0.1;
        }

        .file-item:hover::before, .folder-item:hover::before {
            left: 100%;
        }

        .file-item:hover, .folder-item:hover {
            background: var(--glass);
            border-color: var(--glass-border);
            transform: translateX(8px) scale(1.02);
            box-shadow: var(--shadow-lg);
        }

        .file-item.active {
            background: var(--primary-light);
            color: var(--primary-dark);
            border-color: var(--primary);
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .file-icon, .folder-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
        }

        .folder-icon {
            color: #f59e0b;
        }

        .folder-item.expanded .folder-icon {
            transform: rotate(90deg) scale(1.1);
        }

        .folder-contents {
            margin-left: 2rem;
            border-left: 2px solid var(--primary-light);
            padding-left: 1rem;
            animation: slideDown 0.3s ease-out;
        }

        .file-name {
            flex: 1;
            font-size: 0.95rem;
            font-weight: 500;
            position: relative;
            z-index: 2;
        }

        /* Enhanced Editor Area */
        .editor-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--light);
        }

        .editor-tabs {
            display: flex;
            background: var(--glass);
            border-bottom: 1px solid var(--glass-border);
            overflow-x: auto;
            backdrop-filter: blur(10px);
            min-height: 60px;
            align-items: center;
        }

        .editor-tabs-container {
            display: flex;
            flex: 1;
            overflow-x: auto;
        }

        .editor-tab {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: var(--glass);
            border-right: 1px solid var(--glass-border);
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
            max-width: 300px;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .editor-tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, var(--primary-light), transparent);
            transition: left 0.5s;
            opacity: 0.1;
        }

        .editor-tab:hover::before {
            left: 100%;
        }

        .editor-tab.active {
            background: var(--primary-light);
            border-bottom: 3px solid var(--primary);
            transform: translateY(1px);
        }

        .editor-tab:hover:not(.active) {
            background: var(--glass);
            transform: translateY(-2px);
        }

        .tab-close {
            margin-left: auto;
            opacity: 0.7;
            transition: all 0.3s ease;
            padding: 0.25rem;
            border-radius: 4px;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tab-close:hover {
            opacity: 1;
            color: var(--error);
            background: rgba(239, 68, 68, 0.1);
            transform: scale(1.1);
        }

        .editor-wrapper {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: var(--card-bg);
        }

        .editor-container-multi {
            display: none;
            width: 100%;
            height: 100%;
        }

        .editor-container-multi.active {
            display: block;
        }

        #editor {
            width: 100%;
            height: 100%;
        }

        .close-all-tabs {
            padding: 0.5rem 1rem;
            margin: 0 1rem;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--gray);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .close-all-tabs:hover {
            background: var(--error);
            color: white;
            transform: scale(1.05);
        }

        /* Enhanced Status Bar */
        .status-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2rem;
            background: var(--glass);
            border-top: 1px solid var(--glass-border);
            font-size: 0.85rem;
            color: var(--gray);
            backdrop-filter: blur(10px);
        }

        .status-left, .status-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--glass);
            border-radius: var(--radius);
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }

        .status-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Enhanced Terminal */
        .terminal {
            height: 280px;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #00ff88;
            border-top: 1px solid #333;
            display: flex;
            flex-direction: column;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .terminal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 0%, rgba(0, 255, 136, 0.1) 100%);
            pointer-events: none;
        }

        .terminal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            background: rgba(0, 0, 0, 0.8);
            border-bottom: 1px solid #333;
            backdrop-filter: blur(10px);
        }

        .terminal-title {
            font-weight: 600;
            color: #00ff88;
            font-size: 1rem;
        }

        .terminal-actions {
            display: flex;
            gap: 0.5rem;
        }

        .terminal-content {
            flex: 1;
            padding: 1.5rem;
            font-family: 'Fira Code', 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-y: auto;
            background: #000;
            line-height: 1.8;
        }

        .terminal-content::-webkit-scrollbar {
            width: 8px;
        }

        .terminal-content::-webkit-scrollbar-track {
            background: #1a1a1a;
        }

        .terminal-content::-webkit-scrollbar-thumb {
            background: #00ff88;
            border-radius: 10px;
        }

        .terminal-input {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: rgba(0, 0, 0, 0.9);
            border-top: 1px solid #333;
        }

        .terminal-prompt {
            color: #00ff88;
            font-weight: 600;
            font-family: 'Fira Code', monospace;
        }

        .terminal-input-field {
            flex: 1;
            background: transparent;
            border: none;
            color: #00ff88;
            font-family: 'Fira Code', monospace;
            font-size: 0.9rem;
            outline: none;
            padding: 0.5rem 0;
        }

        /* Enhanced Modals */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-2xl);
            transform: scale(0.8) translateY(50px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(20px);
            background: var(--glass);
        }

        .modal-overlay.active .modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .modal-header {
            padding: 2rem 2rem 1rem;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-body {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 2rem;
            animation: slideUp 0.5s ease-out;
        }

        .form-label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.25rem;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            background: var(--glass);
            color: var(--dark);
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2.5rem;
        }

        /* Enhanced Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--gradient);
            color: white;
            border: none;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            z-index: 1000;
            box-shadow: var(--shadow-2xl);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            border: 2px solid var(--glass-border);
        }

        .theme-toggle:hover {
            transform: scale(1.1) rotate(15deg);
            box-shadow: var(--shadow-2xl);
        }

        /* Enhanced Notifications */
        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            padding: 1.5rem 2rem;
            background: var(--success);
            color: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-2xl);
            z-index: 10000;
            animation: slideInRight 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            max-width: 400px;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification.error {
            background: linear-gradient(135deg, var(--error) 0%, #dc2626 100%);
        }

        .notification.warning {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        }

        .notification i {
            font-size: 1.5rem;
        }

        /* Animations */
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-50px);
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.3);
            }
            50% {
                opacity: 1;
                transform: scale(1.05);
            }
            70% {
                transform: scale(0.9);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Loading */
        .loading {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                position: absolute;
                top: 0;
                left: 0;
                height: 100%;
                z-index: 100;
                transform: translateX(-100%);
                box-shadow: var(--shadow-2xl);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .header {
                padding: 1rem;
            }
            
            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.8rem;
            }
        }

        /* File Explorer Actions */
        .explorer-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--glass);
            border-radius: var(--radius);
            border: 1px solid var(--glass-border);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray);
            animation: fadeIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
            animation: bounceIn 1s ease-out;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .empty-state p {
            font-size: 1rem;
            line-height: 1.6;
        }

        /* Unsaved Changes Indicator */
        .unsaved-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            margin-left: 0.5rem;
            animation: pulse 2s infinite;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--glass);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="editor-container">
        <!-- Enhanced Header -->
        <div class="header">
            <div class="header-left">
                <a href="repo_view.php?repo=<?php echo $repoId; ?>" class="back-btn">
                    <ion-icon name="arrow-back-outline"></ion-icon>
                    <span>Repository</span>
                </a>
                <div class="repo-info">
                    <span class="repo-name"><?php echo htmlspecialchars($repoInfo['repo_name'] ?? $repoId); ?></span>
                    <span class="file-path">/<?php echo htmlspecialchars($filePath ?: 'No file selected'); ?></span>
                    <?php if ($hasUnsavedChanges): ?>
                        <span class="unsaved-indicator" title="Unsaved changes"></span>
                    <?php endif; ?>
                    <span class="permission-badge permission-<?php echo $userPermission; ?>">
                        <i class="fas fa-shield-alt"></i>
                        <?php echo ucfirst($userPermission); ?>
                    </span>
                </div>
            </div>
            <div class="header-right">
                <button class="btn btn-secondary" id="sidebarToggle" title="Toggle Explorer">
                    <i class="fas fa-folder-tree"></i>
                    <span>Explorer</span>
                </button>
                <button class="btn btn-secondary" id="searchToggle" title="Search">
                    <i class="fas fa-search"></i>
                    <span>Search</span>
                </button>
                <button class="btn btn-secondary" id="terminalToggle" title="Toggle Terminal">
                    <i class="fas fa-terminal"></i>
                    <span>Terminal</span>
                </button>
                <button class="btn btn-success" id="saveBtn" <?php echo $userPermission === 'read' ? 'disabled' : ''; ?> title="Save (Ctrl+S)">
                    <ion-icon name="save-outline"></ion-icon>
                    <span>Save</span>
                </button>
                <button class="btn btn-primary" id="runBtn" title="Run Code">
                    <i class="fas fa-play-circle"></i>
                    <span>Run</span>
                </button>
            </div>
        </div>

        <!-- Enhanced Main Content -->
        <div class="main-content">
            <!-- Glass Sidebar -->
            <div class="sidebar active" id="sidebar">
                <div class="sidebar-header">
                    <h3 style="font-weight: 700; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                        <i class="fas fa-folder-open"></i>
                        <span>Explorer</span>
                    </h3>
                    <button class="sidebar-toggle" id="sidebarCollapse" title="Collapse Sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                </div>
                <div class="file-explorer">
                    <div class="explorer-actions">
                        <button class="btn btn-secondary" onclick="openFileModal()" <?php echo $userPermission === 'read' ? 'disabled' : ''; ?> title="New File">
                            <i class="fas fa-file-plus"></i>
                            <span>File</span>
                        </button>
                        <button class="btn btn-secondary" onclick="openFolderModal()" <?php echo $userPermission === 'read' ? 'disabled' : ''; ?> title="New Folder">
                            <i class="fas fa-folder-plus"></i>
                            <span>Folder</span>
                        </button>
                    </div>
                    <ul class="file-tree" id="fileTree">
                        <!-- File tree will be populated by JavaScript -->
                    </ul>
                </div>
            </div>

            <!-- Enhanced Editor Area -->
            <div class="editor-area">
                <!-- Enhanced Editor Tabs with Multiple Files -->
                <div class="editor-tabs">
                    <div class="editor-tabs-container" id="editorTabsContainer">
                        <?php 
                        // Display all open tabs
                        foreach ($_SESSION['open_tabs'] as $tabKey): 
                            list($tabFile, $tabType) = explode('|', $tabKey);
                            $isActive = ($tabFile === $filePath && $tabType === $fileType);
                        ?>
                        <div class="editor-tab <?php echo $isActive ? 'active' : ''; ?>" 
                             data-file="<?php echo htmlspecialchars($tabFile); ?>" 
                             data-type="<?php echo htmlspecialchars($tabType); ?>">
                            <i class="<?php echo getFileIcon($tabType); ?>"></i>
                            <span><?php echo htmlspecialchars($tabFile); ?></span>
                            <?php if ($isActive && $hasUnsavedChanges): ?>
                                <span class="unsaved-indicator"></span>
                            <?php endif; ?>
                            <button class="tab-close" 
                                    onclick="closeTab('<?php echo htmlspecialchars($tabKey); ?>')"
                                    title="Close Tab">
                                <ion-icon name="close-outline"></ion-icon>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($_SESSION['open_tabs'])): ?>
                    <button class="close-all-tabs" onclick="closeAllTabs()" title="Close All Tabs">
                        <i class="fas fa-times-circle"></i>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Editor Content Area -->
                <div class="editor-wrapper">
                    <?php if (!empty($filePath)): ?>
                    <div id="editor" class="editor-container-multi active"></div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-code"></i>
                        <h3>Welcome to CodeSphere Editor</h3>
                        <p>Select a file from the explorer or create a new one to start coding</p>
                        <button class="btn btn-primary" onclick="openFileModal()" style="margin-top: 2rem;">
                            <ion-icon name="add-outline"></ion-icon>
                            Create New File
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Enhanced Terminal -->
                <div class="terminal" id="terminal" style="display: none;">
                    <div class="terminal-header">
                        <div class="terminal-title">
                            <i class="fas fa-terminal"></i>
                            Integrated Terminal
                        </div>
                        <div class="terminal-actions">
                            <button class="btn btn-secondary" style="padding: 0.5rem; background: rgba(0,255,136,0.2);">
                                <ion-icon name="add-outline"></ion-icon>
                            </button>
                            <button class="btn btn-secondary" style="padding: 0.5rem; background: rgba(239,68,68,0.2);" onclick="clearTerminal()">
                                <ion-icon name="trash-outline"></ion-icon>
                            </button>
                        </div>
                    </div>
                    <div class="terminal-content" id="terminalContent">
                        <div>> Welcome to CodeSphere Terminal</div>
                        <div>> Type 'help' for available commands</div>
                        <div>> </div>
                    </div>
                    <div class="terminal-input">
                        <span class="terminal-prompt">$</span>
                        <input type="text" class="terminal-input-field" placeholder="Type a command..." id="terminalInput">
                    </div>
                </div>

                <!-- Enhanced Status Bar -->
                <div class="status-bar">
                    <div class="status-left">
                        <div class="status-item">
                            <i class="fas fa-code-branch"></i>
                            <span>main</span>
                        </div>
                        <div class="status-item">
                            <i class="fas fa-cloud" style="color: var(--success);"></i>
                            <span>Synced</span>
                        </div>
                        <?php if ($hasUnsavedChanges): ?>
                        <div class="status-item" style="color: var(--accent);">
                            <i class="fas fa-circle"></i>
                            <span>Unsaved Changes</span>
                        </div>
                        <?php endif; ?>
                        <div class="status-item">
                            <i class="fas fa-folder-open"></i>
                            <span><?php echo count($_SESSION['open_tabs']); ?> tabs open</span>
                        </div>
                    </div>
                    <div class="status-right">
                        <div class="status-item">
                            <i class="fas fa-code"></i>
                            <span id="cursorPosition">Ln 1, Col 1</span>
                        </div>
                        <div class="status-item">
                            <i class="fas fa-indent"></i>
                            <span>Spaces: 2</span>
                        </div>
                        <div class="status-item">
                            <i class="fas fa-font"></i>
                            <span>UTF-8</span>
                        </div>
                        <div class="status-item">
                            <i class="fas fa-language"></i>
                            <span id="fileLanguage">Plain Text</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Create File Modal -->
    <div class="modal-overlay" id="fileModal">
        <div class="modal">
            <div class="modal-header">
                <h3 style="background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                    <i class="fas fa-file-plus"></i>
                    Create New File
                </h3>
                <button onclick="closeFileModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--gray); cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: all 0.3s ease;">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createFileForm">
                    <input type="hidden" name="create_file" value="1">
                    <div class="form-group">
                        <label class="form-label">File Name</label>
                        <input type="text" name="file_name" class="form-control" placeholder="example.php" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">File Type</label>
                        <select name="file_type" class="form-control" required>
                            <option value="txt">Text File</option>
                            <option value="php">PHP</option>
                            <option value="js">JavaScript</option>
                            <option value="html">HTML</option>
                            <option value="css">CSS</option>
                            <option value="py">Python</option>
                            <option value="java">Java</option>
                            <option value="json">JSON</option>
                            <option value="md">Markdown</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Folder</label>
                        <select name="folder" class="form-control" id="fileFolderSelect">
                            <option value="root">Root Directory</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Initial Content (Optional)</label>
                        <textarea name="file_content" class="form-control" rows="6" placeholder="Start writing your code here..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeFileModal()">
                            <ion-icon name="close-outline"></ion-icon>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <ion-icon name="add-outline"></ion-icon>
                            Create File
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Create Folder Modal -->
    <div class="modal-overlay" id="folderModal">
        <div class="modal">
            <div class="modal-header">
                <h3 style="background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                    <i class="fas fa-folder-plus"></i>
                    Create New Folder
                </h3>
                <button onclick="closeFolderModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--gray); cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: all 0.3s ease;">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createFolderForm">
                    <input type="hidden" name="create_folder" value="1">
                    <div class="form-group">
                        <label class="form-label">Folder Name</label>
                        <input type="text" name="folder_name" class="form-control" placeholder="my-folder" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Parent Folder</label>
                        <select name="parent_folder" class="form-control" id="folderParentSelect">
                            <option value="root">Root Directory</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeFolderModal()">
                            <ion-icon name="close-outline"></ion-icon>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <ion-icon name="add-outline"></ion-icon>
                            Create Folder
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Save Modal -->
    <div class="modal-overlay" id="saveModal">
        <div class="modal">
            <div class="modal-header">
                <h3 style="background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                    <ion-icon name="save-outline"></ion-icon>
                    Save Changes
                </h3>
                <button onclick="closeSaveModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--gray); cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: all 0.3s ease;">
                    <ion-icon name="close-outline"></ion-icon>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="saveFileForm">
                    <input type="hidden" name="save_file" value="1">
                    <input type="hidden" name="file_id" id="saveFileId" value="<?php echo $currentFileId; ?>">
                    <div class="form-group">
                        <label class="form-label">Commit Message (Optional)</label>
                        <input type="text" name="commit_message" class="form-control" placeholder="Describe your changes... (Leave empty for no commit)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">File Content</label>
                        <textarea name="content" class="form-control" rows="12" id="saveFileContent" readonly style="background: var(--light);"></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeSaveModal()">
                            <ion-icon name="close-outline"></ion-icon>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <ion-icon name="save-outline"></ion-icon>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Theme Toggle -->
    <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
        <i class="fas fa-moon"></i>
    </button>

    <script>
        // Monaco Editor Initialization
        require.config({ paths: { 'vs': 'https://cdn.jsdelivr.net/npm/monaco-editor@0.33.0/min/vs' }});
        
        let editor;
        let currentFile = '<?php echo $filePath; ?>';
        let currentFileType = '<?php echo $fileType; ?>';
        let userPermission = '<?php echo $userPermission; ?>';
        let repoId = '<?php echo $repoId; ?>';
        let hasUnsavedChanges = <?php echo $hasUnsavedChanges ? 'true' : 'false'; ?>;
        let editors = {}; // Store multiple editor instances
        
        // File structure from PHP
        const fileStructure = {
            files: <?php echo json_encode($files); ?>,
            folders: <?php echo json_encode($folders); ?>
        };

        <?php if (!empty($filePath)): ?>
        require(['vs/editor/editor.main'], function() {
            initializeEditor(currentFile, currentFileType, `<?php echo addslashes($fileContent); ?>`);
        });
        <?php endif; ?>

        function initializeEditor(fileName, fileType, content) {
            const languageMap = {
                'php': 'php',
                'js': 'javascript',
                'html': 'html',
                'css': 'css',
                'py': 'python',
                'java': 'java',
                'json': 'json',
                'md': 'markdown',
                'txt': 'plaintext'
            };
            
            const language = languageMap[fileType] || 'plaintext';
            const editorId = `editor-${fileName.replace(/[^a-zA-Z0-9]/g, '-')}`;
            
            // Create editor container if it doesn't exist
            let editorContainer = document.getElementById(editorId);
            if (!editorContainer) {
                editorContainer = document.createElement('div');
                editorContainer.id = editorId;
                editorContainer.className = 'editor-container-multi';
                document.querySelector('.editor-wrapper').appendChild(editorContainer);
            }
            
            // Hide all editors and show current one
            document.querySelectorAll('.editor-container-multi').forEach(editor => {
                editor.classList.remove('active');
            });
            editorContainer.classList.add('active');
            
            // Initialize Monaco editor
            editor = monaco.editor.create(editorContainer, {
                value: content,
                language: language,
                theme: localStorage.getItem('editorTheme') || 'vs',
                fontSize: parseInt(localStorage.getItem('fontSize')) || 16,
                lineNumbers: 'on',
                roundedSelection: false,
                scrollBeyondLastLine: false,
                readOnly: userPermission === 'read',
                cursorStyle: 'line',
                automaticLayout: true,
                minimap: {
                    enabled: localStorage.getItem('minimap') !== 'false'
                },
                wordWrap: localStorage.getItem('wordWrap') === 'true' ? 'on' : 'off',
                fontFamily: "'Fira Code', 'Courier New', monospace",
                fontLigatures: true,
                smoothScrolling: true,
                mouseWheelZoom: true
            });
            
            // Store editor instance
            editors[fileName] = {
                instance: editor,
                originalContent: content,
                hasUnsavedChanges: false
            };
            
            // Track changes for unsaved changes indicator
            editor.onDidChangeModelContent(function() {
                const currentContent = editor.getValue();
                editors[fileName].hasUnsavedChanges = currentContent !== editors[fileName].originalContent;
                updateUnsavedChangesIndicator(fileName);
            });
            
            // Update status bar with cursor position
            editor.onDidChangeCursorPosition(function(e) {
                const position = e.position;
                document.getElementById('cursorPosition').textContent = 
                    `Ln ${position.lineNumber}, Col ${position.column}`;
            });
            
            // Update file language in status bar
            document.getElementById('fileLanguage').textContent = 
                language.charAt(0).toUpperCase() + language.slice(1);
        }

        function updateUnsavedChangesIndicator(fileName) {
            const tab = document.querySelector(`.editor-tab[data-file="${fileName}"]`);
            if (tab) {
                const existingIndicator = tab.querySelector('.unsaved-indicator');
                if (editors[fileName]?.hasUnsavedChanges) {
                    if (!existingIndicator) {
                        const indicator = document.createElement('span');
                        indicator.className = 'unsaved-indicator';
                        tab.appendChild(indicator);
                    }
                } else {
                    if (existingIndicator) {
                        existingIndicator.remove();
                    }
                }
            }
        }

        // Tab management functions
        function openFileInTab(fileName, fileType) {
            const fileKey = fileName + '|' + fileType;
            
            // Add to session tabs if not already present
            if (!<?php echo json_encode($_SESSION['open_tabs']); ?>.includes(fileKey)) {
                // Use AJAX to add to session
                fetch(`tab_manager.php?action=add_tab&repo=<?php echo $repoId; ?>&file=${encodeURIComponent(fileName)}&type=${fileType}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Redirect to open the file
                            window.location.href = `code_editor.php?repo=<?php echo $repoId; ?>&file=${encodeURIComponent(fileName)}&type=${fileType}`;
                        }
                    });
            } else {
                // Just switch to the existing tab
                window.location.href = `code_editor.php?repo=<?php echo $repoId; ?>&file=${encodeURIComponent(fileName)}&type=${fileType}`;
            }
        }

        function closeTab(tabKey) {
            if (confirm('Are you sure you want to close this tab?')) {
                window.location.href = `code_editor.php?repo=<?php echo $repoId; ?>&close_tab=${encodeURIComponent(tabKey)}`;
            }
        }

        function closeAllTabs() {
            if (confirm('Are you sure you want to close all tabs?')) {
                fetch(`tab_manager.php?action=close_all_tabs&repo=<?php echo $repoId; ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = `code_editor.php?repo=<?php echo $repoId; ?>`;
                        }
                    });
            }
        }

        // Enhanced File Tree with proper folder structure
        function renderFileTree() {
            const fileTree = document.getElementById('fileTree');
            fileTree.innerHTML = '';
            
            // Build proper hierarchical structure
            const structure = buildFileStructure();
            
            // Render everything starting from root
            renderFolder('root', structure, fileTree, 0);
        }

        function buildFileStructure() {
            const structure = {
                name: 'root',
                folders: {},
                files: []
            };
            
            // Process all folders and build hierarchy
            fileStructure.folders.forEach(folder => {
                const path = folder.folder;
                if (path === 'root') return;
                
                const parts = path.split('/').filter(p => p);
                let current = structure;
                
                parts.forEach((part, index) => {
                    if (!current.folders[part]) {
                        current.folders[part] = {
                            name: part,
                            folders: {},
                            files: [],
                            fullPath: parts.slice(0, index + 1).join('/')
                        };
                    }
                    current = current.folders[part];
                });
            });
            
            // Process all files and place them in correct folders
            fileStructure.files.forEach(file => {
                if (file.folder === 'root') {
                    structure.files.push(file);
                } else {
                    const parts = file.folder.split('/').filter(p => p);
                    let current = structure;
                    
                    // Navigate to the correct folder
                    parts.forEach(part => {
                        if (current.folders[part]) {
                            current = current.folders[part];
                        }
                    });
                    
                    // Add file to current folder
                    current.files.push(file);
                }
            });
            
            return structure;
        }

        function renderFolder(folderName, folderData, parentElement, level = 0) {
            // Render files in current folder first
            if (folderData.files && folderData.files.length > 0) {
                folderData.files.forEach(file => {
                    const fileItem = createFileElement(file, level);
                    parentElement.appendChild(fileItem);
                });
            }
            
            // Render subfolders
            if (folderData.folders && Object.keys(folderData.folders).length > 0) {
                Object.keys(folderData.folders).forEach(folderKey => {
                    const folder = folderData.folders[folderKey];
                    const folderItem = createFolderElement(folder, level);
                    parentElement.appendChild(folderItem);
                    
                    const folderContents = document.createElement('div');
                    folderContents.className = 'folder-contents';
                    folderContents.style.display = 'none';
                    
                    // Recursively render contents of this folder
                    renderFolder(folder.name, folder, folderContents, level + 1);
                    
                    parentElement.appendChild(folderContents);
                });
            }
        }

        function createFileElement(file, level) {
            const fileItem = document.createElement('li');
            fileItem.className = 'file-item';
            fileItem.style.paddingLeft = (level * 1.5) + 'rem';
            
            if (file.file_name === currentFile) {
                fileItem.classList.add('active');
            }
            
            const fileIcon = getFileIconClass(file.file_type);
            
            fileItem.innerHTML = `
                <i class="${fileIcon}"></i>
                <span class="file-name">${file.file_name}</span>
            `;
            
            fileItem.addEventListener('click', function(e) {
                e.stopPropagation();
                openFileInTab(file.file_name, file.file_type);
            });
            
            return fileItem;
        }

        function createFolderElement(folder, level) {
            const folderItem = document.createElement('li');
            folderItem.className = 'folder-item';
            folderItem.style.paddingLeft = (level * 1.5) + 'rem';
            
            const hasContents = (folder.files && folder.files.length > 0) || 
                              (folder.folders && Object.keys(folder.folders).length > 0);
            
            folderItem.innerHTML = `
                <i class="fas fa-chevron-right folder-icon ${hasContents ? 'has-contents' : 'no-contents'}"></i>
                <i class="fas fa-folder folder-icon" style="color: #f59e0b;"></i>
                <span class="file-name">${folder.name}</span>
            `;
            
            if (hasContents) {
                folderItem.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const wasExpanded = this.classList.contains('expanded');
                    const contents = this.nextElementSibling;
                    
                    if (!wasExpanded) {
                        this.classList.add('expanded');
                        contents.style.display = 'block';
                        gsap.fromTo(contents, 
                            { height: 0, opacity: 0 },
                            { height: 'auto', opacity: 1, duration: 0.4, ease: "power2.out" }
                        );
                    } else {
                        this.classList.remove('expanded');
                        gsap.to(contents, {
                            height: 0,
                            opacity: 0,
                            duration: 0.3,
                            ease: "power2.in",
                            onComplete: () => contents.style.display = 'none'
                        });
                    }
                });
            }
            
            return folderItem;
        }

        function getFileIconClass(fileType) {
            const iconMap = {
                'php': 'fas fa-code text-purple-400',
                'js': 'fab fa-js-square text-yellow-400',
                'html': 'fab fa-html5 text-orange-500',
                'css': 'fab fa-css3-alt text-blue-500',
                'py': 'fab fa-python text-green-500',
                'java': 'fab fa-java text-red-500',
                'json': 'fas fa-brackets-curly text-amber-500',
                'md': 'fas fa-markdown text-blue-400',
                'txt': 'fas fa-file-alt text-gray-400',
                'default': 'fas fa-file text-gray-400'
            };
            
            return iconMap[fileType] || iconMap['default'];
        }

        // Enhanced UI Controls with GSAP animations
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
            
            gsap.to(sidebar, {
                x: sidebar.classList.contains('active') ? 0 : -320,
                duration: 0.5,
                ease: "power3.out"
            });
        });

        document.getElementById('searchToggle').addEventListener('click', function() {
            // Implement search functionality
            showNotification('Search functionality coming soon!', 'warning');
        });

        document.getElementById('terminalToggle').addEventListener('click', function() {
            const terminal = document.getElementById('terminal');
            const isVisible = terminal.style.display !== 'none';
            
            if (isVisible) {
                gsap.to(terminal, {
                    height: 0,
                    duration: 0.4,
                    ease: "power2.inOut",
                    onComplete: () => terminal.style.display = 'none'
                });
            } else {
                terminal.style.display = 'flex';
                gsap.fromTo(terminal, 
                    { height: 0 },
                    { height: 280, duration: 0.4, ease: "power2.out" }
                );
            }
        });

        document.getElementById('sidebarCollapse').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            const icon = this.querySelector('i');
            
            gsap.to(sidebar, {
                width: sidebar.classList.contains('collapsed') ? 80 : 320,
                duration: 0.5,
                ease: "power3.out"
            });
            
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
                gsap.to(icon, { rotation: 0, duration: 0.3 });
            } else {
                icon.className = 'fas fa-chevron-left';
                gsap.to(icon, { rotation: 0, duration: 0.3 });
            }
        });

        document.getElementById('saveBtn').addEventListener('click', function() {
            if (userPermission === 'read') {
                showNotification('You only have read access to this repository', 'error');
                return;
            }
            
            if (!editor) {
                showNotification('No file is currently open for editing', 'error');
                return;
            }
            
            openSaveModal();
        });

        document.getElementById('runBtn').addEventListener('click', function() {
            const originalText = this.innerHTML;
            this.innerHTML = '<div class="loading"></div> Running...';
            this.disabled = true;
            
            // Animate the button
            gsap.to(this, {
                scale: 0.95,
                duration: 0.2,
                yoyo: true,
                repeat: 1
            });
            
            setTimeout(() => {
                this.innerHTML = originalText;
                this.disabled = false;
                
                // Show terminal output
                const terminalContent = document.getElementById('terminalContent');
                const newEntry = document.createElement('div');
                newEntry.innerHTML = `<div>> Running ${currentFile}...</div>`;
                terminalContent.appendChild(newEntry);
                
                // Show terminal if hidden
                const terminal = document.getElementById('terminal');
                if (terminal.style.display === 'none') {
                    terminal.style.display = 'flex';
                    gsap.fromTo(terminal, 
                        { height: 0 },
                        { height: 280, duration: 0.4, ease: "power2.out" }
                    );
                }
                
                // Simulate code execution
                setTimeout(() => {
                    const outputEntry = document.createElement('div');
                    outputEntry.innerHTML = `<div>> Execution completed successfully!</div><div>> </div>`;
                    terminalContent.appendChild(outputEntry);
                    terminalContent.scrollTop = terminalContent.scrollHeight;
                }, 1000);
                
                showNotification('Code executed successfully!');
            }, 1500);
        });

        // Enhanced Theme Toggle with animation
        const themeToggle = document.getElementById('themeToggle');
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            const icon = themeToggle.querySelector('i');
            
            // Animate the toggle
            gsap.to(themeToggle, {
                rotation: 360,
                duration: 0.5,
                ease: "power2.out"
            });
            
            if (document.body.classList.contains('dark-mode')) {
                icon.className = 'fas fa-sun';
                localStorage.setItem('theme', 'dark');
                if (editor) {
                    monaco.editor.setTheme('vs-dark');
                }
            } else {
                icon.className = 'fas fa-moon';
                localStorage.setItem('theme', 'light');
                if (editor) {
                    monaco.editor.setTheme('vs');
                }
            }
            
            // Reset rotation after animation
            setTimeout(() => {
                gsap.set(themeToggle, { rotation: 0 });
            }, 500);
        });

        // Set initial theme
        if (localStorage.getItem('theme') === 'dark' || 
            (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
            themeToggle.querySelector('i').className = 'fas fa-sun';
        }

        // Enhanced Modal functions with animations
        function openFileModal() {
            if (userPermission === 'read') {
                showNotification('You only have read access to this repository', 'error');
                return;
            }
            
            populateFolderSelect('fileFolderSelect');
            showModal('fileModal');
        }

        function closeFileModal() {
            hideModal('fileModal');
        }

        function openFolderModal() {
            if (userPermission === 'read') {
                showNotification('You only have read access to this repository', 'error');
                return;
            }
            
            populateFolderSelect('folderParentSelect');
            showModal('folderModal');
        }

        function closeFolderModal() {
            hideModal('folderModal');
        }

        function openSaveModal() {
            if (!editor) return;
            
            document.getElementById('saveFileContent').value = editor.getValue();
            document.getElementById('saveFileId').value = '<?php echo $currentFileId; ?>';
            showModal('saveModal');
        }

        function closeSaveModal() {
            hideModal('saveModal');
        }

        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('active');
            
            gsap.fromTo(modal, 
                { scale: 0.8, opacity: 0, y: 50 },
                { scale: 1, opacity: 1, y: 0, duration: 0.5, ease: "back.out(1.7)" }
            );
        }

        function hideModal(modalId) {
            const modal = document.getElementById(modalId);
            
            gsap.to(modal, {
                scale: 0.8,
                opacity: 0,
                y: 50,
                duration: 0.3,
                ease: "power2.in",
                onComplete: () => modal.classList.remove('active')
            });
        }

        function populateFolderSelect(selectId) {
            const select = document.getElementById(selectId);
            select.innerHTML = '<option value="root">Root Directory</option>';
            
            // Add all folders to the select
            fileStructure.folders.forEach(folder => {
                if (folder.folder !== 'root') {
                    const option = document.createElement('option');
                    option.value = folder.folder;
                    option.textContent = folder.folder;
                    select.appendChild(option);
                }
            });
        }

        // Enhanced Terminal functionality
        document.getElementById('terminalInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const command = this.value.trim();
                if (command) {
                    const terminalContent = document.getElementById('terminalContent');
                    const commandEntry = document.createElement('div');
                    commandEntry.innerHTML = `<div>$ ${command}</div>`;
                    terminalContent.appendChild(commandEntry);
                    
                    // Process command
                    let output = processTerminalCommand(command);
                    if (output) {
                        const outputEntry = document.createElement('div');
                        outputEntry.innerHTML = output;
                        terminalContent.appendChild(outputEntry);
                    }
                    
                    const promptEntry = document.createElement('div');
                    promptEntry.innerHTML = `<div>$ </div>`;
                    terminalContent.appendChild(promptEntry);
                    
                    terminalContent.scrollTop = terminalContent.scrollHeight;
                    
                    // Clear input
                    this.value = '';
                }
            }
        });

        function clearTerminal() {
            const terminalContent = document.getElementById('terminalContent');
            gsap.to(terminalContent, {
                opacity: 0,
                duration: 0.3,
                onComplete: () => {
                    terminalContent.innerHTML = '<div>> Terminal cleared</div><div>> </div>';
                    gsap.to(terminalContent, { opacity: 1, duration: 0.3 });
                }
            });
        }

        function processTerminalCommand(command) {
            switch(command.toLowerCase()) {
                case 'help':
                    return `<div>Available commands:</div>
<div>- help: Show this help message</div>
<div>- ls: List files</div>
<div>- clear: Clear terminal</div>
<div>- run: Execute current file</div>
<div>- git status: Check git status</div>`;
                    
                case 'ls':
                    let output = '<div>Files and folders:</div>';
                    // Show root files
                    fileStructure.files.filter(f => f.folder === 'root').forEach(file => {
                        const icon = getFileIconClass(file.file_type).split(' ')[2];
                        output += `<div><span class="${icon}">  ${file.file_name}</span></div>`;
                    });
                    // Show folders
                    fileStructure.folders.filter(f => f.folder !== 'root').forEach(folder => {
                        output += `<div><span style="color: #f59e0b">  📁 ${folder.folder.split('/').pop()}</span></div>`;
                    });
                    return output || '<div>No files in directory</div>';
                    
                case 'clear':
                    clearTerminal();
                    return '';
                    
                case 'run':
                    document.getElementById('runBtn').click();
                    return '<div>Running current file...</div>';
                    
                case 'git status':
                    return `<div>On branch main</div>
<div>Changes not staged for commit:</div>
<div>  modified:   ${currentFile || 'No file'}</div>`;
                    
                default:
                    return `<div>Command not found: ${command}</div><div>Type 'help' for available commands</div>`;
            }
        }

        // Enhanced Notification system
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            document.querySelectorAll('.notification').forEach(notification => {
                gsap.to(notification, {
                    x: 300,
                    opacity: 0,
                    duration: 0.3,
                    ease: "power2.in",
                    onComplete: () => notification.remove()
                });
            });
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            const icon = type === 'error' ? 'fas fa-exclamation-triangle' : 
                        type === 'warning' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
            
            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            // Animate in
            gsap.fromTo(notification, 
                { x: 300, opacity: 0 },
                { x: 0, opacity: 1, duration: 0.5, ease: "back.out(1.7)" }
            );
            
            // Remove notification after 4 seconds
            setTimeout(() => {
                gsap.to(notification, {
                    x: 300,
                    opacity: 0,
                    duration: 0.3,
                    ease: "power2.in",
                    onComplete: () => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }
                });
            }, 4000);
        }

        // Enhanced Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('saveBtn').click();
            }
            
            // Ctrl+` to toggle terminal
            if (e.ctrlKey && e.key === '`') {
                e.preventDefault();
                document.getElementById('terminalToggle').click();
            }
            
            // Ctrl+F to search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchToggle').click();
            }
            
            // Ctrl+B to toggle sidebar
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                document.getElementById('sidebarToggle').click();
            }
        });

        // Initialize with animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate the entire interface in
            gsap.from('.editor-container', {
                opacity: 0,
                y: 20,
                duration: 0.8,
                ease: "power2.out"
            });
            
            renderFileTree();
            
            // Show notifications from PHP
            <?php if ($message): ?>
            setTimeout(() => showNotification('<?php echo addslashes($message); ?>', 'success'), 500);
            <?php endif; ?>
            
            <?php if ($error): ?>
            setTimeout(() => showNotification('<?php echo addslashes($error); ?>', 'error'), 500);
            <?php endif; ?>
            
            // Initialize unsaved changes indicator
            updateUnsavedChangesIndicator(currentFile);
        });

        // Handle beforeunload for unsaved changes
        window.addEventListener('beforeunload', function(e) {
            let hasAnyUnsavedChanges = false;
            Object.keys(editors).forEach(fileName => {
                if (editors[fileName]?.hasUnsavedChanges) {
                    hasAnyUnsavedChanges = true;
                }
            });
            
            if (hasAnyUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
    </script>
</body>
</html>