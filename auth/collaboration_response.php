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

function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

$repoId = $_GET['repo'] ?? '';
$inviterId = $_GET['inviter'] ?? '';
$notificationId = $_GET['notification_id'] ?? '';

if (empty($repoId) || empty($inviterId)) {
    die("Invalid collaboration request");
}

// Get user data
$conn = getDBConnection(DB_NAME_CODESPHERE);
$stmt = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE userId = ?");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get inviter data
$inviterStmt = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE userId = ?");
$inviterStmt->bind_param("s", $inviterId);
$inviterStmt->execute();
$inviterResult = $inviterStmt->get_result();
$inviter = $inviterResult->fetch_assoc();

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = $_POST['response'] ?? '';
    
    if ($response === 'accept') {
        // Add user to repository as collaborator
        $reposConn = getDBConnection(DB_NAME_USER_REPOS);
        $tableName = $user['username'] . '_' . $_SESSION['user_id'];
        
        // Create user's repo table if it doesn't exist
        $tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
        if ($tableCheck->num_rows === 0) {
            $createTable = "CREATE TABLE `$tableName` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                repo_id VARCHAR(255) NOT NULL UNIQUE,
                repo_name VARCHAR(255),
                description TEXT,
                repo_access VARCHAR(50),
                access_start DATETIME,
                role VARCHAR(50),
                permission_level ENUM('read', 'write', 'admin') DEFAULT 'write'
            )";
            $reposConn->query($createTable);
        } else {
            // Check and alter table if needed
            $result = $reposConn->query("SHOW COLUMNS FROM `$tableName` LIKE 'repo_name'");
            if ($result->num_rows === 0) {
                $alterSql = [];
                $columnCheck = $reposConn->query("DESCRIBE `$tableName`");
                $existingColumns = [];
                while ($row = $columnCheck->fetch_assoc()) {
                    $existingColumns[$row['Field']] = true;
                }
                
                if (!isset($existingColumns['repo_name'])) {
                    $alterSql[] = "ADD COLUMN repo_name VARCHAR(255)";
                }
                if (!isset($existingColumns['description'])) {
                    $alterSql[] = "ADD COLUMN description TEXT";
                }
                if (!isset($existingColumns['permission_level'])) {
                    $alterSql[] = "ADD COLUMN permission_level ENUM('read', 'write', 'admin') DEFAULT 'write'";
                }
                
                if (!empty($alterSql)) {
                    $alterQuery = "ALTER TABLE `$tableName` " . implode(', ', $alterSql);
                    $reposConn->query($alterQuery);
                }
            }
        }
        
        // Add repository to user's table
        $accessStart = date('Y-m-d H:i:s');
        $insertStmt = $reposConn->prepare("INSERT INTO `$tableName` (repo_id, repo_name, repo_access, access_start, role, permission_level) VALUES (?, ?, 'private', ?, 'collaborator', 'write')");
        $insertStmt->bind_param("sss", $repoId, $repoId, $accessStart);
        
        if ($insertStmt->execute()) {
            $message = "You have successfully joined the repository!";
            $success = true;
            
            // Send acceptance notification to inviter
            sendResponseNotification($inviterId, $_SESSION['user_id'], $user['first_name'] . ' ' . $user['last_name'], $repoId, 'accepted');
            
        } else {
            $message = "Failed to join repository: " . $insertStmt->error;
        }
        
        $reposConn->close();
    } else {
        $message = "Invitation declined.";
        $success = true;
        
        // Send rejection notification to inviter
        sendResponseNotification($inviterId, $_SESSION['user_id'], $user['first_name'] . ' ' . $user['last_name'], $repoId, 'declined');
    }
    
    // Mark the original notification as read
    markNotificationAsRead($_SESSION['user_id'], $repoId, $inviterId);
    
    // Redirect to prevent form resubmission
    header("Location: collaboration_response.php?repo=" . urlencode($repoId) . "&inviter=" . urlencode($inviterId) . "&processed=1");
    exit();
}

// Check if already processed
$alreadyProcessed = isset($_GET['processed']);

function markNotificationAsRead($userId, $repoId, $inviterId) {
    $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_NOTIFICATIONS);
    $notifTableName = $userId;
    
    // Mark notification as read using multiple criteria for reliability
    $updateStmt = $notifConn->prepare("UPDATE `$notifTableName` SET is_read = 1 WHERE type = 'collaboration_invite' AND (message LIKE ? OR link LIKE ?)");
    $repoSearch = "%" . $repoId . "%";
    $inviterSearch = "%inviter=" . $inviterId . "%";
    $updateStmt->bind_param("ss", $repoSearch, $inviterSearch);
    $updateStmt->execute();
    $notifConn->close();
}

function sendResponseNotification($inviterId, $responderId, $responderName, $repoId, $response) {
    $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_NOTIFICATIONS);
    
    // Create inviter's notification table if it doesn't exist
    $inviterTableName = $inviterId;
    $tableCheck = $notifConn->query("SHOW TABLES LIKE '$inviterTableName'");
    if ($tableCheck->num_rows === 0) {
        $createTable = "CREATE TABLE IF NOT EXISTS `$inviterTableName` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message TEXT NOT NULL,
            link VARCHAR(500),
            type VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_read TINYINT(1) DEFAULT 0
        )";
        $notifConn->query($createTable);
    }
    
    $message = "";
    $type = "collaboration_response";
    
    if ($response === 'accepted') {
        $message = "$responderName accepted your collaboration invitation for repository '$repoId'";
        $link = "repo_view.php?repo=" . urlencode($repoId);
    } else {
        $message = "$responderName declined your collaboration invitation for repository '$repoId'";
        $link = "dashboard.php";
    }
    
    $stmt = $notifConn->prepare("INSERT INTO `$inviterTableName` (message, link, type) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $message, $link, $type);
    $stmt->execute();
    $notifConn->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collaboration Invitation - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 90%;
            text-align: center;
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin: 0.5rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .btn-accept { 
            background: #10b981; 
            color: white; 
        }
        .btn-accept:hover {
            background: #059669;
            transform: translateY(-2px);
        }
        .btn-decline { 
            background: #ef4444; 
            color: white; 
        }
        .btn-decline:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        .btn-dashboard {
            background: #6366f1;
            color: white;
        }
        .btn-dashboard:hover {
            background: #4f46e5;
            transform: translateY(-2px);
        }
        .inviter-info {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .response-form {
            margin: 1.5rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($alreadyProcessed || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="<?php echo $success ? 'success' : 'error'; ?>">
                <i class="fas fa-<?php echo $success ? 'check-circle' : 'exclamation-circle'; ?>" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <h2><?php echo $message; ?></h2>
                <a href="dashboard.php" class="btn btn-dashboard">
                    <i class="fas fa-home"></i> Return to Dashboard
                </a>
            </div>
        <?php else: ?>
            <h1 style="color: #6366f1; margin-bottom: 1.5rem;">
                <ion-icon name="people-outline"></ion-icon> Collaboration Invitation
            </h1>
            
            <div class="inviter-info">
                <p><strong><?php echo htmlspecialchars($inviter['first_name'] . ' ' . $inviter['last_name']); ?></strong> 
                invited you to collaborate on repository:</p>
                <h3 style="color: #6366f1; margin: 1rem 0;"><?php echo htmlspecialchars($repoId); ?></h3>
            </div>
            
            <p style="margin: 1.5rem 0; color: #64748b;">
                Accepting this invitation will give you access to collaborate on this repository.
            </p>
            
            <form method="POST" class="response-form">
                <button type="submit" name="response" value="accept" class="btn btn-accept">
                    <ion-icon name="checkmark-outline"></ion-icon> Accept Invitation
                </button>
                <button type="submit" name="response" value="decline" class="btn btn-decline">
                    <ion-icon name="close-outline"></ion-icon> Decline
                </button>
            </form>
            
            <div style="margin-top: 1rem;">
                <a href="notifications.php" class="btn" style="background: #64748b; color: white;">
                    <i class="fas fa-bell"></i> View All Notifications
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>