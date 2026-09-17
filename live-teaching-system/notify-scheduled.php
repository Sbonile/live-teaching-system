<?php
/**
 * Scheduled-stream reminder engine.
 *
 * Sends in-app reminders to enrolled students 30 minutes and 5 minutes
 * before a scheduled live_streams row begins.
 *
 * Safe to call from cron OR from any page load. Uses the notify_*_sent
 * flags on live_streams to guarantee each reminder fires at most once
 * per stream.
 *
 * Depends on:
 *   - `live_streams` table has columns `notify_30min_sent` and `notify_5min_sent`
 *     (run the migration below once if they don't exist):
 *
 *       ALTER TABLE `live_streams`
 *         ADD COLUMN `notify_30min_sent` TINYINT(1) NOT NULL DEFAULT 0
 *           AFTER `viewer_count`,
 *         ADD COLUMN `notify_5min_sent`  TINYINT(1) NOT NULL DEFAULT 0
 *           AFTER `notify_30min_sent`;
 */

/* Guard so this file can be required multiple times safely. */
if (!defined('LIVETEACH_NOTIFY_INCLUDED')) {
    define('LIVETEACH_NOTIFY_INCLUDED', 1);

    /**
     * Send all due reminders. Returns the count of notifications sent.
     */
    function notify_scheduled_streams(mysqli $connection): int
    {
        $sent = 0;

        /* ---------------- 30-minute window ---------------- */
        $q = $connection->prepare("
            SELECT ls.id, ls.class_id, ls.stream_url, ls.platform, ls.scheduled_time,
                   c.title AS class_title
            FROM live_streams ls
            JOIN live_classes c ON c.id = ls.class_id
            WHERE ls.status = 'scheduled'
              AND ls.notify_30min_sent = 0
              AND ls.scheduled_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)
        ");
        if ($q) {
            $q->execute();
            $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            foreach ($rows as $s) {
                notify_students_for_stream($connection, $s, '30min', $sent);
            }
        }

        /* ---------------- 5-minute window ---------------- */
        $q = $connection->prepare("
            SELECT ls.id, ls.class_id, ls.stream_url, ls.platform, ls.scheduled_time,
                   c.title AS class_title
            FROM live_streams ls
            JOIN live_classes c ON c.id = ls.class_id
            WHERE ls.status = 'scheduled'
              AND ls.notify_5min_sent = 0
              AND ls.scheduled_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 5 MINUTE)
        ");
        if ($q) {
            $q->execute();
            $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
            foreach ($rows as $s) {
                notify_students_for_stream($connection, $s, '5min', $sent);
            }
        }

        return $sent;
    }

    /**
     * Insert reminder rows for every paid, enrolled student of one class,
     * then flip the flag column so we never resend.
     */
    function notify_students_for_stream(mysqli $connection, array $stream, string $window, int &$sent): void
    {
        /* Notification wording varies per window */
        if ($window === '30min') {
            $title    = '⏰ Class starts in 30 minutes';
            $body     = "Your class '{$stream['class_title']}' starts at "
                      . date('g:i A', strtotime($stream['scheduled_time']))
                      . ". Join link: {$stream['stream_url']}";
            $flagCol  = 'notify_30min_sent';
        } else {
            $title    = '🔔 Class starts in 5 minutes';
            $body     = "Your class '{$stream['class_title']}' starts very soon. "
                      . "Click to join: {$stream['stream_url']}";
            $flagCol  = 'notify_5min_sent';
        }

        /* Deep link back into the class page — the #live-stream anchor
           scrolls straight to the join panel once the teacher starts. */
        $link = '../classes/class.php?id=' . (int)$stream['class_id'] . '#live-stream';

        /* Get enrolled, paid students */
        $s = $connection->prepare("
            SELECT student_id FROM enrollments
             WHERE class_id = ? AND payment_status = 'paid'
        ");
        $s->bind_param('i', $stream['class_id']);
        $s->execute();
        $students = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        /* Insert one notification per student. */
        if (!empty($students)) {
            $ins = $connection->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, 'live_stream', ?, ?, ?)
            ");
            foreach ($students as $st) {
                $uid = (int)$st['student_id'];
                $ins->bind_param('isss', $uid, $title, $body, $link);
                if ($ins->execute()) {
                    $sent++;
                }
            }
            $ins->close();
        }

        /* Mark reminder sent — prevents duplicates. */
        $u = $connection->prepare("UPDATE live_streams SET {$flagCol} = 1 WHERE id = ?");
        $u->bind_param('i', $stream['id']);
        $u->execute();
        $u->close();
    }
}

/* If accessed directly (via cron or browser), run standalone. */
if (php_sapi_name() === 'cli'
    || basename($_SERVER['PHP_SELF'] ?? '') === 'notify-scheduled.php') {

    require_once __DIR__ . '/../config/database.php';

    try {
        $conn  = getDbConnection();
        $count = notify_scheduled_streams($conn);
        if (php_sapi_name() === 'cli') {
            echo "Sent {$count} reminder notification(s).\n";
        } else {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'sent' => $count]);
        }
    } catch (Throwable $e) {
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, 'Reminder engine failed: ' . $e->getMessage() . "\n");
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}