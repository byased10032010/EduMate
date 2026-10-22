<?php
/**
 * Refresh Captcha AJAX Handler
 */
session_start();

header('Content-Type: application/json');

if (isset($_GET['captcha'])) {
    $_SESSION['captcha_code'] = $_GET['captcha'];
    echo json_encode(['success' => true, 'captcha' => $_GET['captcha']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
