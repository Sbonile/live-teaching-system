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
$classId    = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

/* -----------------------------------------------------------
   Load all enrolled classes for the dropdown.
   Only paid enrollments count as "enrolled".
   ----------------------------------------------------------- */
$stmt = $connection->prepare("
    SELECT DISTINCT c.id, c.title, c.status
    FROM live_classes c
    JOIN enrollments e ON e.class_id = c.id
    WHERE e.student_id = ? AND e.payment_status = 'paid'
    ORDER BY c.start_date DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$enrolledClasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* -----------------------------------------------------------
   If a specific class is requested, verify the student
   is actually enrolled in it.
   ----------------------------------------------------------- */
$selectedClass = null;
if ($classId > 0) {
    foreach ($enrolledClasses as $c) {
        if ((int)$c['id'] === $classId) {
            $selectedClass = $c;
            break;
        }
    }
    if (!$selectedClass) {
        header('Location: attendance.php');
        exit;
    }
}

/* -----------------------------------------------------------
   Load attendance records.
   ----------------------------------------------------------- */
if ($classId > 0) {
    /* Single-class view */
    $stmt = $connection->prepare("
        SELECT a.id, a.student_id, a.class_id, a.session_date, a.status, a.notes,
               c.title  AS class_title,
               c.status AS class_status
        FROM attendance a
        JOIN live_classes c ON c.id = a.class_id
        WHERE a.student_id = ? AND a.class_id = ?
        ORDER BY a.session_date DESC, a.id DESC
    ");
    $stmt->bind_param('ii', $studentId, $classId);
} else {
    /* All-classes view — most recent first */
    $stmt = $connection->prepare("
        SELECT a.id, a.student_id, a.class_id, a.session_date, a.status, a.notes,
               c.title  AS class_title,
               c.status AS class_status
        FROM attendance a
        JOIN live_classes c ON c.id = a.class_id
        WHERE a.student_id = ?
        ORDER BY a.session_date DESC, a.id DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $studentId);
}
$stmt->execute();
$attendanceRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* -----------------------------------------------------------
   Aggregate stats for the selected scope.
   ----------------------------------------------------------- */
if ($classId > 0) {
    $stmt = $connection->prepare("
        SELECT
            COUNT(CASE WHEN status = 'present' THEN 1 END) AS present,
            COUNT(CASE WHEN status = 'late'    THEN 1 END) AS late,
            COUNT(CASE WHEN status = 'absent'  THEN 1 END) AS absent,
            COUNT(*) AS total
        FROM attendance
        WHERE student_id = ? AND class_id = ?
    ");
    $stmt->bind_param('ii', $studentId, $classId);
} else {
    $stmt = $connection->prepare("
        SELECT
            COUNT(CASE WHEN status = 'present' THEN 1 END) AS present,
            COUNT(CASE WHEN status = 'late'    THEN 1 END) AS late,
            COUNT(CASE WHEN status = 'absent'  THEN 1 END) AS absent,
            COUNT(*) AS total
        FROM attendance
        WHERE student_id = ?
    ");
    $stmt->bind_param('i', $studentId);
}
$stmt->execute();
$classStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* Normalise nulls */
$classStats['present'] = (int)($classStats['present'] ?? 0);
$classStats['late']    = (int)($classStats['late']    ?? 0);
$classStats['absent']  = (int)($classStats['absent']  ?? 0);
$classStats['total']   = (int)($classStats['total']   ?? 0);

/* -----------------------------------------------------------
   Attendance rate — late counts as present per your system.
   ----------------------------------------------------------- */
$attendanceRate = 0;
if ($classStats['total'] > 0) {
    $attendanceRate = (int)round(
        (($classStats['present'] + $classStats['late']) / $classStats['total']) * 100
    );
}

/* -----------------------------------------------------------
   Monthly summary for the chart — last 6 months.
   ----------------------------------------------------------- */
if ($classId > 0) {
    $stmt = $connection->prepare("
        SELECT
            DATE_FORMAT(session_date, '%b %Y') AS month,
            DATE_FORMAT(session_date, '%Y-%m') AS month_sort,
            COUNT(CASE WHEN status = 'present' THEN 1 END) AS present,
            COUNT(CASE WHEN status = 'late'    THEN 1 END) AS late,
            COUNT(CASE WHEN status = 'absent'  THEN 1 END) AS absent,
            COUNT(*) AS total
        FROM attendance
        WHERE student_id = ? AND class_id = ?
        GROUP BY DATE_FORMAT(session_date, '%Y-%m')
        ORDER BY month_sort DESC
        LIMIT 6
    ");
    $stmt->bind_param('ii', $studentId, $classId);
} else {
    $stmt = $connection->prepare("
        SELECT
            DATE_FORMAT(session_date, '%b %Y') AS month,
            DATE_FORMAT(session_date, '%Y-%m') AS month_sort,
            COUNT(CASE WHEN status = 'present' THEN 1 END) AS present,
            COUNT(CASE WHEN status = 'late'    THEN 1 END) AS late,
            COUNT(CASE WHEN status = 'absent'  THEN 1 END) AS absent,
            COUNT(*) AS total
        FROM attendance
        WHERE student_id = ?
        GROUP BY DATE_FORMAT(session_date, '%Y-%m')
        ORDER BY month_sort DESC
        LIMIT 6
    ");
    $stmt->bind_param('i', $studentId);
}
$stmt->execute();
$monthlyData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$monthlyData = array_reverse($monthlyData);

/* -----------------------------------------------------------
   Trend — last 30 days, one row per date.
   ----------------------------------------------------------- */
if ($classId > 0) {
    $stmt = $connection->prepare("
        SELECT
            DATE(session_date) AS date,
            status,
            COUNT(*) AS count
        FROM attendance
        WHERE student_id = ?
          AND class_id = ?
          AND session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(session_date), status
        ORDER BY session_date ASC
    ");
    $stmt->bind_param('ii', $studentId, $classId);
} else {
    $stmt = $connection->prepare("
        SELECT
            DATE(session_date) AS date,
            status,
            COUNT(*) AS count
        FROM attendance
        WHERE student_id = ?
          AND session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(session_date), status
        ORDER BY session_date ASC
    ");
    $stmt->bind_param('i', $studentId);
}
$stmt->execute();
$weeklyTrendRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Reshape into a date-keyed map */
$trendData = [];
foreach ($weeklyTrendRaw as $item) {
    $d = $item['date'];
    if (!isset($trendData[$d])) {
        $trendData[$d] = ['present' => 0, 'late' => 0, 'absent' => 0];
    }
    $st = $item['status'];
    if (isset($trendData[$d][$st])) {
        $trendData[$d][$st] = (int)$item['count'];
    }
}

/* -----------------------------------------------------------
   Certificate status — only for the selected class.
   ----------------------------------------------------------- */
$certificateStatus = null;
if ($classId > 0) {
    $stmt = $connection->prepare("
        SELECT certificate_issued
        FROM enrollments
        WHERE student_id = ? AND class_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $studentId, $classId);
    $stmt->execute();
    $certificateStatus = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* -----------------------------------------------------------
   Precompute CSV payload so export doesn't depend on DOM.
   ----------------------------------------------------------- */
$csvRows = [];
$csvRows[] = ['Date', 'Time', 'Class', 'Class Status', 'Attendance', 'Notes'];
foreach ($attendanceRecords as $r) {
    $csvRows[] = [
        date('Y-m-d', strtotime($r['session_date'])),
        date('H:i',  strtotime($r['session_date'])),
        $r['class_title'],
        ucfirst($r['class_status']),
        ucfirst($r['status']),
        $r['notes'] ?? '',
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My attendance · LiveTeach</title>
<style>
/* ===== LiveTeach student attendance revamp — scoped to .lt-page ===== */
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

.lt-dash-header p strong { color: var(--lt-gold); font-weight: 500; }

.lt-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

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

.lt-btn-primary { background: var(--lt-flame); color: var(--lt-parchment); }
.lt-btn-primary:hover { background: var(--lt-flame-bright); }

.lt-btn-gold { background: var(--lt-gold); color: var(--lt-ink); }
.lt-btn-gold:hover { background: #E8B85A; }

.lt-btn-outline {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover { border-color: var(--lt-gold); color: var(--lt-gold); }

.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

/* ---------- Toolbar ---------- */
.lt-toolbar {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 16px 20px;
    margin: 32px 0 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.lt-toolbar-group {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.lt-toolbar-group label {
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    font-weight: 600;
}

.lt-select {
    padding: 10px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.88rem;
    font-family: inherit;
    min-width: 220px;
    cursor: pointer;
    transition: border-color 0.15s ease;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
    box-sizing: border-box;
}

.lt-select:focus { outline: none; border-color: var(--lt-flame); }

/* ---------- Stats strip ---------- */
.lt-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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
    line-height: 1;
    margin-bottom: 8px;
    color: var(--lt-parchment);
}

.lt-stat-number.lt-present { color: var(--lt-gold); }
.lt-stat-number.lt-late    { color: #E4A54C; }
.lt-stat-number.lt-absent  { color: var(--lt-flame-bright); }

.lt-stat-label { font-size: 0.8rem; color: var(--lt-parchment-dim); }

/* ---------- Section head ---------- */
.lt-section { padding-bottom: 40px; }

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

/* ---------- Class info panel ---------- */
.lt-class-info {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 32px;
    align-items: center;
}

.lt-class-info h3 {
    font-size: 1.35rem;
    margin: 0 0 14px;
    color: var(--lt-parchment);
}

.lt-class-info-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
}

.lt-class-info-meta strong { color: var(--lt-parchment); font-weight: 500; }

.lt-ring-wrap { position: relative; flex-shrink: 0; }

.lt-ring-center {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
}

.lt-ring-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.7rem;
    color: var(--lt-gold);
    line-height: 1;
    margin-bottom: 4px;
}

.lt-ring-label {
    font-size: 0.65rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    font-weight: 600;
}

/* ---------- Monthly chart ---------- */
.lt-chart {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 26px;
}

.lt-chart-row {
    display: grid;
    grid-template-columns: 78px 1fr 90px;
    align-items: center;
    gap: 16px;
    margin-bottom: 12px;
}

.lt-chart-row:last-child { margin-bottom: 0; }

.lt-chart-label {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.76rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.03em;
}

.lt-chart-track {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    height: 24px;
    overflow: hidden;
    display: flex;
}

.lt-chart-present { background: var(--lt-gold); height: 100%; }
.lt-chart-late    { background: #E4A54C; height: 100%; }
.lt-chart-absent  { background: var(--lt-flame); height: 100%; }

.lt-chart-count {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    text-align: right;
}

.lt-chart-legend {
    display: flex;
    gap: 24px;
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid var(--lt-line);
    justify-content: center;
    flex-wrap: wrap;
}

.lt-chart-legend span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
}

.lt-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
.lt-swatch-present { background: var(--lt-gold); }
.lt-swatch-late    { background: #E4A54C; }
.lt-swatch-absent  { background: var(--lt-flame); }

/* ---------- Trend dots ---------- */
.lt-trend {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 26px;
    display: flex;
    gap: 14px;
    overflow-x: auto;
}

.lt-trend-day { text-align: center; min-width: 62px; flex-shrink: 0; }

.lt-trend-dot {
    width: 42px;
    height: 42px;
    margin: 0 auto 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.85rem;
    color: var(--lt-ink);
    font-weight: 700;
    border: 1px solid transparent;
}

.lt-trend-dot.lt-present { background: var(--lt-gold); }
.lt-trend-dot.lt-late    { background: #E4A54C; }
.lt-trend-dot.lt-absent  { background: var(--lt-flame); color: var(--lt-parchment); }

.lt-trend-date {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.7rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.03em;
}

/* ---------- Table ---------- */
.lt-table-wrap {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden;
}

.lt-table-scroll { overflow-x: auto; }

.lt-table { width: 100%; border-collapse: collapse; min-width: 720px; }

.lt-table thead th {
    background: var(--lt-charcoal-raised);
    padding: 14px 18px;
    text-align: left;
    font-size: 0.72rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-gold);
    font-weight: 700;
    border-bottom: 1px solid var(--lt-line);
    white-space: nowrap;
}

.lt-table tbody td {
    padding: 14px 18px;
    border-bottom: 1px solid var(--lt-line);
    font-size: 0.88rem;
    color: var(--lt-parchment);
    vertical-align: middle;
}

.lt-table tbody tr:last-child td { border-bottom: none; }
.lt-table tbody tr:hover { background: var(--lt-ink); }

.lt-table-date {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-parchment);
}

.lt-table-class { font-size: 0.9rem; color: var(--lt-parchment); margin: 0 0 3px; }

.lt-table-class-status {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.lt-table-notes { font-size: 0.85rem; color: var(--lt-parchment-dim); }

/* ---------- Status pills ---------- */
.lt-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 11px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid transparent;
    white-space: nowrap;
}

.lt-status-pill.lt-present { background: rgba(217, 164, 65, 0.15); color: var(--lt-gold);        border-color: rgba(217, 164, 65, 0.35); }
.lt-status-pill.lt-late    { background: rgba(228, 165, 76, 0.15); color: #E4A54C;              border-color: rgba(228, 165, 76, 0.35); }
.lt-status-pill.lt-absent  { background: rgba(200, 52, 30, 0.18);  color: var(--lt-flame-bright); border-color: rgba(200, 52, 30, 0.45); }
.lt-status-pill.lt-completed { background: rgba(241, 231, 214, 0.06); color: var(--lt-parchment-dim); border-color: var(--lt-line); }
.lt-status-pill.lt-ongoing   { background: rgba(200, 52, 30, 0.18);  color: var(--lt-flame-bright); border-color: rgba(200, 52, 30, 0.45); }
.lt-status-pill.lt-upcoming  { background: rgba(217, 164, 65, 0.15); color: var(--lt-gold);        border-color: rgba(217, 164, 65, 0.35); }
.lt-status-pill.lt-cancelled { background: rgba(241, 231, 214, 0.06); color: var(--lt-parchment-dim); border-color: var(--lt-line); }
.lt-status-pill.lt-certificate { background: rgba(217, 164, 65, 0.2); color: var(--lt-gold); border-color: rgba(217, 164, 65, 0.5); }

/* ---------- Empty state ---------- */
.lt-empty {
    text-align: center;
    padding: 60px 32px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 14px;
}

.lt-empty-icon { font-size: 2.8rem; margin-bottom: 14px; opacity: 0.6; }

.lt-empty h3 { font-size: 1.2rem; margin: 0 0 10px; color: var(--lt-parchment); }

.lt-empty p {
    color: var(--lt-parchment-dim);
    margin: 0 0 22px;
    font-size: 0.9rem;
    line-height: 1.65;
}

/* ---------- Tips ---------- */
.lt-tips {
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    border-radius: 12px;
    padding: 28px 26px;
    margin-bottom: 40px;
}

.lt-tips-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid rgba(241, 231, 214, 0.25);
}

.lt-tips-head h2 { font-size: 1.15rem; margin: 0; color: var(--lt-parchment); }

.lt-tips-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
}

.lt-tip { padding: 16px 18px; background: rgba(0, 0, 0, 0.15); border-radius: 10px; }

.lt-tip .lt-tip-icon { font-size: 1.4rem; margin-bottom: 8px; display: block; }

.lt-tip strong {
    display: block;
    font-size: 0.92rem;
    color: var(--lt-parchment);
    margin-bottom: 6px;
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-tip p {
    margin: 0;
    font-size: 0.82rem;
    color: rgba(241, 231, 214, 0.85);
    line-height: 1.55;
}

/* ---------- Print stylesheet ---------- */
@media print {
    .lt-page { background: #fff !important; color: #000 !important; }
    .lt-dash-header,
    .lt-toolbar,
    .lt-tips,
    .lt-header-actions { display: none !important; }
    .lt-stats-grid { border-color: #ccc !important; }
    .lt-stat { border-color: #ccc !important; }
    .lt-stat-number, .lt-stat-label { color: #000 !important; }
    .lt-chart, .lt-trend, .lt-table-wrap, .lt-class-info {
        background: #fff !important;
        border-color: #ccc !important;
        color: #000 !important;
    }
    .lt-chart-track { background: #eee !important; border-color: #ccc !important; }
    .lt-table thead th { background: #f2f2f2 !important; color: #000 !important; }
    .lt-table tbody td { color: #000 !important; border-color: #ccc !important; }
}

/* ---------- Responsive ---------- */
@media (max-width: 960px) {
    .lt-stats-grid { grid-template-columns: repeat(3, 1fr); }
    .lt-stat:nth-child(3) { border-right: none; }
    .lt-stat:nth-child(1), .lt-stat:nth-child(2), .lt-stat:nth-child(3) { border-bottom: 1px solid var(--lt-line); }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
    .lt-class-info { grid-template-columns: 1fr; text-align: center; }
    .lt-ring-wrap { margin: 0 auto; }
}

@media (max-width: 560px) {
    .lt-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .lt-stat { border-right: 1px solid var(--lt-line); }
    .lt-stat:nth-child(2n) { border-right: none; }
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-chart-row { grid-template-columns: 60px 1fr 60px; gap: 10px; }
    .lt-toolbar { flex-direction: column; align-items: stretch; }
    .lt-toolbar-group { width: 100%; }
    .lt-toolbar-group .lt-btn { flex: 1; }
    .lt-select { width: 100%; min-width: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
    .lt-chart-present, .lt-chart-late, .lt-chart-absent { transition: none; }
}
</style>
</head>
<body class="lt-page">
<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Attendance record
                </span>
                <h1>My attendance.</h1>
                <p>
                    <?php if ($selectedClass): ?>
                        Tracking for <strong><?php echo htmlspecialchars($selectedClass['title']); ?></strong>
                    <?php else: ?>
                        Across all of your enrolled classes
                    <?php endif; ?>
                </p>
            </div>
            <div class="lt-header-actions">
                <a href="index.php" class="lt-btn lt-btn-outline">← Back to dashboard</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <!-- Toolbar -->
        <div class="lt-toolbar">
            <div class="lt-toolbar-group">
                <label for="classSelect">📚 Class</label>
                <select id="classSelect" class="lt-select"
                        onchange="window.location.href='attendance.php?class_id=' + this.value">
                    <option value="0">All classes</option>
                    <?php foreach ($enrolledClasses as $class): ?>
                        <option value="<?php echo (int)$class['id']; ?>"
                                <?php echo $classId === (int)$class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['title']); ?>
                            (<?php echo ucfirst($class['status']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="lt-toolbar-group">
                <button type="button" onclick="exportAttendanceCsv()" class="lt-btn lt-btn-primary lt-btn-sm">
                    📥 Export CSV
                </button>
                <button type="button" onclick="window.print()" class="lt-btn lt-btn-outline lt-btn-sm">
                    🖨️ Print report
                </button>
            </div>
        </div>

        <!-- Stats strip -->
        <div class="lt-stats-grid">
            <div class="lt-stat">
                <div class="lt-stat-number lt-present"><?php echo (int)$classStats['present']; ?></div>
                <div class="lt-stat-label">✅ Present</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number lt-late"><?php echo (int)$classStats['late']; ?></div>
                <div class="lt-stat-label">⏰ Late</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number lt-absent"><?php echo (int)$classStats['absent']; ?></div>
                <div class="lt-stat-label">❌ Absent</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo $attendanceRate; ?>%</div>
                <div class="lt-stat-label">📊 Attendance rate</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$classStats['total']; ?></div>
                <div class="lt-stat-label">📅 Total sessions</div>
            </div>
        </div>

        <!-- Class info (only when a single class is selected) -->
        <?php if ($selectedClass): ?>
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num">01</span>
                    <h2>Class information</h2>
                </div>
                <div class="lt-class-info">
                    <div>
                        <h3><?php echo htmlspecialchars($selectedClass['title']); ?></h3>
                        <div class="lt-class-info-meta">
                            <span>Status:
                                <span class="lt-status-pill lt-<?php echo htmlspecialchars($selectedClass['status']); ?>">
                                    <?php echo ucfirst($selectedClass['status']); ?>
                                </span>
                            </span>
                            <?php if ($certificateStatus && !empty($certificateStatus['certificate_issued'])): ?>
                                <span class="lt-status-pill lt-certificate">🏆 Certificate earned</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="lt-ring-wrap">
                        <svg width="120" height="120" viewBox="0 0 120 120">
                            <circle cx="60" cy="60" r="54" fill="none"
                                    stroke="#241F18" stroke-width="12"/>
                            <circle cx="60" cy="60" r="54" fill="none"
                                    stroke="#C8341E" stroke-width="12"
                                    stroke-dasharray="<?php echo 2 * pi() * 54; ?>"
                                    stroke-dashoffset="<?php echo (2 * pi() * 54) * (1 - $attendanceRate / 100); ?>"
                                    transform="rotate(-90 60 60)"/>
                        </svg>
                        <div class="lt-ring-center">
                            <div class="lt-ring-number"><?php echo $attendanceRate; ?>%</div>
                            <div class="lt-ring-label">Attendance</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Monthly trend -->
        <?php if (!empty($monthlyData)): ?>
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num"><?php echo $selectedClass ? '02' : '01'; ?></span>
                    <h2>Monthly attendance trend</h2>
                </div>
                <div class="lt-chart">
                    <?php foreach ($monthlyData as $month):
                        $total = (int)$month['present'] + (int)$month['late'] + (int)$month['absent'];
                        $presentPercent = $total > 0 ? ((int)$month['present'] / $total) * 100 : 0;
                        $latePercent    = $total > 0 ? ((int)$month['late']    / $total) * 100 : 0;
                        $absentPercent  = $total > 0 ? ((int)$month['absent']  / $total) * 100 : 0;
                    ?>
                        <div class="lt-chart-row">
                            <div class="lt-chart-label"><?php echo htmlspecialchars($month['month']); ?></div>
                            <div class="lt-chart-track">
                                <div class="lt-chart-present" style="width: <?php echo $presentPercent; ?>%;"></div>
                                <div class="lt-chart-late"    style="width: <?php echo $latePercent; ?>%;"></div>
                                <div class="lt-chart-absent"  style="width: <?php echo $absentPercent; ?>%;"></div>
                            </div>
                            <div class="lt-chart-count"><?php echo $total; ?> session<?php echo $total != 1 ? 's' : ''; ?></div>
                        </div>
                    <?php endforeach; ?>

                    <div class="lt-chart-legend">
                        <span><span class="lt-swatch lt-swatch-present"></span> Present</span>
                        <span><span class="lt-swatch lt-swatch-late"></span> Late</span>
                        <span><span class="lt-swatch lt-swatch-absent"></span> Absent</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent activity -->
        <?php if (!empty($trendData)): ?>
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num"><?php echo $selectedClass ? '03' : '02'; ?></span>
                    <h2>Recent activity · last 30 days</h2>
                </div>
                <div class="lt-trend">
                    <?php foreach ($trendData as $date => $data):
                        $total = $data['present'] + $data['late'] + $data['absent'];
                        $status = $data['present'] > 0
                            ? 'present'
                            : ($data['late'] > 0 ? 'late' : 'absent');
                    ?>
                        <div class="lt-trend-day">
                            <div class="lt-trend-dot lt-<?php echo $status; ?>"><?php echo $total; ?></div>
                            <div class="lt-trend-date"><?php echo date('M d', strtotime($date)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Detailed records -->
        <div class="lt-section">
            <div class="lt-section-head">
                <span class="lt-num"><?php echo $selectedClass ? '04' : '03'; ?></span>
                <h2>Detailed attendance records</h2>
            </div>

            <?php if (empty($attendanceRecords)): ?>
                <div class="lt-empty">
                    <div class="lt-empty-icon">📭</div>
                    <h3>No attendance records found</h3>
                    <p>
                        <?php if ($classId > 0): ?>
                            You haven't attended any sessions for this class yet.<br>Join the live class to start building your attendance record!
                        <?php else: ?>
                            You haven't attended any classes yet.<br>Browse and enroll in classes to get started!
                        <?php endif; ?>
                    </p>
                    <?php if ($classId > 0): ?>
                        <a href="../classes/class.php?id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary">
                            View class →
                        </a>
                    <?php else: ?>
                        <a href="../classes/index.php" class="lt-btn lt-btn-primary">
                            Browse classes →
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="lt-table-wrap">
                    <div class="lt-table-scroll">
                        <table class="lt-table" id="attendanceTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <?php if (!$classId): ?>
                                        <th>Class</th>
                                    <?php endif; ?>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRecords as $record): ?>
                                    <tr>
                                        <td>
                                            <div class="lt-table-date">
                                                <?php echo date('l, M d, Y', strtotime($record['session_date'])); ?>
                                            </div>
                                        </td>
                                        <?php if (!$classId): ?>
                                            <td>
                                                <div class="lt-table-class">
                                                    <?php echo htmlspecialchars($record['class_title']); ?>
                                                </div>
                                                <div class="lt-table-class-status">
                                                    <?php echo ucfirst($record['class_status']); ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="lt-status-pill lt-<?php echo htmlspecialchars($record['status']); ?>">
                                                <?php
                                                    echo $record['status'] === 'present'
                                                        ? '✅ Present'
                                                        : ($record['status'] === 'late' ? '⏰ Late' : '❌ Absent');
                                                ?>
                                            </span>
                                        </td>
                                        <td class="lt-table-notes">
                                            <?php echo htmlspecialchars($record['notes'] ?? '—'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tips -->
        <div class="lt-tips">
            <div class="lt-tips-head">
                <span style="font-size: 1.1rem;">💡</span>
                <h2>Attendance tips</h2>
            </div>
            <div class="lt-tips-grid">
                <div class="lt-tip">
                    <span class="lt-tip-icon">🎯</span>
                    <strong>Stay consistent</strong>
                    <p>Regular attendance helps you stay on track with course material.</p>
                </div>
                <div class="lt-tip">
                    <span class="lt-tip-icon">⏰</span>
                    <strong>Be on time</strong>
                    <p>Join classes 5 minutes early to avoid being marked late.</p>
                </div>
                <div class="lt-tip">
                    <span class="lt-tip-icon">🏆</span>
                    <strong>Earn certificates</strong>
                    <p>Maintain 80%+ attendance to qualify for certificates.</p>
                </div>
                <div class="lt-tip">
                    <span class="lt-tip-icon">📹</span>
                    <strong>Watch recordings</strong>
                    <p>Missed a class? Catch up with recorded sessions.</p>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
/* ---------- CSV export ----------
   Uses precomputed rows from PHP instead of scraping the DOM. */
const __CSV_ROWS__ = <?php echo json_encode($csvRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const __CSV_NAME__ = 'attendance_report_<?php echo date("Y-m-d"); ?><?php echo $classId ? "_class{$classId}" : ""; ?>.csv';

function csvEscape(v) {
    v = (v === null || v === undefined) ? '' : String(v);
    /* Quote if the value contains a comma, quote, or newline. */
    if (v.indexOf(',') !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) {
        return '"' + v.replace(/"/g, '""') + '"';
    }
    return v;
}

function exportAttendanceCsv() {
    const lines = __CSV_ROWS__.map(function (row) {
        return row.map(csvEscape).join(',');
    });
    /* Prepend BOM so Excel on Windows reads UTF-8 correctly. */
    const csv = '\uFEFF' + lines.join('\r\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = __CSV_NAME__;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
}
</script>

<?php require_once '../includes/footer.php'; ?>