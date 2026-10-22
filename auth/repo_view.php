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

$repoId = $_GET['repo'] ?? '';
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
$repoDetails = null;
$userRole = '';
$userPermission = '';

$tableCheck = $reposConn->query("SHOW TABLES LIKE '$tableName'");
if ($tableCheck->num_rows > 0) {
    $accessStmt = $reposConn->prepare("SELECT * FROM `$tableName` WHERE repo_id = ?");
    $accessStmt->bind_param("s", $repoId);
    $accessStmt->execute();
    $repoResult = $accessStmt->get_result();
    
    if ($repoResult->num_rows > 0) {
        $hasAccess = true;
        $repoDetails = $repoResult->fetch_assoc();
        $userRole = $repoDetails['role'] ?? 'collaborator';
        $userPermission = $repoDetails['permission_level'] ?? 'write';
    }
}

if (!$hasAccess) {
    header('Location: dashboard.php');
    exit();
}

// Get repository data
$repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
if ($repoConn->connect_error) {
    die("Repository not found or access denied");
}

// Handle file/folder operations
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_file'])) {
        $fileName = trim($_POST['file_name']);
        $fileType = $_POST['file_type'];
        $folder = $_POST['folder'] ?? 'root';
        
        // Auto-detect file type if not specified
        if (empty($fileType) && !empty($fileName)) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $fileType = $extension ?: 'txt';
        }
        
        if (!empty($fileName)) {
            // ✅ CHECK FOR DUPLICATE FILE NAME IN THE SAME FOLDER
            $checkDuplicateStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_name = ? AND file_type != 'folder'");
            $checkDuplicateStmt->bind_param("ss", $folder, $fileName);
            $checkDuplicateStmt->execute();
            $duplicateResult = $checkDuplicateStmt->get_result()->fetch_assoc();
            
            if ($duplicateResult['count'] > 0) {
                $error = "A file with name '$fileName' already exists in this folder!";
            } else {
                // Create file table
                $fileTableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $fileName);
                $createFileTable = "CREATE TABLE IF NOT EXISTS `$fileTableName` (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    content LONGTEXT,
                    version INT DEFAULT 1,
                    created_by VARCHAR(50),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    last_modified_by VARCHAR(50)
                )";
                
                if ($repoConn->query($createFileTable)) {
                    // Insert file record
                    $insertFile = $repoConn->prepare("INSERT INTO files (folder, file_name, file_type, created_by) VALUES (?, ?, ?, ?)");
                    $insertFile->bind_param("ssss", $folder, $fileName, $fileType, $_SESSION['user_id']);
                    
                    if ($insertFile->execute()) {
                        // ✅ ADD COMMIT FOR FILE CREATION (UPDATED STRUCTURE)
                        $commitHash = bin2hex(random_bytes(16));
                        $commitMessage = "Created file: $fileName" . ($folder !== 'root' ? " in $folder" : "");
                        $authorName = $user['first_name'] . ' ' . $user['last_name'];
                        
                        $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                        $changes = json_encode(['action' => 'create', 'file' => $fileName, 'folder' => $folder]);
                        $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                        $commitStmt->execute();
                        
                        $message = "File '$fileName' created successfully!";
                    } else {
                        $error = "Failed to create file record: " . $insertFile->error;
                    }
                } else {
                    $error = "Failed to create file table: " . $repoConn->error;
                }
            }
            $checkDuplicateStmt->close();
        }
    }
    
    if (isset($_POST['create_folder'])) {
        $folderName = trim($_POST['folder_name']);
        $parentFolder = $_POST['parent_folder'] ?? 'root';
        
        if (!empty($folderName)) {
            $newFolder = $parentFolder === 'root' ? $folderName : $parentFolder . '/' . $folderName;
            
            // ✅ CHECK FOR DUPLICATE FOLDER NAME IN THE SAME PARENT FOLDER
            $checkDuplicateStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_name = '.folder' AND file_type = 'folder'");
            $checkDuplicateStmt->bind_param("s", $newFolder);
            $checkDuplicateStmt->execute();
            $duplicateResult = $checkDuplicateStmt->get_result()->fetch_assoc();
            
            if ($duplicateResult['count'] > 0) {
                $error = "A folder with name '$folderName' already exists in this location!";
            } else {
                // Check if folder already exists (additional check)
                $checkFolder = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ?");
                $checkFolder->bind_param("s", $newFolder);
                $checkFolder->execute();
                $folderResult = $checkFolder->get_result()->fetch_assoc();
                
                if ($folderResult['count'] == 0) {
                    // Create a placeholder file to represent the folder
                    $insertFolder = $repoConn->prepare("INSERT INTO files (folder, file_name, file_type, created_by) VALUES (?, '.folder', 'folder', ?)");
                    $insertFolder->bind_param("ss", $newFolder, $_SESSION['user_id']);
                    
                    if ($insertFolder->execute()) {
                        // ✅ ADD COMMIT FOR FOLDER CREATION (UPDATED STRUCTURE)
                        $commitHash = bin2hex(random_bytes(16));
                        $commitMessage = "Created folder: $folderName" . ($parentFolder !== 'root' ? " in $parentFolder" : "");
                        $authorName = $user['first_name'] . ' ' . $user['last_name'];
                        
                        $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                        $changes = json_encode(['action' => 'create_folder', 'folder' => $folderName, 'path' => $newFolder]);
                        $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                        $commitStmt->execute();
                        
                        $message = "Folder '$folderName' created successfully!";
                    } else {
                        $error = "Failed to create folder: " . $insertFolder->error;
                    }
                } else {
                    $error = "Folder already exists!";
                }
                $checkFolder->close();
            }
            $checkDuplicateStmt->close();
        }
    }
    
    if (isset($_POST['add_collaborator'])) {
        $collaboratorId = trim($_POST['collaborator_id']);
        $permission = $_POST['permission_level'] ?? 'write';
        
        if (!empty($collaboratorId)) {
            // Check if user exists
            $checkUser = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE userId = ?");
            $checkUser->bind_param("s", $collaboratorId);
            $checkUser->execute();
            $userResult = $checkUser->get_result();
            
            if ($userResult->num_rows === 1) {
                $collaborator = $userResult->fetch_assoc();
                
                // Add to repository collaborators table
                $addCollabStmt = $repoConn->prepare("INSERT INTO collaborators (user_id, username, permission_level, added_by, status) VALUES (?, ?, ?, ?, 'active')");
                $addCollabStmt->bind_param("ssss", $collaboratorId, $collaborator['username'], $permission, $_SESSION['user_id']);
                
                if ($addCollabStmt->execute()) {
                    // ✅ ADD COMMIT FOR COLLABORATOR ADDITION
                    $commitHash = bin2hex(random_bytes(16));
                    $commitMessage = "Added collaborator: {$collaborator['first_name']} {$collaborator['last_name']}";
                    $authorName = $user['first_name'] . ' ' . $user['last_name'];
                    
                    $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                    $changes = json_encode(['action' => 'add_collaborator', 'collaborator' => $collaborator['username'], 'collaborator_name' => $collaborator['first_name'] . ' ' . $collaborator['last_name']]);
                    $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                    $commitStmt->execute();
                    
                    // Send collaboration invite
                    $notifConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME_NOTIFICATIONS);
                    $notifTableName = $collaboratorId;
                    
                    $messageText = "{$user['first_name']} {$user['last_name']} invited you to collaborate on repository '$repoId'";
                    $link = "collaboration_response.php?repo=$repoId&inviter=" . $_SESSION['user_id'];
                    $type = "collaboration_invite";
                    
                    $stmt = $notifConn->prepare("INSERT INTO `$notifTableName` (message, link, type) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $messageText, $link, $type);
                    
                    if ($stmt->execute()) {
                        $message = "Collaboration invitation sent to {$collaborator['first_name']} {$collaborator['last_name']}!";
                    } else {
                        $error = "Failed to send invitation: " . $stmt->error;
                    }
                    
                    $notifConn->close();
                } else {
                    $error = "Failed to add collaborator: " . $addCollabStmt->error;
                }
            } else {
                $error = "User not found!";
            }
        }
    }
    
    if (isset($_POST['delete_file'])) {
        $fileId = $_POST['file_id'];
        
        // Get file details before deletion for commit message
        $fileStmt = $repoConn->prepare("SELECT file_name, folder FROM files WHERE id = ?");
        $fileStmt->bind_param("i", $fileId);
        $fileStmt->execute();
        $fileResult = $fileStmt->get_result()->fetch_assoc();
        $fileName = $fileResult['file_name'] ?? '';
        $fileFolder = $fileResult['folder'] ?? '';
        
        $deleteStmt = $repoConn->prepare("DELETE FROM files WHERE id = ?");
        $deleteStmt->bind_param("i", $fileId);
        
        if ($deleteStmt->execute()) {
            // ✅ ADD COMMIT FOR FILE DELETION (UPDATED STRUCTURE)
            $commitHash = bin2hex(random_bytes(16));
            $commitMessage = "Deleted file: $fileName" . ($fileFolder !== 'root' ? " from $fileFolder" : "");
            $authorName = $user['first_name'] . ' ' . $user['last_name'];
            
            $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
            $changes = json_encode(['action' => 'delete', 'file' => $fileName, 'folder' => $fileFolder]);
            $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
            $commitStmt->execute();
            
            $message = "File '$fileName' deleted successfully!";
        } else {
            $error = "Failed to delete file: " . $deleteStmt->error;
        }
    }
    
    if (isset($_POST['delete_folder'])) {
        $folderPath = $_POST['folder_path'];
        
        // Get folder name for commit message
        $folderName = basename($folderPath);
        
        // Delete folder and all its contents
        $deleteStmt = $repoConn->prepare("DELETE FROM files WHERE folder = ? OR folder LIKE ?");
        $likePattern = $folderPath . '/%';
        $deleteStmt->bind_param("ss", $folderPath, $likePattern);
        
        if ($deleteStmt->execute()) {
            // ✅ ADD COMMIT FOR FOLDER DELETION (UPDATED STRUCTURE)
            $commitHash = bin2hex(random_bytes(16));
            $commitMessage = "Deleted folder: $folderName and its contents";
            $authorName = $user['first_name'] . ' ' . $user['last_name'];
            
            $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
            $changes = json_encode(['action' => 'delete_folder', 'folder' => $folderName, 'path' => $folderPath]);
            $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
            $commitStmt->execute();
            
            $message = "Folder '$folderName' and its contents deleted successfully!";
        } else {
            $error = "Failed to delete folder: " . $deleteStmt->error;
        }
    }
    
    if (isset($_POST['update_settings'])) {
    $repoName = $_POST['repo_name'];
    $description = $_POST['description'];
    
    // Get old settings for comparison - FIXED: Only existing columns
    $oldSettingsStmt = $reposConn->prepare("SELECT repo_name, description FROM `$tableName` WHERE repo_id = ?");
    $oldSettingsStmt->bind_param("s", $repoId);
    $oldSettingsStmt->execute();
    $oldSettings = $oldSettingsStmt->get_result()->fetch_assoc();
    
    // Update repository settings - FIXED: Only update existing columns
    $updateStmt = $reposConn->prepare("UPDATE `$tableName` SET repo_name = ?, description = ? WHERE repo_id = ?");
    $updateStmt->bind_param("sss", $repoName, $description, $repoId);
    
    if ($updateStmt->execute()) {
        // ✅ ADD COMMIT FOR SETTINGS UPDATE (UPDATED STRUCTURE)
        $commitHash = bin2hex(random_bytes(16));
        $commitMessage = "Updated repository settings";
        $authorName = $user['first_name'] . ' ' . $user['last_name'];
        
        $changes = [];
        if (isset($oldSettings['repo_name']) && $oldSettings['repo_name'] !== $repoName) {
            $changes['rename'] = ['from' => $oldSettings['repo_name'], 'to' => $repoName];
        }
        
        $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
        $changesJson = json_encode($changes);
        $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changesJson);
        $commitStmt->execute();
        
        $message = "Repository settings updated successfully!";
    } else {
        $error = "Failed to update repository settings: " . $updateStmt->error;
    }
    }
    
    if (isset($_POST['generate_share_link'])) {
        $sharePermission = $_POST['share_permission'];
        $expiry = $_POST['expiry'];
        
        // Calculate expiry date
        $expiryDate = null;
        switch ($expiry) {
            case '1day':
                $expiryDate = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '7days':
                $expiryDate = date('Y-m-d H:i:s', strtotime('+7 days'));
                break;
            case '30days':
                $expiryDate = date('Y-m-d H:i:s', strtotime('+30 days'));
                break;
            case 'never':
            default:
                $expiryDate = null;
                break;
        }
        
        // Generate unique share token
        $shareToken = bin2hex(random_bytes(16));
        
        // Store share link in database with repo_id
        $shareStmt = $repoConn->prepare("INSERT INTO share_links (token, permission, expiry, created_by, repo_id) VALUES (?, ?, ?, ?, ?)");
        $shareStmt->bind_param("sssss", $shareToken, $sharePermission, $expiryDate, $_SESSION['user_id'], $repoId);
        
        if ($shareStmt->execute()) {
            // ✅ ADD COMMIT FOR SHARE LINK CREATION
            $commitHash = bin2hex(random_bytes(16));
            $commitMessage = "Generated share link with $sharePermission permissions";
            $authorName = $user['first_name'] . ' ' . $user['last_name'];
            
            $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
            $changes = json_encode(['action' => 'generate_share_link', 'permission' => $sharePermission]);
            $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
            $commitStmt->execute();
            
            $shareUrl = "http://" . $_SERVER['HTTP_HOST'] . "/codesphere/share_view.php?token=$shareToken";
            $message = "Share link generated! <br><br>
                       <strong>URL:</strong> <a href='$shareUrl' target='_blank' style='word-break: break-all; color: var(--primary); text-decoration: none;'>$shareUrl</a><br><br>
                       <strong>Permissions:</strong> " . ucfirst($sharePermission) . "<br>
                       <strong>Expires:</strong> " . ($expiryDate ? date('F j, Y g:i A', strtotime($expiryDate)) : 'Never');
        } else {
            $error = "Failed to generate share link: " . $shareStmt->error;
        }
    }
    
   if (isset($_POST['transfer_ownership'])) {
    $newOwnerId = trim($_POST['new_owner_id']);
    
    if (!empty($newOwnerId)) {
        // Check if user exists
        $checkUser = $conn->prepare("SELECT username, first_name, last_name FROM users WHERE userId = ?");
        $checkUser->bind_param("s", $newOwnerId);
        $checkUser->execute();
        $userResult = $checkUser->get_result();
        
        if ($userResult->num_rows === 1) {
            $newOwner = $userResult->fetch_assoc();
            
            // Update repository ownership in user_repos database
            $transferStmt = $reposConn->prepare("UPDATE `$tableName` SET role = 'collaborator' WHERE repo_id = ?");
            $transferStmt->bind_param("s", $repoId);
            
            if ($transferStmt->execute()) {
                // Add new owner to their repository table
                $newOwnerTable = $newOwner['username'] . '_' . $newOwnerId;
                
                // Check if new owner's table exists, create if not
                $tableCheck = $reposConn->query("SHOW TABLES LIKE '$newOwnerTable'");
                if ($tableCheck->num_rows === 0) {
                    $createTable = "CREATE TABLE `$newOwnerTable` (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        repo_id VARCHAR(255) NOT NULL UNIQUE,
                        repo_name VARCHAR(255) NOT NULL,
                        description TEXT,
                        repo_access VARCHAR(50),
                        access_start DATETIME,
                        role VARCHAR(50),
                        permission_level ENUM('read', 'write', 'admin') DEFAULT 'admin'
                    )";
                    $reposConn->query($createTable);
                }
                
                // Get current repository description
                $currentDesc = $repoSettings['description'] ?? '';
                
                // Insert repository for new owner
                $insertStmt = $reposConn->prepare("INSERT INTO `$newOwnerTable` (repo_id, repo_name, description, role, permission_level) VALUES (?, ?, ?, 'owner', 'admin')");
                $insertStmt->bind_param("sss", $repoId, $repoId, $currentDesc);
                
                if ($insertStmt->execute()) {
                    // ✅ ADD COMMIT FOR OWNERSHIP TRANSFER (UPDATED STRUCTURE)
                    $commitHash = bin2hex(random_bytes(16));
                    $commitMessage = "Transferred repository ownership to {$newOwner['first_name']} {$newOwner['last_name']}";
                    $authorName = $user['first_name'] . ' ' . $user['last_name'];
                    
                    $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                    $changes = ['action' => 'transfer_ownership', 'new_owner' => $newOwner['username'], 'new_owner_name' => $newOwner['first_name'] . ' ' . $newOwner['last_name']];
                    $changesJson = json_encode($changes);
                    $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changesJson);
                    $commitStmt->execute();
                    
                    $message = "Repository ownership transferred to {$newOwner['first_name']} {$newOwner['last_name']} successfully!";
                } else {
                    $error = "Failed to transfer ownership: " . $insertStmt->error;
                }
            } else {
                $error = "Failed to update ownership: " . $transferStmt->error;
            }
        } else {
            $error = "User not found!";
        }
    }
}
    
    if (isset($_POST['delete_repository'])) {
        $confirmRepoName = trim($_POST['confirm_repo_name']);
        
        if ($confirmRepoName === $repoId) {
            // ✅ ADD FINAL COMMIT BEFORE DELETION (UPDATED STRUCTURE)
            $commitHash = bin2hex(random_bytes(16));
            $commitMessage = "Deleted repository: $repoId";
            $authorName = $user['first_name'] . ' ' . $user['last_name'];
            
            $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
            $changes = json_encode(['action' => 'delete_repository']);
            $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
            $commitStmt->execute();
            
            // Delete repository database
            $deleteDbConn = new mysqli(DB_HOST, DB_USER, DB_PASS);
            if ($deleteDbConn->query("DROP DATABASE `$repoId`")) {
                // Remove repository from user's table
                $deleteStmt = $reposConn->prepare("DELETE FROM `$tableName` WHERE repo_id = ?");
                $deleteStmt->bind_param("s", $repoId);
                
                if ($deleteStmt->execute()) {
                    $message = "Repository deleted successfully! Redirecting to dashboard...";
                    // Redirect to dashboard after 2 seconds
                    echo '<script>setTimeout(() => window.location.href = "dashboard.php", 2000);</script>';
                } else {
                    $error = "Failed to remove repository from your account: " . $deleteStmt->error;
                }
            } else {
                $error = "Failed to delete repository database: " . $deleteDbConn->error;
            }
            $deleteDbConn->close();
        } else {
            $error = "Repository name confirmation does not match!";
        }
    }
    
    if (isset($_POST['remove_collaborator'])) {
        $collaboratorId = $_POST['collaborator_id'];
        
        // Get collaborator details before removal for commit message
        $collabStmt = $repoConn->prepare("SELECT username FROM collaborators WHERE user_id = ?");
        $collabStmt->bind_param("s", $collaboratorId);
        $collabStmt->execute();
        $collabResult = $collabStmt->get_result()->fetch_assoc();
        $collabUsername = $collabResult['username'] ?? '';
        
        $removeStmt = $repoConn->prepare("DELETE FROM collaborators WHERE user_id = ?");
        $removeStmt->bind_param("s", $collaboratorId);
        
        if ($removeStmt->execute()) {
            // ✅ ADD COMMIT FOR COLLABORATOR REMOVAL
            $commitHash = bin2hex(random_bytes(16));
            $commitMessage = "Removed collaborator: $collabUsername";
            $authorName = $user['first_name'] . ' ' . $user['last_name'];
            
            $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
            $changes = json_encode(['action' => 'remove_collaborator', 'collaborator' => $collabUsername]);
            $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
            $commitStmt->execute();
            
            $message = "Collaborator removed successfully!";
        } else {
            $error = "Failed to remove collaborator: " . $removeStmt->error;
        }
    }
    
    if (isset($_POST['upload_files'])) {
        $uploadFolder = $_POST['upload_folder'] ?? 'root';
        $uploadedFiles = [];
        $uploadErrors = [];
        
        if (isset($_FILES['files'])) {
            foreach ($_FILES['files']['name'] as $key => $name) {
                if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = basename($name);
                    
                    // ✅ CHECK FOR DUPLICATE FILE NAME IN THE SAME FOLDER
                    $checkDuplicateStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_name = ? AND file_type != 'folder'");
                    $checkDuplicateStmt->bind_param("ss", $uploadFolder, $fileName);
                    $checkDuplicateStmt->execute();
                    $duplicateResult = $checkDuplicateStmt->get_result()->fetch_assoc();
                    
                    if ($duplicateResult['count'] > 0) {
                        $uploadErrors[] = "File '$fileName' already exists in this folder!";
                        continue; // Skip this file
                    }
                    
                    // Process file upload and create file record
                    $fileType = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'txt';
                    
                    // Create file table
                    $fileTableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $fileName);
                    $createFileTable = "CREATE TABLE IF NOT EXISTS `$fileTableName` (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        content LONGTEXT,
                        version INT DEFAULT 1,
                        created_by VARCHAR(50),
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        last_modified_by VARCHAR(50)
                    )";
                    
                    if ($repoConn->query($createFileTable)) {
                        // Insert file record
                        $insertFile = $repoConn->prepare("INSERT INTO files (folder, file_name, file_type, created_by) VALUES (?, ?, ?, ?)");
                        $insertFile->bind_param("ssss", $uploadFolder, $fileName, $fileType, $_SESSION['user_id']);
                        
                        if ($insertFile->execute()) {
                            // ✅ ADD COMMIT FOR FILE UPLOAD
                            $commitHash = bin2hex(random_bytes(16));
                            $commitMessage = "Uploaded file: $fileName" . ($uploadFolder !== 'root' ? " in $uploadFolder" : "");
                            $authorName = $user['first_name'] . ' ' . $user['last_name'];
                            
                            $commitStmt = $repoConn->prepare("INSERT INTO commits (commit_hash, author_id, author_name, message, changes) VALUES (?, ?, ?, ?, ?)");
                            $changes = json_encode(['action' => 'upload', 'file' => $fileName, 'folder' => $uploadFolder]);
                            $commitStmt->bind_param("sssss", $commitHash, $_SESSION['user_id'], $authorName, $commitMessage, $changes);
                            $commitStmt->execute();
                            
                            $uploadedFiles[] = ['name' => $fileName];
                        }
                    }
                    $checkDuplicateStmt->close();
                }
            }
            
            if (count($uploadedFiles) > 0) {
                $message = count($uploadedFiles) . " file(s) uploaded successfully!";
                if (count($uploadErrors) > 0) {
                    $message .= " " . count($uploadErrors) . " file(s) skipped due to errors.";
                }
            }
            
            if (count($uploadErrors) > 0 && count($uploadedFiles) === 0) {
                $error = implode(" ", $uploadErrors);
            }
        }
    }
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

// Connect to repository database
$repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
if ($repoConn->connect_error) {
    die("Repository not found or access denied");
}

// Add version table if it doesn't exist
addVersionTableToExistingRepo($repoId);


// Get files and folders
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

// Get commits for activity graph
$commits = [];
$commitsStmt = $repoConn->prepare("SELECT * FROM commits ORDER BY timestamp DESC LIMIT 50");
$commitsStmt->execute();
$commitsResult = $commitsStmt->get_result();
$commits = $commitsResult->fetch_all(MYSQLI_ASSOC);

// Get collaborators with full user details
$collaborators = [];
$collabStmt = $repoConn->prepare("SELECT c.*, u.first_name, u.last_name FROM collaborators c JOIN codesphere.users u ON c.user_id = u.userId WHERE c.status = 'active'");
$collabStmt->execute();
$collabResult = $collabStmt->get_result();
$collaborators = $collabResult->fetch_all(MYSQLI_ASSOC);

// Get file statistics
$fileStats = [];
$statsStmt = $repoConn->prepare("SELECT file_type, COUNT(*) as count FROM files WHERE file_type != 'folder' GROUP BY file_type");
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
while ($stat = $statsResult->fetch_assoc()) {
    $fileStats[$stat['file_type']] = $stat['count'];
}

// Get commit activity for graph
$activityStmt = $repoConn->prepare("SELECT DATE(timestamp) as date, COUNT(*) as count FROM commits GROUP BY DATE(timestamp) ORDER BY date DESC LIMIT 30");
$activityStmt->execute();
$activityResult = $activityStmt->get_result();
$commitActivity = $activityResult->fetch_all(MYSQLI_ASSOC);

// Get repository settings - FIXED: Only select existing columns
$settingsStmt = $reposConn->prepare("SELECT repo_id, repo_name, description, role, permission_level FROM `$tableName` WHERE repo_id = ?");
$settingsStmt->bind_param("s", $repoId);
$settingsStmt->execute();
$settingsResult = $settingsStmt->get_result();
$repoSettings = $settingsResult->fetch_assoc();

// Get active share links
$shareLinks = [];
$shareStmt = $repoConn->prepare("SELECT * FROM share_links WHERE is_active = TRUE AND (expiry IS NULL OR expiry > NOW()) ORDER BY created_at DESC");
$shareStmt->execute();
$shareResult = $shareStmt->get_result();
$shareLinks = $shareResult->fetch_all(MYSQLI_ASSOC);

$conn->close();
$reposConn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($repoId); ?> - CodeSphere</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
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
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.1);
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            --gradient-hover: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
        }

        /* Cyberpunk Theme */
        .theme-cyberpunk {
            --primary: #00ff88;
            --primary-dark: #00cc6a;
            --primary-light: #66ffb8;
            --secondary: #ff0088;
            --accent: #ff6b00;
            --success: #00ff88;
            --error: #ff0066;
            --warning: #ffcc00;
            --dark: #0a0a0a;
            --light: #1a1a1a;
            --gray: #666666;
            --light-gray: #333333;
            --card-bg: #222222;
            --border: #444444;
            --sidebar-bg: #151515;
        }

        /* Dark Matrix Theme */
        .theme-matrix {
            --primary: #00ff41;
            --primary-dark: #00cc34;
            --primary-light: #66ff8a;
            --secondary: #0088ff;
            --accent: #ff00ff;
            --success: #00ff41;
            --error: #ff0044;
            --warning: #ffff00;
            --dark: #000000;
            --light: #0a0a0a;
            --gray: #00ff41;
            --light-gray: #003300;
            --card-bg: #001100;
            --border: #00ff41;
            --sidebar-bg: #000800;
        }

        /* Ocean Blue Theme */
        .theme-ocean {
            --primary: #0066cc;
            --primary-dark: #0052a3;
            --primary-light: #4da8ff;
            --secondary: #00b3b3;
            --accent: #ff6b6b;
            --success: #00cc99;
            --error: #ff5252;
            --warning: #ffb74d;
            --dark: #0a1929;
            --light: #f0f8ff;
            --gray: #6699cc;
            --light-gray: #cce5ff;
            --card-bg: #ffffff;
            --border: #b3d9ff;
            --sidebar-bg: #e6f2ff;
        }

        /* Sunset Theme */
        .theme-sunset {
            --primary: #ff6b6b;
            --primary-dark: #ff5252;
            --primary-light: #ffb3b3;
            --secondary: #ffa726;
            --accent: #ab47bc;
            --success: #66bb6a;
            --error: #ef5350;
            --warning: #ffca28;
            --dark: #2c1810;
            --light: #fff8f6;
            --gray: #8d6e63;
            --light-gray: #ffebee;
            --card-bg: #ffffff;
            --border: #ffcdd2;
            --sidebar-bg: #fff3e0;
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

        .container {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 100vh;
        }

        .sidebar-collapsed {
            grid-template-columns: 60px 1fr;
        }

        /* Premium Sidebar */
        .sidebar {
            background: linear-gradient(135deg, var(--sidebar-bg) 0%, rgba(248, 250, 252, 0.95) 100%);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(226, 232, 240, 0.6);
            padding: 1.5rem;
            overflow-y: auto;
            transition: var(--transition);
            position: relative;
            box-shadow: var(--shadow-xl);
            z-index: 100;
        }

        .dark-mode .sidebar {
            background: linear-gradient(135deg, var(--sidebar-bg) 0%, rgba(15, 23, 42, 0.95) 100%);
            border-right: 1px solid rgba(51, 65, 85, 0.6);
        }

        .sidebar.collapsed {
            padding: 1rem 0.5rem;
        }

        .sidebar.collapsed .repo-info,
        .sidebar.collapsed .nav-title,
        .sidebar.collapsed .nav-item span {
            display: none;
        }

        .sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 0.75rem;
        }

        .sidebar.collapsed .nav-icon {
            margin-right: 0;
        }

        .sidebar-toggle {
            position: absolute;
            top: 1rem;
            right: -12px;
            width: 24px;
            height: 24px;
            background: var(--gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.75rem;
            z-index: 10;
            border: 2px solid var(--card-bg);
            transition: var(--transition);
            box-shadow: var(--shadow-lg);
        }

        .sidebar-toggle:hover {
            transform: scale(1.1) rotate(180deg);
            box-shadow: var(--shadow-xl);
        }

        .repo-info {
            margin-bottom: 2rem;
            animation: slideInDown 0.5s ease-out;
        }

        .repo-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .repo-meta {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .badge.owner {
            background: rgba(99, 102, 241, 0.15);
            color: var(--primary);
        }

        .badge.collaborator {
            background: rgba(245, 158, 11, 0.15);
            color: var(--accent);
            border-color: rgba(245, 158, 11, 0.2);
        }

        .nav-section {
            margin-bottom: 2rem;
            animation: slideInLeft 0.6s ease-out;
        }

        .nav-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: var(--dark);
            text-decoration: none;
            border-radius: var(--radius);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 0.25rem;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid transparent;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            transition: var(--transition);
            opacity: 0.1;
        }

        .nav-item:hover::before {
            left: 0;
        }

        .nav-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .nav-item.active {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow-xl);
            transform: translateX(5px);
        }

        .nav-item.active::before {
            display: none;
        }

        .nav-icon {
            width: 20px;
            margin-right: 0.75rem;
            transition: var(--transition);
            position: relative;
            z-index: 1;
        }

        /* Premium Main Content */
        .main-content {
            padding: 2rem;
            overflow-y: auto;
            background: linear-gradient(135deg, var(--light) 0%, #f1f5f9 100%);
            position: relative;
        }

        .dark-mode .main-content {
            background: linear-gradient(135deg, var(--light) 0%, #0f172a 100%);
        }

        .floating-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .floating-element {
            position: absolute;
            border-radius: 50%;
            background: var(--gradient);
            opacity: 0.03;
            filter: blur(60px);
            animation: float 20s infinite ease-in-out;
        }

        .floating-element:nth-child(1) {
            width: 400px;
            height: 400px;
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .floating-element:nth-child(2) {
            width: 300px;
            height: 300px;
            top: 60%;
            right: 5%;
            animation-delay: 7s;
        }

        .floating-element:nth-child(3) {
            width: 250px;
            height: 250px;
            bottom: 20%;
            left: 15%;
            animation-delay: 14s;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
            animation: slideInDown 0.5s ease-out;
            position: relative;
            z-index: 2;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
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
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid transparent;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: var(--shadow-xl);
            background: var(--gradient-hover);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.7);
            color: var(--dark);
            box-shadow: var(--shadow);
            border: 1px solid rgba(226, 232, 240, 0.6);
            backdrop-filter: blur(20px);
        }

        .dark-mode .btn-secondary {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: rgba(255, 255, 255, 0.9);
        }

        .dark-mode .btn-secondary:hover {
            background: rgba(30, 41, 59, 0.9);
        }

        .btn-danger {
            background: var(--error);
            color: white;
            box-shadow: var(--shadow);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: #dc2626;
        }

        .btn-success {
            background: var(--success);
            color: white;
            box-shadow: var(--shadow);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: #059669;
        }

        /* Premium File Browser */
        .file-browser {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(226, 232, 240, 0.6);
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            animation: slideInUp 0.6s ease-out;
            position: relative;
            z-index: 2;
        }

        .dark-mode .file-browser {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .file-browser:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-2px);
        }

        .browser-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            background: linear-gradient(135deg, rgba(255,255,255,0.8) 0%, rgba(248, 250, 252, 0.8) 100%);
            backdrop-filter: blur(20px);
        }

        .dark-mode .browser-header {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.8) 100%);
        }

        .browser-content {
            padding: 1.5rem;
        }

        .file-item, .folder-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: var(--radius);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            margin-bottom: 0.5rem;
            position: relative;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.5);
        }

        .dark-mode .file-item, .dark-mode .folder-item {
            background: rgba(30, 41, 59, 0.5);
        }

        .file-item:hover, .folder-item:hover {
            background: var(--light-gray);
            transform: translateX(8px);
            border-color: var(--primary-light);
            box-shadow: var(--shadow-lg);
        }

        .dark-mode .file-item:hover, .dark-mode .folder-item:hover {
            background: rgba(51, 65, 85, 0.3);
        }

        .file-icon, .folder-icon {
            width: 20px;
            margin-right: 1rem;
            color: var(--gray);
            transition: var(--transition);
        }

        .file-item:hover .file-icon,
        .folder-item:hover .folder-icon {
            transform: scale(1.2);
            color: var(--primary);
        }

        .file-name, .folder-name {
            flex: 1;
            font-weight: 500;
        }

        .file-path {
            color: var(--gray);
            font-size: 0.875rem;
        }

        .file-actions {
            display: flex;
            gap: 0.5rem;
            opacity: 0;
            transition: var(--transition);
        }

        .file-item:hover .file-actions,
        .folder-item:hover .file-actions {
            opacity: 1;
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--light-gray);
            transition: left 0.3s;
        }

        .action-btn:hover::before {
            left: 0;
        }

        .action-btn:hover {
            transform: scale(1.2);
            color: var(--dark);
        }

        .action-btn.delete:hover {
            color: var(--error);
        }

        .action-btn.download:hover {
            color: var(--success);
        }

        /* Premium Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 2;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(226, 232, 240, 0.6);
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .dark-mode .stat-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient);
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .stat-card:hover::before {
            transform: scaleX(1);
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.5rem;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 10px rgba(99, 102, 241, 0.2);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Premium Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
            position: relative;
            z-index: 2;
        }

        .chart-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
        }

        .dark-mode .chart-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .chart-card:hover {
            box-shadow: var(--shadow-xl);
            transform: translateY(-3px);
        }

        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        /* Premium Modals */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(30px);
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            transform: scale(0.7) translateY(50px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(226, 232, 240, 0.6);
        }

        .dark-mode .modal {
            background: rgba(30, 41, 59, 0.9);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .modal-overlay.active .modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, rgba(255,255,255,0.8) 0%, rgba(248, 250, 252, 0.8) 100%);
            backdrop-filter: blur(20px);
        }

        .dark-mode .modal-header {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 23, 42, 0.8) 100%);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            animation: slideInUp 0.4s ease-out;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--light);
            color: var(--dark);
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px);
        }

        .form-control.error {
            border-color: var(--error);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        .form-text.error {
            color: var(--error);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        /* Premium Alerts */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideInDown 0.5s ease-out;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(20px);
            border: 1px solid transparent;
        }

        .alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .alert.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
            border-color: rgba(16, 185, 129, 0.3);
        }

        .alert.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border-left: 4px solid var(--error);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .alert.error .alert-content {
            flex: 1;
        }

        .alert.error .alert-tip {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Premium Collaborators */
        .collaborators-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .collaborator-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: var(--shadow);
        }

        .dark-mode .collaborator-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .collaborator-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary-light);
        }

        .collaborator-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
        }

        .collaborator-card:hover .collaborator-avatar {
            transform: scale(1.1) rotate(5deg);
        }

        .collaborator-info {
            flex: 1;
        }

        .collaborator-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .collaborator-details {
            font-size: 0.875rem;
            color: var(--gray);
        }

        .collaborator-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Premium Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-bottom: 2px solid transparent;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .tab::before {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--gradient);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab:hover::before {
            width: 100%;
            left: 0;
        }

        .tab.active {
            color: var(--primary);
        }

        .tab.active::before {
            width: 100%;
            left: 0;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }

        .tab-content.active {
            display: block;
        }

        /* Premium Insights Grid */
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            position: relative;
            z-index: 2;
        }

        .insight-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(226, 232, 240, 0.6);
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .dark-mode .insight-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(51, 65, 85, 0.6);
        }

        .insight-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-3px);
        }

        /* Premium Commits List */
        .commits-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .commit-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }

        .commit-item:hover {
            background: var(--light-gray);
            transform: translateX(8px);
            border-radius: var(--radius);
        }

        .dark-mode .commit-item:hover {
            background: rgba(51, 65, 85, 0.3);
        }

        .commit-item:last-child {
            border-bottom: none;
        }

        .commit-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
            flex-shrink: 0;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .commit-item:hover .commit-avatar {
            transform: scale(1.1);
        }

        .commit-details {
            flex: 1;
        }

        .commit-message {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .commit-meta {
            font-size: 0.875rem;
            color: var(--gray);
        }

        /* Premium Mobile Menu */
        .mobile-menu-toggle {
            display: none;
            background: var(--gradient);
            color: white;
            border: none;
            border-radius: var(--radius);
            padding: 0.75rem;
            cursor: pointer;
            font-size: 1.25rem;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            z-index: 1001;
            position: fixed;
            top: 1rem;
            left: 1rem;
            backdrop-filter: blur(20px);
        }

        .mobile-menu-toggle:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-xl);
        }

        /* Premium Animations */
        @keyframes float {
            0%, 100% {
                transform: translateY(0) translateX(0) rotate(0deg);
            }
            25% {
                transform: translateY(-20px) translateX(10px) rotate(5deg);
            }
            50% {
                transform: translateY(10px) translateX(-10px) rotate(-5deg);
            }
            75% {
                transform: translateY(-10px) translateX(-20px) rotate(3deg);
            }
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
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

        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }

        @keyframes glow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(99, 102, 241, 0.3);
            }
            50% {
                box-shadow: 0 0 30px rgba(99, 102, 241, 0.6);
            }
        }

        /* Premium Responsive Design */
        @media (max-width: 1024px) {
            .container {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                position: fixed;
                top: 0;
                left: -280px;
                width: 280px;
                height: 100vh;
                z-index: 1000;
                transition: left 0.3s ease;
                box-shadow: var(--shadow-xl);
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .mobile-menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidebar-toggle {
                display: none;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .browser-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .collaborators-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                gap: 1rem;
            }
            
            .chart-card {
                padding: 1rem;
            }
            
            .tab {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }

        /* Premium Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--gradient);
            color: white;
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 1000;
            box-shadow: var(--shadow-xl);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: bounceIn 1s ease-out, pulse 2s infinite 3s, glow 3s infinite;
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .theme-toggle:hover {
            transform: scale(1.2) rotate(15deg);
            box-shadow: var(--shadow-xl);
            animation: none;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* File Type Colors */
        .file-icon.php { color: #777BB3; }
        .file-icon.js { color: #F7DF1E; }
        .file-icon.html { color: #E34F26; }
        .file-icon.css { color: #1572B6; }
        .file-icon.py { color: #3776AB; }
        .file-icon.java { color: #007396; }
        .file-icon.txt { color: var(--gray); }
        .file-icon.json { color: var(--accent); }
        .file-icon.md { color: var(--primary); }

        /* Premium Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            animation: fadeIn 0.6s ease-out;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            animation: bounceIn 1s ease-out;
        }

        /* Premium Progress Bar */
        .progress-bar {
            width: 100%;
            height: 4px;
            background: var(--light-gray);
            border-radius: 2px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: var(--gradient);
            transition: width 0.3s ease;
        }

        /* Particle Background */
        .particles {
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
            border-radius: 50%;
            background: var(--primary);
            opacity: 0.1;
            animation: float-particle 20s infinite linear;
        }

        @keyframes float-particle {
            0% {
                transform: translateY(100vh) translateX(0) rotate(0deg);
            }
            100% {
                transform: translateY(-100px) translateX(100px) rotate(360deg);
            }
        }

        /* Code Editor Preview */
        .code-preview {
            background: #1a1b26;
            border-radius: var(--radius);
            padding: 1rem;
            margin: 1rem 0;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.875rem;
            line-height: 1.5;
            overflow-x: auto;
        }

        .code-line {
            display: flex;
            align-items: center;
            margin-bottom: 0.25rem;
        }

        .line-number {
            color: #565f89;
            margin-right: 1rem;
            min-width: 2rem;
            text-align: right;
        }

        .code-content {
            color: #c0caf5;
        }

        .keyword { color: #bb9af7; }
        .function { color: #7dcfff; }
        .string { color: #9ece6a; }
        .comment { color: #565f89; font-style: italic; }
        .variable { color: #c0caf5; }

        /* Theme Selector */
        .theme-selector {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            z-index: 1000;
        }

        .theme-btn {
            background: var(--gradient);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: var(--shadow-xl);
            transition: var(--transition);
            backdrop-filter: blur(20px);
        }

        .theme-btn:hover {
            transform: scale(1.1) rotate(15deg);
        }

        .theme-options {
            position: absolute;
            bottom: 60px;
            left: 0;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            padding: 1rem;
            box-shadow: var(--shadow-xl);
            display: none;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            min-width: 200px;
        }

        .theme-options.active {
            display: grid;
            animation: slideInUp 0.3s ease-out;
        }

        .theme-option {
            padding: 0.75rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            text-align: center;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .theme-option:hover {
            transform: scale(1.05);
        }

        .theme-option.active {
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }

        /* Share Links List */
        .share-links-list {
            margin-top: 1rem;
        }

        .share-link-item {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 0.5rem;
            border: 1px solid rgba(226, 232, 240, 0.6);
            transition: var(--transition);
        }

        .share-link-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .share-link-url {
            word-break: break-all;
            color: var(--primary);
            text-decoration: none;
            font-family: monospace;
            font-size: 0.875rem;
        }

        .share-link-meta {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 0.25rem;
        }

        /* 3D Card Effects */
        .card-3d {
            transform-style: preserve-3d;
            perspective: 1000px;
        }

        .card-3d:hover {
            transform: rotateY(5deg) rotateX(5deg);
        }

        /* Glass Morphism */
        .glass {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        /* Neon Glow */
        .neon-glow {
            box-shadow: 0 0 10px var(--primary),
                       0 0 20px var(--primary),
                       0 0 40px var(--primary);
        }

        /* Holographic Effect */
        .holographic {
            background: linear-gradient(45deg, 
                rgba(255,255,255,0.1) 0%, 
                rgba(255,255,255,0.3) 50%, 
                rgba(255,255,255,0.1) 100%);
            background-size: 200% 200%;
            animation: holographic 3s ease-in-out infinite;
        }

        @keyframes holographic {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        /* Matrix Rain Effect */
        .matrix-rain {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
            opacity: 0.1;
        }
    </style>
</head>
<body class="theme-default">
    <!-- Particle Background -->
    <div class="particles" id="particles"></div>
    
    <!-- Matrix Rain Effect -->
    <canvas class="matrix-rain" id="matrixRain"></canvas>
    
    <div class="container" id="main-container">
        <!-- Mobile Menu Toggle -->
        <button class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Theme Selector -->
        <div class="theme-selector">
            <button class="theme-btn" id="themeSelectorBtn">
                <i class="fas fa-palette"></i>
            </button>
            <div class="theme-options" id="themeOptions">
                <div class="theme-option active" data-theme="default">Default</div>
                <div class="theme-option" data-theme="cyberpunk">Cyberpunk</div>
                <div class="theme-option" data-theme="matrix">Matrix</div>
                <div class="theme-option" data-theme="ocean">Ocean</div>
                <div class="theme-option" data-theme="sunset">Sunset</div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-toggle" id="sidebar-toggle">
                <i class="fas fa-chevron-left"></i>
            </div>
            
            <div class="repo-info"><br>
                <div class="repo-meta">
                    <span class="badge <?php echo $userRole; ?>"><?php echo ucfirst($userRole); ?></span>
                    <span class="badge"><?php echo ucfirst($userPermission); ?> access</span>
                </div>
            </div>

            <div class="nav-section">
                <div class="nav-title">Repository</div>
                <a href="#" class="nav-item active" data-tab="code">
                    <i class="fas fa-code nav-icon"></i>
                    <span>Code</span>
                </a>
                <a href="#" class="nav-item" data-tab="commits">
                    <i class="fas fa-code-branch nav-icon"></i>
                    <span>Commits (<?php echo count($commits); ?>)</span>
                </a>
                <a href="#" class="nav-item" data-tab="insights">
                    <i class="fas fa-chart-line nav-icon"></i>
                    <span>Insights</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Collaboration</div>
                <a href="#" class="nav-item" onclick="openCollaboratorModal()">
                    <i class="fas fa-user-plus nav-icon"></i>
                    <span>Add Collaborator</span>
                </a>
                <a href="#" class="nav-item" onclick="openShareModal()">
                    <i class="fas fa-share-alt nav-icon"></i>
                    <span>Share Repository</span>
                </a>
                <a href="#" class="nav-item" data-tab="collaborators">
                    <i class="fas fa-users nav-icon"></i>
                    <span>Collaborators (<?php echo count($collaborators) + 1; ?>)</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Settings</div>
                <a href="#" class="nav-item" onclick="openSettingsModal()">
                    <i class="fas fa-cog nav-icon"></i>
                    <span>Repository Settings</span>
                </a>
                
                <a href="#" class="nav-item" onclick="openDangerZoneModal()">
                    <i class="fas fa-trash nav-icon"></i>
                    <span>Danger Zone</span>
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Floating Background Elements -->
            <div class="floating-bg">
                <div class="floating-element"></div>
                <div class="floating-element"></div>
                <div class="floating-element"></div>
            </div>

            <!-- Header -->
            <div class="header">
                <div>
                    <h1 style="font-size: 2.5rem; font-weight: 800; color: var(--dark); margin-bottom: 0.5rem; background: var(--gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                        <?php echo htmlspecialchars($repoId); ?>
                    </h1>
                    <p style="color: var(--gray); font-size: 1.1rem;">Repository • <?php echo $userRole === 'owner' ? 'Owned by you' : 'Collaborating'; ?></p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="openFileModal()">
                        <i class="fas fa-file"></i>
                        New File
                    </button>
                    <button class="btn btn-secondary" onclick="openFolderModal()">
                        <ion-icon name="folder-outline"></ion-icon>
                        New Folder
                    </button>
                    <button class="btn btn-secondary" onclick="openUploadModal()">
                        <ion-icon name="cloud-upload-outline"></ion-icon>
                        Upload
                    </button>
                    <button class="btn btn-success" onclick="downloadRepo()">
                        <ion-icon name="download-outline"></ion-icon>
                        Download
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary" style="text-decoration: none;">
                        <ion-icon name="arrow-back-outline"></ion-icon>
                        Back
                    </a>
                </div>
            </div>

            <!-- Premium Alerts Section -->
            <?php if ($message): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error">
                    <ion-icon name="warning-outline"></ion-icon>
                    <div class="alert-content">
                        <strong>Operation Failed</strong>
                        <div style="margin-top: 0.5rem;"><?php echo htmlspecialchars($error); ?></div>
                        <?php if (strpos($error, 'already exists') !== false): ?>
                            <div class="alert-tip">
                                <i class="fas fa-lightbulb"></i>
                                Tip: Try using a different name or check the existing items in this folder.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="code">Code</button>
                <button class="tab" data-tab="commits">Commits</button>
                <button class="tab" data-tab="insights">Insights</button>
                <button class="tab" data-tab="collaborators">Collaborators</button>
                <button class="tab" data-tab="share-links">Share Links</button>
            </div>

            <!-- Code Tab -->
            <div class="tab-content active" id="code-tab">
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card card-3d">
                        <div class="stat-number"><?php echo count($files); ?></div>
                        <div class="stat-label">Files</div>
                    </div>
                    <div class="stat-card card-3d">
                        <div class="stat-number"><?php echo count($folders); ?></div>
                        <div class="stat-label">Folders</div>
                    </div>
                    <div class="stat-card card-3d">
                        <div class="stat-number"><?php echo count($commits); ?></div>
                        <div class="stat-label">Commits</div>
                    </div>
                    <div class="stat-card card-3d">
                        <div class="stat-number"><?php echo count($collaborators) + 1; ?></div>
                        <div class="stat-label">Collaborators</div>
                    </div>
                </div>

                <!-- File Browser -->
                <div class="file-browser glass">
                    <div class="browser-header">
                        <h3 style="font-weight: 600; color: var(--dark);">Files and Folders</h3>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <button class="btn btn-secondary" onclick="openFileModal()">
                                <ion-icon name="add-outline"></ion-icon>
                                File
                            </button>
                            <button class="btn btn-secondary" onclick="openFolderModal()">
                                <ion-icon name="add-outline"></ion-icon>
                                Folder
                            </button>
                            <button class="btn btn-secondary" onclick="openUploadModal()">
                                <ion-icon name="cloud-upload-outline"></ion-icon>
                                Upload
                            </button>
                        </div>
                    </div>
                    <div class="browser-content">
                        <!-- Folders -->
                        <?php foreach ($folders as $folder): ?>
                            <?php
                            // Check if folder has direct contents (only files directly in this folder)
                            $hasContents = false;
                            $checkContentsStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_type != 'folder'");
                            $checkContentsStmt->bind_param("s", $folder['folder']);
                            $checkContentsStmt->execute();
                            $contentsResult = $checkContentsStmt->get_result()->fetch_assoc();
                            $hasContents = $contentsResult['count'] > 0;
                            $checkContentsStmt->close();
                            ?>
                            <div class="folder-item holographic" onclick="openFolder('<?php echo htmlspecialchars($folder['folder']); ?>')">
                                <div class="folder-arrow <?php echo $hasContents ? 'has-contents' : 'no-contents'; ?>" 
                                    onclick="<?php echo $hasContents ? "toggleFolder('" . htmlspecialchars($folder['folder']) . "', event)" : "event.stopPropagation()"; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                                <i class="fas fa-folder folder-icon" style="color: #f59e0b;"></i>
                                <div class="folder-name"><?php echo htmlspecialchars(basename($folder['folder'])); ?></div>
                                <div class="file-path"><?php echo htmlspecialchars($folder['folder']); ?></div>
                                <div class="file-actions">
                                    <button class="action-btn delete" onclick="event.stopPropagation(); deleteFolder('<?php echo htmlspecialchars($folder['folder']); ?>', '<?php echo htmlspecialchars(basename($folder['folder'])); ?>')">
                                        <ion-icon name="trash-outline"></ion-icon>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Files in Root -->
                        <?php 
                        // Only show files that are directly in root (not in subfolders)
                        $rootFiles = array_filter($files, function($file) {
                            return $file['folder'] === 'root';
                        });
                        ?>

                        <?php foreach ($rootFiles as $file): ?>
                            <div class="file-item holographic" onclick="openFile('<?php echo htmlspecialchars($file['file_name']); ?>', '<?php echo htmlspecialchars($file['file_type']); ?>')">
                                <?php
                                $fileIcons = [
                                    'php' => 'fab fa-php file-icon php',
                                    'js' => 'fab fa-js-square file-icon js',
                                    'html' => 'fab fa-html5 file-icon html',
                                    'css' => 'fab fa-css3-alt file-icon css',
                                    'py' => 'fab fa-python file-icon py',
                                    'java' => 'fab fa-java file-icon java',
                                    'json' => 'fas fa-code file-icon json',
                                    'md' => 'fas fa-markdown file-icon md',
                                    'txt' => 'fas fa-file-alt file-icon txt',
                                    'default' => 'fas fa-file file-icon'
                                ];
                                $fileExt = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                $fileIcon = $fileIcons[$fileExt] ?? $fileIcons['default'];
                                ?>
                                <i class="<?php echo $fileIcon; ?>"></i>
                                <div class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                <div class="file-path"><?php echo htmlspecialchars($file['folder']); ?></div>
                                <div class="file-actions">
                                    <button class="action-btn download" onclick="event.stopPropagation(); downloadFile('<?php echo htmlspecialchars($file['file_name']); ?>')">
                                        <ion-icon name="download-outline"></ion-icon>
                                    </button>
                                    <button class="action-btn delete" onclick="event.stopPropagation(); deleteFile(<?php echo $file['id']; ?>, '<?php echo htmlspecialchars($file['file_name']); ?>')">
                                        <ion-icon name="trash-outline"></ion-icon>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($folders) && empty($rootFiles)): ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <h3>No files yet</h3>
                                <p>Get started by creating your first file or folder</p>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($files) && empty($folders)): ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <h3>No files yet</h3>
                                <p>Get started by creating your first file or folder</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Commits Tab -->
            <div class="tab-content" id="commits-tab">
                <div class="file-browser glass">
                    <div class="browser-header">
                        <h3 style="font-weight: 600; color: var(--dark);">Commit History</h3>
                    </div>
                    <div class="browser-content">
                        <div class="commits-list">
                            <?php foreach ($commits as $commit): ?>
                                <div class="commit-item holographic">
                                    <div class="commit-avatar">
                                        <?php echo strtoupper(substr($commit['author_name'] ?? 'Unknown', 0, 2)); ?>
                                    </div>
                                    <div class="commit-details">
                                        <div class="commit-message"><?php echo htmlspecialchars($commit['message'] ?? 'No message'); ?></div>
                                        <div class="commit-meta">
                                            <?php echo htmlspecialchars($commit['author_name'] ?? 'Unknown'); ?> committed on <?php echo date('M j, Y g:i A', strtotime($commit['timestamp'] ?? 'now')); ?>
                                            <?php if (!empty($commit['commit_hash'])): ?>
                                                • <?php echo substr(htmlspecialchars($commit['commit_hash']), 0, 8); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($commits)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-code-branch"></i>
                                    <h3>No commits yet</h3>
                                    <p>Start coding to see commit history</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Insights Tab -->
            <div class="tab-content" id="insights-tab">
                <div class="insights-grid">
                    <div class="chart-card glass">
                        <div class="chart-title">File Types Distribution</div>
                        <canvas id="fileTypeChart" height="200"></canvas>
                    </div>
                    <div class="chart-card glass">
                        <div class="chart-title">Commit Activity (Last 30 days)</div>
                        <canvas id="commitActivityChart" height="200"></canvas>
                    </div>
                </div>
                
                <div class="file-browser glass" style="margin-top: 2rem;">
                    <div class="browser-header">
                        <h3 style="font-weight: 600; color: var(--dark);">Repository Statistics</h3>
                    </div>
                    <div class="browser-content">
                        <div class="stats-grid">
                            <div class="stat-card card-3d">
                                <div class="stat-number"><?php echo count($files); ?></div>
                                <div class="stat-label">Total Files</div>
                            </div>
                            <div class="stat-card card-3d">
                                <div class="stat-number"><?php echo count($folders); ?></div>
                                <div class="stat-label">Total Folders</div>
                            </div>
                            <div class="stat-card card-3d">
                                <div class="stat-number"><?php echo count($commits); ?></div>
                                <div class="stat-label">Total Commits</div>
                            </div>
                            <div class="stat-card card-3d">
                                <div class="stat-number"><?php echo count($collaborators) + 1; ?></div>
                                <div class="stat-label">Total Contributors</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Premium Collaborators Tab -->
            <div class="tab-content" id="collaborators-tab">
                <div class="file-browser glass">
                    <div class="browser-header">
                        <h3 style="font-weight: 600; color: var(--dark);">Collaborators</h3>
                        <button class="btn btn-primary" onclick="openCollaboratorModal()">
                            <i class="fas fa-user-plus"></i>
                            Add Collaborator
                        </button>
                    </div>
                    <div class="browser-content">
                        <div class="collaborators-list">
                            <!-- Owner -->
                            <div class="collaborator-card card-3d">
                                <div class="collaborator-avatar">
                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                </div>
                                <div class="collaborator-info">
                                    <div class="collaborator-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                    <div class="collaborator-details">Owner • Admin</div>
                                </div>
                                <div class="collaborator-actions">
                                    <span class="badge owner">Owner</span>
                                </div>
                            </div>

                            <!-- Other collaborators -->
                            <?php foreach ($collaborators as $collab): ?>
                                <div class="collaborator-card card-3d">
                                    <div class="collaborator-avatar" style="background: var(--accent);">
                                        <?php echo strtoupper(substr($collab['first_name'], 0, 1) . substr($collab['last_name'], 0, 1)); ?>
                                    </div>
                                    <div class="collaborator-info">
                                        <div class="collaborator-name"><?php echo htmlspecialchars($collab['first_name'] . ' ' . $collab['last_name']); ?></div>
                                        <div class="collaborator-details">@<?php echo htmlspecialchars($collab['username']); ?> • <?php echo ucfirst($collab['permission_level']); ?> access</div>
                                    </div>
                                    <div class="collaborator-actions">
                                        <span class="badge collaborator"><?php echo ucfirst($collab['permission_level']); ?></span>
                                        <?php if ($userRole === 'owner'): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this collaborator?')">
                                                <input type="hidden" name="collaborator_id" value="<?php echo htmlspecialchars($collab['user_id']); ?>">
                                                <button type="submit" name="remove_collaborator" class="action-btn delete" title="Remove collaborator">
                                                    <ion-icon name="close-outline"></ion-icon>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (empty($collaborators)): ?>
                            <div class="empty-state">
                                <ion-icon name="people-outline"></ion-icon>
                                <h3>No collaborators yet</h3>
                                <p>Add collaborators to work together on this repository</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Share Links Tab -->
            <div class="tab-content" id="share-links-tab">
                <div class="file-browser glass">
                    <div class="browser-header">
                        <h3 style="font-weight: 600; color: var(--dark);">Share Links</h3>
                        <button class="btn btn-primary" onclick="openShareModal()">
                            <ion-icon name="link-outline"></ion-icon>
                            Generate New Link
                        </button>
                    </div>
                    <div class="browser-content">
                        <div class="share-links-list">
                            <?php foreach ($shareLinks as $shareLink): ?>
                                <div class="share-link-item holographic">
                                    <div style="display: flex; justify-content: between; align-items: start; gap: 1rem;">
                                        <div style="flex: 1;">
                                            <a href="share_view.php?token=<?php echo htmlspecialchars($shareLink['token']); ?>" 
                                               class="share-link-url" 
                                               target="_blank">
                                               http://<?php echo $_SERVER['HTTP_HOST']; ?>/codesphere/share_view.php?token=<?php echo htmlspecialchars($shareLink['token']); ?>
                                            </a>
                                            <div class="share-link-meta">
                                                <strong>Permissions:</strong> <?php echo ucfirst($shareLink['permission']); ?> • 
                                                <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($shareLink['created_at'])); ?> •
                                                <strong>Expires:</strong> <?php echo $shareLink['expiry'] ? date('M j, Y g:i A', strtotime($shareLink['expiry'])) : 'Never'; ?>
                                            </div>
                                        </div>
                                        <div>
                                            <button class="action-btn" onclick="copyShareLink('<?php echo htmlspecialchars($shareLink['token']); ?>')" title="Copy link">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to revoke this share link?')">
                                                <input type="hidden" name="share_token" value="<?php echo htmlspecialchars($shareLink['token']); ?>">
                                                <button type="submit" name="revoke_share_link" class="action-btn delete" title="Revoke link">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($shareLinks)): ?>
                                <div class="empty-state">
                                    <ion-icon name="link-outline"></ion-icon>
                                    <h3>No active share links</h3>
                                    <p>Generate a share link to allow others to access this repository</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Premium Create File Modal -->
    <div class="modal-overlay" id="fileModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Create New File</h3>
                <button onclick="closeFileModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createFileForm">
                    <div class="form-group">
                        <label class="form-label">File Name</label>
                        <input type="text" id="file_name" name="file_name" class="form-control" placeholder="example.php" required oninput="validateFileNameInput()">
                        <div class="form-text" id="fileTypeHint">File type will be auto-detected from extension</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">File Type</label>
                        <select name="file_type" id="file_type" class="form-control" required>
                            <option value="">Auto-detect</option>
                            <option value="php">PHP</option>
                            <option value="js">JavaScript</option>
                            <option value="html">HTML</option>
                            <option value="css">CSS</option>
                            <option value="py">Python</option>
                            <option value="java">Java</option>
                            <option value="json">JSON</option>
                            <option value="md">Markdown</option>
                            <option value="txt">Text</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Folder</label>
                        <select name="folder" class="form-control">
                            <option value="root">Root</option>
                            <?php foreach ($folders as $folder): ?>
                                <option value="<?php echo htmlspecialchars($folder['folder']); ?>">
                                    <?php echo htmlspecialchars($folder['folder']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeFileModal()">Cancel</button>
                        <button type="submit" name="create_file" class="btn btn-primary" id="createFileBtn">
                            <ion-icon name="add-outline"></ion-icon>
                            Create File
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Folder Modal -->
    <div class="modal-overlay" id="folderModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Create New Folder</h3>
                <button onclick="closeFolderModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="createFolderForm">
                    <div class="form-group">
                        <label class="form-label">Folder Name</label>
                        <input type="text" name="folder_name" class="form-control" placeholder="my-folder" required oninput="validateFolderNameInput()">
                        <div class="form-text" id="folderNameHint">Enter a unique folder name</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Parent Folder</label>
                        <select name="parent_folder" class="form-control">
                            <option value="root">Root</option>
                            <?php foreach ($folders as $folder): ?>
                                <option value="<?php echo htmlspecialchars($folder['folder']); ?>">
                                    <?php echo htmlspecialchars($folder['folder']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeFolderModal()">Cancel</button>
                        <button type="submit" name="create_folder" class="btn btn-primary" id="createFolderBtn">
                            <ion-icon name="add-outline"></ion-icon>
                            Create Folder
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Upload Files</h3>
                <button onclick="closeUploadModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="form-label">Select Files</label>
                        <input type="file" name="files[]" class="form-control" multiple>
                        <div class="form-text">Note: Files with existing names in the destination folder will be skipped</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Destination Folder</label>
                        <select name="upload_folder" class="form-control">
                            <option value="root">Root</option>
                            <?php foreach ($folders as $folder): ?>
                                <option value="<?php echo htmlspecialchars($folder['folder']); ?>">
                                    <?php echo htmlspecialchars($folder['folder']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
                        <button type="submit" name="upload_files" class="btn btn-primary">
                            <ion-icon name="cloud-upload-outline"></ion-icon>
                            Upload Files
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Collaborator Modal -->
    <div class="modal-overlay" id="collaboratorModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Add Collaborator</h3>
                <button onclick="closeCollaboratorModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">User ID</label>
                        <input type="text" name="collaborator_id" class="form-control" placeholder="Enter user ID" required>
                        <small style="color: var(--gray); font-size: 0.875rem; margin-top: 0.5rem; display: block;">
                            <i class="fas fa-info-circle"></i> Enter the user's unique ID
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Permission Level</label>
                        <select name="permission_level" class="form-control" required>
                            <option value="read">Read Only</option>
                            <option value="write" selected>Read & Write</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCollaboratorModal()">Cancel</button>
                        <button type="submit" name="add_collaborator" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i>
                            Send Invitation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Share Repository Modal -->
    <div class="modal-overlay" id="shareModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Share Repository</h3>
                <button onclick="closeShareModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Generate Share Link</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <select name="share_permission" class="form-control">
                                <option value="read">Read Only</option>
                                <option value="write">Read & Write</option>
                            </select>
                            <select name="expiry" class="form-control">
                                <option value="1day">1 Day</option>
                                <option value="7days">7 Days</option>
                                <option value="30days">30 Days</option>
                                <option value="never" selected>Never</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeShareModal()">Cancel</button>
                        <button type="submit" name="generate_share_link" class="btn btn-primary">
                            <ion-icon name="link-outline"></ion-icon>
                            Generate Link
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal-overlay" id="settingsModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Repository Settings</h3>
                <button onclick="closeSettingsModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Repository Name</label>
                        <input type="text" name="repo_name" class="form-control" value="<?php echo htmlspecialchars($repoId); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Repository description"><?php echo htmlspecialchars($repoSettings['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeSettingsModal()">Cancel</button>
                        <button type="submit" name="update_settings" class="btn btn-primary">
                            <ion-icon name="save-outline"></ion-icon>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Premium Danger Zone Modal -->
    <div class="modal-overlay" id="dangerZoneModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Danger Zone</h3>
                <button onclick="closeDangerZoneModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div class="danger-section download-section">
                    <h4>Download Repository</h4>
                    <p>Download this repository as a ZIP file containing all files and folder structure.</p>
                    <button class="btn btn-primary" onclick="downloadRepo()">
                        <ion-icon name="download-outline"></ion-icon>
                        Download as ZIP
                    </button>
                </div>
                
                <div class="danger-section">
                    <h4>Delete Repository</h4>
                    <p>Once you delete a repository, there is no going back. Please be certain.</p>
                    <form method="POST" id="deleteRepoForm">
                        <div class="form-group">
                            <label class="form-label">Type the repository name to confirm:</label>
                            <input type="text" name="confirm_repo_name" class="form-control" placeholder="<?php echo htmlspecialchars($repoId); ?>" required>
                        </div>
                        <button type="submit" name="delete_repository" class="btn btn-danger">
                            <ion-icon name="trash-outline"></ion-icon>
                            Delete this repository
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteConfirmModal">
        <div class="modal glass">
            <div class="modal-header">
                <h3>Confirm Deletion</h3>
                <button onclick="closeDeleteConfirmModal()" style="background: none; border: none; font-size: 1.25rem; color: var(--gray); cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <p id="deleteConfirmMessage">Are you sure you want to delete this item?</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteConfirmModal()">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <ion-icon name="trash-outline"></ion-icon>
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Premium Theme Toggle Button -->
    <button class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon"></i>
    </button>

    <!-- Advanced Animation System -->
    <script>
        // Premium Animation System
        class PremiumAnimations {
            constructor() {
                this.initParticles();
                this.initFloatingElements();
                this.initScrollAnimations();
                this.initHoverEffects();
                this.initMatrixRain();
                this.initThemeSystem();
            }

            // Particle Background System
            initParticles() {
                const particlesContainer = document.getElementById('particles');
                const particleCount = 50;
                
                for (let i = 0; i < particleCount; i++) {
                    const particle = document.createElement('div');
                    particle.className = 'particle';
                    
                    // Random size between 2-8px
                    const size = Math.random() * 6 + 2;
                    particle.style.width = `${size}px`;
                    particle.style.height = `${size}px`;
                    
                    // Random position
                    particle.style.left = `${Math.random() * 100}%`;
                    
                    // Random animation
                    const duration = Math.random() * 20 + 10;
                    const delay = Math.random() * 20;
                    particle.style.animation = `float-particle ${duration}s infinite linear ${delay}s`;
                    
                    // Random opacity
                    particle.style.opacity = Math.random() * 0.1 + 0.05;
                    
                    // Random color based on theme
                    const colors = ['#6366f1', '#8b5cf6', '#f59e0b', '#10b981'];
                    const randomColor = colors[Math.floor(Math.random() * colors.length)];
                    particle.style.background = randomColor;
                    
                    particlesContainer.appendChild(particle);
                }
            }

            // Matrix Rain Effect
            initMatrixRain() {
                const canvas = document.getElementById('matrixRain');
                const ctx = canvas.getContext('2d');
                
                // Set canvas size
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
                
                // Matrix characters
                const chars = "01";
                const charSize = 14;
                const columns = canvas.width / charSize;
                const drops = [];
                
                // Initialize drops
                for (let i = 0; i < columns; i++) {
                    drops[i] = 1;
                }
                
                function draw() {
                    // Semi-transparent black background for trail effect
                    ctx.fillStyle = 'rgba(0, 0, 0, 0.04)';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    
                    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--primary') || '#00ff41';
                    ctx.font = `${charSize}px monospace`;
                    
                    for (let i = 0; i < drops.length; i++) {
                        const text = chars.charAt(Math.floor(Math.random() * chars.length));
                        ctx.fillText(text, i * charSize, drops[i] * charSize);
                        
                        if (drops[i] * charSize > canvas.height && Math.random() > 0.975) {
                            drops[i] = 0;
                        }
                        drops[i]++;
                    }
                }
                
                // Only run in Matrix theme
                function checkThemeAndAnimate() {
                    if (document.body.classList.contains('theme-matrix')) {
                        draw();
                        requestAnimationFrame(checkThemeAndAnimate);
                    } else {
                        setTimeout(checkThemeAndAnimate, 100);
                    }
                }
                
                checkThemeAndAnimate();
                
                // Handle window resize
                window.addEventListener('resize', () => {
                    canvas.width = window.innerWidth;
                    canvas.height = window.innerHeight;
                });
            }

            // Theme System
            initThemeSystem() {
                const themeBtn = document.getElementById('themeSelectorBtn');
                const themeOptions = document.getElementById('themeOptions');
                const themeOptionsElements = document.querySelectorAll('.theme-option');
                
                themeBtn.addEventListener('click', () => {
                    themeOptions.classList.toggle('active');
                });
                
                themeOptionsElements.forEach(option => {
                    option.addEventListener('click', () => {
                        const theme = option.getAttribute('data-theme');
                        
                        // Remove all theme classes
                        document.body.classList.remove('theme-default', 'theme-cyberpunk', 'theme-matrix', 'theme-ocean', 'theme-sunset');
                        
                        // Add selected theme
                        document.body.classList.add(`theme-${theme}`);
                        
                        // Update active option
                        themeOptionsElements.forEach(opt => opt.classList.remove('active'));
                        option.classList.add('active');
                        
                        // Close theme options
                        themeOptions.classList.remove('active');
                        
                        // Save theme preference
                        localStorage.setItem('preferred-theme', theme);
                    });
                });
                
                // Load saved theme
                const savedTheme = localStorage.getItem('preferred-theme') || 'default';
                document.body.classList.add(`theme-${savedTheme}`);
                themeOptionsElements.forEach(opt => {
                    if (opt.getAttribute('data-theme') === savedTheme) {
                        opt.classList.add('active');
                    }
                });
            }

            // Floating Elements Animation
            initFloatingElements() {
                const elements = document.querySelectorAll('.floating-element');
                elements.forEach((element, index) => {
                    // Add random variations to animations
                    const duration = 20 + (index * 5);
                    const delay = index * 3;
                    element.style.animation = `float ${duration}s infinite ease-in-out ${delay}s`;
                });
            }

            // Scroll Animations
            initScrollAnimations() {
                const observerOptions = {
                    threshold: 0.1,
                    rootMargin: '0px 0px -50px 0px'
                };

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.style.animationPlayState = 'running';
                            entry.target.classList.add('animate-in');
                        }
                    });
                }, observerOptions);

                // Observe all animated elements
                document.querySelectorAll('.stat-card, .file-browser, .chart-card, .collaborator-card').forEach(el => {
                    observer.observe(el);
                });
            }

            // Enhanced Hover Effects
            initHoverEffects() {
                // Add ripple effect to buttons
                document.addEventListener('mouseover', (e) => {
                    if (e.target.classList.contains('btn')) {
                        this.createRipple(e);
                    }
                });

                // Enhanced card hover effects
                document.querySelectorAll('.stat-card, .file-item, .folder-item, .collaborator-card').forEach(card => {
                    card.addEventListener('mouseenter', () => {
                        card.style.transform = 'translateY(-8px) scale(1.02)';
                    });
                    
                    card.addEventListener('mouseleave', () => {
                        card.style.transform = '';
                    });
                });

                // 3D tilt effect
                document.querySelectorAll('.card-3d').forEach(card => {
                    card.addEventListener('mousemove', (e) => {
                        const rect = card.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;
                        
                        const centerX = rect.width / 2;
                        const centerY = rect.height / 2;
                        
                        const rotateY = (x - centerX) / 25;
                        const rotateX = (centerY - y) / 25;
                        
                        card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1.02, 1.02, 1.02)`;
                    });
                    
                    card.addEventListener('mouseleave', () => {
                        card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) scale3d(1, 1, 1)';
                    });
                });
            }

            createRipple(event) {
                const button = event.currentTarget;
                const circle = document.createElement('span');
                const diameter = Math.max(button.clientWidth, button.clientHeight);
                const radius = diameter / 2;

                circle.style.width = circle.style.height = `${diameter}px`;
                circle.style.left = `${event.clientX - button.offsetLeft - radius}px`;
                circle.style.top = `${event.clientY - button.offsetTop - radius}px`;
                circle.classList.add('ripple');

                const ripple = button.getElementsByClassName('ripple')[0];
                if (ripple) {
                    ripple.remove();
                }

                button.appendChild(circle);
            }
        }

        // Initialize Premium Animations
        document.addEventListener('DOMContentLoaded', () => {
            new PremiumAnimations();
        });
    </script>

    <!-- Core Functionality Script -->
    <script>
        // Enhanced file name validation
        function validateFileName(fileName, folder) {
            if (!fileName || fileName.trim() === '') {
                return 'File name is required';
            }
            
            // Check if file name contains invalid characters
            const invalidChars = /[<>:"\/\\|?*\x00-\x1F]/;
            if (invalidChars.test(fileName)) {
                return 'File name contains invalid characters: < > : " / \\ | ? *';
            }
            
            // Check if file name is too long
            if (fileName.length > 255) {
                return 'File name is too long (max 255 characters)';
            }
            
            // Check for reserved names
            const reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
            const nameWithoutExt = fileName.split('.')[0].toUpperCase();
            if (reservedNames.includes(nameWithoutExt)) {
                return 'File name is reserved by the system';
            }
            
            // Check for . and .. 
            if (fileName === '.' || fileName === '..') {
                return 'File name cannot be "." or ".."';
            }
            
            return null; // No errors
        }

        // Enhanced folder name validation
        function validateFolderName(folderName) {
            if (!folderName || folderName.trim() === '') {
                return 'Folder name is required';
            }
            
            // Check if folder name contains invalid characters
            const invalidChars = /[<>:"\/\\|?*\x00-\x1F]/;
            if (invalidChars.test(folderName)) {
                return 'Folder name contains invalid characters: < > : " / \\ | ? *';
            }
            
            // Check if folder name is too long
            if (folderName.length > 255) {
                return 'Folder name is too long (max 255 characters)';
            }
            
            // Check for reserved names
            const reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
            if (reservedNames.includes(folderName.toUpperCase())) {
                return 'Folder name is reserved by the system';
            }
            
            // Check for . and .. 
            if (folderName === '.' || folderName === '..') {
                return 'Folder name cannot be "." or ".."';
            }
            
            return null; // No errors
        }

        // Real-time file name validation
        function validateFileNameInput() {
            const fileNameInput = document.getElementById('file_name');
            const fileTypeHint = document.getElementById('fileTypeHint');
            const createFileBtn = document.getElementById('createFileBtn');
            const fileName = fileNameInput.value.trim();
            
            const validationError = validateFileName(fileName, 'root');
            
            if (validationError) {
                fileTypeHint.className = 'form-text error';
                fileTypeHint.textContent = validationError;
                fileNameInput.className = 'form-control error';
                createFileBtn.disabled = true;
            } else {
                fileTypeHint.className = 'form-text';
                autoDetectFileType();
                fileNameInput.className = 'form-control';
                createFileBtn.disabled = false;
            }
        }

        // Real-time folder name validation
        function validateFolderNameInput() {
            const folderNameInput = document.querySelector('input[name="folder_name"]');
            const folderNameHint = document.getElementById('folderNameHint');
            const createFolderBtn = document.getElementById('createFolderBtn');
            const folderName = folderNameInput.value.trim();
            
            const validationError = validateFolderName(folderName);
            
            if (validationError) {
                folderNameHint.className = 'form-text error';
                folderNameHint.textContent = validationError;
                folderNameInput.className = 'form-control error';
                createFolderBtn.disabled = true;
            } else {
                folderNameHint.className = 'form-text';
                folderNameHint.textContent = 'Enter a unique folder name';
                folderNameInput.className = 'form-control';
                createFolderBtn.disabled = false;
            }
        }

        // Enhanced form submission with validation
        document.getElementById('createFileForm')?.addEventListener('submit', function(e) {
            const fileName = document.getElementById('file_name').value.trim();
            const folder = document.querySelector('select[name="folder"]').value;
            
            const validationError = validateFileName(fileName, folder);
            if (validationError) {
                e.preventDefault();
                alert('Error: ' + validationError);
                return false;
            }
        });

        // Enhanced folder form submission
        document.getElementById('createFolderForm')?.addEventListener('submit', function(e) {
            const folderName = document.querySelector('input[name="folder_name"]').value.trim();
            const parentFolder = document.querySelector('select[name="parent_folder"]').value;
            
            const validationError = validateFolderName(folderName);
            if (validationError) {
                e.preventDefault();
                alert('Error: ' + validationError);
                return false;
            }
        });

        // Modal functions
        function openFileModal() {
            document.getElementById('fileModal').classList.add('active');
            setTimeout(() => {
                validateFileNameInput();
            }, 100);
        }

        function closeFileModal() {
            document.getElementById('fileModal').classList.remove('active');
        }

        function openFolderModal() {
            document.getElementById('folderModal').classList.add('active');
            setTimeout(() => {
                validateFolderNameInput();
            }, 100);
        }

        function closeFolderModal() {
            document.getElementById('folderModal').classList.remove('active');
        }

        function openUploadModal() {
            document.getElementById('uploadModal').classList.add('active');
        }

        function closeUploadModal() {
            document.getElementById('uploadModal').classList.remove('active');
        }

        function openCollaboratorModal() {
            document.getElementById('collaboratorModal').classList.add('active');
        }

        function closeCollaboratorModal() {
            document.getElementById('collaboratorModal').classList.remove('active');
        }

        function openShareModal() {
            document.getElementById('shareModal').classList.add('active');
        }

        function closeShareModal() {
            document.getElementById('shareModal').classList.remove('active');
        }

        function openSettingsModal() {
            document.getElementById('settingsModal').classList.add('active');
        }

        function closeSettingsModal() {
            document.getElementById('settingsModal').classList.remove('active');
        }

        function openDangerZoneModal() {
            document.getElementById('dangerZoneModal').classList.add('active');
        }

        function closeDangerZoneModal() {
            document.getElementById('dangerZoneModal').classList.remove('active');
        }

        function openDeleteConfirmModal() {
            document.getElementById('deleteConfirmModal').classList.add('active');
        }

        function closeDeleteConfirmModal() {
            document.getElementById('deleteConfirmModal').classList.remove('active');
        }

        // File operations
        function openFile(fileName, fileType) {
            window.location.href = `code_editor.php?repo=<?php echo $repoId; ?>&file=${fileName}&type=${fileType}`;
        }

        function openFolder(folderPath) {
            alert(`Opening folder: ${folderPath}`);
        }

        function deleteFile(fileId, fileName) {
            document.getElementById('deleteConfirmMessage').textContent = `Are you sure you want to delete the file "${fileName}"?`;
            document.getElementById('confirmDeleteBtn').onclick = function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="file_id" value="${fileId}">
                                 <input type="hidden" name="delete_file" value="1">`;
                document.body.appendChild(form);
                form.submit();
            };
            openDeleteConfirmModal();
        }

        function deleteFolder(folderPath, folderName) {
            document.getElementById('deleteConfirmMessage').textContent = `Are you sure you want to delete the folder "${folderName}" and all its contents?`;
            document.getElementById('confirmDeleteBtn').onclick = function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="folder_path" value="${folderPath}">
                                 <input type="hidden" name="delete_folder" value="1">`;
                document.body.appendChild(form);
                form.submit();
            };
            openDeleteConfirmModal();
        }

        function downloadFile(fileName) {
            alert(`Downloading file: ${fileName}`);
        }

        // Copy share link to clipboard
        function copyShareLink(token) {
            const url = `http://<?php echo $_SERVER['HTTP_HOST']; ?>/codesphere/share_view.php?token=${token}`;
            navigator.clipboard.writeText(url).then(() => {
                // Show success message
                const originalText = event.target.innerHTML;
                event.target.innerHTML = '<ion-icon name="checkmark-outline"></ion-icon>';
                event.target.style.color = 'var(--success)';
                
                setTimeout(() => {
                    event.target.innerHTML = originalText;
                    event.target.style.color = '';
                }, 2000);
            });
        }

        // Folder toggle functionality
        function toggleFolder(folderPath, event) {
            event.stopPropagation();
            const folderItem = event.target.closest('.folder-item');
            const arrowIcon = folderItem.querySelector('.folder-arrow i');
            const arrowContainer = folderItem.querySelector('.folder-arrow');
            
            if (arrowContainer.classList.contains('no-contents')) {
                return;
            }
            
            if (arrowIcon.classList.contains('fa-chevron-right')) {
                arrowIcon.classList.remove('fa-chevron-right');
                arrowIcon.classList.add('fa-chevron-down');
                loadFolderContents(folderPath, folderItem);
            } else {
                arrowIcon.classList.remove('fa-chevron-down');
                arrowIcon.classList.add('fa-chevron-right');
                hideFolderContents(folderItem);
            }
        }

        function loadFolderContents(folderPath, folderItem) {
            fetch(`load_folder.php?repo=<?php echo $repoId; ?>&folder=${encodeURIComponent(folderPath)}`)
                .then(response => response.text())
                .then(html => {
                    let contentsContainer = folderItem.nextElementSibling;
                    if (!contentsContainer || !contentsContainer.classList.contains('folder-contents')) {
                        contentsContainer = document.createElement('div');
                        contentsContainer.className = 'folder-contents';
                        folderItem.parentNode.insertBefore(contentsContainer, folderItem.nextSibling);
                    }
                    contentsContainer.innerHTML = html;
                })
                .catch(error => console.error('Error loading folder contents:', error));
        }

        function hideFolderContents(folderItem) {
            const contentsContainer = folderItem.nextElementSibling;
            if (contentsContainer && contentsContainer.classList.contains('folder-contents')) {
                contentsContainer.remove();
            }
        }

        // Download repository as ZIP
        function downloadRepo() {
            if (confirm('Are you sure you want to download this repository as a ZIP file?')) {
                window.location.href = `download_repo.php?repo=<?php echo $repoId; ?>`;
            }
        }

        // Auto-detect file type
        function autoDetectFileType() {
            const fileName = document.getElementById('file_name').value;
            const fileTypeSelect = document.getElementById('file_type');
            const fileTypeHint = document.getElementById('fileTypeHint');
            
            if (fileName) {
                const extension = fileName.split('.').pop().toLowerCase();
                const fileTypes = {
                    'php': 'PHP',
                    'js': 'JavaScript',
                    'html': 'HTML',
                    'css': 'CSS',
                    'py': 'Python',
                    'java': 'Java',
                    'json': 'JSON',
                    'md': 'Markdown',
                    'txt': 'Text'
                };
                
                if (fileTypes[extension]) {
                    fileTypeSelect.value = extension;
                    fileTypeHint.textContent = `Detected file type: ${fileTypes[extension]}`;
                } else {
                    fileTypeSelect.value = 'txt';
                    fileTypeHint.textContent = 'File type set to Text (default)';
                }
            } else {
                fileTypeHint.textContent = 'File type will be auto-detected from extension';
            }
        }

        // Tab navigation
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const tabId = this.getAttribute('data-tab');
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(`${tabId}-tab`).classList.add('active');
                
                document.querySelectorAll('.nav-item').forEach(item => {
                    item.classList.remove('active');
                });
                document.querySelector(`.nav-item[data-tab="${tabId}"]`).classList.add('active');
            });
        });

        // Sidebar navigation
        document.querySelectorAll('.nav-item[data-tab]').forEach(item => {
            item.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelector(`.tab[data-tab="${tabId}"]`).classList.add('active');
                
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(`${tabId}-tab`).classList.add('active');
                
                document.querySelectorAll('.nav-item').forEach(i => {
                    i.classList.remove('active');
                });
                this.classList.add('active');
                
                if (window.innerWidth <= 1024) {
                    document.getElementById('sidebar').classList.remove('active');
                }
            });
        });

        // Sidebar toggle
        document.getElementById('sidebar-toggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const container = document.getElementById('main-container');
            const icon = this.querySelector('i');
            
            sidebar.classList.toggle('collapsed');
            container.classList.toggle('sidebar-collapsed');
            
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
            } else {
                icon.className = 'fas fa-chevron-left';
            }
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.getElementById('mobileMenuToggle');
            
            if (window.innerWidth <= 1024 && 
                sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) && 
                !mobileToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Close modals on outside click
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });

        // Theme toggle
        const themeToggle = document.getElementById('themeToggle');
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            const icon = themeToggle.querySelector('i');
            if (document.body.classList.contains('dark-mode')) {
                icon.className = 'fas fa-sun';
                localStorage.setItem('dark-mode', 'true');
            } else {
                icon.className = 'fas fa-moon';
                localStorage.setItem('dark-mode', 'false');
            }
        });

        // Set initial theme
        if (localStorage.getItem('dark-mode') === 'true') {
            document.body.classList.add('dark-mode');
            themeToggle.querySelector('i').className = 'fas fa-sun';
        }

        // Charts
        document.addEventListener('DOMContentLoaded', function() {
            // File Type Chart
            const fileTypeCtx = document.getElementById('fileTypeChart').getContext('2d');
            const fileTypeChart = new Chart(fileTypeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_keys($fileStats)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($fileStats)); ?>,
                        backgroundColor: [
                            '#6366f1', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444', '#3b82f6'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Commit Activity Chart
            const commitCtx = document.getElementById('commitActivityChart').getContext('2d');
            const commitActivityChart = new Chart(commitCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($commitActivity, 'date')); ?>,
                    datasets: [{
                        label: 'Commits',
                        data: <?php echo json_encode(array_column($commitActivity, 'count')); ?>,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>