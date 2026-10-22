<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

$repoId = $_GET['repo'] ?? '';
$fileName = $_GET['file'] ?? '';

if (empty($repoId) || empty($fileName)) {
    die('Invalid parameters');
}

// Connect to repository database
$repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
if ($repoConn->connect_error) {
    die('Database connection failed');
}

// Get file content
$fileStmt = $repoConn->prepare("SELECT content, file_type FROM files WHERE file_name = ? AND file_type != 'folder'");
$fileStmt->bind_param("s", $fileName);
$fileStmt->execute();
$fileResult = $fileStmt->get_result();

if ($fileResult->num_rows > 0) {
    $fileData = $fileResult->fetch_assoc();
    $content = $fileData['content'];
    $fileType = $fileData['file_type'];
    
    if ($fileType === 'html') {
        header('Content-Type: text/html');
        echo $content;
    } else {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Preview: ' . htmlspecialchars($fileName) . '</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
                .preview-container { background: white; padding: 2rem; border-radius: 8px; }
                pre { background: #f8f9fa; padding: 1rem; border-radius: 4px; overflow-x: auto; }
            </style>
        </head>
        <body>
            <div class="preview-container">
                <h3>Preview: ' . htmlspecialchars($fileName) . '</h3>
                <p>This is a ' . htmlspecialchars($fileType) . ' file. Full rendering is only available for HTML files.</p>
                <pre>' . htmlspecialchars($content) . '</pre>
                <button onclick="window.close()">Close</button>
            </div>
        </body>
        </html>';
    }
} else {
    echo 'File not found';
}

$repoConn->close();
?>