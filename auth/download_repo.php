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
if (empty($repoId)) {
    die('Repository not specified');
}

// Create a temporary directory with proper permissions
$tempDir = sys_get_temp_dir() . '/codesphere_' . uniqid() . '_' . $repoId;
if (!mkdir($tempDir, 0755, true)) {
    die('Failed to create temporary directory');
}

try {
    // Connect to repository database
    $repoConn = new mysqli(DB_HOST, DB_USER, DB_PASS, $repoId);
    if ($repoConn->connect_error) {
        throw new Exception('Database connection failed: ' . $repoConn->connect_error);
    }

    // Get all files (excluding folders)
    $filesStmt = $repoConn->prepare("SELECT * FROM files WHERE file_type != 'folder'");
    if (!$filesStmt) {
        throw new Exception('Failed to prepare files statement: ' . $repoConn->error);
    }
    
    $filesStmt->execute();
    $filesResult = $filesStmt->get_result();

    $fileCount = 0;
    $errorCount = 0;

    // Create directory structure and files
    while ($file = $filesResult->fetch_assoc()) {
        try {
            // Handle folder paths - replace 'root' with empty string
            $folderPath = $file['folder'] === 'root' ? '' : $file['folder'];
            $filePath = $tempDir . '/' . $folderPath . '/' . $file['file_name'];
            $dirPath = dirname($filePath);
            
            // Create directory if it doesn't exist
            if (!is_dir($dirPath)) {
                if (!mkdir($dirPath, 0755, true)) {
                    throw new Exception("Failed to create directory: $dirPath");
                }
            }
            
            // Get file content from the file's table
            $fileTableName = preg_replace('/[^a-zA-Z0-9_]/', '_', $file['file_name']);
            $contentStmt = $repoConn->prepare("SELECT content FROM `$fileTableName` ORDER BY version DESC LIMIT 1");
            
            if ($contentStmt) {
                $contentStmt->execute();
                $contentResult = $contentStmt->get_result();
                if ($contentRow = $contentResult->fetch_assoc()) {
                    $content = $contentRow['content'] ?? '';
                    if (file_put_contents($filePath, $content) === false) {
                        throw new Exception("Failed to write file: $filePath");
                    }
                    $fileCount++;
                } else {
                    // Create empty file if no content
                    file_put_contents($filePath, '');
                    $fileCount++;
                }
                $contentStmt->close();
            } else {
                // Create empty file if table doesn't exist
                file_put_contents($filePath, '');
                $fileCount++;
            }
        } catch (Exception $e) {
            error_log("Error processing file {$file['file_name']}: " . $e->getMessage());
            $errorCount++;
            continue;
        }
    }

    $filesStmt->close();
    $repoConn->close();

    if ($fileCount === 0) {
        throw new Exception('No files found to download');
    }

    // Create ZIP file with proper error handling
    $zipFile = $tempDir . '.zip';
    $zip = new ZipArchive();
    $zipStatus = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    
    if ($zipStatus !== TRUE) {
        throw new Exception("Failed to create ZIP file (Error: $zipStatus)");
    }

    // Add files to ZIP
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($tempDir) + 1);
            
            // Replace backslashes with forward slashes for cross-platform compatibility
            $relativePath = str_replace('\\', '/', $relativePath);
            
            if (!$zip->addFile($filePath, $relativePath)) {
                throw new Exception("Failed to add file to ZIP: $relativePath");
            }
        }
    }

    if (!$zip->close()) {
        throw new Exception('Failed to close ZIP file');
    }

    if (!file_exists($zipFile)) {
        throw new Exception('ZIP file was not created');
    }

    // Send ZIP file to browser with proper headers
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $repoId . '.zip"');
    header('Content-Length: ' . filesize($zipFile));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Clean output buffering to prevent corruption
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($zipFile);

    // Clean up temporary files
    unlink($zipFile);
    deleteDirectory($tempDir);

} catch (Exception $e) {
    // Clean up on error
    if (isset($tempDir) && is_dir($tempDir)) {
        deleteDirectory($tempDir);
    }
    if (isset($zipFile) && file_exists($zipFile)) {
        unlink($zipFile);
    }
    
    // Log error and show user-friendly message
    error_log('Download error: ' . $e->getMessage());
    die('Error downloading repository: ' . $e->getMessage());
}

/**
 * Recursively delete a directory and its contents
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
?>