<?php
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$userId     = (int)$_SESSION['user']['id'];
$msg        = '';

/* =====================================================================
   POST HANDLERS — mark as read, mark all as read
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    /* Mark one as read */
    if (isset($_POST['mark_read'])) {
        $notifId = (int)$_POST['mark_read'];

        $stmt = $connection->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param('ii', $notifId, $userId);
        $stmt->execute();
        $stmt->close();

        /* Redirect back so a refresh doesn't resubmit */
        header('Location: index.php?msg=' . urlencode('Marked as read.'));
        exit;
    }

    /* Mark all as read */
    if (isset($_POST['mark_all_read'])) {
        $stmt = $connection->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        header('Location: index.php?msg=' . urlencode("Marked {$affected} notification(s) as read."));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
}

/* =====================================================================
   FILTERS + PAGINATION
   ===================================================================== */
$showUnreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
$page           = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit          = 20;
$offset         = ($page - 1) * $limit;

/* Counts for the summary strip */
$stmt = $connection->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param('i', $userId);
$stmt->execute();
$unreadCount = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $connection->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$totalCount = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

/* Main list query */
$where  = "user_id = ?";
$params = [$userId];
$types  = 'i';

if ($showUnreadOnly) {
    $where .= " AND is_read = 0";
}

/* Total pages for current filter */
$countSql = "SELECT COUNT(*) AS c FROM notifications WHERE $where";
$stmt = $connection->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$filteredTotal = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$totalPages = max(1, (int)ceil($filteredTotal / $limit));

/* Clamp page to valid range */
if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $limit;
}

/* Fetch the notifications for this page */
$sql = "
    SELECT id, type, title, message, link, is_read, created_at
    FROM notifications
    WHERE $where
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
";
$stmt = $connection->prepare($sql);
$bindTypes = $types . 'ii';
$bindParams = array_merge($params, [$limit, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =====================================================================
   RENDER
   ===================================================================== */
require_once '../includes/header.php';
?>

<style>
/* ===== LiveTeach notifications revamp — scoped to .lt-page ===== */
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
.lt-page h1, .lt-page h2, .lt-page h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container { max-width: 820px; margin: 0 auto; padding: 0 24px; }

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

.lt-dash-header p strong { color: var(--lt-gold); font-weight: 500; }

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
    text-decoration: none;
}

.lt-btn:hover { transform: translateY(-2px); }

.lt-btn-primary { background: var(--lt-flame); color: var(--lt-parchment); }
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
    background: var(--lt-gold);
    color: var(--lt-ink);
}
.lt-btn-gold:hover {
    background: #E8B85A;
    box-shadow: 0 10px 30px -10px rgba(217, 164, 65, 0.55);
}

.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

.lt-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
}

/* ---------- Success alert ---------- */
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
    margin-bottom: 24px;
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

.lt-summary-number.lt-flame { color: var(--lt-flame-bright); }

.lt-summary-label {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    transition: color 0.3s ease;
}

.lt-summary-cell:hover .lt-summary-label { color: var(--lt-parchment); }

/* ---------- Filter bar ---------- */
.lt-filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 22px;
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
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
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

.lt-tab-count {
    display: inline-block;
    background: rgba(0, 0, 0, 0.25);
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 0.72rem;
}

/* ---------- Animations ---------- */
.lt-reveal {
    opacity: 0;
    transform: translateY(24px);
    transition:
        opacity 0.7s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: opacity, transform;
}
.lt-reveal.lt-visible { opacity: 1; transform: translateY(0); }

.lt-reveal-scale {
    opacity: 0;
    transform: scale(0.96);
    transition:
        opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}
.lt-reveal-scale.lt-visible { opacity: 1; transform: scale(1); }

.lt-delay-1 { transition-delay: 0.06s; }
.lt-delay-2 { transition-delay: 0.12s; }
.lt-delay-3 { transition-delay: 0.18s; }
.lt-delay-4 { transition-delay: 0.24s; }
.lt-delay-5 { transition-delay: 0.30s; }

/* ---------- Notification list ---------- */
.lt-notif-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-bottom: 32px;
}

.lt-notif {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 20px 22px;
    display: flex;
    gap: 18px;
    align-items: flex-start;
    position: relative;
    overflow: hidden;
    transition:
        border-color 0.25s ease,
        transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
        background 0.3s ease,
        box-shadow 0.35s ease;
}

.lt-notif::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--lt-flame);
    transform: scaleY(0);
    transform-origin: top center;
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}

.lt-notif:hover::before { transform: scaleY(1); }

.lt-notif:hover {
    border-color: var(--lt-gold);
    transform: translateY(-3px);
    background: var(--lt-charcoal-raised);
    box-shadow: 0 16px 32px -20px rgba(217, 164, 65, 0.5);
}

.lt-notif.lt-unread {
    background: linear-gradient(180deg, rgba(200, 52, 30, 0.08), rgba(200, 52, 30, 0.03));
    border-color: rgba(200, 52, 30, 0.35);
}

.lt-notif.lt-unread::before {
    transform: scaleY(1);
    background: linear-gradient(180deg, var(--lt-flame-bright), var(--lt-flame-dark));
}

.lt-notif.lt-unread:hover {
    border-color: var(--lt-flame);
    box-shadow: 0 16px 32px -20px rgba(200, 52, 30, 0.6);
}

.lt-notif-clickable { cursor: pointer; }

.lt-notif-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
    transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), border-color 0.3s ease;
}

.lt-notif:hover .lt-notif-icon {
    transform: scale(1.08) rotate(-4deg);
    border-color: var(--lt-gold);
}

.lt-notif.lt-unread .lt-notif-icon {
    border-color: rgba(200, 52, 30, 0.4);
    background: rgba(200, 52, 30, 0.08);
}

.lt-notif-body { flex: 1; min-width: 0; }

.lt-notif-title {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem;
    color: var(--lt-parchment);
    margin: 0 0 6px;
    line-height: 1.35;
    transition: color 0.3s ease;
}

.lt-notif:hover .lt-notif-title { color: var(--lt-gold); }

.lt-notif-message {
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
    line-height: 1.6;
    margin: 0 0 10px;
    word-break: break-word;
}

.lt-notif-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.03em;
}

.lt-notif-type {
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid var(--lt-line);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 600;
    font-size: 0.65rem;
}

.lt-notif-type.lt-enrollment,
.lt-notif-type.lt-certificate,
.lt-notif-type.lt-payment {
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.35);
    background: rgba(217, 164, 65, 0.1);
}
.lt-notif-type.lt-live_stream {
    color: var(--lt-flame-bright);
    border-color: rgba(200, 52, 30, 0.45);
    background: rgba(200, 52, 30, 0.12);
}
.lt-notif-type.lt-class_update {
    color: var(--lt-parchment-dim);
    border-color: var(--lt-line);
    background: rgba(241, 231, 214, 0.05);
}

.lt-notif-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
    align-self: center;
}

.lt-notif-mark {
    background: transparent;
    border: 1px solid var(--lt-line);
    padding: 6px 12px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--lt-parchment-dim);
    font-family: inherit;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.lt-notif-mark:hover {
    background: var(--lt-flame);
    border-color: var(--lt-flame);
    color: var(--lt-parchment);
    transform: translateY(-1px);
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
    display: inline-block;
    animation: lt-empty-float 4s ease-in-out infinite;
}

@keyframes lt-empty-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

.lt-empty h3 { font-size: 1.25rem; margin: 0 0 10px; color: var(--lt-parchment); }
.lt-empty p { color: var(--lt-parchment-dim); margin: 0; font-size: 0.92rem; line-height: 1.65; }

/* ---------- Pagination ---------- */
.lt-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    padding: 32px 0 72px;
    flex-wrap: wrap;
}

.lt-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid var(--lt-line);
    color: var(--lt-parchment-dim);
    font-size: 0.85rem;
    font-weight: 600;
    background: transparent;
    transition: all 0.15s ease;
}

.lt-page-btn:hover { border-color: var(--lt-gold); color: var(--lt-gold); }

.lt-page-btn.lt-page-btn-current {
    background: var(--lt-flame);
    border-color: var(--lt-flame);
    color: var(--lt-parchment);
}

.lt-page-btn.lt-page-btn-disabled {
    opacity: 0.35;
    cursor: not-allowed;
    pointer-events: none;
}

.lt-page-info {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.78rem;
    color: var(--lt-parchment-dim);
    margin: 0 8px;
}

/* ---------- Responsive ---------- */
@media (max-width: 700px) {
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
    .lt-summary { grid-template-columns: 1fr; }
    .lt-summary-cell { border-right: none; border-bottom: 1px solid var(--lt-line); }
    .lt-summary-cell:last-child { border-bottom: none; }
}

@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-notif { padding: 16px 18px; gap: 14px; flex-wrap: wrap; }
    .lt-notif-icon { width: 38px; height: 38px; font-size: 1.1rem; }
    .lt-notif-title { font-size: 0.98rem; }
    .lt-notif-actions { width: 100%; flex-direction: row; }
    .lt-notif-actions .lt-notif-mark { flex: 1; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-empty-icon,
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
    .lt-reveal-scale { opacity: 1; transform: none; transition: none; }
    .lt-notif:hover, .lt-btn:hover { transform: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Inbox
                </span>
                <h1>Your notifications.</h1>
                <p>
                    <?php if ($unreadCount > 0): ?>
                        You have <strong><?php echo (int)$unreadCount; ?></strong>
                        unread notification<?php echo $unreadCount != 1 ? 's' : ''; ?>.
                    <?php else: ?>
                        You're all caught up.
                    <?php endif; ?>
                </p>
            </div>
            <div class="lt-header-actions">
                <?php if ($unreadCount > 0): ?>
                    <form method="post" style="margin: 0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="mark_all_read" value="1">
                        <button type="submit" class="lt-btn lt-btn-outline">✓ Mark all as read</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="lt-container">
        <?php if ($msg): ?>
            <div class="lt-alert lt-alert-success">
                <span>✓</span><span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Summary strip -->
        <div class="lt-summary" style="margin-top: 32px;">
            <div class="lt-summary-cell lt-reveal lt-delay-1">
                <div class="lt-summary-number lt-flame" data-count="<?php echo (int)$unreadCount; ?>">0</div>
                <div class="lt-summary-label">Unread</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-2">
                <div class="lt-summary-number" data-count="<?php echo (int)$totalCount; ?>">0</div>
                <div class="lt-summary-label">Total</div>
            </div>
            <div class="lt-summary-cell lt-reveal lt-delay-3">
                <div class="lt-summary-number" data-count="<?php echo (int)count($notifications); ?>">0</div>
                <div class="lt-summary-label">On this page</div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="lt-filter-bar">
            <div class="lt-tabs">
                <a href="index.php" class="lt-tab <?php echo !$showUnreadOnly ? 'lt-active' : ''; ?>">
                    📥 All <span class="lt-tab-count"><?php echo (int)$totalCount; ?></span>
                </a>
                <a href="index.php?unread=1" class="lt-tab <?php echo $showUnreadOnly ? 'lt-active' : ''; ?>">
                    🔵 Unread <span class="lt-tab-count"><?php echo (int)$unreadCount; ?></span>
                </a>
            </div>
        </div>

        <!-- Notifications list -->
        <?php if (empty($notifications)): ?>
            <div class="lt-empty lt-reveal-scale">
                <div class="lt-empty-icon">📭</div>
                <h3>
                    <?php echo $showUnreadOnly ? 'No unread notifications' : 'No notifications yet'; ?>
                </h3>
                <p>
                    <?php if ($showUnreadOnly): ?>
                        You've read everything. Switch back to <a href="index.php" style="color:var(--lt-gold);">All notifications</a> to see your history.
                    <?php else: ?>
                        When you receive notifications, they will appear here.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="lt-notif-list">
                <?php $i = 1; foreach ($notifications as $notif):
                    $icon = '🔔';
                    if ($notif['type'] == 'enrollment')       $icon = '📚';
                    elseif ($notif['type'] == 'live_stream')  $icon = '🔴';
                    elseif ($notif['type'] == 'certificate')  $icon = '🏆';
                    elseif ($notif['type'] == 'class_update') $icon = '📢';
                    elseif ($notif['type'] == 'payment')      $icon = '💰';

                    $typeLabel = ucwords(str_replace('_', ' ', (string)$notif['type']));
                    $hasLink   = !empty($notif['link']) && $notif['link'] !== '#';
                ?>
                    <div class="lt-notif <?php echo $notif['is_read'] ? '' : 'lt-unread'; ?> <?php echo $hasLink ? 'lt-notif-clickable' : ''; ?> lt-reveal lt-delay-<?php echo min($i, 5); ?>"
                         <?php if ($hasLink): ?>
                             data-notif-id="<?php echo (int)$notif['id']; ?>"
                             data-notif-link="<?php echo htmlspecialchars($notif['link'], ENT_QUOTES); ?>"
                             onclick="markAndRedirect(this)"
                         <?php endif; ?>>

                        <div class="lt-notif-icon"><?php echo $icon; ?></div>

                        <div class="lt-notif-body">
                            <h3 class="lt-notif-title"><?php echo htmlspecialchars($notif['title']); ?></h3>
                            <p class="lt-notif-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></p>
                            <div class="lt-notif-meta">
                                <span class="lt-notif-type lt-<?php echo htmlspecialchars($notif['type']); ?>">
                                    <?php echo htmlspecialchars($typeLabel); ?>
                                </span>
                                <span><?php echo date('F j, Y \a\t g:i A', strtotime($notif['created_at'])); ?></span>
                            </div>
                        </div>

                        <?php if (!$notif['is_read']): ?>
                            <div class="lt-notif-actions">
                                <form method="post" style="margin: 0;" onclick="event.stopPropagation();">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="mark_read" value="<?php echo (int)$notif['id']; ?>">
                                    <button type="submit" class="lt-notif-mark">Mark read</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php $i++; endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <?php
                $qs = $showUnreadOnly ? '&unread=1' : '';
                $prevDisabled = $page <= 1;
                $nextDisabled = $page >= $totalPages;
            ?>
            <nav class="lt-pagination">
                <?php if ($prevDisabled): ?>
                    <span class="lt-page-btn lt-page-btn-disabled">← Prev</span>
                <?php else: ?>
                    <a href="?page=<?php echo $page - 1 . $qs; ?>" class="lt-page-btn">← Prev</a>
                <?php endif; ?>

                <span class="lt-page-info">
                    Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
                </span>

                <?php if ($nextDisabled): ?>
                    <span class="lt-page-btn lt-page-btn-disabled">Next →</span>
                <?php else: ?>
                    <a href="?page=<?php echo $page + 1 . $qs; ?>" class="lt-page-btn">Next →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</main>

<script>
/* ---------- Mark one as read via AJAX, then redirect ----------
   We can't easily do form POST + redirect via a click on the whole
   row, so we POST asynchronously and then navigate. If the AJAX call
   fails, we still navigate — the notification just stays unread. */
function markAndRedirect(el) {
    const id   = el.dataset.notifId;
    const link = el.dataset.notifLink;

    if (!id || !link || link === '#') return;

    const token = <?php echo json_encode(csrf_token()); ?>;

    fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'csrf_token=' + encodeURIComponent(token) + '&mark_read=' + encodeURIComponent(id),
        credentials: 'same-origin'
    })
    .catch(function () { /* silent */ })
    .finally(function () {
        window.location.href = link;
    });
}

/* ============================================================
   Reveal + counter animation controller
   ============================================================ */
(function () {
    'use strict';

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReduced) {
        document.querySelectorAll('.lt-reveal, .lt-reveal-scale')
            .forEach(el => el.classList.add('lt-visible'));
        document.querySelectorAll('.lt-summary-number[data-count]').forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            el.textContent = target.toLocaleString();
        });
        return;
    }

    /* Reveal on scroll */
    const revealTargets = document.querySelectorAll('.lt-reveal, .lt-reveal-scale');
    if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('lt-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
        revealTargets.forEach(el => revealObserver.observe(el));
    } else {
        revealTargets.forEach(el => el.classList.add('lt-visible'));
    }

    /* Animated counters */
    function animateCount(el, target, duration) {
        const startTime = performance.now();
        function tick(now) {
            const elapsed  = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased    = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.floor(target * eased).toLocaleString();
            if (progress < 1) requestAnimationFrame(tick);
            else el.textContent = target.toLocaleString();
        }
        requestAnimationFrame(tick);
    }

    const counters = document.querySelectorAll('.lt-summary-number[data-count]');
    if ('IntersectionObserver' in window && counters.length) {
        const counterObserver = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const target = parseInt(el.getAttribute('data-count'), 10);
                    if (!isNaN(target) && target > 0) animateCount(el, target, 1200);
                    else el.textContent = '0';
                    observer.unobserve(el);
                }
            });
        }, { threshold: 0.4 });
        counters.forEach(el => counterObserver.observe(el));
    } else {
        counters.forEach(function (el) {
            const target = parseInt(el.getAttribute('data-count'), 10) || 0;
            el.textContent = target.toLocaleString();
        });
    }
})();
</script>

<?php require_once '../includes/footer.php'; ?>