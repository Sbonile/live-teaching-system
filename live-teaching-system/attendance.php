<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$teacherId  = (int)$_SESSION['user']['id'];
$classId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg        = '';
$error      = '';

/* -----------------------------------------------------------
   Optional notification helper.
   Only runs if the notification_preferences table has a row for
   the user; safe if the table doesn't exist.
   ----------------------------------------------------------- */
if (!function_exists('sendNotification')) {
    function sendNotification(mysqli $conn, int $userId, string $type, string $title, string $message, string $link = ''): void {
        /* Best-effort — never break the request if this fails. */
        $stmt = @$conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) return;
        $stmt->bind_param('issss', $userId, $type, $title, $message, $link);
        @$stmt->execute();
        $stmt->close();
    }
}

/* -----------------------------------------------------------
   Verify class belongs to teacher.
   ----------------------------------------------------------- */
$stmt = $connection->prepare("SELECT * FROM live_classes WHERE id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $classId, $teacherId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    header('Location: classes.php');
    exit;
}

/* -----------------------------------------------------------
   Validate date input.
   ----------------------------------------------------------- */
$sessionDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
    $sessionDate = date('Y-m-d');
}

/* =====================================================================
   POST HANDLERS
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_verify();

    /* ---------------- Save individual attendance ---------------- */
    if (isset($_POST['submit_attendance'])) {
        $sessionDate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['session_date'] ?? '')
            ? $_POST['session_date']
            : date('Y-m-d');
        $attendanceData = $_POST['attendance'] ?? [];
        $notesData      = $_POST['notes'] ?? [];

        $validStatuses = ['present', 'absent', 'late'];

        /* Two prepared statements, bound once each, re-executed per row. */
        $upd = $connection->prepare("UPDATE attendance SET status = ?, notes = ? WHERE id = ?");
        $ins = $connection->prepare("INSERT INTO attendance (student_id, class_id, session_date, status, notes) VALUES (?, ?, ?, ?, ?)");
        $chk = $connection->prepare("SELECT id FROM attendance WHERE student_id = ? AND class_id = ? AND session_date = ?");

        foreach ($attendanceData as $studentId => $status) {
            $studentId = (int)$studentId;
            $status    = in_array($status, $validStatuses, true) ? $status : 'absent';
            $notes     = trim((string)($notesData[$studentId] ?? ''));

            /* Confirm the student is actually enrolled in this class */
            $enr = $connection->prepare("SELECT 1 FROM enrollments WHERE student_id = ? AND class_id = ? AND payment_status = 'paid' LIMIT 1");
            $enr->bind_param('ii', $studentId, $classId);
            $enr->execute();
            $ok = (bool)$enr->get_result()->fetch_row();
            $enr->close();
            if (!$ok) continue;

            $chk->bind_param('iis', $studentId, $classId, $sessionDate);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();

            if ($existing) {
                $upd->bind_param('ssi', $status, $notes, $existing['id']);
                $upd->execute();
            } else {
                $ins->bind_param('iisss', $studentId, $classId, $sessionDate, $status, $notes);
                $ins->execute();
            }
        }
        $upd->close();
        $ins->close();
        $chk->close();

        /* Refresh the enrollment attendance counter for this class */
        $ref = $connection->prepare("
            UPDATE enrollments e
            SET attendance = (
                SELECT COUNT(*) FROM attendance a
                WHERE a.student_id = e.student_id
                  AND a.class_id  = e.class_id
                  AND a.status <> 'absent'
            )
            WHERE e.class_id = ?
        ");
        $ref->bind_param('i', $classId);
        $ref->execute();
        $ref->close();

        $msg = "Attendance saved for " . date('M d, Y', strtotime($sessionDate)) . "!";
    }

    /* ---------------- Bulk attendance ---------------- */
    if (isset($_POST['bulk_attendance'])) {
        $bulk_status = $_POST['bulk_attendance'];
        $validStatuses = ['present', 'late', 'absent'];
        if (!in_array($bulk_status, $validStatuses, true)) {
            $bulk_status = 'present';
        }

        $sessionDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['session_date'] ?? '')
            ? $_POST['session_date']
            : date('Y-m-d');

        /* Only fill students who don't already have a record for this date. */
        $students = $connection->query("
            SELECT u.id, u.fullname
            FROM users u
            JOIN enrollments e ON e.student_id = u.id
            WHERE e.class_id = $classId AND e.payment_status = 'paid'
        ")->fetch_all(MYSQLI_ASSOC);

        $chk = $connection->prepare("SELECT id FROM attendance WHERE student_id = ? AND class_id = ? AND session_date = ?");
        $ins = $connection->prepare("INSERT INTO attendance (student_id, class_id, session_date, status) VALUES (?, ?, ?, ?)");

        foreach ($students as $student) {
            $sid = (int)$student['id'];
            $chk->bind_param('iis', $sid, $classId, $sessionDate);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            if (!$exists) {
                $ins->bind_param('iiss', $sid, $classId, $sessionDate, $bulk_status);
                $ins->execute();

                /* Notify each student individually */
                sendNotification(
                    $connection,
                    $sid,
                    'class_update',
                    '📋 Attendance Recorded',
                    "Your attendance for '" . $class['title'] . "' on " . date('M d, Y', strtotime($sessionDate)) . " has been marked as " . ucfirst($bulk_status) . ".",
                    "../dashboard/attendance.php?class_id=" . $classId
                );
            }
        }
        $chk->close();
        $ins->close();

        /* Refresh enrollment counters */
        $ref = $connection->prepare("
            UPDATE enrollments e
            SET attendance = (
                SELECT COUNT(*) FROM attendance a
                WHERE a.student_id = e.student_id
                  AND a.class_id  = e.class_id
                  AND a.status <> 'absent'
            )
            WHERE e.class_id = ?
        ");
        $ref->bind_param('i', $classId);
        $ref->execute();
        $ref->close();

        $msg = "Bulk attendance marked as " . ucfirst($bulk_status) . " for " . date('M d, Y', strtotime($sessionDate));
    }

    /* Redirect to avoid form re-submission */
    if ($error === '') {
        header("Location: attendance.php?id=$classId&date=$sessionDate&msg=" . urlencode($msg));
        exit;
    }
}

if (isset($_GET['msg'])) $msg = urldecode($_GET['msg']);
if (isset($_GET['err'])) $error = urldecode($_GET['err']);

/* =====================================================================
   LOAD DATA FOR RENDER
   ===================================================================== */

/* ---------- Enrolled students + their per-class attendance stats ---------- */
$stmt = $connection->prepare("
    SELECT
        u.id, u.fullname, u.email, u.phone,
        (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = ? AND a.status = 'present') AS present_count,
        (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = ? AND a.status = 'late')    AS late_count,
        (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = ? AND a.status = 'absent')  AS absent_count,
        (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = ?)                          AS total_sessions,
        (SELECT e.id      FROM enrollments e WHERE e.student_id = u.id AND e.class_id = ? LIMIT 1)                AS enroll_id
    FROM users u
    JOIN enrollments e ON e.student_id = u.id
    WHERE e.class_id = ? AND e.payment_status = 'paid'
    ORDER BY u.fullname
");
$stmt->bind_param('iiiiii', $classId, $classId, $classId, $classId, $classId, $classId);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Existing attendance for the selected date ---------- */
$existingAtt = [];
$stmt = $connection->prepare("SELECT student_id, status, notes FROM attendance WHERE class_id = ? AND session_date = ?");
$stmt->bind_param('is', $classId, $sessionDate);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $existingAtt[(int)$row['student_id']] = $row;
}
$stmt->close();

/* ---------- Session history with totals per date ---------- */
$stmt = $connection->prepare("
    SELECT
        session_date,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN status = 'late'    THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN status = 'absent'  THEN 1 ELSE 0 END) AS absent_count
    FROM attendance
    WHERE class_id = ?
    GROUP BY session_date
    ORDER BY session_date DESC
    LIMIT 60
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$sessionDates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Overall stats ---------- */
$totalStudents  = count($students);
$totalPresent   = array_sum(array_column($students, 'present_count'));
$totalLate      = array_sum(array_column($students, 'late_count'));
$totalAbsent    = array_sum(array_column($students, 'absent_count'));
$overallPresent = $totalPresent + $totalLate;   // late counts as attended
$totalPossible  = $overallPresent + $totalAbsent;
$attendanceRate = $totalPossible > 0 ? (int)round(($overallPresent / $totalPossible) * 100) : 0;

/* ---------- Weekly trend data (last 14 days with data) ---------- */
$stmt = $connection->prepare("
    SELECT
        DATE(session_date) AS date,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN status = 'late'    THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN status = 'absent'  THEN 1 ELSE 0 END) AS absent,
        COUNT(*) AS total
    FROM attendance
    WHERE class_id = ?
      AND session_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(session_date)
    ORDER BY session_date ASC
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$weeklyData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- CSV payload precomputed so export doesn't scrape DOM ---------- */
$csvRows   = [];
$csvRows[] = ['#', 'Student', 'Email', 'Overall %', 'Present', 'Late', 'Absent', 'Status on ' . $sessionDate, 'Notes'];
foreach ($students as $i => $s) {
    $rate = ((int)$s['total_sessions'] > 0)
        ? (int)round((((int)$s['present_count'] + (int)$s['late_count']) / (int)$s['total_sessions']) * 100)
        : 0;
    $att = $existingAtt[(int)$s['id']] ?? null;
    $csvRows[] = [
        $i + 1,
        $s['fullname'],
        $s['email'],
        $rate . '%',
        $s['present_count'],
        $s['late_count'],
        $s['absent_count'],
        $att ? ucfirst($att['status']) : '—',
        $att['notes'] ?? '',
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attendance · <?php echo htmlspecialchars($class['title']); ?></title>
<style>
/* ===== LiveTeach attendance revamp — scoped to .lt-page ===== */
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

/* ---------- Alerts ---------- */
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

/* ---------- Stats strip ---------- */
.lt-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-bottom: 32px;
}

.lt-stat {
    padding: 24px 20px;
    border-right: 1px solid var(--lt-line);
    position: relative;
}

.lt-stat:last-child { border-right: none; }

.lt-stat::before {
    content: "";
    position: absolute;
    top: 0;
    left: 20px;
    width: 24px;
    height: 2px;
    background: var(--lt-flame);
}

.lt-stat-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.7rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
}

.lt-stat-number.lt-present { color: var(--lt-gold); }
.lt-stat-number.lt-late    { color: #E4A54C; }
.lt-stat-number.lt-absent  { color: var(--lt-flame-bright); }

.lt-stat-label { font-size: 0.78rem; color: var(--lt-parchment-dim); }

/* ---------- Toolbar ---------- */
.lt-toolbar {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 18px;
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

.lt-date-input {
    padding: 10px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.88rem;
    font-family: inherit;
    transition: border-color 0.15s ease;
    box-sizing: border-box;
}

.lt-date-input:focus { outline: none; border-color: var(--lt-flame); }

/* ---------- Bulk bar ---------- */
.lt-bulk {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 14px 20px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.lt-bulk-label {
    font-size: 0.78rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    font-weight: 600;
}

/* ---------- Attendance table ---------- */
.lt-table-wrap {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 22px;
}

.lt-table-scroll { overflow-x: auto; }

.lt-table { width: 100%; border-collapse: collapse; min-width: 820px; }

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

.lt-student-name { font-weight: 500; color: var(--lt-parchment); }
.lt-student-email { font-size: 0.8rem; color: var(--lt-parchment-dim); }

.lt-att-stat {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.lt-att-rate {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
}

.lt-att-rate.lt-good    { color: var(--lt-gold); }
.lt-att-rate.lt-average { color: #E4A54C; }
.lt-att-rate.lt-poor    { color: var(--lt-flame-bright); }

.lt-att-breakdown {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
}

/* Status select */
.lt-status-select {
    padding: 8px 12px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.85rem;
    font-family: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
    min-width: 140px;
}

.lt-status-select:focus { outline: none; border-color: var(--lt-flame); }

.lt-status-select.lt-present { border-color: rgba(217, 164, 65, 0.5); color: var(--lt-gold); }
.lt-status-select.lt-late    { border-color: rgba(228, 165, 76, 0.5); color: #E4A54C; }
.lt-status-select.lt-absent  { border-color: rgba(200, 52, 30, 0.5);  color: var(--lt-flame-bright); }

/* Notes input */
.lt-notes-input {
    padding: 8px 12px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.85rem;
    font-family: inherit;
    width: 100%;
    max-width: 220px;
    box-sizing: border-box;
    transition: border-color 0.15s ease;
}

.lt-notes-input::placeholder { color: rgba(201, 190, 172, 0.45); }
.lt-notes-input:focus { outline: none; border-color: var(--lt-flame); }

.lt-table-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
}

/* Save actions */
.lt-form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 40px;
}

.lt-form-actions .lt-btn { min-width: 160px; }

/* ---------- Section head ---------- */
.lt-section-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
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

.lt-section { margin-bottom: 44px; }

/* ---------- Chart ---------- */
.lt-chart {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 26px;
}

.lt-chart-row {
    display: grid;
    grid-template-columns: 70px 1fr 60px;
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
    border-radius: 6px;
    height: 24px;
    overflow: hidden;
    display: flex;
    border: 1px solid var(--lt-line);
}

.lt-chart-present { background: var(--lt-gold); height: 100%; transition: width 0.3s ease; }
.lt-chart-late    { background: #E4A54C; height: 100%; transition: width 0.3s ease; }
.lt-chart-absent  { background: var(--lt-flame); height: 100%; transition: width 0.3s ease; }

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

.lt-chart-legend .lt-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
.lt-swatch-present { background: var(--lt-gold); }
.lt-swatch-late    { background: #E4A54C; }
.lt-swatch-absent  { background: var(--lt-flame); }

/* ---------- Session history ---------- */
.lt-history {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden;
}

.lt-history-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 22px;
    border-bottom: 1px solid var(--lt-line);
    cursor: pointer;
    transition: background 0.15s ease;
    gap: 16px;
    flex-wrap: wrap;
}

.lt-history-row:last-child { border-bottom: none; }
.lt-history-row:hover { background: var(--lt-ink); }

.lt-history-date {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.98rem;
    color: var(--lt-parchment);
}

.lt-history-stats {
    display: flex;
    gap: 18px;
    font-size: 0.82rem;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
}

.lt-history-stats .lt-hp { color: var(--lt-gold); }
.lt-history-stats .lt-hl { color: #E4A54C; }
.lt-history-stats .lt-ha { color: var(--lt-flame-bright); }

/* ---------- Empty ---------- */
.lt-empty {
    text-align: center;
    padding: 48px 24px;
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
}

/* ---------- Print stylesheet ---------- */
@media print {
    .lt-page { background: #fff !important; color: #000 !important; }
    .lt-dash-header,
    .lt-toolbar,
    .lt-bulk,
    .lt-header-actions,
    .lt-form-actions { display: none !important; }
    .lt-stat-number, .lt-stat-label { color: #000 !important; }
    .lt-table-wrap, .lt-chart, .lt-history {
        background: #fff !important;
        border-color: #ccc !important;
        color: #000 !important;
    }
    .lt-table thead th { background: #f2f2f2 !important; color: #000 !important; }
    .lt-table tbody td { color: #000 !important; border-color: #ccc !important; }
}

/* ---------- Responsive ---------- */
@media (max-width: 960px) {
    .lt-stats-grid { grid-template-columns: repeat(3, 1fr); }
    .lt-stat:nth-child(3) { border-right: none; }
    .lt-stat:nth-child(1), .lt-stat:nth-child(2), .lt-stat:nth-child(3) {
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
}

@media (max-width: 560px) {
    .lt-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .lt-stat { border-right: 1px solid var(--lt-line); }
    .lt-stat:nth-child(2n) { border-right: none; }
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-form-actions .lt-btn { min-width: 0; width: 100%; }
    .lt-chart-row { grid-template-columns: 56px 1fr 48px; gap: 10px; }
    .lt-bulk { flex-direction: column; align-items: stretch; }
    .lt-bulk .lt-btn { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
    .lt-chart-present, .lt-chart-late, .lt-chart-absent { transition: none; }
}
</style>
</head>
<body>
<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Attendance
                </span>
                <h1>Take attendance.</h1>
                <p>Tracking for <strong><?php echo htmlspecialchars($class['title']); ?></strong> · <?php echo date('l, M d, Y', strtotime($sessionDate)); ?></p>
            </div>
            <div class="lt-header-actions">
                <a href="classes.php" class="lt-btn lt-btn-outline">← My classes</a>
                <a href="students.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-outline">👥 Students</a>
            </div>
        </div>
    </div>

    <div class="lt-container" style="padding-bottom: 72px;">
        <!-- Alerts -->
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

        <!-- Stats strip -->
        <div class="lt-stats-grid" style="margin-top: 32px;">
            <div class="lt-stat">
                <div class="lt-stat-number lt-present"><?php echo (int)$totalPresent; ?></div>
                <div class="lt-stat-label">✅ Present</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number lt-late"><?php echo (int)$totalLate; ?></div>
                <div class="lt-stat-label">⏰ Late</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number lt-absent"><?php echo (int)$totalAbsent; ?></div>
                <div class="lt-stat-label">❌ Absent</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$attendanceRate; ?>%</div>
                <div class="lt-stat-label">📊 Overall rate</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$totalStudents; ?></div>
                <div class="lt-stat-label">👥 Enrolled</div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="lt-toolbar">
            <div class="lt-toolbar-group">
                <form method="get" id="dateForm" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <input type="hidden" name="id" value="<?php echo (int)$classId; ?>">
                    <label for="datePicker">Date</label>
                    <input type="date" id="datePicker" name="date" class="lt-date-input"
                           value="<?php echo htmlspecialchars($sessionDate); ?>"
                           onchange="this.form.submit()">
                </form>
                <a href="?id=<?php echo (int)$classId; ?>&date=<?php echo date('Y-m-d'); ?>" class="lt-btn lt-btn-outline lt-btn-sm">Today</a>
            </div>

            <div class="lt-toolbar-group">
                <button type="button" onclick="exportAttendance()" class="lt-btn lt-btn-outline lt-btn-sm">📥 Export CSV</button>
                <button type="button" onclick="window.print()" class="lt-btn lt-btn-outline lt-btn-sm">🖨️ Print</button>
            </div>
        </div>

        <!-- Bulk actions -->
        <div class="lt-bulk">
            <span class="lt-bulk-label">Bulk mark</span>
            <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin: 0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="session_date" value="<?php echo htmlspecialchars($sessionDate); ?>">
                <button type="submit" name="bulk_attendance" value="present" class="lt-btn lt-btn-gold lt-btn-sm"
                        onclick="return confirm('Mark all students as present?')">✅ All present</button>
                <button type="submit" name="bulk_attendance" value="late" class="lt-btn lt-btn-outline lt-btn-sm"
                        onclick="return confirm('Mark all students as late?')">⏰ All late</button>
                <button type="submit" name="bulk_attendance" value="absent" class="lt-btn lt-btn-outline lt-btn-sm"
                        onclick="return confirm('Mark all students as absent?')">❌ All absent</button>
            </form>
        </div>

        <!-- Attendance form -->
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="session_date" value="<?php echo htmlspecialchars($sessionDate); ?>">

            <div class="lt-table-wrap">
                <div class="lt-table-scroll">
                    <table class="lt-table" id="attendanceTable">
                        <thead>
                            <tr>
                                <th style="width: 48px;">#</th>
                                <th>Student</th>
                                <th>Overall attendance</th>
                                <th>Status for <?php echo date('M d, Y', strtotime($sessionDate)); ?></th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="5" class="lt-table-empty">
                                        No enrolled students in this class yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $index => $student):
                                    $att = $existingAtt[(int)$student['id']] ?? null;
                                    $totalSess = (int)$student['total_sessions'];
                                    $present   = (int)$student['present_count'];
                                    $late      = (int)$student['late_count'];
                                    $absent    = (int)$student['absent_count'];

                                    $studentRate = $totalSess > 0
                                        ? (int)round((($present + $late) / $totalSess) * 100)
                                        : 0;
                                    $rateClass = $studentRate >= 80
                                        ? 'lt-good'
                                        : ($studentRate >= 50 ? 'lt-average' : 'lt-poor');
                                    $currentStatus = $att ? $att['status'] : '';
                                ?>
                                    <tr>
                                        <td style="color: var(--lt-parchment-dim); font-family: 'SFMono-Regular', Menlo, Consolas, monospace;">
                                            <?php echo $index + 1; ?>
                                        </td>
                                        <td>
                                            <div class="lt-student-name"><?php echo htmlspecialchars($student['fullname']); ?></div>
                                            <div class="lt-student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                        </td>
                                        <td>
                                            <div class="lt-att-stat">
                                                <span class="lt-att-rate <?php echo $rateClass; ?>"><?php echo $studentRate; ?>%</span>
                                                <span class="lt-att-breakdown">
                                                    P:<?php echo $present; ?> ·
                                                    L:<?php echo $late; ?> ·
                                                    A:<?php echo $absent; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <select name="attendance[<?php echo (int)$student['id']; ?>]"
                                                    class="lt-status-select <?php echo $currentStatus ? 'lt-' . htmlspecialchars($currentStatus) : ''; ?>">
                                                <option value="present" <?php echo ($att && $att['status'] === 'present') ? 'selected' : ''; ?>>✅ Present</option>
                                                <option value="late"    <?php echo ($att && $att['status'] === 'late')    ? 'selected' : ''; ?>>⏰ Late</option>
                                                <option value="absent"  <?php echo ($att && $att['status'] === 'absent')  ? 'selected' : ''; ?>>❌ Absent</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="notes[<?php echo (int)$student['id']; ?>]"
                                                   class="lt-notes-input"
                                                   value="<?php echo htmlspecialchars($att['notes'] ?? ''); ?>"
                                                   placeholder="Optional note...">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($students)): ?>
                <div class="lt-form-actions">
                    <button type="submit" name="submit_attendance" class="lt-btn lt-btn-primary">💾 Save attendance</button>
                    <button type="reset" class="lt-btn lt-btn-outline">Reset form</button>
                </div>
            <?php endif; ?>
        </form>

        <!-- Attendance chart -->
        <?php if (!empty($weeklyData)): ?>
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num">01</span>
                    <h2>Attendance trend · last 14 days</h2>
                </div>
                <div class="lt-chart">
                    <?php foreach ($weeklyData as $day):
                        $total = (int)$day['present'] + (int)$day['late'] + (int)$day['absent'];
                        $presentPercent = $total > 0 ? ((int)$day['present'] / $total) * 100 : 0;
                        $latePercent    = $total > 0 ? ((int)$day['late']    / $total) * 100 : 0;
                        $absentPercent  = $total > 0 ? ((int)$day['absent']  / $total) * 100 : 0;
                    ?>
                        <div class="lt-chart-row">
                            <div class="lt-chart-label"><?php echo date('M d', strtotime($day['date'])); ?></div>
                            <div class="lt-chart-track">
                                <div class="lt-chart-present" style="width: <?php echo $presentPercent; ?>%;"></div>
                                <div class="lt-chart-late"    style="width: <?php echo $latePercent; ?>%;"></div>
                                <div class="lt-chart-absent"  style="width: <?php echo $absentPercent; ?>%;"></div>
                            </div>
                            <div class="lt-chart-count"><?php echo (int)$day['present']; ?>/<?php echo $total; ?></div>
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

        <!-- Session history -->
        <?php if (!empty($sessionDates)): ?>
            <div class="lt-section">
                <div class="lt-section-head">
                    <span class="lt-num">02</span>
                    <h2>Session history</h2>
                </div>
                <div class="lt-history">
                    <?php foreach ($sessionDates as $session): ?>
                        <div class="lt-history-row"
                             onclick="window.location.href='attendance.php?id=<?php echo (int)$classId; ?>&date=<?php echo htmlspecialchars($session['session_date']); ?>'">
                            <div class="lt-history-date">
                                <?php echo date('l, M d, Y', strtotime($session['session_date'])); ?>
                            </div>
                            <div class="lt-history-stats">
                                <span class="lt-hp">✅ <?php echo (int)$session['present_count']; ?></span>
                                <span class="lt-hl">⏰ <?php echo (int)$session['late_count']; ?></span>
                                <span class="lt-ha">❌ <?php echo (int)$session['absent_count']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
/* ---------- CSV export ----------
   Rows come from PHP so the export never depends on DOM scraping
   (which would pull in emoji pills and break on commas). */
const __CSV_ROWS__ = <?php echo json_encode($csvRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const __CSV_NAME__ = 'attendance_<?php echo preg_replace('/[^A-Za-z0-9_-]/', '_', $class['title']); ?>_<?php echo $sessionDate; ?>.csv';

function csvEscape(v) {
    v = (v === null || v === undefined) ? '' : String(v);
    if (v.indexOf(',') !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) {
        return '"' + v.replace(/"/g, '""') + '"';
    }
    return v;
}

function exportAttendance() {
    const lines = __CSV_ROWS__.map(function (row) {
        return row.map(csvEscape).join(',');
    });
    const csv  = '\uFEFF' + lines.join('\r\n');   // BOM for Excel
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

/* ---------- Auto-save on select change ----------
   Only fires once per 2 seconds even if the user changes
   several selects in a row. Works only when a Save button exists. */
(function () {
    const form = document.querySelector('form input[name="submit_attendance"]')?.closest('form');
    if (!form) return;

    const saveButton = form.querySelector('button[name="submit_attendance"]');
    if (!saveButton) return;

    let timer = null;
    document.querySelectorAll('.lt-status-select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            /* Sync the visual pill colour immediately */
            sel.classList.remove('lt-present', 'lt-late', 'lt-absent');
            sel.classList.add('lt-' + sel.value);

            if (timer) clearTimeout(timer);
            timer = setTimeout(function () {
                saveButton.click();
            }, 2000);
        });
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>