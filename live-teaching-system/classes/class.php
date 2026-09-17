<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

/* Notifications helper — used when enrollment succeeds */
if (file_exists(__DIR__ . '/../includes/notifications.php')) {
    require_once __DIR__ . '/../includes/notifications.php';
}

$connection = getDbConnection();
$classId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* Fallback base path in case header.php hasn't been loaded yet */
if (!defined('APP_BASE')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $marker     = '/live-teaching-system';
    if (strpos($scriptName, $marker) === 0) {
        define('APP_BASE', $marker);
    } else {
        $dir = str_replace('\\', '/', dirname($scriptName));
        define('APP_BASE', rtrim($dir, '/'));
    }
}

/* =============================================================
   POST HANDLERS — before any output
   ============================================================= */

/* ---- Enrollment ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['enroll'])
    && isset($_SESSION['user'])
    && $_SESSION['user']['role'] === 'student') {

    csrf_verify();
    $studentId = (int)$_SESSION['user']['id'];

    $checkEnroll = $connection->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ?");
    $checkEnroll->bind_param('ii', $studentId, $classId);
    $checkEnroll->execute();
    $existing = $checkEnroll->get_result()->fetch_assoc();
    $checkEnroll->close();

    if (!$existing) {
        $stmt = $connection->prepare("INSERT INTO enrollments (student_id, class_id, payment_status) VALUES (?, ?, 'pending')");
        $stmt->bind_param('ii', $studentId, $classId);
        $stmt->execute();
        $stmt->close();

        /* Fire notification — function is best-effort, never fatal */
        if (function_exists('liveteach_notify_enrollment')) {
            liveteach_notify_enrollment($connection, $classId, $studentId);
        }
    }

    header("Location: " . APP_BASE . "/payments/checkout.php?class_id=$classId");
    exit;
}

/* ---- Review submission ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_review'])
    && isset($_SESSION['user'])
    && $_SESSION['user']['role'] === 'student') {

    csrf_verify();
    $studentId = (int)$_SESSION['user']['id'];
    $rating    = (int)($_POST['rating']  ?? 0);
    $comment   = trim($_POST['comment'] ?? '');

    if ($rating >= 1 && $rating <= 5 && $comment !== '') {
        $checkReview = $connection->prepare("SELECT id FROM reviews WHERE student_id = ? AND class_id = ?");
        $checkReview->bind_param('ii', $studentId, $classId);
        $checkReview->execute();
        $hasReview = $checkReview->get_result()->num_rows > 0;
        $checkReview->close();

        if (!$hasReview) {
            $stmt = $connection->prepare("INSERT INTO reviews (student_id, class_id, rating, comment) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiis', $studentId, $classId, $rating, $comment);
            $stmt->execute();
            $stmt->close();
        }
    }
    header("Location: class.php?id=$classId");
    exit;
}

/* =============================================================
   LOAD CLASS
   ============================================================= */
$stmt = $connection->prepare("
    SELECT c.*,
           u.fullname      AS teacher_name,
           u.bio           AS teacher_bio,
           u.id            AS teacher_id,
           u.profile_image
    FROM live_classes c
    JOIN users u ON c.teacher_id = u.id
    WHERE c.id = ?
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    header('Location: index.php');
    exit;
}

/* =============================================================
   VIEWER STATE
   ============================================================= */
$isEnrolled = false;
$isTeacher  = false;
$isAdmin    = false;

if (isset($_SESSION['user'])) {
    $uid   = (int)$_SESSION['user']['id'];
    $urole = $_SESSION['user']['role'] ?? '';

    if ($urole === 'student') {
        $checkStmt = $connection->prepare("SELECT id, payment_status FROM enrollments WHERE student_id = ? AND class_id = ?");
        $checkStmt->bind_param('ii', $uid, $classId);
        $checkStmt->execute();
        $enrollment = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        $isEnrolled = $enrollment && $enrollment['payment_status'] === 'paid';
    } elseif ($urole === 'teacher' && $uid === (int)$class['teacher_id']) {
        $isTeacher = true;
    } elseif ($urole === 'admin') {
        $isAdmin = true;
    }
}

/* =============================================================
   ACTIVE LIVE STREAM
   ============================================================= */
$streamStmt = $connection->prepare("
    SELECT * FROM live_streams
    WHERE class_id = ? AND status = 'live'
    ORDER BY actual_start_time DESC, id DESC
    LIMIT 1
");
$streamStmt->bind_param('i', $classId);
$streamStmt->execute();
$activeStream = $streamStmt->get_result()->fetch_assoc();
$streamStmt->close();

/* Jitsi room name */
$roomName    = "liveteach_class_{$classId}_" . substr(md5((string)$classId), 0, 8);
$jitsiDomain = "meet.jit.si";

/* =============================================================
   PREVIEW VIDEOS
   ============================================================= */
$previewStmt = $connection->prepare("
    SELECT * FROM videos
    WHERE class_id = ? AND is_preview = 1 AND status = 'active'
    ORDER BY created_at DESC
    LIMIT 3
");
$previewStmt->bind_param('i', $classId);
$previewStmt->execute();
$previewVideos = $previewStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$previewStmt->close();

/* =============================================================
   ALL CLASS VIDEOS
   ============================================================= */
$vidStmt = $connection->prepare("
    SELECT * FROM videos
    WHERE class_id = ? AND status = 'active'
    ORDER BY created_at DESC
");
$vidStmt->bind_param('i', $classId);
$vidStmt->execute();
$classVideos = $vidStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$vidStmt->close();

/* =============================================================
   REVIEWS
   ============================================================= */
$revStmt = $connection->prepare("
    SELECT r.*, u.fullname
    FROM reviews r
    JOIN users u ON r.student_id = u.id
    WHERE r.class_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
");
$revStmt->bind_param('i', $classId);
$revStmt->execute();
$reviews = $revStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$revStmt->close();

$avgStmt = $connection->prepare("SELECT AVG(rating) as avg, COUNT(*) as total FROM reviews WHERE class_id = ?");
$avgStmt->bind_param('i', $classId);
$avgStmt->execute();
$avgRating = $avgStmt->get_result()->fetch_assoc();
$avgStmt->close();

/* =============================================================
   PUBLISHED LESSON COUNT
   ============================================================= */
$lcStmt = $connection->prepare("
    SELECT COUNT(*) as count FROM course_lessons
    WHERE class_id = ? AND status = 'published'
");
$lcStmt->bind_param('i', $classId);
$lcStmt->execute();
$lessonsCount = (int)$lcStmt->get_result()->fetch_assoc()['count'];
$lcStmt->close();

/* =============================================================
   CLASS DOCUMENTS
   ============================================================= */
$docStmt = $connection->prepare("
    SELECT d.*, l.title AS lesson_title
    FROM documents d
    LEFT JOIN course_lessons l ON l.id = d.lesson_id
    WHERE d.class_id = ? AND d.is_published = 1
    ORDER BY d.category, d.created_at DESC
");
$docStmt->bind_param('i', $classId);
$docStmt->execute();
$classDocuments = $docStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$docStmt->close();

/* =============================================================
   RENDER
   ============================================================= */
require_once '../includes/header.php';
?>

<style>
/* ===== LiveTeach class detail revamp — scoped to .lt-page ===== */
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

.lt-container { max-width: 1180px; margin: 0 auto; padding: 0 24px; }

.lt-detail-header {
    padding: 60px 0 48px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
}

.lt-detail-header::before {
    content: "";
    position: absolute;
    top: -220px;
    right: -180px;
    width: 560px;
    height: 560px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
}

.lt-detail-header-inner {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 32px;
    align-items: start;
}

.lt-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-bottom: 18px;
}

.lt-status-upcoming {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border: 1px solid rgba(217, 164, 65, 0.35);
}

.lt-status-ongoing {
    background: rgba(200, 52, 30, 0.18);
    color: var(--lt-flame-bright);
    border: 1px solid rgba(200, 52, 30, 0.45);
}

.lt-status-ongoing .lt-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.5s infinite;
}

.lt-status-completed,
.lt-status-cancelled {
    background: rgba(241, 231, 214, 0.06);
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
}

@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}

.lt-detail-header h1 {
    font-size: clamp(1.9rem, 3.6vw, 2.6rem);
    line-height: 1.15;
    margin: 0 0 12px;
    color: var(--lt-parchment);
}

.lt-detail-byline {
    font-size: 0.95rem;
    color: var(--lt-parchment-dim);
    margin: 0;
}

.lt-detail-byline strong { color: var(--lt-gold); font-weight: 500; }

.lt-rating-block { text-align: right; flex-shrink: 0; }

.lt-rating-stars {
    font-size: 1.4rem;
    color: var(--lt-gold);
    letter-spacing: 2px;
    line-height: 1;
    margin-bottom: 6px;
}

.lt-rating-count { font-size: 0.8rem; color: var(--lt-parchment-dim); }

.lt-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    margin-top: 28px;
    padding-top: 22px;
    border-top: 1px solid var(--lt-line);
}

.lt-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
}

.lt-meta-item .lt-meta-icon { font-size: 0.95rem; }
.lt-meta-item strong { color: var(--lt-parchment); font-weight: 500; }

.lt-layout {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 40px;
    padding: 48px 0 72px;
    align-items: start;
}

.lt-section {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
    margin-bottom: 24px;
}

.lt-section-title {
    font-size: 1.2rem;
    color: var(--lt-parchment);
    margin: 0 0 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
    display: flex;
    align-items: center;
    gap: 10px;
}

.lt-section-title .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

.lt-section p {
    line-height: 1.7;
    color: var(--lt-parchment-dim);
    margin: 0 0 14px;
}
.lt-section p:last-child { margin-bottom: 0; }

.lt-course-cta {
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    border: none;
    text-align: center;
    padding: 32px 26px;
}

.lt-course-cta h3 {
    font-size: 1.3rem;
    color: var(--lt-parchment);
    margin: 0 0 8px;
}

.lt-course-cta p {
    color: rgba(241, 231, 214, 0.9);
    margin: 0 0 20px;
    font-size: 0.95rem;
}

.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 11px 22px;
    border-radius: 8px;
    font-size: 0.9rem;
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

.lt-btn-outline { background: transparent; border-color: var(--lt-line); color: var(--lt-parchment); }
.lt-btn-outline:hover { border-color: var(--lt-gold); color: var(--lt-gold); }

.lt-btn-parchment { background: var(--lt-parchment); color: var(--lt-flame-dark); }
.lt-btn-parchment:hover { background: #fff; }

.lt-btn-danger { background: var(--lt-flame); color: var(--lt-parchment); }
.lt-btn-danger:hover { background: var(--lt-flame-bright); }

.lt-live {
    background: var(--lt-charcoal);
    border: 1px solid rgba(200, 52, 30, 0.45);
    border-radius: 12px;
    padding: 26px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}

.lt-live::before {
    content: "";
    position: absolute;
    top: -120px;
    right: -120px;
    width: 320px;
    height: 320px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.25), transparent 70%);
    pointer-events: none;
}

.lt-live-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 22px;
    position: relative;
    z-index: 1;
}

.lt-live-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(200, 52, 30, 0.18);
    border: 1px solid rgba(200, 52, 30, 0.5);
    color: var(--lt-flame-bright);
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.lt-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.5s infinite;
}

.lt-live-hint { font-size: 0.82rem; color: var(--lt-parchment-dim); }

.lt-join-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

.lt-join-option {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 22px 20px;
    text-align: center;
    transition: border-color 0.15s ease;
}

.lt-join-option:hover { border-color: var(--lt-gold); }

.lt-join-option-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
    color: var(--lt-parchment);
    margin-bottom: 8px;
}

.lt-join-option-desc {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    margin-bottom: 16px;
    line-height: 1.5;
}

.lt-join-option .lt-btn { width: 100%; }

.lt-jitsi-wrap { display: none; margin-top: 20px; position: relative; z-index: 1; }
.lt-jitsi-wrap.lt-open { display: block; }

.lt-jitsi-embed {
    width: 100%;
    height: 560px;
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    background: #000;
}

.lt-link-box {
    margin-top: 18px;
    padding: 16px 18px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    position: relative;
    z-index: 1;
}

.lt-link-box p { margin: 0 0 10px; font-size: 0.85rem; color: var(--lt-parchment-dim); }

.lt-link-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.lt-link-box code {
    flex: 1;
    min-width: 0;
    background: var(--lt-charcoal-raised);
    padding: 10px 12px;
    border-radius: 6px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.82rem;
    color: var(--lt-parchment);
    word-break: break-all;
}

.lt-copy-btn {
    background: var(--lt-gold);
    color: var(--lt-ink);
    border: none;
    padding: 10px 14px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.82rem;
    font-weight: 600;
    font-family: inherit;
    transition: background 0.15s ease;
    white-space: nowrap;
}

.lt-copy-btn:hover { background: #E8B85A; }

.lt-waiting {
    background: var(--lt-charcoal);
    border: 1px solid rgba(217, 164, 65, 0.35);
    border-radius: 12px;
    padding: 40px 28px;
    text-align: center;
    margin-bottom: 24px;
}

.lt-waiting-icon { font-size: 2.6rem; margin-bottom: 14px; }

.lt-waiting h3 {
    color: var(--lt-gold);
    margin: 0 0 10px;
    font-size: 1.25rem;
}

.lt-waiting p {
    color: var(--lt-parchment-dim);
    margin: 0 0 22px;
    font-size: 0.95rem;
    line-height: 1.6;
}

/* ---------- Document list ---------- */
.lt-docs-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 18px;
}

.lt-doc-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    flex-wrap: wrap;
}

.lt-doc-row-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.lt-doc-row-body { flex: 1; min-width: 200px; }

.lt-doc-row-title {
    font-weight: 600;
    color: var(--lt-parchment);
    margin-bottom: 3px;
    font-size: 0.92rem;
}

.lt-doc-row-meta {
    font-size: 0.75rem;
    color: var(--lt-parchment-dim);
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
}

/* ---------- Videos ---------- */
.lt-video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
}

.lt-video {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    overflow: hidden;
    cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease;
}

.lt-video:hover { border-color: var(--lt-flame); transform: translateY(-3px); }

.lt-video-thumb {
    position: relative;
    background: #000;
    height: 150px;
    overflow: hidden;
}

.lt-video-thumb iframe,
.lt-video-thumb video,
.lt-video-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border: none;
    display: block;
    pointer-events: none;
}

.lt-video-overlay {
    position: absolute;
    inset: 0;
    background: rgba(16, 13, 10, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.lt-video:hover .lt-video-overlay { opacity: 1; }

.lt-play-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--lt-flame);
    color: var(--lt-parchment);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    padding-left: 3px;
}

.lt-video-info { padding: 14px 16px; }

.lt-video-title {
    font-size: 0.92rem;
    color: var(--lt-parchment);
    margin-bottom: 6px;
    line-height: 1.4;
}

.lt-video-tag {
    font-size: 0.7rem;
    color: var(--lt-gold);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    font-weight: 600;
}

/* ---------- Teacher ---------- */
.lt-teacher { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }

.lt-teacher-avatar {
    width: 76px;
    height: 76px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    flex-shrink: 0;
}

.lt-teacher-info h3 {
    color: var(--lt-parchment);
    font-size: 1.15rem;
    margin: 0 0 6px;
}

.lt-teacher-info p {
    color: var(--lt-parchment-dim);
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.6;
}

/* ---------- Reviews ---------- */
.lt-review-form {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 26px;
}

.lt-review-form h4 {
    font-size: 1.05rem;
    margin: 0 0 18px;
    color: var(--lt-parchment);
}

.lt-field { margin-bottom: 18px; }

.lt-field label {
    display: block;
    font-size: 0.72rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
    font-weight: 600;
}

.lt-field textarea {
    width: 100%;
    padding: 12px 14px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.92rem;
    font-family: inherit;
    resize: vertical;
    min-height: 90px;
    box-sizing: border-box;
}

.lt-field textarea::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-field textarea:focus {
    outline: none;
    border-color: var(--lt-flame);
    background: #14110D;
}

.lt-stars { display: inline-flex; gap: 6px; }

.lt-star {
    font-size: 1.6rem;
    color: rgba(241, 231, 214, 0.25);
    cursor: pointer;
    transition: color 0.15s ease, transform 0.1s ease;
    user-select: none;
}

.lt-star:hover,
.lt-star.lt-active { color: var(--lt-gold); }

.lt-star:hover { transform: scale(1.1); }

.lt-review {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 20px;
    margin-bottom: 14px;
}

.lt-review-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.lt-review-author {
    font-weight: 600;
    color: var(--lt-parchment);
    font-size: 0.92rem;
}

.lt-review-stars {
    color: var(--lt-gold);
    font-size: 0.9rem;
    letter-spacing: 1px;
    margin-top: 4px;
}

.lt-review-date { font-size: 0.75rem; color: var(--lt-parchment-dim); }

.lt-review-body {
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    line-height: 1.65;
    margin: 0;
}

.lt-empty-note {
    text-align: center;
    padding: 36px 20px;
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    border: 1px dashed var(--lt-line);
    border-radius: 10px;
}

/* ---------- Sidebar ---------- */
.lt-sidebar { position: sticky; top: 100px; }

.lt-side-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 24px;
}

.lt-side-price {
    text-align: center;
    padding-bottom: 22px;
    margin-bottom: 22px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-price-large {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 2.4rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
}

.lt-price-large small {
    font-family: 'Inter', sans-serif;
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    margin-left: 6px;
    font-weight: 400;
}

.lt-price-free { color: var(--lt-gold); }
.lt-price-note { font-size: 0.78rem; color: var(--lt-parchment-dim); }

.lt-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
}
.lt-progress-label strong { color: var(--lt-parchment); font-weight: 500; }

.lt-progress {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    height: 6px;
    overflow: hidden;
    margin-bottom: 22px;
}

.lt-progress-fill {
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame));
    height: 100%;
    border-radius: 6px;
    transition: width 0.3s ease;
}

.lt-side-actions { display: flex; flex-direction: column; gap: 10px; }
.lt-side-actions .lt-btn { width: 100%; padding: 13px 20px; font-size: 0.92rem; }
.lt-side-actions form { margin: 0; }
.lt-side-actions form .lt-btn { width: 100%; }

.lt-register-hint {
    margin-top: -2px;
    padding: 0 4px;
    text-align: center;
    font-size: 0.82rem;
    line-height: 1.5;
    color: var(--lt-parchment-dim);
}

.lt-register-hint a {
    color: var(--lt-gold);
    font-weight: 600;
    border-bottom: 1px solid transparent;
    transition: color 0.2s ease, border-color 0.2s ease;
}

.lt-register-hint a:hover {
    color: var(--lt-flame-bright);
    border-bottom-color: rgba(228, 78, 46, 0.5);
}

.lt-includes {
    margin-top: 22px;
    padding-top: 22px;
    border-top: 1px solid var(--lt-line);
}

.lt-includes h4 {
    font-size: 0.95rem;
    color: var(--lt-parchment);
    margin: 0 0 14px;
}

.lt-includes ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.lt-includes li {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
}

.lt-includes li .lt-inc-icon {
    color: var(--lt-gold);
    font-size: 0.9rem;
    flex-shrink: 0;
}

/* ---------- Modal ---------- */
.lt-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(16, 13, 10, 0.94);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.lt-modal.lt-open { display: flex; }

.lt-modal-box {
    width: 100%;
    max-width: 1100px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 14px;
    overflow: hidden;
}

.lt-modal-head {
    padding: 16px 22px;
    background: var(--lt-charcoal-raised);
    border-bottom: 1px solid var(--lt-line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.lt-modal-title { margin: 0; font-size: 1rem; color: var(--lt-parchment); }

.lt-modal-close {
    background: transparent;
    border: 1px solid var(--lt-line);
    color: var(--lt-parchment);
    width: 34px;
    height: 34px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    transition: border-color 0.15s ease, color 0.15s ease;
    flex-shrink: 0;
}

.lt-modal-close:hover { border-color: var(--lt-flame); color: var(--lt-flame-bright); }

.lt-modal-body { padding: 20px 22px 22px; }

.lt-modal-body iframe,
.lt-modal-body video {
    width: 100%;
    min-height: 520px;
    border: none;
    border-radius: 10px;
    background: #000;
    display: block;
}

@media (max-width: 960px) {
    .lt-layout { grid-template-columns: 1fr; gap: 28px; }
    .lt-sidebar { position: static; }
    .lt-detail-header-inner { grid-template-columns: 1fr; }
    .lt-rating-block { text-align: left; }
    .lt-join-options { grid-template-columns: 1fr; }
    .lt-jitsi-embed { height: 400px; }
    .lt-modal-body iframe,
    .lt-modal-body video { min-height: 300px; }
}

@media (max-width: 560px) {
    .lt-detail-header { padding: 44px 0 36px; }
    .lt-section { padding: 22px 20px; }
    .lt-link-row { flex-direction: column; align-items: stretch; }
    .lt-copy-btn { width: 100%; }
    .lt-meta { gap: 14px; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-status-ongoing .lt-status-dot,
    .lt-live-dot { animation: none; }
}

/* ---------- Live recording controls ---------- */
.lt-record-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    margin-top: 16px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.lt-record-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #666;
    flex-shrink: 0;
    transition: background 0.3s ease;
}

.lt-record-bar.lt-recording .lt-record-dot {
    background: var(--lt-flame-bright);
    animation: lt-rec-pulse 1.2s ease-in-out infinite;
}

@keyframes lt-rec-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50%      { box-shadow: 0 0 0 8px rgba(228, 78, 46, 0); }
}

.lt-record-status {
    flex: 1;
    min-width: 0;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    letter-spacing: 0.03em;
    word-break: break-word;
}

.lt-record-bar.lt-recording .lt-record-status {
    color: var(--lt-flame-bright);
    font-weight: 600;
}

.lt-record-timer {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.9rem;
    color: var(--lt-flame-bright);
    font-weight: 700;
    min-width: 60px;
    text-align: right;
}
</style>

<main class="lt-page">
    <div class="lt-detail-header">
        <div class="lt-container lt-detail-header-inner">
            <div>
                <?php
                    $statusLabels = [
                        'upcoming'  => 'Upcoming',
                        'ongoing'   => 'Live now',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ];
                    $statusLabel = $statusLabels[$class['status']] ?? ucfirst($class['status']);
                ?>
                <span class="lt-status lt-status-<?php echo htmlspecialchars($class['status']); ?>">
                    <?php if ($class['status'] == 'ongoing'): ?>
                        <span class="lt-status-dot"></span>
                    <?php endif; ?>
                    <?php echo $statusLabel; ?>
                </span>
                <h1><?php echo htmlspecialchars($class['title']); ?></h1>
                <p class="lt-detail-byline">Taught by <strong><?php echo htmlspecialchars($class['teacher_name']); ?></strong></p>

                <div class="lt-meta">
                    <div class="lt-meta-item">
                        <span class="lt-meta-icon">📅</span>
                        <span><strong><?php echo date('l, F j, Y', strtotime($class['start_date'])); ?></strong></span>
                    </div>
                    <div class="lt-meta-item">
                        <span class="lt-meta-icon">⏰</span>
                        <span><?php echo date('g:i A', strtotime($class['start_date'])); ?></span>
                    </div>
                    <div class="lt-meta-item">
                        <span class="lt-meta-icon">⏱️</span>
                        <span><?php echo htmlspecialchars($class['duration'] ?: 'Flexible'); ?></span>
                    </div>
                    <div class="lt-meta-item">
                        <span class="lt-meta-icon">📊</span>
                        <span>Level: <strong><?php echo ucfirst($class['level']); ?></strong></span>
                    </div>
                    <?php if ($class['category']): ?>
                        <div class="lt-meta-item">
                            <span class="lt-meta-icon">📂</span>
                            <span><?php echo htmlspecialchars($class['category']); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($lessonsCount > 0): ?>
                        <div class="lt-meta-item">
                            <span class="lt-meta-icon">📚</span>
                            <span><strong><?php echo (int)$lessonsCount; ?></strong> lessons</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($avgRating['avg'])): ?>
                <div class="lt-rating-block">
                    <div class="lt-rating-stars">
                        <?php
                        $avg = round((float)$avgRating['avg'], 1);
                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= $avg) echo '★';
                            elseif ($i - 0.5 <= $avg) echo '½';
                            else echo '☆';
                        }
                        ?>
                    </div>
                    <div class="lt-rating-count">
                        <?php echo number_format((float)$avgRating['avg'], 1); ?> · <?php echo (int)$avgRating['total']; ?> review<?php echo $avgRating['total'] != 1 ? 's' : ''; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-layout">

            <div>
                <?php if ($activeStream && ($isEnrolled || $isTeacher || $isAdmin)): ?>
                    <div class="lt-live" id="live-stream">
                        <div class="lt-live-header">
                            <span class="lt-live-badge">
                                <span class="lt-live-dot"></span>
                                Class is live now
                            </span>
                            <span class="lt-live-hint">Choose how to join →</span>
                        </div>

                        <div class="lt-join-options">
                            <div class="lt-join-option">
                                <div class="lt-join-option-title">🎥 Join in this window</div>
                                <div class="lt-join-option-desc">Best experience — stay on the page.</div>
                                <button type="button" onclick="toggleJitsiEmbed()" class="lt-btn lt-btn-primary" id="showEmbedBtn">
                                    Join live class
                                </button>
                            </div>

                            <div class="lt-join-option">
                                <div class="lt-join-option-title">🔗 Open in new tab</div>
                                <div class="lt-join-option-desc">Use your preferred browser.</div>
                                <a href="<?php echo htmlspecialchars($activeStream['stream_url']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-outline">
                                    Open meeting
                                </a>
                            </div>
                        </div>

                        <div id="jitsiEmbedContainer" class="lt-jitsi-wrap">
                            <iframe
                                class="lt-jitsi-embed"
                                src="https://<?php echo htmlspecialchars($jitsiDomain); ?>/<?php echo htmlspecialchars($roomName); ?>#config.prejoinPageEnabled=false&userInfo.displayName=<?php echo urlencode($_SESSION['user']['fullname'] ?? 'Student'); ?>"
                                allow="camera; microphone; fullscreen; display-capture"
                                allowfullscreen>
                            </iframe>
                            <div class="lt-link-box" style="margin-top: 14px;">
                                <p>💡 <strong>Tip:</strong> Your microphone is off by default. Click the mic button to speak, and use chat for questions.</p>
                            </div>
                        </div>

                        <div class="lt-link-box">
                            <p>🔗 <strong>Meeting link</strong></p>
                            <div class="lt-link-row">
                                <code id="meetingLink"><?php echo htmlspecialchars($activeStream['stream_url']); ?></code>
                                <button type="button" class="lt-copy-btn" onclick="copyMeetingLink()">📋 Copy</button>
                            </div>
                        </div>

                        <?php if ($isTeacher): ?>
                            <div class="lt-record-bar" id="recordBar">
                                <div class="lt-record-dot"></div>
                                <div class="lt-record-status" id="recordStatus">
                                    Not recording. Click below to start recording this session.
                                </div>
                                <div class="lt-record-timer" id="recordTimer">00:00:00</div>
                                <button type="button" class="lt-btn lt-btn-danger" id="recordToggleBtn" onclick="toggleRecording()">
                                    ⏺ Start recording
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($class['status'] == 'ongoing' && $isEnrolled && !$activeStream): ?>
                    <div class="lt-waiting">
                        <div class="lt-waiting-icon">⏰</div>
                        <h3>Waiting for teacher to start</h3>
                        <p>The teacher hasn't started the live session yet. Check back soon!</p>
                        <button type="button" onclick="location.reload()" class="lt-btn lt-btn-primary">⟳ Refresh page</button>
                    </div>
                <?php elseif ($class['status'] == 'ongoing' && !$isEnrolled && !$isTeacher && !$isAdmin): ?>
                    <div class="lt-waiting">
                        <div class="lt-waiting-icon">🔒</div>
                        <h3>Enroll to join live class</h3>
                        <p>This class is currently in progress. Enroll now to join the live session.</p>
                    </div>
                <?php endif; ?>

                <?php if ($isEnrolled && $lessonsCount > 0): ?>
                    <div class="lt-section lt-course-cta">
                        <h3>📚 Course content available</h3>
                        <p>This class has <?php echo (int)$lessonsCount; ?> lesson<?php echo $lessonsCount != 1 ? 's' : ''; ?> ready for you.</p>
                        <a href="<?php echo APP_BASE; ?>/classes/course-view.php?id=<?php echo (int)$classId; ?>&from=class" class="lt-btn lt-btn-parchment">Start learning →</a>
                    </div>
                <?php endif; ?>

                <div class="lt-section">
                    <h2 class="lt-section-title"><span class="lt-num">01</span> About this class</h2>
                    <p><?php echo nl2br(htmlspecialchars((string)$class['description'])); ?></p>
                </div>

                <?php if (!empty($class['materials'])): ?>
                    <div class="lt-section">
                        <h2 class="lt-section-title"><span class="lt-num">02</span> What you'll learn</h2>
                        <p><?php echo nl2br(htmlspecialchars((string)$class['materials'])); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($classDocuments) && ($isEnrolled || $isTeacher || $isAdmin)): ?>
                    <div class="lt-section">
                        <h2 class="lt-section-title"><span class="lt-num">03</span> Resources</h2>
                        <p>Past papers, memos, worksheets and notes for this class.</p>

                        <?php
                            $catLabels = [
                                'past_paper' => ['📝', 'Past paper'],
                                'memo'       => ['✅', 'Memo'],
                                'worksheet'  => ['📋', 'Worksheet'],
                                'notes'      => ['📓', 'Notes'],
                                'slides'     => ['📊', 'Slides'],
                                'textbook'   => ['📖', 'Textbook'],
                                'other'      => ['📎', 'Other'],
                            ];
                        ?>
                        <div class="lt-docs-list">
                            <?php foreach ($classDocuments as $doc):
                                $cat = $catLabels[$doc['category']] ?? ['📎', 'Other'];
                            ?>
                                <div class="lt-doc-row">
                                    <div class="lt-doc-row-icon"><?php echo $cat[0]; ?></div>
                                    <div class="lt-doc-row-body">
                                        <div class="lt-doc-row-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                        <div class="lt-doc-row-meta">
                                            <span><?php echo $cat[1]; ?></span>
                                            <span>· <?php echo number_format($doc['size_bytes'] / 1024, 0); ?> KB</span>
                                            <?php if (!empty($doc['lesson_title'])): ?>
                                                <span>· <?php echo htmlspecialchars($doc['lesson_title']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a href="<?php echo APP_BASE; ?>/classes/documents/download.php?id=<?php echo (int)$doc['id']; ?>"
                                       class="lt-btn lt-btn-outline" target="_blank" rel="noopener">
                                        ⬇️ Download
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($classVideos) && ($isEnrolled || $isTeacher || $isAdmin)): ?>
                    <div class="lt-section">
                        <h2 class="lt-section-title"><span class="lt-num">04</span> Class videos</h2>
                        <div class="lt-video-grid">
                            <?php foreach ($classVideos as $video):
                                $embed_url = '';
                                $is_local = false;

                                if (strpos($video['video_url'], 'uploads/') === 0) {
                                    $embed_url = '../' . $video['video_url'];
                                    $is_local = true;
                                } elseif (strpos($video['video_url'], 'youtube.com') !== false || strpos($video['video_url'], 'youtu.be') !== false) {
                                    preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video['video_url'], $matches);
                                    $video_id = $matches[1] ?? '';
                                    $embed_url = "https://www.youtube.com/embed/" . $video_id;
                                }
                            ?>
                                <div class="lt-video"
                                     data-video-url="<?php echo htmlspecialchars($embed_url, ENT_QUOTES); ?>"
                                     data-video-title="<?php echo htmlspecialchars($video['title'], ENT_QUOTES); ?>"
                                     data-video-local="<?php echo $is_local ? '1' : '0'; ?>"
                                     onclick="openVideoModalFromAttrs(this)">
                                    <div class="lt-video-thumb">
                                        <?php if (!empty($video['thumbnail'])): ?>
                                            <img src="../<?php echo htmlspecialchars($video['thumbnail']); ?>" alt="">
                                        <?php elseif ($embed_url && !$is_local): ?>
                                            <iframe src="<?php echo htmlspecialchars($embed_url); ?>" frameborder="0"></iframe>
                                        <?php else: ?>
                                            <div style="display:flex; align-items:center; justify-content:center; height:100%; font-size:2rem; color: var(--lt-parchment-dim);">🎬</div>
                                        <?php endif; ?>
                                        <div class="lt-video-overlay"><div class="lt-play-icon">▶</div></div>
                                    </div>
                                    <div class="lt-video-info">
                                        <div class="lt-video-title"><?php echo htmlspecialchars($video['title']); ?></div>
                                        <?php if (!empty($video['is_preview'])): ?>
                                            <div class="lt-video-tag">⭐ Free preview</div>
                                        <?php elseif (isset($video['kind']) && $video['kind'] === 'live_recording'): ?>
                                            <div class="lt-video-tag">🔴 Live recording</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($previewVideos) && !$isEnrolled && !$isTeacher && !$isAdmin): ?>
                    <div class="lt-section">
                        <h2 class="lt-section-title"><span class="lt-num">04</span> Free preview videos</h2>
                        <p>Watch these free previews before enrolling.</p>
                        <div class="lt-video-grid">
                            <?php foreach ($previewVideos as $video):
                                $embed_url = '';
                                if (strpos($video['video_url'], 'youtube.com') !== false || strpos($video['video_url'], 'youtu.be') !== false) {
                                    preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video['video_url'], $matches);
                                    $video_id = $matches[1] ?? '';
                                    $embed_url = "https://www.youtube.com/embed/" . $video_id;
                                }
                            ?>
                                <div class="lt-video"
                                     data-video-url="<?php echo htmlspecialchars($embed_url, ENT_QUOTES); ?>"
                                     data-video-title="<?php echo htmlspecialchars($video['title'], ENT_QUOTES); ?>"
                                     data-video-local="0"
                                     onclick="openVideoModalFromAttrs(this)">
                                    <div class="lt-video-thumb">
                                        <?php if (!empty($video['thumbnail'])): ?>
                                            <img src="../<?php echo htmlspecialchars($video['thumbnail']); ?>" alt="">
                                        <?php elseif ($embed_url): ?>
                                            <iframe src="<?php echo htmlspecialchars($embed_url); ?>" frameborder="0"></iframe>
                                        <?php else: ?>
                                            <div style="display:flex; align-items:center; justify-content:center; height:100%; font-size:2rem; color: var(--lt-parchment-dim);">🎬</div>
                                        <?php endif; ?>
                                        <div class="lt-video-overlay"><div class="lt-play-icon">▶</div></div>
                                    </div>
                                    <div class="lt-video-info">
                                        <div class="lt-video-title"><?php echo htmlspecialchars($video['title']); ?></div>
                                        <div class="lt-video-tag">⭐ Free preview</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="lt-section">
                    <h2 class="lt-section-title"><span class="lt-num">05</span> Meet your teacher</h2>
                    <div class="lt-teacher">
                        <div class="lt-teacher-avatar">👨‍🏫</div>
                        <div class="lt-teacher-info">
                            <h3><?php echo htmlspecialchars($class['teacher_name']); ?></h3>
                            <p>
                                <?php if (!empty($class['teacher_bio'])): ?>
                                    <?php echo nl2br(htmlspecialchars($class['teacher_bio'])); ?>
                                <?php else: ?>
                                    Experienced educator passionate about teaching and helping students succeed.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="lt-section">
                    <h2 class="lt-section-title"><span class="lt-num">06</span> Student reviews</h2>

                    <?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] == 'student' && $isEnrolled): ?>
                        <div class="lt-review-form">
                            <h4>Write a review</h4>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <div class="lt-field">
                                    <label>Your rating</label>
                                    <div class="lt-stars" id="ratingStars">
                                        <span class="lt-star" data-rating="1">★</span>
                                        <span class="lt-star" data-rating="2">★</span>
                                        <span class="lt-star" data-rating="3">★</span>
                                        <span class="lt-star" data-rating="4">★</span>
                                        <span class="lt-star" data-rating="5">★</span>
                                    </div>
                                    <input type="hidden" name="rating" id="ratingValue" required>
                                </div>
                                <div class="lt-field">
                                    <label>Your review</label>
                                    <textarea name="comment" rows="3" required placeholder="Share your experience..."></textarea>
                                </div>
                                <button type="submit" name="submit_review" class="lt-btn lt-btn-primary">Submit review</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($reviews)): ?>
                        <div class="lt-empty-note">No reviews yet. Be the first to review this class.</div>
                    <?php else: ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="lt-review">
                                <div class="lt-review-head">
                                    <div>
                                        <div class="lt-review-author"><?php echo htmlspecialchars($review['fullname']); ?></div>
                                        <div class="lt-review-stars">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <?php echo $i <= (int)$review['rating'] ? '★' : '☆'; ?>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <div class="lt-review-date"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></div>
                                </div>
                                <p class="lt-review-body"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="lt-sidebar">
                <div class="lt-side-card">
                    <div class="lt-side-price">
                        <?php if ($class['price'] > 0): ?>
                            <div class="lt-price-large">R <?php echo number_format((float)$class['price'], 2); ?><small>ZAR</small></div>
                            <div class="lt-price-note">One-time payment · Lifetime access</div>
                        <?php else: ?>
                            <div class="lt-price-large lt-price-free">🎁 Free</div>
                            <div class="lt-price-note">Completely free course</div>
                        <?php endif; ?>
                    </div>

                    <div class="lt-progress-label">
                        <span>👥 Enrollment</span>
                        <span><strong><?php echo (int)$class['current_students']; ?></strong> / <?php echo (int)$class['max_students']; ?></span>
                    </div>
                    <div class="lt-progress">
                        <div class="lt-progress-fill" style="width: <?php echo ((int)$class['current_students'] / max(1, (int)$class['max_students'])) * 100; ?>%;"></div>
                    </div>

                    <div class="lt-side-actions">
                        <?php if ($activeStream && ($isEnrolled || $isTeacher || $isAdmin)): ?>
                            <a href="#live-stream" class="lt-btn lt-btn-primary">🔴 Join live class now</a>
                        <?php elseif ($isEnrolled): ?>
                            <a href="<?php echo APP_BASE; ?>/dashboard/my-classes.php" class="lt-btn lt-btn-gold">✅ Go to my classes</a>
                            <?php if ($lessonsCount > 0): ?>
                                <a href="<?php echo APP_BASE; ?>/classes/course-view.php?id=<?php echo (int)$classId; ?>&from=class" class="lt-btn lt-btn-outline">📚 View course content</a>
                            <?php endif; ?>
                        <?php elseif ($isTeacher): ?>
                            <a href="<?php echo APP_BASE; ?>/teacher/edit-class.php?id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary">✏️ Edit class</a>
                            <a href="<?php echo APP_BASE; ?>/teacher/live-stream.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-gold">🎥 Start live stream</a>
                            <a href="<?php echo APP_BASE; ?>/teacher/course-content.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-outline">📚 Manage content</a>
                        <?php elseif (isset($_SESSION['user']) && $_SESSION['user']['role'] == 'student'): ?>
                            <form method="post">
                                <?php echo csrf_field(); ?>
                                <button type="submit" name="enroll" class="lt-btn lt-btn-primary">Enroll now →</button>
                            </form>
                        <?php else: ?>
                            <a href="<?php echo APP_BASE; ?>/login.php" class="lt-btn lt-btn-primary">Login to enroll</a>
                            <div class="lt-register-hint">
                                New here? <a href="<?php echo APP_BASE; ?>/register.php">Create an account</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lt-includes">
                        <h4>This class includes</h4>
                        <ul>
                            <li><span class="lt-inc-icon">🎥</span> Live interactive sessions</li>
                            <li><span class="lt-inc-icon">📹</span> Recorded class videos</li>
                            <li><span class="lt-inc-icon">📚</span> Course materials</li>
                            <li><span class="lt-inc-icon">🏆</span> Certificate of completion</li>
                        </ul>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<div id="videoModal" class="lt-modal">
    <div class="lt-modal-box">
        <div class="lt-modal-head">
            <h3 class="lt-modal-title" id="modalTitle">Video player</h3>
            <button type="button" class="lt-modal-close" onclick="closeVideoModal()">×</button>
        </div>
        <div class="lt-modal-body">
            <div id="modalVideoContainer"></div>
        </div>
    </div>
</div>

<script>
/* ---------- Live stream embed toggle ---------- */
let embedVisible = false;
function toggleJitsiEmbed() {
    const container = document.getElementById('jitsiEmbedContainer');
    const btn       = document.getElementById('showEmbedBtn');
    if (!container || !btn) return;

    if (!embedVisible) {
        container.classList.add('lt-open');
        btn.textContent = 'Hide live class';
        embedVisible = true;
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        container.classList.remove('lt-open');
        btn.textContent = 'Join live class';
        embedVisible = false;
    }
}

/* ---------- Copy meeting link ---------- */
function copyMeetingLink() {
    const linkEl = document.getElementById('meetingLink');
    if (!linkEl) return;
    const link = linkEl.innerText;
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(link).then(() => {
        const btn = document.querySelector('.lt-copy-btn');
        if (!btn) return;
        const original = btn.textContent;
        btn.textContent = '✅ Copied';
        setTimeout(() => { btn.textContent = original; }, 2000);
    });
}

/* ---------- Video modal ---------- */
function isSafeMediaUrl(url) {
    if (typeof url !== 'string' || url === '') return false;
    if (/^https?:\/\//i.test(url)) return true;
    if (url.indexOf('../uploads/') === 0) return true;
    return false;
}

function openVideoModal(url, title, isLocal) {
    if (!isSafeMediaUrl(url)) {
        console.warn('Blocked unsafe media URL:', url);
        return;
    }

    const modal      = document.getElementById('videoModal');
    const modalTitle = document.getElementById('modalTitle');
    const container  = document.getElementById('modalVideoContainer');
    if (!modal || !modalTitle || !container) return;

    modalTitle.textContent = title || 'Video';
    container.innerHTML = '';

    if (isLocal) {
        const v = document.createElement('video');
        v.src = url;
        v.controls = true;
        v.autoplay = true;
        container.appendChild(v);
    } else {
        const f = document.createElement('iframe');
        f.src = url;
        f.setAttribute('frameborder', '0');
        f.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
        f.setAttribute('allowfullscreen', '');
        container.appendChild(f);
    }

    modal.classList.add('lt-open');
}

function openVideoModalFromAttrs(el) {
    openVideoModal(
        el.dataset.videoUrl   || '',
        el.dataset.videoTitle || 'Video',
        el.dataset.videoLocal === '1'
    );
}

function closeVideoModal() {
    const modal     = document.getElementById('videoModal');
    const container = document.getElementById('modalVideoContainer');
    if (!modal || !container) return;
    modal.classList.remove('lt-open');
    container.innerHTML = '';
}

/* ---------- Rating stars ---------- */
(function initRatingStars() {
    const stars       = document.querySelectorAll('.lt-star');
    const ratingInput = document.getElementById('ratingValue');
    if (!stars.length || !ratingInput) return;

    stars.forEach(star => {
        star.addEventListener('click', function () {
            const rating = parseInt(this.getAttribute('data-rating'), 10);
            ratingInput.value = rating;
            stars.forEach((s, index) => s.classList.toggle('lt-active', index < rating));
        });

        star.addEventListener('mouseenter', function () {
            const rating = parseInt(this.getAttribute('data-rating'), 10);
            stars.forEach((s, index) => s.classList.toggle('lt-active', index < rating));
        });
    });

    const container = document.getElementById('ratingStars');
    if (container) {
        container.addEventListener('mouseleave', function () {
            const current = parseInt(ratingInput.value, 10) || 0;
            stars.forEach((s, index) => s.classList.toggle('lt-active', index < current));
        });
    }
})();

/* ---------- Escape to close ---------- */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeVideoModal();
});

/* ---------- Auto-refresh while waiting for teacher ---------- */
(function () {
    const waiting = document.querySelector('.lt-waiting');
    const live    = document.querySelector('.lt-live');
    if (!waiting || live) return;

    setTimeout(function () {
        location.reload();
    }, 30000);
})();

/* ============================================================
   Live session recording — teacher-only
   Captures teacher's camera + mic, uploads to the server
   when stopped.
   ============================================================ */
(function initRecording() {
    'use strict';

    const bar       = document.getElementById('recordBar');
    const toggleBtn = document.getElementById('recordToggleBtn');
    const statusEl  = document.getElementById('recordStatus');
    const timerEl   = document.getElementById('recordTimer');

    if (!bar || !toggleBtn || !statusEl || !timerEl) return;   // not the teacher

    const CSRF         = <?php echo json_encode(csrf_token()); ?>;
    const UPLOAD_URL   = <?php echo json_encode(APP_BASE . '/classes/recordings/upload.php'); ?>;
    const CLASS_ID     = <?php echo (int)$classId; ?>;
    const STREAM_ID    = <?php echo (int)($activeStream['id'] ?? 0); ?>;
    const CLASS_TITLE  = <?php echo json_encode($class['title']); ?>;

    let mediaRecorder = null;
    let recordedChunks = [];
    let stream = null;
    let startTime = 0;
    let timerInterval = null;

    function fmt(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return String(h).padStart(2, '0') + ':' +
               String(m).padStart(2, '0') + ':' +
               String(s).padStart(2, '0');
    }

    function setRecordingUI(isRecording) {
        bar.classList.toggle('lt-recording', isRecording);
        toggleBtn.innerHTML = isRecording ? '⏹ Stop & upload' : '⏺ Start recording';
    }

    window.toggleRecording = async function () {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: true
            });
        } catch (err) {
            statusEl.textContent = '❌ Could not access camera/mic: ' + err.message;
            return;
        }

        recordedChunks = [];

        const mimeCandidates = [
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp8,opus',
            'video/webm',
            'video/mp4'
        ];
        const mimeType = mimeCandidates.find(m => MediaRecorder.isTypeSupported(m)) || '';

        try {
            mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : {});
        } catch (err) {
            statusEl.textContent = '❌ Recorder init failed: ' + err.message;
            stream.getTracks().forEach(t => t.stop());
            return;
        }

        mediaRecorder.ondataavailable = function (e) {
            if (e.data && e.data.size > 0) recordedChunks.push(e.data);
        };

        mediaRecorder.onstop = function () {
            if (stream) stream.getTracks().forEach(t => t.stop());
            clearInterval(timerInterval);
            setRecordingUI(false);

            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const blob = new Blob(recordedChunks, { type: mimeType || 'video/webm' });
            recordedChunks = [];

            if (blob.size === 0) {
                statusEl.textContent = '⚠️ Recording was empty — nothing to upload.';
                return;
            }

            uploadRecording(blob, elapsed);
        };

        mediaRecorder.start(5000);

        startTime = Date.now();
        setRecordingUI(true);
        statusEl.textContent = '🔴 Recording — you can now click "Join live class" below.';

        timerInterval = setInterval(function () {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            timerEl.textContent = fmt(elapsed);
        }, 1000);
        timerEl.textContent = '00:00:00';
    };

    async function uploadRecording(blob, durationSeconds) {
        statusEl.textContent = '📤 Uploading recording (' +
            (blob.size / 1024 / 1024).toFixed(1) + ' MB)…';
        toggleBtn.disabled = true;

        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('class_id',   String(CLASS_ID));
        fd.append('stream_id',  String(STREAM_ID));
        fd.append('title',      CLASS_TITLE + ' — live recording');
        fd.append('duration_seconds', String(durationSeconds));
        fd.append('recording',  blob, 'recording.webm');

        try {
            const res  = await fetch(UPLOAD_URL, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            const data = await res.json();

            if (data.ok) {
                statusEl.textContent = '✅ Recording uploaded. Reload the page to see it under Class videos.';
                setTimeout(() => {
                    statusEl.textContent = 'Not recording. Click below to start recording this session.';
                }, 8000);
            } else {
                statusEl.textContent = '❌ Upload failed: ' + (data.error || 'unknown');
            }
        } catch (err) {
            statusEl.textContent = '❌ Upload failed: ' + err.message;
        } finally {
            toggleBtn.disabled = false;
        }
    }

    window.addEventListener('beforeunload', function (e) {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            e.preventDefault();
            e.returnValue = 'You are still recording. Stop and upload first?';
            return e.returnValue;
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>