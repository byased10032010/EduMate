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

function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

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

function initializeDatabases() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create messages database and tables
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME_MESSAGES);
    $conn->select_db(DB_NAME_MESSAGES);
    
    $conn->query("CREATE TABLE IF NOT EXISTS conversations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id VARCHAR(100) UNIQUE NOT NULL,
        user1_id VARCHAR(50) NOT NULL,
        user2_id VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user1_id),
        INDEX (user2_id)
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id VARCHAR(100) NOT NULL,
        sender_id VARCHAR(50) NOT NULL,
        receiver_id VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        message_type ENUM('text', 'image', 'file') DEFAULT 'text',
        is_read BOOLEAN DEFAULT FALSE,
        parent_message_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
        INDEX (sender_id),
        INDEX (receiver_id),
        INDEX (conversation_id),
        INDEX (is_read)
    )");
    
    // Create user_friends database and tables
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME_USER_FRIENDS);
    $conn->select_db(DB_NAME_USER_FRIENDS);
    
    $conn->query("CREATE TABLE IF NOT EXISTS friend_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id VARCHAR(50) NOT NULL,
        receiver_id VARCHAR(50) NOT NULL,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_request (sender_id, receiver_id),
        INDEX (sender_id),
        INDEX (receiver_id),
        INDEX (status)
    )");
    
    // Create other databases
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME_FRIEND_CHATS);
    $conn->query("CREATE DATABASE IF NOT EXISTS user_status");
    $conn->select_db("user_status");
    
    $conn->query("CREATE TABLE IF NOT EXISTS user_online_status (
        user_id VARCHAR(50) PRIMARY KEY,
        status ENUM('online', 'offline', 'idle') DEFAULT 'offline',
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (status),
        INDEX (last_seen)
    )");
    
    $conn->close();
}

// Initialize databases
initializeDatabases();

function updateUserOnlineStatus($userId, $status = 'online') {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'user_status');
    if (!$conn->connect_error) {
        $stmt = $conn->prepare("INSERT INTO user_online_status (user_id, status) VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE status = ?, last_seen = CURRENT_TIMESTAMP");
        $stmt->bind_param("sss", $userId, $status, $status);
        $stmt->execute();
        $conn->close();
    }
}

function getUserOnlineStatus($userId) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'user_status');
    if (!$conn->connect_error) {
        $stmt = $conn->prepare("SELECT status, last_seen FROM user_online_status WHERE user_id = ?");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $conn->close();
        
        if ($result) {
            $lastSeen = strtotime($result['last_seen']);
            $fiveMinutesAgo = time() - 300;
            if ($lastSeen < $fiveMinutesAgo && $result['status'] !== 'offline') {
                updateUserOnlineStatus($userId, 'offline');
                return 'offline';
            }
            return $result['status'];
        }
    }
    return 'offline';
}

updateUserOnlineStatus($_SESSION['user_id'], 'online');

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

$totalRepos = count($userRepos);
$collaboratorRepos = 0;
$ownerRepos = 0;

foreach ($userRepos as $repo) {
    if ($repo['role'] === 'collaborator') {
        $collaboratorRepos++;
    } else {
        $ownerRepos++;
    }
}

$totalCommits = 0;
foreach ($userRepos as $repo) {
    $repoDb = $repo['repo_id'];
    $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
    if (!$repoConn->connect_error) {
        $commitStmt = $repoConn->prepare("SELECT COUNT(*) as commit_count FROM commits WHERE author_id = ?");
        $commitStmt->bind_param("s", $_SESSION['user_id']);
        $commitStmt->execute();
        $commitResult = $commitStmt->get_result()->fetch_assoc();
        $totalCommits += $commitResult['commit_count'] ?? 0;
        $repoConn->close();
    }
}

$contributionStreak = 0;
$today = date('Y-m-d');
for ($i = 0; $i < 30; $i++) {
    $checkDate = date('Y-m-d', strtotime("-$i days"));
    $hasContribution = false;
    
    foreach ($userRepos as $repo) {
        $repoDb = $repo['repo_id'];
        $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
        if (!$repoConn->connect_error) {
            $streakStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM commits WHERE author_id = ? AND DATE(timestamp) = ?");
            $streakStmt->bind_param("ss", $_SESSION['user_id'], $checkDate);
            $streakStmt->execute();
            $streakResult = $streakStmt->get_result()->fetch_assoc();
            if ($streakResult['count'] > 0) {
                $hasContribution = true;
                break;
            }
            $repoConn->close();
        }
    }
    
    if ($hasContribution) {
        $contributionStreak++;
    } else {
        break;
    }
}

$notifications = [];
$unreadNotificationCount = 0;
$notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
if (!$notifConn->connect_error) {
    $notifTableName = $_SESSION['user_id'];
    $notifTableCheck = $notifConn->query("SHOW TABLES LIKE '$notifTableName'");
    
    if ($notifTableCheck->num_rows > 0) {
        $notifStmt = $notifConn->prepare("SELECT * FROM `$notifTableName` ORDER BY created_at DESC LIMIT 50");
        $notifStmt->execute();
        $notifications = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $unreadStmt = $notifConn->prepare("SELECT COUNT(*) as count FROM `$notifTableName` WHERE is_read = 0");
        $unreadStmt->execute();
        $unreadResult = $unreadStmt->get_result()->fetch_assoc();
        $unreadNotificationCount = $unreadResult['count'];
    }
}

$unreadMessagesCount = 0;
$recentMessages = [];
$conversations = [];
$messagesConn = getDBConnection(DB_NAME_MESSAGES);

$unreadStmt = $messagesConn->prepare("SELECT COUNT(*) as unread_count FROM messages WHERE receiver_id = ? AND is_read = FALSE");
$unreadStmt->bind_param("s", $_SESSION['user_id']);
$unreadStmt->execute();
$unreadResult = $unreadStmt->get_result()->fetch_assoc();
$unreadMessagesCount = $unreadResult['unread_count'] ?? 0;

$convStmt = $messagesConn->prepare("
    SELECT c.*, 
           u1.first_name as user1_first_name, u1.last_name as user1_last_name, u1.username as user1_username,
           u2.first_name as user2_first_name, u2.last_name as user2_last_name, u2.username as user2_username,
           (SELECT message FROM messages WHERE conversation_id = c.conversation_id ORDER BY created_at DESC LIMIT 1) as last_message,
           (SELECT created_at FROM messages WHERE conversation_id = c.conversation_id ORDER BY created_at DESC LIMIT 1) as last_message_time,
           (SELECT COUNT(*) FROM messages WHERE conversation_id = c.conversation_id AND receiver_id = ? AND is_read = FALSE) as unread_count
    FROM conversations c
    LEFT JOIN codesphere.users u1 ON c.user1_id = u1.userId
    LEFT JOIN codesphere.users u2 ON c.user2_id = u2.userId
    WHERE c.user1_id = ? OR c.user2_id = ?
    ORDER BY last_message_time DESC
    LIMIT 10
");
$convStmt->bind_param("sss", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$convStmt->execute();
$conversations = $convStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// FIXED: Use the correct database connection for friend_requests
$friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);
$pendingRequests = [];

// FIXED: All friend_requests queries now use the correct database
$sentRequestsStmt = $friendsConn->prepare("
    SELECT fr.*, u.username, u.first_name, u.last_name 
    FROM friend_requests fr 
    JOIN codesphere.users u ON fr.receiver_id = u.userId 
    WHERE fr.sender_id = ? AND fr.status = 'pending'
");
$sentRequestsStmt->bind_param("s", $_SESSION['user_id']);
$sentRequestsStmt->execute();
$sentRequests = $sentRequestsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$receivedRequestsStmt = $friendsConn->prepare("
    SELECT fr.*, u.username, u.first_name, u.last_name 
    FROM friend_requests fr 
    JOIN codesphere.users u ON fr.sender_id = u.userId 
    WHERE fr.receiver_id = ? AND fr.status = 'pending'
");
$receivedRequestsStmt->bind_param("s", $_SESSION['user_id']);
$receivedRequestsStmt->execute();
$receivedRequests = $receivedRequestsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// FIXED: Friends query uses the correct database
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

// NEW: Search functionality for finding users
$searchResults = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    $searchStmt = $conn->prepare("
        SELECT userId, username, first_name, last_name, email 
        FROM users 
        WHERE (username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
        AND userId != ?
        LIMIT 10
    ");
    $searchLike = "%$searchTerm%";
    $searchStmt->bind_param("sssss", $searchLike, $searchLike, $searchLike, $searchLike, $_SESSION['user_id']);
    $searchStmt->execute();
    $searchResults = $searchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$friendUnreadCounts = [];
$friendOnlineStatus = [];
foreach ($friends as $friend) {
    $unreadStmt = $messagesConn->prepare("
        SELECT COUNT(*) as unread_count 
        FROM messages 
        WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE
    ");
    $unreadStmt->bind_param("ss", $friend['userId'], $_SESSION['user_id']);
    $unreadStmt->execute();
    $unreadResult = $unreadStmt->get_result()->fetch_assoc();
    $friendUnreadCounts[$friend['userId']] = $unreadResult['unread_count'] ?? 0;
    $friendOnlineStatus[$friend['userId']] = getUserOnlineStatus($friend['userId']);
}

// NEW: Friend suggestions based on mutual connections and similar interests
$friendSuggestions = [];
$suggestionStmt = $conn->prepare("
    SELECT DISTINCT u.userId, u.username, u.first_name, u.last_name, u.email
    FROM users u
    WHERE u.userId != ? 
    AND u.userId NOT IN (
        SELECT 
            CASE 
                WHEN sender_id = ? THEN receiver_id
                ELSE sender_id
            END as friend_id
        FROM user_friends.friend_requests 
        WHERE (sender_id = ? OR receiver_id = ?) 
        AND status IN ('accepted', 'pending')
    )
    ORDER BY RAND()
    LIMIT 5
");
$suggestionStmt->bind_param("ssss", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$suggestionStmt->execute();
$friendSuggestions = $suggestionStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// NEW: Get mutual friends count
$mutualFriends = [];
foreach ($friends as $friend) {
    $mutualStmt = $friendsConn->prepare("
        SELECT COUNT(*) as mutual_count
        FROM friend_requests
        WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
        AND status = 'accepted'
        AND (sender_id IN (
            SELECT 
                CASE 
                    WHEN sender_id = ? THEN receiver_id
                    ELSE sender_id
                END as friend_id
            FROM friend_requests 
            WHERE (sender_id = ? OR receiver_id = ?) 
            AND status = 'accepted'
        ) OR receiver_id IN (
            SELECT 
                CASE 
                    WHEN sender_id = ? THEN receiver_id
                    ELSE sender_id
                END as friend_id
            FROM friend_requests 
            WHERE (sender_id = ? OR receiver_id = ?) 
            AND status = 'accepted'
        ))
    ");
    $mutualStmt->bind_param("ssssssssss", 
        $_SESSION['user_id'], $friend['userId'], 
        $friend['userId'], $_SESSION['user_id'],
        $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'],
        $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']
    );
    $mutualStmt->execute();
    $mutualResult = $mutualStmt->get_result()->fetch_assoc();
    $mutualFriends[$friend['userId']] = $mutualResult['mutual_count'] ?? 0;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'get_friend_chat':
            header('Content-Type: application/json');
            $friendId = $_GET['friend_id'] ?? '';
            if (!empty($friendId)) {
                $messages = getFriendChatMessages($_SESSION['user_id'], $friendId, 50);
                markMessagesAsRead($_SESSION['user_id'], $friendId);
                echo json_encode($messages);
            } else {
                echo json_encode([]);
            }
            exit();
            
        case 'get_online_status':
            header('Content-Type: application/json');
            $friendId = $_GET['friend_id'] ?? '';
            if (!empty($friendId)) {
                $status = getUserOnlineStatus($friendId);
                echo json_encode(['status' => $status]);
            } else {
                echo json_encode(['status' => 'offline']);
            }
            exit();
            
        case 'mark_as_read':
            header('Content-Type: application/json');
            $friendId = $_GET['friend_id'] ?? '';
            if (!empty($friendId)) {
                markMessagesAsRead($_SESSION['user_id'], $friendId);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false]);
            }
            exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_friend_request'])) {
        $receiver_id = $_POST['receiver_id'];
        
        if (!empty($receiver_id)) {
            $checkStmt = $friendsConn->prepare("SELECT * FROM friend_requests WHERE sender_id = ? AND receiver_id = ?");
            $checkStmt->bind_param("ss", $_SESSION['user_id'], $receiver_id);
            $checkStmt->execute();
            
            if ($checkStmt->get_result()->num_rows === 0) {
                $insertStmt = $friendsConn->prepare("INSERT INTO friend_requests (sender_id, receiver_id) VALUES (?, ?)");
                $insertStmt->bind_param("ss", $_SESSION['user_id'], $receiver_id);
                
                if ($insertStmt->execute()) {
                    $friendSuccess = "Friend request sent successfully!";
                } else {
                    $friendError = "Failed to send friend request.";
                }
            } else {
                $friendError = "Friend request already sent.";
            }
        }
    }
    
    if (isset($_POST['accept_friend_request'])) {
        $request_id = $_POST['request_id'];
        
        $updateStmt = $friendsConn->prepare("UPDATE friend_requests SET status = 'accepted' WHERE id = ? AND receiver_id = ?");
        $updateStmt->bind_param("is", $request_id, $_SESSION['user_id']);
        
        if ($updateStmt->execute()) {
            $friendSuccess = "Friend request accepted!";
            $requestStmt = $friendsConn->prepare("SELECT * FROM friend_requests WHERE id = ?");
            $requestStmt->bind_param("i", $request_id);
            $requestStmt->execute();
            $request = $requestStmt->get_result()->fetch_assoc();
            
            if ($request) {
                createUserFriendsTable($_SESSION['user_id'], $request['sender_id']);
                createUserFriendsTable($request['sender_id'], $_SESSION['user_id']);
                createFriendChatTable($_SESSION['user_id'], $request['sender_id']);
            }
        }
    }
    
    if (isset($_POST['reject_friend_request'])) {
        $request_id = $_POST['request_id'];
        
        $updateStmt = $friendsConn->prepare("UPDATE friend_requests SET status = 'rejected' WHERE id = ? AND receiver_id = ?");
        $updateStmt->bind_param("is", $request_id, $_SESSION['user_id']);
        
        if ($updateStmt->execute()) {
            $friendSuccess = "Friend request rejected.";
        }
    }
    
    if (isset($_POST['remove_friend'])) {
        $friend_id = $_POST['friend_id'];
        
        $deleteStmt = $friendsConn->prepare("DELETE FROM friend_requests WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND status = 'accepted'");
        $deleteStmt->bind_param("ssss", $_SESSION['user_id'], $friend_id, $friend_id, $_SESSION['user_id']);
        
        if ($deleteStmt->execute()) {
            $friendSuccess = "Friend removed successfully.";
        }
    }
    
    if (isset($_POST['send_friend_message'])) {
        $friend_id = $_POST['friend_id'];
        $message = trim($_POST['message']);
        
        if (!empty($message) && !empty($friend_id)) {
            $chatTableName = generateChatTableName($_SESSION['user_id'], $friend_id);
            $chatConn = getDBConnection(DB_NAME_FRIEND_CHATS);
            $tableCheck = $chatConn->query("SHOW TABLES LIKE '$chatTableName'");
            
            if ($tableCheck->num_rows === 0) {
                createFriendChatTable($_SESSION['user_id'], $friend_id);
            }
            
            $insertStmt = $chatConn->prepare("INSERT INTO `$chatTableName` (sender_id, message, message_type) VALUES (?, ?, 'text')");
            $insertStmt->bind_param("ss", $_SESSION['user_id'], $message);
            
            if ($insertStmt->execute()) {
                $messageSuccess = "Message sent successfully!";
                $conversation_id = generateConversationId($_SESSION['user_id'], $friend_id);
                
                $convCheckStmt = $messagesConn->prepare("SELECT conversation_id FROM conversations WHERE conversation_id = ?");
                $convCheckStmt->bind_param("s", $conversation_id);
                $convCheckStmt->execute();
                $existingConv = $convCheckStmt->get_result()->fetch_assoc();
                
                if (!$existingConv) {
                    $createConvStmt = $messagesConn->prepare("INSERT INTO conversations (conversation_id, user1_id, user2_id) VALUES (?, ?, ?)");
                    $createConvStmt->bind_param("sss", $conversation_id, $_SESSION['user_id'], $friend_id);
                    $createConvStmt->execute();
                }
                
                $insertMsgStmt = $messagesConn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
                $insertMsgStmt->bind_param("ssss", $conversation_id, $_SESSION['user_id'], $friend_id, $message);
                $insertMsgStmt->execute();
            } else {
                $messageError = "Failed to send message.";
            }
            
            $chatConn->close();
        }
    }
}

function createUserFriendsTable($userId, $friendId) {
    $conn = getDBConnection(DB_NAME_USER_FRIENDS);
    $userTableName = "user_" . $userId;
    $createTableSQL = "CREATE TABLE IF NOT EXISTS `$userTableName` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        friend_id VARCHAR(50) NOT NULL,
        status ENUM('active', 'blocked') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_friend (friend_id),
        INDEX (friend_id),
        INDEX (status)
    )";
    $conn->query($createTableSQL);
    
    $insertStmt = $conn->prepare("INSERT INTO `$userTableName` (friend_id) VALUES (?) 
                                 ON DUPLICATE KEY UPDATE status = 'active', updated_at = CURRENT_TIMESTAMP");
    $insertStmt->bind_param("s", $friendId);
    $insertStmt->execute();
    
    $conn->close();
}

function createFriendChatTable($user1, $user2) {
    $conn = getDBConnection(DB_NAME_FRIEND_CHATS);
    $tableName = generateChatTableName($user1, $user2);
    
    $createTableSQL = "CREATE TABLE IF NOT EXISTS `$tableName` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        message_type ENUM('text', 'image', 'file') DEFAULT 'text',
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (sender_id),
        INDEX (is_read),
        INDEX (created_at)
    )";
    
    $conn->query($createTableSQL);
    $conn->close();
}

function generateChatTableName($user1, $user2) {
    $users = [$user1, $user2];
    sort($users);
    return 'chat_' . md5(implode('_', $users));
}

function getFriendChatMessages($user1, $user2, $limit = 50) {
    $chatConn = getDBConnection(DB_NAME_FRIEND_CHATS);
    $tableName = generateChatTableName($user1, $user2);
    $tableCheck = $chatConn->query("SHOW TABLES LIKE '$tableName'");
    
    if ($tableCheck->num_rows === 0) {
        $chatConn->close();
        return [];
    }
    
    $stmt = $chatConn->prepare("SELECT * FROM `$tableName` ORDER BY created_at ASC LIMIT ?");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $chatConn->close();
    return $messages;
}

function markMessagesAsRead($userId, $friendId) {
    $messagesConn = getDBConnection(DB_NAME_MESSAGES);
    $updateStmt = $messagesConn->prepare("UPDATE messages SET is_read = TRUE WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE");
    $updateStmt->bind_param("ss", $friendId, $userId);
    $updateStmt->execute();
    
    $chatConn = getDBConnection(DB_NAME_FRIEND_CHATS);
    $tableName = generateChatTableName($userId, $friendId);
    $tableCheck = $chatConn->query("SHOW TABLES LIKE '$tableName'");
    if ($tableCheck->num_rows > 0) {
        $chatUpdateStmt = $chatConn->prepare("UPDATE `$tableName` SET is_read = TRUE WHERE sender_id = ? AND is_read = FALSE");
        $chatUpdateStmt->bind_param("s", $friendId);
        $chatUpdateStmt->execute();
    }
    
    $chatConn->close();
    $messagesConn->close();
}

function generateConversationId($user1, $user2) {
    $users = [$user1, $user2];
    sort($users);
    return 'conv_' . md5(implode('_', $users));
}

$conn->close();
$reposConn->close();
if (isset($notifConn)) $notifConn->close();
$messagesConn->close();
$friendsConn->close();

$stats = [
    'total_repos' => $totalRepos,
    'owner_repos' => $ownerRepos,
    'collaborator_repos' => $collaboratorRepos,
    'total_commits' => $totalCommits,
    'collaborators_count' => $collaboratorRepos,
    'friends_count' => count($friends),
    'pending_requests' => count($receivedRequests),
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friends - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-xl); max-height: 300px; overflow-y: auto; z-index: 1000; display: none; }
        .search-result-item { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); cursor: pointer; transition: var(--transition); }
        .search-result-item:hover { background: var(--sidebar-hover); }
        .search-result-item:last-child { border-bottom: none; }
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
        
        .dashboard { flex: 1; padding: 2rem; }
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
        .stat-icon.repository { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .stat-icon.owner { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-icon.collaborator { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-icon.commit { background: linear-gradient(135deg, #8b5cf6, #6366f1); }
        .stat-icon.streak { background: linear-gradient(135deg, #ec4899, #db2777); }
        .stat-icon.message { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .stat-icon.friends { background: linear-gradient(135deg, #f59e0b, #ea580c); }
        .stat-trend { font-size: 0.8rem; font-weight: 600; padding: 0.25rem 0.75rem; border-radius: 12px; transition: var(--transition); }
        .stat-trend.positive { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .stat-card h3 { font-size: 2.5rem; margin-bottom: 0.5rem; color: var(--dark); font-weight: 800; transition: var(--transition); background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .stat-card p { color: var(--gray); font-size: 0.95rem; transition: var(--transition); }
        
        .friend-requests, .friends-section, .friend-suggestions { background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); margin-bottom: 2rem; overflow: hidden; box-shadow: var(--shadow-lg); backdrop-filter: blur(10px); }
        .friend-requests { animation: fadeInUp 0.8s ease-out 0.6s both; }
        .friends-section { animation: fadeInUp 0.8s ease-out 0.4s both; }
        .friend-suggestions { animation: fadeInUp 0.8s ease-out 0.2s both; }
        
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
        
        .request-item { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; transition: var(--transition); }
        .request-item:hover { background: var(--sidebar-hover); }
        .request-item:last-child { border-bottom: none; }
        .request-user { display: flex; align-items: center; gap: 1rem; }
        .request-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--gradient); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1rem; }
        .request-info h4 { font-weight: 600; margin-bottom: 0.25rem; }
        .request-info p { font-size: 0.8rem; color: var(--gray); }
        .request-actions { display: flex; gap: 0.5rem; }
        
        .friends-grid, .suggestions-grid { padding: 1.5rem 2rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
        .friend-card, .suggestion-card { padding: 1.5rem; border: 1px solid var(--border); border-radius: var(--radius-lg); transition: var(--transition); cursor: pointer; position: relative; overflow: hidden; backdrop-filter: blur(10px); }
        .friend-card::before, .suggestion-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: var(--gradient-light); transition: var(--transition); }
        .friend-card:hover, .suggestion-card:hover { border-color: var(--primary); box-shadow: var(--shadow-lg); transform: translateY(-4px); }
        .friend-card:hover::before, .suggestion-card:hover::before { left: 0; }
        .friend-header, .suggestion-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; position: relative; z-index: 1; }
        .friend-name, .suggestion-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--primary); font-size: 1.1rem; transition: var(--transition); }
        .friend-card:hover .friend-name, .suggestion-card:hover .suggestion-name { color: var(--primary-dark); }
        .friend-icon, .suggestion-icon { color: var(--gray); transition: var(--transition); }
        .friend-card:hover .friend-icon, .suggestion-card:hover .suggestion-icon { color: var(--primary); transform: scale(1.1); }
        .friend-badge { padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; transition: var(--transition); }
        .friend-badge.online { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .friend-badge.offline { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); }
        .friend-badge.idle { background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid rgba(99, 102, 241, 0.2); }
        .friend-email, .suggestion-email { color: var(--gray); margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.5; position: relative; z-index: 1; }
        .friend-actions, .suggestion-actions { display: flex; align-items: center; gap: 1.5rem; font-size: 0.8rem; color: var(--gray); position: relative; z-index: 1; }
        .friend-action, .suggestion-action { display: flex; align-items: center; gap: 0.375rem; transition: var(--transition); }
        .friend-card:hover .friend-action, .suggestion-card:hover .suggestion-action { color: var(--dark); }
        
        .empty-state { text-align: center; padding: 3rem 2rem; color: var(--gray); }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.5; animation: bounce 2s infinite; }
        .empty-state h3 { font-size: 1.3rem; margin-bottom: 0.5rem; color: var(--dark); }
        
        .search-section { background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); padding: 2rem; margin-bottom: 2rem; box-shadow: var(--shadow-lg); animation: fadeInUp 0.8s ease-out 0.8s both; backdrop-filter: blur(10px); }
        .search-section h3 { margin-bottom: 1rem; font-weight: 600; }
        .search-form { display: flex; gap: 1rem; }
        .search-input { flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        
        .search-results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 1.5rem; }
        .search-result-card { padding: 1.5rem; border: 1px solid var(--border); border-radius: var(--radius-lg); transition: var(--transition); cursor: pointer; position: relative; overflow: hidden; backdrop-filter: blur(10px); }
        .search-result-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: var(--gradient-light); transition: var(--transition); }
        .search-result-card:hover { border-color: var(--primary); box-shadow: var(--shadow-lg); transform: translateY(-4px); }
        .search-result-card:hover::before { left: 0; }
        .search-result-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; position: relative; z-index: 1; }
        .search-result-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--primary); font-size: 1.1rem; transition: var(--transition); }
        .search-result-card:hover .search-result-name { color: var(--primary-dark); }
        .search-result-icon { color: var(--gray); transition: var(--transition); }
        .search-result-card:hover .search-result-icon { color: var(--primary); transform: scale(1.1); }
        .search-result-email { color: var(--gray); margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.5; position: relative; z-index: 1; }
        .search-result-actions { display: flex; align-items: center; gap: 1.5rem; font-size: 0.8rem; color: var(--gray); position: relative; z-index: 1; }
        .search-result-action { display: flex; align-items: center; gap: 0.375rem; transition: var(--transition); }
        .search-result-card:hover .search-result-action { color: var(--dark); }
        
        /* NEW: Mutual Friends Badge */
        .mutual-friends { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: var(--gray); margin-bottom: 0.5rem; }
        .mutual-count { background: var(--primary); color: white; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        
        /* NEW: Friend Activity */
        .friend-activity { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); }
        .activity-item { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem; font-size: 0.8rem; color: var(--gray); }
        .activity-icon { width: 24px; height: 24px; border-radius: 50%; background: var(--gradient-light); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 0.7rem; }
        
        /* NEW: Quick Actions */
        .quick-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
        .quick-action { padding: 0.5rem 1rem; background: var(--sidebar-hover); border-radius: var(--radius); font-size: 0.8rem; transition: var(--transition); cursor: pointer; }
        .quick-action:hover { background: var(--primary); color: white; transform: translateY(-2px); }
        
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
            .dashboard { padding: 1rem; }
            .welcome-section h1 { font-size: 2rem; }
            .welcome-section p { font-size: 1rem; }
            .friends-grid, .suggestions-grid, .search-results-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
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
                <a href="all_repos.php" class="nav-item"><i class="fas fa-code-branch nav-icon"></i><span class="nav-text">All Repositories</span><span class="nav-badge"><?php echo $stats['total_repos']; ?></span></a>
                <a href="repo.php" class="nav-item"><i class="fas fa-plus-circle nav-icon"></i><span class="nav-text">New Repository</span></a>
                <a href="code_editor.php" class="nav-item"><i class="fas fa-code nav-icon"></i><span class="nav-text">Code Editor</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Collaborate</div>
                <a href="collaborations.php" class="nav-item"><i class="fas fa-users nav-icon"></i><span class="nav-text">Collaborations</span><span class="nav-badge"><?php echo $stats['collaborator_repos']; ?></span></a>
                <a href="notifications.php" class="nav-item"><i class="fas fa-bell nav-icon"></i><span class="nav-text">Notifications</span><span class="nav-badge" id="notificationCount"><?php echo $unreadNotificationCount; ?></span></a>
                <a href="messages.php" class="nav-item"><i class="fas fa-envelope nav-icon"></i><span class="nav-text">Messages</span><span class="nav-badge" id="sidebarMessageCount"><?php echo $unreadMessagesCount; ?></span></a>
                <a href="friends.php" class="nav-item active"><i class="fas fa-user-friends nav-icon"></i><span class="nav-text">Friends</span><span class="nav-badge"><?php echo $stats['friends_count']; ?></span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Insights</div>
                <a href="analytics.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i><span class="nav-text">Analytics</span></a>
                <a href="contributions.php" class="nav-item"><i class="fas fa-fire-flame-curved nav-icon"></i><span class="nav-text">Contributions</span><span class="nav-badge"><?php echo $contributionStreak; ?></span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Account</div>
                <a href="profile.php" class="nav-item"><i class="fas fa-user-circle nav-icon"></i><span class="nav-text">Profile</span></a>
                <a href="settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i><span class="nav-text">Settings</span></a>
                <a href="#" class="nav-item" onclick="showLogoutModal()"><i class="fas fa-sign-out-alt nav-icon"></i><span class="nav-text">Logout</span></a>
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
                        <input type="text" class="search-input" id="searchInput" placeholder="Search users...">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="nav-right">
                    <button class="nav-btn" title="Friends" id="friendsBtn">
                        <i class="fas fa-user-friends"></i>
                        <?php if ($stats['pending_requests'] > 0): ?><span class="friend-badge" id="headerFriendCount"><?php echo $stats['pending_requests']; ?></span><?php endif; ?>
                    </button>
                    <button class="nav-btn" title="Messages" id="messageBtn">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unreadMessagesCount > 0): ?><span class="message-badge" id="headerMessageCount"><?php echo $unreadMessagesCount; ?></span><?php endif; ?>
                    </button>
                    <button class="nav-btn" title="Notifications" id="notificationBtn">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadNotificationCount > 0): ?><span class="notification-badge" id="headerNotificationCount"><?php echo $unreadNotificationCount; ?></span><?php endif; ?>
                    </button>
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

        <section class="dashboard">
            <?php if (isset($friendSuccess)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $friendSuccess; ?></div><?php endif; ?>
            <?php if (isset($friendError)): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $friendError; ?></div><?php endif; ?>

            <div class="welcome-section">
                <div class="welcome-content">
                    <h1><i class="fas fa-user-friends welcome-icon"></i>Friends & Connections</h1>
                    <p>Connect with other developers, collaborate on projects, and build your coding network.</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-header"><div class="stat-icon friends"><i class="fas fa-user-friends"></i></div><span class="stat-trend positive">+<?php echo $stats['friends_count'] > 0 ? round($stats['friends_count'] * 0.1) : 0; ?></span></div><h3><?php echo $stats['friends_count']; ?></h3><p>Total Friends</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon message"><i class="fas fa-envelope"></i></div><span class="stat-trend positive">+<?php echo $unreadMessagesCount; ?></span></div><h3><?php echo $unreadMessagesCount; ?></h3><p>Unread Messages</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon collaborator"><ion-icon name="people-outline"></ion-icon></div><span class="stat-trend positive">+<?php echo $stats['collaborator_repos'] > 0 ? round($stats['collaborator_repos'] * 0.1) : 0; ?></span></div><h3><?php echo $stats['collaborator_repos']; ?></h3><p>Collaborations</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon repository"><i class="fas fa-code-branch"></i></div><span class="stat-trend positive">+<?php echo $stats['total_repos'] > 0 ? round($stats['total_repos'] * 0.1) : 0; ?></span></div><h3><?php echo $stats['total_repos']; ?></h3><p>Total Repositories</p></div>
            </div>

            <div class="search-section">
                <h3>Find Users</h3>
                <form method="GET" class="search-form">
                    <input type="text" class="search-input" name="search" placeholder="Search by username, name, or email..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i>Search</button>
                </form>
                
                <?php if (!empty($searchResults)): ?>
                    <div class="search-results-grid">
                        <?php foreach ($searchResults as $userResult): ?>
                            <div class="search-result-card">
                                <div class="search-result-header">
                                    <div class="search-result-name"><i class="fas fa-user search-result-icon"></i><?php echo htmlspecialchars($userResult['first_name'] . ' ' . $userResult['last_name']); ?></div>
                                </div>
                                <p class="search-result-email">@<?php echo htmlspecialchars($userResult['username']); ?> • <?php echo htmlspecialchars($userResult['email']); ?></p>
                                <div class="mutual-friends">
                                    <ion-icon name="people-outline"></ion-icon>
                                    <span>Mutual friends: <span class="mutual-count"><?php echo $mutualFriends[$userResult['userId']] ?? 0; ?></span></span>
                                </div>
                                <div class="search-result-actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="receiver_id" value="<?php echo $userResult['userId']; ?>">
                                        <button type="submit" name="send_friend_request" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i>Add Friend</button>
                                    </form>
                                    <span class="search-result-action"><i class="fas fa-envelope"></i>Message</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (isset($_GET['search']) && empty($searchResults)): ?>
                    <div class="empty-state" style="padding: 2rem; margin-top: 1rem;">
                        <i class="fas fa-search"></i>
                        <h3>No users found</h3>
                        <p>Try searching with different keywords</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($receivedRequests) > 0): ?>
            <div class="friend-requests">
                <div class="section-header"><h2>Friend Requests</h2><span class="nav-badge"><?php echo count($receivedRequests); ?></span></div>
                <?php foreach ($receivedRequests as $request): ?>
                <div class="request-item">
                    <div class="request-user">
                        <div class="request-avatar"><?php echo strtoupper(substr($request['first_name'], 0, 1)) . strtoupper(substr($request['last_name'], 0, 1)); ?></div>
                        <div class="request-info">
                            <h4><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></h4>
                            <p>@<?php echo htmlspecialchars($request['username']); ?></p>
                            <div class="mutual-friends">
                                <ion-icon name="people-outline"></ion-icon>
                                <span>Mutual friends: <span class="mutual-count"><?php echo $mutualFriends[$request['sender_id']] ?? 0; ?></span></span>
                            </div>
                        </div>
                    </div>
                    <div class="request-actions">
                        <form method="POST" style="display: inline;"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" name="accept_friend_request" class="btn btn-success btn-sm"><ion-icon name="checkmark-outline"></ion-icon>Accept</button></form>
                        <form method="POST" style="display: inline;"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" name="reject_friend_request" class="btn btn-error btn-sm"><ion-icon name="close-outline"></ion-icon>Reject</button></form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="friends-section">
                <div class="section-header">
                    <h2>Your Friends (<?php echo count($friends); ?>)</h2>
                    <div class="quick-actions">
                        <button class="btn btn-outline btn-sm" onclick="refreshFriends()"><i class="fas fa-sync-alt"></i>Refresh</button>
                        <button class="btn btn-primary btn-sm" onclick="showFriendSuggestions()"><i class="fas fa-user-plus"></i>Find Friends</button>
                    </div>
                </div>
                <div class="friends-grid">
                    <?php if (count($friends) > 0): ?>
                        <?php foreach ($friends as $friend): ?>
                            <div class="friend-card">
                                <div class="friend-header">
                                    <div class="friend-name"><i class="fas fa-user friend-icon"></i><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?></div>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <span class="friend-badge <?php echo $friendOnlineStatus[$friend['userId']]; ?>"><?php echo ucfirst($friendOnlineStatus[$friend['userId']]); ?></span>
                                        <?php if (isset($friendUnreadCounts[$friend['userId']]) && $friendUnreadCounts[$friend['userId']] > 0): ?><span class="nav-badge"><?php echo $friendUnreadCounts[$friend['userId']]; ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <p class="friend-email">@<?php echo htmlspecialchars($friend['username']); ?></p>
                                <div class="mutual-friends">
                                    <ion-icon name="people-outline"></ion-icon>
                                    <span>Mutual friends: <span class="mutual-count"><?php echo $mutualFriends[$friend['userId']] ?? 0; ?></span></span>
                                </div>
                                <div class="friend-activity">
                                    <div class="activity-item">
                                        <div class="activity-icon"><i class="fas fa-code"></i></div>
                                        <span>Recently active in <?php echo $friend['username']; ?>'s repositories</span>
                                    </div>
                                </div>
                                <div class="friend-actions">
                                    <button class="btn btn-primary btn-sm" onclick="openFriendChat('<?php echo $friend['userId']; ?>', '<?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>')"><ion-icon name="chatbubble-outline"></ion-icon>Message</button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="friend_id" value="<?php echo $friend['userId']; ?>">
                                        <button type="submit" name="remove_friend" class="btn btn-error btn-sm" onclick="return confirm('Are you sure you want to remove this friend?')"><i class="fas fa-user-minus"></i>Remove</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-user-friends"></i><h3>No friends yet</h3><p>Add friends to collaborate and chat</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (count($friendSuggestions) > 0): ?>
            <div class="friend-suggestions">
                <div class="section-header"><h2>Friend Suggestions</h2></div>
                <div class="suggestions-grid">
                    <?php foreach ($friendSuggestions as $suggestion): ?>
                        <div class="suggestion-card">
                            <div class="suggestion-header">
                                <div class="suggestion-name"><i class="fas fa-user suggestion-icon"></i><?php echo htmlspecialchars($suggestion['first_name'] . ' ' . $suggestion['last_name']); ?></div>
                            </div>
                            <p class="suggestion-email">@<?php echo htmlspecialchars($suggestion['username']); ?></p>
                            <div class="mutual-friends">
                                <ion-icon name="people-outline"></ion-icon>
                                <span>Mutual friends: <span class="mutual-count"><?php echo $mutualFriends[$suggestion['userId']] ?? 0; ?></span></span>
                            </div>
                            <div class="suggestion-actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="receiver_id" value="<?php echo $suggestion['userId']; ?>">
                                    <button type="submit" name="send_friend_request" class="btn btn-primary btn-sm"><i class="fas fa-user-plus"></i>Add Friend</button>
                                </form>
                                <span class="suggestion-action"><i class="fas fa-envelope"></i>Message</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
    </div>

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
    
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
        opacity: 0;
        visibility: hidden;
        transition: var(--transition);
        backdrop-filter: blur(4px);
    }
    
    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }
    
    .modal-close {
        background: none;
        border: none;
        color: white;
        cursor: pointer;
        font-size: 1.2rem;
        padding: 0.5rem;
        border-radius: var(--radius);
        transition: var(--transition);
    }
    
    .modal-close:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(90deg);
    }
    </style>

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

    function showLogoutModal() {
        document.getElementById('logoutModal').classList.add('active');
    }

    function hideLogoutModal() {
        document.getElementById('logoutModal').classList.remove('active');
    }

    function openFriendChat(friendId, friendName) {
        // In a real implementation, this would open a chat interface
        alert(`Opening chat with ${friendName}`);
        // You would typically redirect to a messages page or open a chat modal
        // window.location.href = `messages.php?friend_id=${friendId}`;
    }
    
    function refreshFriends() {
        // In a real implementation, this would refresh the friends list
        location.reload();
    }
    
    function showFriendSuggestions() {
        // Scroll to friend suggestions section
        document.querySelector('.friend-suggestions').scrollIntoView({ 
            behavior: 'smooth' 
        });
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
        
        // Logout modal event listeners
        document.getElementById('logoutModalClose').addEventListener('click', hideLogoutModal);
        document.getElementById('cancelLogout').addEventListener('click', hideLogoutModal);
        document.getElementById('confirmLogout').addEventListener('click', () => {
            window.location.href = 'logout.php';
        });
        
        document.getElementById('logoutModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('logoutModal')) hideLogoutModal();
        });
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
        if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }
    });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    let searchTimeout;

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = this.value.trim();
        if (searchTerm.length < 2) { 
            searchResults.style.display = 'none'; 
            return; 
        }
        
        searchTimeout = setTimeout(() => {
            fetch(`friends.php?search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.text())
                .then(html => {
                    // This would need to be adjusted based on your search implementation
                    console.log('Search results would be displayed here');
                })
                .catch(error => {
                    console.error('Search error:', error);
                });
        }, 300);
    });

    document.addEventListener('click', (e) => { 
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none'; 
        }
    });

    // Animation for cards
    document.addEventListener('DOMContentLoaded', () => {
        const cards = document.querySelectorAll('.stat-card, .friend-card, .suggestion-card, .search-result-card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 0.1}s`;
        });
    });
    </script>
</body>
</html>