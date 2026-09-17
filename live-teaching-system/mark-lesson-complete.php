<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_verify();

$studentId = (int)$_SESSION['user']['id'];
$lessonId  = (int)($_POST['lesson_id'] ?? 0);
$classId   = (int)($_POST['class_id']  ?? 0);
$fromRaw   = (string)($_POST['from']    ?? '');

if ($lessonId <= 0 || $classId <= 0) {
    http_response_code(422);
    exit('Missing lesson or class ID.');
}

$connection = getDbConnection();

/* Verify enrolment */
$chk = $connection->prepare("
    SELECT id FROM enrollments
     WHERE student_id = ? AND class_id = ? AND payment_status = 'paid'
");
$chk->bind_param('ii', $studentId, $classId);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    $chk->close();
    http_response_code(403);
    exit('You are not enrolled in this class.');
}
$chk->close();

/* Verify lesson belongs to class */
$chk = $connection->prepare("SELECT id FROM course_lessons WHERE id = ? AND class_id = ?");
$chk->bind_param('ii', $lessonId, $classId);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    $chk->close();
    http_response_code(404);
    exit('Lesson not found in this class.');
}
$chk->close();

/* Upsert completion */
$up = $connection->prepare("
    INSERT INTO lesson_progress
        (student_id, class_id, lesson_id, status, completed_at)
    VALUES (?, ?, ?, 'completed', NOW())
    ON DUPLICATE KEY UPDATE
        status = 'completed',
        completed_at = IFNULL(completed_at, NOW())
");
$up->bind_param('iii', $studentId, $classId, $lessonId);
$up->execute();
$up->close();

/* Preserve ?from= through the redirect */
$allowedFroms = ['my-classes', 'class', 'teacher-class', 'content', 'student-home', 'dashboard', 'class-list'];
$fromSuffix = '';
if (in_array($fromRaw, $allowedFroms, true)) {
    $fromSuffix = '&from=' . urlencode($fromRaw);
}

header("Location: course-view.php?id=$classId&lesson=$lessonId$fromSuffix");
exit;