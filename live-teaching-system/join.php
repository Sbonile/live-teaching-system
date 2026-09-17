<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'student') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$studentId = $_SESSION['user']['id'];

// Verify enrollment and class status
$stmt = $connection->prepare("
    SELECT c.*, e.payment_status, u.fullname as teacher_name
    FROM live_classes c
    JOIN enrollments e ON e.class_id = c.id AND e.student_id = ?
    JOIN users u ON c.teacher_id = u.id
    WHERE c.id = ?
");
$stmt->bind_param('ii', $studentId, $classId);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    header('Location: ../classes/index.php');
    exit;
}
if ($data['payment_status'] != 'paid') {
    header("Location: ../payments/checkout.php?class_id=$classId");
    exit;
}
if ($data['status'] == 'upcoming') {
    header("Location: ../dashboard/index.php?msg=not_started");
    exit;
}

// Record attendance for today if ongoing
if ($data['status'] == 'ongoing') {
    $today = date('Y-m-d');
    $attCheck = $connection->prepare("SELECT id FROM attendance WHERE student_id = ? AND class_id = ? AND session_date = ?");
    $attCheck->bind_param('iis', $studentId, $classId, $today);
    $attCheck->execute();
    if ($attCheck->get_result()->num_rows == 0) {
        $ins = $connection->prepare("INSERT INTO attendance (student_id, class_id, session_date, status) VALUES (?, ?, ?, 'present')");
        $ins->bind_param('iis', $studentId, $classId, $today);
        $ins->execute();
        // Update attendance count in enrollments
        $connection->query("UPDATE enrollments SET attendance = attendance + 1 WHERE student_id = $studentId AND class_id = $classId");
    }
}

require_once '../includes/header.php';
?>

<style>
/* ===== LiveTeach join revamp — scoped to .lt-page ===== */
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
    padding-bottom: 72px;
}

.lt-page .lt-serif,
.lt-page h1, .lt-page h2, .lt-page h3 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container {
    max-width: 720px;
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

.lt-dash-header p strong {
    color: var(--lt-gold);
    font-weight: 500;
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 13px 24px;
    border-radius: 8px;
    font-size: 0.95rem;
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

.lt-btn-block { width: 100%; }

/* ---------- Card ---------- */
.lt-card {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 14px;
    padding: 32px 30px;
    margin-top: 32px;
    position: relative;
    overflow: hidden;
}

.lt-card.lt-live {
    border-color: rgba(200, 52, 30, 0.5);
}

.lt-card.lt-live::before {
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
    padding: 7px 15px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 20px;
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

.lt-class-title {
    font-size: 1.35rem;
    color: var(--lt-parchment);
    margin: 0 0 16px;
    line-height: 1.3;
    position: relative;
    z-index: 1;
}

.lt-class-meta {
    display: flex;
    flex-direction: column;
    gap: 10px;
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
    padding-bottom: 22px;
    margin-bottom: 22px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    z-index: 1;
}

.lt-class-meta span {
    display: flex;
    align-items: center;
    gap: 10px;
}

.lt-class-meta .lt-meta-icon {
    width: 18px;
    text-align: center;
    flex-shrink: 0;
    opacity: 0.85;
}

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
    font-size: 0.9rem;
    line-height: 1.55;
    border: 1px solid transparent;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    position: relative;
    z-index: 1;
}

.lt-alert-success {
    background: rgba(217, 164, 65, 0.12);
    border-color: rgba(217, 164, 65, 0.4);
    color: #EBD3A0;
}

.lt-alert-info {
    background: var(--lt-ink);
    border-color: var(--lt-line);
    color: var(--lt-parchment-dim);
}

/* ---------- Meeting box ---------- */
.lt-meeting {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 24px 22px;
    text-align: center;
    position: relative;
    z-index: 1;
    margin-bottom: 20px;
}

.lt-meeting p {
    color: var(--lt-parchment-dim);
    font-size: 0.9rem;
    margin: 0 0 18px;
    line-height: 1.6;
}

.lt-meeting .lt-btn {
    padding: 15px 36px;
    font-size: 1rem;
}

.lt-meeting-meta {
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid var(--lt-line);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    letter-spacing: 0.03em;
    line-height: 1.7;
}

.lt-meeting-meta strong {
    color: var(--lt-gold);
    font-weight: 600;
}

/* ---------- Actions ---------- */
.lt-actions {
    margin-top: 24px;
    padding-top: 22px;
    border-top: 1px solid var(--lt-line);
    position: relative;
    z-index: 1;
}

/* ---------- Responsive ---------- */
@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-card { padding: 26px 22px; }
    .lt-meeting { padding: 20px 18px; }
    .lt-meeting .lt-btn { padding: 13px 24px; width: 100%; }
    .lt-actions .lt-btn { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-live-dot { animation: none; }
}
</style>

<main class="lt-page">
    <!-- Header -->
    <div class="lt-dash-header">
        <div class="lt-container lt-dash-header-inner">
            <span class="lt-eyebrow">
                <span class="lt-eyebrow-dot"></span>
                <?php echo $data['status'] == 'ongoing' ? 'Live session' : 'Class'; ?>
            </span>
            <h1>
                <?php if ($data['status'] == 'ongoing'): ?>
                    Your class is live.
                <?php elseif ($data['status'] == 'completed'): ?>
                    Class has ended.
                <?php else: ?>
                    Join live class.
                <?php endif; ?>
            </h1>
            <p>Session for <strong><?php echo htmlspecialchars($data['title']); ?></strong></p>
        </div>
    </div>

    <div class="lt-container">
        <div class="lt-card <?php echo $data['status'] == 'ongoing' ? 'lt-live' : ''; ?>">
            <?php if ($data['status'] == 'ongoing'): ?>
                <span class="lt-live-badge" style="position: relative; z-index: 1;">
                    <span class="lt-live-dot"></span>
                    Live now
                </span>
            <?php endif; ?>
            
            <h2 class="lt-class-title"><?php echo htmlspecialchars($data['title']); ?></h2>
            
            <div class="lt-class-meta">
                <span><span class="lt-meta-icon">👨‍🏫</span> <?php echo htmlspecialchars($data['teacher_name']); ?></span>
                <span><span class="lt-meta-icon">📅</span> <?php echo date('l, F j, Y \a\t g:i A', strtotime($data['start_date'])); ?></span>
            </div>

            <?php if ($data['status'] == 'ongoing'): ?>
                <div class="lt-alert lt-alert-success">
                    <span>✓</span>
                    <span>Attendance recorded for today.</span>
                </div>
                
                <?php if ($data['meeting_link']): ?>
                    <div class="lt-meeting">
                        <p>Click the button below to join your live class session.</p>
                        <a href="<?php echo htmlspecialchars($data['meeting_link']); ?>" target="_blank" class="lt-btn lt-btn-primary">
                            🎥 Join live class →
                        </a>
                        
                        <?php if ($data['meeting_id']): ?>
                            <div class="lt-meeting-meta">
                                <strong>Meeting ID</strong> · <?php echo htmlspecialchars($data['meeting_id']); ?>
                                <?php if ($data['meeting_password']): ?>
                                    <br>
                                    <strong>Password</strong> · <?php echo htmlspecialchars($data['meeting_password']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="lt-alert lt-alert-info">
                        <span>ℹ️</span>
                        <span>The teacher hasn't added a meeting link yet. Please check back shortly.</span>
                    </div>
                <?php endif; ?>
                
            <?php elseif ($data['status'] == 'completed'): ?>
                <div class="lt-alert lt-alert-info">
                    <span>ℹ️</span>
                    <span>This class has ended.</span>
                </div>
                
                <?php if ($data['recording_url']): ?>
                    <div class="lt-meeting">
                        <p>A recording of this class is available to watch.</p>
                        <a href="<?php echo htmlspecialchars($data['recording_url']); ?>" target="_blank" class="lt-btn lt-btn-gold">
                            📹 Watch recording →
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="lt-actions">
                <a href="../dashboard/index.php" class="lt-btn lt-btn-outline">← Back to dashboard</a>
            </div>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>