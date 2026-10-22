<?php
/**
 * EduMate - Student: One-click enroll from course preview
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('student');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: courses.php');
    exit;
}
$token = $_POST['csrf_token'] ?? '';
$cid   = (int)($_POST['course_id'] ?? 0);
if (!csrf_check(is_string($token) ? $token : null) || $cid <= 0) {
    flash_set('error', 'Invalid request.');
    header('Location: courses.php');
    exit;
}
$uid = (int)current_user()['id'];
try {
    db()->prepare('INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (:u, :c)')
        ->execute([':u' => $uid, ':c' => $cid]);
    record_activity($uid, 'fa-book-open', 'Enrolled in a new course', null);
    flash_set('success', 'Enrolled!');
} catch (Throwable $e) {
    flash_set('error', 'Could not enroll.');
}
header('Location: course.php?id=' . $cid);
exit;
