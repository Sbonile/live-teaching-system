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

// Handle refund
if (isset($_POST['refund'])) {
    $eid = (int)$_POST['enroll_id'];
    $connection->query("UPDATE enrollments SET payment_status = 'refunded' WHERE id = $eid");
    $connection->query("UPDATE live_classes lc SET current_students = GREATEST(0, current_students-1) WHERE id = (SELECT class_id FROM enrollments WHERE id = $eid)");
    $msg = "Payment refunded.";
}

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$where = '1=1';
if ($search) { $s = $connection->real_escape_string($search); $where .= " AND (u.fullname LIKE '%$s%' OR u.email LIKE '%$s%' OR e.payment_reference LIKE '%$s%')"; }
if ($statusFilter) $where .= " AND e.payment_status = '$statusFilter'";

$enrollments = $connection->query("
    SELECT e.*, u.fullname, u.email, c.title as class_title, c.price as class_price
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    JOIN live_classes c ON e.class_id = c.id
    WHERE $where
    ORDER BY e.enrolled_at DESC
    LIMIT 100
")->fetch_all(MYSQLI_ASSOC);

$totals = $connection->query("SELECT
    SUM(CASE WHEN payment_status='paid' THEN amount_paid ELSE 0 END) as total_paid,
    SUM(CASE WHEN payment_status='refunded' THEN amount_paid ELSE 0 END) as total_refunded,
    COUNT(CASE WHEN payment_status='pending' THEN 1 END) as pending_count
FROM enrollments")->fetch_assoc();
?>

<style>
/* ===== LiveTeach admin payments revamp — scoped to .lt-page ===== */
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

.lt-btn-warn {
    background: transparent;
    border-color: rgba(217, 164, 65, 0.5);
    color: var(--lt-gold);
}
.lt-btn-warn:hover {
    background: rgba(217, 164, 65, 0.15);
    border-color: var(--lt-gold);
    box-shadow: 0 10px 30px -10px rgba(217, 164, 65, 0.5);
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
    grid-template-columns: repeat(3, 1fr);
    border-top: 1px solid var(--lt-line);
    border-bottom: 1px solid var(--lt-line);
    margin-top: 32px;
}

.lt-summary-cell {
    padding: 28px 24px;
    border-right: 1px solid var(--lt-line);
    position: relative;
    transition: background 0.3s ease;
}

.lt-summary-cell:last-child { border-right: none; }

.lt-summary-cell::before {
    content: "";
    position: absolute;
    top: 0;
    left: 24px;
    width: 26px;
    height: 2px;
    background: var(--lt-flame);
    transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-summary-cell:hover::before { width: calc(100% - 48px); }
.lt-summary-cell:hover { background: var(--lt-charcoal); }

.lt-summary-number {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 2rem;
    color: var(--lt-parchment);
    line-height: 1;
    margin-bottom: 8px;
    transition: color 0.3s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
    display: inline-block;
}

.lt-summary-cell:hover .lt-summary-number {
    transform: scale(1.05);
}

.lt-summary-number.lt-success { color: var(--lt-gold); }
.lt-summary-number.lt-pending { color: #E4A54C; }
.lt-summary-number.lt-refunded { color: var(--lt-flame-bright); }

.lt-summary-label {
    font-size: 0.82rem;
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

.lt-table-scroll { overflow-x: auto; }

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

.lt-student-name {
    font-weight: 500;
    color: var(--lt-parchment);
    margin: 0 0 3px;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.lt-student-email {
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
}

.lt-class-name {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.95rem;
    color: var(--lt-parchment);
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
}

.lt-amount {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1rem;
    color: var(--lt-gold);
    white-space: nowrap;
}

.lt-cell-muted {
    color: var(--lt-parchment-dim);
    font-size: 0.85rem;
}

.lt-reference {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.76rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.03em;
    white-space: nowrap;
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

.lt-pill-paid {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.4);
}

.lt-pill-pending {
    background: rgba(228, 165, 76, 0.15);
    color: #E4A54C;
    border-color: rgba(228, 165, 76, 0.4);
}

.lt-pill-refunded {
    background: rgba(200, 52, 30, 0.15);
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.4);
}

.lt-pill-failed {
    background: rgba(200, 52, 30, 0.18);
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.45);
}

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

/* ---------- Responsive ---------- */
@media (max-width: 720px) {
    .lt-summary { grid-template-columns: 1fr; }
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
                    Transactions
                </span>
                <h1>Payments.</h1>
                <p>Track every enrollment payment, refund, and pending transaction on the platform.</p>
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
                <div class="lt-summary-number lt-success">R <?php echo number_format($totals['total_paid'] ?? 0, 0); ?></div>
                <div class="lt-summary-label">Total revenue</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-2">
                <div class="lt-summary-number lt-pending"><?php echo $totals['pending_count'] ?? 0; ?></div>
                <div class="lt-summary-label">Pending payments</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-3">
                <div class="lt-summary-number lt-refunded">R <?php echo number_format($totals['total_refunded'] ?? 0, 0); ?></div>
                <div class="lt-summary-label">Refunded</div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="get" class="lt-filter-bar lt-reveal">
            <div class="lt-filter-field">
                <input type="text" name="search" class="lt-search-input" placeholder="🔍 Search student, email, reference..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="lt-filter-field" style="flex: 0 0 200px;">
                <select name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach(['paid','pending','refunded','failed'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $statusFilter==$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="lt-btn lt-btn-primary lt-btn-sm">Search</button>
            <?php if ($search || $statusFilter): ?>
                <a href="payments.php" class="lt-btn lt-btn-outline lt-btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Count line -->
        <p class="lt-count-line lt-reveal">
            <strong><?php echo count($enrollments); ?></strong>
            transaction<?php echo count($enrollments) != 1 ? 's' : ''; ?> shown
            <span style="opacity: 0.7;">(latest 100)</span>
        </p>

        <!-- Table -->
        <div class="lt-table-card lt-reveal">
            <div class="lt-table-scroll">
                <table class="lt-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $e): ?>
                            <tr>
                                <td>
                                    <div class="lt-student-name"><?php echo htmlspecialchars($e['fullname']); ?></div>
                                    <div class="lt-student-email"><?php echo htmlspecialchars($e['email']); ?></div>
                                </td>
                                <td>
                                    <span class="lt-class-name" title="<?php echo htmlspecialchars($e['class_title']); ?>">
                                        <?php echo htmlspecialchars($e['class_title']); ?>
                                    </span>
                                </td>
                                <td class="lt-amount">R <?php echo number_format($e['amount_paid'], 2); ?></td>
                                <td class="lt-cell-muted"><?php echo ucfirst($e['payment_method'] ?? '—'); ?></td>
                                <td class="lt-reference"><?php echo htmlspecialchars($e['payment_reference'] ?? '—'); ?></td>
                                <td>
                                    <span class="lt-pill lt-pill-<?php echo htmlspecialchars($e['payment_status']); ?>">
                                        <?php echo ucfirst($e['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="lt-cell-muted"><?php echo date('M d, Y', strtotime($e['enrolled_at'])); ?></td>
                                <td>
                                    <?php if ($e['payment_status'] == 'paid'): ?>
                                        <div class="lt-row-actions">
                                            <form method="post" style="display:inline; margin: 0;" onsubmit="return confirm('Issue refund?');">
                                                <input type="hidden" name="enroll_id" value="<?php echo $e['id']; ?>">
                                                <button type="submit" name="refund" class="lt-btn lt-btn-warn lt-btn-sm">Refund</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="lt-cell-muted">—</span>
                                    <?php endif; ?>
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