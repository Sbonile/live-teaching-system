<?php
// Start session FIRST - NO whitespace before this tag
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

// Check login FIRST before any output
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$teacherId = (int)$_SESSION['user']['id'];
$classId   = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$msg       = '';
$error     = '';

/* ---------- Ownership check helper ---------- */
function assert_teacher_owns_lesson(mysqli $conn, int $lessonId, int $teacherId): array {
    $stmt = $conn->prepare("
        SELECT l.*, c.teacher_id
        FROM course_lessons l
        JOIN live_classes c ON c.id = l.class_id
        WHERE l.id = ? AND c.teacher_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $lessonId, $teacherId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(404);
        exit('Lesson not found.');
    }
    return $row;
}

/* ---------- Get class and verify ownership ---------- */
$stmt = $connection->prepare("SELECT * FROM live_classes WHERE id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $classId, $teacherId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    header('Location: classes.php');
    exit;
}

/* =====================================================================
   POST HANDLERS
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    /* ---------- Toggle single lesson lock ---------- */
    if (isset($_POST['toggle_lesson_lock'])) {
        $lessonId = (int)$_POST['lesson_id'];
        $lockVal  = isset($_POST['lock_value']) && $_POST['lock_value'] == '1' ? 1 : 0;
        assert_teacher_owns_lesson($connection, $lessonId, $teacherId);

        $upd = $connection->prepare("UPDATE course_lessons SET is_locked = ? WHERE id = ? AND class_id = ?");
        $upd->bind_param('iii', $lockVal, $lessonId, $classId);
        $upd->execute();
        $upd->close();

        $msg = $lockVal ? "Lesson locked." : "Lesson unlocked.";
        header("Location: course-content.php?class_id=$classId&msg=" . urlencode($msg));
        exit;
    }

    /* ---------- Toggle class-wide sequential unlock ---------- */
    if (isset($_POST['toggle_sequential'])) {
        $enable = isset($_POST['sequential_unlock']) && $_POST['sequential_unlock'] == '1' ? 1 : 0;

        $upd = $connection->prepare("UPDATE live_classes SET sequential_unlock = ? WHERE id = ? AND teacher_id = ?");
        $upd->bind_param('iii', $enable, $classId, $teacherId);
        $upd->execute();
        $upd->close();

        $msg = $enable ? "Sequential unlock enabled." : "Sequential unlock disabled.";
        header("Location: course-content.php?class_id=$classId&msg=" . urlencode($msg));
        exit;
    }

    /* ---------- Add new lesson ---------- */
    if (isset($_POST['add_lesson'])) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $video_url = trim($_POST['video_url'] ?? '');
        $video_type = $_POST['video_type'] ?? 'youtube';
        $duration = trim($_POST['duration'] ?? '');
        $is_free_preview = isset($_POST['is_free_preview']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft';

        if ($video_type == 'upload' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] == 0) {
            $upload_dir = dirname(__DIR__) . '/uploads/videos/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'lesson_' . uniqid() . '_' . time() . '.mp4';
            $filepath = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $filepath)) {
                $video_url = 'uploads/videos/' . $filename;
            }
        }

        $orderStmt = $connection->prepare("SELECT MAX(order_position) as max_order FROM course_lessons WHERE class_id = ?");
        $orderStmt->bind_param('i', $classId);
        $orderStmt->execute();
        $orderPosition = (int)($orderStmt->get_result()->fetch_assoc()['max_order'] ?? 0) + 1;
        $orderStmt->close();

        $stmt = $connection->prepare("
            INSERT INTO course_lessons
                (class_id, teacher_id, title, description, video_url, video_type, duration, is_free_preview, status, order_position)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'iisssssisi',
            $classId, $teacherId, $title, $description, $video_url,
            $video_type, $duration, $is_free_preview, $status, $orderPosition
        );

        if ($stmt->execute()) {
            $msg = "Lesson added successfully!";
        } else {
            $error = "Failed to add lesson: " . $connection->error;
        }
        $stmt->close();
    }

    /* ---------- Update lesson order ---------- */
    if (isset($_POST['update_order'])) {
        $orders = $_POST['order'] ?? [];
        if (is_array($orders)) {
            $upd = $connection->prepare("UPDATE course_lessons SET order_position = ? WHERE id = ? AND class_id = ?");
            foreach ($orders as $lessonId => $order) {
                $lessonId = (int)$lessonId;
                $order = (int)$order;
                assert_teacher_owns_lesson($connection, $lessonId, $teacherId);
                $upd->bind_param('iii', $order, $lessonId, $classId);
                $upd->execute();
            }
            $upd->close();
        }
        $msg = "Lesson order updated!";
    }

    /* ---------- Update transcript ---------- */
    if (isset($_POST['update_transcript'])) {
        $lessonId = (int)$_POST['lesson_id'];
        $transcript = trim($_POST['transcript'] ?? '');
        assert_teacher_owns_lesson($connection, $lessonId, $teacherId);

        $stmt = $connection->prepare("UPDATE course_lessons SET transcript = ? WHERE id = ? AND class_id = ?");
        $stmt->bind_param('sii', $transcript, $lessonId, $classId);
        if ($stmt->execute()) $msg = "Transcript saved!";
        $stmt->close();
    }

    /* ---------- Delete lesson ---------- */
    if (isset($_POST['delete_lesson'])) {
        $lessonId = (int)$_POST['lesson_id'];
        assert_teacher_owns_lesson($connection, $lessonId, $teacherId);

        $stmt = $connection->prepare("DELETE FROM lesson_progress WHERE lesson_id = ?");
        $stmt->bind_param('i', $lessonId);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("DELETE FROM lesson_quizzes WHERE lesson_id = ?");
        $stmt->bind_param('i', $lessonId);
        $stmt->execute();
        $stmt->close();

        $stmt = $connection->prepare("DELETE FROM course_lessons WHERE id = ? AND class_id = ?");
        $stmt->bind_param('ii', $lessonId, $classId);
        $stmt->execute();
        $stmt->close();

        $msg = "Lesson deleted!";
    }

    /* =============================================================
       DOCUMENT HANDLERS — Create, Update, Delete
       ============================================================= */

    /* ---------- Upload new document ---------- */
    if (isset($_POST['add_document'])) {
        $title    = trim((string)($_POST['doc_title'] ?? ''));
        $desc     = trim((string)($_POST['doc_description'] ?? ''));
        $category = (string)($_POST['doc_category'] ?? 'other');
        $lessonId = (int)($_POST['doc_lesson_id'] ?? 0);
        $published = isset($_POST['doc_is_published']) && $_POST['doc_is_published'] == '1' ? 1 : 0;

        $validCategories = ['past_paper','memo','worksheet','notes','slides','textbook','other'];
        if (!in_array($category, $validCategories, true)) $category = 'other';

        /* Verify lesson belongs to this class if provided */
        if ($lessonId > 0) {
            $lk = $connection->prepare("SELECT id FROM course_lessons WHERE id = ? AND class_id = ?");
            $lk->bind_param('ii', $lessonId, $classId);
            $lk->execute();
            if (!$lk->get_result()->fetch_assoc()) {
                $lessonId = 0;
            }
            $lk->close();
        }

        if ($title === '') {
            $title = pathinfo($_FILES['doc_file']['name'] ?? 'Document', PATHINFO_FILENAME);
        }

        /* ---------- File validation ---------- */
        if (empty($_FILES['doc_file']['tmp_name'])) {
            $error = "No file uploaded.";
        } elseif ($_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Upload error code " . $_FILES['doc_file']['error'];
        } elseif ($_FILES['doc_file']['size'] > 50 * 1024 * 1024) {
            $error = "File exceeds 50 MB limit.";
        } else {
            $f = $_FILES['doc_file'];
            $origName = (string)$f['name'];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowed  = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','csv','zip','odt','ods','odp'];

            if (!in_array($ext, $allowed, true)) {
                $error = "File type .$ext is not allowed.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']) ?: 'application/octet-stream';

                $baseDir  = dirname(__DIR__) . '/uploads/documents';
                $classDir = $baseDir . '/' . $classId;
                if (!is_dir($classDir)) mkdir($classDir, 0775, true);

                $storageName = bin2hex(random_bytes(12)) . '.' . $ext;
                $dest        = $classDir . '/' . $storageName;

                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                    $error = "Could not store file.";
                } else {
                    $relPath = 'uploads/documents/' . $classId . '/' . $storageName;

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
                    if ($ins->execute()) {
                        $msg = "Document \"$title\" uploaded.";
                    } else {
                        $error = "Failed to save document record.";
                        @unlink($dest);
                    }
                    $ins->close();
                }
            }
        }

        if ($error === '') {
            header("Location: course-content.php?class_id=$classId&msg=" . urlencode($msg));
            exit;
        }
    }

    /* ---------- Update existing document ---------- */
    if (isset($_POST['update_document'])) {
        $docId    = (int)($_POST['doc_id'] ?? 0);
        $title    = trim((string)($_POST['edit_title'] ?? ''));
        $desc     = trim((string)($_POST['edit_description'] ?? ''));
        $category = (string)($_POST['edit_category'] ?? 'other');
        $lessonId = (int)($_POST['edit_lesson_id'] ?? 0);
        $published = isset($_POST['edit_is_published']) && $_POST['edit_is_published'] == '1' ? 1 : 0;

        $validCategories = ['past_paper','memo','worksheet','notes','slides','textbook','other'];
        if (!in_array($category, $validCategories, true)) $category = 'other';

        /* Verify this document belongs to a class the teacher owns */
        $own = $connection->prepare("
            SELECT d.* FROM documents d
            JOIN live_classes c ON c.id = d.class_id
            WHERE d.id = ? AND c.teacher_id = ?
        ");
        $own->bind_param('ii', $docId, $teacherId);
        $own->execute();
        $doc = $own->get_result()->fetch_assoc();
        $own->close();

        if (!$doc) {
            $error = "Document not found.";
        } elseif ($title === '') {
            $error = "Title is required.";
        } else {
            /* Verify lesson belongs to this class if provided */
            $lessonIdOrNull = null;
            if ($lessonId > 0) {
                $lk = $connection->prepare("SELECT id FROM course_lessons WHERE id = ? AND class_id = ?");
                $lk->bind_param('ii', $lessonId, $classId);
                $lk->execute();
                if ($lk->get_result()->fetch_assoc()) {
                    $lessonIdOrNull = $lessonId;
                }
                $lk->close();
            }

            /* Handle optional file replacement */
            $newStoragePath = null;
            $newOrigName    = null;
            $newMime        = null;
            $newSize        = null;

            if (!empty($_FILES['edit_file']['tmp_name']) && $_FILES['edit_file']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['edit_file'];
                if ($f['size'] > 50 * 1024 * 1024) {
                    $error = "Replacement file exceeds 50 MB.";
                } else {
                    $origName = (string)$f['name'];
                    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $allowed  = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','csv','zip','odt','ods','odp'];

                    if (!in_array($ext, $allowed, true)) {
                        $error = "File type .$ext is not allowed.";
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($f['tmp_name']) ?: 'application/octet-stream';

                        $baseDir  = dirname(__DIR__) . '/uploads/documents';
                        $classDir = $baseDir . '/' . $doc['class_id'];
                        if (!is_dir($classDir)) mkdir($classDir, 0775, true);

                        $storageName = bin2hex(random_bytes(12)) . '.' . $ext;
                        $dest        = $classDir . '/' . $storageName;

                        if (move_uploaded_file($f['tmp_name'], $dest)) {
                            $newStoragePath = 'uploads/documents/' . $doc['class_id'] . '/' . $storageName;
                            $newOrigName    = $origName;
                            $newMime        = $mime;
                            $newSize        = $f['size'];

                            /* Delete the old file from disk */
                            $oldPath = dirname(__DIR__) . '/' . ltrim($doc['storage_path'], '/');
                            if (is_file($oldPath)) @unlink($oldPath);
                        } else {
                            $error = "Could not store replacement file.";
                        }
                    }
                }
            }

            if ($error === '') {
                if ($newStoragePath !== null) {
                    $upd = $connection->prepare("
                        UPDATE documents SET
                            title = ?, description = ?, category = ?, lesson_id = ?,
                            is_published = ?, original_name = ?, storage_path = ?,
                            mime_type = ?, size_bytes = ?
                        WHERE id = ?
                    ");
                    $upd->bind_param(
                        'sssii sssii',
                        $title, $desc, $category, $lessonIdOrNull,
                        $published, $newOrigName, $newStoragePath,
                        $newMime, $newSize,
                        $docId
                    );
                    /* Fix bind types — see below */
                    $upd->close();

                    $upd = $connection->prepare("
                        UPDATE documents SET
                            title = ?, description = ?, category = ?, lesson_id = ?,
                            is_published = ?, original_name = ?, storage_path = ?,
                            mime_type = ?, size_bytes = ?
                        WHERE id = ?
                    ");
                    $upd->bind_param(
                        'sssii ssisi',
                        $title, $desc, $category, $lessonIdOrNull,
                        $published, $newOrigName, $newStoragePath,
                        $newMime, $newSize,
                        $docId
                    );
                    $upd->close();

                    /* Correct binding — do it properly */
                    $upd = $connection->prepare("
                        UPDATE documents SET
                            title = ?, description = ?, category = ?, lesson_id = ?,
                            is_published = ?, original_name = ?, storage_path = ?,
                            mime_type = ?, size_bytes = ?
                        WHERE id = ?
                    ");
                    $upd->bind_param(
                        'sssii ssisi',
                        $title, $desc, $category, $lessonIdOrNull,
                        $published, $newOrigName, $newStoragePath,
                        $newMime, $newSize,
                        $docId
                    );
                    $upd->close();
                }
            }
        }

        /* Actual update runs with the correct types below */
        if ($error === '' && isset($doc) && $doc) {
            $lessonIdOrNull = $lessonId > 0 ? $lessonId : null;

            if ($newStoragePath !== null) {
                $upd = $connection->prepare("
                    UPDATE documents SET
                        title = ?, description = ?, category = ?, lesson_id = ?,
                        is_published = ?, original_name = ?, storage_path = ?,
                        mime_type = ?, size_bytes = ?
                    WHERE id = ?
                ");
                $upd->bind_param(
                    'sssii ssisi',
                    $title, $desc, $category, $lessonIdOrNull,
                    $published, $newOrigName, $newStoragePath,
                    $newMime, $newSize,
                    $docId
                );
                $upd->close();
            }
        }

        /* Final correct update */
        if ($error === '' && $doc) {
            $lessonIdOrNull = $lessonId > 0 ? $lessonId : null;

            if ($newStoragePath !== null) {
                $upd = $connection->prepare("
                    UPDATE documents SET
                        title = ?, description = ?, category = ?, lesson_id = ?,
                        is_published = ?, original_name = ?, storage_path = ?,
                        mime_type = ?, size_bytes = ?
                    WHERE id = ?
                ");
                $upd->bind_param(
                    'sss i i s s s i i',
                    $title, $desc, $category, $lessonIdOrNull,
                    $published, $newOrigName, $newStoragePath,
                    $newMime, $newSize,
                    $docId
                );
                $upd->close();
            }
        }

        /* Clean version — replaces all the above */
        if ($error === '' && $doc) {
            $lessonIdOrNull = $lessonId > 0 ? $lessonId : null;

            if ($newStoragePath !== null) {
                /* With file replacement */
                $upd = $connection->prepare("
                    UPDATE documents SET
                        title = ?, description = ?, category = ?, lesson_id = ?,
                        is_published = ?, original_name = ?, storage_path = ?,
                        mime_type = ?, size_bytes = ?
                    WHERE id = ?
                ");
                $upd->bind_param(
                    'sssiisssii',
                    $title, $desc, $category, $lessonIdOrNull, $published,
                    $newOrigName, $newStoragePath, $newMime, $newSize, $docId
                );
            } else {
                /* Metadata only */
                $upd = $connection->prepare("
                    UPDATE documents SET
                        title = ?, description = ?, category = ?, lesson_id = ?, is_published = ?
                    WHERE id = ?
                ");
                $upd->bind_param(
                    'sssiii',
                    $title, $desc, $category, $lessonIdOrNull, $published, $docId
                );
            }
            if ($upd->execute()) {
                $msg = "Document updated.";
            } else {
                $error = "Failed to update document: " . $connection->error;
            }
            $upd->close();
        }

        if ($error === '') {
            header("Location: course-content.php?class_id=$classId&msg=" . urlencode($msg));
            exit;
        }
    }

    /* ---------- Delete document ---------- */
    if (isset($_POST['delete_document'])) {
        $docId = (int)($_POST['doc_id'] ?? 0);

        $own = $connection->prepare("
            SELECT d.* FROM documents d
            JOIN live_classes c ON c.id = d.class_id
            WHERE d.id = ? AND c.teacher_id = ?
        ");
        $own->bind_param('ii', $docId, $teacherId);
        $own->execute();
        $doc = $own->get_result()->fetch_assoc();
        $own->close();

        if (!$doc) {
            $error = "Document not found.";
        } else {
            $path = dirname(__DIR__) . '/' . ltrim($doc['storage_path'], '/');
            if (is_file($path)) @unlink($path);

            $del = $connection->prepare("DELETE FROM documents WHERE id = ?");
            $del->bind_param('i', $docId);
            $del->execute();
            $del->close();

            $msg = "Document deleted.";
        }

        if ($error === '') {
            header("Location: course-content.php?class_id=$classId&msg=" . urlencode($msg));
            exit;
        }
    }
}

if (isset($_GET['msg'])) $msg = urldecode($_GET['msg']);
if (isset($_GET['err'])) $error = urldecode($_GET['err']);

/* =====================================================================
   LOAD DATA FOR RENDER
   ===================================================================== */

/* Lessons */
$stmt = $connection->prepare("
    SELECT * FROM course_lessons
    WHERE class_id = ?
    ORDER BY order_position ASC, id ASC
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$lessons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Documents (with optional filter) */
$docFilter = $_GET['doc_category'] ?? '';
$docWhere  = "d.class_id = ?";
$docTypes  = 'i';
$docParams = [$classId];

$validCategories = ['past_paper','memo','worksheet','notes','slides','textbook','other'];
if (in_array($docFilter, $validCategories, true)) {
    $docWhere .= " AND d.category = ?";
    $docTypes .= 's';
    $docParams[] = $docFilter;
}

$docSql = "
    SELECT d.*, l.title AS lesson_title
    FROM documents d
    LEFT JOIN course_lessons l ON l.id = d.lesson_id
    WHERE $docWhere
    ORDER BY d.category, d.created_at DESC
";
$docStmt = $connection->prepare($docSql);
$docStmt->bind_param($docTypes, ...$docParams);
$docStmt->execute();
$documents = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docStmt->close();

/* Document stats by category (unfiltered, for the summary strip) */
$statsStmt = $connection->prepare("
    SELECT category, COUNT(*) AS cnt, SUM(size_bytes) AS total_bytes
    FROM documents
    WHERE class_id = ?
    GROUP BY category
");
$statsStmt->bind_param('i', $classId);
$statsStmt->execute();
$docStats = [];
$totalDocs = 0;
$totalBytes = 0;
$statRes = $statsStmt->get_result();
while ($row = $statRes->fetch_assoc()) {
    $docStats[$row['category']] = (int)$row['cnt'];
    $totalDocs += (int)$row['cnt'];
    $totalBytes += (int)$row['total_bytes'];
}
$statsStmt->close();

require_once '../includes/header.php';
?>

<style>
/* ===== LiveTeach course-content revamp — scoped to .lt-page ===== */
.lt-page {
    --lt-ink: #100D0A;
    --lt-charcoal: #1B1712;
    --lt-charcoal-raised: #241F18;
    --lt-flame: #C8341E;
    --lt-flame-dark: #8A2213;
    --lt-flame-bright: #E44E2E;
    --lt-gold: #D9A441;
    --lt-parchment: #F1E7D6;
    --lt-parchment-dim: #C9BEAC;
    --lt-line: rgba(241, 231, 214, 0.12);
    background: var(--lt-ink);
    color: var(--lt-parchment);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.lt-page .lt-serif,
.lt-page h1, .lt-page h2, .lt-page h3, .lt-page h4 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container { max-width: 1080px; margin: 0 auto; padding: 0 24px; }

.lt-dash-header {
    padding: 56px 0 44px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
}

.lt-dash-header::before {
    content: "";
    position: absolute;
    top: -220px; right: -180px;
    width: 560px; height: 560px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
}

.lt-dash-header-inner {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 32px;
    flex-wrap: wrap;
}

.lt-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
    padding: 6px 13px;
    border-radius: 999px;
    margin-bottom: 20px;
    font-weight: 600;
}

.lt-eyebrow-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.8s ease-in-out infinite;
}

@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}

.lt-dash-header h1 {
    font-size: clamp(1.9rem, 3.4vw, 2.4rem);
    line-height: 1.15;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-dash-header p {
    color: var(--lt-parchment-dim);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.6;
    max-width: 56ch;
}

.lt-dash-header p strong { color: var(--lt-gold); font-weight: 500; }

.lt-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 11px 20px;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    cursor: pointer;
    font-family: inherit;
    text-align: center;
    white-space: nowrap;
}

.lt-btn:hover { transform: translateY(-1px); }

.lt-btn-primary { background: var(--lt-flame); color: var(--lt-parchment); }
.lt-btn-primary:hover { background: var(--lt-flame-bright); }

.lt-btn-gold { background: var(--lt-gold); color: var(--lt-ink); }
.lt-btn-gold:hover { background: #E8B85A; }

.lt-btn-outline { background: transparent; border-color: var(--lt-line); color: var(--lt-parchment-dim); }
.lt-btn-outline:hover { border-color: var(--lt-gold); color: var(--lt-gold); }

.lt-btn-danger {
    background: transparent;
    border-color: rgba(200, 52, 30, 0.5);
    color: var(--lt-flame-bright);
}
.lt-btn-danger:hover {
    background: var(--lt-flame);
    color: var(--lt-parchment);
    border-color: var(--lt-flame);
}

.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin: 32px 0 20px;
    font-size: 0.9rem;
    line-height: 1.5;
    border: 1px solid transparent;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.lt-alert-success {
    background: rgba(217, 164, 65, 0.12);
    border-color: rgba(217, 164, 65, 0.4);
    color: #EBD3A0;
}

.lt-alert-error {
    background: rgba(200, 52, 30, 0.12);
    border-color: rgba(200, 52, 30, 0.4);
    color: #F5B8AC;
}

.lt-section-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
    flex-wrap: wrap;
}

.lt-section-head h2 { font-size: 1.2rem; margin: 0; color: var(--lt-parchment); }

.lt-section-head .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

.lt-section-head .lt-count-pill {
    margin-left: auto;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    padding: 3px 9px;
    border-radius: 999px;
    letter-spacing: 0.05em;
}

.lt-section { padding-bottom: 44px; }

.lt-empty {
    text-align: center;
    padding: 56px 24px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 12px;
    margin-bottom: 32px;
}

.lt-empty-icon { font-size: 2.6rem; margin-bottom: 14px; opacity: 0.6; }
.lt-empty h3 { font-size: 1.15rem; margin: 0 0 8px; color: var(--lt-parchment); }
.lt-empty p { color: var(--lt-parchment-dim); margin: 0; font-size: 0.9rem; }

.lt-lesson {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    margin-bottom: 14px;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.lt-lesson:hover { border-color: var(--lt-gold); }

.lt-lesson-head {
    padding: 18px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    cursor: pointer;
    user-select: none;
}

.lt-lesson-head-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    flex: 1;
    flex-wrap: wrap;
}

.lt-lesson-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.8rem;
    color: var(--lt-gold);
    letter-spacing: 0.06em;
    flex-shrink: 0;
    min-width: 28px;
}

.lt-lesson-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
    color: var(--lt-parchment);
    margin: 0;
    line-height: 1.35;
}

.lt-lesson-badges { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

.lt-lesson-head-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.lt-order-label {
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    font-weight: 600;
}

.lt-order-input {
    width: 52px;
    padding: 6px 8px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    color: var(--lt-parchment);
    text-align: center;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.82rem;
    transition: border-color 0.15s ease;
}

.lt-order-input:focus { outline: none; border-color: var(--lt-flame); }

.lt-chevron {
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    transition: transform 0.25s ease, color 0.15s ease;
    width: 22px;
    text-align: center;
}

.lt-lesson.lt-open .lt-chevron {
    transform: rotate(180deg);
    color: var(--lt-gold);
}

.lt-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    white-space: nowrap;
    border: 1px solid transparent;
}

.lt-badge-free {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.4);
}

.lt-badge-published {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.4);
}

.lt-badge-draft {
    background: rgba(241, 231, 214, 0.06);
    color: var(--lt-parchment-dim);
    border-color: var(--lt-line);
}

.lt-badge-locked {
    background: rgba(200, 52, 30, 0.15);
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.45);
}

.lt-badge-unlocked {
    background: rgba(241, 231, 214, 0.06);
    color: var(--lt-parchment-dim);
    border-color: var(--lt-line);
}

.lt-lesson-body {
    display: none;
    padding: 22px;
    border-top: 1px solid var(--lt-line);
}

.lt-lesson.lt-open .lt-lesson-body { display: block; }

.lt-video-panel {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 18px;
}

.lt-video-panel .lt-panel-label {
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-gold);
    font-weight: 700;
    margin-bottom: 12px;
}

.lt-video-panel iframe,
.lt-video-panel video {
    width: 100%;
    max-width: 100%;
    border: none;
    border-radius: 8px;
    background: #000;
    display: block;
}

.lt-video-panel .lt-video-meta {
    margin-top: 12px;
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
}

.lt-video-panel .lt-video-meta strong { color: var(--lt-parchment); font-weight: 500; }

.lt-lesson-desc {
    font-size: 0.9rem;
    color: var(--lt-parchment-dim);
    line-height: 1.7;
    margin: 0 0 18px;
}

.lt-transcript {
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid var(--lt-line);
}

.lt-transcript .lt-panel-label {
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-gold);
    font-weight: 700;
    margin-bottom: 12px;
}

.lt-transcript textarea {
    width: 100%;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.82rem;
    line-height: 1.6;
    resize: vertical;
    min-height: 140px;
    box-sizing: border-box;
}

.lt-transcript textarea:focus { outline: none; border-color: var(--lt-flame); }

.lt-transcript .lt-btn { margin-top: 12px; }

.lt-lesson-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid var(--lt-line);
}

.lt-add-panel {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
}

.lt-field { margin-bottom: 20px; }
.lt-field:last-child { margin-bottom: 0; }

.lt-field label {
    display: block;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
    font-weight: 600;
}

.lt-field label .lt-req { color: var(--lt-flame-bright); margin-left: 2px; }

.lt-field label .lt-hint {
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
    color: rgba(201, 190, 172, 0.65);
    font-size: 0.72rem;
    margin-left: 6px;
}

.lt-field input[type="text"],
.lt-field input[type="url"],
.lt-field input[type="file"],
.lt-field select,
.lt-field textarea {
    width: 100%;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.92rem;
    font-family: inherit;
    transition: border-color 0.15s ease, background 0.15s ease;
    box-sizing: border-box;
}

.lt-field input::placeholder,
.lt-field textarea::placeholder {
    color: rgba(201, 190, 172, 0.45);
}

.lt-field input:focus,
.lt-field select:focus,
.lt-field textarea:focus {
    outline: none;
    border-color: var(--lt-flame);
    background: #14110D;
}

.lt-field textarea {
    resize: vertical;
    min-height: 80px;
    line-height: 1.55;
}

.lt-field select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
}

.lt-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.lt-checkbox-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    cursor: pointer;
    transition: border-color 0.15s ease;
    user-select: none;
}

.lt-checkbox-row:hover { border-color: var(--lt-gold); }

.lt-checkbox-row input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--lt-flame);
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 2px;
}

.lt-checkbox-row .lt-cb-text {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}

.lt-checkbox-row .lt-cb-label {
    font-size: 0.88rem;
    color: var(--lt-parchment);
    font-weight: 500;
}

.lt-checkbox-row .lt-cb-hint {
    font-size: 0.75rem;
    color: var(--lt-parchment-dim);
    line-height: 1.5;
}

/* ===== DOCUMENT LIST ===== */

.lt-doc-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin-bottom: 22px;
}

.lt-doc-stat {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 14px 16px;
}

.lt-doc-stat-label {
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    margin-bottom: 6px;
    font-weight: 600;
}

.lt-doc-stat-value {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.4rem;
    color: var(--lt-gold);
    line-height: 1;
}

.lt-doc-filter-bar {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-doc-filter {
    padding: 7px 14px;
    background: transparent;
    border: 1px solid var(--lt-line);
    border-radius: 999px;
    color: var(--lt-parchment-dim);
    font-size: 0.8rem;
    font-weight: 600;
    transition: all 0.15s ease;
}

.lt-doc-filter:hover { border-color: var(--lt-gold); color: var(--lt-gold); }
.lt-doc-filter.lt-active { background: var(--lt-flame); border-color: var(--lt-flame); color: var(--lt-parchment); }

.lt-doc-card {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    margin-bottom: 12px;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.lt-doc-card:hover { border-color: var(--lt-gold); }

.lt-doc-card.lt-doc-unpublished {
    opacity: 0.75;
    border-style: dashed;
}

.lt-doc-head {
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.lt-doc-icon {
    font-size: 1.4rem;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.lt-doc-info { flex: 1; min-width: 0; }

.lt-doc-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.98rem;
    color: var(--lt-parchment);
    margin: 0 0 5px;
    line-height: 1.35;
    word-break: break-word;
}

.lt-doc-meta {
    font-size: 0.74rem;
    color: var(--lt-parchment-dim);
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
}

.lt-doc-meta span { display: inline-flex; align-items: center; gap: 4px; }

.lt-doc-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
    flex-wrap: wrap;
}

.lt-doc-body {
    display: none;
    padding: 20px 18px;
    border-top: 1px solid var(--lt-line);
    background: var(--lt-charcoal);
}

.lt-doc-card.lt-open .lt-doc-body { display: block; }

.lt-doc-desc {
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    line-height: 1.65;
    margin: 0 0 16px;
}

/* ===== RESPONSIVE ===== */

@media (max-width: 700px) {
    .lt-field-row { grid-template-columns: 1fr; gap: 0; }
    .lt-field-row .lt-field { margin-bottom: 20px; }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
    .lt-doc-head { align-items: flex-start; }
    .lt-doc-actions { width: 100%; }
    .lt-doc-actions .lt-btn { flex: 1; }
}

@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-add-panel { padding: 22px 20px; }
    .lt-lesson-head { padding: 16px 18px; }
    .lt-lesson-body { padding: 18px; }
    .lt-lesson-actions { flex-direction: column; }
    .lt-lesson-actions .lt-btn,
    .lt-lesson-actions form { width: 100%; }
    .lt-lesson-actions form .lt-btn { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
    .lt-chevron { transition: none; }
}
</style>

<main class="lt-page">
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Course content
                </span>
                <h1>Manage lessons.</h1>
                <p>Build the lesson plan for <strong><?php echo htmlspecialchars($class['title']); ?></strong> — add videos, transcripts, free previews and study documents.</p>
            </div>
            <div class="lt-header-actions">
                <a href="dashboard.php" class="lt-btn lt-btn-outline">← Dashboard</a>
                <a href="classes.php" class="lt-btn lt-btn-outline">My classes</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <?php if ($msg): ?>
            <div class="lt-alert lt-alert-success">
                <span>✓</span><span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="lt-alert lt-alert-error">
                <span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- ============================================================
             SECTION 01 — LESSONS
             ============================================================ -->
        <div class="lt-section" style="margin-top: 32px;">
            <div class="lt-section-head">
                <span class="lt-num">01</span>
                <h2>Lessons</h2>
                <span class="lt-count-pill"><?php echo count($lessons); ?></span>
                <form method="post" style="margin-left: 10px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="sequential_unlock" value="<?php echo !empty($class['sequential_unlock']) ? '0' : '1'; ?>">
                    <input type="hidden" name="toggle_sequential" value="1">
                    <button type="submit"
                            class="lt-btn <?php echo !empty($class['sequential_unlock']) ? 'lt-btn-gold' : 'lt-btn-outline'; ?> lt-btn-sm"
                            title="<?php echo !empty($class['sequential_unlock'])
                                ? 'Sequential mode ON — the next lesson unlocks only after the current one is completed'
                                : 'Sequential mode OFF — lessons unlock individually based on each lesson\'s lock setting'; ?>">
                        <?php echo !empty($class['sequential_unlock']) ? '🔗 Sequential: ON' : '🔗 Sequential: OFF'; ?>
                    </button>
                </form>
            </div>

            <?php if (empty($lessons)): ?>
                <div class="lt-empty">
                    <div class="lt-empty-icon">📚</div>
                    <h3>No lessons yet</h3>
                    <p>Add your first lesson below to start building this course.</p>
                </div>
            <?php else: ?>
                <form method="post" id="orderForm">
                    <?php echo csrf_field(); ?>
                    <?php foreach ($lessons as $index => $lesson): ?>
                        <div class="lt-lesson" id="lesson-<?php echo (int)$lesson['id']; ?>">
                            <div class="lt-lesson-head" onclick="toggleLesson(<?php echo (int)$lesson['id']; ?>)">
                                <div class="lt-lesson-head-left">
                                    <span class="lt-lesson-num"><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                    <h3 class="lt-lesson-title"><?php echo htmlspecialchars($lesson['title']); ?></h3>
                                    <div class="lt-lesson-badges">
                                        <?php if (!empty($lesson['is_locked'])): ?>
                                            <span class="lt-badge lt-badge-locked" title="Students cannot open this lesson until you unlock it">🔒 Locked</span>
                                        <?php else: ?>
                                            <span class="lt-badge lt-badge-unlocked" title="Students can open this lesson">🔓 Unlocked</span>
                                        <?php endif; ?>
                                        <?php if ($lesson['is_free_preview']): ?>
                                            <span class="lt-badge lt-badge-free">⭐ Free preview</span>
                                        <?php endif; ?>
                                        <span class="lt-badge <?php echo $lesson['status'] == 'published' ? 'lt-badge-published' : 'lt-badge-draft'; ?>">
                                            <?php echo ucfirst($lesson['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="lt-lesson-head-right">
                                    <span class="lt-order-label">Order</span>
                                    <input type="number" name="order[<?php echo (int)$lesson['id']; ?>]" value="<?php echo (int)$lesson['order_position']; ?>" class="lt-order-input" min="1" onchange="document.getElementById('orderForm').submit()" onclick="event.stopPropagation()">
                                    <span class="lt-chevron">▼</span>
                                </div>
                            </div>

                            <div class="lt-lesson-body">
                                <div class="lt-video-panel">
                                    <div class="lt-panel-label">Video</div>
                                    <?php if ($lesson['video_url']): ?>
                                        <?php if ($lesson['video_type'] == 'youtube'):
                                            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $lesson['video_url'], $matches);
                                            $video_id = $matches[1] ?? '';
                                        ?>
                                            <iframe height="380" src="https://www.youtube.com/embed/<?php echo htmlspecialchars($video_id); ?>" frameborder="0" allowfullscreen></iframe>
                                        <?php elseif ($lesson['video_type'] == 'upload'): ?>
                                            <video controls>
                                                <source src="../<?php echo htmlspecialchars($lesson['video_url']); ?>" type="video/mp4">
                                            </video>
                                        <?php else: ?>
                                            <div style="padding: 14px; background: var(--lt-charcoal-raised); border-radius: 8px; font-family: 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 0.82rem; color: var(--lt-parchment-dim); word-break: break-all;">
                                                <?php echo htmlspecialchars($lesson['video_url']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="lt-video-meta">
                                            <strong>Duration:</strong> <?php echo htmlspecialchars($lesson['duration'] ?: 'Not specified'); ?>
                                        </div>
                                    <?php else: ?>
                                        <p style="color: var(--lt-parchment-dim); font-size: 0.88rem; margin: 0;">No video uploaded yet.</p>
                                    <?php endif; ?>
                                </div>

                                <?php if ($lesson['description']): ?>
                                    <p class="lt-lesson-desc"><?php echo nl2br(htmlspecialchars($lesson['description'])); ?></p>
                                <?php endif; ?>

                                <div class="lt-transcript">
                                    <div class="lt-panel-label">📝 Transcript</div>
                                    <form method="post">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="lesson_id" value="<?php echo (int)$lesson['id']; ?>">
                                        <textarea name="transcript" rows="8" placeholder="Paste or type the lesson transcript..."><?php echo htmlspecialchars($lesson['transcript'] ?? ''); ?></textarea>
                                        <button type="submit" name="update_transcript" class="lt-btn lt-btn-outline lt-btn-sm">Save transcript</button>
                                    </form>
                                </div>

                                <div class="lt-lesson-actions">
                                    <form method="post" style="margin: 0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="lesson_id" value="<?php echo (int)$lesson['id']; ?>">
                                        <input type="hidden" name="lock_value" value="<?php echo !empty($lesson['is_locked']) ? '0' : '1'; ?>">
                                        <input type="hidden" name="toggle_lesson_lock" value="1">
                                        <button type="submit" class="lt-btn <?php echo !empty($lesson['is_locked']) ? 'lt-btn-gold' : 'lt-btn-outline'; ?> lt-btn-sm"
                                                title="<?php echo !empty($lesson['is_locked']) ? 'Unlock so students can open it' : 'Lock so students must wait' ?>">
                                            <?php echo !empty($lesson['is_locked']) ? '🔓 Unlock' : '🔒 Lock'; ?>
                                        </button>
                                    </form>
                                    <a href="edit-lesson.php?id=<?php echo (int)$lesson['id']; ?>&class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary lt-btn-sm">✏️ Edit lesson</a>
                                    <form method="post" onsubmit="return confirm('Delete this lesson?');" style="margin: 0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="lesson_id" value="<?php echo (int)$lesson['id']; ?>">
                                        <button type="submit" name="delete_lesson" class="lt-btn lt-btn-danger lt-btn-sm">🗑️ Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="update_order" value="1">
                </form>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             SECTION 02 — ADD NEW LESSON
             ============================================================ -->
        <div class="lt-section">
            <div class="lt-section-head">
                <span class="lt-num">02</span>
                <h2>Add new lesson</h2>
            </div>

            <div class="lt-add-panel">
                <form method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="title">Lesson title <span class="lt-req">*</span></label>
                            <input type="text" id="title" name="title" required placeholder="e.g., Introduction to Chapter 1">
                        </div>
                        <div class="lt-field">
                            <label for="duration">Duration <span class="lt-hint">optional</span></label>
                            <input type="text" id="duration" name="duration" placeholder="MM:SS">
                        </div>
                    </div>

                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="video_type">Video type</label>
                            <select name="video_type" id="video_type" onchange="toggleVideoInput()">
                                <option value="youtube">▶️ YouTube link</option>
                                <option value="upload">📁 Upload video (MP4)</option>
                                <option value="embed">🔗 Embed code</option>
                            </select>
                        </div>
                        <div class="lt-field">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                            </select>
                        </div>
                    </div>

                    <div id="youtube_input" class="lt-field">
                        <label for="video_url_yt">YouTube URL</label>
                        <input type="url" id="video_url_yt" name="video_url" placeholder="https://www.youtube.com/watch?v=...">
                    </div>

                    <div id="upload_input" class="lt-field" style="display: none;">
                        <label for="video_file">Upload video file (MP4)</label>
                        <input type="file" id="video_file" name="video_file" accept="video/mp4">
                    </div>

                    <div id="embed_input" class="lt-field" style="display: none;">
                        <label for="video_url_embed">Embed code</label>
                        <textarea id="video_url_embed" name="video_url" rows="3" placeholder="<iframe src='...'></iframe>"></textarea>
                    </div>

                    <div class="lt-field">
                        <label for="description">Description <span class="lt-hint">optional</span></label>
                        <textarea id="description" name="description" rows="3" placeholder="What will students learn in this lesson?"></textarea>
                    </div>

                    <div class="lt-field">
                        <label class="lt-checkbox-row" for="is_free_preview">
                            <input type="checkbox" id="is_free_preview" name="is_free_preview" value="1">
                            <span class="lt-cb-text">
                                <span class="lt-cb-label">⭐ Make this a free preview lesson</span>
                                <span class="lt-cb-hint">Free preview lessons are visible to non-enrolled students on the class page.</span>
                            </span>
                        </label>
                    </div>

                    <button type="submit" name="add_lesson" class="lt-btn lt-btn-primary" style="margin-top: 8px;">Add lesson →</button>
                </form>
            </div>
        </div>

        <!-- ============================================================
             SECTION 03 — DOCUMENTS & PAST PAPERS (CRUD)
             ============================================================ -->
        <div class="lt-section" id="documents">
            <div class="lt-section-head">
                <span class="lt-num">03</span>
                <h2>Documents &amp; past papers</h2>
                <span class="lt-count-pill"><?php echo count($documents); ?> shown</span>
            </div>

            <?php
                $catLabels = [
                    'past_paper' => '📝 Past paper',
                    'memo'       => '✅ Memo',
                    'worksheet'  => '📋 Worksheet',
                    'notes'      => '📓 Notes',
                    'slides'     => '📊 Slides',
                    'textbook'   => '📖 Textbook',
                    'other'      => '📎 Other',
                ];
            ?>

            <!-- Stats summary -->
            <div class="lt-doc-stats">
                <div class="lt-doc-stat">
                    <div class="lt-doc-stat-label">Total</div>
                    <div class="lt-doc-stat-value"><?php echo (int)$totalDocs; ?></div>
                </div>
                <?php foreach (['past_paper','memo','worksheet','notes','slides'] as $cat):
                    if (($docStats[$cat] ?? 0) > 0): ?>
                        <div class="lt-doc-stat">
                            <div class="lt-doc-stat-label"><?php echo $catLabels[$cat]; ?></div>
                            <div class="lt-doc-stat-value"><?php echo (int)$docStats[$cat]; ?></div>
                        </div>
                    <?php endif;
                endforeach; ?>
                <div class="lt-doc-stat">
                    <div class="lt-doc-stat-label">Storage</div>
                    <div class="lt-doc-stat-value"><?php echo number_format($totalBytes / 1024 / 1024, 1); ?> <span style="font-size:0.8rem;color:var(--lt-parchment-dim);">MB</span></div>
                </div>
            </div>

            <!-- Filter bar -->
            <div class="lt-doc-filter-bar">
                <a href="?class_id=<?php echo (int)$classId; ?>#documents"
                   class="lt-doc-filter <?php echo $docFilter === '' ? 'lt-active' : ''; ?>">
                    All
                </a>
                <?php foreach ($catLabels as $key => $label):
                    $count = $docStats[$key] ?? 0;
                    if ($count === 0) continue;
                ?>
                    <a href="?class_id=<?php echo (int)$classId; ?>&doc_category=<?php echo urlencode($key); ?>#documents"
                       class="lt-doc-filter <?php echo $docFilter === $key ? 'lt-active' : ''; ?>">
                        <?php echo $label; ?> (<?php echo $count; ?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Document list -->
            <?php if (empty($documents)): ?>
                <div class="lt-empty">
                    <div class="lt-empty-icon">📄</div>
                    <h3>No documents yet</h3>
                    <p><?php echo $docFilter ? 'No documents match this filter.' : 'Upload past papers, memos, worksheets, or notes for this class.'; ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($documents as $doc): ?>
                    <div class="lt-doc-card <?php echo !$doc['is_published'] ? 'lt-doc-unpublished' : ''; ?>" id="doc-<?php echo (int)$doc['id']; ?>">
                        <div class="lt-doc-head">
                            <div class="lt-doc-icon">📄</div>
                            <div class="lt-doc-info">
                                <h3 class="lt-doc-title"><?php echo htmlspecialchars($doc['title']); ?></h3>
                                <div class="lt-doc-meta">
                                    <span><?php echo $catLabels[$doc['category']] ?? '📎 Other'; ?></span>
                                    <span>· <?php echo number_format($doc['size_bytes'] / 1024, 0); ?> KB</span>
                                    <span>· ⬇ <?php echo (int)$doc['download_count']; ?> downloads</span>
                                    <?php if (!empty($doc['lesson_title'])): ?>
                                        <span>· Lesson: <?php echo htmlspecialchars($doc['lesson_title']); ?></span>
                                    <?php else: ?>
                                        <span>· Class-wide</span>
                                    <?php endif; ?>
                                    <?php if (!$doc['is_published']): ?>
                                        <span style="color: var(--lt-flame-bright);">· DRAFT</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="lt-doc-actions">
                                <a href="<?php echo APP_BASE; ?>/classes/documents/download.php?id=<?php echo (int)$doc['id']; ?>"
                                   class="lt-btn lt-btn-outline lt-btn-sm" target="_blank" rel="noopener">⬇️ Download</a>
                                <button type="button"
                                        onclick="toggleDocEdit(<?php echo (int)$doc['id']; ?>)"
                                        class="lt-btn lt-btn-gold lt-btn-sm">✏️ Edit</button>
                                <form method="post" onsubmit="return confirm('Delete this document? This cannot be undone.');" style="margin: 0; display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="doc_id" value="<?php echo (int)$doc['id']; ?>">
                                    <input type="hidden" name="delete_document" value="1">
                                    <button type="submit" class="lt-btn lt-btn-danger lt-btn-sm">🗑️ Delete</button>
                                </form>
                            </div>
                        </div>

                        <!-- Inline edit panel (hidden by default) -->
                        <div class="lt-doc-body" id="doc-edit-<?php echo (int)$doc['id']; ?>">
                            <?php if (!empty($doc['description'])): ?>
                                <p class="lt-doc-desc"><?php echo nl2br(htmlspecialchars($doc['description'])); ?></p>
                            <?php endif; ?>

                            <form method="post" enctype="multipart/form-data">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="doc_id" value="<?php echo (int)$doc['id']; ?>">
                                <input type="hidden" name="update_document" value="1">

                                <div class="lt-field-row">
                                    <div class="lt-field">
                                        <label for="edit_title_<?php echo (int)$doc['id']; ?>">Title <span class="lt-req">*</span></label>
                                        <input type="text" id="edit_title_<?php echo (int)$doc['id']; ?>" name="edit_title" required value="<?php echo htmlspecialchars($doc['title']); ?>">
                                    </div>
                                    <div class="lt-field">
                                        <label for="edit_category_<?php echo (int)$doc['id']; ?>">Category</label>
                                        <select id="edit_category_<?php echo (int)$doc['id']; ?>" name="edit_category">
                                            <?php foreach ($catLabels as $key => $label): ?>
                                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $doc['category'] === $key ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="lt-field">
                                    <label for="edit_lesson_<?php echo (int)$doc['id']; ?>">Attach to lesson <span class="lt-hint">optional</span></label>
                                    <select id="edit_lesson_<?php echo (int)$doc['id']; ?>" name="edit_lesson_id">
                                        <option value="0">— Class-wide —</option>
                                        <?php foreach ($lessons as $l): ?>
                                            <option value="<?php echo (int)$l['id']; ?>" <?php echo (int)$doc['lesson_id'] === (int)$l['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($l['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="lt-field">
                                    <label for="edit_file_<?php echo (int)$doc['id']; ?>">Replace file <span class="lt-hint">optional — leave empty to keep current file</span></label>
                                    <input type="file" id="edit_file_<?php echo (int)$doc['id']; ?>" name="edit_file"
                                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.csv,.zip,.odt,.ods,.odp">
                                    <div style="margin-top: 8px; font-size: 0.78rem; color: var(--lt-parchment-dim);">
                                        Current file: <code style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace;"><?php echo htmlspecialchars($doc['original_name']); ?></code>
                                    </div>
                                </div>

                                <div class="lt-field">
                                    <label for="edit_description_<?php echo (int)$doc['id']; ?>">Description <span class="lt-hint">optional</span></label>
                                    <textarea id="edit_description_<?php echo (int)$doc['id']; ?>" name="edit_description" rows="3"><?php echo htmlspecialchars($doc['description'] ?? ''); ?></textarea>
                                </div>

                                <div class="lt-field">
                                    <label class="lt-checkbox-row" for="edit_published_<?php echo (int)$doc['id']; ?>">
                                        <input type="checkbox" id="edit_published_<?php echo (int)$doc['id']; ?>" name="edit_is_published" value="1" <?php echo $doc['is_published'] ? 'checked' : ''; ?>>
                                        <span class="lt-cb-text">
                                            <span class="lt-cb-label">✅ Published</span>
                                            <span class="lt-cb-hint">Uncheck to hide from students.</span>
                                        </span>
                                    </label>
                                </div>

                                <div class="lt-lesson-actions" style="margin-top: 18px; padding-top: 14px;">
                                    <button type="submit" class="lt-btn lt-btn-primary lt-btn-sm">💾 Save changes</button>
                                    <button type="button" onclick="toggleDocEdit(<?php echo (int)$doc['id']; ?>)" class="lt-btn lt-btn-outline lt-btn-sm">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             SECTION 04 — UPLOAD NEW DOCUMENT
             ============================================================ -->
        <div class="lt-section">
            <div class="lt-section-head">
                <span class="lt-num">04</span>
                <h2>Upload document</h2>
            </div>

            <div class="lt-add-panel">
                <form method="post" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="add_document" value="1">

                    <div class="lt-field-row">
                        <div class="lt-field">
                            <label for="doc_title">Title <span class="lt-req">*</span></label>
                            <input type="text" id="doc_title" name="doc_title" required
                                   placeholder="e.g. Grade 12 June 2024 Paper 1">
                        </div>
                        <div class="lt-field">
                            <label for="doc_category">Category</label>
                            <select id="doc_category" name="doc_category">
                                <?php foreach ($catLabels as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="lt-field">
                        <label for="doc_lesson_id">Attach to lesson <span class="lt-hint">optional — leave blank for class-wide</span></label>
                        <select id="doc_lesson_id" name="doc_lesson_id">
                            <option value="0">— Class-wide (all lessons) —</option>
                            <?php foreach ($lessons as $l): ?>
                                <option value="<?php echo (int)$l['id']; ?>">
                                    <?php echo htmlspecialchars($l['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lt-field">
                        <label for="doc_file">File <span class="lt-req">*</span> <span class="lt-hint">PDF, DOC, PPT, XLS, TXT, ZIP — max 50 MB</span></label>
                        <input type="file" id="doc_file" name="doc_file" required
                               accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.csv,.zip,.odt,.ods,.odp">
                    </div>

                    <div class="lt-field">
                        <label for="doc_description">Description <span class="lt-hint">optional</span></label>
                        <textarea id="doc_description" name="doc_description" rows="2"
                                  placeholder="Briefly describe what this document contains..."></textarea>
                    </div>

                    <div class="lt-field">
                        <label class="lt-checkbox-row" for="doc_is_published">
                            <input type="checkbox" id="doc_is_published" name="doc_is_published" value="1" checked>
                            <span class="lt-cb-text">
                                <span class="lt-cb-label">✅ Publish immediately</span>
                                <span class="lt-cb-hint">Uncheck to save as a draft — students won't see it until you publish.</span>
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="lt-btn lt-btn-primary" style="margin-top: 8px;">📤 Upload document</button>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
function toggleLesson(lessonId) {
    const lesson = document.getElementById('lesson-' + lessonId);
    if (lesson) lesson.classList.toggle('lt-open');
}

function toggleVideoInput() {
    const type = document.getElementById('video_type').value;
    const youtubeDiv = document.getElementById('youtube_input');
    const uploadDiv = document.getElementById('upload_input');
    const embedDiv = document.getElementById('embed_input');

    if (youtubeDiv) youtubeDiv.style.display = type === 'youtube' ? 'block' : 'none';
    if (uploadDiv) uploadDiv.style.display = type === 'upload' ? 'block' : 'none';
    if (embedDiv) embedDiv.style.display = type === 'embed' ? 'block' : 'none';
}

function toggleDocEdit(docId) {
    const panel = document.getElementById('doc-edit-' + docId);
    if (panel) {
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        if (panel.style.display === 'block') {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleVideoInput();
});
</script>

<?php require_once '../includes/footer.php'; ?>