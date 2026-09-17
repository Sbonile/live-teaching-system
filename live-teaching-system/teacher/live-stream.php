<?php
// Start session FIRST - NO whitespace before this tag
session_start();
require_once '../config/database.php';
require_once '../includes/csrf.php';

// Check login FIRST before any output
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'teacher') {
    header('Location: ../login.php');
    exit;
}

$connection = getDbConnection();
$teacherId  = (int)$_SESSION['user']['id'];
$teacherName = $_SESSION['user']['fullname'] ?? 'Teacher';
$classId    = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$msg        = '';
$error      = '';

/* ---------------------------------------------------------------
   Run the scheduled-stream reminder engine on every page load.
   This fires 30-min and 5-min reminders even without cron.
   --------------------------------------------------------------- */
require_once __DIR__ . '/notify-scheduled.php';
notify_scheduled_streams($connection);

/* ---------------------------------------------------------------
   No class selected → show the class selector
   --------------------------------------------------------------- */
if ($classId === 0) {
    $stmt = $connection->prepare("
        SELECT id, title, status, start_date
        FROM live_classes
        WHERE teacher_id = ?
        ORDER BY start_date DESC
    ");
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    require_once '../includes/header.php';
    ?>
    <style>
    /* ===== LiveTeach stream selector revamp — scoped to .lt-page ===== */
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
    .lt-page h1, .lt-page h2, .lt-page h3 { font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif; font-weight: 500; }
    .lt-page a { text-decoration: none; color: inherit; }

    .lt-container { max-width: 1180px; margin: 0 auto; padding: 0 24px; }

    .lt-dash-header {
        padding: 56px 0 44px;
        border-bottom: 1px solid var(--lt-line);
        position: relative;
        overflow: hidden;
    }
    .lt-dash-header::before {
        content: "";
        position: absolute;
        top: -220px; right: -180px;
        width: 560px; height: 560px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
        pointer-events: none;
    }
    .lt-dash-header-inner { position: relative; z-index: 1; }
    .lt-eyebrow {
        display: inline-flex; align-items: center; gap: 9px;
        font-size: 0.78rem; letter-spacing: 0.05em; text-transform: uppercase;
        color: var(--lt-parchment-dim);
        border: 1px solid var(--lt-line); padding: 6px 13px;
        border-radius: 999px; margin-bottom: 20px; font-weight: 600;
    }
    .lt-eyebrow-dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: var(--lt-flame-bright);
        animation: lt-pulse 1.8s ease-in-out infinite;
    }
    @keyframes lt-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
        50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
    }
    .lt-dash-header h1 {
        font-size: clamp(1.9rem, 3.4vw, 2.4rem);
        line-height: 1.15; margin: 0 0 10px; color: var(--lt-parchment);
    }
    .lt-dash-header p {
        color: var(--lt-parchment-dim);
        font-size: 0.95rem; margin: 0; line-height: 1.6; max-width: 52ch;
    }

    .lt-selector-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 18px;
        padding: 40px 0 72px;
    }
    .lt-select-card {
        background: var(--lt-charcoal);
        border: 1px solid var(--lt-line);
        border-radius: 12px;
        padding: 22px 24px;
        transition: border-color 0.15s ease, transform 0.15s ease;
        display: flex; flex-direction: column; gap: 12px;
    }
    .lt-select-card:hover {
        border-color: var(--lt-flame);
        transform: translateY(-3px);
    }
    .lt-select-title {
        font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
        font-size: 1.15rem;
        color: var(--lt-parchment);
        margin: 0;
        line-height: 1.3;
    }
    .lt-select-date {
        font-size: 0.82rem;
        color: var(--lt-parchment-dim);
    }
    .lt-status {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 3px 10px; border-radius: 999px;
        font-size: 0.66rem; font-weight: 600;
        letter-spacing: 0.05em; text-transform: uppercase;
    }
    .lt-status-upcoming { background: rgba(217,164,65,0.15); color: var(--lt-gold); border: 1px solid rgba(217,164,65,0.35); }
    .lt-status-ongoing { background: rgba(200,52,30,0.18); color: var(--lt-flame-bright); border: 1px solid rgba(200,52,30,0.45); }
    .lt-status-ongoing .lt-status-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--lt-flame-bright); animation: lt-pulse 1.5s infinite; }
    .lt-status-completed, .lt-status-cancelled { background: rgba(241,231,214,0.06); color: var(--lt-parchment-dim); border: 1px solid var(--lt-line); }

    .lt-empty {
        text-align: center; padding: 72px 32px;
        background: var(--lt-charcoal);
        border: 1px dashed var(--lt-line);
        border-radius: 14px;
    }
    .lt-empty-icon { font-size: 3rem; margin-bottom: 16px; opacity: 0.6; }
    .lt-empty h3 { font-size: 1.3rem; margin: 0 0 10px; color: var(--lt-parchment); }
    .lt-empty p { color: var(--lt-parchment-dim); margin: 0 0 24px; font-size: 0.92rem; }
    .lt-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        padding: 10px 18px; border-radius: 8px;
        font-size: 0.88rem; font-weight: 600;
        border: 1px solid transparent;
        transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        cursor: pointer; font-family: inherit; text-align: center; white-space: nowrap;
    }
    .lt-btn:hover { transform: translateY(-1px); }
    .lt-btn-primary { background: var(--lt-flame); color: var(--lt-parchment); }
    .lt-btn-primary:hover { background: var(--lt-flame-bright); }
    </style>
    <main class="lt-page">
        <div class="lt-dash-header">
            <div class="lt-container lt-dash-header-inner">
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Live &amp; video
                </span>
                <h1>Choose a class.</h1>
                <p>Select a class to manage its live streams and videos.</p>
            </div>
        </div>
        <div class="lt-container">
            <?php if (empty($classes)): ?>
                <div class="lt-empty" style="margin-top: 40px;">
                    <div class="lt-empty-icon">📭</div>
                    <h3>No classes yet</h3>
                    <p>Create a class first to start streaming and uploading videos.</p>
                    <a href="add-class.php" class="lt-btn lt-btn-primary">Create a class</a>
                </div>
            <?php else: ?>
                <div class="lt-selector-grid">
                    <?php foreach ($classes as $class): ?>
                        <a href="?class_id=<?php echo (int)$class['id']; ?>" class="lt-select-card">
                            <h3 class="lt-select-title"><?php echo htmlspecialchars($class['title']); ?></h3>
                            <div class="lt-select-date">📅 <?php echo date('M d, Y', strtotime($class['start_date'])); ?></div>
                            <div>
                                <span class="lt-status lt-status-<?php echo htmlspecialchars($class['status']); ?>">
                                    <?php if ($class['status'] == 'ongoing'): ?><span class="lt-status-dot"></span><?php endif; ?>
                                    <?php echo ucfirst($class['status']); ?>
                                </span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php
    require_once '../includes/footer.php';
    exit;
}

/* ---------------------------------------------------------------
   Class selected — verify ownership
   --------------------------------------------------------------- */
$stmt = $connection->prepare("SELECT * FROM live_classes WHERE id = ? AND teacher_id = ?");
$stmt->bind_param('ii', $classId, $teacherId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    header('Location: classes.php');
    exit;
}

/* Deterministic room name */
$roomName    = "liveteach_class_{$classId}_" . substr(md5($classId . $teacherId), 0, 8);
$jitsiDomain = "meet.jit.si";

/* ---------------------------------------------------------------
   POST HANDLERS
   --------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    /* ---------- Start Jitsi meeting ---------- */
    if (isset($_POST['start_jitsi'])) {
        $meeting_link = "https://{$jitsiDomain}/{$roomName}";
        $platform     = 'jitsi';

        $upd = $connection->prepare("UPDATE live_classes SET meeting_link = ?, status = 'ongoing', stream_platform = ? WHERE id = ? AND teacher_id = ?");
        $upd->bind_param('ssii', $meeting_link, $platform, $classId, $teacherId);
        $upd->execute();
        $upd->close();

        $ins = $connection->prepare("
            INSERT INTO live_streams
              (class_id, teacher_id, stream_url, embed_code, status, platform, scheduled_time, actual_start_time)
            VALUES (?, ?, ?, ?, 'live', ?, NOW(), NOW())
        ");
        $ins->bind_param('iisss', $classId, $teacherId, $meeting_link, $meeting_link, $platform);
        $ins->execute();
        $ins->close();

        header("Location: ?class_id=$classId&live=1&platform=jitsi");
        exit;
    }

    /* ---------- Start Zoom meeting ---------- */
    if (isset($_POST['start_zoom'])) {
        $meeting_link = trim($_POST['zoom_link'] ?? '');
        $platform     = 'zoom';

        if ($meeting_link === '' || !filter_var($meeting_link, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid Zoom meeting link";
        } else {
            $upd = $connection->prepare("UPDATE live_classes SET meeting_link = ?, status = 'ongoing', stream_platform = ? WHERE id = ? AND teacher_id = ?");
            $upd->bind_param('ssii', $meeting_link, $platform, $classId, $teacherId);
            $upd->execute();
            $upd->close();

            $ins = $connection->prepare("
                INSERT INTO live_streams
                  (class_id, teacher_id, stream_url, embed_code, status, platform, scheduled_time, actual_start_time)
                VALUES (?, ?, ?, ?, 'live', ?, NOW(), NOW())
            ");
            $ins->bind_param('iisss', $classId, $teacherId, $meeting_link, $meeting_link, $platform);
            $ins->execute();
            $ins->close();

            header("Location: ?class_id=$classId&live=1&platform=zoom");
            exit;
        }
    }

    /* ---------- Start Google Meet ---------- */
    if (isset($_POST['start_google_meet'])) {
        $meeting_link = trim($_POST['google_link'] ?? '');
        $platform     = 'google_meet';

        if ($meeting_link === '' || !filter_var($meeting_link, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid Google Meet link";
        } else {
            $upd = $connection->prepare("UPDATE live_classes SET meeting_link = ?, status = 'ongoing', stream_platform = ? WHERE id = ? AND teacher_id = ?");
            $upd->bind_param('ssii', $meeting_link, $platform, $classId, $teacherId);
            $upd->execute();
            $upd->close();

            $ins = $connection->prepare("
                INSERT INTO live_streams
                  (class_id, teacher_id, stream_url, embed_code, status, platform, scheduled_time, actual_start_time)
                VALUES (?, ?, ?, ?, 'live', ?, NOW(), NOW())
            ");
            $ins->bind_param('iisss', $classId, $teacherId, $meeting_link, $meeting_link, $platform);
            $ins->execute();
            $ins->close();

            header("Location: ?class_id=$classId&live=1&platform=google_meet");
            exit;
        }
    }

    /* ---------- Start a SCHEDULED stream immediately ---------- */
    if (isset($_POST['start_scheduled'])) {
        $streamId = (int)$_POST['stream_id'];

        /* Verify ownership + that the stream is scheduled */
        $chk = $connection->prepare("
            SELECT ls.* FROM live_streams ls
            JOIN live_classes c ON c.id = ls.class_id
            WHERE ls.id = ? AND c.teacher_id = ?
        ");
        $chk->bind_param('ii', $streamId, $teacherId);
        $chk->execute();
        $stream = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$stream) {
            $error = "Stream not found.";
        } else {
            /* End any other live stream on this class first */
            $end = $connection->prepare("UPDATE live_streams SET status='ended', actual_end_time=NOW() WHERE class_id = ? AND status='live'");
            $end->bind_param('i', $classId);
            $end->execute();
            $end->close();

            /* Promote this scheduled stream to live */
            $upd = $connection->prepare("
                UPDATE live_streams
                SET status = 'live',
                    actual_start_time = NOW()
                WHERE id = ?
            ");
            $upd->bind_param('i', $streamId);
            $upd->execute();
            $upd->close();

            /* Also flip the class to ongoing */
            $upd2 = $connection->prepare("UPDATE live_classes SET status = 'ongoing', meeting_link = ? WHERE id = ? AND teacher_id = ?");
            $upd2->bind_param('sii', $stream['stream_url'], $classId, $teacherId);
            $upd2->execute();
            $upd2->close();

            header("Location: ?class_id=$classId&live=1&platform=" . urlencode($stream['platform'] ?: 'jitsi'));
            exit;
        }
    }

    /* ---------- End live stream ---------- */
    if (isset($_POST['end_stream'])) {
        $streamId = (int)$_POST['stream_id'];

        /* Ownership check — only touch streams on this teacher's class */
        $chk = $connection->prepare("
            SELECT ls.id FROM live_streams ls
            JOIN live_classes c ON c.id = ls.class_id
            WHERE ls.id = ? AND c.teacher_id = ?
        ");
        $chk->bind_param('ii', $streamId, $teacherId);
        $chk->execute();
        $ok = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();

        if ($ok) {
            $upd = $connection->prepare("UPDATE live_streams SET status = 'ended', actual_end_time = NOW() WHERE id = ?");
            $upd->bind_param('i', $streamId);
            $upd->execute();
            $upd->close();

            $upd2 = $connection->prepare("UPDATE live_classes SET status = 'completed' WHERE id = ? AND teacher_id = ?");
            $upd2->bind_param('ii', $classId, $teacherId);
            $upd2->execute();
            $upd2->close();
        }

        header("Location: ?class_id=$classId");
        exit;
    }

    /* ---------- Schedule a future stream ---------- */
    if (isset($_POST['schedule_stream'])) {
        $stream_link    = trim($_POST['stream_link'] ?? '');
        $platform       = $_POST['platform'] ?? 'jitsi';
        $scheduled_time = trim($_POST['scheduled_time'] ?? '');

        if ($stream_link === '') {
            $error = "Please enter a meeting link";
        } elseif (!in_array($platform, ['jitsi', 'zoom', 'google_meet', 'custom'], true)) {
            $error = "Invalid platform";
        } elseif ($scheduled_time === '') {
            $error = "Please select a date and time";
        } else {
            /* Normalise "YYYY-MM-DDTHH:MM" to MySQL DATETIME */
            $scheduled_time = str_replace('T', ' ', $scheduled_time) . (strlen($scheduled_time) === 16 ? ':00' : '');

            $ins = $connection->prepare("
                INSERT INTO live_streams
                  (class_id, teacher_id, stream_url, embed_code, status, platform, scheduled_time)
                VALUES (?, ?, ?, ?, 'scheduled', ?, ?)
            ");
            $ins->bind_param('iissss', $classId, $teacherId, $stream_link, $stream_link, $platform, $scheduled_time);
            if ($ins->execute()) {
                $msg = "Live stream scheduled successfully!";
            } else {
                $error = "Failed to schedule stream";
            }
            $ins->close();
        }
    }

    /* ---------- Delete stream ---------- */
    if (isset($_POST['delete_stream'])) {
        $streamId = (int)$_POST['stream_id'];

        $chk = $connection->prepare("
            SELECT ls.id FROM live_streams ls
            JOIN live_classes c ON c.id = ls.class_id
            WHERE ls.id = ? AND c.teacher_id = ?
        ");
        $chk->bind_param('ii', $streamId, $teacherId);
        $chk->execute();
        $ok = (bool)$chk->get_result()->fetch_assoc();
        $chk->close();

        if ($ok) {
            $del = $connection->prepare("DELETE FROM live_streams WHERE id = ?");
            $del->bind_param('i', $streamId);
            $del->execute();
            $del->close();
            $msg = "Stream removed";
        } else {
            $error = "Stream not found";
        }
    }

    /* ---------- Delete video ---------- */
    if (isset($_POST['delete_video'])) {
        $videoId = (int)$_POST['video_id'];

        $chk = $connection->prepare("
            SELECT v.* FROM videos v
            JOIN live_classes c ON c.id = v.class_id
            WHERE v.id = ? AND c.teacher_id = ?
        ");
        $chk->bind_param('ii', $videoId, $teacherId);
        $chk->execute();
        $video = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($video) {
            /* Only delete files that live inside our own uploads/videos/ dir */
            if (strpos($video['video_url'], 'uploads/videos/') === 0) {
                $filepath = dirname(__DIR__) . '/' . $video['video_url'];
                $realUploadDir = realpath(dirname(__DIR__) . '/uploads/videos');
                $realFile      = realpath($filepath);
                if ($realUploadDir && $realFile && strpos($realFile, $realUploadDir) === 0 && is_file($realFile)) {
                    @unlink($realFile);
                }
            }

            $del = $connection->prepare("DELETE FROM videos WHERE id = ?");
            $del->bind_param('i', $videoId);
            $del->execute();
            $del->close();
            $msg = "Video deleted";
        } else {
            $error = "Video not found";
        }
    }
}

/* ---------------------------------------------------------------
   Load data for render
   --------------------------------------------------------------- */

/* Currently active stream */
$stmt = $connection->prepare("
    SELECT * FROM live_streams
    WHERE class_id = ? AND status = 'live'
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$activeStream = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* All streams (with reminder flags) */
$stmt = $connection->prepare("
    SELECT * FROM live_streams
    WHERE class_id = ?
    ORDER BY scheduled_time DESC
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Videos */
$stmt = $connection->prepare("
    SELECT * FROM videos
    WHERE class_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$showLiveEmbed   = isset($_GET['live']) && $activeStream;
$currentPlatform = isset($_GET['platform']) ? $_GET['platform'] : ($activeStream['platform'] ?? 'jitsi');

/* Precompute per-scheduled-stream reminder status */
$now = new DateTime();

require_once '../includes/header.php';
?>

<style>
/* ===== LiveTeach live-stream revamp — scoped to .lt-page ===== */
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
    top: -220px; right: -180px;
    width: 560px; height: 560px;
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
    display: inline-flex; align-items: center; gap: 9px;
    font-size: 0.78rem; letter-spacing: 0.05em; text-transform: uppercase;
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line); padding: 6px 13px;
    border-radius: 999px; margin-bottom: 20px; font-weight: 600;
}
.lt-eyebrow-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.8s ease-in-out infinite;
}
@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}
.lt-dash-header h1 {
    font-size: clamp(1.9rem, 3.4vw, 2.4rem);
    line-height: 1.15; margin: 0 0 10px; color: var(--lt-parchment);
}
.lt-dash-header p {
    color: var(--lt-parchment-dim);
    font-size: 0.95rem; margin: 0; line-height: 1.6; max-width: 56ch;
}
.lt-dash-header p strong { color: var(--lt-gold); font-weight: 500; }
.lt-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 11px 20px; border-radius: 8px;
    font-size: 0.88rem; font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    cursor: pointer; font-family: inherit; text-align: center; white-space: nowrap;
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
.lt-btn-block { width: 100%; }
.lt-btn-sm { padding: 8px 14px; font-size: 0.8rem; }

/* ---------- Alerts ---------- */
.lt-alert {
    border-radius: 10px; padding: 14px 18px; margin: 32px 0 20px;
    font-size: 0.9rem; line-height: 1.5;
    border: 1px solid transparent;
    display: flex; align-items: flex-start; gap: 10px;
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

/* ---------- Live section ---------- */
.lt-live {
    background: var(--lt-charcoal);
    border: 1px solid rgba(200, 52, 30, 0.45);
    border-radius: 14px;
    padding: 24px 24px 22px;
    margin: 32px 0 24px;
    position: relative;
    overflow: hidden;
}
.lt-live::before {
    content: "";
    position: absolute;
    top: -140px; right: -120px;
    width: 340px; height: 340px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
}
.lt-live-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px; flex-wrap: wrap;
    margin-bottom: 20px;
    position: relative; z-index: 1;
}
.lt-live-badge {
    display: inline-flex; align-items: center; gap: 10px;
    background: rgba(200, 52, 30, 0.18);
    border: 1px solid rgba(200, 52, 30, 0.5);
    color: var(--lt-flame-bright);
    padding: 8px 16px; border-radius: 999px;
    font-size: 0.78rem; font-weight: 700;
    letter-spacing: 0.06em; text-transform: uppercase;
}
.lt-live-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.5s infinite;
}
.lt-jitsi-frame {
    width: 100%; height: 560px;
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    background: #000; display: block;
    position: relative; z-index: 1;
}
.lt-link-box {
    margin-top: 16px; padding: 16px 18px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    position: relative; z-index: 1;
}
.lt-link-box p { margin: 0 0 10px; font-size: 0.85rem; color: var(--lt-parchment-dim); }
.lt-link-box code {
    display: block;
    background: var(--lt-charcoal-raised);
    padding: 10px 12px; border-radius: 6px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.82rem; color: var(--lt-parchment);
    word-break: break-all; margin-bottom: 12px;
}
.lt-link-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ---------- Tabs ---------- */
.lt-tabs {
    display: flex; gap: 4px;
    border-bottom: 1px solid var(--lt-line);
    margin-bottom: 28px;
    overflow-x: auto;
    padding-bottom: 0;
}
.lt-tab-btn {
    background: transparent; border: none;
    color: var(--lt-parchment-dim);
    padding: 12px 18px;
    font-size: 0.88rem; font-weight: 500;
    font-family: inherit; cursor: pointer;
    position: relative; transition: color 0.15s ease;
    white-space: nowrap;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
}
.lt-tab-btn:hover { color: var(--lt-parchment); }
.lt-tab-btn.lt-active {
    color: var(--lt-gold);
    border-bottom-color: var(--lt-flame);
}
.lt-tab-content { display: none; padding-bottom: 72px; }
.lt-tab-content.lt-active { display: block; }

/* ---------- Section head ---------- */
.lt-section-head {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 22px; padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
}
.lt-section-head h2 { font-size: 1.2rem; margin: 0; color: var(--lt-parchment); }
.lt-section-head .lt-num {
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.72rem; color: var(--lt-gold);
    letter-spacing: 0.08em; text-transform: uppercase; font-weight: 600;
}

/* ---------- Platform cards ---------- */
.lt-platform-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px; margin-bottom: 26px;
}
.lt-platform-card {
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 24px 22px; text-align: center;
    cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease, background 0.15s ease;
}
.lt-platform-card:hover { border-color: var(--lt-gold); transform: translateY(-2px); }
.lt-platform-card.lt-selected { border-color: var(--lt-flame); background: var(--lt-charcoal); }
.lt-platform-icon { font-size: 2.2rem; margin-bottom: 14px; display: block; }
.lt-platform-name {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.05rem; color: var(--lt-parchment); margin-bottom: 8px;
}
.lt-platform-desc { font-size: 0.78rem; color: var(--lt-parchment-dim); line-height: 1.5; }

/* ---------- Form panel ---------- */
.lt-panel {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 28px 26px;
}
.lt-field { margin-bottom: 20px; }
.lt-field:last-child { margin-bottom: 0; }
.lt-field label {
    display: block;
    font-size: 0.72rem; letter-spacing: 0.05em;
    text-transform: uppercase; color: var(--lt-parchment-dim);
    margin-bottom: 8px; font-weight: 600;
}
.lt-field label .lt-req { color: var(--lt-flame-bright); margin-left: 2px; }
.lt-field input[type="url"],
.lt-field input[type="datetime-local"],
.lt-field select {
    width: 100%; padding: 12px 14px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.92rem; font-family: inherit;
    transition: border-color 0.15s ease;
    box-sizing: border-box;
}
.lt-field input::placeholder { color: rgba(201, 190, 172, 0.45); }
.lt-field input:focus,
.lt-field select:focus { outline: none; border-color: var(--lt-flame); }
.lt-field select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%3E%3Cpath%20fill%3D%22%23C9BEAC%22%20d%3D%22M6%208L0%200h12z%22%2F%3E%3C%2Fsvg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 38px;
}
.lt-field-help {
    font-size: 0.78rem; color: var(--lt-parchment-dim);
    margin-top: 8px; line-height: 1.55;
}
.lt-info-block {
    margin-top: 24px; padding: 18px 20px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    font-size: 0.85rem; color: var(--lt-parchment-dim);
    line-height: 1.7;
}
.lt-info-block strong { color: var(--lt-gold); font-weight: 600; }

/* ---------- Video grid ---------- */
.lt-video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 18px;
}
.lt-video {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    overflow: hidden; cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease;
    display: flex; flex-direction: column;
}
.lt-video:hover { border-color: var(--lt-flame); transform: translateY(-3px); }
.lt-video-thumb {
    position: relative; background: #000;
    height: 170px; overflow: hidden;
}
.lt-video-thumb img,
.lt-video-thumb video,
.lt-video-thumb iframe {
    width: 100%; height: 100%;
    object-fit: cover; border: none;
    display: block; pointer-events: none;
}
.lt-video-overlay {
    position: absolute; inset: 0;
    background: rgba(16, 13, 10, 0.55);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity 0.2s ease;
}
.lt-video:hover .lt-video-overlay { opacity: 1; }
.lt-play-icon {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--lt-flame); color: var(--lt-parchment);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; padding-left: 3px;
}
.lt-video-body {
    padding: 14px 16px 16px;
    display: flex; flex-direction: column; gap: 10px; flex: 1;
}
.lt-video-title { font-size: 0.95rem; color: var(--lt-parchment); margin: 0; line-height: 1.35; }
.lt-video-meta {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 0.72rem; color: var(--lt-parchment-dim); gap: 10px;
}
.lt-video-tag {
    font-size: 0.65rem; font-weight: 600;
    letter-spacing: 0.05em; text-transform: uppercase;
    color: var(--lt-gold);
    background: rgba(217, 164, 65, 0.12);
    border: 1px solid rgba(217, 164, 65, 0.35);
    padding: 3px 8px; border-radius: 999px;
}
.lt-video-actions {
    display: flex; gap: 8px;
    margin-top: auto; padding-top: 12px;
    border-top: 1px solid var(--lt-line);
}
.lt-video-actions .lt-btn { flex: 1; }

/* ---------- Stream rows ---------- */
.lt-stream-row {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 10px;
    padding: 18px 20px; margin-bottom: 12px;
    display: flex; justify-content: space-between;
    align-items: center; gap: 16px; flex-wrap: wrap;
}
.lt-stream-row:last-child { margin-bottom: 0; }
.lt-stream-main { min-width: 0; flex: 1; }
.lt-stream-when {
    font-size: 0.92rem; color: var(--lt-parchment);
    font-weight: 500; margin: 0 0 6px;
}
.lt-stream-meta {
    font-size: 0.78rem; color: var(--lt-parchment-dim);
    line-height: 1.6;
}
.lt-stream-meta a {
    color: var(--lt-gold);
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.76rem;
    word-break: break-all;
}
.lt-stream-meta a:hover { color: var(--lt-flame-bright); }
.lt-stream-actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }

/* Reminder status strip */
.lt-reminder-status {
    display: inline-flex; gap: 10px; flex-wrap: wrap;
    margin-top: 8px;
    font-size: 0.72rem;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    color: var(--lt-parchment-dim);
}
.lt-reminder-status span {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; border-radius: 999px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
}
.lt-reminder-status span.lt-sent {
    color: var(--lt-gold);
    border-color: rgba(217, 164, 65, 0.4);
    background: rgba(217, 164, 65, 0.12);
}
.lt-reminder-status span.lt-pending { color: var(--lt-parchment-dim); }

/* Starts-in countdown */
.lt-starts-in {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 0.74rem;
    color: var(--lt-gold);
    background: rgba(217, 164, 65, 0.12);
    border: 1px solid rgba(217, 164, 65, 0.35);
    padding: 3px 10px;
    border-radius: 999px;
    margin-left: 8px;
}
.lt-starts-in.lt-live {
    color: var(--lt-flame-bright);
    background: rgba(200, 52, 30, 0.18);
    border-color: rgba(200, 52, 30, 0.5);
}

/* ---------- Empty state ---------- */
.lt-empty {
    text-align: center;
    padding: 60px 32px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 14px;
}
.lt-empty-icon { font-size: 2.6rem; margin-bottom: 14px; opacity: 0.6; }
.lt-empty h3 { font-size: 1.2rem; margin: 0 0 10px; color: var(--lt-parchment); }
.lt-empty p {
    color: var(--lt-parchment-dim);
    margin: 0 0 20px; font-size: 0.9rem; line-height: 1.6;
}

/* ---------- Video modal ---------- */
.lt-modal {
    display: none; position: fixed; inset: 0;
    background: rgba(16, 13, 10, 0.94);
    z-index: 2000;
    align-items: center; justify-content: center;
    padding: 24px;
}
.lt-modal.lt-open { display: flex; }
.lt-modal-box {
    width: 100%; max-width: 1100px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 14px; overflow: hidden;
}
.lt-modal-head {
    padding: 16px 22px;
    background: var(--lt-charcoal-raised);
    border-bottom: 1px solid var(--lt-line);
    display: flex; justify-content: space-between;
    align-items: center; gap: 16px;
}
.lt-modal-title { margin: 0; font-size: 1rem; color: var(--lt-parchment); }
.lt-modal-close {
    background: transparent; border: 1px solid var(--lt-line);
    color: var(--lt-parchment);
    width: 34px; height: 34px; border-radius: 50%;
    cursor: pointer; font-size: 1.1rem; line-height: 1;
    transition: border-color 0.15s ease, color 0.15s ease;
    flex-shrink: 0;
}
.lt-modal-close:hover { border-color: var(--lt-flame); color: var(--lt-flame-bright); }
.lt-modal-body { padding: 20px 22px 22px; }
.lt-modal-body iframe,
.lt-modal-body video {
    width: 100%; min-height: 520px;
    border: none; border-radius: 10px;
    background: #000; display: block;
}

/* ---------- Responsive ---------- */
@media (max-width: 900px) {
    .lt-dash-header-inner { flex-direction: column; align-items: flex-start; }
    .lt-jitsi-frame { height: 420px; }
    .lt-modal-body iframe,
    .lt-modal-body video { min-height: 320px; }
}
@media (max-width: 560px) {
    .lt-dash-header { padding: 40px 0 32px; }
    .lt-panel { padding: 22px 20px; }
    .lt-live { padding: 20px 18px; }
    .lt-jitsi-frame { height: 340px; }
    .lt-stream-row { flex-direction: column; align-items: stretch; }
    .lt-stream-row .lt-btn { width: 100%; }
    .lt-stream-actions { width: 100%; }
    .lt-stream-actions .lt-btn { flex: 1; }
    .lt-video-actions { flex-direction: column; }
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
            <div>
                <span class="lt-eyebrow">
                    <span class="lt-eyebrow-dot"></span>
                    Live &amp; video
                </span>
                <h1><?php echo htmlspecialchars($class['title']); ?></h1>
                <p>Start a live meeting, schedule a future session, or manage recorded videos for this class.</p>
            </div>
            <div class="lt-header-actions">
                <a href="dashboard.php" class="lt-btn lt-btn-outline">← Dashboard</a>
                <a href="upload-video.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary">📤 Upload video</a>
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
        <?php if ($error): ?>
            <div class="lt-alert lt-alert-error">
                <span>⚠️</span><span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Live section -->
        <?php if ($showLiveEmbed && $activeStream): ?>
            <div class="lt-live" style="margin-top: 32px;">
                <div class="lt-live-header">
                    <span class="lt-live-badge">
                        <span class="lt-live-dot"></span>
                        Live now · <?php echo ucfirst(str_replace('_', ' ', $currentPlatform)); ?>
                    </span>
                    <form method="post" style="margin: 0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="stream_id" value="<?php echo (int)$activeStream['id']; ?>">
                        <button type="submit" name="end_stream" class="lt-btn lt-btn-danger" onclick="return confirm('End this live stream?');">
                            🛑 End stream
                        </button>
                    </form>
                </div>

                <?php if ($currentPlatform == 'jitsi'): ?>
                    <iframe
                        class="lt-jitsi-frame"
                        src="https://<?php echo htmlspecialchars($jitsiDomain); ?>/<?php echo htmlspecialchars($roomName); ?>#config.prejoinPageEnabled=false&userInfo.displayName=<?php echo urlencode($teacherName . ' (Teacher)'); ?>"
                        allow="camera; microphone; fullscreen; display-capture"
                        allowfullscreen>
                    </iframe>
                <?php else: ?>
                    <div class="lt-link-box">
                        <p>🔗 <strong>Your meeting is live</strong></p>
                        <code><?php echo htmlspecialchars($activeStream['stream_url']); ?></code>
                        <div class="lt-link-actions">
                            <a href="<?php echo htmlspecialchars($activeStream['stream_url']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-gold">
                                🚀 Join <?php echo ucfirst(str_replace('_', ' ', $currentPlatform)); ?> meeting
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="lt-link-box">
                    <p>🔗 <strong>Share this link with students</strong></p>
                    <code><?php echo htmlspecialchars($activeStream['stream_url']); ?></code>
                    <div class="lt-link-actions">
                        <span style="font-size: 0.78rem; color: var(--lt-parchment-dim); align-self: center;">💡 Students can also join from the class page.</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="lt-tabs" style="margin-top: 32px;">
            <button type="button" class="lt-tab-btn <?php echo !$showLiveEmbed ? 'lt-active' : ''; ?>" onclick="switchTab('videos')">📹 Class videos</button>
            <button type="button" class="lt-tab-btn" onclick="switchTab('start')">🚀 Start meeting</button>
            <button type="button" class="lt-tab-btn" onclick="switchTab('streams')">📅 Scheduled</button>
            <button type="button" class="lt-tab-btn" onclick="switchTab('schedule')">📆 Schedule</button>
        </div>

        <!-- Videos tab -->
        <div id="videos-tab" class="lt-tab-content <?php echo !$showLiveEmbed ? 'lt-active' : ''; ?>">
            <div class="lt-section-head">
                <span class="lt-num">01</span>
                <h2>Class videos</h2>
            </div>

            <?php if (empty($videos)): ?>
                <div class="lt-empty">
                    <div class="lt-empty-icon">🎬</div>
                    <h3>No videos added yet</h3>
                    <p>Upload lesson recordings, previews, or supplementary material for your students.</p>
                    <a href="upload-video.php?class_id=<?php echo (int)$classId; ?>" class="lt-btn lt-btn-primary">Upload your first video</a>
                </div>
            <?php else: ?>
                <div class="lt-video-grid">
                    <?php foreach ($videos as $video):
                        $embed_url = '';
                        $is_local  = false;

                        if (strpos($video['video_url'], 'uploads/') === 0) {
                            $embed_url = '../' . $video['video_url'];
                            $is_local  = true;
                        } elseif (strpos($video['video_url'], 'youtube.com') !== false || strpos($video['video_url'], 'youtu.be') !== false) {
                            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video['video_url'], $matches);
                            $video_id  = $matches[1] ?? '';
                            $embed_url = "https://www.youtube.com/embed/" . $video_id;
                        }
                    ?>
                        <div class="lt-video" onclick="openVideoPlayer('<?php echo htmlspecialchars($embed_url, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($video['title'], ENT_QUOTES); ?>', <?php echo $is_local ? 'true' : 'false'; ?>)">
                            <div class="lt-video-thumb">
                                <?php if ($video['thumbnail']): ?>
                                    <img src="../<?php echo htmlspecialchars($video['thumbnail']); ?>" alt="">
                                <?php elseif ($is_local): ?>
                                    <video src="<?php echo htmlspecialchars($embed_url); ?>"></video>
                                <?php elseif ($embed_url): ?>
                                    <iframe src="<?php echo htmlspecialchars($embed_url); ?>" frameborder="0"></iframe>
                                <?php else: ?>
                                    <div style="display:flex; align-items:center; justify-content:center; height:100%; font-size:2rem; color: var(--lt-parchment-dim);">🎬</div>
                                <?php endif; ?>
                                <div class="lt-video-overlay"><div class="lt-play-icon">▶</div></div>
                            </div>
                            <div class="lt-video-body">
                                <h3 class="lt-video-title"><?php echo htmlspecialchars($video['title']); ?></h3>
                                <div class="lt-video-meta">
                                    <span><?php if ($video['is_preview']): ?><span class="lt-video-tag">⭐ Preview</span><?php endif; ?></span>
                                    <span>📅 <?php echo date('M d, Y', strtotime($video['created_at'])); ?></span>
                                </div>
                                <div class="lt-video-actions" onclick="event.stopPropagation()">
                                    <button type="button" onclick="openVideoPlayer('<?php echo htmlspecialchars($embed_url, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($video['title'], ENT_QUOTES); ?>', <?php echo $is_local ? 'true' : 'false'; ?>)" class="lt-btn lt-btn-primary lt-btn-sm">
                                        ▶️ Watch
                                    </button>
                                    <form method="post" onsubmit="return confirm('Delete this video?');" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="video_id" value="<?php echo (int)$video['id']; ?>">
                                        <button type="submit" name="delete_video" class="lt-btn lt-btn-danger lt-btn-sm">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Start meeting tab -->
        <div id="start-tab" class="lt-tab-content">
            <div class="lt-section-head">
                <span class="lt-num">02</span>
                <h2>Start a live meeting</h2>
            </div>

            <div class="lt-platform-grid">
                <div class="lt-platform-card" onclick="selectPlatform('jitsi')" id="platform-jitsi">
                    <span class="lt-platform-icon">🎥</span>
                    <div class="lt-platform-name">Jitsi Meet</div>
                    <div class="lt-platform-desc">Free, open-source, no account needed</div>
                </div>
                <div class="lt-platform-card" onclick="selectPlatform('zoom')" id="platform-zoom">
                    <span class="lt-platform-icon">💻</span>
                    <div class="lt-platform-name">Zoom</div>
                    <div class="lt-platform-desc">Professional video conferencing</div>
                </div>
                <div class="lt-platform-card" onclick="selectPlatform('google')" id="platform-google">
                    <span class="lt-platform-icon">🔵</span>
                    <div class="lt-platform-name">Google Meet</div>
                    <div class="lt-platform-desc">Secure, integrated with Google</div>
                </div>
            </div>

            <div id="jitsi-form">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="start_jitsi" class="lt-btn lt-btn-primary lt-btn-block" style="padding: 14px 24px; font-size: 0.95rem;">
                        🚀 Start Jitsi meeting now
                    </button>
                </form>
            </div>

            <div id="zoom-form" style="display: none;">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="lt-field">
                        <label for="zoom_link">Zoom meeting link <span class="lt-req">*</span></label>
                        <input type="url" id="zoom_link" name="zoom_link" required placeholder="https://zoom.us/j/123456789?pwd=xxxx">
                    </div>
                    <button type="submit" name="start_zoom" class="lt-btn lt-btn-primary lt-btn-block" style="padding: 14px 24px; font-size: 0.95rem;">
                        🚀 Start Zoom meeting
                    </button>
                </form>
            </div>

            <div id="google-form" style="display: none;">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="lt-field">
                        <label for="google_link">Google Meet link <span class="lt-req">*</span></label>
                        <input type="url" id="google_link" name="google_link" required placeholder="https://meet.google.com/xxx-xxxx-xxx">
                    </div>
                    <button type="submit" name="start_google_meet" class="lt-btn lt-btn-primary lt-btn-block" style="padding: 14px 24px; font-size: 0.95rem;">
                        🚀 Start Google Meet
                    </button>
                </form>
            </div>

            <div class="lt-info-block">
                💡 <strong>How to use</strong><br>
                • <strong>Jitsi:</strong> Click "Start Jitsi meeting" — works immediately, no account required.<br>
                • <strong>Zoom:</strong> Create a meeting on Zoom, copy the link, paste it here, then Start.<br>
                • <strong>Google Meet:</strong> Create a meeting on Google Meet, copy the link, paste it here, then Start.<br>
                • <strong>To end:</strong> Click the red "End stream" button at the top of this page.<br>
                • <strong>Reminders:</strong> Students enrolled in this class are automatically notified 30 minutes and 5 minutes before a scheduled stream begins.
            </div>
        </div>

        <!-- Scheduled streams tab -->
        <div id="streams-tab" class="lt-tab-content">
            <div class="lt-section-head">
                <span class="lt-num">03</span>
                <h2>Scheduled streams</h2>
            </div>

            <?php
            $scheduledStreams = array_filter($streams, function ($s) { return $s['status'] == 'scheduled'; });
            if (empty($scheduledStreams)): ?>
                <div class="lt-empty">
                    <div class="lt-empty-icon">📅</div>
                    <h3>No scheduled streams</h3>
                    <p>Schedule a future session from the "Schedule" tab to plan ahead.</p>
                </div>
            <?php else: ?>
                <?php foreach ($scheduledStreams as $stream):
                    $scheduled = new DateTime($stream['scheduled_time']);
                    $diff      = $now->diff($scheduled);
                    $isFuture  = $scheduled > $now;
                    $isStarted = !$isFuture;

                    /* Build a human countdown */
                    $totalMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
                    if (!$isFuture) {
                        $countdown = 'Started';
                    } elseif ($totalMinutes < 60) {
                        $countdown = $totalMinutes . ' min';
                    } elseif ($totalMinutes < 1440) {
                        $hours = floor($totalMinutes / 60);
                        $mins  = $totalMinutes % 60;
                        $countdown = $hours . 'h' . ($mins > 0 ? ' ' . $mins . 'm' : '');
                    } else {
                        $countdown = $diff->days . ' day' . ($diff->days != 1 ? 's' : '');
                    }
                ?>
                    <div class="lt-stream-row">
                        <div class="lt-stream-main">
                            <div class="lt-stream-when">
                                📅 <?php echo date('l, M d, Y', strtotime($stream['scheduled_time'])); ?> at <?php echo date('h:i A', strtotime($stream['scheduled_time'])); ?>
                                <span class="lt-starts-in <?php echo !$isFuture ? 'lt-live' : ''; ?>">
                                    <?php echo !$isFuture ? '🔴 Ready to start' : '⏱ Starts in ' . $countdown; ?>
                                </span>
                            </div>
                            <div class="lt-stream-meta">
                                Platform: <?php echo ucfirst(str_replace('_', ' ', $stream['platform'] ?? 'Jitsi')); ?><br>
                                🔗 <a href="<?php echo htmlspecialchars($stream['stream_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(substr($stream['stream_url'], 0, 60)); ?><?php echo strlen($stream['stream_url']) > 60 ? '…' : ''; ?></a>
                            </div>
                            <div class="lt-reminder-status">
                                <span class="<?php echo !empty($stream['notify_30min_sent']) ? 'lt-sent' : 'lt-pending'; ?>">
                                    <?php echo !empty($stream['notify_30min_sent']) ? '✓ 30-min reminder sent' : '○ 30-min reminder pending'; ?>
                                </span>
                                <span class="<?php echo !empty($stream['notify_5min_sent']) ? 'lt-sent' : 'lt-pending'; ?>">
                                    <?php echo !empty($stream['notify_5min_sent']) ? '✓ 5-min reminder sent' : '○ 5-min reminder pending'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="lt-stream-actions">
                            <?php if ($isStarted): ?>
                                <form method="post" style="margin: 0;" onsubmit="return confirm('Start this meeting now? Students will be able to join immediately.');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="stream_id" value="<?php echo (int)$stream['id']; ?>">
                                    <button type="submit" name="start_scheduled" class="lt-btn lt-btn-primary lt-btn-sm">
                                        🚀 Start now
                                    </button>
                                </form>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($stream['stream_url']); ?>" target="_blank" rel="noopener" class="lt-btn lt-btn-outline lt-btn-sm">
                                    🔗 Open link
                                </a>
                            <?php endif; ?>

                            <form method="post" onsubmit="return confirm('Delete this scheduled stream?');" style="margin: 0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="stream_id" value="<?php echo (int)$stream['id']; ?>">
                                <button type="submit" name="delete_stream" class="lt-btn lt-btn-danger lt-btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Schedule tab -->
        <div id="schedule-tab" class="lt-tab-content">
            <div class="lt-section-head">
                <span class="lt-num">04</span>
                <h2>Schedule a future stream</h2>
            </div>

            <div class="lt-panel">
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <div class="lt-field">
                        <label for="platform">Platform</label>
                        <select id="platform" name="platform" required>
                            <option value="jitsi">🎥 Jitsi Meet</option>
                            <option value="zoom">💻 Zoom</option>
                            <option value="google_meet">🔵 Google Meet</option>
                        </select>
                    </div>

                    <div class="lt-field">
                        <label for="stream_link">Meeting link <span class="lt-req">*</span></label>
                        <input type="url" id="stream_link" name="stream_link" required placeholder="https://meet.jit.si/room-name">
                        <div class="lt-field-help">Paste the meeting link students will use to join.</div>
                    </div>

                    <div class="lt-field">
                        <label for="scheduled_time">Date &amp; time <span class="lt-req">*</span></label>
                        <input type="datetime-local" id="scheduled_time" name="scheduled_time" required>
                        <div class="lt-field-help">
                            Students enrolled in this class will automatically receive in-app reminders
                            <strong style="color:var(--lt-gold);">30 minutes</strong> and
                            <strong style="color:var(--lt-gold);">5 minutes</strong> before this time.
                        </div>
                    </div>

                    <button type="submit" name="schedule_stream" class="lt-btn lt-btn-primary" style="margin-top: 8px;">
                        📆 Schedule stream
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<!-- Video modal -->
<div id="videoModal" class="lt-modal">
    <div class="lt-modal-box">
        <div class="lt-modal-head">
            <h3 class="lt-modal-title" id="modalTitle">Video player</h3>
            <button type="button" class="lt-modal-close" onclick="closeVideoPlayer()">×</button>
        </div>
        <div class="lt-modal-body">
            <div id="modalVideoContainer"></div>
        </div>
    </div>
</div>

<script>
let selectedPlatform = 'jitsi';

function selectPlatform(platform) {
    selectedPlatform = platform;

    document.querySelectorAll('.lt-platform-card').forEach(card => card.classList.remove('lt-selected'));
    const card = document.getElementById(`platform-${platform}`);
    if (card) card.classList.add('lt-selected');

    const jitsi  = document.getElementById('jitsi-form');
    const zoom   = document.getElementById('zoom-form');
    const google = document.getElementById('google-form');
    if (jitsi)  jitsi.style.display  = platform === 'jitsi'  ? 'block' : 'none';
    if (zoom)   zoom.style.display   = platform === 'zoom'   ? 'block' : 'none';
    if (google) google.style.display = platform === 'google' ? 'block' : 'none';
}

function switchTab(tabName) {
    document.querySelectorAll('.lt-tab-content').forEach(tab => tab.classList.remove('lt-active'));
    document.querySelectorAll('.lt-tab-btn').forEach(btn => btn.classList.remove('lt-active'));

    const target = document.getElementById(tabName + '-tab');
    if (target) target.classList.add('lt-active');

    const tabMap = { 'videos': 0, 'start': 1, 'streams': 2, 'schedule': 3 };
    const buttons = document.querySelectorAll('.lt-tab-btn');
    if (tabMap[tabName] !== undefined && buttons[tabMap[tabName]]) {
        buttons[tabMap[tabName]].classList.add('lt-active');
    }
}

function openVideoPlayer(url, title, isLocal) {
    /* Basic URL sanity — reject javascript:, data:, etc. */
    if (!/^https?:\/\//i.test(url) && url.indexOf('../uploads/') !== 0) {
        console.warn('Blocked unsafe media URL:', url);
        return;
    }

    const modal = document.getElementById('videoModal');
    const modalTitle = document.getElementById('modalTitle');
    const container = document.getElementById('modalVideoContainer');

    modalTitle.textContent = title;

    /* Build elements instead of innerHTML so nothing user-controlled ends up as HTML */
    container.innerHTML = '';
    if (isLocal) {
        const v = document.createElement('video');
        v.src = url;
        v.controls = true;
        v.autoplay = true;
        container.appendChild(v);
    } else {
        const f = document.createElement('iframe');
        f.src = url;
        f.setAttribute('frameborder', '0');
        f.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
        f.setAttribute('allowfullscreen', '');
        container.appendChild(f);
    }

    modal.classList.add('lt-open');
}

function closeVideoPlayer() {
    const modal = document.getElementById('videoModal');
    const container = document.getElementById('modalVideoContainer');
    modal.classList.remove('lt-open');
    container.innerHTML = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeVideoPlayer();
});

/* Init — only if the platform cards exist on this page */
if (document.getElementById('platform-jitsi')) {
    selectPlatform('jitsi');
}
</script>

<?php require_once '../includes/footer.php'; ?>