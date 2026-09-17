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
$currentYear = date('Y');

// Get teacher's profile info
$teacher = $connection->query("SELECT * FROM users WHERE id = $teacherId")->fetch_assoc();

// Get all classes with stats
$classes = $connection->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND payment_status = 'paid') as student_count,
        (SELECT SUM(amount_paid) FROM enrollments WHERE class_id = c.id AND payment_status = 'paid') as revenue,
        (SELECT COUNT(*) FROM attendance a JOIN enrollments e ON a.student_id = e.student_id WHERE e.class_id = c.id) as attendance_count
    FROM live_classes c
    WHERE c.teacher_id = $teacherId
    ORDER BY c.start_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Calculate totals
$totalClasses = count($classes);
$totalStudents = 0;
$totalRevenue = 0;
$totalAttendance = 0;
$activeClasses = 0;
$upcomingClasses = 0;
$completedClasses = 0;

foreach ($classes as $class) {
    $totalStudents += $class['student_count'];
    $totalRevenue += $class['revenue'];
    $totalAttendance += $class['attendance_count'];
    
    if ($class['status'] == 'ongoing') $activeClasses++;
    if ($class['status'] == 'upcoming') $upcomingClasses++;
    if ($class['status'] == 'completed') $completedClasses++;
}

// Get recent enrollments
$recentEnrollments = $connection->query("
    SELECT e.*, u.fullname as student_name, u.email, c.title as class_title
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    JOIN live_classes c ON e.class_id = c.id
    WHERE c.teacher_id = $teacherId AND e.payment_status = 'paid'
    ORDER BY e.enrolled_at DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get monthly revenue for chart (last 6 months)
$monthlyRevenue = $connection->query("
    SELECT DATE_FORMAT(e.enrolled_at, '%b') as month,
           DATE_FORMAT(e.enrolled_at, '%m') as month_num,
           SUM(e.amount_paid) as revenue,
           COUNT(e.id) as enrollments
    FROM enrollments e
    JOIN live_classes c ON e.class_id = c.id
    WHERE c.teacher_id = $teacherId AND e.payment_status = 'paid'
        AND e.enrolled_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(e.enrolled_at, '%Y-%m')
    ORDER BY e.enrolled_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Get top performing classes
$topClasses = $connection->query("
    SELECT c.title, COUNT(e.id) as enrollments, SUM(e.amount_paid) as revenue
    FROM live_classes c
    LEFT JOIN enrollments e ON e.class_id = c.id AND e.payment_status = 'paid'
    WHERE c.teacher_id = $teacherId
    GROUP BY c.id
    ORDER BY enrollments DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get pending tasks (classes starting soon)
$pendingTasks = $connection->query("
    SELECT id, title, start_date, status
    FROM live_classes
    WHERE teacher_id = $teacherId 
        AND status = 'upcoming' 
        AND start_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY start_date ASC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Calculate average rating
$avgRating = $connection->query("
    SELECT AVG(r.rating) as avg_rating, COUNT(r.id) as total_reviews
    FROM reviews r
    JOIN live_classes c ON r.class_id = c.id
    WHERE c.teacher_id = $teacherId
")->fetch_assoc();

// Get certificate count
$certificatesIssued = $connection->query("
    SELECT COUNT(*) as count FROM enrollments e
    JOIN live_classes c ON e.class_id = c.id
    WHERE c.teacher_id = $teacherId AND e.certificate_issued = 1
")->fetch_assoc()['count'];
?>

<style>
/* ===== LiveTeach teacher dashboard revamp — scoped to .lt-page ===== */
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
    overflow-x: hidden;
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

/* ============================================================= */
/* ANIMATION SYSTEM                                              */
/* ============================================================= */

.lt-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.lt-reveal.lt-visible { opacity: 1; transform: translateY(0); }

.lt-reveal-left {
    opacity: 0;
    transform: translateX(-28px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-left.lt-visible { opacity: 1; transform: translateX(0); }

.lt-reveal-right {
    opacity: 0;
    transform: translateX(28px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-right.lt-visible { opacity: 1; transform: translateX(0); }

.lt-reveal-scale {
    opacity: 0;
    transform: scale(0.95);
    transition:
        opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-scale.lt-visible { opacity: 1; transform: scale(1); }

.lt-delay-1 { transition-delay: 0.08s; }
.lt-delay-2 { transition-delay: 0.16s; }
.lt-delay-3 { transition-delay: 0.24s; }
.lt-delay-4 { transition-delay: 0.32s; }
.lt-delay-5 { transition-delay: 0.40s; }
.lt-delay-6 { transition-delay: 0.48s; }

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
    animation: lt-glow-float 12s ease-in-out infinite;
}

@keyframes lt-glow-float {
    0%, 100% { transform: translate(0, 0) scale(1); opacity: 1; }
    50% { transform: translate(-24px, 18px) scale(1.08); opacity: 0.88; }
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

.lt-header-rating {
    text-align: right;
    flex-shrink: 0;
}

.lt-header-stars {
    font-size: 1.3rem;
    color: var(--lt-gold);
    letter-spacing: 2px;
    line-height: 1;
    margin-bottom: 6px;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-header-rating:hover .lt-header-stars {
    transform: scale(1.06);
}

.lt-header-rating-meta {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
}

/* ---------- Stats ---------- */
.lt-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
}

.lt-stat {
    padding: 28px 22px;
    border-right: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
    transition: background 0.3s ease;
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
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1), left 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-stat:hover::before {
    width: calc(100% - 44px);
}

.lt-stat:hover {
    background: var(--lt-charcoal);
}

.lt-stat-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.9rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
    transition: color 0.3s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: inline-block;
}

.lt-stat:hover .lt-stat-number {
    color: var(--lt-gold);
    transform: scale(1.05);
}

.lt-stat-label {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    margin-bottom: 6px;
    transition: color 0.3s ease;
}

.lt-stat-meta {
    font-size: 0.75rem;
    color: var(--lt-gold);
}

.lt-stat-meta.lt-muted {
    color: var(--lt-parchment-dim);
    opacity: 0.7;
}

/* ---------- Quick actions ---------- */
.lt-quick-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    padding: 40px 0 32px;
}

.lt-quick-btn {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition:
        border-color 0.25s ease,
        transform 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        background 0.3s ease,
        box-shadow 0.4s ease;
    position: relative;
    overflow: hidden;
}

.lt-quick-btn::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(200, 52, 30, 0.08), transparent 60%);
    opacity: 0;
    transition: opacity 0.35s ease;
    pointer-events: none;
}

.lt-quick-btn:hover {
    border-color: var(--lt-flame);
    transform: translateY(-4px);
    background: var(--lt-charcoal-raised);
    box-shadow: 0 16px 32px -20px rgba(200, 52, 30, 0.5);
}

.lt-quick-btn:hover::before { opacity: 1; }

.lt-quick-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease;
}

.lt-quick-btn:hover .lt-quick-icon {
    transform: scale(1.1) rotate(-4deg);
    box-shadow: 0 8px 16px -8px rgba(200, 52, 30, 0.7);
}

.lt-quick-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.lt-quick-text .lt-quick-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--lt-parchment);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-quick-text .lt-quick-hint {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    transition: color 0.25s ease;
}

.lt-quick-btn:hover .lt-quick-hint {
    color: var(--lt-gold);
}

/* ---------- Layout ---------- */
.lt-dash-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 28px;
    padding: 8px 0 72px;
    align-items: start;
}

.lt-col {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

/* ---------- Sections ---------- */
.lt-section {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 26px 24px;
    transition: border-color 0.3s ease;
}

.lt-section:hover {
    border-color: rgba(217, 164, 65, 0.25);
}

.lt-section-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-section-head h2 {
    font-size: 1.15rem;
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
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    padding: 3px 9px;
    border-radius: 999px;
    letter-spacing: 0.05em;
}

.lt-empty-note {
    text-align: center;
    padding: 34px 20px;
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    border: 1px dashed var(--lt-line);
    border-radius: 10px;
    line-height: 1.6;
}

.lt-empty-note .lt-empty-icon {
    font-size: 2rem;
    display: block;
    margin-bottom: 12px;
    opacity: 0.6;
}

.lt-empty-note .lt-btn {
    margin-top: 16px;
}

/* ---------- Class cards (in dashboard) ---------- */
.lt-class-row {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 16px 18px;
    margin-bottom: 12px;
    transition:
        border-color 0.25s ease,
        transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
        box-shadow 0.35s ease;
}

.lt-class-row:last-child { margin-bottom: 0; }

.lt-class-row:hover {
    border-color: var(--lt-gold);
    transform: translateY(-2px);
    box-shadow: 0 12px 24px -18px rgba(217, 164, 65, 0.5);
}

.lt-class-row-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}

.lt-class-row-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.02rem;
    color: var(--lt-parchment);
    margin: 0 0 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.lt-class-row-date {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
}

.lt-class-row-stats {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    margin-bottom: 12px;
}

.lt-class-row-stats strong {
    color: var(--lt-parchment);
    font-weight: 500;
}

.lt-class-row-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* ---------- Status pills ---------- */
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
    border: 1px solid var(--lt-line);
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 7px;
    font-size: 0.8rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1),
                background 0.2s ease,
                border-color 0.2s ease,
                color 0.2s ease,
                box-shadow 0.25s ease;
    cursor: pointer;
    font-family: inherit;
    text-align: center;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}

.lt-btn:hover { transform: translateY(-2px); }

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover {
    background: var(--lt-flame-bright);
    box-shadow: 0 10px 20px -10px rgba(228, 78, 46, 0.6);
}

.lt-btn-gold {
    background: var(--lt-gold);
    color: var(--lt-ink);
}
.lt-btn-gold:hover {
    background: #E8B85A;
    box-shadow: 0 10px 20px -10px rgba(217, 164, 65, 0.6);
}

.lt-btn-outline {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
    box-shadow: 0 8px 16px -12px rgba(217, 164, 65, 0.5);
}

.lt-btn-block {
    width: 100%;
}

/* ---------- Revenue chart ---------- */
.lt-chart {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.lt-chart-row {
    display: grid;
    grid-template-columns: 42px 1fr;
    align-items: center;
    gap: 14px;
}

.lt-chart-label {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.04em;
}

.lt-chart-track {
    background: var(--lt-ink);
    border-radius: 6px;
    height: 26px;
    position: relative;
    overflow: hidden;
}

.lt-chart-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--lt-flame-dark), var(--lt-flame));
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 10px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--lt-parchment);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    transition: width 0.8s cubic-bezier(0.22, 1, 0.36, 1);
    min-width: 48px;
    /* Grow animation: bar starts at 0 width, JS sets target */
    transform-origin: left center;
}

.lt-chart-bar.lt-animate {
    /* The width animation is triggered via transition when we toggle .lt-grow on the parent */
}

.lt-chart-row:hover .lt-chart-bar {
    background: linear-gradient(90deg, var(--lt-flame), var(--lt-flame-bright));
}

/* ---------- Enrollment / list rows ---------- */
.lt-list-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 13px 0;
    border-bottom: 1px solid var(--lt-line);
    transition: padding-left 0.3s cubic-bezier(0.22, 1, 0.36, 1), background 0.25s ease;
    position: relative;
}

.lt-list-row::before {
    content: "";
    position: absolute;
    left: 0;
    top: 25%;
    bottom: 25%;
    width: 2px;
    background: var(--lt-flame);
    transform: scaleY(0);
    transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-list-row:hover::before { transform: scaleY(1); }

.lt-list-row:last-child { border-bottom: none; }

.lt-list-row:hover {
    padding-left: 12px;
    background: linear-gradient(90deg, rgba(200, 52, 30, 0.05), transparent 70%);
}

.lt-list-row-main {
    min-width: 0;
    flex: 1;
}

.lt-list-row-title {
    font-size: 0.92rem;
    color: var(--lt-parchment);
    font-weight: 500;
    margin: 0 0 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-list-row-sub {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-list-row-right {
    text-align: right;
    flex-shrink: 0;
}

.lt-list-row-value {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1rem;
    color: var(--lt-gold);
    transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-list-row:hover .lt-list-row-value {
    transform: scale(1.05);
}

.lt-list-row-meta {
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    margin-top: 2px;
}

.lt-medal {
    font-size: 0.95rem;
    margin-right: 4px;
    display: inline-block;
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-list-row:hover .lt-medal {
    transform: scale(1.2) rotate(-8deg);
}

/* ---------- Tasks ---------- */
.lt-task {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--lt-line);
    transition: padding-left 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-task:last-child { border-bottom: none; }

.lt-task:hover {
    padding-left: 6px;
}

.lt-task-priority {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.lt-task:hover .lt-task-priority {
    transform: scale(1.3);
}

.lt-priority-high {
    background: var(--lt-flame-bright);
    box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.5);
    animation: lt-priority-pulse 2s ease-in-out infinite;
}

@keyframes lt-priority-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 5px rgba(228, 78, 46, 0); }
}

.lt-priority-medium { background: var(--lt-gold); }
.lt-priority-low { background: rgba(241, 231, 214, 0.35); }

.lt-task-body {
    flex: 1;
    min-width: 0;
}

.lt-task-title {
    font-size: 0.92rem;
    color: var(--lt-parchment);
    font-weight: 500;
    margin: 0 0 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lt-task-meta {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
}

/* ---------- Pro tips ---------- */
.lt-tips {
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    border: none;
    position: relative;
    overflow: hidden;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.4s ease;
}

.lt-tips::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.1), transparent 60%);
    opacity: 0;
    transition: opacity 0.6s ease;
    pointer-events: none;
}

.lt-tips:hover::before { opacity: 1; }

.lt-tips:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 40px -24px rgba(200, 52, 30, 0.7);
}

.lt-tips .lt-section-head {
    border-bottom-color: rgba(241, 231, 214, 0.25);
    position: relative;
    z-index: 1;
}

.lt-tips .lt-section-head h2 {
    color: var(--lt-parchment);
}

.lt-tips ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.lt-tips li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.88rem;
    color: rgba(241, 231, 214, 0.95);
    line-height: 1.55;
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-tips li:hover {
    transform: translateX(4px);
}

.lt-tips li .lt-tip-icon {
    flex-shrink: 0;
    font-size: 1rem;
    margin-top: 1px;
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    display: inline-block;
}

.lt-tips li:hover .lt-tip-icon {
    transform: scale(1.15);
}

/* ---------- Responsive ---------- */
@media (max-width: 960px) {
    .lt-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .lt-stat:nth-child(2) { border-right: none; }
    .lt-stat:nth-child(1),
    .lt-stat:nth-child(2) {
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-quick-actions {
        grid-template-columns: repeat(2, 1fr);
    }
    .lt-dash-layout {
        grid-template-columns: 1fr;
    }
    .lt-dash-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
    .lt-header-rating { text-align: left; }
}

@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-section { padding: 22px 20px; }
    .lt-quick-actions { grid-template-columns: 1fr; }
    .lt-class-row-actions { flex-direction: column; align-items: stretch; }
    .lt-class-row-actions .lt-btn { width: 100%; }
}

/* ---------- Reduced motion ---------- */
@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-status-ongoing .lt-status-dot,
    .lt-priority-high {
        animation: none;
    }
    .lt-dash-header::before { animation: none; }

    .lt-reveal,
    .lt-reveal-left,
    .lt-reveal-right,
    .lt-reveal-scale {
        opacity: 1;
        transform: none;
        transition: none;
    }

    .lt-chart-bar { transition: none; }

    .lt-quick-btn:hover,
    .lt-class-row:hover,
    .lt-list-row:hover,
    .lt-tips:hover,
    .lt-btn:hover {
        transform: none;
    }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Teacher dashboard
                </span>
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $teacher['fullname'])[0]); ?>.</h1>
                <p>Here's what's happening with your classes today.</p>
            </div>
            
            <div class="lt-header-rating">
                <div class="lt-header-stars">
                    <?php 
                    $avg = round($avgRating['avg_rating'] ?? 0, 1);
                    for ($i = 1; $i <= 5; $i++) {
                        echo $i <= $avg ? '★' : '☆';
                    }
                    ?>
                </div>
                <div class="lt-header-rating-meta">
                    <?php echo number_format($avg, 1); ?> · <?php echo $avgRating['total_reviews'] ?? 0; ?> review<?php echo ($avgRating['total_reviews'] ?? 0) != 1 ? 's' : ''; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <!-- Stats strip -->
        <div class="lt-stats-grid">
            <div class="lt-stat lt-reveal lt-delay-1">
                <div class="lt-stat-number" data-count="<?php echo $totalClasses; ?>">0</div>
                <div class="lt-stat-label">Total classes</div>
                <div class="lt-stat-meta">↑ <?php echo $activeClasses; ?> active now</div>
            </div>
            <div class="lt-stat lt-reveal lt-delay-2">
                <div class="lt-stat-number" data-count="<?php echo $totalStudents; ?>">0</div>
                <div class="lt-stat-label">Total students</div>
                <div class="lt-stat-meta">Across all classes</div>
            </div>
            <div class="lt-stat lt-reveal lt-delay-3">
                <div class="lt-stat-number" data-count="<?php echo (int)$totalRevenue; ?>" data-prefix="R ">0</div>
                <div class="lt-stat-label">Total revenue</div>
                <div class="lt-stat-meta lt-muted">Lifetime earnings</div>
            </div>
            <div class="lt-stat lt-reveal lt-delay-4">
                <div class="lt-stat-number" data-count="<?php echo $certificatesIssued; ?>">0</div>
                <div class="lt-stat-label">Certificates issued</div>
                <div class="lt-stat-meta lt-muted">To successful students</div>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="lt-quick-actions">
            <a href="add-class.php" class="lt-quick-btn lt-reveal lt-delay-1">
                <div class="lt-quick-icon">➕</div>
                <div class="lt-quick-text">
                    <span class="lt-quick-label">Create new class</span>
                    <span class="lt-quick-hint">Publish a session</span>
                </div>
            </a>
            <a href="classes.php" class="lt-quick-btn lt-reveal lt-delay-2">
                <div class="lt-quick-icon">📋</div>
                <div class="lt-quick-text">
                    <span class="lt-quick-label">Manage classes</span>
                    <span class="lt-quick-hint">All your sessions</span>
                </div>
            </a>
            <a href="students.php" class="lt-quick-btn lt-reveal lt-delay-3">
                <div class="lt-quick-icon">👥</div>
                <div class="lt-quick-text">
                    <span class="lt-quick-label">View students</span>
                    <span class="lt-quick-hint">Roster &amp; progress</span>
                </div>
            </a>
            <a href="live-stream.php" class="lt-quick-btn lt-reveal lt-delay-4">
                <div class="lt-quick-icon">🎥</div>
                <div class="lt-quick-text">
                    <span class="lt-quick-label">Live stream</span>
                    <span class="lt-quick-hint">Start a session</span>
                </div>
            </a>
        </div>

        <!-- Main grid -->
        <div class="lt-dash-layout">
            
            <!-- Left column -->
            <div class="lt-col">
                <!-- My Classes -->
                <div class="lt-section lt-reveal-left">
                    <div class="lt-section-head">
                        <span class="lt-num">01</span>
                        <h2>My classes</h2>
                        <span class="lt-count-pill"><?php echo $totalClasses; ?></span>
                    </div>
                    
                    <?php if (empty($classes)): ?>
                        <div class="lt-empty-note">
                            <span class="lt-empty-icon">📭</span>
                            <p>You haven't created any classes yet.</p>
                            <a href="add-class.php" class="lt-btn lt-btn-primary">Create your first class</a>
                        </div>
                    <?php else: ?>
                        <?php $i = 1; foreach (array_slice($classes, 0, 3) as $class): ?>
                            <div class="lt-class-row lt-reveal lt-delay-<?php echo min($i, 6); ?>">
                                <div class="lt-class-row-head">
                                    <div style="min-width: 0;">
                                        <h3 class="lt-class-row-title">
                                            <?php echo htmlspecialchars($class['title']); ?>
                                            <?php 
                                                $statusLabels = [
                                                    'upcoming' => 'Upcoming',
                                                    'ongoing' => 'Live',
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
                                        </h3>
                                    </div>
                                    <div class="lt-class-row-date">
                                        📅 <?php echo date('M d, Y', strtotime($class['start_date'])); ?>
                                    </div>
                                </div>
                                <div class="lt-class-row-stats">
                                    <span>👥 <strong><?php echo $class['student_count']; ?>/<?php echo $class['max_students']; ?></strong> students</span>
                                    <span>💰 <strong>R <?php echo number_format($class['revenue'] ?? 0, 0); ?></strong></span>
                                    <span>📊 <strong><?php echo $class['attendance_count']; ?></strong> attendances</span>
                                </div>
                                <div class="lt-class-row-actions">
                                    <a href="edit-class.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline">✏️ Edit</a>
                                    <a href="attendance.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline">📋 Attendance</a>
                                    <a href="students.php?class_id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline">👥 Students</a>
                                    <a href="live-stream.php?class_id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline">🎥 Live</a>
                                    <a href="course-content.php?class_id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline">📚 Content</a>
                                    <a href="../classes/class.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline" target="_blank">👁️ View</a>
                                </div>
                            </div>
                        <?php $i++; endforeach; ?>
                        
                        <?php if (count($classes) > 3): ?>
                            <div style="text-align: center; margin-top: 18px;">
                                <a href="classes.php" class="lt-btn lt-btn-outline">View all <?php echo count($classes); ?> classes →</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Revenue chart -->
                <?php if (!empty($monthlyRevenue)): ?>
                    <div class="lt-section lt-reveal-left lt-delay-1">
                        <div class="lt-section-head">
                            <span class="lt-num">02</span>
                            <h2>Revenue trend</h2>
                            <span class="lt-count-pill">6 mo</span>
                        </div>
                        <div class="lt-chart" data-chart-animate>
                            <?php 
                            $maxRevenue = max(array_column($monthlyRevenue, 'revenue'));
                            $maxRevenue = $maxRevenue > 0 ? $maxRevenue : 1;
                            ?>
                            <?php foreach ($monthlyRevenue as $month): ?>
                                <div class="lt-chart-row">
                                    <div class="lt-chart-label"><?php echo $month['month']; ?></div>
                                    <div class="lt-chart-track">
                                        <div class="lt-chart-bar" data-bar-width="<?php echo ($month['revenue'] / $maxRevenue) * 100; ?>" style="width: 0%;">
                                            R <?php echo number_format($month['revenue'], 0); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right column -->
            <div class="lt-col">
                <!-- Recent enrollments -->
                <div class="lt-section lt-reveal-right">
                    <div class="lt-section-head">
                        <span class="lt-num">03</span>
                        <h2>Recent enrollments</h2>
                    </div>
                    
                    <?php if (empty($recentEnrollments)): ?>
                        <div class="lt-empty-note">
                            <p>No recent enrollments yet.</p>
                        </div>
                    <?php else: ?>
                        <?php $i = 1; foreach ($recentEnrollments as $enrollment): ?>
                            <div class="lt-list-row lt-reveal lt-delay-<?php echo min($i, 6); ?>">
                                <div class="lt-list-row-main">
                                    <div class="lt-list-row-title"><?php echo htmlspecialchars($enrollment['student_name']); ?></div>
                                    <div class="lt-list-row-sub"><?php echo htmlspecialchars($enrollment['class_title']); ?></div>
                                </div>
                                <div class="lt-list-row-right">
                                    <div class="lt-list-row-value">R <?php echo number_format($enrollment['amount_paid'], 2); ?></div>
                                    <div class="lt-list-row-meta"><?php echo date('M d', strtotime($enrollment['enrolled_at'])); ?></div>
                                </div>
                            </div>
                        <?php $i++; endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Top performing classes -->
                <?php if (!empty($topClasses)): ?>
                    <div class="lt-section lt-reveal-right lt-delay-1">
                        <div class="lt-section-head">
                            <span class="lt-num">04</span>
                            <h2>Top classes</h2>
                        </div>
                        <?php $i = 1; foreach ($topClasses as $index => $class): ?>
                            <div class="lt-list-row lt-reveal lt-delay-<?php echo min($i, 6); ?>">
                                <div class="lt-list-row-main">
                                    <div class="lt-list-row-title">
                                        <?php if ($index == 0) echo '<span class="lt-medal">🥇</span>'; ?>
                                        <?php if ($index == 1) echo '<span class="lt-medal">🥈</span>'; ?>
                                        <?php if ($index == 2) echo '<span class="lt-medal">🥉</span>'; ?>
                                        <?php echo htmlspecialchars(substr($class['title'], 0, 30)); ?>
                                    </div>
                                    <div class="lt-list-row-sub"><?php echo $class['enrollments']; ?> enrollment<?php echo $class['enrollments'] != 1 ? 's' : ''; ?></div>
                                </div>
                                <div class="lt-list-row-right">
                                    <div class="lt-list-row-value">R <?php echo number_format($class['revenue'], 0); ?></div>
                                </div>
                            </div>
                        <?php $i++; endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Upcoming tasks -->
                <div class="lt-section lt-reveal-right lt-delay-2">
                    <div class="lt-section-head">
                        <span class="lt-num">05</span>
                        <h2>Upcoming tasks</h2>
                    </div>
                    
                    <?php if (empty($pendingTasks)): ?>
                        <div class="lt-empty-note">
                            <p>No pending tasks. All caught up! 🎉</p>
                        </div>
                    <?php else: ?>
                        <?php $i = 1; foreach ($pendingTasks as $task): 
                            $daysLeft = ceil((strtotime($task['start_date']) - time()) / (60 * 60 * 24));
                            $priority = $daysLeft <= 1 ? 'high' : ($daysLeft <= 3 ? 'medium' : 'low');
                        ?>
                            <div class="lt-task lt-reveal lt-delay-<?php echo min($i, 6); ?>">
                                <div class="lt-task-priority lt-priority-<?php echo $priority; ?>"></div>
                                <div class="lt-task-body">
                                    <div class="lt-task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                    <div class="lt-task-meta">
                                        <?php echo date('M d, Y', strtotime($task['start_date'])); ?> · 
                                        <?php echo $daysLeft; ?> day<?php echo $daysLeft != 1 ? 's' : ''; ?> left
                                    </div>
                                </div>
                                <a href="edit-class.php?id=<?php echo $task['id']; ?>" class="lt-btn lt-btn-outline">Prepare →</a>
                            </div>
                        <?php $i++; endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pro tips -->
                <div class="lt-section lt-tips lt-reveal-scale lt-delay-1">
                    <div class="lt-section-head">
                        <span class="lt-num" style="color: rgba(241,231,214,0.85);">06</span>
                        <h2>Pro tips</h2>
                    </div>
                    <ul>
                        <li><span class="lt-tip-icon">📹</span><span>Upload free preview videos to attract more students.</span></li>
                        <li><span class="lt-tip-icon">🎯</span><span>Keep your class status updated (Upcoming → Live → Completed).</span></li>
                        <li><span class="lt-tip-icon">🏆</span><span>Issue certificates to motivate students to complete.</span></li>
                        <li><span class="lt-tip-icon">📊</span><span>Check attendance regularly to track student engagement.</span></li>
                        <li><span class="lt-tip-icon">💬</span><span>Respond to student reviews to build trust.</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
/* ============================================================
   LiveTeach teacher dashboard animation controller
   - Scroll reveals via IntersectionObserver
   - Animated stat counters (with optional prefix like "R ")
   - Revenue bars grow from 0 to their data-bar-width when scrolled in
   - Respects prefers-reduced-motion
   ============================================================ */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Reduced motion: reveal everything and settle final values ---------- */
    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal, .lt-reveal-left, .lt-reveal-right, .lt-reveal-scale')
            .forEach(el => el.classList.add('lt-visible'));

        document.querySelectorAll('.lt-stat-number[data-count]').forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const prefix = el.getAttribute('data-prefix') || '';
            el.textContent = prefix + target.toLocaleString();
        });

        document.querySelectorAll('.lt-chart-bar[data-bar-width]').forEach(function (bar) {
            bar.style.width = bar.getAttribute('data-bar-width') + '%';
        });

        return;
    }

    /* ---------- Reveal on scroll ---------- */
    const revealTargets = document.querySelectorAll(
        '.lt-reveal, .lt-reveal-left, .lt-reveal-right, .lt-reveal-scale'
    );

    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        });

        revealTargets.forEach(el => revealObserver.observe(el));
    } else {
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }

    /* ---------- Animated stat counters ---------- */
    function animateCount(el, target, duration, prefix) {
        const startTime = performance.now();

        function tick(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
            const value = Math.floor(target * eased);
            el.textContent = prefix + value.toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = prefix + target.toLocaleString();
            }
        }

        requestAnimationFrame(tick);
    }

    const counters = document.querySelectorAll('.lt-stat-number[data-count]');

    if ('IntersectionObserver' in window && counters.length) {
        const counterObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const target = parseInt(el.getAttribute('data-count'), 10);
                    const prefix = el.getAttribute('data-prefix') || '';
                    if (!isNaN(target) && target > 0) {
                        animateCount(el, target, 1400, prefix);
                    } else {
                        el.textContent = prefix + '0';
                    }
                    observer.unobserve(el);
                }
            });
        }, { threshold: 0.4 });

        counters.forEach(el => counterObserver.observe(el));
    } else {
        counters.forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const prefix = el.getAttribute('data-prefix') || '';
            el.textContent = prefix + target.toLocaleString();
        });
    }

    /* ---------- Revenue bars grow on scroll ---------- */
    const chartWrappers = document.querySelectorAll('[data-chart-animate]');

    if ('IntersectionObserver' in window && chartWrappers.length) {
        const chartObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const bars = entry.target.querySelectorAll('.lt-chart-bar[data-bar-width]');
                    bars.forEach(function (bar, i) {
                        const target = parseFloat(bar.getAttribute('data-bar-width')) || 0;
                        // Stagger each bar slightly
                        setTimeout(function () {
                            bar.style.width = target + '%';
                        }, i * 80);
                    });
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        chartWrappers.forEach(el => chartObserver.observe(el));
    } else {
        document.querySelectorAll('.lt-chart-bar[data-bar-width]').forEach(function (bar) {
            bar.style.width = bar.getAttribute('data-bar-width') + '%';
        });
    }
})();
</script>

<?php require_once '../includes/footer.php'; ?>