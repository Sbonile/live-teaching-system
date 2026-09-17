<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'student') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$studentId = (int)$_SESSION['user']['id'];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE additions
$whereExtra = "";
$params = [];
$types = '';

if ($filter == 'upcoming') {
    $whereExtra .= " AND c.status = 'upcoming'";
} elseif ($filter == 'ongoing') {
    $whereExtra .= " AND c.status = 'ongoing'";
} elseif ($filter == 'completed') {
    $whereExtra .= " AND c.status = 'completed'";
}

if ($search !== '') {
    $whereExtra .= " AND (c.title LIKE ? OR c.description LIKE ? OR u.fullname LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

// Subqueries use the student's id — bind it three times for the three subqueries
// then once for the WHERE clause. Order matters.
$sql = "
    SELECT e.*,
           c.title, c.description, c.short_description, c.start_date, c.end_date,
           c.status as class_status, c.meeting_link, c.recording_url,
           c.duration, c.level, c.category, c.price, c.thumbnail, c.max_students, c.current_students,
           c.teacher_id, u.fullname as teacher_name, u.email as teacher_email,
           (SELECT COUNT(*) FROM attendance a WHERE a.student_id = ? AND a.class_id = c.id AND a.status = 'present') as sessions_attended,
           (SELECT COUNT(*) FROM attendance a WHERE a.student_id = ? AND a.class_id = c.id) as total_sessions,
           (SELECT COUNT(*) FROM attendance a WHERE a.class_id = c.id) as class_total_sessions,
           (SELECT AVG(rating) FROM reviews WHERE class_id = c.id) as class_rating,
           (SELECT COUNT(*) FROM reviews WHERE class_id = c.id) as total_reviews,
           (SELECT COUNT(*) FROM course_lessons l WHERE l.class_id = c.id AND (l.status = 'published' OR l.is_free_preview = 1)) as published_lessons,
           (SELECT COUNT(*) FROM lesson_progress p WHERE p.student_id = ? AND p.class_id = c.id AND p.status = 'completed') as lessons_completed
    FROM enrollments e
    JOIN live_classes c ON e.class_id = c.id
    JOIN users u ON c.teacher_id = u.id
    WHERE e.student_id = ? AND e.payment_status = 'paid' $whereExtra
    ORDER BY
        CASE c.status
            WHEN 'ongoing' THEN 1
            WHEN 'upcoming' THEN 2
            ELSE 3
        END,
        c.start_date ASC
";

// Build final param list in order: subquery students (3x), then WHERE student_id, then search
$bindParams = [$studentId, $studentId, $studentId, $studentId];
$bindTypes = 'iiii';
foreach ($params as $p) {
    $bindParams[] = $p;
}
$bindTypes .= $types;

$stmt = $connection->prepare($sql);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$totalClasses = count($classes);
$completedClasses = count(array_filter($classes, function($c) { return $c['class_status'] == 'completed'; }));
$ongoingClasses = count(array_filter($classes, function($c) { return $c['class_status'] == 'ongoing'; }));
$upcomingClasses = count(array_filter($classes, function($c) { return $c['class_status'] == 'upcoming'; }));
$totalCertificates = count(array_filter($classes, function($c) { return !empty($c['certificate_issued']); }));

// Upcoming sessions (next 7 days)
$stmt = $connection->prepare("
    SELECT a.session_date, c.title as class_title, c.id as class_id
    FROM attendance a
    JOIN live_classes c ON a.class_id = c.id
    WHERE a.student_id = ?
        AND a.session_date > NOW()
        AND a.session_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY a.session_date ASC
    LIMIT 5
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$upcomingSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<style>
/* ===== LiveTeach my-classes revamp — scoped to .lt-page ===== */
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

.lt-container {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ---------- Header ---------- */
.lt-dash-header {
    padding: 56px 0 44px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
}

.lt-dash-header::before {
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
    width: 7px;
    height: 7px;
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

.lt-header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ---------- Buttons ---------- */
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

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover { background: var(--lt-flame-bright); }

.lt-btn-gold {
    background: var(--lt-gold);
    color: var(--lt-ink);
}
.lt-btn-gold:hover { background: #E8B85A; }

.lt-btn-outline {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
}

.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

/* ---------- Stats strip ---------- */
.lt-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-bottom: 32px;
}

.lt-stat {
    padding: 26px 22px;
    border-right: 1px solid var(--lt-line);
    position: relative;
}

.lt-stat:last-child { border-right: none; }

.lt-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 22px;
    width: 26px;
    height: 2px;
    background: var(--lt-flame);
}

.lt-stat-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.9rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
}

.lt-stat-label {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
}

/* ---------- Filter bar ---------- */
.lt-filter-bar {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.lt-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.lt-tab {
    padding: 8px 16px;
    background: transparent;
    border: 1px solid var(--lt-line);
    border-radius: 999px;
    color: var(--lt-parchment-dim);
    font-size: 0.82rem;
    font-weight: 600;
    transition: all 0.15s ease;
}

.lt-tab:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
}

.lt-tab.lt-active {
    background: var(--lt-flame);
    border-color: var(--lt-flame);
    color: var(--lt-parchment);
}

.lt-search-form {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.lt-search-input {
    padding: 9px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.85rem;
    font-family: inherit;
    min-width: 220px;
    transition: border-color 0.15s ease;
    box-sizing: border-box;
}

.lt-search-input::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-search-input:focus {
    outline: none;
    border-color: var(--lt-flame);
}

/* ---------- Section head ---------- */
.lt-section {
    padding-bottom: 40px;
}

.lt-section-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-section-head h2 {
    font-size: 1.2rem;
    margin: 0;
    color: var(--lt-parchment);
}

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

/* ---------- Class cards ---------- */
.lt-class {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    margin-bottom: 16px;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.lt-class:hover { border-color: var(--lt-gold); }

.lt-class-head {
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    cursor: pointer;
    user-select: none;
}

.lt-class-head-left {
    min-width: 0;
    flex: 1;
}

.lt-class-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.15rem;
    color: var(--lt-parchment);
    margin: 0 0 10px;
    line-height: 1.3;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.lt-class-meta {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
}

.lt-class-head-right {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}

.lt-class-rating {
    font-size: 0.85rem;
    color: var(--lt-gold);
    letter-spacing: 1px;
    text-align: right;
}

.lt-class-rating small {
    font-size: 0.7rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0;
}

.lt-class-progress-mini {
    text-align: right;
}

.lt-class-progress-mini .lt-progress-val {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.1rem;
    color: var(--lt-gold);
    line-height: 1;
    margin-bottom: 4px;
}

.lt-class-progress-mini .lt-progress-lbl {
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    font-weight: 600;
}

.lt-chevron {
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    transition: transform 0.25s ease, color 0.15s ease;
    width: 22px;
    text-align: center;
}

.lt-class.lt-open .lt-chevron {
    transform: rotate(180deg);
    color: var(--lt-gold);
}

.lt-class-body {
    display: none;
    padding: 22px 24px;
    border-top: 1px solid var(--lt-line);
}

.lt-class.lt-open .lt-class-body {
    display: block;
}

.lt-class-desc {
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    line-height: 1.7;
    margin: 0 0 20px;
}

.lt-progress-block {
    margin-bottom: 20px;
}

.lt-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
}

.lt-progress-label span:last-child {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    color: var(--lt-gold);
}

.lt-progress-track {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    height: 6px;
    overflow: hidden;
}

.lt-progress-fill {
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame));
    height: 100%;
    border-radius: 6px;
    transition: width 0.4s ease;
}

.lt-progress-meta {
    font-size: 0.75rem;
    color: var(--lt-parchment-dim);
    margin-top: 8px;
}

.lt-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.lt-info-item {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 16px 18px;
}

.lt-info-label {
    font-size: 0.7rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-gold);
    margin-bottom: 12px;
    font-weight: 700;
}

.lt-info-value {
    font-size: 0.86rem;
    color: var(--lt-parchment);
    line-height: 1.7;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.lt-info-value .lt-iv-icon {
    flex-shrink: 0;
    opacity: 0.85;
    width: 18px;
    text-align: center;
}

.lt-info-value.lt-warn {
    color: var(--lt-gold);
}

.lt-class-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 18px;
    border-top: 1px solid var(--lt-line);
}

.lt-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.66rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    white-space: nowrap;
    border: 1px solid transparent;
}

.lt-status-upcoming {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.35);
}

.lt-status-ongoing {
    background: rgba(200, 52, 30, 0.18);
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.45);
}

.lt-status-ongoing .lt-status-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.5s infinite;
}

.lt-status-completed,
.lt-status-cancelled {
    background: rgba(241, 231, 214, 0.06);
    color: var(--lt-parchment-dim);
    border-color: var(--lt-line);
}

.lt-status-certificate {
    background: rgba(217, 164, 65, 0.2);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.5);
}

.lt-sessions {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 26px;
    position: relative;
    overflow: hidden;
}

.lt-sessions::before {
    content: "";
    position: absolute;
    top: -120px;
    right: -120px;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(217, 164, 65, 0.12), transparent 70%);
    pointer-events: none;
}

.lt-session-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 14px 0;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    z-index: 1;
}

.lt-session-row:last-child { border-bottom: none; }
.lt-session-row:first-of-type { padding-top: 0; }

.lt-session-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.98rem;
    color: var(--lt-parchment);
    margin: 0 0 4px;
}

.lt-session-date {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
}

.lt-empty {
    text-align: center;
    padding: 72px 32px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 14px;
}

.lt-empty-icon {
    font-size: 2.8rem;
    margin-bottom: 16px;
    opacity: 0.6;
}

.lt-empty h3 {
    font-size: 1.25rem;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-empty p {
    color: var(--lt-parchment-dim);
    margin: 0 0 24px;
    font-size: 0.92rem;
    line-height: 1.65;
}

@media (max-width: 960px) {
    .lt-stats-grid { grid-template-columns: repeat(3, 1fr); }
    .lt-stat:nth-child(3) { border-right: none; }
    .lt-stat:nth-child(1),
    .lt-stat:nth-child(2),
    .lt-stat:nth-child(3) { border-bottom: 1px solid var(--lt-line); }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
}

@media (max-width: 640px) {
    .lt-filter-bar { flex-direction: column; align-items: stretch; }
    .lt-search-form { width: 100%; }
    .lt-search-input { flex: 1; min-width: 0; }
}

@media (max-width: 560px) {
    .lt-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .lt-stat { border-right: 1px solid var(--lt-line); }
    .lt-stat:nth-child(2n) { border-right: none; }
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-class-head { padding: 18px 20px; }
    .lt-class-body { padding: 18px 20px; }
    .lt-class-head-right { width: 100%; justify-content: space-between; }
    .lt-class-actions { flex-direction: column; }
    .lt-class-actions .lt-btn { width: 100%; }
    .lt-session-row { flex-direction: column; align-items: flex-start; }
    .lt-session-row .lt-btn { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-status-ongoing .lt-status-dot { animation: none; }
    .lt-chevron { transition: none; }
    .lt-progress-fill { transition: none; }
}
</style>

<main class="lt-page">
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    My classes
                </span>
                <h1>Enrolled classes.</h1>
                <p>Manage and track all your enrolled classes.</p>
            </div>
            <div class="lt-header-actions">
                <a href="index.php" class="lt-btn lt-btn-outline">← Dashboard</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-stats-grid" style="margin-top: 32px;">
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$totalClasses; ?></div>
                <div class="lt-stat-label">Total enrolled</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$ongoingClasses; ?></div>
                <div class="lt-stat-label">🟢 In progress</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$upcomingClasses; ?></div>
                <div class="lt-stat-label">📅 Upcoming</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$completedClasses; ?></div>
                <div class="lt-stat-label">✅ Completed</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$totalCertificates; ?></div>
                <div class="lt-stat-label">🏆 Certificates</div>
            </div>
        </div>

        <div class="lt-filter-bar">
            <div class="lt-tabs">
                <a href="?filter=all<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"
                   class="lt-tab <?php echo $filter == 'all' ? 'lt-active' : ''; ?>">All classes</a>
                <a href="?filter=ongoing<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"
                   class="lt-tab <?php echo $filter == 'ongoing' ? 'lt-active' : ''; ?>">🟢 In progress</a>
                <a href="?filter=upcoming<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"
                   class="lt-tab <?php echo $filter == 'upcoming' ? 'lt-active' : ''; ?>">📅 Upcoming</a>
                <a href="?filter=completed<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"
                   class="lt-tab <?php echo $filter == 'completed' ? 'lt-active' : ''; ?>">✅ Completed</a>
            </div>

            <form method="get" class="lt-search-form">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="search" class="lt-search-input" placeholder="🔍 Search classes..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="lt-btn lt-btn-outline lt-btn-sm">Search</button>
                <?php if ($search): ?>
                    <a href="?filter=<?php echo htmlspecialchars($filter); ?>" class="lt-btn lt-btn-outline lt-btn-sm">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($classes)): ?>
            <div class="lt-empty">
                <div class="lt-empty-icon">📭</div>
                <h3>No classes found</h3>
                <p>
                    <?php if ($search): ?>
                        No classes match your search criteria.
                    <?php elseif ($filter != 'all'): ?>
                        You have no <?php echo htmlspecialchars($filter); ?> classes.
                    <?php else: ?>
                        You haven't enrolled in any classes yet.
                    <?php endif; ?>
                </p>
                <a href="../classes/index.php" class="lt-btn lt-btn-primary">Browse classes →</a>
            </div>
        <?php else: ?>
            <?php foreach ($classes as $class):
                $progress = $class['total_sessions'] > 0 ? round(($class['sessions_attended'] / $class['total_sessions']) * 100) : 0;
                $daysUntilStart = ceil((strtotime($class['start_date']) - time()) / (60 * 60 * 24));
                $isAlmostFull = $class['current_students'] >= $class['max_students'] * 0.8;

                $statusLabels = [
                    'ongoing' => 'In progress',
                    'upcoming' => 'Upcoming',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ];
                $statusLabel = $statusLabels[$class['class_status']] ?? ucfirst($class['class_status']);

                $hasLessons = (int)$class['published_lessons'] > 0;
                $hasStartedCourse = (int)$class['lessons_completed'] > 0;
                $allLessonsDone = $hasLessons && (int)$class['lessons_completed'] >= (int)$class['published_lessons'];
            ?>
                <div class="lt-class">
                    <div class="lt-class-head" onclick="toggleDetails(this)">
                        <div class="lt-class-head-left">
                            <h3 class="lt-class-title">
                                <?php echo htmlspecialchars($class['title']); ?>
                                <span class="lt-status lt-status-<?php echo htmlspecialchars($class['class_status']); ?>">
                                    <?php if ($class['class_status'] == 'ongoing'): ?>
                                        <span class="lt-status-dot"></span>
                                    <?php endif; ?>
                                    <?php echo $statusLabel; ?>
                                </span>
                            </h3>
                            <div class="lt-class-meta">
                                <span>👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                <span>📅 <?php echo date('M d, Y', strtotime($class['start_date'])); ?></span>
                                <span>⏱️ <?php echo htmlspecialchars($class['duration'] ?: 'Self-paced'); ?></span>
                                <span>📊 <?php echo ucfirst($class['level']); ?></span>
                            </div>
                        </div>
                        <div class="lt-class-head-right">
                            <?php if ($class['class_rating']): ?>
                                <div class="lt-class-rating">
                                    <?php
                                    $rating = round($class['class_rating']);
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo $i <= $rating ? '★' : '☆';
                                    }
                                    ?>
                                    <br><small>(<?php echo (int)$class['total_reviews']; ?> reviews)</small>
                                </div>
                            <?php endif; ?>
                            <div class="lt-class-progress-mini">
                                <div class="lt-progress-val"><?php echo $progress; ?>%</div>
                                <div class="lt-progress-lbl">Progress</div>
                            </div>
                            <span class="lt-chevron">▼</span>
                        </div>
                    </div>

                    <div class="lt-class-body">
                        <p class="lt-class-desc">
                            <?php echo nl2br(htmlspecialchars(substr((string)($class['short_description'] ?? $class['description']), 0, 200))); ?>
                            <?php if (strlen((string)($class['description'] ?? '')) > 200): ?>...<?php endif; ?>
                        </p>

                        <div class="lt-progress-block">
                            <div class="lt-progress-label">
                                <span>📊 Your learning progress</span>
                                <span><?php echo $progress; ?>% complete</span>
                            </div>
                            <div class="lt-progress-track">
                                <div class="lt-progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                            </div>
                            <div class="lt-progress-meta">
                                <?php echo (int)$class['sessions_attended']; ?> of <?php echo (int)$class['total_sessions']; ?> sessions attended
                            </div>
                        </div>

                        <div class="lt-info-grid">
                            <div class="lt-info-item">
                                <div class="lt-info-label">Class details</div>
                                <div class="lt-info-value"><span class="lt-iv-icon">📂</span> <?php echo htmlspecialchars($class['category'] ?: 'General'); ?></div>
                                <div class="lt-info-value"><span class="lt-iv-icon">💰</span> R <?php echo number_format((float)$class['price'], 2); ?></div>
                                <div class="lt-info-value"><span class="lt-iv-icon">👥</span> <?php echo (int)$class['current_students']; ?>/<?php echo (int)$class['max_students']; ?> students</div>
                            </div>
                            <div class="lt-info-item">
                                <div class="lt-info-label">Schedule</div>
                                <div class="lt-info-value"><span class="lt-iv-icon">📅</span> <?php echo date('l, M d, Y', strtotime($class['start_date'])); ?></div>
                                <div class="lt-info-value"><span class="lt-iv-icon">⏰</span> <?php echo date('h:i A', strtotime($class['start_date'])); ?></div>
                                <?php if ($class['class_status'] == 'upcoming' && $daysUntilStart > 0): ?>
                                    <div class="lt-info-value"><span class="lt-iv-icon">⏳</span> Starts in <?php echo $daysUntilStart; ?> day<?php echo $daysUntilStart != 1 ? 's' : ''; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="lt-info-item">
                                <div class="lt-info-label">Teacher</div>
                                <div class="lt-info-value"><span class="lt-iv-icon">👨‍🏫</span> <?php echo htmlspecialchars($class['teacher_name']); ?></div>
                                <div class="lt-info-value"><span class="lt-iv-icon">📧</span> <?php echo htmlspecialchars($class['teacher_email']); ?></div>
                            </div>
                            <div class="lt-info-item">
                                <div class="lt-info-label">Course content</div>
                                <div class="lt-info-value">
                                    <span class="lt-iv-icon">📚</span>
                                    <?php if ($hasLessons): ?>
                                        <?php echo (int)$class['lessons_completed']; ?> of <?php echo (int)$class['published_lessons']; ?> lessons completed
                                    <?php else: ?>
                                        Lessons coming soon
                                    <?php endif; ?>
                                </div>
                                <?php if ($hasLessons): ?>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon"><?php echo $allLessonsDone ? '🎉' : '📖'; ?></span>
                                        <?php if ($allLessonsDone): ?>
                                            All lessons complete!
                                        <?php elseif ($hasStartedCourse): ?>
                                            Pick up where you left off
                                        <?php else: ?>
                                            Start with the first lesson
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="lt-info-item">
                                <div class="lt-info-label">Your status</div>
                                <div class="lt-info-value">
                                    <?php if (!empty($class['certificate_issued'])): ?>
                                        🏆 Certificate earned
                                    <?php elseif ($class['class_status'] == 'completed' && $progress >= 80): ?>
                                        ✅ Ready for certificate
                                    <?php elseif ($class['class_status'] == 'ongoing'): ?>
                                        🔴 Actively learning
                                    <?php elseif ($class['class_status'] == 'upcoming'): ?>
                                        📅 Not started yet
                                    <?php else: ?>
                                        📖 In progress
                                    <?php endif; ?>
                                </div>
                                <?php if ($isAlmostFull && $class['class_status'] == 'upcoming'): ?>
                                    <div class="lt-info-value lt-warn"><span class="lt-iv-icon">⚠️</span> Class almost full</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="lt-class-actions">
                            <?php if ($class['class_status'] == 'ongoing' && $class['meeting_link']): ?>
                                <a href="../classes/join.php?class_id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-primary lt-btn-sm">
                                    🔴 Join live class
                                </a>
                            <?php endif; ?>

                            <?php if ($hasLessons): ?>
                                <a href="../classes/course-view.php?id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-primary lt-btn-sm">
                                    📚 <?php echo $hasStartedCourse ? 'Continue course' : 'Start course'; ?>
                                    (<?php echo (int)$class['lessons_completed']; ?>/<?php echo (int)$class['published_lessons']; ?>)
                                </a>
                            <?php endif; ?>

                            <?php if ($class['class_status'] == 'upcoming'): ?>
                                <a href="../classes/class.php?id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">
                                    📅 View class details
                                </a>
                                <?php if ($class['meeting_link']): ?>
                                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-outline lt-btn-sm">
                                        🔗 Meeting link
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($class['class_status'] == 'completed' && $class['recording_url']): ?>
                                <a href="<?php echo htmlspecialchars($class['recording_url']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-gold lt-btn-sm">
                                    📹 Watch recordings
                                </a>
                            <?php endif; ?>

                            <a href="attendance.php?class_id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">
                                📋 View attendance
                            </a>

                            <?php if ($class['class_status'] == 'completed' && empty($class['certificate_issued']) && $progress >= 80): ?>
                                <a href="request-certificate.php?class_id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-gold lt-btn-sm">
                                    🏆 Request certificate
                                </a>
                            <?php endif; ?>

                            <?php if ($class['recording_url']): ?>
                                <a href="<?php echo htmlspecialchars($class['recording_url']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-outline lt-btn-sm">
                                    📹 Recording available
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($upcomingSessions)): ?>
            <div class="lt-section" style="margin-top: 44px;">
                <div class="lt-section-head">
                    <span class="lt-num">01</span>
                    <h2>Upcoming sessions</h2>
                    <span class="lt-count-pill">next 7 days</span>
                </div>
                <div class="lt-sessions">
                    <?php foreach ($upcomingSessions as $session): ?>
                        <div class="lt-session-row">
                            <div>
                                <h4 class="lt-session-title"><?php echo htmlspecialchars($session['class_title']); ?></h4>
                                <div class="lt-session-date"><?php echo date('l, M d, Y', strtotime($session['session_date'])); ?></div>
                            </div>
                            <a href="../classes/class.php?id=<?php echo (int)$session['class_id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">View class</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
function toggleDetails(element) {
    const card = element.closest('.lt-class');
    if (card) {
        card.classList.toggle('lt-open');
    }
}

<?php if (isset($_GET['expand']) && $_GET['expand'] == 'true'): ?>
    document.querySelectorAll('.lt-class').forEach(card => {
        card.classList.add('lt-open');
    });
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>