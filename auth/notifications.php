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

// Get notifications
$notifications = [];
$unreadNotificationCount = 0;

$notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'notifications');
if (!$notifConn->connect_error) {
    $notifTableName = $_SESSION['user_id'];
    $notifTableCheck = $notifConn->query("SHOW TABLES LIKE '$notifTableName'");
    
    if ($notifTableCheck->num_rows > 0) {
        $notifStmt = $notifConn->prepare("SELECT * FROM `$notifTableName` ORDER BY created_at DESC");
        $notifStmt->execute();
        $notifications = $notifStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Count unread notifications
        $unreadStmt = $notifConn->prepare("SELECT COUNT(*) as count FROM `$notifTableName` WHERE is_read = 0");
        $unreadStmt->execute();
        $unreadResult = $unreadStmt->get_result()->fetch_assoc();
        $unreadNotificationCount = $unreadResult['count'];
    }
}

// Handle notification actions
if (isset($_POST['mark_as_read'])) {
    $notificationId = $_POST['notification_id'];
    if (isset($notifConn) && !$notifConn->connect_error) {
        $updateStmt = $notifConn->prepare("UPDATE `$notifTableName` SET is_read = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $notificationId);
        $updateStmt->execute();
        header('Location: notifications.php');
        exit();
    }
}

if (isset($_POST['mark_all_read'])) {
    if (isset($notifConn) && !$notifConn->connect_error) {
        $updateStmt = $notifConn->prepare("UPDATE `$notifTableName` SET is_read = 1 WHERE is_read = 0");
        $updateStmt->execute();
        header('Location: notifications.php');
        exit();
    }
}

if (isset($_POST['clear_all'])) {
    if (isset($notifConn) && !$notifConn->connect_error) {
        $deleteStmt = $notifConn->prepare("DELETE FROM `$notifTableName`");
        $deleteStmt->execute();
        header('Location: notifications.php');
        exit();
    }
}

// Close connections
$conn->close();
if (isset($notifConn)) $notifConn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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
            --darker: #020617;
            --light: #f8fafc;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --card-bg: #ffffff;
            --border: #e2e8f0;
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15);
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-hover: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            --glass-bg: rgba(255, 255, 255, 0.1);
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
            --card-bg: #1e293b;
            --border: #334155;
            --glass-bg: rgba(30, 41, 59, 0.6);
            --glass-border: rgba(255, 255, 255, 0.1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.3);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.4);
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
        }

        /* Floating Background Elements */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
            pointer-events: none;
        }

        .shape {
            position: absolute;
            opacity: 0.03;
            border-radius: 50%;
            animation: float 25s infinite linear;
            filter: blur(40px);
        }

        .shape-1 {
            width: 200px;
            height: 200px;
            background: var(--primary);
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 150px;
            height: 150px;
            background: var(--secondary);
            top: 70%;
            left: 80%;
            animation-delay: -8s;
        }

        .shape-3 {
            width: 250px;
            height: 250px;
            background: var(--accent);
            top: 40%;
            left: 70%;
            animation-delay: -16s;
        }

        .shape-4 {
            width: 100px;
            height: 100px;
            background: var(--success);
            top: 20%;
            left: 85%;
            animation-delay: -12s;
        }

        .shape-5 {
            width: 180px;
            height: 180px;
            background: var(--error);
            top: 80%;
            left: 10%;
            animation-delay: -4s;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Header Styles */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 3rem;
            padding: 1.5rem 0;
            position: relative;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--border), transparent);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            background: var(--gradient-light);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .back-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.1), transparent);
            transition: var(--transition);
        }

        .back-btn:hover {
            transform: translateX(-5px);
            box-shadow: var(--shadow-lg);
            color: var(--primary-dark);
        }

        .back-btn:hover::before {
            left: 100%;
        }

        .theme-toggle {
            background: var(--gradient);
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.75rem;
            border-radius: 50%;
            transition: var(--transition);
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
        }

        .theme-toggle:hover {
            transform: scale(1.1) rotate(180deg);
            box-shadow: var(--shadow-xl);
        }

        /* Page Title Styles */
        .page-header {
            text-align: center;
            margin-bottom: 3rem;
            position: relative;
        }

        .page-title {
            font-size: 4rem;
            font-weight: 900;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
            position: relative;
            display: inline-block;
            animation: titleGlow 3s ease-in-out infinite alternate;
        }

        @keyframes titleGlow {
            from {
                filter: drop-shadow(0 0 20px rgba(99, 102, 241, 0.3));
            }
            to {
                filter: drop-shadow(0 0 30px rgba(139, 92, 246, 0.5));
            }
        }

        .page-subtitle {
            font-size: 1.2rem;
            color: var(--gray);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* Notifications Card */
        .notifications-card {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            border: 1px solid var(--border);
            backdrop-filter: blur(20px);
            position: relative;
        }

        .notifications-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient);
        }

        .card-header {
            padding: 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
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
            background: var(--gradient-light);
            opacity: 0.1;
            transform: rotate(-45deg);
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            position: relative;
            z-index: 1;
        }

        .notification-stats {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            font-size: 0.95rem;
            position: relative;
            z-index: 1;
        }

        .unread-badge {
            background: var(--gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 700;
            box-shadow: var(--shadow);
            animation: pulse 2s infinite;
        }

        .total-count {
            color: var(--gray);
            font-weight: 600;
        }

        .card-actions {
            display: flex;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Notifications List */
        .notifications-list {
            max-height: 70vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary) transparent;
        }

        .notifications-list::-webkit-scrollbar {
            width: 6px;
        }

        .notifications-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .notifications-list::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }

        .notification-item {
            padding: 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.6s ease-out;
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

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background: var(--gradient-light);
            border-left: 4px solid var(--primary);
        }

        .notification-item:hover {
            transform: translateX(10px);
            background: var(--glass-bg);
        }

        .notification-item:hover::before {
            left: 0;
        }

        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
            transition: var(--transition);
            font-size: 1.2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            z-index: 1;
        }

        .notification-item:hover .notification-icon {
            transform: scale(1.1) rotate(10deg);
            box-shadow: var(--shadow-xl);
        }

        .notification-content {
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .notification-message {
            margin-bottom: 0.75rem;
            line-height: 1.6;
            font-size: 1.05rem;
            color: var(--dark);
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 0.75rem;
        }

        .notification-time {
            font-size: 0.85rem;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notification-type {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .type-collaboration_invite {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .type-collaboration_response {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .type-system {
            background: rgba(245, 158, 11, 0.1);
            color: var(--accent);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .notification-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
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
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            position: relative;
            z-index: 1;
        }

        .notification-action:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 6rem 2rem;
            color: var(--gray);
            position: relative;
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            background: var(--gradient-light);
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.5;
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.7;
            color: var(--primary);
            position: relative;
            z-index: 1;
            animation: bounce 3s infinite;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            color: var(--dark);
            position: relative;
            z-index: 1;
            font-weight: 700;
        }

        .empty-state p {
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            position: relative;
            z-index: 1;
        }

        .btn-primary:hover {
            background: var(--gradient-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-xl);
        }

        /* Animations */
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            33% {
                transform: translateY(-30px) rotate(120deg);
            }
            66% {
                transform: translateY(30px) rotate(240deg);
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

        @keyframes glow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(99, 102, 241, 0.3);
            }
            50% {
                box-shadow: 0 0 40px rgba(139, 92, 246, 0.5);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 2.5rem;
            }
            
            .card-header {
                flex-direction: column;
                gap: 1.5rem;
                align-items: flex-start;
                text-align: center;
            }
            
            .notification-stats {
                justify-content: center;
                width: 100%;
            }
            
            .card-actions {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .notification-item {
                flex-direction: column;
                gap: 1rem;
                padding: 1.5rem;
            }
            
            .notification-meta {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }
            
            .notification-actions {
                justify-content: center;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }

        /* Enhanced hover effects */
        .notification-item {
            cursor: pointer;
        }

        .notification-read-indicator {
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0;
            transition: var(--transition);
        }

        .notification-item.unread .notification-read-indicator {
            opacity: 1;
            animation: pulse 1.5s infinite;
        }

        /* Advanced gradient borders */
        .notification-item::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: inherit;
            padding: 2px;
            background: var(--gradient);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: xor;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            pointer-events: none;
            opacity: 0;
            transition: var(--transition);
        }

        .notification-item:hover::after {
            opacity: 1;
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

        <div class="page-header">
            <h1 class="page-title">Notifications</h1>
            <p class="page-subtitle">Stay updated with your recent activities, collaborations, and system updates in real-time</p>
        </div>

        <div class="notifications-card">
            <div class="card-header">
                <h2>Your Activity Feed</h2>
                <div class="notification-stats">
                    <span class="unread-badge"><?php echo $unreadNotificationCount; ?> Unread</span>
                    <span class="total-count"><?php echo count($notifications); ?> Total</span>
                </div>
                <div class="card-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="mark_all_read" class="btn btn-outline">
                            <i class="fas fa-check-double"></i> Mark All Read
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="clear_all" class="btn btn-danger" onclick="return confirm('Are you sure you want to clear all notifications? This action cannot be undone.')">
                            <ion-icon name="trash-outline"></ion-icon> Clear All
                        </button>
                    </form>
                </div>
            </div>

            <div class="notifications-list">
                <?php if (count($notifications) > 0): ?>
                    <?php foreach ($notifications as $index => $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s">
                            <div class="notification-read-indicator"></div>
                            <div class="notification-icon">
                                <?php if ($notification['type'] === 'collaboration_invite'): ?>
                                    <i class="fas fa-user-plus"></i>
                                <?php elseif ($notification['type'] === 'collaboration_response'): ?>
                                    <i class="fas fa-handshake"></i>
                                <?php elseif ($notification['type'] === 'system'): ?>
                                    <i class="fas fa-cog"></i>
                                <?php else: ?>
                                    <i class="fas fa-bell"></i>
                                <?php endif; ?>
                            </div>
                            <div class="notification-content">
                                <div class="notification-message">
                                    <?php echo htmlspecialchars($notification['message']); ?>
                                </div>
                                <div class="notification-meta">
                                    <span class="notification-time">
                                        <ion-icon name="time-outline"></ion-icon>
                                        <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                    </span>
                                    <?php if ($notification['type']): ?>
                                        <span class="notification-type type-<?php echo $notification['type']; ?>">
                                            <?php echo str_replace('_', ' ', $notification['type']); ?>
                                        </span>
                                    <?php endif; ?>
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
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <h3>No Notifications Yet</h3>
                        <p>Your notification center is currently empty. You'll see important updates, collaboration invites, and system messages here when they arrive.</p>
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-rocket"></i>
                            Explore Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Theme Toggle with enhanced animation
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        // Check for saved theme or prefer-color-scheme
        if (localStorage.getItem('theme') === 'dark' || 
            (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            // Add transition class for smooth theme change
            document.body.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
            
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
            
            // Remove transition after animation completes
            setTimeout(() => {
                document.body.style.transition = '';
            }, 500);
        });

        // Enhanced notification interactions
        document.addEventListener('DOMContentLoaded', () => {
            const notificationItems = document.querySelectorAll('.notification-item');
            
            notificationItems.forEach(item => {
                item.addEventListener('click', (e) => {
                    // Don't trigger if user clicked on action buttons
                    if (!e.target.closest('.notification-action')) {
                        item.classList.add('read');
                        item.classList.remove('unread');
                        
                        // Add subtle animation
                        item.style.transform = 'translateX(0)';
                        setTimeout(() => {
                            item.style.transform = '';
                        }, 300);
                    }
                });
            });

            // Add parallax effect to floating shapes
            document.addEventListener('mousemove', (e) => {
                const shapes = document.querySelectorAll('.shape');
                const mouseX = e.clientX / window.innerWidth;
                const mouseY = e.clientY / window.innerHeight;
                
                shapes.forEach((shape, index) => {
                    const speed = (index + 1) * 0.5;
                    const x = (mouseX - 0.5) * speed * 20;
                    const y = (mouseY - 0.5) * speed * 20;
                    
                    shape.style.transform = `translate(${x}px, ${y}px) rotate(${x}deg)`;
                });
            });
        });

        // Auto-refresh notifications every 30 seconds with visual indicator
        setInterval(() => {
            const refreshIndicator = document.createElement('div');
            refreshIndicator.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--gradient);
                color: white;
                padding: 0.5rem 1rem;
                border-radius: var(--radius);
                font-size: 0.8rem;
                font-weight: 600;
                z-index: 10000;
                animation: slideInRight 0.3s ease-out;
            `;
            refreshIndicator.textContent = '🔄 Refreshing...';
            document.body.appendChild(refreshIndicator);
            
            setTimeout(() => {
                refreshIndicator.remove();
                window.location.reload();
            }, 1000);
        }, 30000);

        // Add keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Alt + N to focus notifications
            if (e.altKey && e.key === 'n') {
                e.preventDefault();
                document.querySelector('.notifications-list').scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            }
            
            // Escape key to go back
            if (e.key === 'Escape') {
                window.location.href = 'dashboard.php';
            }
        });

        // Enhanced loading animation
        window.addEventListener('load', () => {
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.5s ease-in';
            
            setTimeout(() => {
                document.body.style.opacity = '1';
            }, 100);
        });
    </script>
</body>
</html>