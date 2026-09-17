<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

$connection = getDbConnection();
$classId  = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$lessonId = isset($_GET['lesson']) ? (int)$_GET['lesson'] : 0;

/* ---------- Load class ---------- */
$stmt = $connection->prepare("SELECT * FROM live_classes WHERE id = ?");
$stmt->bind_param('i', $classId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    header('Location: index.php');
    exit;
}

/* ---------- Viewer role ---------- */
$userId     = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
$userRole   = $_SESSION['user']['role'] ?? null;
$isTeacher  = ($userRole === 'teacher' && $userId === (int)$class['teacher_id']);
$isAdmin    = ($userRole === 'admin');
$isEnrolled = false;

if ($userRole === 'student' && $userId > 0) {
    $check = $connection->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? AND payment_status = 'paid'");
    $check->bind_param('ii', $userId, $classId);
    $check->execute();
    $isEnrolled = (bool)$check->get_result()->fetch_assoc();
    $check->close();
}

/* ---------- Load lessons ---------- */
$stmt = $connection->prepare("
    SELECT * FROM course_lessons
    WHERE class_id = ? AND (status = 'published' OR is_free_preview = 1)
    ORDER BY order_position ASC, id ASC
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$lessons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Pick current lesson ---------- */
$currentLesson = null;
if ($lessonId > 0) {
    foreach ($lessons as $l) {
        if ((int)$l['id'] === $lessonId) { $currentLesson = $l; break; }
    }
}
if (!$currentLesson && !empty($lessons)) {
    $currentLesson = $lessons[0];
    $lessonId = (int)$currentLesson['id'];
}

/* ---------- Load progress ---------- */
$progress = [];
if ($isEnrolled && $userId > 0) {
    $stmt = $connection->prepare("
        SELECT lesson_id, status, last_position, watched_duration, completed_at
        FROM lesson_progress
        WHERE student_id = ? AND class_id = ?
    ");
    $stmt->bind_param('ii', $userId, $classId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $progress[(int)$row['lesson_id']] = $row;
    }
    $stmt->close();
}

/* ---------- Completion counts ---------- */
$completedCount = 0;
foreach ($lessons as $lesson) {
    if (isset($progress[(int)$lesson['id']]) && $progress[(int)$lesson['id']]['status'] === 'completed') {
        $completedCount++;
    }
}
$progressPercent = count($lessons) > 0 ? ($completedCount / count($lessons)) * 100 : 0;

/* ---------- Lock + sequential state ---------- */
$sequential = !empty($class['sequential_unlock']);
$canViewAll = $isEnrolled || $isTeacher || $isAdmin;

function lesson_state(array $lesson, ?array $prev, array $progress, bool $sequential, bool $isEnrolled, bool $isTeacher, bool $isAdmin): array {
    if ($isTeacher || $isAdmin)                     return ['canView' => true,  'reason' => 'staff'];
    if (!empty($lesson['is_free_preview']))         return ['canView' => true,  'reason' => 'preview'];
    if (!$isEnrolled)                               return ['canView' => false, 'reason' => 'not_enrolled'];
    if (!empty($lesson['is_locked']))               return ['canView' => false, 'reason' => 'teacher_locked'];
    if ($sequential && $prev !== null) {
        $prevId   = (int)$prev['id'];
        $prevDone = isset($progress[$prevId]) && $progress[$prevId]['status'] === 'completed';
        if (!$prevDone)                             return ['canView' => false, 'reason' => 'sequential'];
    }
    return ['canView' => true, 'reason' => 'open'];
}

$lessonState   = [];
$lessonIndexed = [];
foreach ($lessons as $i => $l) {
    $lessonIndexed[(int)$l['id']] = $i;
    $prev = $i > 0 ? $lessons[$i - 1] : null;
    $lessonState[(int)$l['id']] = lesson_state($l, $prev, $progress, $sequential, $isEnrolled, $isTeacher, $isAdmin);
}

/* ---------- Resolve video ---------- */
$embed_url = '';
$hasVideo  = false;
$videoId   = 0;
if ($currentLesson) {
    $videoId = (int)$currentLesson['id'];
    $vt = $currentLesson['video_type'] ?? 'upload';
    $vu = (string)($currentLesson['video_url'] ?? '');

    if ($vu !== '') {
        if ($vt === 'youtube') {
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $vu, $m) && !empty($m[1])) {
                $embed_url = "https://www.youtube.com/embed/" . $m[1];
                $hasVideo = true;
            }
        } elseif ($vt === 'vimeo') {
            if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/', $vu, $m) && !empty($m[1])) {
                $embed_url = "https://player.vimeo.com/video/" . $m[1];
                $hasVideo = true;
            }
        } elseif ($vt === 'upload') {
            $embed_url = '../' . ltrim($vu, '/');
            $hasVideo = true;
        } else {
            $embed_url = $vu;
            $hasVideo = true;
        }
    }
}

/* ---------- Load documents ---------- */
$lessonDocs = [];
$classDocs  = [];

if ($currentLesson) {
    $sql = "SELECT * FROM documents WHERE class_id = ? AND lesson_id = ?";
    if (!$isTeacher && !$isAdmin) {
        $sql .= " AND is_published = 1";
    }
    $sql .= " ORDER BY category, created_at DESC";

    $stmt = $connection->prepare($sql);
    $stmt->bind_param('ii', $classId, $currentLesson['id']);
    $stmt->execute();
    $lessonDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$sql = "SELECT * FROM documents WHERE class_id = ? AND lesson_id IS NULL";
if (!$isTeacher && !$isAdmin) {
    $sql .= " AND is_published = 1";
}
$sql .= " ORDER BY category, created_at DESC";

$stmt = $connection->prepare($sql);
$stmt->bind_param('i', $classId);
$stmt->execute();
$classDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalDocsAvailable = count($lessonDocs) + count($classDocs);

/* ---------- Smart back navigation ---------- */
$backUrl   = null;
$backLabel = '← Back';

$fromParam = isset($_GET['from']) ? (string)$_GET['from'] : '';
if ($fromParam !== '') {
    $allowedFroms = [
        'my-classes'    => [APP_BASE . '/dashboard/my-classes.php',                              '← My classes'],
        'class'         => [APP_BASE . '/classes/class.php?id=' . (int)$classId,                 '← Back to class'],
        'teacher-class' => [APP_BASE . '/teacher/classes.php',                                   '← My classes'],
        'content'       => [APP_BASE . '/teacher/course-content.php?class_id=' . (int)$classId,  '← Back to lessons'],
        'student-home'  => [APP_BASE . '/student/index.php',                                     '← Dashboard'],
        'dashboard'     => [APP_BASE . '/dashboard/index.php',                                   '← Dashboard'],
        'class-list'    => [APP_BASE . '/classes/index.php',                                     '← All classes'],
    ];
    if (isset($allowedFroms[$fromParam])) {
        $backUrl   = $allowedFroms[$fromParam][0];
        $backLabel = $allowedFroms[$fromParam][1];
    }
}

if ($backUrl === null && !empty($_SERVER['HTTP_REFERER'])) {
    $ref  = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host && strpos($ref, $host) !== false) {
        $refPath  = parse_url($ref, PHP_URL_PATH) ?: '';
        $selfPath = $_SERVER['PHP_SELF'] ?? '';
        if ($refPath !== '' && $refPath !== $selfPath) {
            $backUrl = $ref;
            if (strpos($refPath, 'my-classes.php') !== false)         $backLabel = '← My classes';
            elseif (strpos($refPath, 'teacher/classes.php') !== false) $backLabel = '← My classes';
            elseif (strpos($refPath, 'course-content.php') !== false)  $backLabel = '← Back to lessons';
            elseif (strpos($refPath, 'classes/class.php') !== false)   $backLabel = '← Back to class';
            elseif (strpos($refPath, 'classes/index.php') !== false)   $backLabel = '← All classes';
            elseif (strpos($refPath, 'student/index.php') !== false
                 || strpos($refPath, 'dashboard/index.php') !== false) $backLabel = '← Dashboard';
            else                                                       $backLabel = '← Back';
        }
    }
}

if ($backUrl === null) {
    if ($isTeacher || $isAdmin) {
        $backUrl   = APP_BASE . '/teacher/classes.php';
        $backLabel = '← My classes';
    } elseif ($isEnrolled) {
        $backUrl   = APP_BASE . '/dashboard/my-classes.php';
        $backLabel = '← My classes';
    } else {
        $backUrl   = APP_BASE . '/classes/class.php?id=' . (int)$classId;
        $backLabel = '← Back to class';
    }
}

$fromSuffix = ($fromParam !== '') ? '&from=' . urlencode($fromParam) : '';

/* ---------- Category labels ---------- */
$catLabels = [
    'past_paper' => ['📝', 'Past paper'],
    'memo'       => ['✅', 'Memo'],
    'worksheet'  => ['📋', 'Worksheet'],
    'notes'      => ['📓', 'Notes'],
    'slides'     => ['📊', 'Slides'],
    'textbook'   => ['📖', 'Textbook'],
    'other'      => ['📎', 'Other'],
];

$csrfToken = csrf_token();

require_once '../includes/header.php';
?>

<style>
/* ===== LiveTeach course-view revamp — scoped to .lt-page ===== */
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
    min-height: 100vh;
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
    animation: lt-glow-float 12s ease-in-out infinite;
}

@keyframes lt-glow-float {
    0%, 100% { transform: translate(0, 0) scale(1); opacity: 1; }
    50% { transform: translate(-24px, 18px) scale(1.08); opacity: 0.88; }
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

.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

.lt-course-layout {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 40px;
    padding: 48px 0 72px;
    align-items: start;
}

.lt-course-content { min-width: 0; }

.lt-lesson-head { margin-bottom: 22px; }

.lt-lesson-head h1 {
    font-size: clamp(1.4rem, 2.4vw, 1.75rem);
    margin: 0 0 10px;
    color: var(--lt-parchment);
    line-height: 1.3;
}

.lt-lesson-head p {
    margin: 0;
    color: var(--lt-parchment-dim);
    font-size: 0.92rem;
    line-height: 1.65;
}

.lt-video-container {
    background: #000;
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 22px;
    position: relative;
}

.lt-video-container iframe,
.lt-video-container video {
    width: 100%;
    height: 520px;
    display: block;
    border: none;
}

.lt-no-video {
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 12px;
    padding: 48px 32px;
    text-align: center;
    margin-bottom: 22px;
    color: var(--lt-parchment-dim);
}

.lt-locked-panel {
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 12px;
    padding: 56px 32px;
    text-align: center;
}

.lt-locked-icon {
    font-size: 2.8rem;
    margin-bottom: 16px;
    opacity: 0.65;
    display: inline-block;
    animation: lt-locked-float 4s ease-in-out infinite;
}

@keyframes lt-locked-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
}

.lt-locked-panel h3 {
    font-size: 1.3rem;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-locked-panel p {
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    margin: 0 0 22px;
    line-height: 1.65;
    max-width: 44ch;
    margin-left: auto;
    margin-right: auto;
}

.lt-transcript {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 22px;
    margin-top: 22px;
}

.lt-transcript h3 {
    font-size: 1.05rem;
    margin: 0 0 16px;
    color: var(--lt-parchment);
    display: flex;
    align-items: center;
    gap: 10px;
}

.lt-transcript h3 .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.7rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

.lt-transcript-text {
    max-height: 320px;
    overflow-y: auto;
    padding: 18px 20px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.84rem;
    line-height: 1.75;
    color: var(--lt-parchment-dim);
    white-space: pre-wrap;
}

/* ===== DOCUMENTS PANEL ===== */

.lt-docs-panel {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 22px;
    margin-top: 22px;
}

.lt-docs-panel h3 {
    font-size: 1.05rem;
    margin: 0 0 6px;
    color: var(--lt-parchment);
    display: flex;
    align-items: center;
    gap: 10px;
}

.lt-docs-panel h3 .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.7rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

.lt-docs-panel .lt-docs-sub {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    margin: 0 0 18px;
}

.lt-docs-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.lt-doc-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    transition: border-color 0.15s ease, background 0.15s ease;
    flex-wrap: wrap;
}

.lt-doc-item:hover { border-color: var(--lt-gold); }

.lt-doc-item-icon {
    width: 40px;
    height: 40px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.lt-doc-item-body { flex: 1; min-width: 0; }

.lt-doc-item-title {
    font-size: 0.92rem;
    color: var(--lt-parchment);
    margin: 0 0 3px;
    font-weight: 500;
    line-height: 1.35;
    word-break: break-word;
}

.lt-doc-item-meta {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
}

.lt-doc-item-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
    flex-wrap: wrap;
}

/* ===== DOCUMENT PREVIEW MODAL ===== */

.lt-doc-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(16, 13, 10, 0.94);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 24px;
    transition: padding 0.2s ease;
}

.lt-doc-modal.lt-open { display: flex; }

.lt-doc-modal-box {
    width: 100%;
    max-width: 1100px;
    height: 90vh;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: max-width 0.2s ease, height 0.2s ease, border-radius 0.2s ease;
}

.lt-doc-modal-head {
    padding: 14px 20px;
    background: var(--lt-charcoal-raised);
    border-bottom: 1px solid var(--lt-line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}

.lt-doc-modal-title {
    margin: 0;
    font-size: 0.95rem;
    color: var(--lt-parchment);
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
    line-height: 1.35;
    word-break: break-word;
}

.lt-doc-modal-meta {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    margin-top: 3px;
}

.lt-doc-modal-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-shrink: 0;
}

.lt-doc-modal-close {
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

.lt-doc-modal-close:hover { border-color: var(--lt-flame); color: var(--lt-flame-bright); }

.lt-doc-modal-body {
    flex: 1;
    overflow: hidden;
    background: #000;
    position: relative;
}

.lt-doc-modal-body iframe {
    width: 100%;
    height: 100%;
    border: 0;
    display: block;
    background: #fff;
}

.lt-doc-modal-body .lt-doc-modal-nopreview {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    color: var(--lt-parchment-dim);
    text-align: center;
    padding: 32px;
}

.lt-doc-modal-body .lt-doc-modal-nopreview .lt-big-icon {
    font-size: 3rem;
    opacity: 0.6;
}

.lt-doc-modal-body .lt-doc-modal-nopreview h3 {
    margin: 0;
    color: var(--lt-parchment);
    font-size: 1.15rem;
}

.lt-doc-modal-body .lt-doc-modal-nopreview p {
    margin: 0;
    font-size: 0.9rem;
    max-width: 40ch;
    line-height: 1.6;
}

/* CSS-only fullscreen fallback (for browsers without Fullscreen API) */
.lt-doc-modal.lt-fullscreen {
    padding: 0;
}

.lt-doc-modal.lt-fullscreen .lt-doc-modal-box {
    max-width: 100%;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    border: none;
}

.lt-doc-modal.lt-fullscreen .lt-doc-modal-head {
    padding: 10px 16px;
}

.lt-doc-modal.lt-fullscreen .lt-doc-modal-body {
    border-radius: 0;
}

/* When native fullscreen is active, the box fills the viewport */
.lt-doc-modal-box:fullscreen {
    max-width: 100%;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    border: none;
}

.lt-doc-modal-box:-webkit-full-screen {
    max-width: 100%;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    border: none;
}

.lt-doc-modal-box:-moz-full-screen {
    max-width: 100%;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    border: none;
}

.lt-doc-modal-box:-ms-fullscreen {
    max-width: 100%;
    width: 100vw;
    height: 100vh;
    border-radius: 0;
    border: none;
}

/* ===== SIDEBAR / PROMPTS ===== */

.lt-complete-form { margin-top: 22px; }

.lt-complete-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 13px 26px;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-size: 0.92rem;
    font-weight: 600;
    cursor: pointer;
    background: var(--lt-flame);
    color: var(--lt-parchment);
    transition: transform 0.15s ease, background 0.15s ease;
}

.lt-complete-btn:hover:not(:disabled) {
    background: var(--lt-flame-bright);
    transform: translateY(-2px);
}

.lt-complete-btn:disabled,
.lt-complete-btn.lt-completed {
    background: transparent;
    border: 1px solid rgba(217, 164, 65, 0.4);
    color: var(--lt-gold);
    cursor: not-allowed;
}

.lt-complete-btn.lt-completed { background: rgba(217, 164, 65, 0.08); }

.lt-lesson-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-top: 28px;
    padding-top: 22px;
    border-top: 1px solid var(--lt-line);
    flex-wrap: wrap;
}

.lt-lesson-nav .lt-btn { min-width: 160px; }

.lt-course-sidebar {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 24px;
    position: sticky;
    top: 100px;
}

.lt-course-sidebar h3 {
    font-size: 1.1rem;
    margin: 0 0 18px;
    color: var(--lt-parchment);
    display: flex;
    align-items: center;
    gap: 10px;
}

.lt-course-sidebar h3 .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.7rem;
    color: var(--lt-gold);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
}

.lt-progress-bar {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    height: 6px;
    overflow: hidden;
    margin-bottom: 10px;
}

.lt-progress-fill {
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame));
    height: 100%;
    border-radius: 6px;
    transition: width 0.5s ease;
}

.lt-progress-meta {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    margin: 0 0 20px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    letter-spacing: 0.03em;
}

.lt-progress-meta strong { color: var(--lt-gold); font-weight: 500; }

.lt-lesson-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.lt-lesson-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    cursor: pointer;
    transition: border-color 0.2s ease, transform 0.15s ease, background 0.2s ease;
    position: relative;
    overflow: hidden;
}

.lt-lesson-item::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--lt-flame);
    transform: scaleY(0);
    transform-origin: top center;
    transition: transform 0.3s ease;
}

.lt-lesson-item:hover {
    border-color: var(--lt-gold);
    transform: translateX(3px);
}

.lt-lesson-item:hover::before { transform: scaleY(1); }

.lt-lesson-item.lt-active {
    background: var(--lt-charcoal-raised);
    border-color: var(--lt-flame);
}

.lt-lesson-item.lt-active::before { transform: scaleY(1); }

.lt-lesson-item.lt-locked {
    opacity: 0.55;
    cursor: not-allowed;
}

.lt-lesson-item.lt-locked:hover {
    transform: none;
    border-color: var(--lt-line);
}

.lt-lesson-item.lt-locked:hover::before { transform: scaleY(0); }

.lt-lesson-icon {
    font-size: 1rem;
    flex-shrink: 0;
    width: 22px;
    text-align: center;
    transition: transform 0.15s ease;
}

.lt-lesson-item:hover .lt-lesson-icon { transform: scale(1.15); }

.lt-lesson-body { flex: 1; min-width: 0; }

.lt-lesson-title {
    font-size: 0.88rem;
    color: var(--lt-parchment);
    margin: 0 0 3px;
    line-height: 1.35;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-lesson-item.lt-active .lt-lesson-title { color: var(--lt-gold); }

.lt-lesson-duration {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    letter-spacing: 0.03em;
}

.lt-lesson-lock {
    font-size: 0.85rem;
    flex-shrink: 0;
    color: var(--lt-parchment-dim);
}

.lt-side-prompt {
    margin-top: 22px;
    padding: 20px 18px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.lt-side-prompt p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    line-height: 1.5;
}

.lt-side-prompt .lt-btn { width: 100%; }

.lt-side-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.72rem;
    color: var(--lt-gold);
    background: rgba(217, 164, 65, 0.12);
    border: 1px solid rgba(217, 164, 65, 0.35);
    padding: 4px 10px;
    border-radius: 999px;
    margin-bottom: 12px;
    font-weight: 600;
}

.lt-seq-banner {
    margin-top: 14px;
    padding: 10px 14px;
    background: rgba(217, 164, 65, 0.12);
    border: 1px solid rgba(217, 164, 65, 0.4);
    border-radius: 8px;
    color: var(--lt-gold);
    font-size: 0.82rem;
}

.lt-empty {
    text-align: center;
    padding: 72px 32px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 14px;
}

.lt-empty-icon {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.6;
    display: inline-block;
    animation: lt-locked-float 4s ease-in-out infinite;
}

.lt-empty h3 {
    font-size: 1.25rem;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-empty p {
    color: var(--lt-parchment-dim);
    font-size: 0.92rem;
    margin: 0;
    line-height: 1.65;
}

@media (max-width: 960px) {
    .lt-course-layout { grid-template-columns: 1fr; gap: 28px; }
    .lt-course-sidebar { position: static; }
    .lt-detail-header-inner { grid-template-columns: 1fr; }
    .lt-rating-block { text-align: left; }
    .lt-video-container iframe,
    .lt-video-container video { height: 380px; }
    .lt-doc-modal-box { max-width: 100%; height: 95vh; }
}

@media (max-width: 560px) {
    .lt-detail-header { padding: 44px 0 36px; }
    .lt-course-sidebar { padding: 20px 18px; }
    .lt-video-container iframe,
    .lt-video-container video { height: 240px; }
    .lt-locked-panel { padding: 40px 24px; }
    .lt-lesson-nav { flex-direction: column; }
    .lt-lesson-nav .lt-btn { width: 100%; min-width: 0; }
    .lt-lesson-nav span { display: none; }
    .lt-doc-item-actions { width: 100%; }
    .lt-doc-item-actions .lt-btn { flex: 1; }
    .lt-doc-modal-head { flex-wrap: wrap; }
    .lt-doc-modal-actions { width: 100%; justify-content: flex-end; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-status-ongoing .lt-status-dot,
    .lt-detail-header::before,
    .lt-locked-icon,
    .lt-empty-icon { animation: none; }

    .lt-btn:hover,
    .lt-lesson-item:hover,
    .lt-complete-btn:hover { transform: none; }

    .lt-doc-modal,
    .lt-doc-modal-box { transition: none; }
}
</style>

<main class="lt-page">
    <div class="lt-detail-header">
        <div class="lt-container lt-detail-header-inner">
            <div>
                <span class="lt-status lt-status-<?php echo htmlspecialchars($class['status']); ?>">
                    <?php if ($class['status'] == 'ongoing'): ?>
                        <span class="lt-status-dot"></span>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($class['title']); ?> · Course
                </span>
                <h1><?php echo htmlspecialchars($currentLesson ? $currentLesson['title'] : $class['title']); ?></h1>
                <p class="lt-detail-byline">
                    <?php if ($isTeacher): ?>
                        You're the teacher for this class. All lessons are unlocked.
                    <?php elseif ($isEnrolled): ?>
                        You're enrolled. Work through the lessons at your own pace.
                    <?php elseif (isset($_SESSION['user'])): ?>
                        Preview the free lessons below. Enroll to unlock the full course.
                    <?php else: ?>
                        Preview the free lessons below. Log in to track your progress.
                    <?php endif; ?>
                </p>

                <?php if ($sequential && !$isTeacher && !$isAdmin): ?>
                    <div class="lt-seq-banner">
                        🔗 <strong>Sequential course</strong> — lessons unlock one at a time. Complete each lesson to reach the next.
                    </div>
                <?php endif; ?>

                <div class="lt-meta">
                    <div class="lt-meta-item">
                        <span class="lt-meta-icon">📚</span>
                        <span><strong><?php echo count($lessons); ?></strong> lesson<?php echo count($lessons) != 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="lt-meta-item">
                        <span class="lt-meta-icon">✅</span>
                        <span><strong><?php echo $completedCount; ?></strong> completed</span>
                    </div>
                    <?php if ($totalDocsAvailable > 0): ?>
                        <div class="lt-meta-item">
                            <span class="lt-meta-icon">📎</span>
                            <span><strong><?php echo $totalDocsAvailable; ?></strong> document<?php echo $totalDocsAvailable != 1 ? 's' : ''; ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($currentLesson && $currentLesson['duration']): ?>
                        <div class="lt-meta-item">
                            <span class="lt-meta-icon">⏱️</span>
                            <span><?php echo htmlspecialchars($currentLesson['duration']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lt-rating-block">
                <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES); ?>"
                   class="lt-btn lt-btn-outline"
                   style="margin-bottom: 12px;">
                    <?php echo htmlspecialchars($backLabel); ?>
                </a>
                <div class="lt-rating-count" style="text-align: right;">
                    Lesson <?php
                        $currentIndex = 0;
                        if ($currentLesson) {
                            foreach ($lessons as $i => $l) {
                                if ((int)$l['id'] === (int)$currentLesson['id']) {
                                    $currentIndex = $i + 1;
                                    break;
                                }
                            }
                        }
                        echo $currentIndex;
                    ?>
                    of <?php echo count($lessons); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-course-layout">
            <div class="lt-course-content">
                <?php if ($currentLesson):
                    $state   = $lessonState[(int)$currentLesson['id']] ?? ['canView' => false, 'reason' => 'unknown'];
                    $canView = $state['canView'];
                    $reason  = $state['reason'];
                ?>
                    <div class="lt-lesson-head">
                        <h1><?php echo htmlspecialchars($currentLesson['title']); ?></h1>
                        <?php if (!empty($currentLesson['description'])): ?>
                            <p><?php echo htmlspecialchars($currentLesson['description']); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($canView && $hasVideo): ?>
                        <div class="lt-video-container">
                            <?php if (($currentLesson['video_type'] ?? '') === 'upload'): ?>
                                <video id="lessonVideo"
                                       controls
                                       preload="metadata"
                                       data-lesson-id="<?php echo (int)$videoId; ?>"
                                       data-class-id="<?php echo (int)$classId; ?>"
                                       data-resume="<?php echo (int)($progress[$videoId]['last_position'] ?? 0); ?>">
                                    <source src="<?php echo htmlspecialchars($embed_url); ?>" type="video/mp4">
                                </video>
                            <?php else: ?>
                                <iframe src="<?php echo htmlspecialchars($embed_url); ?>"
                                        frameborder="0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen></iframe>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($canView && !$hasVideo): ?>
                        <div class="lt-no-video">
                            <div style="font-size:2.5rem; margin-bottom:12px;">🎬</div>
                            <p style="margin:0; font-size:0.95rem;">No video has been added to this lesson yet.</p>
                        </div>
                    <?php elseif (!$canView): ?>
                        <div class="lt-locked-panel">
                            <?php if ($reason === 'sequential'): ?>
                                <div class="lt-locked-icon">⏳</div>
                                <h3>Complete the previous lesson first</h3>
                                <p>This course unlocks lessons one at a time. Finish the lesson before this one to continue.</p>
                                <?php
                                    $myIdx  = $lessonIndexed[(int)$currentLesson['id']] ?? 0;
                                    $prevId = ($myIdx > 0) ? (int)$lessons[$myIdx - 1]['id'] : 0;
                                ?>
                                <?php if ($prevId): ?>
                                    <a href="<?php echo APP_BASE; ?>/classes/course-view.php?id=<?php echo (int)$classId; ?>&lesson=<?php echo $prevId; ?><?php echo $fromSuffix; ?>" class="lt-btn lt-btn-primary">← Go to previous lesson</a>
                                <?php endif; ?>
                            <?php elseif ($reason === 'not_enrolled'): ?>
                                <div class="lt-locked-icon">🔒</div>
                                <h3>Enroll to watch this lesson</h3>
                                <p>This lesson is part of the full course curriculum. Enroll to unlock every video, transcript, and the completion certificate.</p>
                                <a href="<?php echo APP_BASE; ?>/classes/class.php?id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary">Enroll now →</a>
                            <?php else: ?>
                                <div class="lt-locked-icon">🔒</div>
                                <h3>This lesson is locked</h3>
                                <p>Your teacher hasn't unlocked this lesson yet. Check back later, or contact your teacher for access.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- LESSON ATTACHMENTS -->
                    <?php if ($canView && !empty($lessonDocs)): ?>
                        <div class="lt-docs-panel">
                            <h3><span class="lt-num">02</span> 📎 Lesson attachments</h3>
                            <p class="lt-docs-sub">
                                <?php echo count($lessonDocs); ?> document<?php echo count($lessonDocs) != 1 ? 's' : ''; ?> for this lesson.
                                Click any to preview.
                            </p>
                            <div class="lt-docs-list">
                                <?php foreach ($lessonDocs as $doc):
                                    $cat = $catLabels[$doc['category']] ?? ['📎', 'Other'];
                                ?>
                                    <div class="lt-doc-item">
                                        <div class="lt-doc-item-icon"><?php echo $cat[0]; ?></div>
                                        <div class="lt-doc-item-body">
                                            <div class="lt-doc-item-title">
                                                <?php echo htmlspecialchars($doc['title']); ?>
                                                <?php if (!$doc['is_published']): ?>
                                                    <span style="font-size:0.7rem;color:var(--lt-flame-bright);">· DRAFT</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="lt-doc-item-meta">
                                                <span><?php echo $cat[1]; ?></span>
                                                <span>· <?php echo number_format($doc['size_bytes'] / 1024, 0); ?> KB</span>
                                                <span>· <?php echo htmlspecialchars($doc['original_name']); ?></span>
                                            </div>
                                        </div>
                                        <div class="lt-doc-item-actions">
                                            <button type="button"
                                                    class="lt-btn lt-btn-primary lt-btn-sm"
                                                    data-preview-id="<?php echo (int)$doc['id']; ?>"
                                                    data-preview-title="<?php echo htmlspecialchars($doc['title'], ENT_QUOTES); ?>"
                                                    data-preview-mime="<?php echo htmlspecialchars($doc['mime_type'], ENT_QUOTES); ?>"
                                                    data-preview-name="<?php echo htmlspecialchars($doc['original_name'], ENT_QUOTES); ?>"
                                                    onclick="openDocPreviewFromAttrs(this)">
                                                👁️ Preview
                                            </button>
                                            <a href="<?php echo APP_BASE; ?>/classes/documents/download.php?id=<?php echo (int)$doc['id']; ?>"
                                               class="lt-btn lt-btn-outline lt-btn-sm"
                                               target="_blank" rel="noopener">⬇️ Download</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($currentLesson['transcript']) && $canView): ?>
                        <div class="lt-transcript">
                            <h3><span class="lt-num">03</span> Transcript</h3>
                            <div class="lt-transcript-text">
                                <?php echo nl2br(htmlspecialchars($currentLesson['transcript'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- CLASS RESOURCES -->
                    <?php if ($canView && !empty($classDocs)): ?>
                        <div class="lt-docs-panel">
                            <h3><span class="lt-num">04</span> 📚 Class resources</h3>
                            <p class="lt-docs-sub">
                                <?php echo count($classDocs); ?> document<?php echo count($classDocs) != 1 ? 's' : ''; ?> available across all lessons.
                            </p>
                            <div class="lt-docs-list">
                                <?php foreach ($classDocs as $doc):
                                    $cat = $catLabels[$doc['category']] ?? ['📎', 'Other'];
                                ?>
                                    <div class="lt-doc-item">
                                        <div class="lt-doc-item-icon"><?php echo $cat[0]; ?></div>
                                        <div class="lt-doc-item-body">
                                            <div class="lt-doc-item-title">
                                                <?php echo htmlspecialchars($doc['title']); ?>
                                                <?php if (!$doc['is_published']): ?>
                                                    <span style="font-size:0.7rem;color:var(--lt-flame-bright);">· DRAFT</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="lt-doc-item-meta">
                                                <span><?php echo $cat[1]; ?></span>
                                                <span>· <?php echo number_format($doc['size_bytes'] / 1024, 0); ?> KB</span>
                                                <span>· <?php echo htmlspecialchars($doc['original_name']); ?></span>
                                            </div>
                                        </div>
                                        <div class="lt-doc-item-actions">
                                            <button type="button"
                                                    class="lt-btn lt-btn-primary lt-btn-sm"
                                                    data-preview-id="<?php echo (int)$doc['id']; ?>"
                                                    data-preview-title="<?php echo htmlspecialchars($doc['title'], ENT_QUOTES); ?>"
                                                    data-preview-mime="<?php echo htmlspecialchars($doc['mime_type'], ENT_QUOTES); ?>"
                                                    data-preview-name="<?php echo htmlspecialchars($doc['original_name'], ENT_QUOTES); ?>"
                                                    onclick="openDocPreviewFromAttrs(this)">
                                                👁️ Preview
                                            </button>
                                            <a href="<?php echo APP_BASE; ?>/classes/documents/download.php?id=<?php echo (int)$doc['id']; ?>"
                                               class="lt-btn lt-btn-outline lt-btn-sm"
                                               target="_blank" rel="noopener">⬇️ Download</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isEnrolled && $canView):
                        $isCompleted = isset($progress[(int)$currentLesson['id']]) && $progress[(int)$currentLesson['id']]['status'] === 'completed';
                    ?>
                        <form method="post" action="<?php echo APP_BASE; ?>/classes/mark-lesson-complete.php" class="lt-complete-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="lesson_id" value="<?php echo (int)$currentLesson['id']; ?>">
                            <input type="hidden" name="class_id" value="<?php echo (int)$classId; ?>">
                            <input type="hidden" name="from" value="<?php echo htmlspecialchars($fromParam); ?>">
                            <button type="submit" class="lt-complete-btn <?php echo $isCompleted ? 'lt-completed' : ''; ?>" <?php echo $isCompleted ? 'disabled' : ''; ?>>
                                <?php echo $isCompleted ? '✅ Completed' : '📌 Mark as complete'; ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="lt-lesson-nav">
                        <?php
                        $prevLesson = null;
                        $nextLesson = null;
                        foreach ($lessons as $index => $lesson) {
                            if ((int)$lesson['id'] === (int)$currentLesson['id']) {
                                if ($index > 0) $prevLesson = $lessons[$index - 1];
                                if ($index < count($lessons) - 1) $nextLesson = $lessons[$index + 1];
                                break;
                            }
                        }
                        ?>
                        <?php if ($prevLesson): ?>
                            <a href="<?php echo APP_BASE; ?>/classes/course-view.php?id=<?php echo (int)$classId; ?>&lesson=<?php echo (int)$prevLesson['id']; ?><?php echo $fromSuffix; ?>" class="lt-btn lt-btn-outline">← Previous lesson</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>

                        <?php if ($nextLesson): ?>
                            <a href="<?php echo APP_BASE; ?>/classes/course-view.php?id=<?php echo (int)$classId; ?>&lesson=<?php echo (int)$nextLesson['id']; ?><?php echo $fromSuffix; ?>" class="lt-btn lt-btn-primary">Next lesson →</a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="lt-empty">
                        <div class="lt-empty-icon">📚</div>
                        <h3>No lessons yet</h3>
                        <p>The teacher is preparing course content. Check back soon!</p>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="lt-course-sidebar">
                <h3><span class="lt-num">01</span> Lessons</h3>

                <?php if ($totalDocsAvailable > 0): ?>
                    <div class="lt-side-badge">📎 <?php echo $totalDocsAvailable; ?> document<?php echo $totalDocsAvailable != 1 ? 's' : ''; ?> in this course</div>
                <?php endif; ?>

                <div class="lt-progress-bar">
                    <div class="lt-progress-fill" style="width: <?php echo $progressPercent; ?>%;"></div>
                </div>
                <p class="lt-progress-meta">
                    <strong><?php echo $completedCount; ?></strong> of <strong><?php echo count($lessons); ?></strong> lessons completed
                </p>

                <div class="lt-lesson-list">
                    <?php foreach ($lessons as $index => $lesson):
                        $st           = $lessonState[(int)$lesson['id']] ?? ['canView' => false, 'reason' => 'unknown'];
                        $canView      = $st['canView'];
                        $reason       = $st['reason'];
                        $isCompleted  = isset($progress[(int)$lesson['id']]) && $progress[(int)$lesson['id']]['status'] === 'completed';
                        $isActive     = $currentLesson && (int)$currentLesson['id'] === (int)$lesson['id'];

                        $titleAttr = '';
                        if (!$canView) {
                            if ($reason === 'sequential') {
                                $titleAttr = 'Complete the previous lesson to unlock this one';
                            } elseif ($reason === 'not_enrolled') {
                                $titleAttr = 'Enroll to unlock this lesson';
                            } else {
                                $titleAttr = 'This lesson is locked by your teacher';
                            }
                        }
                    ?>
                        <div class="lt-lesson-item <?php echo $isActive ? 'lt-active' : ''; ?> <?php echo !$canView ? 'lt-locked' : ''; ?>"
                             <?php if ($titleAttr): ?>title="<?php echo htmlspecialchars($titleAttr, ENT_QUOTES); ?>"<?php endif; ?>
                             onclick="<?php echo $canView ? "location.href='" . APP_BASE . "/classes/course-view.php?id=" . (int)$classId . "&lesson=" . (int)$lesson['id'] . $fromSuffix . "'" : ''; ?>">
                            <div class="lt-lesson-icon">
                                <?php if ($isCompleted): ?>✅
                                <?php elseif (!empty($lesson['is_free_preview'])): ?>⭐
                                <?php elseif ($reason === 'sequential'): ?>⏳
                                <?php else: ?>🔒
                                <?php endif; ?>
                            </div>
                            <div class="lt-lesson-body">
                                <div class="lt-lesson-title"><?php echo ($index + 1) . '. ' . htmlspecialchars($lesson['title']); ?></div>
                                <?php if (!empty($lesson['duration'])): ?>
                                    <div class="lt-lesson-duration">⏱️ <?php echo htmlspecialchars($lesson['duration']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!$canView): ?>
                                <div class="lt-lesson-lock"><?php echo $reason === 'sequential' ? '⏳' : '🔒'; ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$isEnrolled && !$isTeacher && !$isAdmin && isset($_SESSION['user'])): ?>
                    <div class="lt-side-prompt">
                        <p>🔒 Enroll to unlock all lessons</p>
                        <a href="<?php echo APP_BASE; ?>/classes/class.php?id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary">Enroll now →</a>
                    </div>
                <?php elseif (!isset($_SESSION['user'])): ?>
                    <div class="lt-side-prompt">
                        <p>🔒 Log in to track your progress</p>
                        <a href="<?php echo APP_BASE; ?>/login.php" class="lt-btn lt-btn-primary">Log in</a>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</main>

<!-- DOCUMENT PREVIEW MODAL -->
<div id="docPreviewModal" class="lt-doc-modal" onclick="if(event.target===this) closeDocPreview();">
    <div class="lt-doc-modal-box" onclick="event.stopPropagation();">
        <div class="lt-doc-modal-head">
            <div>
                <h3 class="lt-doc-modal-title" id="docPreviewTitle">Document</h3>
                <div class="lt-doc-modal-meta" id="docPreviewMeta"></div>
            </div>
            <div class="lt-doc-modal-actions">
                <button type="button"
                        id="docPreviewFullscreen"
                        class="lt-btn lt-btn-outline lt-btn-sm"
                        onclick="toggleDocFullscreen()"
                        title="Fullscreen (F)">
                    ⛶ Fullscreen
                </button>
                <a href="#" id="docPreviewDownload" class="lt-btn lt-btn-outline lt-btn-sm" target="_blank" rel="noopener">⬇️ Download</a>
                <button type="button" class="lt-doc-modal-close" onclick="closeDocPreview()" title="Close (Esc)">×</button>
            </div>
        </div>
        <div class="lt-doc-modal-body" id="docPreviewBody"></div>
    </div>
</div>

<script>
/* ---------- Video progress tracker ---------- */
(function () {
    const video = document.getElementById('lessonVideo');
    if (!video) return;

    const lessonId = parseInt(video.dataset.lessonId || '0', 10);
    const classId  = parseInt(video.dataset.classId  || '0', 10);
    const resumeAt = parseInt(video.dataset.resume   || '0', 10);
    const csrf     = <?php echo json_encode($csrfToken); ?>;

    if (!lessonId || !classId) return;

    video.addEventListener('loadedmetadata', function () {
        if (resumeAt > 0 && resumeAt < video.duration - 5) {
            try { video.currentTime = resumeAt; } catch (e) {}
        }
    });

    let saveTimeout = null;
    let lastSent = 0;

    function sendProgress(position, duration) {
        if (!duration || isNaN(duration) || duration <= 0) return;
        if (!position || isNaN(position) || position < 0) return;

        fetch('<?php echo APP_BASE; ?>/classes/mark-lesson-progress.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                lesson_id: lessonId,
                class_id:  classId,
                position:  Math.floor(position),
                duration:  Math.floor(duration)
            })
        }).catch(function () {});
    }

    video.addEventListener('timeupdate', function () {
        if (video.paused || video.seeking) return;
        const pct = video.duration > 0 ? (video.currentTime / video.duration) * 100 : 0;
        if (pct < 10) return;

        if (saveTimeout) clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function () {
            const now = Date.now();
            if (now - lastSent < 5000) return;
            lastSent = now;
            sendProgress(video.currentTime, video.duration);
        }, 500);
    });

    video.addEventListener('pause', function () {
        sendProgress(video.currentTime, video.duration);
    });

    window.addEventListener('beforeunload', function () {
        if (!video.paused && video.duration > 0) {
            const payload = JSON.stringify({
                lesson_id: lessonId,
                class_id:  classId,
                position:  Math.floor(video.currentTime),
                duration:  Math.floor(video.duration)
            });
            if (navigator.sendBeacon) {
                const blob = new Blob([payload], { type: 'application/json' });
                navigator.sendBeacon('<?php echo APP_BASE; ?>/classes/mark-lesson-progress.php?_csrf=' + encodeURIComponent(csrf), blob);
            }
        }
    });
})();

/* ---------- Document preview ---------- */

const PREVIEWABLE_MIMES = [
    'application/pdf',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'text/plain', 'text/csv'
];

function isPreviewable(mime) {
    return PREVIEWABLE_MIMES.indexOf(mime) !== -1;
}

function openDocPreviewFromAttrs(btn) {
    openDocPreview(
        btn.dataset.previewId,
        btn.dataset.previewTitle || 'Document',
        btn.dataset.previewMime  || 'application/octet-stream',
        btn.dataset.previewName  || ''
    );
}

function openDocPreview(id, title, mime, filename) {
    const modal   = document.getElementById('docPreviewModal');
    const titleEl = document.getElementById('docPreviewTitle');
    const metaEl  = document.getElementById('docPreviewMeta');
    const bodyEl  = document.getElementById('docPreviewBody');
    const dlEl    = document.getElementById('docPreviewDownload');

    const base = '<?php echo APP_BASE; ?>';
    const previewUrl  = base + '/classes/documents/preview.php?id=' + encodeURIComponent(id);
    const downloadUrl = base + '/classes/documents/download.php?id=' + encodeURIComponent(id);

    titleEl.textContent = title;
    metaEl.textContent  = filename + (filename ? ' · ' : '') + (mime || 'file');
    dlEl.href = downloadUrl;

    bodyEl.innerHTML = '';

    if (isPreviewable(mime)) {
        const iframe = document.createElement('iframe');
        iframe.src = previewUrl;
        iframe.setAttribute('title', title);
        bodyEl.appendChild(iframe);
    } else {
        const icon = mime.indexOf('word') !== -1          ? '📄'
                   : mime.indexOf('presentation') !== -1  ? '📊'
                   : mime.indexOf('sheet') !== -1         ? '📈'
                   : mime.indexOf('zip') !== -1           ? '🗜️'
                   : '📎';

        bodyEl.innerHTML = `
            <div class="lt-doc-modal-nopreview">
                <div class="lt-big-icon">${icon}</div>
                <h3>Preview not available for this file type</h3>
                <p>${escapeHtml(filename || 'This file')} is a ${escapeHtml(mime || 'binary')} file. Download it to open it in your word processor, spreadsheet, or presentation app.</p>
                <a href="${downloadUrl}" class="lt-btn lt-btn-primary" target="_blank" rel="noopener">⬇️ Download file</a>
            </div>
        `;
    }

    modal.classList.add('lt-open');
    document.body.style.overflow = 'hidden';

    updateFullscreenButton();
}

function closeDocPreview() {
    const modal  = document.getElementById('docPreviewModal');
    const bodyEl = document.getElementById('docPreviewBody');

    /* If native fullscreen is active, exit it first */
    if (isNativeFullscreen()) {
        Promise.resolve(exitNativeFullscreen()).catch(function () {});
    }

    /* Stop any running PDF reader / media */
    bodyEl.innerHTML = '';

    /* Clear both fullscreen modes */
    modal.classList.remove('lt-fullscreen');
    modal.classList.remove('lt-open');
    document.body.style.overflow = '';

    updateFullscreenButton();
}

/* ---------- Native browser fullscreen ---------- */

function requestNativeFullscreen(el) {
    const fn = el.requestFullscreen
            || el.webkitRequestFullscreen
            || el.mozRequestFullScreen
            || el.msRequestFullscreen;
    if (!fn) return Promise.reject('unsupported');
    return fn.call(el);
}

function exitNativeFullscreen() {
    const fn = document.exitFullscreen
            || document.webkitExitFullscreen
            || document.mozCancelFullScreen
            || document.msExitFullscreen;
    if (!fn) return Promise.reject('unsupported');
    return fn.call(document);
}

function isNativeFullscreen() {
    return !!(document.fullscreenElement
           || document.webkitFullscreenElement
           || document.mozFullScreenElement
           || document.msFullscreenElement);
}

function toggleDocFullscreen() {
    const modal = document.getElementById('docPreviewModal');
    if (!modal || !modal.classList.contains('lt-open')) return;

    const box = modal.querySelector('.lt-doc-modal-box');
    if (!box) return;

    if (isNativeFullscreen()) {
        Promise.resolve(exitNativeFullscreen()).catch(function () {
            modal.classList.remove('lt-fullscreen');
            updateFullscreenButton();
        });
    } else {
        requestNativeFullscreen(box).catch(function () {
            /* Fallback to CSS-only fullscreen */
            modal.classList.add('lt-fullscreen');
            updateFullscreenButton();
        });
    }
}

function updateFullscreenButton() {
    const btn = document.getElementById('docPreviewFullscreen');
    if (!btn) return;
    if (isNativeFullscreen()) {
        btn.innerHTML = '⤡ Exit fullscreen';
        btn.title = 'Exit fullscreen (Esc)';
    } else {
        btn.innerHTML = '⛶ Fullscreen';
        btn.title = 'Fullscreen (F)';
    }
}

/* Keep button label in sync with native events */
['fullscreenchange', 'webkitfullscreenchange',
 'mozfullscreenchange', 'MSFullscreenChange'].forEach(function (evt) {
    document.addEventListener(evt, function () {
        const modal = document.getElementById('docPreviewModal');
        if (!modal || !modal.classList.contains('lt-open')) return;
        updateFullscreenButton();
    });
});

/* ---------- Keyboard shortcuts ---------- */

document.addEventListener('keydown', function (e) {
    const modal = document.getElementById('docPreviewModal');
    if (!modal || !modal.classList.contains('lt-open')) return;

    const tag = (e.target.tagName || '').toLowerCase();
    const typing = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;
    if (typing) return;

    if (e.key === 'Escape') {
        /* Note: if native fullscreen is active, the browser consumes Esc
           itself and exits fullscreen before this handler runs — that's
           intentional and matches YouTube/Drive behaviour. */
        if (modal.classList.contains('lt-fullscreen')) {
            modal.classList.remove('lt-fullscreen');
            updateFullscreenButton();
        } else {
            closeDocPreview();
        }
    } else if (e.key === 'f' || e.key === 'F') {
        e.preventDefault();
        toggleDocFullscreen();
    }
});

/* ---------- HTML escape helper ---------- */
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
</script>

<?php require_once '../includes/footer.php'; ?>