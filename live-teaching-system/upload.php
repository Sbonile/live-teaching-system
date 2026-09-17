<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/csrf.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'teacher') {
    header('Location: ../../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../teacher/classes.php');
    exit;
}

csrf_verify();

$connection = getDbConnection();
$teacherId  = (int)$_SESSION['user']['id'];

/* ---------- Input ---------- */
$classId  = (int)($_POST['class_id']  ?? 0);
$lessonId = (int)($_POST['lesson_id'] ?? 0);
$title    = trim((string)($_POST['title'] ?? ''));
$desc     = trim((string)($_POST['description'] ?? ''));
$category = (string)($_POST['category'] ?? 'other');
$published = isset($_POST['is_published']) && $_POST['is_published'] == '1' ? 1 : 0;

/* ---------- Validate ---------- */
$validCategories = ['past_paper','memo','worksheet','notes','slides','textbook','other'];
if (!in_array($category, $validCategories, true)) $category = 'other';

if ($classId <= 0) {
    header("Location: ../../teacher/course-content.php?class_id=0&err=" . urlencode("Missing class ID."));
    exit;
}

/* Verify teacher owns this class */
$own = $connection->prepare("SELECT id FROM live_classes WHERE id = ? AND teacher_id = ?");
$own->bind_param('ii', $classId, $teacherId);
$own->execute();
$owns = $own->get_result()->fetch_assoc();
$own->close();

if (!$owns) {
    header("Location: ../../teacher/classes.php?err=" . urlencode("Class not found."));
    exit;
}

/* If lesson_id provided, verify it belongs to this class */
if ($lessonId > 0) {
    $lk = $connection->prepare("SELECT id FROM course_lessons WHERE id = ? AND class_id = ?");
    $lk->bind_param('ii', $lessonId, $classId);
    $lk->execute();
    if (!$lk->get_result()->fetch_assoc()) {
        $lk->close();
        $lessonId = 0; // silently drop — don't fail the upload
    } else {
        $lk->close();
    }
}

if ($title === '') {
    $title = pathinfo($_FILES['file']['name'] ?? 'Document', PATHINFO_FILENAME);
}

/* ---------- Handle the file ---------- */
if (empty($_FILES['file']['tmp_name'])) {
    header("Location: ../../teacher/course-content.php?class_id=$classId&err=" . urlencode("No file uploaded."));
    exit;
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    header("Location: ../../teacher/course-content.php?class_id=$classId&err=" . urlencode("Upload error code " . $f['error']));
    exit;
}

$maxSize = 50 * 1024 * 1024; // 50 MB
if ($f['size'] > $maxSize) {
    header("Location: ../../teacher/course-content.php?class_id=$classId&err=" . urlencode("File exceeds 50 MB limit."));
    exit;
}

$origName = (string)$f['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowed  = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','csv','zip','odt','ods','odp'];
if (!in_array($ext, $allowed, true)) {
    header("Location: ../../teacher/course-content.php?class_id=$classId&err=" . urlencode("File type .$ext is not allowed."));
    exit;
}

/* Server-side MIME check — never trust the client */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']) ?: 'application/octet-stream';

/* Storage layout: uploads/documents/{class_id}/{random}.{ext} */
$baseDir = dirname(__DIR__, 2) . '/uploads/documents';
$classDir = $baseDir . '/' . $classId;
if (!is_dir($classDir)) {
    mkdir($classDir, 0775, true);
}

$storageName = bin2hex(random_bytes(12)) . '.' . $ext;
$dest = $classDir . '/' . $storageName;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    header("Location: ../../teacher/course-content.php?class_id=$classId&err=" . urlencode("Could not store file."));
    exit;
}

/* Relative path stored in DB (so it survives moves of the project root) */
$relPath = 'uploads/documents/' . $classId . '/' . $storageName;

/* ---------- Insert record ---------- */
$ins = $connection->prepare("
    INSERT INTO documents
        (class_id, lesson_id, teacher_id, title, description, category,
         original_name, storage_path, mime_type, size_bytes, is_published)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$lessonIdOrNull = $lessonId > 0 ? $lessonId : null;
$ins->bind_param(
    'iiissssssii',
    $classId, $lessonIdOrNull, $teacherId, $title, $desc, $category,
    $origName, $relPath, $mime, $f['size'], $published
);
$ins->execute();
$newId = (int)$ins->insert_id;
$ins->close();

header("Location: ../../teacher/course-content.php?class_id=$classId&msg=" . urlencode("Document \"$title\" uploaded."));
exit;