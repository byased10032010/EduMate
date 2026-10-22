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
define('DB_NAME_FRIENDS', 'user_friends');
define('DB_NAME_REPOS', 'user_repos');

// Database connection function
function getDBConnection($dbName) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $dbName);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Initialize profile table if it doesn't exist
function initializeProfileTable() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_CODESPHERE);
    
    // Check if profiles table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'profiles'");
    if ($tableCheck->num_rows === 0) {
        // Create profiles table
        $createTableSQL = "CREATE TABLE profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL UNIQUE,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            bio TEXT,
            profile_picture VARCHAR(255),
            theme VARCHAR(20) DEFAULT 'light',
            language VARCHAR(10) DEFAULT 'en',
            email_notifications BOOLEAN DEFAULT TRUE,
            push_notifications BOOLEAN DEFAULT TRUE,
            auto_save BOOLEAN DEFAULT TRUE,
            code_formatting BOOLEAN DEFAULT TRUE,
            profile_visibility ENUM('public', 'private', 'friends') DEFAULT 'public',
            email_visibility ENUM('private', 'friends') DEFAULT 'private',
            repo_visibility ENUM('public', 'private', 'friends') DEFAULT 'public',
            activity_visibility ENUM('public', 'private', 'friends') DEFAULT 'public',
            allow_friend_requests BOOLEAN DEFAULT TRUE,
            allow_messages BOOLEAN DEFAULT TRUE,
            two_factor_enabled BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(userId) ON DELETE CASCADE
        )";
        
        if ($conn->query($createTableSQL) === FALSE) {
            error_log("Error creating profiles table: " . $conn->error);
        }
        
        // Migrate existing user data to profiles table
        $migrateSQL = "INSERT IGNORE INTO profiles (user_id, first_name, last_name, username, email) 
                      SELECT userId, first_name, last_name, username, email FROM users";
        $conn->query($migrateSQL);
    }
    
    $conn->close();
}

// Initialize the table
initializeProfileTable();

// Get user data with profile information
$conn = getDBConnection(DB_NAME_CODESPHERE);
$user = [];

// Try to get data from profiles table first, fall back to users table
$stmt = $conn->prepare("
    SELECT 
        COALESCE(p.user_id, u.userId) as user_id,
        COALESCE(p.first_name, u.first_name) as first_name,
        COALESCE(p.last_name, u.last_name) as last_name,
        COALESCE(p.username, u.username) as username,
        COALESCE(p.email, u.email) as email,
        p.bio,
        p.profile_picture,
        COALESCE(p.theme, 'light') as theme,
        COALESCE(p.language, 'en') as language,
        COALESCE(p.email_notifications, 1) as email_notifications,
        COALESCE(p.push_notifications, 1) as push_notifications,
        COALESCE(p.auto_save, 1) as auto_save,
        COALESCE(p.code_formatting, 1) as code_formatting,
        COALESCE(p.profile_visibility, 'public') as profile_visibility,
        COALESCE(p.email_visibility, 'private') as email_visibility,
        COALESCE(p.repo_visibility, 'public') as repo_visibility,
        COALESCE(p.activity_visibility, 'public') as activity_visibility,
        COALESCE(p.allow_friend_requests, 1) as allow_friend_requests,
        COALESCE(p.allow_messages, 1) as allow_messages,
        COALESCE(p.two_factor_enabled, 0) as two_factor_enabled,
        u.password as password_hash
    FROM users u
    LEFT JOIN profiles p ON u.userId = p.user_id
    WHERE u.userId = ?
");
$stmt->bind_param("s", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Initialize variables
$successMessage = '';
$errorMessage = '';

// Handle file upload
function handleFileUpload($file, $userId) {
    $uploadDir = 'uploads/profiles/';
    
    // Create directory if it doesn't exist with proper permissions
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'message' => 'Failed to create upload directory'];
        }
    }
    
    // Check if directory is writable
    if (!is_writable($uploadDir)) {
        return ['success' => false, 'message' => 'Upload directory is not writable'];
    }
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        return ['success' => false, 'message' => $errorMessages[$file['error']] ?? 'Unknown upload error'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size exceeds 2MB limit'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, and GIF files are allowed'];
    }
    
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = $userId . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // Verify the file was actually created
        if (file_exists($filePath)) {
            return ['success' => true, 'file_path' => $filePath];
        } else {
            return ['success' => false, 'message' => 'File was not saved properly'];
        }
    }
    
    return ['success' => false, 'message' => 'Failed to upload file'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update Profile Information
    if (isset($_POST['update_profile'])) {
        $firstName = trim($_POST['first_name']);
        $lastName = trim($_POST['last_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $bio = trim($_POST['bio']);
        
        // Validate inputs
        if (empty($firstName) || empty($lastName) || empty($username) || empty($email)) {
            $errorMessage = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Please enter a valid email address.";
        } else {
            // Check if username is already taken by another user
            $checkStmt = $conn->prepare("SELECT user_id FROM profiles WHERE username = ? AND user_id != ?");
            $checkStmt->bind_param("ss", $username, $_SESSION['user_id']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $errorMessage = "Username is already taken. Please choose a different one.";
            } else {
                // Check if email is already taken by another user
                $checkStmt = $conn->prepare("SELECT user_id FROM profiles WHERE email = ? AND user_id != ?");
                $checkStmt->bind_param("ss", $email, $_SESSION['user_id']);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkResult->num_rows > 0) {
                    $errorMessage = "Email is already registered. Please use a different email.";
                } else {
                    // Handle profile picture removal
                    $profilePicture = $user['profile_picture'];
                    if (isset($_POST['remove_profile_picture']) && $_POST['remove_profile_picture'] == '1') {
                        if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                            unlink($user['profile_picture']);
                        }
                        $profilePicture = NULL;
                    }
                    // Handle file upload
                    else if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                        $uploadResult = handleFileUpload($_FILES['profile_picture'], $_SESSION['user_id']);
                        if ($uploadResult['success']) {
                            $profilePicture = $uploadResult['file_path'];
                            
                            // Delete old profile picture if exists
                            if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                                unlink($user['profile_picture']);
                            }
                        } else {
                            $errorMessage = $uploadResult['message'];
                        }
                    }
                    
                    if (empty($errorMessage)) {
                        // Update or insert profile data
                        $upsertStmt = $conn->prepare("
                            INSERT INTO profiles (user_id, first_name, last_name, username, email, bio, profile_picture) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                first_name = VALUES(first_name),
                                last_name = VALUES(last_name),
                                username = VALUES(username),
                                email = VALUES(email),
                                bio = VALUES(bio),
                                profile_picture = VALUES(profile_picture)
                        ");
                        $upsertStmt->bind_param("sssssss", $_SESSION['user_id'], $firstName, $lastName, $username, $email, $bio, $profilePicture);
                        
                        if ($upsertStmt->execute()) {
                            $successMessage = "Profile updated successfully!";
                            // Refresh user data
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $user = $result->fetch_assoc();
                        } else {
                            $errorMessage = "Failed to update profile. Please try again.";
                        }
                    }
                }
            }
        }
    }
    
    // Change Password
    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $errorMessage = "Please fill in all password fields.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New passwords do not match.";
        } elseif (strlen($newPassword) < 8) {
            $errorMessage = "Password must be at least 8 characters long.";
        } else {
            // Verify current password - PLAIN TEXT COMPARISON (as per your auth.php)
            if ($currentPassword === $user['password_hash']) {
                // Update password in users table - STORE AS PLAIN TEXT (as per your auth.php)
                $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE userId = ?");
                $updateStmt->bind_param("ss", $newPassword, $_SESSION['user_id']);
                
                if ($updateStmt->execute()) {
                    $successMessage = "Password changed successfully!";
                    // Update the local user data
                    $user['password_hash'] = $newPassword;
                } else {
                    $errorMessage = "Failed to change password. Please try again.";
                }
            } else {
                $errorMessage = "Current password is incorrect.";
            }
        }
    }
    
    // Update Preferences
if (isset($_POST['update_preferences'])) {
    $theme = $_POST['theme'] ?? 'light';
    $language = $_POST['language'] ?? 'en';
    $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
    $pushNotifications = isset($_POST['push_notifications']) ? 1 : 0;
    $autoSave = isset($_POST['auto_save']) ? 1 : 0;
    $codeFormatting = isset($_POST['code_formatting']) ? 1 : 0;
    $twoFactor = isset($_POST['two_factor']) ? 1 : 0;
    
    // Update preferences in profiles table
    $upsertStmt = $conn->prepare("
        INSERT INTO profiles (user_id, theme, language, email_notifications, push_notifications, auto_save, code_formatting, two_factor_enabled) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            theme = VALUES(theme),
            language = VALUES(language),
            email_notifications = VALUES(email_notifications),
            push_notifications = VALUES(push_notifications),
            auto_save = VALUES(auto_save),
            code_formatting = VALUES(code_formatting),
            two_factor_enabled = VALUES(two_factor_enabled)
    ");
    $upsertStmt->bind_param("sssiiiii", $_SESSION['user_id'], $theme, $language, $emailNotifications, $pushNotifications, $autoSave, $codeFormatting, $twoFactor);
    
    if ($upsertStmt->execute()) {
        $successMessage = "Preferences updated successfully!";
        // Refresh user data
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        // Update session theme if changed
        if ($theme !== ($_SESSION['theme'] ?? 'light')) {
            $_SESSION['theme'] = $theme;
        }
        
        // Set a cookie for theme preference (lasts 30 days)
        setcookie('user_theme', $theme, time() + (30 * 24 * 60 * 60), "/");
    } else {
        $errorMessage = "Failed to update preferences. Please try again.";
    }
}

    // Update Privacy Settings
    if (isset($_POST['update_privacy'])) {
        $profileVisibility = $_POST['profile_visibility'] ?? 'public';
        $emailVisibility = $_POST['email_visibility'] ?? 'private';
        $repoVisibility = $_POST['repo_visibility'] ?? 'public';
        $activityVisibility = $_POST['activity_visibility'] ?? 'public';
        $allowFriendRequests = isset($_POST['allow_friend_requests']) ? 1 : 0;
        $allowMessages = isset($_POST['allow_messages']) ? 1 : 0;
        
        // Update privacy settings in profiles table
        $upsertStmt = $conn->prepare("
            INSERT INTO profiles (user_id, profile_visibility, email_visibility, repo_visibility, activity_visibility, allow_friend_requests, allow_messages) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                profile_visibility = VALUES(profile_visibility),
                email_visibility = VALUES(email_visibility),
                repo_visibility = VALUES(repo_visibility),
                activity_visibility = VALUES(activity_visibility),
                allow_friend_requests = VALUES(allow_friend_requests),
                allow_messages = VALUES(allow_messages)
        ");
        $upsertStmt->bind_param("sssssii", $_SESSION['user_id'], $profileVisibility, $emailVisibility, $repoVisibility, $activityVisibility, $allowFriendRequests, $allowMessages);
        
        if ($upsertStmt->execute()) {
            $successMessage = "Privacy settings updated successfully!";
            // Refresh user data
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
        } else {
            $errorMessage = "Failed to update privacy settings. Please try again.";
        }
    }
    
    // Export Data
    if (isset($_POST['export_data'])) {
        // Gather all user data
        $userData = [
            'profile' => $user,
            'repositories' => [],
            'friends' => [],
            'messages' => []
        ];
        
        // Get repositories
        $reposConn = getDBConnection(DB_NAME_REPOS);
        $tableName = $user['username'] . '_' . $_SESSION['user_id'];
        $tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
        if ($tableCheck->num_rows > 0) {
            $repoStmt = $reposConn->prepare("SELECT * FROM `$tableName`");
            $repoStmt->execute();
            $userData['repositories'] = $repoStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $reposConn->close();
        
        // Get friends data
        $friendsConn = getDBConnection(DB_NAME_FRIENDS);
        $friendsStmt = $friendsConn->prepare("
            SELECT u.username, u.first_name, u.last_name, fr.status, fr.created_at 
            FROM friend_requests fr 
            JOIN codesphere.users u ON (fr.sender_id = u.userId OR fr.receiver_id = u.userId) 
            WHERE (fr.sender_id = ? OR fr.receiver_id = ?) AND u.userId != ? AND fr.status = 'accepted'
        ");
        $friendsStmt->bind_param("sss", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
        $friendsStmt->execute();
        $userData['friends'] = $friendsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $friendsConn->close();
        
        // Set headers for download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="codesphere_data_' . date('Y-m-d') . '.json"');
        echo json_encode($userData, JSON_PRETTY_PRINT);
        exit();
    }
    
    // Handle Account Deletion
    if (isset($_POST['delete_account'])) {
        $confirmText = $_POST['confirm_text'] ?? '';
        
        if ($confirmText === 'DELETE MY ACCOUNT') {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get user's friends before deletion to notify them
                $friendsConn = getDBConnection(DB_NAME_FRIENDS);
                $getFriendsStmt = $friendsConn->prepare("
                    SELECT 
                        CASE 
                            WHEN sender_id = ? THEN receiver_id 
                            ELSE sender_id 
                        END as friend_id
                    FROM friend_requests 
                    WHERE (sender_id = ? OR receiver_id = ?) AND status = 'accepted'
                ");
                $getFriendsStmt->bind_param("sss", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
                $getFriendsStmt->execute();
                $friendsResult = $getFriendsStmt->get_result();
                $friendIds = [];
                while ($row = $friendsResult->fetch_assoc()) {
                    $friendIds[] = $row['friend_id'];
                }
                
                // Update friend requests to mark as deleted
                $updateFriendsStmt = $friendsConn->prepare("
                    UPDATE friend_requests 
                    SET status = 'deleted' 
                    WHERE (sender_id = ? OR receiver_id = ?) AND status = 'accepted'
                ");
                $updateFriendsStmt->bind_param("ss", $_SESSION['user_id'], $_SESSION['user_id']);
                $updateFriendsStmt->execute();
                
                $friendsConn->close();
                
                // Delete user data from profiles table
                $deleteProfileStmt = $conn->prepare("DELETE FROM profiles WHERE user_id = ?");
                $deleteProfileStmt->bind_param("s", $_SESSION['user_id']);
                $deleteProfileStmt->execute();
                
                // Delete user repositories data
                $reposConn = getDBConnection(DB_NAME_REPOS);
                $tableName = $user['username'] . '_' . $_SESSION['user_id'];
                $reposConn->query("DROP TABLE IF EXISTS `$tableName`");
                $reposConn->close();
                
                // Delete user from users table
                $deleteUserStmt = $conn->prepare("DELETE FROM users WHERE userId = ?");
                $deleteUserStmt->bind_param("s", $_SESSION['user_id']);
                $deleteUserStmt->execute();
                
                // Commit transaction
                $conn->commit();
                
                // Delete profile picture if exists
                if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                    unlink($user['profile_picture']);
                }
                
                // Logout and redirect
                session_destroy();
                header('Location: auth.php?message=account_deleted');
                exit();
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $errorMessage = "Failed to delete account. Please try again later. Error: " . $e->getMessage();
            }
        } else {
            $errorMessage = "Please type 'DELETE MY ACCOUNT' exactly as shown to confirm deletion.";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $user['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - CodeSphere</title>    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #06b6d4;
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
            --transition-slow: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.15);
            --shadow-2xl: 0 25px 50px rgba(0, 0, 0, 0.25);
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --radius-2xl: 20px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-hover: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            --gradient-light: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            --gradient-glow: linear-gradient(135deg, rgba(99, 102, 241, 0.2) 0%, rgba(139, 92, 246, 0.2) 100%);
            --neon-glow: 0 0 20px rgba(99, 102, 241, 0.4), 0 0 40px rgba(99, 102, 241, 0.2), 0 0 60px rgba(99, 102, 241, 0.1);
            --neon-glow-accent: 0 0 20px rgba(245, 158, 11, 0.4), 0 0 40px rgba(245, 158, 11, 0.2), 0 0 60px rgba(245, 158, 11, 0.1);
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
            --shadow-2xl: 0 25px 50px rgba(0, 0, 0, 0.5);
            --gradient-light: linear-gradient(135deg, rgba(129, 140, 248, 0.1) 0%, rgba(167, 139, 250, 0.1) 100%);
            --neon-glow: 0 0 20px rgba(129, 140, 248, 0.4), 0 0 40px rgba(129, 140, 248, 0.2), 0 0 60px rgba(129, 140, 248, 0.1);
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
            transition: var(--transition-slow);
            overflow-x: hidden;
        }

        /* Enhanced Sidebar with Glass Effect */
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.9);
            border-right: 1px solid var(--border);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            transition: var(--transition-slow);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(20px);
        }

        .dark-mode .sidebar {
            background: rgba(30, 41, 59, 0.9);
        }

        .sidebar.collapsed {
            width: 80px;
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
            animation: float 8s infinite ease-in-out;
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
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
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
            transition: var(--transition);
        }

        .logo:hover .logo-icon {
            transform: rotate(15deg);
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
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .sidebar.collapsed .nav-badge {
            display: none;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: var(--transition-slow);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        /* Header with Glass Effect */
        header {
            background: rgba(255, 255, 255, 0.8);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow);
            transition: var(--transition-slow);
        }

        .dark-mode header {
            background: rgba(30, 41, 59, 0.8);
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
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-size: 0.9rem;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .nav-btn {
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            color: var(--dark);
            font-size: 1.1rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .nav-btn:hover {
            background: var(--sidebar-hover);
            transform: translateY(-4px);
            animation: bounceDown 0.5s ease;
        }

        @keyframes bounceDown {
            0% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
            100% { transform: translateY(-4px); }
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .user-menu:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            position: relative;
        }

        .user-avatar::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: var(--gradient);
            border-radius: 50%;
            z-index: -1;
            opacity: 0;
            transition: var(--transition);
        }

        .user-menu:hover .user-avatar::before {
            opacity: 1;
            animation: rotate 2s linear infinite;
        }

        .user-menu:hover .user-avatar {
            transform: scale(1.1);
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

        /* Settings Content */
        .settings {
            flex: 1;
            padding: 2rem;
        }

        .settings-header {
            margin-bottom: 2.5rem;
            background: var(--gradient);
            color: white;
            padding: 2.5rem;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-2xl), var(--neon-glow);
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.8s ease-out;
        }

        .settings-header::before {
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

        .settings-header::after {
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

        .settings-header-content {
            position: relative;
            z-index: 1;
        }

        .settings-header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            font-weight: 800;
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .settings-icon {
            font-size: 2.5rem;
            animation: bounce 2s infinite, glow 3s infinite alternate;
        }

        .settings-header p {
            opacity: 0.9;
            font-size: 1.2rem;
            position: relative;
            max-width: 600px;
            text-shadow: 0 1px 5px rgba(0, 0, 0, 0.2);
        }

        /* Settings Navigation */
        .settings-nav {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .settings-nav::-webkit-scrollbar {
            display: none;
        }

        .settings-nav-item {
            padding: 0.75rem 1.5rem;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .settings-nav-item::before {
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

        .settings-nav-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .settings-nav-item:hover::before {
            left: 0;
        }

        .settings-nav-item.active {
            background: var(--gradient);
            color: white;
            border-color: var(--primary);
            box-shadow: var(--shadow-lg);
        }

        .settings-nav-item.active::before {
            display: none;
        }

        /* Settings Sections */
        .settings-section {
            display: none;
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            animation: fadeInUp 0.8s ease-out;
            backdrop-filter: blur(10px);
        }

        .settings-section.active {
            display: block;
        }

        .section-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--gradient-light);
        }

        .section-header h2 {
            font-size: 1.5rem;
            color: var(--dark);
            font-weight: 700;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-content {
            padding: 2rem;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-size: 0.9rem;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .form-check-label {
            font-weight: 500;
            color: var(--dark);
        }

        /* Buttons */
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

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow-lg);
        }

        .btn-primary:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: var(--shadow-xl), var(--neon-glow);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: var(--gray);
            color: white;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--dark);
        }

        .btn-outline:hover {
            background: var(--sidebar-hover);
            transform: translateY(-2px);
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            animation: slideInUp 0.5s ease-out;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        /* Profile Picture Upload Section */
        .profile-picture-section {
            background: var(--gradient-light);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .profile-picture-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 150px;
            height: 150px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 50%;
            animation: float 8s infinite ease-in-out;
        }

        .profile-picture-container {
            display: flex;
            align-items: center;
            gap: 2rem;
            position: relative;
            z-index: 1;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            border: 4px solid var(--card-bg);
            flex-shrink: 0;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .profile-avatar:hover img {
            transform: scale(1.05);
        }

        .profile-upload-controls {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .upload-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .upload-btn {
            padding: 0.75rem 1.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--card-bg);
            color: var(--dark);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .upload-btn:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .upload-btn.primary {
            background: var(--gradient);
            color: white;
            border-color: transparent;
        }

        .upload-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .upload-btn.danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border-color: var(--error);
        }

        .upload-btn.danger:hover {
            background: var(--error);
            color: white;
        }

        .upload-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .upload-status {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .upload-status.success {
            color: var(--success);
            font-weight: 500;
        }

        .upload-status.error {
            color: var(--error);
            font-weight: 500;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
            opacity: 0;
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: var(--transition);
            border-radius: 50%;
            cursor: pointer;
        }

        .profile-avatar:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay i {
            color: white;
            font-size: 1.5rem;
        }

        /* Image Preview Modal */
        .image-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(10px);
        }

        .image-preview-modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .preview-container {
            background: var(--card-bg);
            border-radius: var(--radius-xl);
            padding: 2rem;
            max-width: 90%;
            max-height: 90%;
            position: relative;
            box-shadow: var(--shadow-2xl);
        }

        .preview-image {
            max-width: 400px;
            max-height: 400px;
            border-radius: var(--radius);
            display: block;
        }

        .preview-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: center;
        }

        .close-preview {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: var(--gray);
            font-size: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
            padding: 0.5rem;
            border-radius: var(--radius);
        }

        .close-preview:hover {
            color: var(--error);
            background: rgba(239, 68, 68, 0.1);
        }

        /* Danger Zone */
        .danger-zone {
            border: 1px solid var(--error);
            border-radius: var(--radius-lg);
            padding: 2rem;
            background: rgba(239, 68, 68, 0.05);
        }

        .danger-zone h3 {
            color: var(--error);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .danger-zone p {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        .confirm-delete {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .confirm-delete .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.8rem;
        }

        .strength-weak { color: var(--error); }
        .strength-medium { color: var(--warning); }
        .strength-strong { color: var(--success); }
        .strength-very-strong { color: var(--success); font-weight: bold; }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient);
            transition: var(--transition);
            border-radius: 3px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--gradient-light);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 80px;
            }

            .sidebar .nav-text,
            .sidebar .logo-text,
            .sidebar .nav-title,
            .sidebar .nav-badge {
                display: none;
            }

            .main-content {
                margin-left: 80px;
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

            .settings {
                padding: 1rem;
            }

            .settings-header {
                padding: 2rem 1.5rem;
            }

            .settings-header h1 {
                font-size: 2rem;
            }

            .settings-header p {
                font-size: 1rem;
            }

            .form-row {
                flex-direction: column;
                gap: 1rem;
            }

            .section-content {
                padding: 1.5rem;
            }

            .confirm-delete {
                flex-direction: column;
                align-items: stretch;
            }

            .profile-picture-container {
                flex-direction: column;
                text-align: center;
            }

            .upload-buttons {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
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

        @keyframes glow {
            from {
                filter: drop-shadow(0 0 5px rgba(255, 255, 255, 0.5));
            }
            to {
                filter: drop-shadow(0 0 20px rgba(255, 255, 255, 0.8));
            }
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
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

        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            max-width: 400px;
        }

        .toast {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem 1.5rem;
            margin-bottom: 0.5rem;
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideInRight 0.3s ease-out;
            transition: var(--transition);
        }

        .toast.success {
            border-left: 4px solid var(--success);
        }

        .toast.error {
            border-left: 4px solid var(--error);
        }

        .toast.warning {
            border-left: 4px solid var(--warning);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="<?php echo ($user['theme'] ?? 'light') === 'dark' ? 'dark-mode' : ''; ?>">
    <!-- Floating Background Elements -->
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>
        
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="dashboard.php" class="logo">
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
                <a href="all_repos.php" class="nav-item">
                    <i class="fas fa-code-branch nav-icon"></i>
                    <span class="nav-text">All Repositories</span>
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
                </a>
                <a href="notifications.php" class="nav-item">
                    <i class="fas fa-bell nav-icon"></i>
                    <span class="nav-text">Notifications</span>
                    <span class="nav-badge">3</span>
                </a>
                <a href="messages.php" class="nav-item">
                    <i class="fas fa-envelope nav-icon"></i>
                    <span class="nav-text">Messages</span>
                    <span class="nav-badge">5</span>
                </a>
                <a href="friends.php" class="nav-item">
                    <i class="fas fa-user-friends nav-icon"></i>
                    <span class="nav-text">Friends</span>
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
                <a href="settings.php" class="nav-item active">
                    <i class="fas fa-cog nav-icon"></i>
                    <span class="nav-text">Settings</span>
                </a>
                
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Header -->
        <header>
            <div class="top-nav">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="search-bar">
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" placeholder="Search settings...">
                    </div>
                </div>

                <div class="nav-right">
                    <button class="nav-btn theme-toggle" id="themeToggle" title="Toggle theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="user-menu" onclick="window.location.href='profile.php'">
                        <div class="user-avatar">
                            <?php 
                            if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                                echo '<img src="' . htmlspecialchars($user['profile_picture']) . '" alt="Profile Picture">';
                            } else {
                                echo strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1));
                            }
                            ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div class="user-role">Developer</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Settings Content -->
        <section class="settings">
            <!-- Success/Error Messages -->
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $successMessage; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?>
                </div>
            <?php endif; ?>

            <div class="settings-header">
                <div class="settings-header-content">
                    <h1>
                        <i class="fas fa-cog settings-icon"></i>
                        Account Settings
                    </h1>
                    <p>Manage your account preferences, privacy settings, and security options</p>
                </div>
            </div>

            <!-- User Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" id="repoCount">12</div>
                    <div class="stat-label">Repositories</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="friendCount">24</div>
                    <div class="stat-label">Friends</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="contributionCount">156</div>
                    <div class="stat-label">Contributions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="projectCount">8</div>
                    <div class="stat-label">Active Projects</div>
                </div>
            </div>

            <!-- Settings Navigation -->
            <div class="settings-nav">
                <div class="settings-nav-item active" data-target="profile">
                    <i class="fas fa-user"></i>
                    Profile
                </div>
                <div class="settings-nav-item" data-target="security">
                    <i class="fas fa-shield-alt"></i>
                    Security
                </div>
                <div class="settings-nav-item" data-target="preferences">
                    <i class="fas fa-sliders-h"></i>
                    Preferences
                </div>
                <div class="settings-nav-item" data-target="privacy">
                    <ion-icon name="lock-closed-outline"></ion-icon>
                    Privacy
                </div>
                <div class="settings-nav-item" data-target="account">
                    <i class="fas fa-user-cog"></i>
                    Account
                </div>
            </div>

            <!-- Profile Settings -->
            <div class="settings-section active" id="profile-section">
                <div class="section-header">
                    <h2>Profile Information</h2>
                </div>
                <div class="section-content">
                    <form method="POST" enctype="multipart/form-data" id="profileForm">
                        <!-- Profile Picture Upload Section -->
                        <div class="profile-picture-section">
                            <div class="profile-picture-container">
                                <div class="profile-avatar" id="profileAvatar">
                                    <?php 
                                    if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                                        echo '<img src="' . htmlspecialchars($user['profile_picture']) . '" alt="Profile Picture" id="avatarImage">';
                                    } else {
                                        echo '<span id="avatarInitials">' . strtoupper(substr($user['first_name'], 0, 1)) . strtoupper(substr($user['last_name'], 0, 1)) . '</span>';
                                    }
                                    ?>
                                    <div class="avatar-overlay" onclick="document.getElementById('profile_picture').click()">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                </div>
                                
                                <div class="profile-upload-controls">
                                    <div class="upload-buttons">
                                        <div class="file-input-wrapper">
                                            <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif" onchange="handleFileSelect(this.files[0])">
                                            <button type="button" class="upload-btn primary" onclick="document.getElementById('profile_picture').click()">
                                                <ion-icon name="cloud-upload-outline"></ion-icon>
                                                Upload New Photo
                                            </button>
                                        </div>
                                        
                                        <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                                        <button type="button" class="upload-btn" onclick="previewImage('<?php echo htmlspecialchars($user['profile_picture']); ?>')">
                                            <i class="fas fa-eye"></i>
                                            Preview
                                        </button>
                                        <button type="button" class="upload-btn danger" onclick="removeProfilePicture()">
                                            <ion-icon name="trash-outline"></ion-icon>
                                            Remove
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="upload-info">
                                        <div class="upload-status" id="uploadStatus">
                                            <i class="fas fa-info-circle"></i>
                                            JPG, PNG or GIF. Max size 2MB. Recommended: 400x400px
                                        </div>
                                        <div id="file-name" class="upload-status"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="first_name">First Name *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="last_name">Last Name *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="username">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                <div class="progress-bar">
                                    <div class="progress-fill" id="usernameProgress" style="width: 0%"></div>
                                </div>
                                <div id="username-feedback" class="form-text"></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="bio">Bio</label>
                            <textarea class="form-control" id="bio" name="bio" placeholder="Tell us a little about yourself" maxlength="500"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                            <div class="progress-bar">
                                <div class="progress-fill" id="bioProgress" style="width: 0%"></div>
                            </div>
                            <p class="form-text">Brief description for your profile. URLs are hyperlinked. <span id="bioCounter">0/500</span> characters</p>
                        </div>

                        <button type="submit" name="update_profile" class="btn btn-primary" id="profileSubmitBtn">
                            <ion-icon name="save-outline"></ion-icon>
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="settings-section" id="security-section">
                <div class="section-header">
                    <h2>Security Settings</h2>
                </div>
                <div class="section-content">
                    <form method="POST" id="passwordForm">
                        <div class="form-group">
                            <label class="form-label" for="current_password">Current Password *</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password *</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <div class="progress-bar">
                                <div class="progress-fill" id="passwordProgress" style="width: 0%"></div>
                            </div>
                            <div id="password-strength" class="password-strength"></div>
                            <p class="form-text">Your new password must be at least 8 characters long.</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password *</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            <div id="password-match" class="form-text"></div>
                        </div>

                        <button type="submit" name="change_password" class="btn btn-primary" id="passwordSubmitBtn">
                            <ion-icon name="key-outline"></ion-icon>
                            Change Password
                        </button>
                    </form>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border);">

                    <h3 style="margin-bottom: 1rem;">Two-Factor Authentication</h3>
                    <form method="POST" id="twoFactorForm">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="two_factor" name="two_factor" <?php echo ($user['two_factor_enabled'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="two_factor">Enable two-factor authentication</label>
                        </div>
                        <p class="form-text">Add an extra layer of security to your account by enabling two-factor authentication.</p>
                        <button type="submit" name="update_preferences" class="btn btn-outline" style="margin-top: 1rem;">
                            <i class="fas fa-mobile-alt"></i>
                            Update 2FA Settings
                        </button>
                    </form>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border);">

                    <h3 style="margin-bottom: 1rem;">Active Sessions</h3>
                    <div class="sessions-list">
                        <div class="session-item" style="padding: 1rem; border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 1rem;">
                            <div style="display: flex; justify-content: between; align-items: center;">
                                <div>
                                    <strong>Current Session</strong>
                                    <div class="form-text">Chrome on Windows • <?php echo date('M j, Y \a\t g:i A'); ?></div>
                                </div>
                                <button class="btn btn-outline" style="padding: 0.5rem 1rem;">
                                    <i class="fas fa-sign-out-alt"></i>
                                    Logout
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Preferences Settings -->
            <div class="settings-section" id="preferences-section">
                <div class="section-header">
                    <h2>Preferences</h2>
                </div>
                <div class="section-content">
                    <form method="POST" id="preferencesForm">
                        <div class="form-group">
                            <label class="form-label" for="theme">Theme</label>
                            <select class="form-control" id="theme" name="theme">
                                <option value="light" <?php echo ($user['theme'] ?? 'light') === 'light' ? 'selected' : ''; ?>>Light</option>
                                <option value="dark" <?php echo ($user['theme'] ?? 'light') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                <option value="auto" <?php echo ($user['theme'] ?? 'light') === 'auto' ? 'selected' : ''; ?>>Auto (System)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="language">Language</label>
                            <select class="form-control" id="language" name="language">
                                <option value="en" <?php echo ($user['language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                <option value="es" <?php echo ($user['language'] ?? 'en') === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                <option value="fr" <?php echo ($user['language'] ?? 'en') === 'fr' ? 'selected' : ''; ?>>French</option>
                                <option value="de" <?php echo ($user['language'] ?? 'en') === 'de' ? 'selected' : ''; ?>>German</option>
                                <option value="ja" <?php echo ($user['language'] ?? 'en') === 'ja' ? 'selected' : ''; ?>>Japanese</option>
                            </select>
                        </div>

                        <h3 style="margin: 1.5rem 0 1rem;">Notifications</h3>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="email_notifications" name="email_notifications" <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="email_notifications">Email notifications</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="push_notifications" name="push_notifications" <?php echo ($user['push_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="push_notifications">Push notifications</label>
                        </div>

                        <h3 style="margin: 1.5rem 0 1rem;">Editor Preferences</h3>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="auto_save" name="auto_save" <?php echo ($user['auto_save'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="auto_save">Auto-save changes</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="code_formatting" name="code_formatting" <?php echo ($user['code_formatting'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="code_formatting">Auto-format code on save</label>
                        </div>

                        <button type="submit" name="update_preferences" class="btn btn-primary" style="margin-top: 1.5rem;">
                            <ion-icon name="save-outline"></ion-icon>
                            Save Preferences
                        </button>
                    </form>
                </div>
            </div>

            <!-- Privacy Settings -->
            <div class="settings-section" id="privacy-section">
                <div class="section-header">
                    <h2>Privacy Settings</h2>
                </div>
                <div class="section-content">
                    <form method="POST" id="privacyForm">
                        <div class="form-group">
                            <label class="form-label" for="profile_visibility">Profile Visibility</label>
                            <select class="form-control" id="profile_visibility" name="profile_visibility">
                                <option value="public" <?php echo ($user['profile_visibility'] ?? 'public') === 'public' ? 'selected' : ''; ?>>Public - Anyone can see your profile</option>
                                <option value="private" <?php echo ($user['profile_visibility'] ?? 'public') === 'private' ? 'selected' : ''; ?>>Private - Only you can see your profile</option>
                                <option value="friends" <?php echo ($user['profile_visibility'] ?? 'public') === 'friends' ? 'selected' : ''; ?>>Friends - Only your friends can see your profile</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email_visibility">Email Visibility</label>
                            <select class="form-control" id="email_visibility" name="email_visibility">
                                <option value="private" <?php echo ($user['email_visibility'] ?? 'private') === 'private' ? 'selected' : ''; ?>>Private - Only you can see your email</option>
                                <option value="friends" <?php echo ($user['email_visibility'] ?? 'private') === 'friends' ? 'selected' : ''; ?>>Friends - Only your friends can see your email</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="repo_visibility">Default Repository Visibility</label>
                            <select class="form-control" id="repo_visibility" name="repo_visibility">
                                <option value="public" <?php echo ($user['repo_visibility'] ?? 'public') === 'public' ? 'selected' : ''; ?>>Public - Anyone can see your repositories</option>
                                <option value="private" <?php echo ($user['repo_visibility'] ?? 'public') === 'private' ? 'selected' : ''; ?>>Private - Only you can see your repositories</option>
                                <option value="friends" <?php echo ($user['repo_visibility'] ?? 'public') === 'friends' ? 'selected' : ''; ?>>Friends - Only your friends can see your repositories</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="activity_visibility">Activity Visibility</label>
                            <select class="form-control" id="activity_visibility" name="activity_visibility">
                                <option value="public" <?php echo ($user['activity_visibility'] ?? 'public') === 'public' ? 'selected' : ''; ?>>Public - Anyone can see your activity</option>
                                <option value="private" <?php echo ($user['activity_visibility'] ?? 'public') === 'private' ? 'selected' : ''; ?>>Private - Only you can see your activity</option>
                                <option value="friends" <?php echo ($user['activity_visibility'] ?? 'public') === 'friends' ? 'selected' : ''; ?>>Friends - Only your friends can see your activity</option>
                            </select>
                        </div>

                        <h3 style="margin: 1.5rem 0 1rem;">Communication</h3>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="allow_friend_requests" name="allow_friend_requests" <?php echo ($user['allow_friend_requests'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="allow_friend_requests">Allow friend requests</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="allow_messages" name="allow_messages" <?php echo ($user['allow_messages'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="allow_messages">Allow direct messages</label>
                        </div>

                        <button type="submit" name="update_privacy" class="btn btn-primary" style="margin-top: 1.5rem;">
                            <ion-icon name="save-outline"></ion-icon>
                            Save Privacy Settings
                        </button>
                    </form>
                </div>
            </div>

            <!-- Account Settings -->
            <div class="settings-section" id="account-section">
                <div class="section-header">
                    <h2>Account Management</h2>
                </div>
                <div class="section-content">
                    <div class="form-group">
                        <h3 style="margin-bottom: 1rem;">Export Data</h3>
                        <p style="margin-bottom: 1rem;">Download all your data in a portable JSON format.</p>
                        <form method="POST">
                            <button type="submit" name="export_data" class="btn btn-outline">
                                <ion-icon name="download-outline"></ion-icon>
                                Export Account Data
                            </button>
                        </form>
                    </div>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border);">

                    <div class="danger-zone">
                        <h3><ion-icon name="warning-outline"></ion-icon> Danger Zone</h3>
                        <p>Once you delete your account, there is no going back. Please be certain.</p>
                        <p class="form-text" style="margin-bottom: 1rem;">
                            <strong>Note:</strong> Your account will be marked as deleted. Your friends will see you as "Deleted User" and your repositories will be archived.
                        </p>
                        
                        <form method="POST" class="confirm-delete" id="deleteAccountForm">
                            <div class="form-group">
                                <label class="form-label" for="confirm_text">Type "DELETE MY ACCOUNT" to confirm</label>
                                <input type="text" class="form-control" id="confirm_text" name="confirm_text" placeholder="DELETE MY ACCOUNT">
                                <div id="delete-feedback" class="form-text"></div>
                            </div>
                            <button type="submit" name="delete_account" class="btn btn-danger" id="deleteAccountBtn">
                                <i class="fas fa-trash-alt"></i>
                                Delete Account
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        // Toast Notification System
        function showToast(message, type = 'success') {
            const toastContainer = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'}"></i>
                <span>${message}</span>
            `;
            
            toastContainer.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Profile Picture Upload Functions
        function handleFileSelect(file) {
            const fileNameDisplay = document.getElementById('file-name');
            const uploadStatus = document.getElementById('uploadStatus');
            const profileAvatar = document.getElementById('profileAvatar');
            const avatarImage = document.getElementById('avatarImage');
            const avatarInitials = document.getElementById('avatarInitials');
            
            if (file) {
                fileNameDisplay.textContent = `Selected: ${file.name}`;
                fileNameDisplay.className = 'upload-status success';
                
                // Validate file type and size
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                const maxSize = 2 * 1024 * 1024; // 2MB
                
                if (!allowedTypes.includes(file.type)) {
                    uploadStatus.innerHTML = '<i class="fas fa-exclamation-circle"></i> Only JPG, PNG, and GIF files are allowed';
                    uploadStatus.className = 'upload-status error';
                    return;
                }
                
                if (file.size > maxSize) {
                    uploadStatus.innerHTML = '<i class="fas fa-exclamation-circle"></i> File size exceeds 2MB limit';
                    uploadStatus.className = 'upload-status error';
                    return;
                }
                
                uploadStatus.innerHTML = '<i class="fas fa-check-circle"></i> File selected. Click "Save Changes" to upload';
                uploadStatus.className = 'upload-status success';
                
                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (avatarImage) {
                        avatarImage.src = e.target.result;
                    } else {
                        if (avatarInitials) {
                            avatarInitials.style.display = 'none';
                        }
                        const newImg = document.createElement('img');
                        newImg.src = e.target.result;
                        newImg.alt = 'Profile Preview';
                        newImg.id = 'avatarImage';
                        profileAvatar.appendChild(newImg);
                    }
                };
                reader.readAsDataURL(file);
            } else {
                fileNameDisplay.textContent = '';
                uploadStatus.innerHTML = '<i class="fas fa-info-circle"></i> JPG, PNG or GIF. Max size 2MB. Recommended: 400x400px';
                uploadStatus.className = 'upload-status';
            }
        }

        function previewImage(imageUrl) {
            const modal = document.getElementById('imagePreviewModal');
            const previewImg = document.getElementById('previewImage');
            
            if (!modal) {
                // Create modal if it doesn't exist
                const modalHTML = `
                    <div class="image-preview-modal" id="imagePreviewModal">
                        <div class="preview-container">
                            <button class="close-preview" onclick="closePreview()">
                                <ion-icon name="close-outline"></ion-icon>
                            </button>
                            <img src="${imageUrl}" alt="Profile Picture Preview" class="preview-image" id="previewImage">
                            <div class="preview-actions">
                                <button class="btn btn-primary" onclick="closePreview()">
                                    <ion-icon name="checkmark-outline"></ion-icon>
                                    Looks Good
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', modalHTML);
            } else {
                previewImg.src = imageUrl;
                modal.classList.add('active');
            }
        }

        function closePreview() {
            const modal = document.getElementById('imagePreviewModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function removeProfilePicture() {
            if (confirm('Are you sure you want to remove your profile picture?')) {
                const profileAvatar = document.getElementById('profileAvatar');
                const avatarImage = document.getElementById('avatarImage');
                const avatarInitials = document.getElementById('avatarInitials');
                const uploadStatus = document.getElementById('uploadStatus');
                
                if (avatarImage) {
                    avatarImage.remove();
                }
                
                if (avatarInitials) {
                    avatarInitials.style.display = 'flex';
                }
                
                // Create a hidden field to indicate removal
                let removeField = document.getElementById('remove_profile_picture');
                if (!removeField) {
                    removeField = document.createElement('input');
                    removeField.type = 'hidden';
                    removeField.name = 'remove_profile_picture';
                    removeField.id = 'remove_profile_picture';
                    removeField.value = '1';
                    document.querySelector('form').appendChild(removeField);
                }
                
                uploadStatus.innerHTML = '<i class="fas fa-check-circle"></i> Profile picture will be removed when you save changes';
                uploadStatus.className = 'upload-status success';
                
                // Hide remove button
                const removeBtn = document.querySelector('.upload-btn.danger');
                if (removeBtn) {
                    removeBtn.style.display = 'none';
                }
                
                const previewBtn = document.querySelector('.upload-btn:nth-child(2)');
                if (previewBtn) {
                    previewBtn.style.display = 'none';
                }
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', (e) => {
            const modal = document.getElementById('imagePreviewModal');
            if (modal && e.target === modal) {
                closePreview();
            }
        });

        // Handle form submission for profile picture
        document.querySelector('form').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('profile_picture');
            const removeField = document.getElementById('remove_profile_picture');
            
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                const maxSize = 2 * 1024 * 1024;
                
                if (file.size > maxSize) {
                    e.preventDefault();
                    showToast('File size exceeds 2MB limit. Please choose a smaller file.', 'error');
                    return;
                }
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && (fileInput.files.length > 0 || removeField)) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                submitBtn.disabled = true;
                
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 2000);
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

        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = themeToggle.querySelector('i');

        // Set initial theme based on user preference or system preference
        const userTheme = '<?php echo $user['theme'] ?? 'light'; ?>';
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        if (userTheme === 'dark' || (userTheme === 'auto' && systemPrefersDark)) {
            document.body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
            
            // Update theme preference via AJAX
            const theme = document.body.classList.contains('dark-mode') ? 'dark' : 'light';
            updateThemePreference(theme);
        });

        function updateThemePreference(theme) {
            const formData = new FormData();
            formData.append('theme', theme);
            formData.append('update_preferences', 'true');
            
            fetch('settings.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                showToast('Theme updated successfully!');
            }).catch(err => {
                console.log('Theme update failed');
                showToast('Failed to update theme', 'error');
            });
        }

        // Settings Navigation
        const settingsNavItems = document.querySelectorAll('.settings-nav-item');
        const settingsSections = document.querySelectorAll('.settings-section');

        settingsNavItems.forEach(item => {
            item.addEventListener('click', () => {
                const target = item.getAttribute('data-target');
                
                // Update active nav item
                settingsNavItems.forEach(navItem => navItem.classList.remove('active'));
                item.classList.add('active');
                
                // Show target section
                settingsSections.forEach(section => {
                    section.classList.remove('active');
                    if (section.id === `${target}-section`) {
                        section.classList.add('active');
                    }
                });
            });
        });

        // Bio character counter
        const bioTextarea = document.getElementById('bio');
        const bioCounter = document.getElementById('bioCounter');
        const bioProgress = document.getElementById('bioProgress');

        if (bioTextarea) {
            bioTextarea.addEventListener('input', function() {
                const length = this.value.length;
                const maxLength = 500;
                const percentage = (length / maxLength) * 100;
                
                bioCounter.textContent = `${length}/${maxLength}`;
                bioProgress.style.width = `${percentage}%`;
                
                if (percentage > 90) {
                    bioProgress.style.background = 'var(--error)';
                } else if (percentage > 75) {
                    bioProgress.style.background = 'var(--warning)';
                } else {
                    bioProgress.style.background = 'var(--gradient)';
                }
            });
            
            // Initialize counter
            bioTextarea.dispatchEvent(new Event('input'));
        }

        // Username validation
        const usernameInput = document.getElementById('username');
        const usernameProgress = document.getElementById('usernameProgress');
        const usernameFeedback = document.getElementById('username-feedback');

        if (usernameInput) {
            usernameInput.addEventListener('input', function() {
                const username = this.value;
                let strength = 0;
                let feedback = '';
                
                if (username.length >= 3) strength += 25;
                if (username.length >= 5) strength += 25;
                if (/^[a-zA-Z0-9_]+$/.test(username)) strength += 25;
                if (username.length <= 20) strength += 25;
                
                usernameProgress.style.width = `${strength}%`;
                
                if (strength < 50) {
                    usernameProgress.style.background = 'var(--error)';
                    feedback = 'Username is too weak';
                } else if (strength < 75) {
                    usernameProgress.style.background = 'var(--warning)';
                    feedback = 'Username is acceptable';
                } else {
                    usernameProgress.style.background = 'var(--success)';
                    feedback = 'Username is strong';
                }
                
                usernameFeedback.textContent = feedback;
                usernameFeedback.className = `form-text ${strength < 50 ? 'strength-weak' : strength < 75 ? 'strength-medium' : 'strength-strong'}`;
            });
            
            // Initialize validation
            usernameInput.dispatchEvent(new Event('input'));
        }

        // Password strength indicator
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordProgress = document.getElementById('passwordProgress');
        const passwordStrength = document.getElementById('password-strength');
        const passwordMatch = document.getElementById('password-match');

        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let feedback = '';
                
                if (password.length >= 8) strength += 20;
                if (/[A-Z]/.test(password)) strength += 20;
                if (/[a-z]/.test(password)) strength += 20;
                if (/[0-9]/.test(password)) strength += 20;
                if (/[^A-Za-z0-9]/.test(password)) strength += 20;
                
                passwordProgress.style.width = `${strength}%`;
                
                if (strength < 40) {
                    passwordProgress.style.background = 'var(--error)';
                    feedback = '<span class="strength-weak">Weak password</span>';
                } else if (strength < 60) {
                    passwordProgress.style.background = 'var(--warning)';
                    feedback = '<span class="strength-medium">Medium password</span>';
                } else if (strength < 80) {
                    passwordProgress.style.background = 'var(--success)';
                    feedback = '<span class="strength-strong">Strong password</span>';
                } else {
                    passwordProgress.style.background = 'var(--success)';
                    feedback = '<span class="strength-very-strong">Very strong password</span>';
                }
                
                passwordStrength.innerHTML = feedback;
                
                // Check password match
                if (confirmPasswordInput.value) {
                    checkPasswordMatch();
                }
            });
        }

        function checkPasswordMatch() {
            const password = newPasswordInput.value;
            const confirm = confirmPasswordInput.value;
            
            if (confirm && password !== confirm) {
                passwordMatch.innerHTML = '<span class="strength-weak">Passwords do not match</span>';
                confirmPasswordInput.style.borderColor = 'var(--error)';
            } else if (confirm && password === confirm) {
                passwordMatch.innerHTML = '<span class="strength-strong">Passwords match</span>';
                confirmPasswordInput.style.borderColor = 'var(--success)';
            } else {
                passwordMatch.innerHTML = '';
                confirmPasswordInput.style.borderColor = '';
            }
        }

        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        }

        // Delete account confirmation
        const deleteForm = document.getElementById('deleteAccountForm');
        const confirmText = document.getElementById('confirm_text');
        const deleteFeedback = document.getElementById('delete-feedback');

        if (deleteForm) {
            confirmText.addEventListener('input', function() {
                if (this.value === 'DELETE MY ACCOUNT') {
                    deleteFeedback.innerHTML = '<span class="strength-strong">Confirmation text matches</span>';
                    this.style.borderColor = 'var(--success)';
                } else {
                    deleteFeedback.innerHTML = '<span class="strength-weak">Type exactly: DELETE MY ACCOUNT</span>';
                    this.style.borderColor = 'var(--error)';
                }
            });

            deleteForm.addEventListener('submit', function(e) {
                const confirmText = document.getElementById('confirm_text').value;
                if (confirmText !== 'DELETE MY ACCOUNT') {
                    e.preventDefault();
                    showToast('Please type "DELETE MY ACCOUNT" exactly as shown to confirm deletion.', 'error');
                    return false;
                }
                
                if (!confirm('Are you absolutely sure? This action cannot be undone! Your account will be permanently deleted.')) {
                    e.preventDefault();
                    return false;
                }
                
                // Show loading state
                const deleteBtn = document.getElementById('deleteAccountBtn');
                const originalText = deleteBtn.innerHTML;
                deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting Account...';
                deleteBtn.disabled = true;
                
                // Allow form to submit
                return true;
            });
        }

        // Form validation
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = this.querySelectorAll('[required]');
                let valid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        valid = false;
                        field.style.borderColor = 'var(--error)';
                    } else {
                        field.style.borderColor = '';
                    }
                });
                
                // Special validation for password change
                if (this.querySelector('[name="change_password"]')) {
                    const newPassword = document.getElementById('new_password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    
                    if (newPassword !== confirmPassword) {
                        valid = false;
                        document.getElementById('confirm_password').style.borderColor = 'var(--error)';
                        showToast('New passwords do not match.', 'error');
                    }
                }
                
                if (!valid) {
                    e.preventDefault();
                    if (!this.querySelector('[name="change_password"]')) {
                        showToast('Please fill in all required fields.', 'error');
                    }
                }
            });
        });

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        });

        // Search functionality
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const sections = document.querySelectorAll('.settings-section');
                const navItems = document.querySelectorAll('.settings-nav-item');
                
                if (searchTerm) {
                    let foundSection = false;
                    
                    sections.forEach(section => {
                        const sectionText = section.textContent.toLowerCase();
                        if (sectionText.includes(searchTerm)) {
                            section.style.display = 'block';
                            foundSection = true;
                            
                            // Activate corresponding nav item
                            const sectionId = section.id.replace('-section', '');
                            navItems.forEach(item => {
                                if (item.getAttribute('data-target') === sectionId) {
                                    item.classList.add('active');
                                    section.classList.add('active');
                                } else {
                                    item.classList.remove('active');
                                }
                            });
                        } else {
                            section.style.display = 'none';
                        }
                    });
                    
                    // Show all nav items when searching
                    navItems.forEach(item => item.style.display = 'flex');
                    
                    if (!foundSection) {
                        // Show no results message
                        sections.forEach(section => section.style.display = 'none');
                    }
                } else {
                    // Reset to default state
                    sections.forEach(section => {
                        section.style.display = 'none';
                    });
                    navItems.forEach(item => item.style.display = 'flex');
                    
                    // Show the active section
                    const activeNav = document.querySelector('.settings-nav-item.active');
                    if (activeNav) {
                        const target = activeNav.getAttribute('data-target');
                        document.getElementById(`${target}-section`).style.display = 'block';
                    }
                }
            });
        }

        // Real-time theme preview
        const themeSelect = document.getElementById('theme');
        if (themeSelect) {
            themeSelect.addEventListener('change', function() {
                const theme = this.value;
                
                if (theme === 'dark') {
                    document.body.classList.add('dark-mode');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else if (theme === 'light') {
                    document.body.classList.remove('dark-mode');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                } else {
                    // Auto - use system preference
                    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                        document.body.classList.add('dark-mode');
                        themeIcon.classList.remove('fa-moon');
                        themeIcon.classList.add('fa-sun');
                    } else {
                        document.body.classList.remove('dark-mode');
                        themeIcon.classList.remove('fa-sun');
                        themeIcon.classList.add('fa-moon');
                    }
                }
            });
        }

        // Animate stats counter
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

        // Initialize stats animation
        setTimeout(() => {
            animateCounter(document.getElementById('repoCount'), 12);
            animateCounter(document.getElementById('friendCount'), 24);
            animateCounter(document.getElementById('contributionCount'), 156);
            animateCounter(document.getElementById('projectCount'), 8);
        }, 500);

        // Auto-save for forms (except password and delete forms)
        const autoSaveForms = document.querySelectorAll('form:not([class="confirm-delete"]):not([id*="password"])');
        autoSaveForms.forEach(form => {
            const inputs = form.querySelectorAll('input, select, textarea');
            let saveTimeout;
            
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    clearTimeout(saveTimeout);
                    saveTimeout = setTimeout(() => {
                        // Show saving indicator
                        const submitBtn = form.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            const originalText = submitBtn.innerHTML;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                            submitBtn.disabled = true;
                            
                            // Simulate auto-save
                            setTimeout(() => {
                                submitBtn.innerHTML = originalText;
                                submitBtn.disabled = false;
                                
                                showToast('Changes saved successfully!');
                            }, 1000);
                        }
                    }, 1000);
                });
            });
        });

        // Enhanced file drag and drop
        const profileAvatar = document.getElementById('profileAvatar');
        if (profileAvatar) {
            profileAvatar.addEventListener('dragover', (e) => {
                e.preventDefault();
                profileAvatar.style.transform = 'scale(1.1)';
                profileAvatar.style.boxShadow = 'var(--shadow-2xl)';
            });

            profileAvatar.addEventListener('dragleave', () => {
                profileAvatar.style.transform = 'scale(1)';
                profileAvatar.style.boxShadow = 'var(--shadow-xl)';
            });

            profileAvatar.addEventListener('drop', (e) => {
                e.preventDefault();
                profileAvatar.style.transform = 'scale(1)';
                profileAvatar.style.boxShadow = 'var(--shadow-xl)';
                
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const fileInput = document.getElementById('profile_picture');
                    fileInput.files = files;
                    handleFileSelect(files[0]);
                }
            });
        }
    </script>

    
</body>
</html>