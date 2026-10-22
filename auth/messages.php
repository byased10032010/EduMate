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
    $_SESSION['theme'] = $_COOKIE['user_theme'] ?? 'light';
    setcookie('user_theme', $_SESSION['theme'], time() + (30 * 24 * 60 * 60), "/");
}

// Initialize connections
$conn = getDBConnection(DB_NAME_CODESPHERE);
$messagesConn = getDBConnection(DB_NAME_MESSAGES);
$friendsConn = getDBConnection(DB_NAME_USER_FRIENDS);

// Get user data
$stmt = $conn->prepare("SELECT username, email, first_name, last_name FROM users WHERE userId = ?");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get unread message count
$unreadStmt = $messagesConn->prepare("SELECT COUNT(*) as unread_count FROM messages WHERE receiver_id = ? AND is_read = FALSE");
$unreadStmt->bind_param("s", $_SESSION['user_id']);
$unreadStmt->execute();
$unreadResult = $unreadStmt->get_result()->fetch_assoc();
$unreadMessagesCount = $unreadResult['unread_count'] ?? 0;

// Get conversations
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
");
$convStmt->bind_param("sss", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$convStmt->execute();
$conversations = $convStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get friends for new message dropdown
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

// Helper functions
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

// Get friend unread counts and online status
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

// Update current user's online status
updateUserOnlineStatus($_SESSION['user_id'], 'online');

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_conversation_messages':
            $conversationId = $_GET['conversation_id'] ?? '';
            if (!empty($conversationId)) {
                $messages = getConversationMessages($conversationId, $_SESSION['user_id']);
                markConversationAsRead($conversationId, $_SESSION['user_id']);
                echo json_encode($messages);
            } else {
                echo json_encode([]);
            }
            exit();
            
        case 'get_friend_chat':
            $friendId = $_GET['friend_id'] ?? '';
            if (!empty($friendId)) {
                $messages = getFriendChatMessages($_SESSION['user_id'], $friendId, 50);
                markMessagesAsRead($_SESSION['user_id'], $friendId);
                echo json_encode($messages);
            } else {
                echo json_encode([]);
            }
            exit();
            
        case 'send_message':
            $receiverId = $_POST['receiver_id'] ?? '';
            $message = trim($_POST['message'] ?? '');
            $conversationId = $_POST['conversation_id'] ?? '';
            
            if (!empty($message) && (!empty($receiverId) || !empty($conversationId))) {
                $result = sendMessage($_SESSION['user_id'], $receiverId, $message, $conversationId);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            }
            exit();
            
        case 'mark_as_read':
            $conversationId = $_GET['conversation_id'] ?? '';
            $friendId = $_GET['friend_id'] ?? '';
            
            if (!empty($conversationId)) {
                markConversationAsRead($conversationId, $_SESSION['user_id']);
                echo json_encode(['success' => true]);
            } elseif (!empty($friendId)) {
                markMessagesAsRead($_SESSION['user_id'], $friendId);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false]);
            }
            exit();
            
        case 'search_messages':
            $searchTerm = $_GET['search'] ?? '';
            if (!empty($searchTerm)) {
                $results = searchMessages($_SESSION['user_id'], $searchTerm);
                echo json_encode($results);
            } else {
                echo json_encode([]);
            }
            exit();
            
        case 'delete_conversation':
            $conversationId = $_POST['conversation_id'] ?? '';
            if (!empty($conversationId)) {
                $result = deleteConversation($conversationId, $_SESSION['user_id']);
                echo json_encode($result);
            } else {
                echo json_encode(['success' => false, 'error' => 'No conversation specified']);
            }
            exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message'])) {
        $receiverId = $_POST['receiver_id'] ?? '';
        $message = trim($_POST['message'] ?? '');
        $conversationId = $_POST['conversation_id'] ?? '';
        
        if (!empty($message) && (!empty($receiverId) || !empty($conversationId))) {
            $result = sendMessage($_SESSION['user_id'], $receiverId, $message, $conversationId);
            if ($result['success']) {
                $messageSuccess = "Message sent successfully!";
            } else {
                $messageError = $result['error'] ?? "Failed to send message.";
            }
        } else {
            $messageError = "Please enter a message and select a recipient.";
        }
    }
    
    if (isset($_POST['start_conversation'])) {
        $receiverId = $_POST['receiver_id'] ?? '';
        $message = trim($_POST['initial_message'] ?? '');
        
        if (!empty($receiverId) && !empty($message)) {
            $result = sendMessage($_SESSION['user_id'], $receiverId, $message);
            if ($result['success']) {
                $messageSuccess = "Conversation started!";
                header('Location: messages.php?conversation=' . $result['conversation_id']);
                exit();
            } else {
                $messageError = $result['error'] ?? "Failed to start conversation.";
            }
        } else {
            $messageError = "Please select a friend and enter a message.";
        }
    }
}

// Additional Helper functions
function getConversationMessages($conversationId, $userId) {
    $conn = getDBConnection(DB_NAME_MESSAGES);
    $stmt = $conn->prepare("
        SELECT m.*, u.first_name, u.last_name, u.username 
        FROM messages m 
        JOIN codesphere.users u ON m.sender_id = u.userId 
        WHERE m.conversation_id = ? 
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("s", $conversationId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    return $messages;
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

function sendMessage($senderId, $receiverId, $message, $conversationId = null) {
    $conn = getDBConnection(DB_NAME_MESSAGES);
    
    // Generate conversation ID if not provided
    if (empty($conversationId)) {
        $conversationId = generateConversationId($senderId, $receiverId);
        
        // Check if conversation already exists
        $checkStmt = $conn->prepare("SELECT conversation_id FROM conversations WHERE conversation_id = ?");
        $checkStmt->bind_param("s", $conversationId);
        $checkStmt->execute();
        $existingConv = $checkStmt->get_result()->fetch_assoc();
        
        if (!$existingConv) {
            $createStmt = $conn->prepare("INSERT INTO conversations (conversation_id, user1_id, user2_id) VALUES (?, ?, ?)");
            $createStmt->bind_param("sss", $conversationId, $senderId, $receiverId);
            if (!$createStmt->execute()) {
                $conn->close();
                return ['success' => false, 'error' => 'Failed to create conversation'];
            }
        }
    }
    
    // Insert message
    $insertStmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
    $insertStmt->bind_param("ssss", $conversationId, $senderId, $receiverId, $message);
    
    if ($insertStmt->execute()) {
        // Also add to friend chat table for real-time features
        $chatConn = getDBConnection(DB_NAME_FRIEND_CHATS);
        $tableName = generateChatTableName($senderId, $receiverId);
        $tableCheck = $chatConn->query("SHOW TABLES LIKE '$tableName'");
        
        if ($tableCheck->num_rows === 0) {
            createFriendChatTable($senderId, $receiverId);
        }
        
        $chatStmt = $chatConn->prepare("INSERT INTO `$tableName` (sender_id, message, message_type) VALUES (?, ?, 'text')");
        $chatStmt->bind_param("ss", $senderId, $message);
        $chatStmt->execute();
        $chatConn->close();
        
        $conn->close();
        return ['success' => true, 'conversation_id' => $conversationId];
    } else {
        $conn->close();
        return ['success' => false, 'error' => 'Failed to send message'];
    }
}

function markConversationAsRead($conversationId, $userId) {
    $conn = getDBConnection(DB_NAME_MESSAGES);
    $stmt = $conn->prepare("UPDATE messages SET is_read = TRUE WHERE conversation_id = ? AND receiver_id = ? AND is_read = FALSE");
    $stmt->bind_param("ss", $conversationId, $userId);
    $stmt->execute();
    $conn->close();
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

function searchMessages($userId, $searchTerm) {
    $conn = getDBConnection(DB_NAME_MESSAGES);
    $stmt = $conn->prepare("
        SELECT m.*, c.conversation_id, 
               u1.first_name as sender_first_name, u1.last_name as sender_last_name,
               u2.first_name as receiver_first_name, u2.last_name as receiver_last_name
        FROM messages m
        JOIN conversations c ON m.conversation_id = c.conversation_id
        JOIN codesphere.users u1 ON m.sender_id = u1.userId
        JOIN codesphere.users u2 ON m.receiver_id = u2.userId
        WHERE (c.user1_id = ? OR c.user2_id = ?) AND m.message LIKE ?
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $searchTermLike = "%$searchTerm%";
    $stmt->bind_param("sss", $userId, $userId, $searchTermLike);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    return $results;
}

function deleteConversation($conversationId, $userId) {
    $conn = getDBConnection(DB_NAME_MESSAGES);
    
    // Verify user is part of the conversation
    $checkStmt = $conn->prepare("SELECT * FROM conversations WHERE conversation_id = ? AND (user1_id = ? OR user2_id = ?)");
    $checkStmt->bind_param("sss", $conversationId, $userId, $userId);
    $checkStmt->execute();
    $conversation = $checkStmt->get_result()->fetch_assoc();
    
    if (!$conversation) {
        $conn->close();
        return ['success' => false, 'error' => 'Conversation not found or access denied'];
    }
    
    // Delete messages
    $deleteMsgStmt = $conn->prepare("DELETE FROM messages WHERE conversation_id = ?");
    $deleteMsgStmt->bind_param("s", $conversationId);
    $deleteMsgStmt->execute();
    
    // Delete conversation
    $deleteConvStmt = $conn->prepare("DELETE FROM conversations WHERE conversation_id = ?");
    $deleteConvStmt->bind_param("s", $conversationId);
    
    if ($deleteConvStmt->execute()) {
        $conn->close();
        return ['success' => true];
    } else {
        $conn->close();
        return ['success' => false, 'error' => 'Failed to delete conversation'];
    }
}

function generateConversationId($user1, $user2) {
    $users = [$user1, $user2];
    sort($users);
    return 'conv_' . md5(implode('_', $users));
}

function generateChatTableName($user1, $user2) {
    $users = [$user1, $user2];
    sort($users);
    return 'chat_' . md5(implode('_', $users));
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

// Don't close connections here - let them be closed naturally or in functions
// $conn->close();
// $messagesConn->close();
// $friendsConn->close();

// Check if we have an active conversation for the display
$activeConversation = null;
$otherUser = null;
if (isset($_GET['conversation']) && !empty($_GET['conversation'])) {
    $conversationId = $_GET['conversation'];
    $convStmt = $messagesConn->prepare("
        SELECT c.*, 
               u1.first_name as user1_first_name, u1.last_name as user1_last_name, u1.username as user1_username,
               u2.first_name as user2_first_name, u2.last_name as user2_last_name, u2.username as user2_username
        FROM conversations c
        LEFT JOIN codesphere.users u1 ON c.user1_id = u1.userId
        LEFT JOIN codesphere.users u2 ON c.user2_id = u2.userId
        WHERE c.conversation_id = ? AND (c.user1_id = ? OR c.user2_id = ?)
    ");
    $convStmt->bind_param("sss", $conversationId, $_SESSION['user_id'], $_SESSION['user_id']);
    $convStmt->execute();
    $activeConversation = $convStmt->get_result()->fetch_assoc();
    
    if ($activeConversation) {
        $otherUser = $activeConversation['user1_id'] === $_SESSION['user_id'] ? 
            ['id' => $activeConversation['user2_id'], 'name' => $activeConversation['user2_first_name'] . ' ' . $activeConversation['user2_last_name'], 'username' => $activeConversation['user2_username']] : 
            ['id' => $activeConversation['user1_id'], 'name' => $activeConversation['user1_first_name'] . ' ' . $activeConversation['user1_last_name'], 'username' => $activeConversation['user1_username']];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* All the CSS styles from the previous version remain the same */
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
        
        .messages-container { flex: 1; padding: 2rem; display: flex; gap: 2rem; }
        .conversations-sidebar { width: 350px; background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-lg); backdrop-filter: blur(10px); display: flex; flex-direction: column; }
        .conversations-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--gradient-light); }
        .conversations-header h2 { font-size: 1.5rem; color: var(--dark); font-weight: 700; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .new-conversation-btn { background: var(--gradient); color: white; border: none; padding: 0.5rem 1rem; border-radius: var(--radius); cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 600; }
        .new-conversation-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .conversations-list { flex: 1; overflow-y: auto; }
        .conversation-item { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 0.75rem; position: relative; }
        .conversation-item:hover { background: var(--sidebar-hover); }
        .conversation-item.active { background: var(--gradient-light); border-left: 3px solid var(--primary); }
        .conversation-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--gradient); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1rem; flex-shrink: 0; position: relative; }
        .online-status { position: absolute; bottom: 0; right: 0; width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--card-bg); }
        .online-status.online { background: var(--success); }
        .online-status.offline { background: var(--gray); }
        .online-status.idle { background: var(--warning); }
        .conversation-info { flex: 1; min-width: 0; }
        .conversation-name { font-weight: 600; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conversation-preview { font-size: 0.8rem; color: var(--gray); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .conversation-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 0.25rem; flex-shrink: 0; }
        .conversation-time { font-size: 0.7rem; color: var(--gray); }
        .conversation-unread { background: var(--primary); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center; font-weight: 600; }
        
        .chat-area { flex: 1; background: var(--card-bg); border-radius: var(--radius-xl); border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-lg); backdrop-filter: blur(10px); display: flex; flex-direction: column; }
        .chat-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--gradient-light); }
        .chat-user { display: flex; align-items: center; gap: 0.75rem; }
        .chat-user-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--gradient); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1rem; position: relative; }
        .chat-user-info h3 { font-weight: 600; margin-bottom: 0.25rem; }
        .chat-user-info p { font-size: 0.8rem; color: var(--gray); }
        .chat-actions { display: flex; gap: 0.5rem; }
        .chat-action-btn { background: none; border: none; color: var(--dark); cursor: pointer; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); }
        .chat-action-btn:hover { background: var(--sidebar-hover); transform: scale(1.1); }
        
        .chat-messages { flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; scrollbar-width: thin; scrollbar-color: var(--primary) transparent; }
        .chat-messages::-webkit-scrollbar { width: 6px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 3px; }
        .message { max-width: 70%; padding: 0.75rem 1rem; border-radius: var(--radius-lg); position: relative; animation: messageSlide 0.3s ease-out; }
        .message.sent { align-self: flex-end; background: var(--gradient); color: white; border-bottom-right-radius: 4px; }
        .message.received { align-self: flex-start; background: var(--sidebar-hover); color: var(--dark); border-bottom-left-radius: 4px; }
        .message-content { margin-bottom: 0.25rem; }
        .message-meta { font-size: 0.7rem; opacity: 0.7; display: flex; justify-content: space-between; align-items: center; }
        .message-status { display: flex; align-items: center; gap: 0.25rem; }
        .read-status { color: var(--success); }
        
        .chat-input { padding: 1.5rem; border-top: 1px solid var(--border); background: var(--card-bg); }
        .chat-form { display: flex; gap: 0.75rem; }
        .message-input { flex: 1; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; resize: none; font-family: 'Inter', sans-serif; transition: var(--transition); }
        .message-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .send-btn { padding: 0.75rem 1.5rem; background: var(--gradient); color: white; border: none; border-radius: var(--radius); cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 0.5rem; }
        .send-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        
        .empty-state { text-align: center; padding: 3rem 2rem; color: var(--gray); }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; opacity: 0.5; animation: bounce 2s infinite; }
        .empty-state h3 { font-size: 1.3rem; margin-bottom: 0.5rem; color: var(--dark); }
        
        .alert { padding: 1rem 1.5rem; border-radius: var(--radius); margin-bottom: 1rem; animation: slideInUp 0.5s ease-out; }
        .alert-success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #10b981; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #ef4444; }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); display: flex; align-items: center; justify-content: center; z-index: 2000; opacity: 0; visibility: hidden; transition: var(--transition); backdrop-filter: blur(4px); }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .new-conversation-modal { background: var(--card-bg); border-radius: var(--radius-2xl); width: 90%; max-width: 500px; max-height: 80vh; overflow: hidden; box-shadow: var(--shadow-2xl); transform: translateY(20px) scale(0.95); transition: var(--transition); backdrop-filter: blur(20px); }
        .modal-overlay.active .new-conversation-modal { transform: translateY(0) scale(1); }
        .modal-header { padding: 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--gradient); color: white; }
        .modal-header h3 { font-size: 1.3rem; font-weight: 600; }
        .modal-close { background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; padding: 0.5rem; border-radius: var(--radius); transition: var(--transition); }
        .modal-close:hover { background: rgba(255, 255, 255, 0.1); transform: rotate(90deg); }
        .modal-body { padding: 1.5rem; max-height: 60vh; overflow-y: auto; }
        .friend-select { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; margin-bottom: 1rem; transition: var(--transition); }
        .friend-select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .message-textarea { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--light); color: var(--dark); font-size: 0.9rem; resize: vertical; min-height: 120px; font-family: 'Inter', sans-serif; transition: var(--transition); }
        .message-textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 1rem; }
        
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; position: relative; overflow: hidden; }
        .btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: var(--transition); }
        .btn:hover::before { left: 100%; }
        .btn-primary { background: var(--gradient); color: white; box-shadow: var(--shadow-lg); }
        .btn-primary:hover { transform: translateY(-2px) scale(1.05); box-shadow: var(--shadow-xl), var(--neon-glow); }
        .btn-secondary { background: var(--light-gray); color: var(--dark); }
        .btn-secondary:hover { background: var(--gray); color: white; transform: translateY(-2px); }
        .btn-error { background: var(--error); color: white; }
        .btn-error:hover { background: #dc2626; transform: translateY(-2px); }
        
        .floating-shapes { position: fixed; top: 0; left: 0; width: 100%; height: 100%; overflow: hidden; z-index: -1; }
        .shape { position: absolute; opacity: 0.03; border-radius: 50%; animation: float 25s infinite linear; }
        .shape-1 { width: 150px; height: 150px; background: var(--primary); top: 10%; left: 5%; animation-delay: 0s; }
        .shape-2 { width: 100px; height: 100px; background: var(--secondary); top: 70%; left: 80%; animation-delay: -8s; }
        .shape-3 { width: 200px; height: 200px; background: var(--accent); top: 40%; left: 70%; animation-delay: -16s; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideInUp { from { opacity: 0; transform: translateY(50px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% { transform: translateY(0); } 40% { transform: translateY(-10px); } 60% { transform: translateY(-5px); } }
        @keyframes float { 0%, 100% { transform: translateY(0) rotate(0deg); } 33% { transform: translateY(-20px) rotate(120deg); } 66% { transform: translateY(20px) rotate(240deg); } }
        @keyframes rotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes messageSlide { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
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
            .messages-container { flex-direction: column; padding: 1rem; gap: 1rem; }
            .conversations-sidebar { width: 100%; height: 300px; }
            .chat-area { min-height: 500px; }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="dashboard.php" class="logo">
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
                <a href="collaborations.php" class="nav-item"><i class="fas fa-users nav-icon"></i><span class="nav-text">Collaborations</span></a>
                <a href="notifications.php" class="nav-item"><i class="fas fa-bell nav-icon"></i><span class="nav-text">Notifications</span></a>
                <a href="messages.php" class="nav-item active"><i class="fas fa-envelope nav-icon"></i><span class="nav-text">Messages</span><span class="nav-badge" id="sidebarMessageCount"><?php echo $unreadMessagesCount; ?></span></a>
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
                <a href="#" class="nav-item" onclick="confirmLogout()"><i class="fas fa-sign-out-alt nav-icon"></i><span class="nav-text">Logout</span></a>
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
                        <input type="text" class="search-input" id="searchInput" placeholder="Search messages...">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                </div>
                <div class="nav-right">
                    <button class="nav-btn" title="Friends" onclick="window.location.href='friends.php'">
                        <i class="fas fa-user-friends"></i>
                    </button>
                    <button class="nav-btn" title="Notifications" onclick="window.location.href='notifications.php'">
                        <i class="fas fa-bell"></i>
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

        <div class="messages-container">
            <div class="conversations-sidebar">
                <div class="conversations-header">
                    <h2>Messages</h2>
                    <button class="new-conversation-btn" id="newConversationBtn"><ion-icon name="add-outline"></ion-icon>New</button>
                </div>
                <div class="conversations-list" id="conversationsList">
                    <?php if (count($conversations) > 0): ?>
                        <?php foreach ($conversations as $conv): ?>
                            <?php 
                                $otherUser = $conv['user1_id'] === $_SESSION['user_id'] ? 
                                    ['id' => $conv['user2_id'], 'name' => $conv['user2_first_name'] . ' ' . $conv['user2_last_name'], 'username' => $conv['user2_username']] : 
                                    ['id' => $conv['user1_id'], 'name' => $conv['user1_first_name'] . ' ' . $conv['user1_last_name'], 'username' => $conv['user1_username']];
                                $status = $friendOnlineStatus[$otherUser['id']] ?? 'offline';
                                $isActive = isset($_GET['conversation']) && $_GET['conversation'] === $conv['conversation_id'];
                            ?>
                            <div class="conversation-item <?php echo $isActive ? 'active' : ''; ?>" data-conversation-id="<?php echo $conv['conversation_id']; ?>" data-user-id="<?php echo $otherUser['id']; ?>">
                                <div class="conversation-avatar"><?php echo strtoupper(substr($otherUser['name'], 0, 1)); ?><div class="online-status <?php echo $status; ?>"></div></div>
                                <div class="conversation-info">
                                    <div class="conversation-name"><?php echo htmlspecialchars($otherUser['name']); ?></div>
                                    <div class="conversation-preview"><?php echo htmlspecialchars($conv['last_message'] ?? 'No messages yet'); ?></div>
                                </div>
                                <div class="conversation-meta">
                                    <div class="conversation-time"><?php echo $conv['last_message_time'] ? date('M j', strtotime($conv['last_message_time'])) : ''; ?></div>
                                    <?php if ($conv['unread_count'] > 0): ?><div class="conversation-unread"><?php echo $conv['unread_count']; ?></div><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <h3>No conversations yet</h3>
                            <p>Start a conversation with a friend</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-area">
                <?php if (isset($_GET['conversation']) && !empty($_GET['conversation']) && $activeConversation): ?>
                    <div class="chat-header">
                        <div class="chat-user">
                            <div class="chat-user-avatar"><?php echo strtoupper(substr($otherUser['name'], 0, 1)); ?><div class="online-status <?php echo $friendOnlineStatus[$otherUser['id']] ?? 'offline'; ?>"></div></div>
                            <div class="chat-user-info">
                                <h3><?php echo htmlspecialchars($otherUser['name']); ?></h3>
                                <p>@<?php echo htmlspecialchars($otherUser['username']); ?> • <span id="chatUserStatus"><?php echo ucfirst($friendOnlineStatus[$otherUser['id']] ?? 'offline'); ?></span></p>
                            </div>
                        </div>
                        <div class="chat-actions">
                            <button class="chat-action-btn" title="Search conversation" id="searchConversationBtn"><i class="fas fa-search"></i></button>
                            <button class="chat-action-btn" title="Delete conversation" id="deleteConversationBtn"><ion-icon name="trash-outline"></ion-icon></button>
                        </div>
                    </div>
                    <div class="chat-messages" id="chatMessages">
                        <!-- Messages will be loaded via JavaScript -->
                    </div>
                    <div class="chat-input">
                        <form class="chat-form" id="chatForm" method="POST">
                            <input type="hidden" name="conversation_id" value="<?php echo $_GET['conversation']; ?>">
                            <textarea class="message-input" id="messageInput" name="message" placeholder="Type your message..." rows="1" required></textarea>
                            <button type="submit" class="send-btn" name="send_message"><i class="fas fa-paper-plane"></i>Send</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <h3>Select a conversation</h3>
                        <p>Choose a conversation from the sidebar or start a new one</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- New Conversation Modal -->
    <div class="modal-overlay" id="newConversationModal">
        <div class="new-conversation-modal">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> New Conversation</h3>
                <button class="modal-close" id="newConversationModalClose"><ion-icon name="close-outline"></ion-icon></button>
            </div>
            <form method="POST" id="newConversationForm">
                <div class="modal-body">
                    <select class="friend-select" name="receiver_id" required>
                        <option value="">Select a friend...</option>
                        <?php foreach ($friends as $friend): ?>
                            <option value="<?php echo $friend['userId']; ?>"><?php echo htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']); ?> (@<?php echo htmlspecialchars($friend['username']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <textarea class="message-textarea" name="initial_message" placeholder="Type your initial message..." required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelNewConversation">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="start_conversation">Start Conversation</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        function initializeTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'light';
            
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
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        initializeTheme();

        themeToggle.addEventListener('click', () => {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            document.body.classList.toggle('dark-mode');
            
            if (newTheme === 'dark') {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
            
            // Save preference
            const formData = new FormData();
            formData.append('theme', newTheme);
            formData.append('update_preferences', 'true');
            
            fetch('settings.php', {
                method: 'POST',
                body: formData
            }).then(response => {
                document.cookie = `user_theme=${newTheme}; path=/; max-age=${30 * 24 * 60 * 60}`;
            }).catch(err => {
                console.log('Theme update failed, but local changes applied');
            });
            
            localStorage.setItem('user_theme', newTheme);
        });

        // Apply theme from localStorage on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('user_theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
                initializeTheme();
            }
        });

        // Sidebar Toggle
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

        // New Conversation Modal
        const newConversationBtn = document.getElementById('newConversationBtn');
        const newConversationModal = document.getElementById('newConversationModal');
        const newConversationModalClose = document.getElementById('newConversationModalClose');
        const cancelNewConversation = document.getElementById('cancelNewConversation');

        newConversationBtn.addEventListener('click', () => {
            newConversationModal.classList.add('active');
        });

        newConversationModalClose.addEventListener('click', () => {
            newConversationModal.classList.remove('active');
        });

        cancelNewConversation.addEventListener('click', () => {
            newConversationModal.classList.remove('active');
        });

        newConversationModal.addEventListener('click', (e) => {
            if (e.target === newConversationModal) {
                newConversationModal.classList.remove('active');
            }
        });

        // Conversation Selection
        const conversationItems = document.querySelectorAll('.conversation-item');
        conversationItems.forEach(item => {
            item.addEventListener('click', () => {
                const conversationId = item.getAttribute('data-conversation-id');
                window.location.href = `messages.php?conversation=${conversationId}`;
            });
        });

        // Load Messages for Active Conversation
        <?php if (isset($_GET['conversation']) && !empty($_GET['conversation']) && $activeConversation): ?>
            const conversationId = '<?php echo $_GET['conversation']; ?>';
            const chatMessages = document.getElementById('chatMessages');
            const otherUserId = '<?php echo $otherUser['id']; ?>';
            
            function loadMessages() {
                fetch(`messages.php?action=get_conversation_messages&conversation_id=${conversationId}`)
                    .then(response => response.json())
                    .then(messages => {
                        chatMessages.innerHTML = '';
                        
                        if (messages.length === 0) {
                            chatMessages.innerHTML = `
                                <div class="empty-state" style="padding: 2rem;">
                                    <i class="fas fa-comments"></i>
                                    <h3>No messages yet</h3>
                                    <p>Start the conversation by sending a message</p>
                                </div>
                            `;
                        } else {
                            messages.forEach(msg => {
                                const messageDiv = document.createElement('div');
                                messageDiv.className = `message ${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? 'sent' : 'received'}`;
                                
                                const messageTime = new Date(msg.created_at);
                                const timeText = messageTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                
                                const readStatus = msg.is_read ? '<i class="fas fa-check-double read-status"></i>' : '<ion-icon name="checkmark-outline"></ion-icon>';
                                
                                messageDiv.innerHTML = `
                                    <div class="message-content">${escapeHtml(msg.message)}</div>
                                    <div class="message-meta">
                                        <span>${timeText}</span>
                                        <div class="message-status">
                                            ${msg.sender_id === '<?php echo $_SESSION['user_id']; ?>' ? readStatus : ''}
                                        </div>
                                    </div>
                                `;
                                
                                chatMessages.appendChild(messageDiv);
                            });
                            
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                        }
                    })
                    .catch(error => {
                        console.error('Error loading messages:', error);
                        chatMessages.innerHTML = `
                            <div class="empty-state" style="padding: 2rem;">
                                <i class="fas fa-exclamation-circle"></i>
                                <h3>Error loading messages</h3>
                                <p>Please try again later</p>
                            </div>
                        `;
                    });
            }
            
            // Load messages initially
            loadMessages();
            
            // Set up real-time updates
            let messageCheckInterval = setInterval(() => {
                loadMessages();
            }, 3000);
            
            // Update online status
            function updateOnlineStatus() {
                const statusElement = document.getElementById('chatUserStatus');
                const statusDot = document.querySelector('.chat-user-avatar .online-status');
                
                fetch(`dashboard.php?action=get_online_status&friend_id=${otherUserId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (statusElement) {
                            statusElement.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                        }
                        if (statusDot) {
                            statusDot.className = `online-status ${data.status}`;
                        }
                    })
                    .catch(error => console.error('Error checking online status:', error));
            }
            
            // Update online status every 10 seconds
            let statusInterval = setInterval(updateOnlineStatus, 10000);
            updateOnlineStatus(); // Initial call
            
            // Send Message
            const chatForm = document.getElementById('chatForm');
            const messageInput = document.getElementById('messageInput');
            
            chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                
                const message = messageInput.value.trim();
                if (!message) return;
                
                const formData = new FormData(chatForm);
                
                fetch('messages.php?action=send_message', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        messageInput.value = '';
                        loadMessages();
                    } else {
                        alert('Failed to send message: ' + (result.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error sending message:', error);
                    alert('Error sending message. Please try again.');
                });
            });
            
            // Auto-resize textarea
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            
            // Delete Conversation
            const deleteConversationBtn = document.getElementById('deleteConversationBtn');
            
            deleteConversationBtn.addEventListener('click', () => {
                if (confirm('Are you sure you want to delete this conversation? This action cannot be undone.')) {
                    const formData = new FormData();
                    formData.append('conversation_id', conversationId);
                    
                    fetch('messages.php?action=delete_conversation', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            window.location.href = 'messages.php';
                        } else {
                            alert('Failed to delete conversation: ' + (result.error || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting conversation:', error);
                        alert('Error deleting conversation. Please try again.');
                    });
                }
            });
            
            // Clean up intervals when leaving the page
            window.addEventListener('beforeunload', () => {
                clearInterval(messageCheckInterval);
                clearInterval(statusInterval);
            });
        <?php endif; ?>
        
        // Search Messages
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
                fetch(`messages.php?action=search_messages&search=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.json())
                    .then(results => {
                        searchResults.innerHTML = '';
                        
                        if (results.length === 0) {
                            searchResults.innerHTML = '<div class="search-result-item">No messages found</div>';
                        } else {
                            results.forEach(result => {
                                const resultItem = document.createElement('div');
                                resultItem.className = 'search-result-item';
                                
                                const senderName = result.sender_first_name + ' ' + result.sender_last_name;
                                const receiverName = result.receiver_first_name + ' ' + result.receiver_last_name;
                                const isSent = result.sender_id === '<?php echo $_SESSION['user_id']; ?>';
                                
                                resultItem.innerHTML = `
                                    <div style="font-weight: 600;">${isSent ? 'To: ' + receiverName : 'From: ' + senderName}</div>
                                    <div style="font-size: 0.8rem; color: var(--gray); margin-bottom: 0.5rem;">${escapeHtml(result.message)}</div>
                                    <div style="font-size: 0.7rem; color: var(--gray);">${new Date(result.created_at).toLocaleString()}</div>
                                `;
                                
                                resultItem.addEventListener('click', () => {
                                    window.location.href = `messages.php?conversation=${result.conversation_id}`;
                                    searchResults.style.display = 'none';
                                    searchInput.value = '';
                                });
                                
                                searchResults.appendChild(resultItem);
                            });
                        }
                        
                        searchResults.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        searchResults.innerHTML = '<div class="search-result-item">Search error</div>';
                        searchResults.style.display = 'block';
                    });
            }, 300);
        });
        
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.style.display = 'none';
            }
        });
        
        // Utility function to escape HTML
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        // Show alerts if any
        <?php if (isset($messageSuccess)): ?>
            setTimeout(() => {
                const alert = document.createElement('div');
                alert.className = 'alert alert-success';
                alert.innerHTML = `<i class="fas fa-check-circle"></i> <?php echo $messageSuccess; ?>`;
                document.querySelector('.messages-container').insertBefore(alert, document.querySelector('.messages-container').firstChild);
                setTimeout(() => alert.remove(), 5000);
            }, 100);
        <?php endif; ?>
        
        <?php if (isset($messageError)): ?>
            setTimeout(() => {
                const alert = document.createElement('div');
                alert.className = 'alert alert-error';
                alert.innerHTML = `<i class="fas fa-exclamation-circle"></i> <?php echo $messageError; ?>`;
                document.querySelector('.messages-container').insertBefore(alert, document.querySelector('.messages-container').firstChild);
                setTimeout(() => alert.remove(), 5000);
            }, 100);
        <?php endif; ?>
    </script>
</body>
</html>