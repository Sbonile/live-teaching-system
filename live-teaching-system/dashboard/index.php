<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'student') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$studentId  = (int)$_SESSION['user']['id'];

/* ---------- Student profile ---------- */
$stmt = $connection->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    /* Session points at a user that no longer exists */
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/* ---------- Enrolled classes with per-class progress ---------- */
$stmt = $connection->prepare("
    SELECT e.*,
           c.id AS class_id,
           c.title, c.description, c.start_date, c.end_date,
           c.status AS class_status, c.meeting_link, c.recording_url,
           c.duration, c.level, c.category, c.price, c.thumbnail,
           c.teacher_id,
           u.fullname AS teacher_name,
           (SELECT COUNT(*) FROM attendance a
              WHERE a.student_id = ? AND a.class_id = c.id AND a.status = 'present') AS sessions_attended,
           (SELECT COUNT(*) FROM attendance a
              WHERE a.student_id = ? AND a.class_id = c.id) AS total_sessions,
           (SELECT COUNT(*) FROM course_lessons l
              WHERE l.class_id = c.id AND (l.status = 'published' OR l.is_free_preview = 1)) AS published_lessons,
           (SELECT COUNT(*) FROM lesson_progress p
              WHERE p.student_id = ? AND p.class_id = c.id AND p.status = 'completed') AS lessons_completed
    FROM enrollments e
    JOIN live_classes c ON e.class_id = c.id
    JOIN users u ON c.teacher_id = u.id
    WHERE e.student_id = ? AND e.payment_status = 'paid'
    ORDER BY
        CASE c.status
            WHEN 'ongoing' THEN 1
            WHEN 'upcoming' THEN 2
            ELSE 3
        END,
        c.start_date ASC
");
$stmt->bind_param('iiii', $studentId, $studentId, $studentId, $studentId);
$stmt->execute();
$enrolledClasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Attendance summary ---------- */
$stmt = $connection->prepare("
    SELECT
        COUNT(CASE WHEN status = 'present' THEN 1 END) AS present_count,
        COUNT(CASE WHEN status = 'late'    THEN 1 END) AS late_count,
        COUNT(CASE WHEN status = 'absent'  THEN 1 END) AS absent_count,
        COUNT(*) AS total_sessions
    FROM attendance
    WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$attendanceSummary = $stmt->get_result()->fetch_assoc() ?: [
    'present_count' => 0, 'late_count' => 0, 'absent_count' => 0, 'total_sessions' => 0,
];
$stmt->close();

/* Normalise to integers so arithmetic is consistent */
$attendanceSummary['present_count']  = (int)$attendanceSummary['present_count'];
$attendanceSummary['late_count']     = (int)$attendanceSummary['late_count'];
$attendanceSummary['absent_count']   = (int)$attendanceSummary['absent_count'];
$attendanceSummary['total_sessions'] = (int)$attendanceSummary['total_sessions'];

/* ---------- Certificates ---------- */
$stmt = $connection->prepare("
    SELECT COUNT(*) AS c FROM enrollments
    WHERE student_id = ? AND certificate_issued = 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$certificatesCount = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

/* ---------- Total spent ---------- */
$stmt = $connection->prepare("
    SELECT COALESCE(SUM(amount_paid), 0) AS total
    FROM enrollments
    WHERE student_id = ? AND payment_status = 'paid'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$totalSpent = (float)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

/* ---------- Recent activity (last 10 attendance rows) ---------- */
$stmt = $connection->prepare("
    SELECT a.*, c.title AS class_title
    FROM attendance a
    JOIN live_classes c ON a.class_id = c.id
    WHERE a.student_id = ?
    ORDER BY a.session_date DESC, a.id DESC
    LIMIT 10
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$recentActivity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Split enrolled classes by status ---------- */
$upcomingClasses  = [];
$ongoingClasses   = [];
$completedClasses = [];

foreach ($enrolledClasses as $cls) {
    switch ($cls['class_status']) {
        case 'upcoming':
            if (strtotime($cls['start_date']) > time()) {
                $upcomingClasses[] = $cls;
            }
            break;
        case 'ongoing':
            $ongoingClasses[] = $cls;
            break;
        case 'completed':
            $completedClasses[] = $cls;
            break;
    }
}

/* ---------- Overall progress across all enrolled classes ---------- */
$totalProgress = 0;
if (!empty($enrolledClasses)) {
    $progressSum = 0;
    foreach ($enrolledClasses as $cls) {
        $total = (int)$cls['total_sessions'];
        if ($total > 0) {
            $classProgress = ((int)$cls['sessions_attended'] / $total) * 100;
            $progressSum += min(100, $classProgress);
        }
    }
    $totalProgress = (int)round($progressSum / count($enrolledClasses));
}

/* ---------- Recommended classes ---------- */
$recommendedClasses = [];
$studentCategories  = array_filter(array_unique(array_column($enrolledClasses, 'category')));

if (!empty($studentCategories)) {
    /* Take up to 3 categories — bound as placeholders */
    $cats = array_slice(array_values($studentCategories), 0, 3);
    $placeholders = implode(',', array_fill(0, count($cats), '?'));

    $sql = "
        SELECT c.*, u.fullname AS teacher_name,
               (SELECT COUNT(*) FROM enrollments
                  WHERE class_id = c.id AND payment_status = 'paid') AS enrolled_count
        FROM live_classes c
        JOIN users u ON c.teacher_id = u.id
        WHERE c.category IN ($placeholders)
          AND c.id NOT IN (
              SELECT class_id FROM enrollments WHERE student_id = ?
          )
          AND c.status NOT IN ('cancelled', 'completed')
        ORDER BY c.start_date ASC
        LIMIT 3
    ";
    $stmt = $connection->prepare($sql);

    /* Build bind types: categories are strings, studentId is int */
    $types = str_repeat('s', count($cats)) . 'i';
    $params = array_merge($cats, [$studentId]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $recommendedClasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<style>
/* ===== LiveTeach student dashboard revamp — scoped to .lt-page ===== */
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

.lt-progress-badge {
    text-align: center;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 18px 26px;
    flex-shrink: 0;
}

.lt-progress-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 2rem;
    color: var(--lt-gold);
    line-height: 1;
    margin-bottom: 6px;
}

.lt-progress-label {
    font-size: 0.72rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    font-weight: 600;
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.85rem;
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
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-bottom: 40px;
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

/* ---------- Section head ---------- */
.lt-section { padding-bottom: 44px; }

.lt-section-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
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

/* ---------- Live section ---------- */
.lt-live {
    background: var(--lt-charcoal);
    border: 1px solid rgba(200, 52, 30, 0.5);
    border-radius: 12px;
    padding: 24px 26px;
    margin-bottom: 40px;
    position: relative;
    overflow: hidden;
}

.lt-live::before {
    content: "";
    position: absolute;
    top: -140px;
    right: -120px;
    width: 340px;
    height: 340px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
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
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 16px;
    position: relative;
    z-index: 1;
}

.lt-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.5s infinite;
}

.lt-live-class {
    position: relative;
    z-index: 1;
    padding: 16px 0;
    border-bottom: 1px solid var(--lt-line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.lt-live-class:last-of-type {
    border-bottom: none;
    padding-bottom: 0;
}
.lt-live-class:first-of-type { padding-top: 0; }

.lt-live-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.1rem;
    color: var(--lt-parchment);
    margin: 0 0 6px;
}

.lt-live-meta {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}

/* ---------- Class cards ---------- */
.lt-class {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    margin-bottom: 16px;
    padding: 22px 24px;
    transition: border-color 0.15s ease;
}

.lt-class:hover { border-color: var(--lt-gold); }

.lt-class-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.lt-class-head-left { min-width: 0; flex: 1; }

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

.lt-class-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    flex-shrink: 0;
}

/* Status pills */
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

/* Progress block */
.lt-progress-block { margin: 16px 0 18px; }

.lt-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.78rem;
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

/* Actions */
.lt-class-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 6px;
}

/* ---------- Two-column layout ---------- */
.lt-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    margin-bottom: 44px;
}

/* ---------- List rows ---------- */
.lt-list-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--lt-line);
}

.lt-list-row:last-child { border-bottom: none; }

.lt-list-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.lt-list-main { flex: 1; min-width: 0; }

.lt-list-title {
    font-size: 0.92rem;
    color: var(--lt-parchment);
    font-weight: 500;
    margin: 0 0 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-list-sub {
    font-size: 0.76rem;
    color: var(--lt-parchment-dim);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-att-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.66rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid transparent;
    flex-shrink: 0;
}

.lt-att-present {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.35);
}

.lt-att-late {
    background: rgba(228, 165, 76, 0.15);
    color: #E4A54C;
    border-color: rgba(228, 165, 76, 0.35);
}

.lt-att-absent {
    background: rgba(200, 52, 30, 0.18);
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.45);
}

/* ---------- Recommendation cards ---------- */
.lt-rec-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
}

.lt-rec {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 22px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    transition: border-color 0.15s ease, transform 0.15s ease;
}

.lt-rec:hover {
    border-color: var(--lt-flame);
    transform: translateY(-3px);
}

.lt-rec-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

.lt-rec h4 {
    font-size: 1.05rem;
    margin: 0;
    color: var(--lt-parchment);
    line-height: 1.35;
}

.lt-rec-meta {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    line-height: 1.6;
}

.lt-rec-price {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.25rem;
    color: var(--lt-parchment);
    margin-top: auto;
    padding-top: 14px;
    border-top: 1px solid var(--lt-line);
}

.lt-rec-price.lt-free { color: var(--lt-gold); }

.lt-rec-price small {
    font-family: 'Inter', sans-serif;
    font-size: 0.7rem;
    color: var(--lt-parchment-dim);
    margin-left: 4px;
    font-weight: 400;
}

/* ---------- Learning stats ---------- */
.lt-learn-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
}

.lt-learn-stat {
    padding: 24px 20px;
    text-align: center;
    border-right: 1px solid var(--lt-line);
}

.lt-learn-stat:last-child { border-right: none; }

.lt-learn-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.8rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
}

.lt-learn-number.lt-present { color: var(--lt-gold); }
.lt-learn-number.lt-late    { color: #E4A54C; }
.lt-learn-number.lt-absent  { color: var(--lt-flame-bright); }

.lt-learn-label {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
}

/* ---------- Empty state ---------- */
.lt-empty {
    text-align: center;
    padding: 56px 24px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 12px;
}

.lt-empty-icon {
    font-size: 2.6rem;
    margin-bottom: 14px;
    opacity: 0.6;
}

.lt-empty h3 {
    font-size: 1.15rem;
    margin: 0 0 8px;
    color: var(--lt-parchment);
}

.lt-empty p {
    color: var(--lt-parchment-dim);
    margin: 0 0 20px;
    font-size: 0.9rem;
    line-height: 1.6;
}

.lt-empty-inline {
    text-align: center;
    padding: 36px 20px;
    color: var(--lt-parchment-dim);
    font-size: 0.88rem;
    line-height: 1.6;
}

/* ---------- Responsive ---------- */
@media (max-width: 960px) {
    .lt-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .lt-stat:nth-child(2) { border-right: none; }
    .lt-stat:nth-child(1), .lt-stat:nth-child(2) { border-bottom: 1px solid var(--lt-line); }
    .lt-two-col { grid-template-columns: 1fr; gap: 32px; }
    .lt-learn-stats { grid-template-columns: repeat(2, 1fr); }
    .lt-learn-stat:nth-child(2) { border-right: none; }
    .lt-learn-stat:nth-child(1), .lt-learn-stat:nth-child(2) { border-bottom: 1px solid var(--lt-line); }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
    .lt-progress-badge { align-self: flex-start; }
}

@media (max-width: 560px) {
    .lt-stats-grid { grid-template-columns: 1fr; }
    .lt-stat { border-right: none; border-bottom: 1px solid var(--lt-line); }
    .lt-stat:last-child { border-bottom: none; }
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-class { padding: 20px 18px; }
    .lt-class-actions { flex-direction: column; }
    .lt-class-actions .lt-btn { width: 100%; }
    .lt-live { padding: 20px 18px; }
    .lt-live-class { flex-direction: column; align-items: flex-start; }
    .lt-live-class .lt-btn { width: 100%; }
    .lt-learn-stats { grid-template-columns: 1fr; }
    .lt-learn-stat { border-right: none; border-bottom: 1px solid var(--lt-line); }
    .lt-learn-stat:last-child { border-bottom: none; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-live-dot,
    .lt-status-ongoing .lt-status-dot { animation: none; }
    .lt-progress-fill { transition: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Student dashboard
                </span>
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', trim((string)($student['fullname'] ?? 'there')))[0] ?: 'there'); ?>.</h1>
                <p>Continue your learning journey — here's what's happening with your classes.</p>
            </div>
            <div class="lt-progress-badge">
                <div class="lt-progress-number"><?php echo (int)$totalProgress; ?>%</div>
                <div class="lt-progress-label">Overall progress</div>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <!-- Stats strip -->
        <div class="lt-stats-grid" style="margin-top: 32px;">
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo count($enrolledClasses); ?></div>
                <div class="lt-stat-label">Enrolled classes</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$attendanceSummary['present_count']; ?></div>
                <div class="lt-stat-label">Classes attended</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$certificatesCount; ?></div>
                <div class="lt-stat-label">Certificates earned</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number">R <?php echo number_format($totalSpent, 0); ?></div>
                <div class="lt-stat-label">Total spent</div>
            </div>
        </div>

        <!-- Live now section -->
        <?php if (!empty($ongoingClasses)): ?>
            <div class="lt-live">
                <span class="lt-live-badge">
                    <span class="lt-live-dot"></span>
                    Live now
                </span>
                <?php foreach ($ongoingClasses as $class): ?>
                    <div class="lt-live-class">
                        <div style="min-width: 0; flex: 1;">
                            <h3 class="lt-live-title"><?php echo htmlspecialchars($class['title']); ?></h3>
                            <div class="lt-live-meta">
                                <span>👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                <span>📅 Started <?php echo date('M d, Y H:i', strtotime($class['start_date'])); ?></span>
                            </div>
                        </div>
                        <a href="../classes/class.php?id=<?php echo (int)$class['class_id']; ?>#live-stream" class="lt-btn lt-btn-primary">
                            🔴 Join live class
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- My Enrolled Classes -->
        <div class="lt-section">
            <div class="lt-section-head">
                <span class="lt-num">01</span>
                <h2>My enrolled classes</h2>
                <span class="lt-count-pill"><?php echo count($enrolledClasses); ?></span>
            </div>

            <?php if (empty($enrolledClasses)): ?>
                <div class="lt-empty">
                    <div class="lt-empty-icon">📭</div>
                    <h3>No enrolled classes yet</h3>
                    <p>Start your learning journey by enrolling in a class.</p>
                    <a href="../classes/index.php" class="lt-btn lt-btn-primary">Browse classes →</a>
                </div>
            <?php else: ?>
                <?php foreach ($enrolledClasses as $class):
                    $progress = $class['total_sessions'] > 0 ? round(($class['sessions_attended'] / $class['total_sessions']) * 100) : 0;
                    $hasLessons = (int)$class['published_lessons'] > 0;
                    $hasStartedCourse = (int)$class['lessons_completed'] > 0;
                ?>
                    <div class="lt-class">
                        <div class="lt-class-head">
                            <div class="lt-class-head-left">
                                <h3 class="lt-class-title">
                                    <?php echo htmlspecialchars($class['title']); ?>
                                    <span class="lt-status lt-status-<?php echo htmlspecialchars($class['class_status']); ?>">
                                        <?php if ($class['class_status'] == 'ongoing'): ?>
                                            <span class="lt-status-dot"></span>
                                        <?php endif; ?>
                                        <?php
                                            $statusLabels = [
                                                'ongoing'   => 'In progress',
                                                'upcoming'  => 'Upcoming',
                                                'completed' => 'Completed',
                                                'cancelled' => 'Cancelled',
                                            ];
                                            echo $statusLabels[$class['class_status']] ?? ucfirst($class['class_status']);
                                        ?>
                                    </span>
                                </h3>
                                <div class="lt-class-meta">
                                    <span>👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                    <span>📅 <?php echo date('M d, Y', strtotime($class['start_date'])); ?></span>
                                    <span>⏱️ <?php echo htmlspecialchars($class['duration'] ?: 'Self-paced'); ?></span>
                                    <span>📊 <?php echo ucfirst($class['level']); ?></span>
                                </div>
                            </div>
                            <div class="lt-class-badges">
                                <?php if (!empty($class['certificate_issued'])): ?>
                                    <span class="lt-status lt-status-certificate">🏆 Certificate earned</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="lt-progress-block">
                            <div class="lt-progress-label">
                                <span>📊 Your progress</span>
                                <span><?php echo (int)$progress; ?>%</span>
                            </div>
                            <div class="lt-progress-track">
                                <div class="lt-progress-fill" style="width: <?php echo (int)$progress; ?>%;"></div>
                            </div>
                            <div class="lt-progress-meta">
                                <?php echo (int)$class['sessions_attended']; ?> of <?php echo (int)$class['total_sessions']; ?> sessions attended
                                <?php if ($hasLessons): ?>
                                    · 📚 <?php echo (int)$class['lessons_completed']; ?> of <?php echo (int)$class['published_lessons']; ?> lessons complete
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="lt-class-actions">
                            <?php if ($class['class_status'] == 'ongoing' && !empty($class['meeting_link'])): ?>
                                <a href="../classes/class.php?id=<?php echo (int)$class['class_id']; ?>#live-stream" class="lt-btn lt-btn-primary lt-btn-sm">🔴 Join live class</a>
                            <?php endif; ?>

                            <?php if ($hasLessons): ?>
                                <a href="../classes/course-view.php?id=<?php echo (int)$class['class_id']; ?>&from=my-classes" class="lt-btn lt-btn-primary lt-btn-sm">
                                    📚 <?php echo $hasStartedCourse ? 'Continue course' : 'Start course'; ?>
                                    (<?php echo (int)$class['lessons_completed']; ?>/<?php echo (int)$class['published_lessons']; ?>)
                                </a>
                            <?php endif; ?>

                            <?php if ($class['class_status'] == 'upcoming'): ?>
                                <a href="../classes/class.php?id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">📅 View details</a>
                            <?php endif; ?>

                            <?php if ($class['class_status'] == 'completed' && !empty($class['recording_url'])): ?>
                                <a href="<?php echo htmlspecialchars($class['recording_url']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-gold lt-btn-sm">📹 Watch recordings</a>
                            <?php endif; ?>

                            <a href="attendance.php?class_id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">📋 Attendance</a>

                            <?php if ($class['class_status'] == 'completed' && empty($class['certificate_issued']) && $progress >= 80): ?>
                                <a href="request-certificate.php?class_id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-gold lt-btn-sm">🏆 Request certificate</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Two-column: recent activity + upcoming -->
        <div class="lt-two-col">
            <!-- Recent activity -->
            <div>
                <div class="lt-section-head">
                    <span class="lt-num">02</span>
                    <h2>Recent activity</h2>
                </div>

                <?php if (empty($recentActivity)): ?>
                    <div class="lt-empty-inline">
                        <p style="margin: 0 0 6px;">No recent activity yet.</p>
                        <p style="margin: 0; font-size: 0.8rem; opacity: 0.7;">Attend classes to see your activity here.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="lt-list-row">
                            <div class="lt-list-icon">
                                <?php echo $activity['status'] == 'present' ? '✅' : ($activity['status'] == 'late' ? '⏰' : '❌'); ?>
                            </div>
                            <div class="lt-list-main">
                                <div class="lt-list-title"><?php echo htmlspecialchars($activity['class_title']); ?></div>
                                <div class="lt-list-sub"><?php echo date('l, M d, Y', strtotime($activity['session_date'])); ?></div>
                            </div>
                            <span class="lt-att-pill lt-att-<?php echo htmlspecialchars($activity['status']); ?>">
                                <?php echo ucfirst($activity['status']); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Upcoming classes -->
            <div>
                <div class="lt-section-head">
                    <span class="lt-num">03</span>
                    <h2>Upcoming classes</h2>
                </div>

                <?php if (empty($upcomingClasses)): ?>
                    <div class="lt-empty-inline">
                        <p style="margin: 0 0 6px;">No upcoming classes scheduled.</p>
                        <p style="margin: 0; font-size: 0.8rem; opacity: 0.7;">Check back later for new classes.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingClasses as $class):
                        $daysLeft = ceil((strtotime($class['start_date']) - time()) / (60 * 60 * 24));
                    ?>
                        <div class="lt-list-row">
                            <div class="lt-list-icon">📅</div>
                            <div class="lt-list-main">
                                <div class="lt-list-title"><?php echo htmlspecialchars($class['title']); ?></div>
                                <div class="lt-list-sub">
                                    <?php echo date('M d, Y H:i', strtotime($class['start_date'])); ?>
                                    <?php if ($daysLeft > 0): ?>
                                        · <?php echo (int)$daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> left
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="../classes/class.php?id=<?php echo (int)$class['class_id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">View</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recommended classes -->
        <?php if (!empty($recommendedClasses)): ?>
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num">04</span>
                    <h2>Recommended for you</h2>
                </div>
                <div class="lt-rec-grid">
                    <?php foreach ($recommendedClasses as $class): ?>
                        <div class="lt-rec">
                            <div class="lt-rec-icon">
                                <?php
                                    if ($class['category'] == 'Mathematics')      echo '📐';
                                    elseif ($class['category'] == 'Languages')    echo '📖';
                                    elseif ($class['category'] == 'Science')      echo '🔬';
                                    else                                          echo '🎓';
                                ?>
                            </div>
                            <h4><?php echo htmlspecialchars($class['title']); ?></h4>
                            <div class="lt-rec-meta">
                                👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?><br>
                                👥 <?php echo (int)$class['enrolled_count']; ?> student<?php echo $class['enrolled_count'] != 1 ? 's' : ''; ?> enrolled
                            </div>
                            <div class="lt-rec-price <?php echo $class['price'] <= 0 ? 'lt-free' : ''; ?>">
                                <?php if ($class['price'] > 0): ?>
                                    R <?php echo number_format((float)$class['price'], 2); ?><small>ZAR</small>
                                <?php else: ?>
                                    🎁 Free
                                <?php endif; ?>
                            </div>
                            <a href="../classes/class.php?id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-primary" style="width: 100%;">View class →</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Learning statistics -->
        <div class="lt-section">
            <div class="lt-section-head">
                <span class="lt-num">05</span>
                <h2>Learning statistics</h2>
            </div>
            <div class="lt-learn-stats">
                <div class="lt-learn-stat">
                    <div class="lt-learn-number lt-present"><?php echo (int)$attendanceSummary['present_count']; ?></div>
                    <div class="lt-learn-label">✅ Classes present</div>
                </div>
                <div class="lt-learn-stat">
                    <div class="lt-learn-number lt-late"><?php echo (int)$attendanceSummary['late_count']; ?></div>
                    <div class="lt-learn-label">⏰ Late arrivals</div>
                </div>
                <div class="lt-learn-stat">
                    <div class="lt-learn-number lt-absent"><?php echo (int)$attendanceSummary['absent_count']; ?></div>
                    <div class="lt-learn-label">❌ Absent</div>
                </div>
                <div class="lt-learn-stat">
                    <div class="lt-learn-number">
                        <?php
                            $attendanceRate = $attendanceSummary['total_sessions'] > 0
                                ? (int)round(($attendanceSummary['present_count'] / $attendanceSummary['total_sessions']) * 100)
                                : 0;
                            echo $attendanceRate;
                        ?>%
                    </div>
                    <div class="lt-learn-label">📊 Attendance rate</div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>