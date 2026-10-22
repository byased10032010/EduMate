<?php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME_CODESPHERE', 'codesphere');

// Database connection function
function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: auth.php');
    exit();
}

// Get share link details
$conn = getDBConnection(DB_NAME_CODESPHERE);
$stmt = $conn->prepare("SELECT * FROM share_links WHERE token = ? AND is_active = TRUE AND (expiry IS NULL OR expiry > NOW())");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$shareLink = $result->fetch_assoc();

if (!$shareLink) {
    // Invalid or expired share link
    header('Location: invalid_share.php');
    exit();
}

$repoId = $shareLink['repo_id'];
$permission = $shareLink['permission'];

// Connect to repository database
$repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
if ($repoConn->connect_error) {
    die("Repository not found or access denied");
}

// Get repository files and data
$files = [];
$folders = [];

$filesStmt = $repoConn->prepare("SELECT * FROM files ORDER BY folder, file_name");
$filesStmt->execute();
$filesResult = $filesStmt->get_result();

while ($file = $filesResult->fetch_assoc()) {
    if ($file['file_type'] === 'folder') {
        $folders[] = $file;
    } else {
        $files[] = $file;
    }
}

// Get repository info from user_repos
$reposConn = getDBConnection('user_repos');
$repoInfoStmt = $reposConn->prepare("SELECT repo_name, description FROM user_repos WHERE repo_id = ? LIMIT 1");
$repoInfoStmt->bind_param("s", $repoId);
$repoInfoStmt->execute();
$repoInfoResult = $repoInfoStmt->get_result();
$repoInfo = $repoInfoResult->fetch_assoc();

$repoName = $repoInfo['repo_name'] ?? $repoId;
$repoDescription = $repoInfo['description'] ?? '';

$conn->close();
$repoConn->close();
$reposConn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($repoName); ?> - Shared Repository - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            text-align: center;
            margin-bottom: 3rem;
            animation: slideInDown 0.8s ease-out;
        }

        .repo-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            animation: slideInUp 0.8s ease-out;
        }

        .file-browser {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .browser-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--gradient);
            color: white;
        }

        .browser-content {
            padding: 1.5rem;
        }

        .file-item, .folder-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .file-item:hover, .folder-item:hover {
            background: var(--light-gray);
        }

        .file-icon, .folder-icon {
            width: 20px;
            margin-right: 1rem;
            color: var(--gray);
        }

        .file-name, .folder-name {
            flex: 1;
            font-weight: 500;
        }

        .permission-badge {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="font-size: 2.5rem; font-weight: 800; margin-bottom: 1rem; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <?php echo htmlspecialchars($repoName); ?>
            </h1>
            <p style="color: var(--gray); font-size: 1.1rem; margin-bottom: 1rem;">
                Shared Repository • 
                <span class="permission-badge"><?php echo ucfirst($permission); ?> Access</span>
            </p>
            <?php if (!empty($repoDescription)): ?>
                <p style="color: var(--gray);"><?php echo htmlspecialchars($repoDescription); ?></p>
            <?php endif; ?>
        </div>

        <div class="file-browser">
            <div class="browser-header">
                <h3 style="font-weight: 600;">Files and Folders</h3>
            </div>
            <div class="browser-content">
                <?php if (empty($files) && empty($folders)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--gray);">
                        <i class="fas fa-folder-open" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <h3>No files yet</h3>
                        <p>This repository is currently empty</p>
                    </div>
                <?php else: ?>
                    <!-- Folders -->
                    <?php foreach ($folders as $folder): ?>
                        <div class="folder-item">
                            <i class="fas fa-folder folder-icon" style="color: #f59e0b;"></i>
                            <div class="folder-name"><?php echo htmlspecialchars(basename($folder['folder'])); ?></div>
                            <div style="color: var(--gray); font-size: 0.875rem;"><?php echo htmlspecialchars($folder['folder']); ?></div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Files -->
                    <?php foreach ($files as $file): ?>
                        <div class="file-item">
                            <?php
                            $fileIcons = [
                                'php' => 'fab fa-php file-icon',
                                'js' => 'fab fa-js-square file-icon',
                                'html' => 'fab fa-html5 file-icon',
                                'css' => 'fab fa-css3-alt file-icon',
                                'py' => 'fab fa-python file-icon',
                                'java' => 'fab fa-java file-icon',
                                'json' => 'fas fa-code file-icon',
                                'md' => 'fas fa-markdown file-icon',
                                'txt' => 'fas fa-file-alt file-icon',
                                'default' => 'fas fa-file file-icon'
                            ];
                            $fileExt = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                            $fileIcon = $fileIcons[$fileExt] ?? $fileIcons['default'];
                            ?>
                            <i class="<?php echo $fileIcon; ?>"></i>
                            <div class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></div>
                            <div style="color: var(--gray); font-size: 0.875rem;"><?php echo htmlspecialchars($file['folder']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="repo-card">
            <h3 style="margin-bottom: 1rem;">Share Information</h3>
            <p>This repository has been shared with you with <strong><?php echo ucfirst($permission); ?></strong> permissions.</p>
            <?php if ($shareLink['expiry']): ?>
                <p style="margin-top: 0.5rem;">This share link expires on: <strong><?php echo date('F j, Y g:i A', strtotime($shareLink['expiry'])); ?></strong></p>
            <?php endif; ?>
            <p style="margin-top: 0.5rem; color: var(--gray); font-size: 0.875rem;">
                <i class="fas fa-info-circle"></i> 
                You have <?php echo $permission === 'read' ? 'view-only' : 'read and write'; ?> access to this repository.
            </p>
        </div>
    </div>
</body>
</html>