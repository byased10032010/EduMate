<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

$repoId = $_GET['repo'] ?? '';
$folderPath = $_GET['folder'] ?? '';

if (empty($repoId) || empty($folderPath)) {
    exit('Invalid parameters');
}

// Connect to repository database
$repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
if ($repoConn->connect_error) {
    exit('Database connection failed');
}

// Get ONLY direct subfolders and files in this specific folder
$files = [];
$subfolders = [];

// Get direct subfolders (children of the current folder, not the folder itself)
$subfoldersStmt = $repoConn->prepare("SELECT * FROM files WHERE folder = ? AND file_type = 'folder'");
$subfoldersStmt->bind_param("s", $folderPath);
$subfoldersStmt->execute();
$subfoldersResult = $subfoldersStmt->get_result();
while ($folder = $subfoldersResult->fetch_assoc()) {
    // Only include if it's a direct child (folder path matches exactly)
    if ($folder['folder'] === $folderPath) {
        $subfolders[] = $folder;
    }
}

// Get files in this specific folder only (direct children)
$filesStmt = $repoConn->prepare("SELECT * FROM files WHERE folder = ? AND file_type != 'folder'");
$filesStmt->bind_param("s", $folderPath);
$filesStmt->execute();
$filesResult = $filesStmt->get_result();
while ($file = $filesResult->fetch_assoc()) {
    $files[] = $file;
}

// Display contents
if (empty($subfolders) && empty($files)) {
    echo '<div class="empty-state" style="padding: 1rem; text-align: center; color: var(--gray);">';
    echo '    <i class="fas fa-folder-open" style="opacity: 0.5;"></i>';
    echo '    <p>This folder is empty</p>';
    echo '</div>';
} else {
    // Show only direct subfolders (not the parent folder itself)
    foreach ($subfolders as $subfolder) {
        // Check if subfolder has any direct files (not recursive)
        $hasSubContents = false;
        $checkSubStmt = $repoConn->prepare("SELECT COUNT(*) as count FROM files WHERE folder = ? AND file_type != 'folder'");
        $checkSubStmt->bind_param("s", $subfolder['folder']);
        $checkSubStmt->execute();
        $subContentsResult = $checkSubStmt->get_result()->fetch_assoc();
        $hasSubContents = $subContentsResult['count'] > 0;
        $checkSubStmt->close();
        
        echo '<div class="folder-item subfolder" onclick="openFolder(\'' . htmlspecialchars($subfolder['folder']) . '\')">';
        echo '    <div class="folder-arrow ' . ($hasSubContents ? 'has-contents' : 'no-contents') . '" ';
        echo '         onclick="' . ($hasSubContents ? "toggleFolder('" . htmlspecialchars($subfolder['folder']) . "', event)" : "event.stopPropagation()") . '">';
        echo '        <i class="fas fa-chevron-right"></i>';
        echo '    </div>';
        echo '    <i class="fas fa-folder folder-icon" style="color: #f59e0b;"></i>';
        echo '    <div class="folder-name">' . htmlspecialchars(basename($subfolder['folder'])) . '</div>';
        echo '    <div class="file-path">' . htmlspecialchars($subfolder['folder']) . '</div>';
        echo '    <div class="file-actions">';
        echo '        <button class="action-btn delete" onclick="event.stopPropagation(); deleteFolder(\'' . htmlspecialchars($subfolder['folder']) . '\', \'' . htmlspecialchars(basename($subfolder['folder'])) . '\')">';
        echo '            <ion-icon name="trash-outline"></ion-icon>';
        echo '        </button>';
        echo '    </div>';
        echo '</div>';
    }

    // Show direct files
    foreach ($files as $file) {
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
        
        echo '<div class="file-item subfile" onclick="openFile(\'' . htmlspecialchars($file['file_name']) . '\', \'' . htmlspecialchars($file['file_type']) . '\')">';
        echo '    <i class="' . $fileIcon . '"></i>';
        echo '    <div class="file-name">' . htmlspecialchars($file['file_name']) . '</div>';
        echo '    <div class="file-path">' . htmlspecialchars($file['folder']) . '</div>';
        echo '    <div class="file-actions">';
        echo '        <button class="action-btn download" onclick="event.stopPropagation(); downloadFile(\'' . htmlspecialchars($file['file_name']) . '\')">';
        echo '            <ion-icon name="download-outline"></ion-icon>';
        echo '        </button>';
        echo '        <button class="action-btn delete" onclick="event.stopPropagation(); deleteFile(' . $file['id'] . ', \'' . htmlspecialchars($file['file_name']) . '\')">';
        echo '            <ion-icon name="trash-outline"></ion-icon>';
        echo '        </button>';
        echo '    </div>';
        echo '</div>';
    }
}

$repoConn->close();
?>