<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';
$repoId = $_GET['repo'] ?? '';
$file = $_GET['file'] ?? '';
$fileType = $_GET['type'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'add_tab':
        if (!empty($file) && !empty($fileType)) {
            $fileKey = $file . '|' . $fileType;
            if (!isset($_SESSION['open_tabs'])) {
                $_SESSION['open_tabs'] = [];
            }
            if (!in_array($fileKey, $_SESSION['open_tabs'])) {
                $_SESSION['open_tabs'][] = $fileKey;
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing file parameters']);
        }
        break;
        
    case 'close_all_tabs':
        $_SESSION['open_tabs'] = [];
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>