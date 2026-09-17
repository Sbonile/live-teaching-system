<?php
// classes/documents/delete.php
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
$teacherId = (int)$_SESSION['user']['id'];
$docId     = (int)($_POST['id'] ?? 0);
$classId   = (int)($_POST['class_id'] ?? 0);

$stmt = $connection->prepare("
    SELECT d.* FROM documents d
    JOIN live_classes c ON c.id = d.class_id
    WHERE d.id = ? AND c.teacher_id = ?
");
$stmt->bind_param('ii', $docId, $teacherId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    header("Location: ../../teacher/course-content.php?class_id=$classId&err=" . urlencode("Document not found."));
    exit;
}

/* Remove from disk first */
$path = dirname(__DIR__, 2) . '/' . ltrim($doc['storage_path'], '/');
if (is_file($path)) {
    @unlink($path);
}

/* Then remove the record */
$del = $connection->prepare("DELETE FROM documents WHERE id = ?");
$del->bind_param('i', $docId);
$del->execute();
$del->close();

header("Location: ../../teacher/course-content.php?class_id=$classId&msg=" . urlencode("Document deleted."));
exit;