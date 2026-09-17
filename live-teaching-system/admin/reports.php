<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();

// Revenue by month (last 6 months)
$revenueByMonth = $connection->query("
    SELECT DATE_FORMAT(enrolled_at, '%Y-%m') as month,
           DATE_FORMAT(enrolled_at, '%b %Y') as label,
           SUM(amount_paid) as revenue,
           COUNT(*) as enrollments
    FROM enrollments WHERE payment_status = 'paid'
    GROUP BY DATE_FORMAT(enrolled_at, '%Y-%m')
    ORDER BY month DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);
$revenueByMonth = array_reverse($revenueByMonth);

// Top classes by enrollment
$topClasses = $connection->query("
    SELECT c.title, c.price,
        COUNT(e.id) as enrollments,
        SUM(e.amount_paid) as revenue,
        u.fullname as teacher_name
    FROM live_classes c
    JOIN enrollments e ON e.class_id = c.id AND e.payment_status = 'paid'
    JOIN users u ON c.teacher_id = u.id
    GROUP BY c.id ORDER BY enrollments DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Top teachers
$topTeachers = $connection->query("
    SELECT u.fullname,
        COUNT(DISTINCT c.id) as classes,
        COUNT(DISTINCT e.student_id) as students,
        SUM(e.amount_paid) as revenue
    FROM users u
    JOIN live_classes c ON c.teacher_id = u.id
    JOIN enrollments e ON e.class_id = c.id AND e.payment_status = 'paid'
    WHERE u.role = 'teacher'
    GROUP BY u.id ORDER BY revenue DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Enrollment stats
$stats = $connection->query("
    SELECT
        COUNT(*) as total_enrollments,
        SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN payment_status='pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN payment_status='refunded' THEN 1 ELSE 0 END) as refunded,
        SUM(CASE WHEN payment_status='paid' THEN amount_paid ELSE 0 END) as total_revenue
    FROM enrollments
")->fetch_assoc();

// Category breakdown
$byCategory = $connection->query("
    SELECT c.category, COUNT(e.id) as enrollments, SUM(e.amount_paid) as revenue
    FROM live_classes c
    JOIN enrollments e ON e.class_id = c.id AND e.payment_status = 'paid'
    WHERE c.category IS NOT NULL AND c.category != ''
    GROUP BY c.category ORDER BY enrollments DESC
")->fetch_all(MYSQLI_ASSOC);
?>

<style>
/* ===== LiveTeach admin reports revamp — scoped to .lt-page ===== */
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
    opacity: 0;
    transform: translateY(-12px);
    animation: lt-fade-in-down 0.7s cubic-bezier(0.22, 1, 0.36, 1) 0.1s forwards;
}

@keyframes lt-fade-in-down {
    to { opacity: 1; transform: translateY(0); }
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
    opacity: 0;
    transform: translateY(20px);
    animation: lt-hero-title 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.25s forwards;
}

@keyframes lt-hero-title {
    to { opacity: 1; transform: translateY(0); }
}

.lt-dash-header p {
    color: var(--lt-parchment-dim);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.6;
    opacity: 0;
    transform: translateY(16px);
    animation: lt-hero-copy 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.45s forwards;
}

@keyframes lt-hero-copy {
    to { opacity: 1; transform: translateY(0); }
}

.lt-header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    opacity: 0;
    transform: translateY(16px);
    animation: lt-hero-btn 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.65s forwards;
}

@keyframes lt-hero-btn {
    to { opacity: 1; transform: translateY(0); }
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

.lt-btn-outline {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}
.lt-btn-outline:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
    box-shadow: 0 10px 30px -12px rgba(217, 164, 65, 0.4);
}

/* ---------- KPI strip ---------- */
.lt-kpi {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-top: 32px;
}

.lt-kpi-cell {
    padding: 26px 22px;
    border-right: 1px solid var(--lt-line);
    position: relative;
    transition: background 0.3s ease;
}

.lt-kpi-cell:last-child { border-right: none; }

.lt-kpi-cell::before {
    content: "";
    position: absolute;
    top: 0;
    left: 22px;
    width: 26px;
    height: 2px;
    background: var(--lt-flame);
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-kpi-cell:hover::before { width: calc(100% - 44px); }
.lt-kpi-cell:hover { background: var(--lt-charcoal); }

.lt-kpi-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.85rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
    transition: color 0.3s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: inline-block;
}

.lt-kpi-cell:hover .lt-kpi-number {
    transform: scale(1.05);
    color: var(--lt-gold);
}

.lt-kpi-number.lt-revenue { color: var(--lt-gold); }
.lt-kpi-number.lt-paid { color: var(--lt-gold); }
.lt-kpi-number.lt-pending { color: #E4A54C; }
.lt-kpi-number.lt-refunded { color: var(--lt-flame-bright); }

.lt-kpi-label {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    transition: color 0.3s ease;
}

.lt-kpi-cell:hover .lt-kpi-label { color: var(--lt-parchment); }

/* ---------- Section head ---------- */
.lt-section {
    margin-top: 44px;
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

/* ---------- Card ---------- */
.lt-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden;
}

/* ---------- Bar chart (Monthly Revenue) ---------- */
.lt-chart {
    padding: 24px 26px;
}

.lt-chart-row {
    display: grid;
    grid-template-columns: 90px 1fr 120px;
    align-items: center;
    gap: 16px;
    margin-bottom: 14px;
}

.lt-chart-row:last-child { margin-bottom: 0; }

.lt-chart-label {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.03em;
}

.lt-chart-track {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    height: 26px;
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
    color: var(--lt-parchment);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.76rem;
    font-weight: 600;
    transition: width 0.9s cubic-bezier(0.22, 1, 0.36, 1);
    min-width: 52px;
    width: 0%;
}

.lt-chart-value {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-gold);
    text-align: right;
    white-space: nowrap;
}

.lt-chart-value small {
    display: block;
    font-family: 'Inter', sans-serif;
    font-size: 0.7rem;
    color: var(--lt-parchment-dim);
    font-weight: 400;
    margin-top: 2px;
}

/* ---------- Tables ---------- */
.lt-table-scroll {
    overflow-x: auto;
}

.lt-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 520px;
}

.lt-table thead th {
    background: var(--lt-charcoal-raised);
    padding: 12px 18px;
    text-align: left;
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--lt-gold);
    font-weight: 700;
    border-bottom: 1px solid var(--lt-line);
    white-space: nowrap;
}

.lt-table tbody td {
    padding: 12px 18px;
    border-bottom: 1px solid var(--lt-line);
    font-size: 0.88rem;
    color: var(--lt-parchment);
    vertical-align: middle;
}

.lt-table tbody tr:last-child td { border-bottom: none; }
.lt-table tbody tr { transition: background 0.2s ease; }
.lt-table tbody tr:hover { background: var(--lt-ink); }

.lt-rank {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.04em;
    display: inline-block;
    min-width: 22px;
}

.lt-rank.lt-top { color: var(--lt-gold); }

.lt-entity-name {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.98rem;
    color: var(--lt-parchment);
    margin: 0 0 3px;
    line-height: 1.3;
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

.lt-entity-sub {
    font-size: 0.76rem;
    color: var(--lt-parchment-dim);
}

.lt-cell-num {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-parchment);
    text-align: right;
}

.lt-cell-revenue {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.98rem;
    color: var(--lt-gold);
    text-align: right;
    white-space: nowrap;
}

/* Category pill */
.lt-cat-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border: 1px solid var(--lt-line);
    background: var(--lt-ink);
    color: var(--lt-parchment);
}

/* Two-column grid */
.lt-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 0;
}

/* Empty note */
.lt-empty-note {
    padding: 34px 24px;
    text-align: center;
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    line-height: 1.6;
}

/* ---------- Reveal animations ---------- */
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

.lt-delay-1 { transition-delay: 0.06s; }
.lt-delay-2 { transition-delay: 0.12s; }
.lt-delay-3 { transition-delay: 0.18s; }
.lt-delay-4 { transition-delay: 0.24s; }
.lt-delay-5 { transition-delay: 0.30s; }

/* ---------- Responsive ---------- */
@media (max-width: 1024px) {
    .lt-kpi { grid-template-columns: repeat(3, 1fr); }
    .lt-kpi-cell:nth-child(3n) { border-right: none; }
    .lt-kpi-cell:nth-child(1),
    .lt-kpi-cell:nth-child(2),
    .lt-kpi-cell:nth-child(3) {
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-grid-2 { grid-template-columns: 1fr; }
}

@media (max-width: 720px) {
    .lt-kpi { grid-template-columns: repeat(2, 1fr); }
    .lt-kpi-cell { border-right: 1px solid var(--lt-line); }
    .lt-kpi-cell:nth-child(2n) { border-right: none; }
    .lt-kpi-cell:nth-child(1),
    .lt-kpi-cell:nth-child(2),
    .lt-kpi-cell:nth-child(3),
    .lt-kpi-cell:nth-child(4) {
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-kpi-cell:nth-child(5) { border-bottom: none; }
    .lt-dash-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
    .lt-chart-row {
        grid-template-columns: 70px 1fr;
        gap: 10px;
    }
    .lt-chart-value {
        grid-column: 2;
        text-align: left;
        margin-top: -8px;
        margin-bottom: 6px;
    }
}

@media (max-width: 480px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-kpi { grid-template-columns: 1fr; }
    .lt-kpi-cell {
        border-right: none;
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-kpi-cell:last-child { border-bottom: none; }
    .lt-chart { padding: 20px 18px; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-dash-header::before { animation: none; }

    .lt-eyebrow,
    .lt-dash-header h1,
    .lt-dash-header p,
    .lt-header-actions {
        opacity: 1;
        transform: none;
        animation: none;
    }

    .lt-reveal,
    .lt-reveal-left,
    .lt-reveal-right { opacity: 1; transform: none; transition: none; }

    .lt-chart-bar { transition: none; }
    .lt-btn:hover { transform: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Analytics
                </span>
                <h1>Reports &amp; analytics.</h1>
                <p>Revenue, enrollment, and performance across the entire platform.</p>
            </div>
            <div class="lt-header-actions">
                <a href="dashboard.php" class="lt-btn lt-btn-outline">← Dashboard</a>
            </div>
        </div>
    </div>

    <div class="lt-container" style="padding-bottom: 72px;">
        <!-- KPI strip -->
        <div class="lt-kpi">
            <div class="lt-kpi-cell lt-reveal lt-delay-1">
                <div class="lt-kpi-number lt-revenue" data-count="<?php echo (int)($stats['total_revenue'] ?? 0); ?>" data-prefix="R ">0</div>
                <div class="lt-kpi-label">Total revenue</div>
            </div>
            <div class="lt-kpi-cell lt-reveal lt-delay-2">
                <div class="lt-kpi-number" data-count="<?php echo (int)$stats['total_enrollments']; ?>">0</div>
                <div class="lt-kpi-label">Total enrollments</div>
            </div>
            <div class="lt-kpi-cell lt-reveal lt-delay-3">
                <div class="lt-kpi-number lt-paid" data-count="<?php echo (int)$stats['paid']; ?>">0</div>
                <div class="lt-kpi-label">Paid</div>
            </div>
            <div class="lt-kpi-cell lt-reveal lt-delay-4">
                <div class="lt-kpi-number lt-pending" data-count="<?php echo (int)$stats['pending']; ?>">0</div>
                <div class="lt-kpi-label">Pending</div>
            </div>
            <div class="lt-kpi-cell lt-reveal lt-delay-5">
                <div class="lt-kpi-number lt-refunded" data-count="<?php echo (int)$stats['refunded']; ?>">0</div>
                <div class="lt-kpi-label">Refunded</div>
            </div>
        </div>

        <!-- Monthly revenue -->
        <?php if (!empty($revenueByMonth)): 
            $maxRevenue = max(array_column($revenueByMonth, 'revenue'));
            $maxRevenue = $maxRevenue > 0 ? $maxRevenue : 1;
        ?>
            <div class="lt-section lt-reveal">
                <div class="lt-section-head">
                    <span class="lt-num">01</span>
                    <h2>Monthly revenue</h2>
                    <span class="lt-count-pill">6 mo</span>
                </div>
                <div class="lt-card">
                    <div class="lt-chart" data-chart-animate>
                        <?php foreach ($revenueByMonth as $row): ?>
                            <div class="lt-chart-row">
                                <div class="lt-chart-label"><?php echo htmlspecialchars($row['label']); ?></div>
                                <div class="lt-chart-track">
                                    <div class="lt-chart-bar" data-bar-width="<?php echo ($row['revenue'] / $maxRevenue) * 100; ?>">
                                        R <?php echo number_format($row['revenue'], 0); ?>
                                    </div>
                                </div>
                                <div class="lt-chart-value">
                                    R <?php echo number_format($row['revenue'], 2); ?>
                                    <small><?php echo $row['enrollments']; ?> enrollment<?php echo $row['enrollments'] != 1 ? 's' : ''; ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Top classes + top teachers -->
        <div class="lt-grid-2">
            <div class="lt-section lt-reveal-left">
                <div class="lt-section-head">
                    <span class="lt-num">02</span>
                    <h2>Top classes</h2>
                    <span class="lt-count-pill">by enrollment</span>
                </div>
                <div class="lt-card">
                    <?php if (empty($topClasses)): ?>
                        <div class="lt-empty-note">No data yet.</div>
                    <?php else: ?>
                        <div class="lt-table-scroll">
                            <table class="lt-table">
                                <thead>
                                    <tr><th>Class</th><th style="text-align:right;">Students</th><th style="text-align:right;">Revenue</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topClasses as $i => $c): ?>
                                        <tr>
                                            <td>
                                                <span class="lt-rank <?php echo $i < 3 ? 'lt-top' : ''; ?>">#<?php echo $i + 1; ?></span>
                                                <span class="lt-entity-name" title="<?php echo htmlspecialchars($c['title']); ?>">
                                                    <?php echo htmlspecialchars(substr($c['title'], 0, 34)); ?><?php echo strlen($c['title']) > 34 ? '…' : ''; ?>
                                                </span>
                                                <span class="lt-entity-sub"><?php echo htmlspecialchars($c['teacher_name']); ?></span>
                                            </td>
                                            <td class="lt-cell-num"><?php echo $c['enrollments']; ?></td>
                                            <td class="lt-cell-revenue">R <?php echo number_format($c['revenue'], 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lt-section lt-reveal-right">
                <div class="lt-section-head">
                    <span class="lt-num">03</span>
                    <h2>Top teachers</h2>
                    <span class="lt-count-pill">by revenue</span>
                </div>
                <div class="lt-card">
                    <?php if (empty($topTeachers)): ?>
                        <div class="lt-empty-note">No data yet.</div>
                    <?php else: ?>
                        <div class="lt-table-scroll">
                            <table class="lt-table">
                                <thead>
                                    <tr><th>Teacher</th><th style="text-align:right;">Classes</th><th style="text-align:right;">Students</th><th style="text-align:right;">Revenue</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topTeachers as $i => $t): ?>
                                        <tr>
                                            <td>
                                                <span class="lt-rank <?php echo $i < 3 ? 'lt-top' : ''; ?>">#<?php echo $i + 1; ?></span>
                                                <span class="lt-entity-name"><?php echo htmlspecialchars($t['fullname']); ?></span>
                                            </td>
                                            <td class="lt-cell-num"><?php echo $t['classes']; ?></td>
                                            <td class="lt-cell-num"><?php echo $t['students']; ?></td>
                                            <td class="lt-cell-revenue">R <?php echo number_format($t['revenue'], 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Category breakdown -->
        <?php if (!empty($byCategory)): ?>
            <div class="lt-section lt-reveal">
                <div class="lt-section-head">
                    <span class="lt-num">04</span>
                    <h2>Enrollments by category</h2>
                    <span class="lt-count-pill"><?php echo count($byCategory); ?> categor<?php echo count($byCategory) != 1 ? 'ies' : 'y'; ?></span>
                </div>
                <div class="lt-card">
                    <div class="lt-table-scroll">
                        <table class="lt-table">
                            <thead>
                                <tr><th>Category</th><th style="text-align:right;">Enrollments</th><th style="text-align:right;">Revenue</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byCategory as $cat): ?>
                                    <tr>
                                        <td>
                                            <span class="lt-cat-pill">📂 <?php echo htmlspecialchars($cat['category']); ?></span>
                                        </td>
                                        <td class="lt-cell-num"><?php echo $cat['enrollments']; ?></td>
                                        <td class="lt-cell-revenue">R <?php echo number_format($cat['revenue'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
/* ============================================================
   Reports & analytics animation controller
   - Scroll reveals
   - Animated KPI counters (with optional "R " prefix)
   - Revenue bars grow from 0 to their target width
   - Respects prefers-reduced-motion
   ============================================================ */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal, .lt-reveal-left, .lt-reveal-right')
            .forEach(el => el.classList.add('lt-visible'));

        document.querySelectorAll('.lt-kpi-number[data-count]').forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            const prefix = el.getAttribute('data-prefix') || '';
            el.textContent = prefix + target.toLocaleString();
        });

        document.querySelectorAll('.lt-chart-bar[data-bar-width]').forEach(function (bar) {
            bar.style.width = bar.getAttribute('data-bar-width') + '%';
        });

        return;
    }

    /* Reveal on scroll */
    const revealTargets = document.querySelectorAll('.lt-reveal, .lt-reveal-left, .lt-reveal-right');
    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -50px 0px' });
        revealTargets.forEach(el => revealObserver.observe(el));
    } else {
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }

    /* Animated KPI counters */
    function animateCount(el, target, duration, prefix) {
        const startTime = performance.now();
        function tick(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = prefix + Math.floor(target * eased).toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = prefix + target.toLocaleString();
            }
        }
        requestAnimationFrame(tick);
    }

    const counters = document.querySelectorAll('.lt-kpi-number[data-count]');
    if ('IntersectionObserver' in window && counters.length) {
        const counterObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const target = parseInt(el.getAttribute('data-count'), 10);
                    const prefix = el.getAttribute('data-prefix') || '';
                    if (!isNaN(target) && target > 0) {
                        animateCount(el, target, 1300, prefix);
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

    /* Revenue bars grow on scroll */
    const chartWrappers = document.querySelectorAll('[data-chart-animate]');
    if ('IntersectionObserver' in window && chartWrappers.length) {
        const chartObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const bars = entry.target.querySelectorAll('.lt-chart-bar[data-bar-width]');
                    bars.forEach(function (bar, i) {
                        const target = parseFloat(bar.getAttribute('data-bar-width')) || 0;
                        setTimeout(function () {
                            bar.style.width = target + '%';
                        }, i * 90);
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