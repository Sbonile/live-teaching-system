<?php
// Start session at the VERY beginning - NO whitespace before this tag
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Load DB config FIRST — getDbConnection() must exist before use
require_once __DIR__ . '/../config/database.php';

// 2. Load notification system
require_once __DIR__ . '/notifications.php';

// 3. Shared connection
if (!isset($connection) || !$connection) {
    $connection = getDbConnection();
}

// 4. Shared notification system
if (!isset($notificationSystem) || !$notificationSystem) {
    $notificationSystem = new NotificationSystem($connection);
}

// 5. Get current user
$user = $_SESSION['user'] ?? null;

// 6. Determine base path — reliable version
if (!defined('APP_BASE')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $marker     = '/live-teaching-system';
    if (strpos($scriptName, $marker) === 0) {
        define('APP_BASE', $marker);
    } else {
        $dir = str_replace('\\', '/', dirname($scriptName));
        define('APP_BASE', rtrim($dir, '/'));
    }
}
$basePath = APP_BASE . '/';

// 7. Load unread notifications (only if logged in)
$unreadCount         = 0;
$unreadNotifications = [];

if ($user) {
    $unreadCount         = $notificationSystem->getUnreadCount((int)$user['id']);
    $unreadNotifications = $notificationSystem->getUnread((int)$user['id'], 5);
}

// 8. Detect imminent live sessions for students
//    Sets $imminentSession = [...] or null.
//    The bell renders an urgent "Join now" state from this.
$imminentSession = null;

if ($user && ($user['role'] ?? '') === 'student') {
    $stmt = @$connection->prepare("
        SELECT
            ls.status        AS stream_status,
            ls.stream_url,
            ls.platform,
            ls.scheduled_time,
            c.id             AS class_id,
            c.title          AS class_title,
            u.fullname       AS teacher_name
        FROM live_streams ls
        JOIN live_classes c ON c.id = ls.class_id
        JOIN enrollments e  ON e.class_id = c.id
        LEFT JOIN users u   ON u.id = c.teacher_id
        WHERE e.student_id = ?
          AND e.payment_status = 'paid'
          AND (
                ls.status = 'live'
             OR (ls.status = 'scheduled'
                 AND ls.scheduled_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 15 MINUTE))
          )
        ORDER BY (ls.status = 'live') DESC, ls.scheduled_time ASC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $isLive = ($row['stream_status'] === 'live');

            $startsIn = null;
            if (!$isLive && !empty($row['scheduled_time'])) {
                $diff     = strtotime($row['scheduled_time']) - time();
                $startsIn = max(0, (int)ceil($diff / 60));
            }

            $imminentSession = [
                'class_id'      => (int)$row['class_id'],
                'class_title'   => (string)$row['class_title'],
                'teacher_name'  => (string)($row['teacher_name'] ?? 'Your teacher'),
                'meeting_link'  => (string)$row['stream_url'],
                'join_url'      => $basePath . 'classes/class.php?id=' . (int)$row['class_id'] . '#live-stream',
                'starts_in_min' => $startsIn,
                'is_live'       => $isLive,
            ];
        }
    }
}

// CSRF token for the mark-read POSTs
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/csrf.php';
}
$csrfToken = csrf_token();

// Helper function for time ago
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return date('M d', $timestamp);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LiveTeach SA | South African Online Learning Platform</title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/style.css">
    <style>
    /* Global Styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: var(--charcoal, #1a1a1a);
        color: var(--cream, #f5f0e8);
        line-height: 1.6;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }

    /* Header */
    .site-header {
        background: var(--charcoal-dark, #0f0f0f);
        border-bottom: 3px solid var(--primary-red, #e53935);
        padding: 15px 0;
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .header-inner {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }

    .logo {
        font-size: 1.5rem;
        font-weight: bold;
        text-decoration: none;
    }

    .logo span:first-child { color: var(--primary-red, #e53935); }
    .logo span:last-child  { color: var(--cream, #f5f0e8); }

    .nav-links {
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }

    .nav-links a {
        color: var(--cream, #f5f0e8);
        text-decoration: none;
        transition: all 0.3s ease;
        padding: 8px 0;
    }

    .nav-links a:hover,
    .nav-links a.active {
        color: var(--primary-red, #e53935);
        border-bottom: 2px solid var(--primary-red, #e53935);
    }

    /* Buttons */
    .btn {
        display: inline-block;
        padding: 12px 28px;
        border-radius: 40px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        font-size: 0.9rem;
        text-decoration: none;
        text-align: center;
    }

    .btn-primary {
        background: var(--primary-red, #e53935);
        color: white;
    }

    .btn-primary:hover {
        background: var(--primary-red-dark, #b71c1c);
        transform: translateY(-2px);
    }

    .btn-outline {
        background: transparent;
        border: 2px solid var(--primary-red, #e53935);
        color: var(--primary-red, #e53935);
    }

    .btn-outline:hover {
        background: var(--primary-red, #e53935);
        color: white;
    }

    .btn-success {
        background: #4caf50;
        color: white;
    }

    /* Notification Bell */
    .notification-container {
        position: relative;
        display: inline-block;
    }

    .notification-bell {
        position: relative;
        cursor: pointer;
        padding: 8px;
        font-size: 1.3rem;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        color: var(--cream, #f5f0e8);
        text-decoration: none;
    }

    .notification-bell:hover {
        color: var(--primary-red, #e53935);
    }

    /* Bell states for imminent meetings */
    .notification-bell.lt-has-soon {
        color: #D9A441;
        background: rgba(217, 164, 65, 0.12);
        border-radius: 8px;
    }

    .notification-bell.lt-has-live {
        color: #fff;
        background: var(--primary-red, #e53935);
        border-radius: 8px;
        animation: bell-shake 1.6s ease-in-out infinite;
    }

    @keyframes bell-shake {
        0%, 100% { transform: translateX(0) rotate(0deg); }
        10%      { transform: translateX(-3px) rotate(-8deg); }
        20%      { transform: translateX(3px) rotate(8deg); }
        30%      { transform: translateX(-3px) rotate(-8deg); }
        40%      { transform: translateX(3px) rotate(8deg); }
        50%      { transform: translateX(0) rotate(0deg); }
    }

    .notification-badge {
        position: absolute;
        top: 0;
        right: 0;
        background: #dc3545;
        color: white;
        border-radius: 999px;
        font-size: 0.65rem;
        min-width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        font-weight: bold;
    }

    .notification-badge.lt-badge-live {
        background: var(--primary-red, #e53935);
        color: #fff;
        min-width: 34px;
        height: 20px;
        font-size: 0.62rem;
        letter-spacing: 0.08em;
        animation: badge-live-pulse 1.2s ease-in-out infinite;
    }

    @keyframes badge-live-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(229, 57, 53, 0.55); }
        50%      { box-shadow: 0 0 0 8px rgba(229, 57, 53, 0); }
    }

    .notification-badge.lt-badge-soon {
        background: #D9A441;
        color: #100D0A;
        min-width: 26px;
        height: 20px;
        font-size: 0.62rem;
        letter-spacing: 0.05em;
    }

    .notification-dropdown {
        position: absolute;
        top: 45px;
        right: 0;
        width: 400px;
        max-width: 90vw;
        background: var(--charcoal-dark, #0f0f0f);
        border-radius: 12px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        z-index: 1000;
        display: none;
        border: 1px solid var(--border, #333);
        overflow: hidden;
    }

    .notification-dropdown.show {
        display: block;
        animation: fadeIn 0.2s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Meeting banner inside dropdown */
    .notification-meeting {
        padding: 16px 18px;
        background: linear-gradient(135deg, rgba(229, 57, 53, 0.18), rgba(229, 57, 53, 0.06));
        border-bottom: 1px solid rgba(229, 57, 53, 0.35);
        display: flex;
        gap: 14px;
        align-items: flex-start;
    }

    .notification-meeting.lt-soon {
        background: linear-gradient(135deg, rgba(217, 164, 65, 0.18), rgba(217, 164, 65, 0.04));
        border-bottom-color: rgba(217, 164, 65, 0.35);
    }

    .notification-meeting-icon {
        font-size: 1.5rem;
        line-height: 1;
        flex-shrink: 0;
        animation: meeting-icon-pulse 1.5s ease-in-out infinite;
    }

    @keyframes meeting-icon-pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.7; transform: scale(1.08); }
    }

    .notification-meeting-body { flex: 1; min-width: 0; }

    .notification-meeting-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        padding: 3px 9px;
        border-radius: 999px;
        margin-bottom: 8px;
    }

    .notification-meeting-tag.lt-live {
        background: var(--primary-red, #e53935);
        color: #fff;
    }

    .notification-meeting-tag.lt-soon {
        background: #D9A441;
        color: #100D0A;
    }

    .notification-meeting-tag .lt-meeting-dot {
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: currentColor;
        animation: meeting-dot-pulse 1.5s infinite;
    }

    @keyframes meeting-dot-pulse {
        0%, 100% { opacity: 1; }
        50%      { opacity: 0.35; }
    }

    .notification-meeting-title {
        font-size: 0.95rem;
        color: var(--cream, #f5f0e8);
        margin: 0 0 4px;
        line-height: 1.35;
        word-break: break-word;
        font-weight: 600;
    }

    .notification-meeting-sub {
        font-size: 0.76rem;
        color: var(--cream, #f5f0e8);
        opacity: 0.7;
        margin: 0 0 10px;
        line-height: 1.5;
    }

    .notification-meeting-join {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: var(--primary-red, #e53935);
        color: #fff;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-decoration: none;
        transition: background 0.2s ease, transform 0.2s ease;
        box-shadow: 0 4px 14px -4px rgba(229, 57, 53, 0.6);
    }

    .notification-meeting-join:hover {
        background: var(--primary-red-dark, #b71c1c);
        transform: translateY(-1px);
    }

    .notification-meeting.lt-soon .notification-meeting-join {
        background: #D9A441;
        color: #100D0A;
        box-shadow: 0 4px 14px -4px rgba(217, 164, 65, 0.6);
    }

    .notification-meeting.lt-soon .notification-meeting-join:hover {
        background: #E8B85A;
    }

    .notification-header {
        padding: 12px 15px;
        border-bottom: 1px solid var(--border, #333);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .notification-header h4 {
        margin: 0;
        color: var(--primary-red, #e53935);
        font-size: 0.9rem;
    }

    .notification-mark-all {
        font-size: 0.7rem;
        color: var(--cream, #f5f0e8);
        opacity: 0.7;
        cursor: pointer;
        text-decoration: none;
        background: none;
        border: none;
    }

    .notification-mark-all:hover {
        opacity: 1;
        color: var(--primary-red, #e53935);
    }

    .notification-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .notification-item {
        padding: 12px 15px;
        border-bottom: 1px solid var(--border, #333);
        transition: all 0.2s;
        cursor: pointer;
        display: flex;
        gap: 12px;
    }

    .notification-item.unread {
        background: rgba(229, 57, 53, 0.1);
    }

    .notification-item:hover {
        background: var(--charcoal-light, #2a2a2a);
    }

    .notification-icon {
        font-size: 1.2rem;
        min-width: 32px;
    }

    .notification-content {
        flex: 1;
        min-width: 0;
    }

    .notification-title {
        font-weight: bold;
        margin-bottom: 4px;
        font-size: 0.85rem;
    }

    .notification-message {
        font-size: 0.75rem;
        opacity: 0.7;
        margin-bottom: 4px;
    }

    .notification-time {
        font-size: 0.65rem;
        opacity: 0.5;
    }

    .notification-empty {
        padding: 40px;
        text-align: center;
        opacity: 0.6;
    }

    .notification-footer {
        padding: 10px 15px;
        border-top: 1px solid var(--border, #333);
        text-align: center;
    }

    .notification-footer a {
        font-size: 0.75rem;
        color: var(--primary-red, #e53935);
        text-decoration: none;
    }

    /* Alerts */
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .alert-success {
        background: rgba(76, 175, 80, 0.2);
        border: 1px solid #4caf50;
        color: #90ee90;
    }

    .alert-error {
        background: rgba(244, 67, 54, 0.2);
        border: 1px solid #f44336;
        color: #ff9999;
    }

    .alert-info {
        background: rgba(33, 150, 243, 0.2);
        border: 1px solid #2196f3;
        color: #66b3ff;
    }

    @media (max-width: 768px) {
        .nav-links {
            flex-direction: column;
            width: 100%;
        }
        .notification-dropdown {
            width: 300px;
            right: -20px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .notification-bell.lt-has-live,
        .notification-badge.lt-badge-live,
        .notification-meeting-icon,
        .notification-meeting-tag .lt-meeting-dot {
            animation: none !important;
        }
    }
    </style>
</head>
<body>
<header class="site-header">
    <div class="container">
        <div class="header-inner">
            <a href="<?php echo $basePath; ?>index.php" class="logo">
                <span>🎓</span> <span>LiveTeach</span> <span style="color: var(--cream, #f5f0e8);">SA</span>
            </a>
            <nav class="nav-links">
                <a href="<?php echo $basePath; ?>index.php">Home</a>
                <a href="<?php echo $basePath; ?>classes/index.php">Live Classes</a>
                <?php if ($user): ?>
                    <?php if (($user['role'] ?? '') == 'admin'): ?>
                        <a href="<?php echo $basePath; ?>admin/dashboard.php">Admin Panel</a>
                        <a href="<?php echo $basePath; ?>admin/users.php">Users</a>
                        <a href="<?php echo $basePath; ?>admin/classes.php">Classes</a>
                        <a href="<?php echo $basePath; ?>admin/payments.php">Payments</a>
                    <?php elseif (($user['role'] ?? '') == 'teacher'): ?>
                        <a href="<?php echo $basePath; ?>teacher/dashboard.php">Dashboard</a>
                        <a href="<?php echo $basePath; ?>teacher/classes.php">My Classes</a>
                        <a href="<?php echo $basePath; ?>teacher/students.php">Students</a>
                    <?php else: ?>
                        <a href="<?php echo $basePath; ?>dashboard/index.php">Dashboard</a>
                        <a href="<?php echo $basePath; ?>dashboard/my-classes.php">My Classes</a>
                        <a href="<?php echo $basePath; ?>dashboard/attendance.php">Attendance</a>
                    <?php endif; ?>

                    <?php
                    /* ---------- Notification bell ---------- */
                    $hasSession  = !empty($imminentSession);
                    $sessionLive = $hasSession && !empty($imminentSession['is_live']);
                    $sessionSoon = $hasSession && !$sessionLive;

                    $bellClass = 'notification-bell';
                    if ($sessionLive)       $bellClass .= ' lt-has-live';
                    elseif ($sessionSoon)   $bellClass .= ' lt-has-soon';
                    elseif ($unreadCount>0) $bellClass .= '';

                    $bellIcon = $sessionLive ? '📢' : '🔔';
                    ?>
                    <div class="notification-container">
                        <div class="<?php echo $bellClass; ?>" onclick="toggleNotifications(event)">
                            <?php echo $bellIcon; ?>

                            <?php if ($sessionLive): ?>
                                <span class="notification-badge lt-badge-live" id="notificationBadge">LIVE</span>
                            <?php elseif ($sessionSoon): ?>
                                <?php
                                    $mins  = (int)($imminentSession['starts_in_min'] ?? 0);
                                    $label = $mins <= 1 ? 'NOW' : ($mins . 'm');
                                ?>
                                <span class="notification-badge lt-badge-soon" id="notificationBadge"><?php echo $label; ?></span>
                            <?php elseif ($unreadCount > 0): ?>
                                <span class="notification-badge" id="notificationBadge"><?php echo $unreadCount > 99 ? '99+' : (int)$unreadCount; ?></span>
                            <?php else: ?>
                                <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                            <?php endif; ?>
                        </div>

                        <div class="notification-dropdown" id="notificationDropdown">
                            <?php if ($hasSession): ?>
                                <div class="notification-meeting <?php echo $sessionLive ? '' : 'lt-soon'; ?>">
                                    <div class="notification-meeting-icon"><?php echo $sessionLive ? '🔴' : '⏰'; ?></div>
                                    <div class="notification-meeting-body">
                                        <div class="notification-meeting-tag <?php echo $sessionLive ? 'lt-live' : 'lt-soon'; ?>">
                                            <span class="lt-meeting-dot"></span>
                                            <?php echo $sessionLive
                                                ? 'Live now'
                                                : ('Starts in ' . (int)$imminentSession['starts_in_min'] . ' minute' . ((int)$imminentSession['starts_in_min'] === 1 ? '' : 's')); ?>
                                        </div>
                                        <div class="notification-meeting-title">
                                            <?php echo htmlspecialchars($imminentSession['class_title']); ?>
                                        </div>
                                        <div class="notification-meeting-sub">
                                            with <?php echo htmlspecialchars($imminentSession['teacher_name']); ?>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($imminentSession['join_url']); ?>" class="notification-meeting-join">
                                            <?php echo $sessionLive ? '🔴 Join now' : '🎥 Join meeting'; ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="notification-header">
                                <h4>🔔 Notifications</h4>
                                <?php if ($unreadCount > 0): ?>
                                    <button type="button" class="notification-mark-all" onclick="markAllAsRead(); return false;">Mark all as read</button>
                                <?php endif; ?>
                            </div>

                            <div class="notification-list" id="notificationList">
                                <?php if (!empty($unreadNotifications)): ?>
                                    <?php foreach ($unreadNotifications as $notif):
                                        $icon = '🔔';
                                        if ($notif['type'] == 'enrollment')       $icon = '📚';
                                        elseif ($notif['type'] == 'live_stream')  $icon = '🔴';
                                        elseif ($notif['type'] == 'certificate')  $icon = '🏆';
                                        elseif ($notif['type'] == 'class_update') $icon = '📢';
                                        elseif ($notif['type'] == 'payment')      $icon = '💰';

                                        $link = $notif['link'] ?? '';
                                    ?>
                                        <div class="notification-item unread"
                                             data-id="<?php echo (int)$notif['id']; ?>"
                                             data-link="<?php echo htmlspecialchars($link, ENT_QUOTES); ?>"
                                             onclick="markAsRead(this, event)">
                                            <div class="notification-icon <?php echo htmlspecialchars($notif['type']); ?>">
                                                <?php echo $icon; ?>
                                            </div>
                                            <div class="notification-content">
                                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                                <div class="notification-message"><?php echo htmlspecialchars(mb_substr($notif['message'], 0, 80)); ?><?php echo mb_strlen($notif['message']) > 80 ? '…' : ''; ?></div>
                                                <div class="notification-time"><?php echo timeAgo(strtotime($notif['created_at'])); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-empty">📭 No new notifications</div>
                                <?php endif; ?>
                            </div>

                            <div class="notification-footer">
                                <a href="<?php echo $basePath; ?>notifications/index.php">View all →</a>
                            </div>
                        </div>
                    </div>

                    <a href="<?php echo $basePath; ?>logout.php" style="color:var(--primary-red, #e53935);">
                        Logout (<?php echo htmlspecialchars($user['fullname'] ?? 'User'); ?>)
                    </a>
                <?php else: ?>
                    <a href="<?php echo $basePath; ?>login.php">Login</a>
                    <a href="<?php echo $basePath; ?>register.php" class="btn btn-primary" style="padding:8px 20px;">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</header>

<script>
/* ============================================================
   Notification bell — talks to notifications/index.php directly.
   No dependency on ../ajax/*.php files.
   ============================================================ */
(function () {
    'use strict';

    const CSRF       = <?php echo json_encode($csrfToken); ?>;
    const BASE       = <?php echo json_encode($basePath); ?>;
    const NOTIF_URL  = BASE + 'notifications/index.php';

    /* ---- Toggle dropdown ---- */
    window.toggleNotifications = function (event) {
        if (event) event.stopPropagation();
        const dd = document.getElementById('notificationDropdown');
        if (dd) dd.classList.toggle('show');
    };

    /* ---- Mark one as read + navigate ---- */
    window.markAsRead = function (el, event) {
        if (event) event.stopPropagation();

        const id   = el.dataset.id;
        const link = el.dataset.link || '';
        if (!id) return;

        fetch(NOTIF_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'csrf_token=' + encodeURIComponent(CSRF) + '&mark_read=' + encodeURIComponent(id),
            credentials: 'same-origin',
            redirect: 'manual'
        }).catch(function () {}).finally(function () {
            if (link && link !== '#' && link !== '') {
                window.location.href = link;
            } else {
                el.classList.remove('unread');
                refreshBadge();
            }
        });
    };

    /* ---- Mark all as read ---- */
    window.markAllAsRead = function () {
        fetch(NOTIF_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'csrf_token=' + encodeURIComponent(CSRF) + '&mark_all_read=1',
            credentials: 'same-origin',
            redirect: 'manual'
        }).then(function () {
            document.querySelectorAll('#notificationList .notification-item.unread')
                .forEach(el => el.classList.remove('unread'));
            const list = document.getElementById('notificationList');
            if (list) list.innerHTML = '<div class="notification-empty">📭 No new notifications</div>';
            setBadge(0);
            const dd = document.getElementById('notificationDropdown');
            if (dd) dd.classList.remove('show');
        }).catch(function () {});
    };

    /* ---- Badge helpers ---- */
    function setBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (!badge) return;

        /* Never overwrite LIVE or SOON badges from polling */
        if (badge.classList.contains('lt-badge-live')
         || badge.classList.contains('lt-badge-soon')) {
            return;
        }

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    function refreshBadge() {
        fetch(window.location.href, { credentials: 'same-origin' })
            .then(r => r.text())
            .then(function (html) {
                const m = html.match(/id="notificationBadge"[^>]*>([^<]+)</);
                if (!m) return;
                const text = m[1].trim();
                const n = text === '99+' ? 100 : parseInt(text, 10);
                if (!isNaN(n)) setBadge(n);
            })
            .catch(function () {});
    }

    /* ---- Close dropdown when clicking outside ---- */
    document.addEventListener('click', function (event) {
        const container = document.querySelector('.notification-container');
        const dropdown  = document.getElementById('notificationDropdown');
        if (container && dropdown && !container.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    /* ---- Escape closes dropdown ---- */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const dd = document.getElementById('notificationDropdown');
            if (dd) dd.classList.remove('show');
        }
    });

    /* ---- Light polling: every 30 s, refresh badge & dropdown ---- */
    let pollTimer = null;
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function () {
            if (document.visibilityState !== 'visible') return;
            refreshBadge();
        }, 30000);
    }
    if (document.visibilityState === 'visible') {
        startPolling();
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            startPolling();
        } else if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });
})();
</script>