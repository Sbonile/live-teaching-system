<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

/* CSRF — accepts header or query */
$token = $_SERVER['HTTP_X_CSRF_TOKEN']
      ?? $_POST['csrf_token']
      ?? $_GET['_csrf']
      ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$connection = getDbConnection();
$teacherId  = (int)$_SESSION['user']['id'];

/* ---------- Inputs ---------- */
$classId  = isset($_POST['class_id'])  ? (int)$_POST['class_id']  : 0;
$streamId = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
$title    = trim((string)($_POST['title'] ?? 'Live class recording'));
$duration = isset($_POST['duration_seconds']) ? (int)$_POST['duration_seconds'] : null;

if ($classId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing class_id']);
    exit;
}

/* ---------- Verify teacher owns the class ---------- */
$own = $connection->prepare("SELECT id, title FROM live_classes WHERE id = ? AND teacher_id = ?");
$own->bind_param('ii', $classId, $teacherId);
$own->execute();
$class = $own->get_result()->fetch_assoc();
$own->close();

if (!$class) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'class not found']);
    exit;
}

/* ---------- File validation ---------- */
if (empty($_FILES['recording']['tmp_name'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'no file uploaded']);
    exit;
}
$f = $_FILES['recording'];

if ($f['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'upload error code ' . $f['error']]);
    exit;
}

/* Cap at 2 GB (adjust if needed) */
$maxSize = 2 * 1024 * 1024 * 1024;
if ($f['size'] > $maxSize) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'file exceeds 2 GB limit']);
    exit;
}

/* Server-side MIME check — WebM or MP4 from MediaRecorder */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']) ?: 'application/octet-stream';
$allowedMimes = [
    'video/webm'  => 'webm',
    'video/mp4'   => 'mp4',
    'audio/webm'  => 'webm',   // in case only audio track was captured
];
if (!isset($allowedMimes[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'unsupported mime: ' . $mime]);
    exit;
}
$ext = $allowedMimes[$mime];

/* ---------- Store file ---------- */
$baseDir  = dirname(__DIR__, 2) . '/uploads/recordings';
$classDir = $baseDir . '/' . $classId;
if (!is_dir($classDir)) {
    mkdir($classDir, 0775, true);
}

$storageName = 'rec_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest        = $classDir . '/' . $storageName;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'could not store file']);
    exit;
}

$relPath = 'uploads/recordings/' . $classId . '/' . $storageName;

/* ---------- Insert into videos ---------- */
$stmt = $connection->prepare("
    INSERT INTO videos
        (class_id, teacher_id, title, video_url, video_type, kind,
         is_live_recording, duration, status, created_at, recorded_at, duration_seconds)
    VALUES (?, ?, ?, ?, 'upload', 'live_recording', 1, ?, 'active', NOW(), NOW(), ?)
");
$durationLabel = $duration ? gmdate('H:i:s', $duration) : null;
$stmt->bind_param('iisssi', $classId, $teacherId, $title, $relPath, $durationLabel, $duration);
$stmt->execute();
$videoId = (int)$stmt->insert_id;
$stmt->close();

/* ---------- Notify enrolled students ---------- */
if (function_exists('sendClassNotification')) {
    sendClassNotification(
        $classId,
        'live_stream',
        '📹 Class recording available',
        "The recording of '{$class['title']}' is now available. Watch it in your class videos.",
        '../classes/class.php?id=' . $classId
    );
}

echo json_encode([
    'ok'       => true,
    'video_id' => $videoId,
    'url'      => APP_BASE . '/' . $relPath,
    'size'     => $f['size'],
]);