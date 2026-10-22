<?php
require_once 'session_check.php';
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

$friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);
$pendingRequests = [];
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

if (isset($_GET['action']) && $_GET['action'] == 'get_friend_chat') {
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
}

if (isset($_GET['action']) && $_GET['action'] == 'search_repos') {
    header('Content-Type: application/json');
    $searchTerm = $_GET['search'] ?? '';
    
    if (!empty($searchTerm)) {
        $searchResults = searchPublicRepositories($searchTerm);
        echo json_encode($searchResults);
    } else {
        echo json_encode([]);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_conversations') {
    header('Content-Type: application/json');
    echo json_encode($conversations);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_online_status') {
    header('Content-Type: application/json');
    $friendId = $_GET['friend_id'] ?? '';
    
    if (!empty($friendId)) {
        $status = getUserOnlineStatus($friendId);
        echo json_encode(['status' => $status]);
    } else {
        echo json_encode(['status' => 'offline']);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'mark_as_read') {
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

if (isset($_GET['action']) && $_GET['action'] == 'read_all_messages') {
    header('Content-Type: application/json');
    markAllMessagesAsRead($_SESSION['user_id']);
    echo json_encode(['success' => true]);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'check_new_messages') {
    header('Content-Type: application/json');
    $friendId = $_GET['friend_id'] ?? '';
    $lastMessageId = $_GET['last_message_id'] ?? 0;
    
    if (!empty($friendId)) {
        $newMessages = getNewMessages($_SESSION['user_id'], $friendId, $lastMessageId);
        if (!empty($newMessages)) {
            markMessagesAsRead($_SESSION['user_id'], $friendId);
        }
        echo json_encode($newMessages);
    } else {
        echo json_encode([]);
    }
    exit();
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
                createUserFriendsTable($_SESSION['user_id'], $request['sender_id'], $request['receiver_id']);
                createUserFriendsTable($request['sender_id'], $request['receiver_id'], $_SESSION['user_id']);
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
    
    if (isset($_POST['send_message'])) {
        $receiver_id = $_POST['receiver_id'];
        $message = trim($_POST['message']);
        $parent_message_id = $_POST['parent_message_id'] ?? null;
        
        if (!empty($message) && !empty($receiver_id)) {
            $conversation_id = generateConversationId($_SESSION['user_id'], $receiver_id);
            
            $convCheckStmt = $messagesConn->prepare("SELECT conversation_id FROM conversations WHERE conversation_id = ?");
            $convCheckStmt->bind_param("s", $conversation_id);
            $convCheckStmt->execute();
            $existingConv = $convCheckStmt->get_result()->fetch_assoc();
            
            if (!$existingConv) {
                $createConvStmt = $messagesConn->prepare("INSERT INTO conversations (conversation_id, user1_id, user2_id) VALUES (?, ?, ?)");
                $createConvStmt->bind_param("sss", $conversation_id, $_SESSION['user_id'], $receiver_id);
                $createConvStmt->execute();
            }
            
            $insertMsgStmt = $messagesConn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message, parent_message_id) VALUES (?, ?, ?, ?, ?)");
            $insertMsgStmt->bind_param("ssssi", $conversation_id, $_SESSION['user_id'], $receiver_id, $message, $parent_message_id);
            
            if ($insertMsgStmt->execute()) {
                $messageSuccess = "Message sent successfully!";
            } else {
                $messageError = "Failed to send message.";
            }
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

function createUserFriendsTable($userId, $friendId, $friendName) {
    $conn = getDBConnection(DB_NAME_USER_FRIENDS);
    $userTableName = "user_" . $userId;
    $createTableSQL = "CREATE TABLE IF NOT EXISTS `$userTableName` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        friend_id VARCHAR(50) NOT NULL,
        friend_name VARCHAR(100) NOT NULL,
        status ENUM('active', 'blocked') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_friend (friend_id),
        INDEX (friend_id),
        INDEX (status)
    )";
    $conn->query($createTableSQL);
    
    $insertStmt = $conn->prepare("INSERT INTO `$userTableName` (friend_id, friend_name) VALUES (?, ?) 
                                 ON DUPLICATE KEY UPDATE status = 'active', updated_at = CURRENT_TIMESTAMP");
    $insertStmt->bind_param("ss", $friendId, $friendName);
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

function markAllMessagesAsRead($userId) {
    $messagesConn = getDBConnection(DB_NAME_MESSAGES);
    $updateStmt = $messagesConn->prepare("UPDATE messages SET is_read = TRUE WHERE receiver_id = ? AND is_read = FALSE");
    $updateStmt->bind_param("s", $userId);
    $updateStmt->execute();
    
    $friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);
    $friendsStmt = $friendsConn->prepare("
        SELECT 
            CASE 
                WHEN sender_id = ? THEN receiver_id
                ELSE sender_id
            END as friend_id
        FROM friend_requests 
        WHERE (sender_id = ? OR receiver_id = ?) 
        AND status = 'accepted'
    ");
    $friendsStmt->bind_param("sss", $userId, $userId, $userId);
    $friendsStmt->execute();
    $friends = $friendsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $chatConn = getDBConnection(DB_NAME_FRIEND_CHATS);
    foreach ($friends as $friend) {
        $tableName = generateChatTableName($userId, $friend['friend_id']);
        $tableCheck = $chatConn->query("SHOW TABLES LIKE '$tableName'");
        if ($tableCheck->num_rows > 0) {
            $chatUpdateStmt = $chatConn->prepare("UPDATE `$tableName` SET is_read = TRUE WHERE is_read = FALSE");
            $chatUpdateStmt->execute();
        }
    }
    
    $chatConn->close();
    $friendsConn->close();
    $messagesConn->close();
}

function getNewMessages($userId, $friendId, $lastMessageId) {
    $chatConn = getDBConnection(DB_NAME_FRIEND_CHATS);
    $tableName = generateChatTableName($userId, $friendId);
    $tableCheck = $chatConn->query("SHOW TABLES LIKE '$tableName'");
    
    if ($tableCheck->num_rows === 0) {
        $chatConn->close();
        return [];
    }
    
    $stmt = $chatConn->prepare("SELECT * FROM `$tableName` WHERE id > ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $lastMessageId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $chatConn->close();
    return $messages;
}

function searchPublicRepositories($searchTerm) {
    $conn = getDBConnection(DB_NAME_USER_REPOS);
    $tablesResult = $conn->query("SHOW TABLES");
    $allTables = $tablesResult->fetch_all(MYSQLI_NUM);
    $results = [];
    
    foreach ($allTables as $table) {
        $tableName = $table[0];
        if (strpos($tableName, '_') !== false) {
            $searchStmt = $conn->prepare("
                SELECT * FROM `$tableName` 
                WHERE (repo_id LIKE ? OR repo_access LIKE ?) 
                AND repo_access = 'public'
                LIMIT 10
            ");
            $searchTermLike = "%$searchTerm%";
            $searchStmt->bind_param("ss", $searchTermLike, $searchTermLike);
            $searchStmt->execute();
            $tableResults = $searchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            foreach ($tableResults as $result) {
                $result['table_name'] = $tableName;
                $results[] = $result;
            }
        }
    }
    
    $conn->close();
    return $results;
}

if (isset($_POST['mark_as_read'])) {
    $notificationId = $_POST['notification_id'];
    if (isset($notifConn) && !$notifConn->connect_error) {
        $updateStmt = $notifConn->prepare("UPDATE `$notifTableName` SET is_read = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $notificationId);
        $updateStmt->execute();
        header('Location: dashboard.php');
        exit();
    }
}

if (isset($_POST['mark_all_read'])) {
    if (isset($notifConn) && !$notifConn->connect_error) {
        $updateStmt = $notifConn->prepare("UPDATE `$notifTableName` SET is_read = 1 WHERE is_read = 0");
        $updateStmt->execute();
        header('Location: dashboard.php');
        exit();
    }
}

$recentRepos = array_slice($userRepos, 0, 5);

function generateConversationId($user1, $user2) {
    $users = [$user1, $user2];
    sort($users);
    return 'conv_' . md5(implode('_', $users));
}

// Add these before the closing PHP tag
if (isset($_GET['action']) && $_GET['action'] == 'check_new_notifications') {
    header('Content-Type: application/json');
    
    // Get latest notifications count
    $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
    if (!$notifConn->connect_error) {
        $notifTableName = $_SESSION['user_id'];
        $unreadStmt = $notifConn->prepare("SELECT COUNT(*) as count FROM `$notifTableName` WHERE is_read = 0");
        $unreadStmt->execute();
        $unreadResult = $unreadStmt->get_result()->fetch_assoc();
        
        // Get recent unread notifications
        $recentStmt = $notifConn->prepare("SELECT * FROM `$notifTableName` WHERE is_read = 0 ORDER BY created_at DESC LIMIT 5");
        $recentStmt->execute();
        $recentNotifications = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode([
            'count' => $unreadResult['count'],
            'notifications' => $recentNotifications
        ]);
    } else {
        echo json_encode(['count' => 0, 'notifications' => []]);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'check_repo_updates') {
    header('Content-Type: application/json');
    $updates = [];
    
    // Check for recent commits in user's repos
    foreach ($userRepos as $repo) {
        $repoDb = $repo['repo_id'];
        $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
        if (!$repoConn->connect_error) {
            $updateStmt = $repoConn->prepare("SELECT COUNT(*) as commit_count FROM commits WHERE timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
            $updateStmt->execute();
            $updateResult = $updateStmt->get_result()->fetch_assoc();
            
            if ($updateResult['commit_count'] > 0) {
                $updates[] = [
                    'repo_id' => $repo['repo_id'],
                    'commit_count' => $updateResult['commit_count'],
                    'collaborators' => rand(1, 5) // You'd get this from your database
                ];
            }
            $repoConn->close();
        }
    }
    
    echo json_encode($updates);
    exit();
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
    <title>Dashboard - CodeSphere</title>
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
        
        .add-friend { background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); padding: 2rem; margin-bottom: 2rem; box-shadow: var(--shadow-lg); animation: fadeInUp 0.8s ease-out 0.8s both; backdrop-filter: blur(10px); }
        .add-friend h3 { margin-bottom: 1rem; font-weight: 600; }
        .friend-form { display: flex; gap: 1rem; }
        .friend-input { flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; transition: var(--transition); }
        .friend-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        
        .friend-requests, .friends-section, .activity-feed, .repos-section { background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); margin-bottom: 2rem; overflow: hidden; box-shadow: var(--shadow-lg); backdrop-filter: blur(10px); }
        .friend-requests { animation: fadeInUp 0.8s ease-out 0.6s both; }
        .friends-section { animation: fadeInUp 0.8s ease-out 0.4s both; }
        .activity-feed { animation: fadeInUp 0.8s ease-out 0.2s both; }
        .repos-section { animation: fadeInUp 0.8s ease-out 0.4s both; }
        
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
        
        .friends-grid, .repos-grid { padding: 1.5rem 2rem; }
        .friend-card, .repo-card { padding: 1.5rem; border: 1px solid var(--border); border-radius: var(--radius-lg); transition: var(--transition); margin-bottom: 1rem; cursor: pointer; position: relative; overflow: hidden; backdrop-filter: blur(10px); }
        .friend-card::before, .repo-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: var(--gradient-light); transition: var(--transition); }
        .friend-card:last-child, .repo-card:last-child { margin-bottom: 0; }
        .friend-card:hover, .repo-card:hover { border-color: var(--primary); box-shadow: var(--shadow-lg); transform: translateY(-4px); }
        .friend-card:hover::before, .repo-card:hover::before { left: 0; }
        .friend-header, .repo-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; position: relative; z-index: 1; }
        .friend-name, .repo-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--primary); font-size: 1.1rem; transition: var(--transition); }
        .friend-card:hover .friend-name, .repo-card:hover .repo-name { color: var(--primary-dark); }
        .friend-icon, .repo-icon { color: var(--gray); transition: var(--transition); }
        .friend-card:hover .friend-icon, .repo-card:hover .repo-icon { color: var(--primary); transform: scale(1.1); }
        .repo-badge, .friend-badge { padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; transition: var(--transition); }
        .repo-badge.public, .friend-badge.online { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .repo-badge.private, .friend-badge.offline { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); }
        .repo-badge.owner, .friend-badge.idle { background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid rgba(99, 102, 241, 0.2); }
        .repo-badge.collaborator { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); }
        .friend-email, .repo-description { color: var(--gray); margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.5; position: relative; z-index: 1; }
        .friend-actions, .repo-stats { display: flex; align-items: center; gap: 1.5rem; font-size: 0.8rem; color: var(--gray); position: relative; z-index: 1; }
        .friend-action, .repo-stat { display: flex; align-items: center; gap: 0.375rem; transition: var(--transition); }
        .friend-card:hover .friend-action, .repo-card:hover .repo-stat { color: var(--dark); }
        
        .activity-item { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; transition: var(--transition); position: relative; overflow: hidden; }
        .activity-item::before { content: ''; position: absolute; left: -100%; top: 0; width: 100%; height: 100%; background: var(--gradient-light); transition: var(--transition); }
        .activity-item:hover { background: var(--sidebar-hover); transform: translateX(8px); }
        .activity-item:hover::before { left: 0; }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: var(--primary); position: relative; z-index: 1; transition: var(--transition); box-shadow: var(--shadow); }
        .activity-item:hover .activity-icon { transform: scale(1.1); background: var(--primary); color: white; }
        .activity-content { flex: 1; position: relative; z-index: 1; }
        .activity-message { font-weight: 600; margin-bottom: 0.25rem; }
        .activity-meta { font-size: 0.8rem; color: var(--gray); }
        
        .empty-state { text-align: center; padding: 3rem 2rem; color: var(--gray); }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.5; animation: bounce 2s infinite; }
        .empty-state h3 { font-size: 1.3rem; margin-bottom: 0.5rem; color: var(--dark); }
        
        .message-popup { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); width: 500px; background: var(--card-bg); border-radius: var(--radius-2xl); box-shadow: var(--shadow-2xl), var(--neon-glow); z-index: 2001; display: none; flex-direction: column; overflow: hidden; border: 1px solid var(--border); backdrop-filter: blur(20px); opacity: 0; transition: var(--transition-slow); max-height: 80vh; }
        .message-popup.active { display: flex; transform: translate(-50%, -50%) scale(1); opacity: 1; animation: popIn 0.3s ease-out; }
        @keyframes popIn { from { opacity: 0; transform: translate(-50%, -50%) scale(0.8); } to { opacity: 1; transform: translate(-50%, -50%) scale(1); } }
        .message-popup-header { padding: 1.25rem 1.5rem; background: var(--gradient); color: white; display: flex; align-items: center; justify-content: space-between; position: relative; }
        .message-popup-header::before { content: ''; position: absolute; top: -50%; right: -10%; width: 80px; height: 80px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 6s infinite ease-in-out; }
        .message-popup-header h3 { font-weight: 600; font-size: 1.1rem; position: relative; z-index: 1; }
        .message-popup-close { background: none; border: none; color: white; cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); position: relative; z-index: 1; }
        .message-popup-close:hover { background: rgba(255, 255, 255, 0.1); transform: rotate(90deg); }
        .message-popup-body { padding: 1.5rem; max-height: 400px; overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; }
        .conversation-list { max-height: 300px; overflow-y: auto; margin-bottom: 1rem; border: 1px solid var(--border); border-radius: var(--radius); }
        .conversation-item { padding: 1rem; border-bottom: 1px solid var(--border); cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 0.75rem; position: relative; }
        .conversation-item:hover { background: var(--sidebar-hover); }
        .conversation-item:last-child { border-bottom: none; }
        .conversation-item.active { background: var(--gradient-light); border-left: 3px solid var(--primary); }
        .conversation-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 0.9rem; flex-shrink: 0; position: relative; }
        .online-status { position: absolute; bottom: 0; right: 0; width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--card-bg); }
        .online-status.online { background: var(--success); }
        .online-status.offline { background: var(--gray); }
        .online-status.idle { background: var(--warning); }
        .conversation-info { flex: 1; min-width: 0; }
        .conversation-name { font-weight: 600; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 0.5rem; }
        .conversation-preview { font-size: 0.8rem; color: var(--gray); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conversation-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 0.25rem; flex-shrink: 0; }
        .conversation-time { font-size: 0.7rem; color: var(--gray); }
        .conversation-unread { background: var(--primary); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        .message-preview-container { flex: 1; display: flex; flex-direction: column; gap: 1rem; }
        .active-conversation { border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; background: var(--light); }
        .active-conversation-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border); }
        .active-conversation-user { display: flex; align-items: center; gap: 0.75rem; }
        .active-conversation-messages { max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1rem; }
        .message-bubble { max-width: 80%; padding: 0.75rem 1rem; border-radius: var(--radius-lg); position: relative; animation: messageSlide 0.3s ease-out; }
        .message-bubble.sent { align-self: flex-end; background: var(--gradient); color: white; border-bottom-right-radius: 4px; }
        .message-bubble.received { align-self: flex-start; background: var(--sidebar-hover); color: var(--dark); border-bottom-left-radius: 4px; }
        .message-content { margin-bottom: 0.25rem; }
        .message-meta { font-size: 0.7rem; opacity: 0.7; display: flex; justify-content: space-between; align-items: center; }
        .message-status { display: flex; align-items: center; gap: 0.25rem; }
        .read-status { color: var(--success); }
        .quick-message-form { display: flex; flex-direction: column; gap: 1rem; }
        .recipient-select { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; margin-bottom: 1rem; transition: var(--transition); }
        .recipient-select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .message-popup-textarea { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; resize: vertical; min-height: 120px; font-family: 'Inter', sans-serif; transition: var(--transition); }
        .message-popup-textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .message-popup-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .read-all-btn { background: var(--primary-light); color: var(--primary); border: none; padding: 0.5rem 1rem; border-radius: var(--radius); cursor: pointer; transition: var(--transition); font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; }
        .read-all-btn:hover { background: var(--primary); color: white; }
        
        .chat-widget { position: fixed; bottom: 2rem; right: 2rem; width: 380px; height: 500px; background: var(--card-bg); border-radius: var(--radius-2xl); box-shadow: var(--shadow-2xl), var(--neon-glow); z-index: 1001; display: none; flex-direction: column; overflow: hidden; border: 1px solid var(--border); backdrop-filter: blur(20px); transform: translateY(20px); opacity: 0; transition: var(--transition-slow); }
        .chat-widget.active { display: flex; transform: translateY(0); opacity: 1; animation: slideInUp 0.5s ease-out; }
        .chat-header { padding: 1.25rem 1.5rem; background: var(--gradient); color: white; display: flex; align-items: center; justify-content: space-between; position: relative; }
        .chat-header::before { content: ''; position: absolute; top: -50%; right: -10%; width: 100px; height: 100px; background: rgba(255, 255, 255, 0.1); border-radius: 50%; animation: float 6s infinite ease-in-out; }
        .chat-user { display: flex; align-items: center; gap: 0.75rem; position: relative; z-index: 1; }
        .chat-user-avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; position: relative; }
        .chat-user-info h4 { font-weight: 600; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .chat-user-info p { font-size: 0.8rem; opacity: 0.8; }
        .chat-actions { display: flex; gap: 0.5rem; }
        .chat-action-btn { background: none; border: none; color: white; cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); }
        .chat-action-btn:hover { background: rgba(255, 255, 255, 0.1); }
        .chat-close { background: none; border: none; color: white; cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); position: relative; z-index: 1; }
        .chat-close:hover { background: rgba(255, 255, 255, 0.1); transform: rotate(90deg); }
        .chat-messages { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 1rem; scrollbar-width: thin; scrollbar-color: var(--primary) transparent; }
        .chat-messages::-webkit-scrollbar { width: 6px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }
        .message { max-width: 80%; padding: 0.75rem 1rem; border-radius: var(--radius-lg); position: relative; animation: messageSlide 0.3s ease-out; }
        .message.sent { align-self: flex-end; background: var(--gradient); color: white; border-bottom-right-radius: 4px; }
        .message.received { align-self: flex-start; background: var(--sidebar-hover); color: var(--dark); border-bottom-left-radius: 4px; }
        .message.reply { border-left: 3px solid var(--primary); background: var(--gradient-light); }
        .message-content { margin-bottom: 0.25rem; }
        .message-meta { font-size: 0.7rem; opacity: 0.7; display: flex; justify-content: space-between; align-items: center; }
        .message-status { display: flex; align-items: center; gap: 0.25rem; }
        .reply-btn { background: none; border: none; color: inherit; cursor: pointer; font-size: 0.7rem; opacity: 0.7; transition: var(--transition); }
        .reply-btn:hover { opacity: 1; transform: scale(1.1); }
        .chat-input { padding: 1rem; border-top: 1px solid var(--border); background: var(--card-bg); }
        .reply-indicator { padding: 0.5rem 1rem; background: var(--gradient-light); border-left: 3px solid var(--primary); margin-bottom: 0.5rem; border-radius: var(--radius); display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; }
        .cancel-reply { background: none; border: none; color: var(--gray); cursor: pointer; padding: 0.25rem; border-radius: var(--radius); }
        .chat-form { display: flex; gap: 0.5rem; }
        .message-input { flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; resize: none; font-family: 'Inter', sans-serif; transition: var(--transition); }
        .message-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .send-btn { padding: 0.75rem 1.25rem; background: var(--gradient); color: white; border: none; border-radius: var(--radius); cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 0.5rem; }
        .send-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        
        .chat-toggle { position: fixed; bottom: 2rem; right: 2rem; width: 60px; height: 60px; background: var(--gradient); color: white; border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: var(--shadow-2xl), var(--neon-glow); transition: var(--transition); z-index: 1000; animation: pulse 2s infinite, bounceUp 3s infinite ease-in-out; }
        .chat-toggle:hover { transform: scale(1.1) rotate(15deg); }
        
        .realtime-dot { position: absolute; top: 2px; right: 2px; width: 8px; height: 8px; background: var(--success); border-radius: 50%; animation: pulse 1s infinite; }
        .typing-indicator { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; font-size: 0.8rem; color: var(--gray); font-style: italic; }
        .typing-dots { display: flex; gap: 2px; }
        .typing-dot { width: 4px; height: 4px; background: var(--gray); border-radius: 50%; animation: typing 1.4s infinite ease-in-out; }
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; opacity: 0; visibility: hidden; transition: var(--transition); backdrop-filter: blur(4px); }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .notification-modal, .friends-modal { background: var(--card-bg); border-radius: var(--radius-2xl); width: 90%; max-width: 500px; max-height: 80vh; overflow: hidden; box-shadow: var(--shadow-2xl); transform: translateY(20px) scale(0.95); transition: var(--transition); backdrop-filter: blur(20px); }
        .modal-overlay.active .notification-modal, .modal-overlay.active .friends-modal { transform: translateY(0) scale(1); }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--gradient); color: white; }
        .modal-header h3 { font-size: 1.3rem; font-weight: 600; }
        .modal-close { background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); }
        .modal-close:hover { background: rgba(255, 255, 255, 0.1); transform: rotate(90deg); }
        .modal-body { max-height: 60vh; overflow-y: auto; scrollbar-width: none; -ms-overflow-style: none; }
        .modal-body::-webkit-scrollbar { display: none; }
        .notification-item { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: flex-start; gap: 1rem; transition: var(--transition); position: relative; overflow: hidden; }
        .notification-item::before { content: ''; position: absolute; left: -100%; top: 0; width: 100%; height: 100%; background: var(--gradient-light); transition: var(--transition); }
        .notification-item:hover { background: var(--sidebar-hover); }
        .notification-item:hover::before { left: 0; }
        .notification-item:last-child { border-bottom: none; }
        .notification-item.unread { background: rgba(99, 102, 241, 0.05); }
        .notification-icon { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0; position: relative; z-index: 1; transition: var(--transition); }
        .notification-item:hover .notification-icon { transform: scale(1.1); background: var(--primary); color: white; }
        .notification-content { flex: 1; position: relative; z-index: 1; }
        .notification-message { margin-bottom: 0.25rem; line-height: 1.4; }
        .notification-meta { font-size: 0.8rem; color: var(--gray); }
        .notification-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .notification-action { background: none; border: none; color: var(--primary); font-size: 0.8rem; cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 4px; transition: var(--transition); position: relative; z-index: 1; }
        .notification-action:hover { background: var(--primary-light); transform: translateY(-1px); }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; }
        
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
        @keyframes messageSlide { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        @media (max-width: 1024px) {
            .sidebar { width: 80px; }
            .sidebar .nav-text, .sidebar .logo-text, .sidebar .nav-title, .sidebar .nav-badge { display: none; }
            .main-content { margin-left: 80px; }
            .chat-widget { width: 350px; }
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
            .chat-widget { width: calc(100vw - 2rem); right: 1rem; bottom: 1rem; }
            .chat-toggle { right: 1rem; bottom: 1rem; }
            .message-popup { width: 90%; max-width: 350px; }
        }
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

.friend-badge, .notification-badge, .message-badge {
    transition: all 0.3s ease-in-out;
}

.repo-stat, .activity-item {
    transition: all 0.3s ease-in-out;
}

.new-update {
    animation: highlight 2s ease-in-out;
}

@keyframes highlight {
    0% { background-color: var(--primary-light); }
    100% { background-color: transparent; }
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
                <a href="dashboard.php" class="nav-item active"><i class="fas fa-home nav-icon"></i><span class="nav-text">Dashboard</span></a>
                <a href="all_repos.php" class="nav-item"><i class="fas fa-code-branch nav-icon"></i><span class="nav-text">All Repositories</span><span class="nav-badge"><?php echo $stats['total_repos']; ?></span></a>
                <a href="repo.php" class="nav-item"><i class="fas fa-plus-circle nav-icon"></i><span class="nav-text">New Repository</span></a>
                <a href="code_editor.php" class="nav-item"><i class="fas fa-code nav-icon"></i><span class="nav-text">Code Editor</span></a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Collaborate</div>
                <a href="collaborations.php" class="nav-item"><i class="fas fa-users nav-icon"></i><span class="nav-text">Collaborations</span><span class="nav-badge"><?php echo $stats['collaborator_repos']; ?></span></a>
                <a href="notifications.php" class="nav-item"><i class="fas fa-bell nav-icon"></i><span class="nav-text">Notifications</span><span class="nav-badge" id="notificationCount"><?php echo $unreadNotificationCount; ?></span></a>
                <a href="messages.php" class="nav-item"><i class="fas fa-envelope nav-icon"></i><span class="nav-text">Messages</span><span class="nav-badge" id="sidebarMessageCount"><?php echo $unreadMessagesCount; ?></span></a>
                <a href="friends.php" class="nav-item"><i class="fas fa-user-friends nav-icon"></i><span class="nav-text">Friends</span><span class="nav-badge"><?php echo $stats['friends_count']; ?></span></a>
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

    <!-- AI Assistant Chatbot -->
<div class="chatbot-widget" id="chatbotWidget">
    <div class="chatbot-header">
        <div class="chatbot-avatar">
            <i class="fas fa-robot"></i>
            <div class="online-status online"></div>
        </div>
        <div class="chatbot-info">
            <h4>CodeSphere AI</h4>
            <p>Online • Ready to help</p>
        </div>
        <div class="chatbot-actions">
            <button class="chatbot-action-btn" id="clearChatbotChat" title="Clear chat">
                <i class="fas fa-eraser"></i>
            </button>
            <button class="chatbot-close" id="chatbotClose">
                <ion-icon name="close-outline"></ion-icon>
            </button>
        </div>
    </div>
    <div class="chatbot-messages" id="chatbotMessages">
        <div class="message received">
            <div class="message-content">
                <strong>👋 Hello! I'm CodeSphere AI Assistant</strong><br><br>
                I can help you with:<br>
                • Code explanations & debugging<br>
                • Repository management<br>
                • Git commands & workflows<br>
                • Best practices & tips<br>
                • Project guidance<br><br>
                What can I help you with today?
            </div>
            <div class="message-meta">
                <span>AI Assistant</span>
                <span>Just now</span>
            </div>
        </div>
    </div>
    <div class="chatbot-typing" id="chatbotTyping" style="display: none;">
        <div class="typing-indicator">
            <span>CodeSphere AI is typing</span>
            <div class="typing-dots">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        </div>
    </div>
    <div class="chatbot-input">
        <form class="chatbot-form" id="chatbotForm">
            <div class="chatbot-input-container">
                <textarea class="chatbot-message-input" id="chatbotMessageInput" placeholder="Ask me anything about coding, repositories, or CodeSphere..." rows="1"></textarea>
                <button type="submit" class="chatbot-send-btn">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
            <div class="chatbot-quick-actions">
                <button type="button" class="quick-action-btn" data-prompt="How do I create a new repository?">
                    <i class="fas fa-plus-circle"></i> New Repo
                </button>
                <button type="button" class="quick-action-btn" data-prompt="Show me common Git commands">
                    <i class="fas fa-code-branch"></i> Git Help
                </button>
                <button type="button" class="quick-action-btn" data-prompt="How to collaborate with friends?">
                    <ion-icon name="people-outline"></ion-icon> Collaborate
                </button>
            </div>
        </form>
    </div>
</div>

<button class="chatbot-toggle" id="chatbotToggle">
    <i class="fas fa-robot"></i>
    <span class="chatbot-notification" id="chatbotNotification" style="display: none;"></span>
</button>


<style>
    /* Chatbot Widget Styles */
.chatbot-widget {
    position: fixed;
    bottom: 2rem;
    right: 6rem;
    width: 380px;
    height: 600px;
    background: var(--card-bg);
    border-radius: var(--radius-2xl);
    box-shadow: var(--shadow-2xl), 0 0 30px rgba(99, 102, 241, 0.3);
    z-index: 1002;
    display: none;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--border);
    backdrop-filter: blur(20px);
    transform: translateY(20px) scale(0.95);
    opacity: 0;
    transition: var(--transition-slow);
}

.chatbot-widget.active {
    display: flex;
    transform: translateY(0) scale(1);
    opacity: 1;
    animation: slideInUp 0.5s ease-out;
}

.chatbot-header {
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: white;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    position: relative;
}

.chatbot-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 100px;
    height: 100px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    animation: float 6s infinite ease-in-out;
}

.chatbot-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    position: relative;
    flex-shrink: 0;
}

.chatbot-info {
    flex: 1;
}

.chatbot-info h4 {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: 0.25rem;
}

.chatbot-info p {
    font-size: 0.8rem;
    opacity: 0.9;
}

.chatbot-actions {
    display: flex;
    gap: 0.25rem;
}

.chatbot-action-btn, .chatbot-close {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: var(--radius);
    transition: var(--transition);
    font-size: 0.9rem;
}

.chatbot-action-btn:hover, .chatbot-close:hover {
    background: rgba(255, 255, 255, 0.1);
    transform: scale(1.1);
}

.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    scrollbar-width: thin;
    scrollbar-color: var(--primary) transparent;
}

.chatbot-messages::-webkit-scrollbar {
    width: 6px;
}

.chatbot-messages::-webkit-scrollbar-track {
    background: transparent;
}

.chatbot-messages::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 3px;
}

.chatbot-typing {
    padding: 0.5rem 1rem;
}

.chatbot-input {
    padding: 1rem;
    border-top: 1px solid var(--border);
    background: var(--card-bg);
}

.chatbot-input-container {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.chatbot-message-input {
    flex: 1;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--light);
    color: var(--dark);
    font-size: 0.9rem;
    resize: none;
    font-family: 'Inter', sans-serif;
    transition: var(--transition);
    max-height: 120px;
}

.chatbot-message-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.chatbot-send-btn {
    padding: 0.75rem 1.25rem;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: white;
    border: none;
    border-radius: var(--radius);
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.chatbot-send-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.chatbot-send-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.chatbot-quick-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.quick-action-btn {
    background: var(--gradient-light);
    border: 1px solid var(--border);
    color: var(--dark);
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius);
    font-size: 0.8rem;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 0.25rem;
    flex: 1;
    min-width: 0;
    justify-content: center;
}

.quick-action-btn:hover {
    background: var(--primary-light);
    border-color: var(--primary);
    transform: translateY(-1px);
}

.chatbot-toggle {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #8b5cf6, #6366f1);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    box-shadow: var(--shadow-2xl), 0 0 20px rgba(139, 92, 246, 0.4);
    transition: var(--transition);
    z-index: 1001;
    animation: pulse 2s infinite, bounceUp 3s infinite ease-in-out;
}

.chatbot-toggle:hover {
    transform: scale(1.1) rotate(10deg);
}

.chatbot-notification {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ef4444;
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
}

/* AI Message Styling */
.chatbot-messages .message.received {
    align-self: flex-start;
    background: var(--gradient-light);
    border: 1px solid var(--border);
    border-left: 4px solid #8b5cf6;
}

.chatbot-messages .message.sent {
    align-self: flex-end;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

/* Code blocks in AI responses */
.code-block {
    background: var(--darker);
    color: var(--light);
    padding: 1rem;
    border-radius: var(--radius);
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
    margin: 0.5rem 0;
    overflow-x: auto;
    border: 1px solid var(--border);
}

.code-block .code-keyword { color: #ff79c6; }
.code-block .code-function { color: #50fa7b; }
.code-block .code-string { color: #f1fa8c; }
.code-block .code-comment { color: #6272a4; }
.code-block .code-variable { color: #8be9fd; }

/* Responsive */
@media (max-width: 768px) {
    .chatbot-widget {
        width: calc(100vw - 2rem);
        right: 1rem;
        bottom: 1rem;
        height: 70vh;
    }
    
    .chatbot-toggle {
        right: 1rem;
        bottom: 1rem;
    }
}
</style>


    <div class="main-content" id="mainContent">
        <header>
            <div class="top-nav">
                <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
                <div class="search-bar">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" id="searchInput" placeholder="Search public repositories...">
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
            <?php if (isset($messageSuccess)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $messageSuccess; ?></div><?php endif; ?>
            <?php if (isset($messageError)): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $messageError; ?></div><?php endif; ?>

            <div class="welcome-section">
                <div class="welcome-content">
                    <h1><i class="fas fa-rocket welcome-icon"></i>Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
                    <p>Your coding journey continues. You're on a <?php echo $contributionStreak; ?>-day contribution streak. Keep up the amazing work!</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-header"><div class="stat-icon repository"><i class="fas fa-code-branch"></i></div><span class="stat-trend positive">+<?php echo $stats['total_repos'] > 0 ? round($stats['total_repos'] * 0.1) : 0; ?></span></div><h3><?php echo $stats['total_repos']; ?></h3><p>Total Repositories</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon owner"><i class="fas fa-crown"></i></div><span class="stat-trend positive">+<?php echo $stats['owner_repos'] > 0 ? round($stats['owner_repos'] * 0.1) : 0; ?></span></div><h3><?php echo $stats['owner_repos']; ?></h3><p>Owned Repositories</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon commit"><i class="fas fa-code-commit"></i></div><span class="stat-trend positive">+<?php echo $stats['total_commits'] > 0 ? round($stats['total_commits'] * 0.05) : 0; ?></span></div><h3><?php echo $stats['total_commits']; ?></h3><p>Total Commits</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon streak"><i class="fas fa-fire-flame-curved"></i></div><span class="stat-trend positive">+<?php echo $contributionStreak; ?></span></div><h3><?php echo $contributionStreak; ?></h3><p>Day Streak</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon message"><i class="fas fa-envelope"></i></div><span class="stat-trend positive">+<?php echo $unreadMessagesCount; ?></span></div><h3><?php echo $unreadMessagesCount; ?></h3><p>Unread Messages</p></div>
                <div class="stat-card"><div class="stat-header"><div class="stat-icon friends"><i class="fas fa-user-friends"></i></div><span class="stat-trend positive">+<?php echo $stats['friends_count']; ?></span></div><h3><?php echo $stats['friends_count']; ?></h3><p>Friends</p></div>
            </div>

            <div class="add-friend">
                <h3>Add New Friend</h3>
                <form method="POST" class="friend-form">
                    <input type="text" class="friend-input" name="receiver_id" placeholder="Enter User ID to send friend request" required>
                    <button type="submit" name="send_friend_request" class="btn btn-primary"><i class="fas fa-user-plus"></i>Send Request</button>
                </form>
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
                    <h2>Your Friends</h2>
                    <div><button class="btn btn-primary" id="viewAllFriends"><ion-icon name="list-outline"></ion-icon>View All Friends</button></div>
                </div>
                <div class="friends-grid">
                    <?php if (count($friends) > 0): ?>
                        <?php foreach (array_slice($friends, 0, 5) as $friend): ?>
                            <div class="friend-card" onclick="openFriendChat('<?php echo $friend['userId']; ?>', '<?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>')">
                                <div class="friend-header">
                                    <div class="friend-name"><i class="fas fa-user friend-icon"></i><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?></div>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <span class="friend-badge <?php echo $friendOnlineStatus[$friend['userId']]; ?>"><?php echo ucfirst($friendOnlineStatus[$friend['userId']]); ?></span>
                                        <?php if (isset($friendUnreadCounts[$friend['userId']]) && $friendUnreadCounts[$friend['userId']] > 0): ?><span class="nav-badge"><?php echo $friendUnreadCounts[$friend['userId']]; ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <p class="friend-email">@<?php echo htmlspecialchars($friend['username']); ?></p>
                                <div class="friend-actions">
                                    <span class="friend-action"><i class="fas fa-envelope"></i>Message</span>
                                    <span class="friend-action"><i class="fas fa-code"></i>Collaborate</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-user-friends"></i><h3>No friends yet</h3><p>Add friends to collaborate and chat</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="activity-feed">
                <div class="section-header"><h2>Recent Activity</h2></div>
                <?php
                $recentActivities = [];
                foreach ($userRepos as $repo) {
                    $repoDb = $repo['repo_id'];
                    $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoDb);
                    if (!$repoConn->connect_error) {
                        $activityStmt = $repoConn->prepare("SELECT * FROM commits WHERE author_id = ? ORDER BY timestamp DESC LIMIT 3");
                        $activityStmt->bind_param("s", $_SESSION['user_id']);
                        $activityStmt->execute();
                        $activities = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        foreach ($activities as $activity) {
                            $recentActivities[] = ['message' => "Committed to " . $repo['repo_id'] . ": " . $activity['message'], 'time' => $activity['timestamp']];
                        }
                        $repoConn->close();
                    }
                }
                usort($recentActivities, function($a, $b) { return strtotime($b['time']) - strtotime($a['time']); });
                $recentActivities = array_slice($recentActivities, 0, 5);
                ?>
                <?php if (count($recentActivities) > 0): ?>
                    <?php foreach ($recentActivities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon"><i class="fas fa-code-commit"></i></div>
                            <div class="activity-content">
                                <div class="activity-message"><?php echo htmlspecialchars($activity['message']); ?></div>
                                <div class="activity-meta"><?php echo date('M j, Y g:i A', strtotime($activity['time'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 2rem;"><i class="fas fa-code-commit"></i><h3>No recent activity</h3><p>Your commits will appear here once you start coding</p></div>
                <?php endif; ?>
            </div>

            <div class="repos-section">
                <div class="section-header">
                    <h2>Recent Repositories</h2>
                    <div>
                        <a href="repo.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i>New Repository</a>
                        <a href="all_repos.php" class="btn btn-secondary"><ion-icon name="list-outline"></ion-icon>View All (<?php echo $stats['total_repos']; ?>)</a>
                    </div>
                </div>
                <div class="repos-grid">
                    <?php if (count($recentRepos) > 0): ?>
                        <?php foreach ($recentRepos as $repo): ?>
                            <div class="repo-card" onclick="window.location.href='repo_view.php?repo=<?php echo $repo['repo_id']; ?>'">
                                <div class="repo-header">
                                    <div class="repo-name"><i class="fas fa-book repo-icon"></i><?php echo htmlspecialchars($repo['repo_id']); ?></div>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <span class="repo-badge <?php echo $repo['repo_access'] ?? 'public'; ?>"><?php echo ucfirst($repo['repo_access'] ?? 'public'); ?></span>
                                        <span class="repo-badge <?php echo $repo['role'] ?? 'owner'; ?>"><?php echo ucfirst($repo['role'] ?? 'Owner'); ?></span>
                                    </div>
                                </div>
                                <p class="repo-description">Repository <?php echo $repo['role'] === 'collaborator' ? 'shared with you' : 'created'; ?> on <?php echo date('M j, Y', strtotime($repo['access_start'] ?? 'now')); ?></p>
                                <div class="repo-stats">
                                    <span class="repo-stat"><i class="fas fa-code-branch"></i>main</span>
                                    <span class="repo-stat"><i class="fas fa-user"></i><?php echo $repo['role'] ?? 'Owner'; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-code-branch"></i><h3>No repositories yet</h3><p>Get started by creating your first repository</p><a href="repo.php" class="btn btn-primary" style="margin-top: 1rem;"><i class="fas fa-plus-circle"></i>Create Repository</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="message-popup" id="messagePopup">
        <div class="message-popup-header">
            <h3>Messages</h3>
            <div class="message-popup-actions">
                <button class="read-all-btn" id="readAllMessages" title="Mark all as read"><i class="fas fa-check-double"></i>Read All</button>
                <button class="message-popup-close" id="messagePopupClose"><ion-icon name="close-outline"></ion-icon></button>
            </div>
        </div>
        <div class="message-popup-body">
            <div class="conversation-list" id="conversationList"></div>
            <div class="message-preview-container" id="messagePreviewContainer" style="display: none;">
                <div class="active-conversation">
                    <div class="active-conversation-header">
                        <div class="active-conversation-user">
                            <div class="conversation-avatar"><span id="activeUserAvatar">U</span><div class="online-status" id="activeUserStatus"></div></div>
                            <div><h4 id="activeUserName">User Name</h4><p id="activeUserStatusText">Online</p></div>
                        </div>
                        <button class="btn btn-primary btn-sm" id="openChatBtn"><ion-icon name="chatbubble-outline"></ion-icon>Open Chat</button>
                    </div>
                    <div class="active-conversation-messages" id="activeConversationMessages"></div>
                </div>
            </div>
            <div class="quick-message-form" id="quickMessageForm" style="display: none;">
                <select class="recipient-select" id="recipientSelect" name="receiver_id" required><option value="">Select a recipient...</option><?php foreach ($friends as $friend): ?><option value="<?php echo $friend['userId']; ?>"><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?> (@<?php echo htmlspecialchars($friend['username']); ?>)</option><?php endforeach; ?></select>
                <textarea class="message-popup-textarea" id="messagePopupTextarea" name="message" placeholder="Type your message here..." required></textarea>
            </div>
        </div>
        <div class="message-popup-footer">
            <button type="button" class="btn btn-outline" id="cancelQuickMessage">Cancel</button>
            <div style="display: flex; gap: 0.75rem;">
                <button type="button" class="btn btn-secondary" id="newMessageBtn"><ion-icon name="add-outline"></ion-icon>New Message</button>
                <button type="submit" form="quickMessageForm" class="btn btn-primary" name="send_quick_message" style="display: none;" id="sendQuickMessageBtn"><i class="fas fa-paper-plane"></i>Send Message</button>
            </div>
        </div>
    </div>

    <button class="chat-toggle" id="chatToggle"><i class="fas fa-comment-dots"></i></button>

    <div class="chat-widget" id="chatWidget">
        <div class="chat-header">
            <div class="chat-user">
                <div class="chat-user-avatar" id="chatUserAvatar">U<div class="online-status" id="chatUserStatusDot"></div></div>
                <div class="chat-user-info">
                    <h4 id="chatUserName">Select a friend to chat<span class="realtime-dot" id="realtimeDot" style="display: none;"></span></h4>
                    <p id="chatUserStatus">Online</p>
                </div>
            </div>
            <div class="chat-actions">
                <button class="chat-action-btn" id="markAsReadBtn" title="Mark as read"><i class="fas fa-check-double"></i></button>
                <button class="chat-close" id="chatClose"><ion-icon name="close-outline"></ion-icon></button>
            </div>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="message received">
                <div class="message-content">Welcome to CodeSphere Chat! Select a friend to start messaging.</div>
                <div class="message-meta"><span>System</span><span>Just now</span></div>
            </div>
        </div>
        <div class="typing-indicator" id="typingIndicator" style="display: none;">
            <span id="typingUser">User</span> is typing
            <div class="typing-dots"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>
        </div>
        <div class="chat-input">
            <div class="reply-indicator" id="replyIndicator" style="display: none;">
                <span>Replying to: <strong id="replyToUser">User</strong></span>
                <button class="cancel-reply" id="cancelReply"><ion-icon name="close-outline"></ion-icon></button>
            </div>
            <form class="chat-form" id="chatForm" method="POST">
                <input type="hidden" id="currentReceiver" name="receiver_id">
                <input type="hidden" id="currentFriendId" name="friend_id">
                <input type="hidden" id="parentMessageId" name="parent_message_id">
                <textarea class="message-input" id="messageInput" name="message" placeholder="Type your message..." rows="1" disabled></textarea>
                <button type="submit" class="send-btn" name="send_friend_message" disabled><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="notificationModal">
        <div class="notification-modal">
            <div class="modal-header"><h3>Notifications</h3><button class="modal-close" id="modalClose"><ion-icon name="close-outline"></ion-icon></button></div>
            <div class="modal-body">
                <?php if (count($notifications) > 0): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                            <div class="notification-icon"><i class="fas fa-bell"></i></div>
                            <div class="notification-content">
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-meta"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></div>
                                <?php if ($notification['link']): ?>
                                    <div class="notification-actions">
                                        <a href="<?php echo $notification['link']; ?>" class="notification-action"><ion-icon name="open-outline"></ion-icon>View</a>
                                        <?php if (!$notification['is_read']): ?><form method="POST" style="display: inline;"><input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>"><button type="submit" name="mark_as_read" class="notification-action">Mark as read</button></form><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 2rem;"><i class="fas fa-bell-slash"></i><h3>No notifications</h3><p>You're all caught up!</p></div>
                <?php endif; ?>
            </div>
            <?php if (count($notifications) > 0): ?>
                <div class="modal-footer">
                    <form method="POST"><button type="submit" name="mark_all_read" class="btn btn-outline btn-sm">Mark all as read</button></form>
                    <a href="notifications.php" class="btn btn-primary btn-sm">View all notifications</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal-overlay" id="friendsModal">
        <div class="friends-modal">
            <div class="modal-header"><h3>Your Friends</h3><button class="modal-close" id="friendsModalClose"><ion-icon name="close-outline"></ion-icon></button></div>
            <div class="modal-body">
                <?php if (count($friends) > 0): ?>
                    <?php foreach ($friends as $friend): ?>
                        <div class="friend-card" onclick="openFriendChat('<?php echo $friend['userId']; ?>', '<?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?>')">
                            <div class="friend-header">
                                <div class="friend-name"><i class="fas fa-user friend-icon"></i><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?></div>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <span class="friend-badge <?php echo $friendOnlineStatus[$friend['userId']]; ?>"><?php echo ucfirst($friendOnlineStatus[$friend['userId']]); ?></span>
                                    <?php if (isset($friendUnreadCounts[$friend['userId']]) && $friendUnreadCounts[$friend['userId']] > 0): ?><span class="nav-badge"><?php echo $friendUnreadCounts[$friend['userId']]; ?></span><?php endif; ?>
                                </div>
                            </div>
                            <p class="friend-email">@<?php echo htmlspecialchars($friend['username']); ?></p>
                            <div class="friend-actions">
                                <span class="friend-action"><i class="fas fa-envelope"></i>Message</span>
                                <span class="friend-action"><i class="fas fa-code"></i>Collaborate</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 2rem;"><i class="fas fa-user-friends"></i><h3>No friends yet</h3><p>Add friends to collaborate and chat</p></div>
                <?php endif; ?>
            </div>
        </div>
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
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationModal = document.getElementById('notificationModal');
    const modalClose = document.getElementById('modalClose');
    const friendsBtn = document.getElementById('friendsBtn');
    const friendsModal = document.getElementById('friendsModal');
    const friendsModalClose = document.getElementById('friendsModalClose');
    const viewAllFriends = document.getElementById('viewAllFriends');
    const chatToggle = document.getElementById('chatToggle');
    const chatWidget = document.getElementById('chatWidget');
    const chatClose = document.getElementById('chatClose');
    const chatForm = document.getElementById('chatForm');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('chatForm').querySelector('button[type="submit"]');
    const chatMessages = document.getElementById('chatMessages');
    const currentReceiver = document.getElementById('currentReceiver');
    const currentFriendId = document.getElementById('currentFriendId');
    const parentMessageId = document.getElementById('parentMessageId');
    const replyIndicator = document.getElementById('replyIndicator');
    const cancelReply = document.getElementById('cancelReply');
    const replyToUser = document.getElementById('replyToUser');
    const messageBtn = document.getElementById('messageBtn');
    const messagePopup = document.getElementById('messagePopup');
    const messagePopupClose = document.getElementById('messagePopupClose');
    const cancelQuickMessage = document.getElementById('cancelQuickMessage');
    const conversationList = document.getElementById('conversationList');
    const recipientSelect = document.getElementById('recipientSelect');
    const messagePopupTextarea = document.getElementById('messagePopupTextarea');
    const quickMessageForm = document.getElementById('quickMessageForm');
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const readAllMessages = document.getElementById('readAllMessages');
    const openChatBtn = document.getElementById('openChatBtn');
    const newMessageBtn = document.getElementById('newMessageBtn');
    const sendQuickMessageBtn = document.getElementById('sendQuickMessageBtn');
    const messagePreviewContainer = document.getElementById('messagePreviewContainer');
    const markAsReadBtn = document.getElementById('markAsReadBtn');
    const realtimeDot = document.getElementById('realtimeDot');
    const typingIndicator = document.getElementById('typingIndicator');
    const typingUser = document.getElementById('typingUser');

    let currentActiveConversation = null;
    let messageCheckInterval = null;
    let onlineStatusInterval = null;
    let currentReplyMessage = null;
    let currentFriendChat = null;
    let searchTimeout;

    // Remove old theme initialization since we're using the new one above
    // if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    //     document.body.classList.add('dark-mode');
    //     themeIcon.classList.remove('fa-moon');
    //     themeIcon.classList.add('fa-sun');
    // }

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
    });
    mobileMenuBtn.addEventListener('click', () => sidebar.classList.toggle('mobile-open'));
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) sidebar.classList.remove('mobile-open');
    });
    
    // Remove old theme toggle event listener since we're using the new one above
    // themeToggle.addEventListener('click', () => {
    //     document.body.classList.toggle('dark-mode');
    //     if (document.body.classList.contains('dark-mode')) {
    //         themeIcon.classList.remove('fa-moon');
    //         themeIcon.classList.add('fa-sun');
    //         localStorage.setItem('theme', 'dark');
    //     } else {
    //         themeIcon.classList.remove('fa-sun');
    //         themeIcon.classList.add('fa-moon');
    //         localStorage.setItem('theme', 'light');
    //     }
    // });
    
    // Chatbot functionality
const chatbotToggle = document.getElementById('chatbotToggle');
const chatbotWidget = document.getElementById('chatbotWidget');
const chatbotClose = document.getElementById('chatbotClose');
const chatbotForm = document.getElementById('chatbotForm');
const chatbotMessageInput = document.getElementById('chatbotMessageInput');
const chatbotMessages = document.getElementById('chatbotMessages');
const chatbotTyping = document.getElementById('chatbotTyping');
const clearChatbotChat = document.getElementById('clearChatbotChat');
const quickActionBtns = document.querySelectorAll('.quick-action-btn');

// Chatbase API configuration (Replace with your actual API key)
const CHATBASE_API_KEY = 'your-chatbase-api-key-here';
const CHATBASE_BOT_ID = 'your-bot-id-here';

// Alternative: OpenAI API (if you prefer)
const OPENAI_API_KEY = 'your-openai-api-key-here';

chatbotToggle.addEventListener('click', () => {
    chatbotWidget.classList.toggle('active');
});

chatbotClose.addEventListener('click', () => {
    chatbotWidget.classList.remove('active');
});

clearChatbotChat.addEventListener('click', () => {
    if (confirm('Clear all chat history with AI Assistant?')) {
        chatbotMessages.innerHTML = `
            <div class="message received">
                <div class="message-content">
                    <strong>👋 Hello! I'm CodeSphere AI Assistant</strong><br><br>
                    I can help you with:<br>
                    • Code explanations & debugging<br>
                    • Repository management<br>
                    • Git commands & workflows<br>
                    • Best practices & tips<br>
                    • Project guidance<br><br>
                    What can I help you with today?
                </div>
                <div class="message-meta">
                    <span>AI Assistant</span>
                    <span>Just now</span>
                </div>
            </div>
        `;
    }
});

// Quick action buttons
quickActionBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        const prompt = btn.getAttribute('data-prompt');
        chatbotMessageInput.value = prompt;
        chatbotForm.dispatchEvent(new Event('submit'));
    });
});

// Auto-resize textarea
chatbotMessageInput.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
});

// Chat form submission
chatbotForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const message = chatbotMessageInput.value.trim();
    if (!message) return;
    
    // Add user message to chat
    addMessageToChatbot(message, 'sent');
    chatbotMessageInput.value = '';
    chatbotMessageInput.style.height = 'auto';
    
    // Show typing indicator
    chatbotTyping.style.display = 'block';
    chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    
    try {
        // Get AI response
        const response = await getAIResponse(message);
        addMessageToChatbot(response, 'received');
    } catch (error) {
        console.error('Chatbot error:', error);
        addMessageToChatbot(
            "I'm having trouble connecting right now. Please try again in a moment or check your internet connection.",
            'received'
        );
    } finally {
        chatbotTyping.style.display = 'none';
    }
});

// Add message to chatbot
function addMessageToChatbot(content, type) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${type}`;
    
    const now = new Date();
    const timeText = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    
    // Format code blocks if present
    let formattedContent = content;
    if (content.includes('```')) {
        formattedContent = formatCodeBlocks(content);
    }
    
    messageDiv.innerHTML = `
        <div class="message-content">${formattedContent}</div>
        <div class="message-meta">
            <span>${type === 'sent' ? 'You' : 'CodeSphere AI'}</span>
            <span>${timeText}</span>
        </div>
    `;
    
    chatbotMessages.appendChild(messageDiv);
    chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
}

// Format code blocks in responses
function formatCodeBlocks(content) {
    return content.replace(/```(\w+)?\n([\s\S]*?)```/g, (match, language, code) => {
        const lang = language || 'code';
        return `<div class="code-block">
            <div style="margin-bottom: 0.5rem; font-size: 0.7rem; opacity: 0.7;">${lang.toUpperCase()}</div>
            <pre>${escapeHtml(code.trim())}</pre>
        </div>`;
    });
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// AI Response Function (Choose one method)
async function getAIResponse(userMessage) {
    // Method 1: Chatbase API
    return await getChatbaseResponse(userMessage);
    
    // Method 2: OpenAI API (uncomment to use)
    // return await getOpenAIResponse(userMessage);
    
    // Method 3: Fallback responses (if no API available)
    // return getFallbackResponse(userMessage);
}

// Method 1: Chatbase API
async function getChatbaseResponse(userMessage) {
    try {
        const response = await fetch('https://www.chatbase.co/api/v1/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${CHATBASE_API_KEY}`
            },
            body: JSON.stringify({
                messages: [
                    {
                        role: 'user',
                        content: userMessage
                    }
                ],
                chatbotId: CHATBASE_BOT_ID,
                stream: false,
                temperature: 0.7
            })
        });

        if (!response.ok) {
            throw new Error(`Chatbase API error: ${response.status}`);
        }

        const data = await response.json();
        return data.text || "I apologize, but I couldn't generate a response at the moment.";
    } catch (error) {
        console.error('Chatbase API error:', error);
        return getFallbackResponse(userMessage);
    }
}

// Method 2: OpenAI API
async function getOpenAIResponse(userMessage) {
    try {
        const response = await fetch('https://api.openai.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${OPENAI_API_KEY}`
            },
            body: JSON.stringify({
                model: 'gpt-3.5-turbo',
                messages: [
                    {
                        role: 'system',
                        content: `You are CodeSphere AI, a helpful coding assistant for a GitHub-like platform. You help users with:
                        - Repository creation and management
                        - Git commands and workflows
                        - Code debugging and explanations
                        - Collaboration features
                        - Best practices and tips
                        
                        Be concise, helpful, and provide code examples when relevant. Format code blocks with \`\`\`language.`
                    },
                    {
                        role: 'user',
                        content: userMessage
                    }
                ],
                max_tokens: 500,
                temperature: 0.7
            })
        });

        if (!response.ok) {
            throw new Error(`OpenAI API error: ${response.status}`);
        }

        const data = await response.json();
        return data.choices[0].message.content;
    } catch (error) {
        console.error('OpenAI API error:', error);
        return getFallbackResponse(userMessage);
    }
}

// Method 3: Fallback responses (when no API available)
function getFallbackResponse(userMessage) {
    const lowerMessage = userMessage.toLowerCase();
    
    // Repository-related queries
    if (lowerMessage.includes('repo') || lowerMessage.includes('repository')) {
        return `To create a new repository in CodeSphere:

\`\`\`bash
# Steps to create a new repository:
1. Click the "New Repository" button in sidebar
2. Enter repository name and description
3. Choose visibility (public/private)
4. Initialize with README (recommended)
5. Click "Create Repository"

# Or use Git commands:
git init
git add .
git commit -m "Initial commit"
git branch -M main
\`\`\`

Would you like more specific guidance?`;
    }
    
    // Git commands
    if (lowerMessage.includes('git') || lowerMessage.includes('commit') || lowerMessage.includes('push')) {
        return `Here are essential Git commands:

\`\`\`bash
# Basic workflow:
git status                    # Check changes
git add .                    # Stage all changes
git add filename.txt         # Stage specific file
git commit -m "Your message" # Commit changes
git push origin main         # Push to remote

# Branching:
git branch                   # List branches
git branch new-feature       # Create branch
git checkout new-feature     # Switch branch
git merge main               # Merge branches

# Collaboration:
git pull origin main         # Get latest changes
git fetch origin             # Download objects
git clone <repository-url>   # Clone repository
\`\`\`

Need help with a specific Git scenario?`;
    }
    
    // Collaboration
    if (lowerMessage.includes('collaborat') || lowerMessage.includes('friend') || lowerMessage.includes('team')) {
        return `To collaborate with others in CodeSphere:

**Adding Collaborators:**
1. Go to your repository settings
2. Click "Collaborators" tab
3. Enter friend's username or email
4. Set permission level (read/write/admin)

**Team Features:**
• Real-time code collaboration
• Shared repositories
• Team discussions
• Pull request reviews

**Best Practices:**
• Use feature branches
• Create pull requests for changes
• Review each other's code
• Use issues for tracking tasks

Would you like to know more about any specific collaboration feature?`;
    }
    
    // Default response
    return `I understand you're asking about: "${userMessage}"

While I'm currently operating in limited mode, I can still help you with:

• **Repository Management**: Creating, organizing, and managing your code repositories
• **Git Commands**: Common workflows, branching strategies, and collaboration
• **Code Assistance**: Basic programming concepts and best practices
• **Platform Features**: How to use CodeSphere's collaboration tools

For more advanced AI assistance, please configure the API keys in the chatbot settings.

What specific aspect would you like me to help you with?`;
}

// Auto-open chatbot for first-time users
document.addEventListener('DOMContentLoaded', () => {
    const hasSeenChatbot = localStorage.getItem('hasSeenChatbot');
    if (!hasSeenChatbot) {
        setTimeout(() => {
            chatbotWidget.classList.add('active');
            localStorage.setItem('hasSeenChatbot', 'true');
        }, 3000);
    }
});

// Welcome message when chatbot first opens
chatbotToggle.addEventListener('click', () => {
    const hasInteracted = localStorage.getItem('chatbotInteracted');
    if (!hasInteracted) {
        setTimeout(() => {
            addMessageToChatbot(
                "Welcome to CodeSphere AI! I'm here to help you with coding questions, repository management, Git workflows, and anything else related to your development journey. Feel free to ask me anything!",
                'received'
            );
            localStorage.setItem('chatbotInteracted', 'true');
        }, 1000);
    }
});

// Chatbot Configuration (Add to your settings page)
const chatbotConfig = {
    // Choose your AI provider:
    provider: 'chatbase', // 'chatbase', 'openai', or 'fallback'
    
    // API Keys (set these in your environment or settings)
    apiKeys: {
        chatbase: 'your-chatbase-api-key',
        openai: 'your-openai-api-key'
    },
    
    // Bot behavior
    settings: {
        autoOpen: true,
        showNotifications: true,
        maxHistory: 50,
        temperature: 0.7
    }
};

    notificationBtn.addEventListener('click', () => notificationModal.classList.add('active'));
    modalClose.addEventListener('click', () => notificationModal.classList.remove('active'));
    friendsBtn.addEventListener('click', () => friendsModal.classList.add('active'));
    viewAllFriends.addEventListener('click', () => friendsModal.classList.add('active'));
    friendsModalClose.addEventListener('click', () => friendsModal.classList.remove('active'));
    chatToggle.addEventListener('click', () => chatWidget.classList.toggle('active'));
    chatClose.addEventListener('click', () => chatWidget.classList.remove('active'));
    messageBtn.addEventListener('click', () => {
        messagePopup.classList.add('active');
        loadConversations();
        showConversationList();
    });
    messagePopupClose.addEventListener('click', () => {
        messagePopup.classList.remove('active');
        stopMessageChecking();
        stopOnlineStatusChecking();
    });
    cancelQuickMessage.addEventListener('click', () => showConversationList());
    newMessageBtn.addEventListener('click', () => showNewMessageForm());
    readAllMessages.addEventListener('click', () => markAllMessagesAsRead());
    openChatBtn.addEventListener('click', () => {
        if (currentActiveConversation) {
            openFriendChat(currentActiveConversation.id, currentActiveConversation.name);
            messagePopup.classList.remove('active');
        }
    });
    messagePopup.addEventListener('click', (e) => {
        if (e.target === messagePopup) {
            messagePopup.classList.remove('active');
            stopMessageChecking();
            stopOnlineStatusChecking();
        }
    });
    notificationModal.addEventListener('click', (e) => { if (e.target === notificationModal) notificationModal.classList.remove('active'); });
    friendsModal.addEventListener('click', (e) => { if (e.target === friendsModal) friendsModal.classList.remove('active'); });

    function showConversationList() {
        messagePreviewContainer.style.display = 'none';
        quickMessageForm.style.display = 'none';
        conversationList.style.display = 'block';
        newMessageBtn.style.display = 'flex';
        sendQuickMessageBtn.style.display = 'none';
        document.querySelector('.message-popup-footer .btn-outline').style.display = 'none';
    }

    function showNewMessageForm() {
        conversationList.style.display = 'none';
        messagePreviewContainer.style.display = 'none';
        quickMessageForm.style.display = 'flex';
        newMessageBtn.style.display = 'none';
        sendQuickMessageBtn.style.display = 'flex';
        document.querySelector('.message-popup-footer .btn-outline').style.display = 'block';
    }

    function showMessagePreview(conversation) {
        conversationList.style.display = 'none';
        quickMessageForm.style.display = 'none';
        messagePreviewContainer.style.display = 'block';
        newMessageBtn.style.display = 'flex';
        sendQuickMessageBtn.style.display = 'none';
        document.querySelector('.message-popup-footer .btn-outline').style.display = 'block';
        currentActiveConversation = conversation;
        document.getElementById('activeUserAvatar').textContent = conversation.name.charAt(0).toUpperCase();
        document.getElementById('activeUserName').textContent = conversation.name;
        loadConversationMessages(conversation.id, conversation.name);
        startMessageChecking(conversation.id);
        startOnlineStatusChecking(conversation.id);
    }

    function loadConversations() {
        fetch('dashboard.php?action=get_conversations').then(response => response.json()).then(conversations => {
            conversationList.innerHTML = '';
            if (conversations.length === 0) {
                conversationList.innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--gray);"><i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i><p>No conversations yet</p></div>`;
            } else {
                conversations.forEach(conv => {
                    const otherUser = conv.user1_id === '<?php echo $_SESSION['user_id']; ?>' ? { id: conv.user2_id, name: conv.user2_first_name + ' ' + conv.user2_last_name, username: conv.user2_username } : { id: conv.user1_id, name: conv.user1_first_name + ' ' + conv.user1_last_name, username: conv.user1_username };
                    fetch(`dashboard.php?action=get_online_status&friend_id=${otherUser.id}`).then(response => response.json()).then(data => {
                        const status = data.status;
                        const conversationItem = document.createElement('div');
                        conversationItem.className = 'conversation-item';
                        conversationItem.innerHTML = `<div class="conversation-avatar">${otherUser.name.charAt(0).toUpperCase()}<div class="online-status ${status}"></div></div><div class="conversation-info"><div class="conversation-name">${otherUser.name}</div><div class="conversation-preview">${conv.last_message || 'No messages yet'}</div></div><div class="conversation-meta"><div class="conversation-time">${formatTime(conv.last_message_time)}</div>${conv.unread_count > 0 ? `<div class="conversation-unread">${conv.unread_count}</div>` : ''}</div>`;
                        conversationItem.addEventListener('click', () => {
                            markConversationAsRead(otherUser.id);
                            const unreadBadge = conversationItem.querySelector('.conversation-unread');
                            if (unreadBadge) unreadBadge.remove();
                            showMessagePreview({ id: otherUser.id, name: otherUser.name, username: otherUser.username });
                        });
                        conversationList.appendChild(conversationItem);
                    }).catch(error => console.error('Error loading online status:', error));
                });
            }
        }).catch(error => {
            console.error('Error loading conversations:', error);
            conversationList.innerHTML = `<div style="padding: 1rem; text-align: center; color: var(--error);">Error loading conversations</div>`;
        });
    }

    function loadConversationMessages(friendId, friendName) {
        const messagesContainer = document.getElementById('activeConversationMessages');
        fetch(`dashboard.php?action=get_friend_chat&friend_id=${friendId}`).then(response => response.json()).then(messages => {
            messagesContainer.innerHTML = '';
            if (messages.length === 0) {
                messagesContainer.innerHTML = `<div style="text-align: center; color: var(--gray); padding: 2rem;"><i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.5;"></i><p>No messages yet. Start the conversation!</p></div>`;
            } else {
                messages.forEach(msg => {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message-bubble ${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? 'sent' : 'received'}`;
                    const now = new Date();
                    const messageTime = new Date(msg.created_at);
                    const timeDiff = now - messageTime;
                    let timeText;
                    if (timeDiff < 60000) timeText = 'Just now';
                    else if (timeDiff < 3600000) timeText = `${Math.floor(timeDiff / 60000)}m ago`;
                    else if (timeDiff < 86400000) timeText = `${Math.floor(timeDiff / 3600000)}h ago`;
                    else timeText = messageTime.toLocaleDateString();
                    const readStatus = msg.is_read ? '<i class="fas fa-check-double read-status"></i>' : '<ion-icon name="checkmark-outline"></ion-icon>';
                    messageDiv.innerHTML = `<div class="message-content">${msg.message}</div><div class="message-meta"><span>${timeText}</span><div class="message-status">${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? readStatus : ''}</div></div>`;
                    messagesContainer.appendChild(messageDiv);
                });
            }
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }).catch(error => {
            console.error('Error loading conversation messages:', error);
            messagesContainer.innerHTML = `<div style="text-align: center; color: var(--error); padding: 1rem;">Error loading messages</div>`;
        });
    }

    function startMessageChecking(friendId) {
        stopMessageChecking();
        messageCheckInterval = setInterval(() => {
            const lastMessage = document.querySelector('#activeConversationMessages .message-bubble:last-child');
            const lastMessageId = lastMessage ? lastMessage.dataset.messageId || 0 : 0;
            fetch(`dashboard.php?action=check_new_messages&friend_id=${friendId}&last_message_id=${lastMessageId}`).then(response => response.json()).then(newMessages => {
                if (newMessages.length > 0) {
                    const messagesContainer = document.getElementById('activeConversationMessages');
                    newMessages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message-bubble ${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? 'sent' : 'received'}`;
                        messageDiv.dataset.messageId = msg.id;
                        const now = new Date();
                        const messageTime = new Date(msg.created_at);
                        const timeText = formatTime(messageTime);
                        const readStatus = msg.is_read ? '<i class="fas fa-check-double read-status"></i>' : '<ion-icon name="checkmark-outline"></ion-icon>';
                        messageDiv.innerHTML = `<div class="message-content">${msg.message}</div><div class="message-meta"><span>${timeText}</span><div class="message-status">${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? readStatus : ''}</div></div>`;
                        messagesContainer.appendChild(messageDiv);
                    });
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    updateUnreadCount();
                }
            }).catch(error => console.error('Error checking new messages:', error));
        }, 3000);
    }

    function startOnlineStatusChecking(friendId) {
        stopOnlineStatusChecking();
        onlineStatusInterval = setInterval(() => {
            fetch(`dashboard.php?action=get_online_status&friend_id=${friendId}`).then(response => response.json()).then(data => {
                const status = data.status;
                const statusElement = document.getElementById('activeUserStatus');
                const statusTextElement = document.getElementById('activeUserStatusText');
                if (statusElement) statusElement.className = `online-status ${status}`;
                if (statusTextElement) statusTextElement.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            }).catch(error => console.error('Error checking online status:', error));
        }, 5000);
    }

    function stopMessageChecking() { if (messageCheckInterval) { clearInterval(messageCheckInterval); messageCheckInterval = null; } }
    function stopOnlineStatusChecking() { if (onlineStatusInterval) { clearInterval(onlineStatusInterval); onlineStatusInterval = null; } }

    function markConversationAsRead(friendId) {
        fetch(`dashboard.php?action=mark_as_read&friend_id=${friendId}`).then(response => response.json()).then(data => { if (data.success) updateUnreadCount(); }).catch(error => console.error('Error marking conversation as read:', error));
    }

    function markAllMessagesAsRead() {
        fetch('dashboard.php?action=read_all_messages').then(response => response.json()).then(data => {
            if (data.success) {
                document.querySelectorAll('.conversation-unread').forEach(badge => badge.remove());
                updateUnreadCount();
                const successAlert = document.createElement('div');
                successAlert.className = 'alert alert-success';
                successAlert.innerHTML = `<i class="fas fa-check-circle"></i> All messages marked as read!`;
                document.querySelector('.dashboard').insertBefore(successAlert, document.querySelector('.dashboard').firstChild);
                setTimeout(() => successAlert.remove(), 3000);
            }
        }).catch(error => console.error('Error marking all messages as read:', error));
    }

    function updateUnreadCount() {
        const messageBadge = document.getElementById('headerMessageCount');
        const sidebarBadge = document.getElementById('sidebarMessageCount');
        if (messageBadge) {
            const current = parseInt(messageBadge.textContent);
            if (current > 0) messageBadge.textContent = current - 1;
        }
        if (sidebarBadge) {
            const current = parseInt(sidebarBadge.textContent);
            if (current > 0) sidebarBadge.textContent = current - 1;
        }
    }

    markAsReadBtn.addEventListener('click', () => {
        const currentFriendId = document.getElementById('currentFriendId').value;
        if (currentFriendId) {
            markConversationAsRead(currentFriendId);
            document.querySelectorAll('#chatMessages .message.sent .message-status i.fa-check').forEach(icon => icon.className = 'fas fa-check-double read-status');
        }
    });

    function openFriendChat(friendId, friendName) {
        currentFriendId.value = friendId;
        currentReceiver.value = friendId;
        document.getElementById('chatUserName').textContent = friendName;
        document.getElementById('chatUserAvatar').textContent = friendName.charAt(0).toUpperCase();
        messageInput.disabled = false;
        sendBtn.disabled = false;
        chatWidget.classList.add('active');
        messagePopup.classList.remove('active');
        chatMessages.innerHTML = `<div class="message received"><div class="message-content">Starting conversation with ${friendName}</div><div class="message-meta"><span>System</span><span>Just now</span></div></div>`;
        loadFriendChat(friendId, friendName);
        startChatRealTimeUpdates(friendId, friendName);
    }

    function loadFriendChat(friendId, friendName) {
        fetch('dashboard.php?action=get_friend_chat&friend_id=' + friendId).then(response => response.json()).then(messages => {
            chatMessages.innerHTML = '';
            if (messages.length === 0) {
                chatMessages.innerHTML = `<div class="message received"><div class="message-content">No messages yet. Start the conversation!</div><div class="message-meta"><span>System</span><span>Just now</span></div></div>`;
            } else {
                messages.forEach(msg => {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message ${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? 'sent' : 'received'}`;
                    messageDiv.dataset.messageId = msg.id;
                    const now = new Date();
                    const messageTime = new Date(msg.created_at);
                    const timeDiff = now - messageTime;
                    let timeText;
                    if (timeDiff < 60000) timeText = 'Just now';
                    else if (timeDiff < 3600000) timeText = `${Math.floor(timeDiff / 60000)}m ago`;
                    else if (timeDiff < 86400000) timeText = `${Math.floor(timeDiff / 3600000)}h ago`;
                    else timeText = messageTime.toLocaleDateString();
                    messageDiv.innerHTML = `<div class="message-content">${msg.message}</div><div class="message-meta"><span>${timeText}</span><button class="reply-btn" onclick="setReply(${msg.id}, '${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? 'You' : friendName}')"><i class="fas fa-reply"></i> Reply</button></div>`;
                    chatMessages.appendChild(messageDiv);
                });
            }
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }).catch(error => {
            console.error('Error loading chat:', error);
            chatMessages.innerHTML += `<div class="message received"><div class="message-content">Error loading chat messages</div><div class="message-meta"><span>System</span><span>Just now</span></div></div>`;
        });
    }

    function setReply(messageId, userName) {
        currentReplyMessage = messageId;
        parentMessageId.value = messageId;
        replyToUser.textContent = userName;
        replyIndicator.style.display = 'flex';
        messageInput.focus();
    }

    cancelReply.addEventListener('click', () => {
        currentReplyMessage = null;
        parentMessageId.value = '';
        replyIndicator.style.display = 'none';
    });

    chatForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const message = messageInput.value.trim();
        const friendId = currentFriendId.value;
        if (!message || !friendId) return;
        const formData = new FormData();
        formData.append('friend_id', friendId);
        formData.append('message', message);
        formData.append('send_friend_message', 'true');
        fetch('dashboard.php', { method: 'POST', body: formData }).then(response => response.text()).then(() => {
            const friendName = document.getElementById('chatUserName').textContent;
            loadFriendChat(friendId, friendName);
            messageInput.value = '';
            cancelReply.click();
        }).catch(error => console.error('Error sending message:', error));
    });

    messageInput.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'; });

    function startChatRealTimeUpdates(friendId, friendName) {
        const chatMessageCheck = setInterval(() => {
            const lastMessage = document.querySelector('#chatMessages .message:last-child');
            const lastMessageId = lastMessage ? lastMessage.dataset.messageId || 0 : 0;
            fetch(`dashboard.php?action=check_new_messages&friend_id=${friendId}&last_message_id=${lastMessageId}`).then(response => response.json()).then(newMessages => {
                if (newMessages.length > 0) {
                    newMessages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message ${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? 'sent' : 'received'}`;
                        messageDiv.dataset.messageId = msg.id;
                        const now = new Date();
                        const messageTime = new Date(msg.created_at);
                        const timeDiff = now - messageTime;
                        let timeText;
                        if (timeDiff < 60000) timeText = 'Just now';
                        else if (timeDiff < 3600000) timeText = `${Math.floor(timeDiff / 60000)}m ago`;
                        else if (timeDiff < 86400000) timeText = `${Math.floor(timeDiff / 3600000)}h ago`;
                        else timeText = messageTime.toLocaleDateString();
                        const readStatus = msg.is_read ? '<i class="fas fa-check-double read-status"></i>' : '<ion-icon name="checkmark-outline"></ion-icon>';
                        messageDiv.innerHTML = `<div class="message-content">${msg.message}</div><div class="message-meta"><span>${timeText}</span><div class="message-status">${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? readStatus : ''}</div></div>`;
                        chatMessages.appendChild(messageDiv);
                    });
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                    if (newMessages.some(msg => msg.sender_id !== '<?php echo $_SESSION['user_id']; ?>')) realtimeDot.style.display = 'block';
                }
            }).catch(error => console.error('Error checking new chat messages:', error));
        }, 2000);
        const chatOnlineCheck = setInterval(() => {
            fetch(`dashboard.php?action=get_online_status&friend_id=${friendId}`).then(response => response.json()).then(data => {
                const status = data.status;
                const statusDot = document.getElementById('chatUserStatusDot');
                const statusText = document.getElementById('chatUserStatus');
                if (statusDot) statusDot.className = `online-status ${status}`;
                if (statusText) statusText.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            }).catch(error => console.error('Error checking chat online status:', error));
        }, 3000);
        window.chatIntervals = window.chatIntervals || [];
        window.chatIntervals.push(chatMessageCheck, chatOnlineCheck);
    }

    chatClose.addEventListener('click', () => {
        if (window.chatIntervals) { window.chatIntervals.forEach(interval => clearInterval(interval)); window.chatIntervals = []; }
        chatWidget.classList.remove('active');
    });

    function formatTime(timestamp) {
        if (!timestamp) return '';
        const now = new Date();
        const messageTime = new Date(timestamp);
        const timeDiff = now - messageTime;
        if (timeDiff < 60000) return 'Just now';
        else if (timeDiff < 3600000) return `${Math.floor(timeDiff / 60000)}m ago`;
        else if (timeDiff < 86400000) return `${Math.floor(timeDiff / 3600000)}h ago`;
        else return messageTime.toLocaleDateString();
    }

    quickMessageForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const receiverId = recipientSelect.value;
        const message = messagePopupTextarea.value.trim();
        if (!receiverId || !message) { alert('Please select a recipient and enter a message'); return; }
        const formData = new FormData(quickMessageForm);
        fetch('dashboard.php', { method: 'POST', body: formData }).then(response => response.text()).then(() => {
            const successAlert = document.createElement('div');
            successAlert.className = 'alert alert-success';
            successAlert.innerHTML = `<i class="fas fa-check-circle"></i> Message sent successfully!`;
            document.querySelector('.dashboard').insertBefore(successAlert, document.querySelector('.dashboard').firstChild);
            messagePopup.classList.remove('active');
            quickMessageForm.reset();
            showConversationList();
            setTimeout(() => successAlert.remove(), 3000);
            loadConversations();
        }).catch(error => { console.error('Error sending message:', error); alert('Error sending message. Please try again.'); });
    });

    messagePopupTextarea.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'; });

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = this.value.trim();
        if (searchTerm.length < 2) { searchResults.style.display = 'none'; return; }
        searchTimeout = setTimeout(() => {
            fetch('dashboard.php?action=search_repos&search=' + encodeURIComponent(searchTerm)).then(response => response.json()).then(results => {
                searchResults.innerHTML = '';
                if (results.length === 0) {
                    searchResults.innerHTML = '<div class="search-result-item">No repositories found</div>';
                } else {
                    results.forEach(repo => {
                        const resultItem = document.createElement('div');
                        resultItem.className = 'search-result-item';
                        resultItem.innerHTML = `<div style="font-weight: 600;">${repo.repo_id}</div><div style="font-size: 0.8rem; color: var(--gray);">${repo.table_name.split('_')[0]}</div>`;
                        resultItem.addEventListener('click', () => window.location.href = 'repo_view.php?repo=' + repo.repo_id);
                        searchResults.appendChild(resultItem);
                    });
                }
                searchResults.style.display = 'block';
            }).catch(error => {
                console.error('Search error:', error);
                searchResults.innerHTML = '<div class="search-result-item">Search error</div>';
                searchResults.style.display = 'block';
            });
        }, 300);
    });

    document.addEventListener('click', (e) => { if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) searchResults.style.display = 'none'; });

    document.addEventListener('DOMContentLoaded', () => {
        const cards = document.querySelectorAll('.stat-card');
        cards.forEach((card, index) => card.style.animationDelay = `${index * 0.1}s`);
    });

    setInterval(() => fetch('dashboard.php').catch(err => console.log('Session refresh failed')), 30 * 60 * 1000);
    setInterval(() => {
        const messageBadge = document.querySelector('.message-badge');
        const notificationBadge = document.querySelector('.notification-badge');
        const friendBadge = document.querySelector('.friend-badge');
        if (messageBadge && Math.random() > 0.7) {
            const current = parseInt(messageBadge.textContent);
            messageBadge.textContent = current + 1;
        }
        if (notificationBadge && Math.random() > 0.8) {
            const current = parseInt(notificationBadge.textContent);
            notificationBadge.textContent = current + 1;
        }
    }, 30000);
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
// Real-time friend status updates
function startFriendStatusUpdates() {
    setInterval(() => {
        document.querySelectorAll('.friend-card').forEach(card => {
            const friendId = card.getAttribute('data-friend-id');
            if (friendId) {
                fetch(`dashboard.php?action=get_online_status&friend_id=${friendId}`)
                    .then(response => response.json())
                    .then(data => {
                        const statusBadge = card.querySelector('.friend-badge');
                        if (statusBadge && statusBadge.className !== `friend-badge ${data.status}`) {
                            statusBadge.className = `friend-badge ${data.status}`;
                            statusBadge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                            
                            // Add visual effect for status change
                            statusBadge.style.animation = 'pulse 0.5s ease-in-out';
                            setTimeout(() => statusBadge.style.animation = '', 500);
                        }
                    })
                    .catch(error => console.error('Status update error:', error));
            }
        });
    }, 10000); // Update every 10 seconds
}

// Real-time notification updates
function startNotificationUpdates() {
    setInterval(() => {
        fetch('dashboard.php?action=check_new_notifications')
            .then(response => response.json())
            .then(data => {
                if (data.count > 0) {
                    updateNotificationBadges(data.count);
                    showNewNotifications(data.notifications);
                }
            })
            .catch(error => console.error('Notification update error:', error));
    }, 15000); // Check every 15 seconds
}

function updateNotificationBadges(count) {
    const badges = document.querySelectorAll('.notification-badge');
    badges.forEach(badge => {
        const currentCount = parseInt(badge.textContent) || 0;
        if (count !== currentCount) {
            badge.textContent = count;
            badge.style.animation = 'pulse 0.5s ease-in-out';
            setTimeout(() => badge.style.animation = '', 500);
        }
    });
}

function showNewNotifications(notifications) {
    notifications.forEach(notification => {
        // Show desktop notification if permitted
        if ("Notification" in window && Notification.permission === "granted") {
            new Notification("CodeSphere", {
                body: notification.message,
                icon: "/favicon.ico"
            });
        }
        
        // Add to notifications list if modal is open
        if (document.getElementById('notificationModal').classList.contains('active')) {
            addNotificationToModal(notification);
        }
    });
}

// Real-time message updates for chat widgets
function startMessageUpdates() {
    setInterval(() => {
        const activeChat = document.getElementById('currentFriendId')?.value;
        if (activeChat) {
            checkNewMessages(activeChat);
        }
        
        // Update conversation list
        updateConversationList();
    }, 5000); // Check every 5 seconds
}

function checkNewMessages(friendId) {
    const lastMessage = document.querySelector('#chatMessages .message:last-child');
    const lastMessageId = lastMessage ? lastMessage.getAttribute('data-message-id') : 0;
    
    fetch(`dashboard.php?action=check_new_messages&friend_id=${friendId}&last_message_id=${lastMessageId}`)
        .then(response => response.json())
        .then(messages => {
            if (messages.length > 0) {
                messages.forEach(message => addMessageToChat(message));
                updateUnreadCounts();
            }
        })
        .catch(error => console.error('Message update error:', error));
}

function updateConversationList() {
    if (document.getElementById('messagePopup').classList.contains('active')) {
        loadConversations(); // This function already exists in your code
    }
}

// Real-time repository activity
function startRepositoryUpdates() {
    setInterval(() => {
        fetch('dashboard.php?action=check_repo_updates')
            .then(response => response.json())
            .then(updates => {
                updates.forEach(update => {
                    updateRepositoryCard(update);
                    addActivityToFeed(update);
                });
            })
            .catch(error => console.error('Repo update error:', error));
    }, 30000); // Check every 30 seconds
}

function updateRepositoryCard(update) {
    const repoCard = document.querySelector(`[data-repo-id="${update.repo_id}"]`);
    if (repoCard) {
        const stats = repoCard.querySelector('.repo-stats');
        if (stats) {
            stats.innerHTML = `<span class="repo-stat"><i class="fas fa-code-commit"></i>${update.commit_count} commits</span>
                              <span class="repo-stat"><i class="fas fa-user"></i>${update.collaborators} collaborators</span>`;
        }
    }
}

// Start when page loads
document.addEventListener('DOMContentLoaded', startFriendStatusUpdates);

// Initialize all real-time features
function initializeRealTimeFeatures() {
    startFriendStatusUpdates();
    startNotificationUpdates();
    startMessageUpdates();
    startRepositoryUpdates();
    
    // Request notification permission
    if ("Notification" in window && Notification.permission === "default") {
        Notification.requestPermission();
    }
}

// Start when page loads
document.addEventListener('DOMContentLoaded', initializeRealTimeFeatures);

// Also add data attributes to your HTML elements
// For friend cards: data-friend-id="<?php echo $friend['userId']; ?>"
// For repo cards: data-repo-id="<?php echo $repo['repo_id']; ?>"

// Update your logout link to use this modal
// Change the logout link in sidebar to:
// <a href="#" class="nav-item" onclick="showLogoutModal()"><i class="fas fa-sign-out-alt nav-icon"></i><span class="nav-text">Logout</span></a>
</script>

</body>
</html>