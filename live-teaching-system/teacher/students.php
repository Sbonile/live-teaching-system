<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$teacherId = $_SESSION['user']['id'];
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$msg = '';
$error = '';

// Handle certificate issuance
if (isset($_POST['issue_certificate'])) {
    $enrollId = (int)$_POST['enroll_id'];
    $connection->query("UPDATE enrollments SET certificate_issued = 1 WHERE id = $enrollId");
    $msg = "Certificate issued successfully!";
}

// Handle bulk certificate issuance
if (isset($_POST['bulk_certificates'])) {
    $selectedStudents = $_POST['selected_students'] ?? [];
    if (!empty($selectedStudents)) {
        $ids = implode(',', array_map('intval', $selectedStudents));
        $connection->query("UPDATE enrollments SET certificate_issued = 1 WHERE id IN ($ids)");
        $msg = "Certificates issued to " . count($selectedStudents) . " student(s)!";
    } else {
        $error = "Please select at least one student.";
    }
}

// Handle sending message to student
if (isset($_POST['send_message'])) {
    $studentId = (int)$_POST['student_id'];
    $message = trim($_POST['message']);
    // In a real system, you'd store this in a messages table or send email
    $msg = "Message sent to student (feature coming soon)";
}

// Get class info if specific class selected
if ($classId > 0) {
    $class = $connection->query("SELECT * FROM live_classes WHERE id = $classId AND teacher_id = $teacherId")->fetch_assoc();
    if (!$class) {
        header('Location: dashboard.php');
        exit;
    }
}

// Get students data
if ($classId > 0) {
    // Students for specific class
    $students = $connection->query("
        SELECT u.id, u.fullname, u.email, u.phone, u.created_at as joined_date,
               e.id as enroll_id, e.amount_paid, e.enrolled_at, e.attendance, 
               e.certificate_issued, e.payment_status,
               (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = $classId AND a.status = 'present') as present_count,
               (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = $classId AND a.status = 'late') as late_count,
               (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = $classId AND a.status = 'absent') as absent_count,
               (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.class_id = $classId) as total_sessions,
               (SELECT rating FROM reviews WHERE student_id = u.id AND class_id = $classId LIMIT 1) as rating,
               (SELECT comment FROM reviews WHERE student_id = u.id AND class_id = $classId LIMIT 1) as review_comment
        FROM users u
        JOIN enrollments e ON e.student_id = u.id
        WHERE e.class_id = $classId AND e.payment_status = 'paid'
        ORDER BY u.fullname
    ")->fetch_all(MYSQLI_ASSOC);
    
    $classTitle = $class['title'];
} else {
    // All students across all teacher's classes
    $students = $connection->query("
        SELECT u.id, u.fullname, u.email, u.phone, u.created_at as joined_date,
               COUNT(DISTINCT e.class_id) as classes_count,
               SUM(e.amount_paid) as total_paid,
               SUM(e.attendance) as total_attendance,
               COUNT(DISTINCT CASE WHEN e.certificate_issued = 1 THEN e.class_id END) as certificates_earned,
               MAX(e.enrolled_at) as last_enrolled
        FROM users u
        JOIN enrollments e ON e.student_id = u.id
        JOIN live_classes c ON e.class_id = c.id
        WHERE c.teacher_id = $teacherId AND e.payment_status = 'paid'
        GROUP BY u.id
        ORDER BY u.fullname
    ")->fetch_all(MYSQLI_ASSOC);
    
    $classTitle = "All Classes";
}

// Get stats
$totalStudents = count($students);
$totalRevenue = array_sum(array_column($students, 'total_paid'));
$totalAttendance = array_sum(array_column($students, 'attendance'));
$certificatesIssued = array_sum(array_column($students, 'certificate_issued'));
?>

<style>
/* ===== LiveTeach students revamp — scoped to .lt-page ===== */
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
}

.lt-dash-header p strong {
    color: var(--lt-gold);
    font-weight: 500;
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
    padding: 10px 18px;
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

.lt-btn-sm {
    padding: 8px 14px;
    font-size: 0.8rem;
}

.lt-btn-static {
    cursor: default;
    transform: none !important;
}

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 24px;
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
    grid-template-columns: repeat(4, 1fr);
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
    padding: 18px 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.lt-filter-field {
    flex: 1;
    min-width: 200px;
}

.lt-filter-field input,
.lt-filter-field select {
    width: 100%;
    padding: 11px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.88rem;
    font-family: inherit;
    transition: border-color 0.15s ease;
    box-sizing: border-box;
}

.lt-filter-field input::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-filter-field input:focus,
.lt-filter-field select:focus {
    outline: none;
    border-color: var(--lt-flame);
}

/* ---------- Bulk actions ---------- */
.lt-bulk {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 14px 20px;
    margin-bottom: 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}

.lt-bulk-label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    cursor: pointer;
    user-select: none;
}

.lt-bulk-label input[type="checkbox"],
.lt-student-checkbox {
    width: 16px;
    height: 16px;
    accent-color: var(--lt-flame);
    cursor: pointer;
    flex-shrink: 0;
}

/* ---------- Student cards ---------- */
.lt-student {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    margin-bottom: 14px;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.lt-student:hover { border-color: var(--lt-gold); }

.lt-student-head {
    padding: 20px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    cursor: pointer;
    user-select: none;
}

.lt-student-ident {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    min-width: 0;
}

.lt-student-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.15rem;
    color: var(--lt-parchment);
    flex-shrink: 0;
    text-transform: uppercase;
}

.lt-student-name {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.08rem;
    color: var(--lt-parchment);
    margin: 0 0 4px;
    line-height: 1.3;
}

.lt-student-email {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
}

.lt-student-rating {
    font-size: 0.85rem;
    color: var(--lt-gold);
    letter-spacing: 1px;
    margin-top: 4px;
}

.lt-student-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.lt-chevron {
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    transition: transform 0.25s ease, color 0.15s ease;
    width: 22px;
    text-align: center;
    flex-shrink: 0;
}

.lt-student.lt-open .lt-chevron {
    transform: rotate(180deg);
    color: var(--lt-gold);
}

/* Status pills */
.lt-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    white-space: nowrap;
    border: 1px solid transparent;
}

.lt-pill-good {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.35);
}

.lt-pill-average {
    background: rgba(228, 78, 46, 0.15);
    color: var(--lt-flame-bright);
    border-color: rgba(228, 78, 46, 0.35);
}

.lt-pill-poor {
    background: rgba(200, 52, 30, 0.18);
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.45);
}

.lt-pill-neutral {
    background: rgba(241, 231, 214, 0.06);
    color: var(--lt-parchment-dim);
    border-color: var(--lt-line);
}

/* ---------- Details panel ---------- */
.lt-student-details {
    display: none;
    padding: 0 22px 22px;
    border-top: 1px solid var(--lt-line);
}

.lt-student.lt-open .lt-student-details {
    display: block;
    padding-top: 22px;
}

.lt-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
}

.lt-info-item {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 18px 16px;
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

.lt-info-value .lt-iv-num {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-parchment);
}

/* Review quote */
.lt-review-quote {
    margin-top: 8px;
    padding: 10px 12px;
    background: var(--lt-charcoal);
    border-left: 2px solid var(--lt-gold);
    border-radius: 4px;
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    font-style: italic;
    line-height: 1.55;
}

/* Actions row */
.lt-student-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--lt-line);
    align-items: center;
}

.lt-student-actions .lt-checkbox-wrap {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    cursor: pointer;
    user-select: none;
}

/* ---------- Empty state ---------- */
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
}

.lt-empty h3 {
    font-size: 1.3rem;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-empty p {
    color: var(--lt-parchment-dim);
    margin: 0 0 24px;
    font-size: 0.92rem;
    line-height: 1.65;
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
    max-width: 500px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 14px;
    overflow: hidden;
}

.lt-modal-head {
    padding: 20px 24px;
    background: var(--lt-charcoal-raised);
    border-bottom: 1px solid var(--lt-line);
}

.lt-modal-head h3 {
    font-size: 1.1rem;
    margin: 0;
    color: var(--lt-parchment);
}

.lt-modal-head h3 span {
    color: var(--lt-gold);
}

.lt-modal-body {
    padding: 22px 24px 24px;
}

.lt-field {
    margin-bottom: 18px;
}

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
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.92rem;
    font-family: inherit;
    resize: vertical;
    min-height: 100px;
    box-sizing: border-box;
}

.lt-field textarea::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-field textarea:focus {
    outline: none;
    border-color: var(--lt-flame);
}

.lt-modal-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ---------- Responsive ---------- */
@media (max-width: 900px) {
    .lt-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .lt-stat:nth-child(2) { border-right: none; }
    .lt-stat:nth-child(1),
    .lt-stat:nth-child(2) {
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-dash-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 560px) {
    .lt-stats-grid {
        grid-template-columns: 1fr;
    }
    .lt-stat {
        border-right: none;
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-stat:last-child { border-bottom: none; }
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-student-head { padding: 18px 18px; }
    .lt-student-details { padding-left: 18px; padding-right: 18px; }
    .lt-student-right { width: 100%; }
    .lt-student-actions .lt-btn { width: 100%; }
    .lt-student-actions .lt-checkbox-wrap { margin-left: 0; width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot { animation: none; }
    .lt-chevron { transition: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Student roster
                </span>
                <h1>My students.</h1>
                <p>
                    <?php if ($classId > 0): ?>
                        Enrolled in <strong><?php echo htmlspecialchars($classTitle); ?></strong>
                    <?php else: ?>
                        Across all of your classes
                    <?php endif; ?>
                </p>
            </div>
            <div class="lt-header-actions">
                <?php if ($classId > 0): ?>
                    <a href="students.php" class="lt-btn lt-btn-outline">View all students</a>
                    <a href="attendance.php?id=<?php echo $classId; ?>" class="lt-btn lt-btn-outline">📋 Take attendance</a>
                <?php endif; ?>
                <a href="dashboard.php" class="lt-btn lt-btn-outline">← Dashboard</a>
            </div>
        </div>
    </div>

    <div class="lt-container" style="padding-bottom: 72px;">
        <!-- Alerts -->
        <?php if ($msg): ?>
            <div class="lt-alert lt-alert-success" style="margin-top: 32px;">
                <span>✓</span><span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="lt-alert lt-alert-error" style="margin-top: 32px;">
                <span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats strip -->
        <div class="lt-stats-grid" style="margin-top: 32px;">
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo $totalStudents; ?></div>
                <div class="lt-stat-label">Total students</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number">R <?php echo number_format($totalRevenue, 0); ?></div>
                <div class="lt-stat-label">Total revenue</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo $totalAttendance; ?></div>
                <div class="lt-stat-label">Total attendance</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo $certificatesIssued; ?></div>
                <div class="lt-stat-label">Certificates issued</div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="lt-filter-bar">
            <div class="lt-filter-field">
                <input type="text" id="searchInput" placeholder="🔍 Search by name or email...">
            </div>
            <?php if ($classId > 0): ?>
                <div class="lt-filter-field" style="flex: 0 0 200px;">
                    <select id="attendanceFilter">
                        <option value="">All attendance</option>
                        <option value="good">Good (80%+)</option>
                        <option value="average">Average (50–79%)</option>
                        <option value="poor">Poor (&lt;50%)</option>
                    </select>
                </div>
                <div class="lt-filter-field" style="flex: 0 0 200px;">
                    <select id="certificateFilter">
                        <option value="">All students</option>
                        <option value="issued">Certificate issued</option>
                        <option value="not_issued">No certificate yet</option>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bulk actions -->
        <?php if ($classId > 0 && !empty($students)): ?>
            <form method="post" id="bulkForm">
                <div class="lt-bulk">
                    <label class="lt-bulk-label">
                        <input type="checkbox" id="selectAll">
                        <span>Select all</span>
                    </label>
                    <button type="submit" name="bulk_certificates" class="lt-btn lt-btn-gold" onclick="return confirm('Issue certificates to selected students?')">
                        📜 Issue certificates to selected
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- Students list -->
        <?php if (empty($students)): ?>
            <div class="lt-empty">
                <div class="lt-empty-icon">👥</div>
                <h3>No students yet</h3>
                <p>
                    <?php if ($classId > 0): ?>
                        No students have enrolled in this class yet.<br>Share the class link to attract students!
                    <?php else: ?>
                        You haven't had any student enrollments yet.<br>Create engaging classes to attract students!
                    <?php endif; ?>
                </p>
                <?php if ($classId > 0): ?>
                    <a href="../classes/class.php?id=<?php echo $classId; ?>" class="lt-btn lt-btn-primary" target="_blank">
                        View class page →
                    </a>
                <?php else: ?>
                    <a href="add-class.php" class="lt-btn lt-btn-primary">
                        Create a class
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($students as $student): 
                $attendancePercent = 0;
                $attendanceClass = '';
                if ($classId > 0 && $student['total_sessions'] > 0) {
                    $attendancePercent = round(($student['present_count'] / $student['total_sessions']) * 100);
                    if ($attendancePercent >= 80) {
                        $attendanceClass = 'lt-pill-good';
                    } elseif ($attendancePercent >= 50) {
                        $attendanceClass = 'lt-pill-average';
                    } else {
                        $attendanceClass = 'lt-pill-poor';
                    }
                }
                
                // Avatar initial
                $initial = strtoupper(substr($student['fullname'], 0, 1));
            ?>
                <div class="lt-student" 
                     data-student-name="<?php echo strtolower($student['fullname']); ?>" 
                     data-student-email="<?php echo strtolower($student['email']); ?>" 
                     data-attendance="<?php echo $attendancePercent; ?>" 
                     data-certificate="<?php echo $classId > 0 ? ($student['certificate_issued'] ? 'issued' : 'not_issued') : ''; ?>">
                    
                    <div class="lt-student-head" onclick="toggleDetails(this)">
                        <div class="lt-student-ident">
                            <div class="lt-student-avatar"><?php echo $initial; ?></div>
                            <div style="min-width: 0;">
                                <h3 class="lt-student-name"><?php echo htmlspecialchars($student['fullname']); ?></h3>
                                <div class="lt-student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                <?php if ($classId > 0 && $student['rating']): ?>
                                    <div class="lt-student-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?php echo $i <= $student['rating'] ? '★' : '☆'; ?>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="lt-student-right">
                            <?php if ($classId > 0): ?>
                                <span class="lt-pill <?php echo $attendanceClass; ?>">
                                    📊 <?php echo $attendancePercent; ?>% attendance
                                </span>
                                <?php if ($student['certificate_issued']): ?>
                                    <span class="lt-pill lt-pill-good">🏆 Certificate issued</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="lt-pill lt-pill-neutral">
                                    📚 <?php echo $student['classes_count']; ?> class<?php echo $student['classes_count'] != 1 ? 'es' : ''; ?>
                                </span>
                            <?php endif; ?>
                            <span class="lt-chevron">▼</span>
                        </div>
                    </div>
                    
                    <div class="lt-student-details">
                        <div class="lt-info-grid">
                            <div class="lt-info-item">
                                <div class="lt-info-label">Contact information</div>
                                <div class="lt-info-value">
                                    <span class="lt-iv-icon">📧</span>
                                    <span><?php echo htmlspecialchars($student['email']); ?></span>
                                </div>
                                <div class="lt-info-value">
                                    <span class="lt-iv-icon">📞</span>
                                    <span><?php echo htmlspecialchars($student['phone'] ?? 'Not provided'); ?></span>
                                </div>
                                <div class="lt-info-value">
                                    <span class="lt-iv-icon">📅</span>
                                    <span>Joined <?php echo date('M d, Y', strtotime($student['joined_date'] ?? $student['enrolled_at'])); ?></span>
                                </div>
                            </div>
                            
                            <?php if ($classId > 0): ?>
                                <div class="lt-info-item">
                                    <div class="lt-info-label">Attendance details</div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">✅</span>
                                        <span>Present: <span class="lt-iv-num"><?php echo $student['present_count']; ?></span> sessions</span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">⏰</span>
                                        <span>Late: <span class="lt-iv-num"><?php echo $student['late_count']; ?></span> sessions</span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">❌</span>
                                        <span>Absent: <span class="lt-iv-num"><?php echo $student['absent_count']; ?></span> sessions</span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">📊</span>
                                        <span>Total: <span class="lt-iv-num"><?php echo $student['total_sessions']; ?></span> sessions</span>
                                    </div>
                                </div>
                                
                                <div class="lt-info-item">
                                    <div class="lt-info-label">Payment &amp; progress</div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">💰</span>
                                        <span>Amount paid: <span class="lt-iv-num">R <?php echo number_format($student['amount_paid'], 2); ?></span></span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">📅</span>
                                        <span>Enrolled <?php echo date('M d, Y', strtotime($student['enrolled_at'])); ?></span>
                                    </div>
                                    <?php if ($student['review_comment']): ?>
                                        <div class="lt-review-quote">
                                            "<?php echo htmlspecialchars(substr($student['review_comment'], 0, 80)); ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="lt-info-item">
                                    <div class="lt-info-label">Statistics</div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">📚</span>
                                        <span>Classes enrolled: <span class="lt-iv-num"><?php echo $student['classes_count']; ?></span></span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">💰</span>
                                        <span>Total paid: <span class="lt-iv-num">R <?php echo number_format($student['total_paid'], 2); ?></span></span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">🎓</span>
                                        <span>Certificates: <span class="lt-iv-num"><?php echo $student['certificates_earned']; ?></span></span>
                                    </div>
                                    <div class="lt-info-value">
                                        <span class="lt-iv-icon">📅</span>
                                        <span>Last enrolled <?php echo date('M d, Y', strtotime($student['last_enrolled'])); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="lt-student-actions">
                            <?php if ($classId > 0): ?>
                                <?php if (!$student['certificate_issued']): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="enroll_id" value="<?php echo $student['enroll_id']; ?>">
                                        <button type="submit" name="issue_certificate" class="lt-btn lt-btn-primary lt-btn-sm">
                                            🏆 Issue certificate
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="lt-btn lt-btn-outline lt-btn-sm lt-btn-static" style="opacity: 0.75; border-color: rgba(217,164,65,0.35); color: var(--lt-gold);">
                                        ✅ Certificate issued
                                    </span>
                                <?php endif ?>
                                
                                <button onclick="showMessageModal(<?php echo $student['id']; ?>, '<?php echo addslashes($student['fullname']); ?>')" class="lt-btn lt-btn-outline lt-btn-sm">
                                    💬 Send message
                                </button>
                                
                                <a href="attendance.php?id=<?php echo $classId; ?>&date=<?php echo date('Y-m-d'); ?>" class="lt-btn lt-btn-outline lt-btn-sm">
                                    📋 Take attendance
                                </a>
                                
                                <?php if ($classId > 0 && $student['enroll_id']): ?>
                                    <label class="lt-checkbox-wrap">
                                        <input type="checkbox" class="student-checkbox" value="<?php echo $student['enroll_id']; ?>" form="bulkForm">
                                        <span>Select</span>
                                    </label>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="?class_id=<?php echo $student['classes_count'] > 0 ? '1' : ''; ?>" class="lt-btn lt-btn-outline lt-btn-sm">
                                    View classes
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Message Modal -->
<div id="messageModal" class="lt-modal">
    <div class="lt-modal-box">
        <div class="lt-modal-head">
            <h3>Send message to <span id="studentName"></span></h3>
        </div>
        <div class="lt-modal-body">
            <form method="post">
                <input type="hidden" name="student_id" id="messageStudentId">
                <div class="lt-field">
                    <label for="messageText">Message</label>
                    <textarea id="messageText" name="message" rows="4" required placeholder="Type your message here..."></textarea>
                </div>
                <div class="lt-modal-actions">
                    <button type="submit" name="send_message" class="lt-btn lt-btn-primary">Send message</button>
                    <button type="button" onclick="closeModal()" class="lt-btn lt-btn-outline">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle student details
function toggleDetails(element) {
    const card = element.closest('.lt-student');
    if (card) {
        card.classList.toggle('lt-open');
    }
}

// Search functionality
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const students = document.querySelectorAll('.lt-student');
        
        students.forEach(student => {
            const name = student.getAttribute('data-student-name');
            const email = student.getAttribute('data-student-email');
            
            if (name.includes(searchTerm) || email.includes(searchTerm)) {
                student.style.display = 'block';
            } else {
                student.style.display = 'none';
            }
        });
    });
}

// Attendance filter
const attendanceFilter = document.getElementById('attendanceFilter');
if (attendanceFilter) {
    attendanceFilter.addEventListener('change', function() {
        const filterValue = this.value;
        const students = document.querySelectorAll('.lt-student');
        
        students.forEach(student => {
            const attendance = parseInt(student.getAttribute('data-attendance'));
            
            if (filterValue === '') {
                student.style.display = 'block';
            } else if (filterValue === 'good' && attendance >= 80) {
                student.style.display = 'block';
            } else if (filterValue === 'average' && attendance >= 50 && attendance < 80) {
                student.style.display = 'block';
            } else if (filterValue === 'poor' && attendance < 50) {
                student.style.display = 'block';
            } else {
                student.style.display = 'none';
            }
        });
    });
}

// Certificate filter
const certificateFilter = document.getElementById('certificateFilter');
if (certificateFilter) {
    certificateFilter.addEventListener('change', function() {
        const filterValue = this.value;
        const students = document.querySelectorAll('.lt-student');
        
        students.forEach(student => {
            const certificate = student.getAttribute('data-certificate');
            
            if (filterValue === '') {
                student.style.display = 'block';
            } else if (filterValue === 'issued' && certificate === 'issued') {
                student.style.display = 'block';
            } else if (filterValue === 'not_issued' && certificate === 'not_issued') {
                student.style.display = 'block';
            } else {
                student.style.display = 'none';
            }
        });
    });
}

// Select all functionality
const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.student-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
    });
}

// Message modal
function showMessageModal(studentId, studentName) {
    document.getElementById('messageStudentId').value = studentId;
    document.getElementById('studentName').innerHTML = studentName;
    document.getElementById('messageModal').classList.add('lt-open');
}

function closeModal() {
    document.getElementById('messageModal').classList.remove('lt-open');
}

// Close modal when clicking outside
document.getElementById('messageModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>