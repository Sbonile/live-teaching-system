<?php
// classes/documents/list.php
session_start();
require_once '../../config/database.php';
header('Content-Type: application/json');

$connection = getDbConnection();
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$lessonId = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : 0;

$userId   = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
$userRole = $_SESSION['user']['role']      ?? null;

/* Verify access */
$classStmt = $connection->prepare("SELECT teacher_id FROM live_classes WHERE id = ?");
$classStmt->bind_param('i', $classId);
$classStmt->execute();
$class = $classStmt->get_result()->fetch_assoc();
$classStmt->close();

if (!$class) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'class not found']);
    exit;
}

$isTeacher = ($userRole === 'teacher' && $userId === (int)$class['teacher_id']);
$isAdmin   = ($userRole === 'admin');
$isStudentEnrolled = false;

if ($userRole === 'student' && $userId > 0) {
    $chk = $connection->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? AND payment_status = 'paid'");
    $chk->bind_param('ii', $userId, $classId);
    $chk->execute();
    $isStudentEnrolled = (bool)$chk->get_result()->fetch_assoc();
    $chk->close();
}

$sql = "SELECT id, lesson_id, title, description, category, original_name, mime_type, size_bytes, download_count, is_published, created_at
        FROM documents
        WHERE class_id = ?";
$types  = 'i';
$params = [$classId];

if ($lessonId > 0) {
    $sql .= " AND (lesson_id = ? OR lesson_id IS NULL)";
    $types .= 'i';
    $params[] = $lessonId;
}

if (!$isTeacher && !$isAdmin) {
    $sql .= " AND is_published = 1";
}

if (!$isTeacher && !$isAdmin && !$isStudentEnrolled) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not enrolled']);
    exit;
}

$sql .= " ORDER BY category, created_at DESC";

$stmt = $connection->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['ok' => true, 'documents' => $docs]);