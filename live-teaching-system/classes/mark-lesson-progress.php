<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';
header('Content-Type: application/json');

/* ---------- Auth ---------- */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

/* ---------- CSRF: header, POST, or query fallback ---------- */
$token = $_SERVER['HTTP_X_CSRF_TOKEN']
      ?? $_POST['csrf_token']
      ?? $_GET['_csrf']
      ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

/* ---------- Read JSON body ---------- */
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json']);
    exit;
}

$lessonId = (int)($input['lesson_id'] ?? 0);
$classId  = (int)($input['class_id']  ?? 0);
$position = (int)($input['position']  ?? 0);
$duration = (int)($input['duration']  ?? 0);

if (!$lessonId || !$classId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing params']);
    exit;
}

$studentId  = (int)$_SESSION['user']['id'];
$connection = getDbConnection();

/* ---------- Verify enrolment ---------- */
$chk = $connection->prepare("
    SELECT id FROM enrollments
     WHERE student_id = ? AND class_id = ? AND payment_status = 'paid'
");
$chk->bind_param('ii', $studentId, $classId);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    $chk->close();
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'not enrolled']);
    exit;
}
$chk->close();

/* ---------- Upsert progress ---------- */
$stmt = $connection->prepare("
    INSERT INTO lesson_progress
        (student_id, class_id, lesson_id, status, last_position, watched_duration)
    VALUES (?, ?, ?, 'in_progress', ?, ?)
    ON DUPLICATE KEY UPDATE
        last_position    = GREATEST(last_position, VALUES(last_position)),
        watched_duration = GREATEST(watched_duration, VALUES(watched_duration)),
        status           = IF(status = 'completed', 'completed', 'in_progress')
");
$stmt->bind_param('iiiii', $studentId, $classId, $lessonId, $position, $duration);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true]);