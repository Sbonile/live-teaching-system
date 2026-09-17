<?php
session_start();
require_once '../config/database.php';
require_once '../includes/header.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$msg = '';

// Handle feature toggle
if (isset($_POST['toggle_featured'])) {
    $cid = (int)$_POST['class_id'];
    $newFeat = (int)$_POST['new_featured'];
    $connection->query("UPDATE live_classes SET is_featured = $newFeat WHERE id = $cid");
    $msg = "Featured status updated.";
}

// Handle status change
if (isset($_POST['change_status'])) {
    $cid = (int)$_POST['class_id'];
    $newStatus = $connection->real_escape_string($_POST['new_status']);
    if (in_array($newStatus, ['upcoming','ongoing','completed','cancelled'])) {
        $connection->query("UPDATE live_classes SET status = '$newStatus' WHERE id = $cid");
        $msg = "Class status updated.";
    }
}

// Handle delete
if (isset($_POST['delete_class'])) {
    $cid = (int)$_POST['class_id'];
    $enrolled = $connection->query("SELECT COUNT(*) as c FROM enrollments WHERE class_id = $cid AND payment_status='paid'")->fetch_assoc()['c'];
    if ($enrolled == 0) {
        $connection->query("DELETE FROM live_classes WHERE id = $cid");
        $msg = "Class deleted.";
    } else {
        $msg = "Cannot delete: students are enrolled.";
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$where = '1=1';
if ($search) { $s = $connection->real_escape_string($search); $where .= " AND (c.title LIKE '%$s%' OR u.fullname LIKE '%$s%')"; }
if ($statusFilter) $where .= " AND c.status = '$statusFilter'";

$classes = $connection->query("
    SELECT c.*, u.fullname as teacher_name,
        (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.payment_status='paid') as student_count,
        (SELECT SUM(e.amount_paid) FROM enrollments e WHERE e.class_id = c.id AND e.payment_status='paid') as revenue
    FROM live_classes c
    JOIN users u ON c.teacher_id = u.id
    WHERE $where
    ORDER BY c.created_at DESC
")->fetch_all(MYSQLI_ASSOC);
?>

<style>
/* ===== LiveTeach admin classes revamp — scoped to .lt-page ===== */
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
    max-width: 1280px;
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

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover {
    background: var(--lt-flame-bright);
    box-shadow: 0 10px 30px -10px rgba(228, 78, 46, 0.55);
}

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

.lt-btn-gold {
    background: rgba(217, 164, 65, 0.12);
    border-color: rgba(217, 164, 65, 0.5);
    color: var(--lt-gold);
}
.lt-btn-gold:hover {
    background: rgba(217, 164, 65, 0.2);
    border-color: var(--lt-gold);
    box-shadow: 0 10px 30px -10px rgba(217, 164, 65, 0.5);
}

.lt-btn-danger {
    background: transparent;
    border-color: rgba(200, 52, 30, 0.5);
    color: var(--lt-flame-bright);
}
.lt-btn-danger:hover {
    background: var(--lt-flame);
    border-color: var(--lt-flame);
    color: var(--lt-parchment);
    box-shadow: 0 10px 30px -10px rgba(200, 52, 30, 0.55);
}

.lt-btn-sm { padding: 7px 12px; font-size: 0.76rem; }

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin: 32px 0 0;
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

/* ---------- Summary strip ---------- */
.lt-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-top: 32px;
}

.lt-summary-cell {
    padding: 26px 22px;
    border-right: 1px solid var(--lt-line);
    position: relative;
    transition: background 0.3s ease;
}

.lt-summary-cell:last-child { border-right: none; }

.lt-summary-cell::before {
    content: "";
    position: absolute;
    top: 0;
    left: 22px;
    width: 26px;
    height: 2px;
    background: var(--lt-flame);
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-summary-cell:hover::before { width: calc(100% - 44px); }
.lt-summary-cell:hover { background: var(--lt-charcoal); }

.lt-summary-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.9rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
    transition: color 0.3s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: inline-block;
}

.lt-summary-cell:hover .lt-summary-number {
    color: var(--lt-gold);
    transform: scale(1.05);
}

.lt-summary-number.lt-accent { color: var(--lt-gold); }

.lt-summary-label {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    transition: color 0.3s ease;
}

.lt-summary-cell:hover .lt-summary-label { color: var(--lt-parchment); }

/* ---------- Filter bar ---------- */
.lt-filter-bar {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 16px 20px;
    margin: 32px 0 22px;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.lt-filter-field {
    flex: 1;
    min-width: 200px;
}

.lt-filter-field select {
    width: 100%;
    padding: 11px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.88rem;
    font-family: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
    box-sizing: border-box;
}

.lt-filter-field select:focus {
    outline: none;
    border-color: var(--lt-flame);
}

.lt-search-input {
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

.lt-search-input::placeholder { color: rgba(201, 190, 172, 0.45); }

.lt-search-input:focus {
    outline: none;
    border-color: var(--lt-flame);
}

/* ---------- Count line ---------- */
.lt-count-line {
    font-size: 0.85rem;
    color: var(--lt-parchment-dim);
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.lt-count-line strong {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.1rem;
    color: var(--lt-gold);
    font-weight: 500;
}

/* ---------- Table card ---------- */
.lt-table-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 72px;
}

.lt-table-scroll {
    overflow-x: auto;
}

.lt-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1080px;
}

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
.lt-table tbody tr { transition: background 0.2s ease; }
.lt-table tbody tr:hover { background: var(--lt-ink); }

/* Title cell */
.lt-class-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1rem;
    color: var(--lt-parchment);
    margin: 0 0 4px;
    line-height: 1.3;
    transition: color 0.25s ease;
    display: inline-block;
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.lt-class-title:hover { color: var(--lt-gold); }

.lt-class-date {
    font-size: 0.75rem;
    color: var(--lt-parchment-dim);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    letter-spacing: 0.03em;
}

.lt-cell-muted {
    color: var(--lt-parchment-dim);
    font-size: 0.85rem;
}

.lt-cell-revenue {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1rem;
    color: var(--lt-gold);
}

/* Status select inside table */
.lt-status-select {
    padding: 6px 26px 6px 10px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 6px;
    color: var(--lt-parchment);
    font-size: 0.8rem;
    font-family: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}

.lt-status-select:focus {
    outline: none;
    border-color: var(--lt-flame);
}

/* Rows actions */
.lt-row-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: nowrap;
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

.lt-delay-1 { transition-delay: 0.06s; }
.lt-delay-2 { transition-delay: 0.12s; }
.lt-delay-3 { transition-delay: 0.18s; }
.lt-delay-4 { transition-delay: 0.24s; }

/* ---------- Responsive ---------- */
@media (max-width: 1024px) {
    .lt-summary {
        grid-template-columns: repeat(2, 1fr);
    }
    .lt-summary-cell:nth-child(2n) { border-right: none; }
    .lt-summary-cell:nth-child(1),
    .lt-summary-cell:nth-child(2) {
        border-bottom: 1px solid var(--lt-line);
    }
}

@media (max-width: 720px) {
    .lt-summary {
        grid-template-columns: 1fr;
    }
    .lt-summary-cell {
        border-right: none;
        border-bottom: 1px solid var(--lt-line);
    }
    .lt-summary-cell:last-child { border-bottom: none; }
    .lt-dash-header-inner {
        flex-direction: column;
        align-items: flex-start;
    }
    .lt-filter-bar { flex-direction: column; align-items: stretch; }
    .lt-filter-field { width: 100%; }
    .lt-filter-bar .lt-btn { width: 100%; }
}

@media (max-width: 480px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-table thead th,
    .lt-table tbody td { padding: 12px 14px; }
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

    .lt-reveal { opacity: 1; transform: none; transition: none; }
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
                    Class catalogue
                </span>
                <h1>Manage classes.</h1>
                <p>Review, feature, and moderate every live class on the platform.</p>
            </div>
            <div class="lt-header-actions">
                <a href="dashboard.php" class="lt-btn lt-btn-outline">← Dashboard</a>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <!-- Alerts -->
        <?php if ($msg): ?>
            <div class="lt-alert lt-alert-success">
                <span>✓</span><span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Summary strip -->
        <div class="lt-summary">
            <div class="lt-summary-cell lt-reveal lt-delay-1">
                <div class="lt-summary-number"><?php echo count($classes); ?></div>
                <div class="lt-summary-label">Results shown</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-2">
                <div class="lt-summary-number">
                    <?php echo count(array_filter($classes, fn($c) => $c['status'] === 'ongoing')); ?>
                </div>
                <div class="lt-summary-label">Live now</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-3">
                <div class="lt-summary-number">
                    <?php echo count(array_filter($classes, fn($c) => $c['is_featured'])); ?>
                </div>
                <div class="lt-summary-label">Featured</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-4">
                <div class="lt-summary-number lt-accent">
                    R <?php echo number_format(array_sum(array_column($classes, 'revenue')), 0); ?>
                </div>
                <div class="lt-summary-label">View revenue</div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="get" class="lt-filter-bar lt-reveal">
            <div class="lt-filter-field">
                <input type="text" name="search" class="lt-search-input" placeholder="🔍 Search class or teacher..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="lt-filter-field" style="flex: 0 0 200px;">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach(['upcoming','ongoing','completed','cancelled'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $statusFilter==$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="lt-btn lt-btn-primary lt-btn-sm">Search</button>
            <?php if ($search || $statusFilter): ?>
                <a href="classes.php" class="lt-btn lt-btn-outline lt-btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Count line -->
        <p class="lt-count-line lt-reveal">
            <strong><?php echo count($classes); ?></strong>
            class<?php echo count($classes) != 1 ? 'es' : ''; ?> found
        </p>

        <!-- Table -->
        <div class="lt-table-card lt-reveal">
            <div class="lt-table-scroll">
                <table class="lt-table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Teacher</th>
                            <th>Enrollment</th>
                            <th>Revenue</th>
                            <th>Status</th>
                            <th>Featured</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td>
                                    <a href="../classes/class.php?id=<?php echo $c['id']; ?>" class="lt-class-title">
                                        <?php echo htmlspecialchars($c['title']); ?>
                                    </a>
                                    <div class="lt-class-date"><?php echo date('M d, Y', strtotime($c['start_date'])); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($c['teacher_name']); ?></td>
                                <td class="lt-cell-muted">
                                    <?php echo $c['student_count']; ?> / <?php echo $c['max_students']; ?>
                                </td>
                                <td class="lt-cell-revenue">
                                    R <?php echo number_format($c['revenue'] ?? 0, 2); ?>
                                </td>
                                <td>
                                    <form method="post" style="display:inline; margin: 0;">
                                        <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                        <select name="new_status" class="lt-status-select" onchange="this.form.submit()">
                                            <?php foreach(['upcoming','ongoing','completed','cancelled'] as $st): ?>
                                                <option value="<?php echo $st; ?>" <?php echo $c['status']==$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="change_status" value="1">
                                    </form>
                                </td>
                                <td>
                                    <form method="post" style="display:inline; margin: 0;">
                                        <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                        <input type="hidden" name="new_featured" value="<?php echo $c['is_featured']?0:1; ?>">
                                        <button type="submit" name="toggle_featured" class="lt-btn lt-btn-sm <?php echo $c['is_featured'] ? 'lt-btn-gold' : 'lt-btn-outline'; ?>">
                                            <?php echo $c['is_featured'] ? '⭐ Featured' : '☆ Feature'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <div class="lt-row-actions">
                                        <a href="../teacher/edit-class.php?id=<?php echo $c['id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">Edit</a>
                                        <?php if ($c['student_count'] == 0): ?>
                                            <form method="post" style="display:inline; margin: 0;" onsubmit="return confirm('Delete this class?');">
                                                <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" name="delete_class" class="lt-btn lt-btn-danger lt-btn-sm">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
/* Reveal animation controller */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal').forEach(el => el.classList.add('lt-visible'));
        return;
    }

    const revealTargets = document.querySelectorAll('.lt-reveal');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
        revealTargets.forEach(el => observer.observe(el));
    } else {
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }
})();
</script>

<?php require_once '../includes/footer.php'; ?>