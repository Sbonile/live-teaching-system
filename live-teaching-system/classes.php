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
$msg        = '';
$error      = '';

/* ---------- Handle delete class ---------- */
if (isset($_POST['delete_class'])) {
    csrf_verify();
    $delId = (int)$_POST['delete_class'];

    $own = $connection->prepare("SELECT id FROM live_classes WHERE id = ? AND teacher_id = ?");
    $own->bind_param('ii', $delId, $teacherId);
    $own->execute();
    $owns = $own->get_result()->fetch_assoc();
    $own->close();

    if (!$owns) {
        $error = "Class not found.";
    } else {
        $check = $connection->prepare("
            SELECT COUNT(*) AS cnt FROM enrollments
             WHERE class_id = ?
               AND (payment_status = 'paid' OR certificate_issued = 1)
        ");
        $check->bind_param('i', $delId);
        $check->execute();
        $cnt = (int)$check->get_result()->fetch_assoc()['cnt'];
        $check->close();

        if ($cnt === 0) {
            $del = $connection->prepare("DELETE FROM live_classes WHERE id = ? AND teacher_id = ?");
            $del->bind_param('ii', $delId, $teacherId);
            $del->execute();
            $del->close();
            $msg = "Class deleted successfully!";
        } else {
            $error = "Cannot delete — this class has paid enrollments or issued certificates. Archive it instead.";
        }
    }
}

/* ---------- Handle status update ---------- */
if (isset($_POST['update_status'])) {
    csrf_verify();
    $classId   = (int)$_POST['class_id'];
    $newStatus = $_POST['new_status'];
    $validStatuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];

    if (in_array($newStatus, $validStatuses, true)) {
        $upd = $connection->prepare("UPDATE live_classes SET status = ? WHERE id = ? AND teacher_id = ?");
        $upd->bind_param('sii', $newStatus, $classId, $teacherId);
        $upd->execute();
        $affected = $upd->affected_rows;
        $upd->close();

        if ($affected > 0) {
            $msg = "Class status updated!";
        } else {
            $error = "Could not update class — it may not belong to you, or the status is unchanged.";
        }
    }
}

/* ---------- Handle sequential-unlock toggle ---------- */
if (isset($_POST['toggle_sequential'])) {
    csrf_verify();
    $classId = (int)$_POST['class_id'];
    $enabled = isset($_POST['sequential_unlock']) && $_POST['sequential_unlock'] == '1' ? 1 : 0;

    $upd = $connection->prepare("UPDATE live_classes SET sequential_unlock = ? WHERE id = ? AND teacher_id = ?");
    $upd->bind_param('iii', $enabled, $classId, $teacherId);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected > 0) {
        $msg = $enabled
            ? "Sequential unlock enabled — students must complete each lesson to reach the next."
            : "Sequential unlock disabled — lessons unlock individually based on their lock state.";
    } else {
        $error = "Could not update this class — it may not belong to you, or the setting is unchanged.";
    }
}

/* ---------- Handle bulk lock/unlock of all lessons ---------- */
if (isset($_POST['bulk_lesson_lock'])) {
    csrf_verify();
    $classId = (int)$_POST['class_id'];
    $lockAll = isset($_POST['lock_value']) && $_POST['lock_value'] == '1' ? 1 : 0;

    $own = $connection->prepare("SELECT id FROM live_classes WHERE id = ? AND teacher_id = ?");
    $own->bind_param('ii', $classId, $teacherId);
    $own->execute();
    $owns = $own->get_result()->fetch_assoc();
    $own->close();

    if (!$owns) {
        $error = "Class not found.";
    } else {
        $upd = $connection->prepare("UPDATE course_lessons SET is_locked = ? WHERE class_id = ?");
        $upd->bind_param('ii', $lockAll, $classId);
        $upd->execute();
        $upd->close();
        $msg = $lockAll ? "All lessons locked." : "All lessons unlocked.";
    }
}

/* ---------- Filters ---------- */
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';

$where  = "c.teacher_id = ?";
$params = [$teacherId];
$types  = 'i';

if ($statusFilter && in_array($statusFilter, ['upcoming', 'ongoing', 'completed', 'cancelled'], true)) {
    $where .= " AND c.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($searchFilter !== '') {
    $where .= " AND (c.title LIKE ? OR c.description LIKE ?)";
    $like = '%' . $searchFilter . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

/* ---------- Get classes ---------- */
$sql = "
    SELECT c.*,
        (SELECT COUNT(*) FROM enrollments
           WHERE class_id = c.id AND payment_status = 'paid') AS student_count,
        (SELECT COUNT(*) FROM enrollments
           WHERE class_id = c.id
             AND (payment_status = 'paid' OR certificate_issued = 1)) AS locked_enrollments,
        (SELECT SUM(amount_paid) FROM enrollments
           WHERE class_id = c.id AND payment_status = 'paid') AS total_revenue,
        (SELECT COUNT(*) FROM course_lessons WHERE class_id = c.id) AS lesson_count,
        (SELECT COUNT(*) FROM course_lessons WHERE class_id = c.id AND is_locked = 1) AS locked_lesson_count
    FROM live_classes c
    WHERE $where
    ORDER BY
        CASE c.status
            WHEN 'ongoing' THEN 1
            WHEN 'upcoming' THEN 2
            WHEN 'completed' THEN 3
            ELSE 4
        END,
        c.start_date ASC
";
$stmt = $connection->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Stats ---------- */
$s = $connection->prepare("SELECT COUNT(*) AS cnt FROM live_classes WHERE teacher_id = ?");
$s->bind_param('i', $teacherId);
$s->execute();
$totalClasses = (int)$s->get_result()->fetch_assoc()['cnt'];
$s->close();

$s = $connection->prepare("
    SELECT COUNT(DISTINCT e.student_id) AS cnt
    FROM enrollments e
    JOIN live_classes c ON e.class_id = c.id
    WHERE c.teacher_id = ? AND e.payment_status = 'paid'
");
$s->bind_param('i', $teacherId);
$s->execute();
$totalStudents = (int)$s->get_result()->fetch_assoc()['cnt'];
$s->close();

$s = $connection->prepare("
    SELECT SUM(e.amount_paid) AS total
    FROM enrollments e
    JOIN live_classes c ON e.class_id = c.id
    WHERE c.teacher_id = ? AND e.payment_status = 'paid'
");
$s->bind_param('i', $teacherId);
$s->execute();
$totalRevenue = (float)($s->get_result()->fetch_assoc()['total'] ?? 0);
$s->close();
?>

<style>
/* ===== LiveTeach teacher classes revamp — scoped to .lt-page ===== */
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

.lt-btn-danger {
    background: transparent;
    border-color: rgba(200, 52, 30, 0.5);
    color: var(--lt-flame-bright);
}
.lt-btn-danger:hover {
    background: var(--lt-flame);
    color: var(--lt-parchment);
    border-color: var(--lt-flame);
}

.lt-btn-sm { padding: 7px 12px; font-size: 0.78rem; }

.lt-btn:disabled,
.lt-btn-locked {
    background: transparent;
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
    opacity: 0.55;
    cursor: not-allowed;
    transform: none !important;
}

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

.lt-stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
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

.lt-stat-label { font-size: 0.8rem; color: var(--lt-parchment-dim); }

.lt-filter-bar {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 18px 20px;
    margin-bottom: 28px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.lt-filter-field { flex: 1; min-width: 180px; }

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

.lt-class-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    margin-bottom: 20px;
    overflow: hidden;
    transition: border-color 0.2s ease;
}

.lt-class-card:hover { border-color: var(--lt-flame); }

.lt-class-head {
    padding: 22px 24px;
    border-bottom: 1px solid var(--lt-line);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
}

.lt-class-head-left { min-width: 0; flex: 1; }

.lt-class-title {
    font-size: 1.2rem;
    color: var(--lt-parchment);
    margin: 0 0 10px;
    line-height: 1.3;
}

.lt-class-meta {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 0.83rem;
    color: var(--lt-parchment-dim);
}

.lt-class-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    flex-shrink: 0;
}

.lt-status {
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

.lt-status-soon {
    background: rgba(217, 164, 65, 0.2);
    color: var(--lt-gold);
    border: 1px solid rgba(217, 164, 65, 0.5);
}

.lt-class-body {
    padding: 22px 24px;
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 26px;
    align-items: start;
}

.lt-class-desc {
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    line-height: 1.65;
    margin: 0 0 14px;
}

.lt-class-link {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.lt-class-link a {
    color: var(--lt-gold);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.78rem;
    transition: color 0.15s ease;
    word-break: break-all;
}

.lt-class-link a:hover { color: var(--lt-flame-bright); }

.lt-class-stats {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 6px 16px;
}

.lt-stat-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 11px 0;
    border-bottom: 1px solid var(--lt-line);
    font-size: 0.85rem;
}

.lt-stat-row:last-child { border-bottom: none; }

.lt-stat-row .lt-slabel {
    color: var(--lt-parchment-dim);
    display: flex;
    align-items: center;
    gap: 8px;
}

.lt-stat-row .lt-svalue {
    color: var(--lt-parchment);
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 0.98rem;
}

.lt-class-foot {
    padding: 16px 24px;
    background: var(--lt-charcoal-raised);
    border-top: 1px solid var(--lt-line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}

.lt-class-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.lt-class-tools { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

.lt-status-form select {
    padding: 8px 12px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 7px;
    color: var(--lt-parchment);
    font-size: 0.8rem;
    font-family: inherit;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.lt-status-form select:focus { outline: none; border-color: var(--lt-flame); }

.lt-empty {
    text-align: center;
    padding: 72px 32px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 14px;
}

.lt-empty-icon { font-size: 3rem; margin-bottom: 16px; opacity: 0.6; }

.lt-empty h3 { font-size: 1.3rem; margin: 0 0 10px; color: var(--lt-parchment); }

.lt-empty p {
    color: var(--lt-parchment-dim);
    margin: 0 0 24px;
    font-size: 0.92rem;
    line-height: 1.6;
}

.lt-tips {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
    margin-top: 40px;
}

.lt-tips-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
}

.lt-tips-head h4 { font-size: 1.05rem; margin: 0; color: var(--lt-parchment); }

.lt-tips-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}

.lt-tip {
    padding: 16px 18px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
}

.lt-tip strong {
    display: block;
    font-size: 0.85rem;
    color: var(--lt-gold);
    margin-bottom: 6px;
    font-weight: 600;
}

.lt-tip p {
    margin: 0;
    font-size: 0.83rem;
    color: var(--lt-parchment-dim);
    line-height: 1.55;
}

@media (max-width: 900px) {
    .lt-stats-grid { grid-template-columns: 1fr; }
    .lt-stat { border-right: none; border-bottom: 1px solid var(--lt-line); }
    .lt-stat:last-child { border-bottom: none; }
    .lt-class-body { grid-template-columns: 1fr; }
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
}

@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-class-head,
    .lt-class-body,
    .lt-class-foot { padding-left: 20px; padding-right: 20px; }
    .lt-class-actions,
    .lt-class-tools { width: 100%; }
    .lt-class-actions .lt-btn,
    .lt-class-tools .lt-btn,
    .lt-status-form select { width: 100%; }
    .lt-status-form { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-status-ongoing .lt-status-dot { animation: none; }
}
</style>

<main class="lt-page">
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Manage your catalogue
                </span>
                <h1>My classes.</h1>
                <p>Manage all your live classes from one place.</p>
            </div>
            <a href="add-class.php" class="lt-btn lt-btn-primary">➕ Create new class</a>
        </div>
    </div>

    <div class="lt-container" style="padding-bottom: 72px;">
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

        <div class="lt-stats-grid" style="margin-top: 32px;">
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$totalClasses; ?></div>
                <div class="lt-stat-label">Total classes</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number"><?php echo (int)$totalStudents; ?></div>
                <div class="lt-stat-label">Total students</div>
            </div>
            <div class="lt-stat">
                <div class="lt-stat-number">R <?php echo number_format($totalRevenue ?? 0, 0); ?></div>
                <div class="lt-stat-label">Total revenue</div>
            </div>
        </div>

        <div class="lt-filter-bar">
            <div class="lt-filter-field">
                <input type="text" id="searchInput" placeholder="🔍 Search by class title..." value="<?php echo htmlspecialchars($searchFilter); ?>">
            </div>
            <div class="lt-filter-field" style="flex: 0 0 200px;">
                <select id="statusFilter">
                    <option value="">📊 All status</option>
                    <option value="upcoming" <?php echo $statusFilter == 'upcoming' ? 'selected' : ''; ?>>📅 Upcoming</option>
                    <option value="ongoing" <?php echo $statusFilter == 'ongoing' ? 'selected' : ''; ?>>🔴 Live now</option>
                    <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>✅ Completed</option>
                    <option value="cancelled" <?php echo $statusFilter == 'cancelled' ? 'selected' : ''; ?>>🗄️ Archived</option>
                </select>
            </div>
            <?php if ($searchFilter || $statusFilter): ?>
                <a href="classes.php" class="lt-btn lt-btn-outline">✖ Clear filters</a>
            <?php endif; ?>
        </div>

        <?php if (empty($classes)): ?>
            <div class="lt-empty">
                <div class="lt-empty-icon">📭</div>
                <h3>No classes found</h3>
                <p>
                    <?php if ($searchFilter || $statusFilter): ?>
                        No classes match your filters. Try clearing them.
                    <?php else: ?>
                        You haven't created any classes yet.
                    <?php endif; ?>
                </p>
                <a href="add-class.php" class="lt-btn lt-btn-primary">Create your first class</a>
            </div>
        <?php else: ?>
            <?php foreach ($classes as $class):
                $statusBadge = '';
                $statusText = '';

                switch($class['status']) {
                    case 'upcoming':
                        $statusBadge = 'lt-status-upcoming';
                        $statusText = 'Upcoming';
                        break;
                    case 'ongoing':
                        $statusBadge = 'lt-status-ongoing';
                        $statusText = 'Live now';
                        break;
                    case 'completed':
                        $statusBadge = 'lt-status-completed';
                        $statusText = 'Completed';
                        break;
                    case 'cancelled':
                        $statusBadge = 'lt-status-cancelled';
                        $statusText = 'Archived';
                        break;
                }

                $startDate = new DateTime($class['start_date']);
                $now = new DateTime();
                $isStartingSoon = ($class['status'] == 'upcoming' && $startDate->diff($now)->days <= 3);

                $lockedEnrol = (int)($class['locked_enrollments'] ?? 0);
                $isDeletable = ($lockedEnrol === 0);

                $lessonCount   = (int)($class['lesson_count'] ?? 0);
                $lockedLessons = (int)($class['locked_lesson_count'] ?? 0);
                $sequential    = !empty($class['sequential_unlock']);
            ?>
                <div class="lt-class-card">
                    <div class="lt-class-head">
                        <div class="lt-class-head-left">
                            <h3 class="lt-class-title"><?php echo htmlspecialchars($class['title']); ?></h3>
                            <div class="lt-class-meta">
                                <span>📅 <?php echo date('M d, Y', strtotime($class['start_date'])); ?></span>
                                <span>⏰ <?php echo date('h:i A', strtotime($class['start_date'])); ?></span>
                                <?php if (!empty($class['category'])): ?>
                                    <span>📂 <?php echo htmlspecialchars($class['category']); ?></span>
                                <?php endif; ?>
                                <span>🎓 <?php echo ucfirst($class['level']); ?></span>
                                <span>📚 <?php echo $lessonCount; ?> lessons</span>
                                <?php if ($lockedLessons > 0): ?>
                                    <span>🔒 <?php echo $lockedLessons; ?> locked</span>
                                <?php endif; ?>
                                <?php if ($sequential): ?>
                                    <span>🔗 sequential</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="lt-class-badges">
                            <span class="lt-status <?php echo $statusBadge; ?>">
                                <?php if ($class['status'] == 'ongoing'): ?>
                                    <span class="lt-status-dot"></span>
                                <?php endif; ?>
                                <?php echo $statusText; ?>
                            </span>
                            <?php if ($isStartingSoon && $class['status'] == 'upcoming'): ?>
                                <span class="lt-status lt-status-soon">⚠️ Starting soon</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="lt-class-body">
                        <div>
                            <p class="lt-class-desc">
                                <?php echo nl2br(htmlspecialchars(substr((string)$class['description'], 0, 200))); ?>
                                <?php if (strlen((string)$class['description']) > 200): ?>...<?php endif; ?>
                            </p>

                            <?php if (!empty($class['meeting_link'])): ?>
                                <div class="lt-class-link">
                                    <span>🔗 Meeting link:</span>
                                    <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" rel="noopener">
                                        <?php echo htmlspecialchars(substr($class['meeting_link'], 0, 50)); ?>...
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="lt-class-stats">
                            <div class="lt-stat-row">
                                <span class="lt-slabel">👥 Enrolled</span>
                                <span class="lt-svalue"><?php echo (int)$class['student_count']; ?> / <?php echo (int)$class['max_students']; ?></span>
                            </div>
                            <div class="lt-stat-row">
                                <span class="lt-slabel">💰 Price</span>
                                <span class="lt-svalue">R <?php echo number_format((float)$class['price'], 2); ?></span>
                            </div>
                            <div class="lt-stat-row">
                                <span class="lt-slabel">📊 Revenue</span>
                                <span class="lt-svalue">R <?php echo number_format((float)($class['total_revenue'] ?? 0), 2); ?></span>
                            </div>
                            <div class="lt-stat-row">
                                <span class="lt-slabel">⏱️ Duration</span>
                                <span class="lt-svalue" style="font-family: 'Inter', sans-serif; font-size: 0.85rem;"><?php echo htmlspecialchars($class['duration'] ?: 'Not specified'); ?></span>
                            </div>
                            <div class="lt-stat-row">
                                <span class="lt-slabel">🔒 Lessons locked</span>
                                <span class="lt-svalue" style="font-family: 'Inter', sans-serif; font-size: 0.85rem;"><?php echo $lockedLessons; ?> / <?php echo $lessonCount; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="lt-class-foot">
                        <div class="lt-class-actions">
                            <a href="../classes/class.php?id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm" target="_blank" rel="noopener">👁️ View</a>
                            <a href="edit-class.php?id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-primary lt-btn-sm">✏️ Edit</a>
                            <a href="course-content.php?class_id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">📚 Lessons</a>
                            <a href="attendance.php?class_id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">📋 Attendance</a>
                            <a href="students.php?class_id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">👥 Students</a>
                            <a href="live-stream.php?class_id=<?php echo (int)$class['id']; ?>" class="lt-btn lt-btn-outline lt-btn-sm">🎥 Live</a>
                        </div>

                        <div class="lt-class-tools">
                            <form method="post" class="lt-status-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="class_id" value="<?php echo (int)$class['id']; ?>">
                                <select name="new_status" onchange="this.form.submit()">
                                    <option value="upcoming" <?php echo $class['status'] == 'upcoming' ? 'selected' : ''; ?>>📅 Set upcoming</option>
                                    <option value="ongoing" <?php echo $class['status'] == 'ongoing' ? 'selected' : ''; ?>>🔴 Set live now</option>
                                    <option value="completed" <?php echo $class['status'] == 'completed' ? 'selected' : ''; ?>>✅ Set completed</option>
                                    <option value="cancelled" <?php echo $class['status'] == 'cancelled' ? 'selected' : ''; ?>>🗄️ Archive (cancel)</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>

                            <form method="post" class="lt-status-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="class_id" value="<?php echo (int)$class['id']; ?>">
                                <input type="hidden" name="sequential_unlock" value="<?php echo $sequential ? '0' : '1'; ?>">
                                <input type="hidden" name="toggle_sequential" value="1">
                                <button type="submit"
                                        class="lt-btn <?php echo $sequential ? 'lt-btn-gold' : 'lt-btn-outline'; ?> lt-btn-sm"
                                        title="<?php echo $sequential
                                            ? 'Sequential mode ON — the next lesson unlocks only after the current one is completed'
                                            : 'Sequential mode OFF — lessons unlock individually based on each lesson\'s lock setting'; ?>">
                                    <?php echo $sequential ? '🔗 Sequential: ON' : '🔗 Sequential: OFF'; ?>
                                </button>
                            </form>

                            <?php if ($lessonCount > 0 && $lockedLessons > 0): ?>
                                <form method="post" onsubmit="return confirm('Unlock all lessons in this class?');" style="display:inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="class_id" value="<?php echo (int)$class['id']; ?>">
                                    <input type="hidden" name="lock_value" value="0">
                                    <input type="hidden" name="bulk_lesson_lock" value="1">
                                    <button type="submit" class="lt-btn lt-btn-outline lt-btn-sm" title="Unlock every lesson in this class">
                                        🔓 Unlock all
                                    </button>
                                </form>
                            <?php elseif ($lessonCount > 0): ?>
                                <form method="post" onsubmit="return confirm('Lock all lessons in this class? Students will need them unlocked individually or via sequential progress.');" style="display:inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="class_id" value="<?php echo (int)$class['id']; ?>">
                                    <input type="hidden" name="lock_value" value="1">
                                    <input type="hidden" name="bulk_lesson_lock" value="1">
                                    <button type="submit" class="lt-btn lt-btn-outline lt-btn-sm" title="Lock every lesson in this class">
                                        🔒 Lock all
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($isDeletable): ?>
                                <form method="post" onsubmit="return confirm('Permanently delete this class? This cannot be undone.');" style="display: inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="delete_class" value="<?php echo (int)$class['id']; ?>">
                                    <button type="submit" class="lt-btn lt-btn-danger lt-btn-sm">🗑️ Delete</button>
                                </form>
                            <?php else: ?>
                                <form method="post" onsubmit="return confirm('Archive this class? Students keep access to existing content, but no new enrollments will be accepted.');" style="display: inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="class_id" value="<?php echo (int)$class['id']; ?>">
                                    <input type="hidden" name="new_status" value="cancelled">
                                    <input type="hidden" name="update_status" value="1">
                                    <button type="submit" class="lt-btn lt-btn-outline lt-btn-sm"
                                            title="<?php echo $lockedEnrol; ?> paid enrollment<?php echo $lockedEnrol != 1 ? 's' : '' ?> or issued certificate<?php echo $lockedEnrol != 1 ? 's' : '' ?> — archive instead of delete">
                                        🗄️ Archive
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="lt-tips">
            <div class="lt-tips-head">
                <span style="font-size: 1.1rem;">💡</span>
                <h4>Quick tips</h4>
            </div>
            <div class="lt-tips-grid">
                <div class="lt-tip">
                    <strong>📅 Status management</strong>
                    <p>Update class status as your class progresses from Upcoming → Live → Completed.</p>
                </div>
                <div class="lt-tip">
                    <strong>🔒 Locking & sequential unlock</strong>
                    <p>Lock individual lessons in <em>Lessons → Manage</em>. Or turn on <strong>Sequential</strong> mode here so each lesson unlocks only after the student finishes the one before it.</p>
                </div>
                <div class="lt-tip">
                    <strong>🗄️ Archiving vs deleting</strong>
                    <p>Classes with paid students or issued certificates can be archived but not deleted, so students keep access to what they paid for.</p>
                </div>
                <div class="lt-tip">
                    <strong>💰 Monetization</strong>
                    <p>Set competitive prices and offer free preview lessons to attract more students.</p>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
const searchInput  = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');

function applyFilters() {
    const search = searchInput.value;
    const status = statusFilter.value;
    let url = 'classes.php?';

    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    if (status) url += 'status=' + encodeURIComponent(status);

    window.location.href = url;
}

searchInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') applyFilters();
});

statusFilter.addEventListener('change', function() {
    applyFilters();
});
</script>

<?php require_once '../includes/footer.php'; ?>