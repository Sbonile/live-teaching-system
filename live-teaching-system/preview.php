<?php
session_start();
require_once '../../config/database.php';

$connection = getDbConnection();
$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($docId <= 0) {
    http_response_code(400);
    exit('Missing document ID.');
}

/* Load document + owning class */
$stmt = $connection->prepare("
    SELECT d.*, c.teacher_id
    FROM documents d
    JOIN live_classes c ON c.id = d.class_id
    WHERE d.id = ?
");
$stmt->bind_param('i', $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

/* ---------- Authorisation ---------- */
$userId   = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
$userRole = $_SESSION['user']['role']      ?? null;

$isTeacher = ($userRole === 'teacher' && $userId === (int)$doc['teacher_id']);
$isAdmin   = ($userRole === 'admin');
$isEnrolled = false;

if ($userRole === 'student' && $userId > 0) {
    $chk = $connection->prepare("
        SELECT id FROM enrollments
         WHERE student_id = ? AND class_id = ? AND payment_status = 'paid'
    ");
    $chk->bind_param('ii', $userId, $doc['class_id']);
    $chk->execute();
    $isEnrolled = (bool)$chk->get_result()->fetch_assoc();
    $chk->close();
}

/* Unpublished only visible to staff */
if (!$doc['is_published'] && !$isTeacher && !$isAdmin) {
    http_response_code(404);
    exit('Document not found.');
}

/* Students must be enrolled */
if (!$isTeacher && !$isAdmin && !$isEnrolled) {
    http_response_code(403);
    exit('You are not enrolled in this class.');
}

/* ---------- Serve inline ---------- */
$path = dirname(__DIR__, 2) . '/' . ltrim($doc['storage_path'], '/');
if (!is_file($path)) {
    http_response_code(410);
    exit('File missing on disk.');
}

/* Only allow inline rendering for safe types. Anything else forces download. */
$inlineSafe = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'text/plain', 'text/csv',
];

$disposition = in_array($doc['mime_type'], $inlineSafe, true) ? 'inline' : 'attachment';

header('Content-Type: ' . $doc['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

/* Stream in chunks */
$fp = fopen($path, 'rb');
while (!feof($fp)) {
    echo fread($fp, 1024 * 64);
    flush();
}
fclose($fp);
exit;