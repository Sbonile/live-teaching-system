<?php
/**
 * LiveTeach Notification System
 * -----------------------------
 * Wraps the `notifications` table so any part of the app can raise
 * in-app notifications without knowing the schema.
 *
 * Schema expected (from live_teaching_system.sql):
 *
 *   notifications
 *     id          int
 *     user_id     int
 *     type        varchar(50)
 *     title       varchar(255)
 *     message     text
 *     link        varchar(500) nullable
 *     is_read     tinyint(1)   default 0
 *     created_at  timestamp
 *
 *   notification_preferences
 *     id, user_id, enrollment_notifications, live_stream_notifications,
 *     certificate_notifications, class_update_notifications,
 *     email_notifications
 *
 * All inserts are best-effort — a failed notification never breaks the
 * caller's main flow.
 */

if (!class_exists('NotificationSystem')) {

    class NotificationSystem
    {
        private mysqli $connection;

        public function __construct(mysqli $connection)
        {
            $this->connection = $connection;
        }

        /* =========================================================
           Core operations
           ========================================================= */

        public function create(int $userId, string $type, string $title, string $message, ?string $link = null): bool
        {
            $stmt = @$this->connection->prepare("
                INSERT INTO notifications (user_id, type, title, message, link)
                VALUES (?, ?, ?, ?, ?)
            ");
            if (!$stmt) return false;
            $stmt->bind_param('issss', $userId, $type, $title, $message, $link);
            $ok = @$stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }

        /**
         * Insert into notifications only if the user's preferences
         * allow this notification type. Used by all sending helpers.
         */
        public function createRespectingPreferences(int $userId, string $type, string $title, string $message, ?string $link = null): bool
        {
            /* Map notification type → preference column */
            $prefColumn = [
                'enrollment'   => 'enrollment_notifications',
                'live_stream'  => 'live_stream_notifications',
                'certificate'  => 'certificate_notifications',
                'class_update' => 'class_update_notifications',
            ][$type] ?? null;

            /* Types without a preference column (e.g. payment) always send */
            if ($prefColumn === null) {
                return $this->create($userId, $type, $title, $message, $link);
            }

            /* Read the user's preference; default to "enabled" if no row */
            $stmt = @$this->connection->prepare("
                SELECT $prefColumn AS enabled
                FROM notification_preferences
                WHERE user_id = ?
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && (int)$row['enabled'] === 0) {
                    return false;   // user opted out
                }
            }
            /* No row → treat as enabled */

            return $this->create($userId, $type, $title, $message, $link);
        }

        public function getUnread(int $userId, int $limit = 10): array
        {
            $limit = max(1, min(100, $limit));
            $stmt = @$this->connection->prepare("
                SELECT id, type, title, message, link, is_read, created_at
                FROM notifications
                WHERE user_id = ? AND is_read = 0
                ORDER BY created_at DESC, id DESC
                LIMIT ?
            ");
            if (!$stmt) return [];
            $stmt->bind_param('ii', $userId, $limit);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        }

        public function getAll(int $userId, int $limit = 50, int $offset = 0): array
        {
            $limit  = max(1, min(200, $limit));
            $offset = max(0, $offset);
            $stmt = @$this->connection->prepare("
                SELECT id, type, title, message, link, is_read, created_at
                FROM notifications
                WHERE user_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) return [];
            $stmt->bind_param('iii', $userId, $limit, $offset);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        }

        public function markAsRead(int $notificationId, int $userId): bool
        {
            $stmt = @$this->connection->prepare("
                UPDATE notifications SET is_read = 1
                WHERE id = ? AND user_id = ?
            ");
            if (!$stmt) return false;
            $stmt->bind_param('ii', $notificationId, $userId);
            $ok = @$stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }

        public function markAllAsRead(int $userId): int
        {
            $stmt = @$this->connection->prepare("
                UPDATE notifications SET is_read = 1
                WHERE user_id = ? AND is_read = 0
            ");
            if (!$stmt) return 0;
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected;
        }

        public function getUnreadCount(int $userId): int
        {
            $stmt = @$this->connection->prepare("
                SELECT COUNT(*) AS c FROM notifications
                WHERE user_id = ? AND is_read = 0
            ");
            if (!$stmt) return 0;
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            return $c;
        }

        public function deleteOld(int $days = 30): bool
        {
            $stmt = @$this->connection->prepare("
                DELETE FROM notifications
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            if (!$stmt) return false;
            $stmt->bind_param('i', $days);
            $ok = @$stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }

        /**
         * Send one notification to every paid, enrolled student in a class.
         * Returns the number of notifications inserted.
         */
        public function sendToClass(int $classId, string $type, string $title, string $message, ?string $link = null): int
        {
            $stmt = @$this->connection->prepare("
                SELECT DISTINCT student_id
                FROM enrollments
                WHERE class_id = ? AND payment_status = 'paid'
            ");
            if (!$stmt) return 0;
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $count = 0;
            foreach ($students as $student) {
                if ($this->createRespectingPreferences((int)$student['student_id'], $type, $title, $message, $link)) {
                    $count++;
                }
            }
            return $count;
        }

        /* =========================================================
           Preferences
           ========================================================= */

        public function getPreferences(int $userId): array
        {
            $stmt = @$this->connection->prepare("
                SELECT * FROM notification_preferences WHERE user_id = ? LIMIT 1
            ");
            if (!$stmt) return [];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $this->createDefaultPreferences($userId);
                return [
                    'enrollment_notifications'   => 1,
                    'live_stream_notifications'  => 1,
                    'certificate_notifications'  => 1,
                    'class_update_notifications' => 1,
                    'email_notifications'        => 0,
                ];
            }
            return $row;
        }

        public function createDefaultPreferences(int $userId): bool
        {
            $stmt = @$this->connection->prepare("
                INSERT IGNORE INTO notification_preferences (user_id) VALUES (?)
            ");
            if (!$stmt) return false;
            $stmt->bind_param('i', $userId);
            $ok = @$stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }

        public function updatePreferences(int $userId, array $data): bool
        {
            $stmt = @$this->connection->prepare("
                UPDATE notification_preferences
                SET enrollment_notifications   = ?,
                    live_stream_notifications  = ?,
                    certificate_notifications  = ?,
                    class_update_notifications = ?,
                    email_notifications        = ?
                WHERE user_id = ?
            ");
            if (!$stmt) return false;
            $enrollment = isset($data['enrollment_notifications'])   ? (int)$data['enrollment_notifications']   : 1;
            $live       = isset($data['live_stream_notifications'])  ? (int)$data['live_stream_notifications']  : 1;
            $cert       = isset($data['certificate_notifications'])  ? (int)$data['certificate_notifications']  : 1;
            $class      = isset($data['class_update_notifications']) ? (int)$data['class_update_notifications'] : 1;
            $email      = isset($data['email_notifications'])        ? (int)$data['email_notifications']        : 0;
            $stmt->bind_param('iiiiii', $enrollment, $live, $cert, $class, $email, $userId);
            $ok = @$stmt->execute();
            $stmt->close();
            return (bool)$ok;
        }
    }
}

/* =====================================================================
   Global helper functions
   ===================================================================== */

/**
 * Used by teacher/attendance.php and any other caller.
 */
if (!function_exists('sendNotification')) {
    function sendNotification(int $userId, string $type, string $title, string $message, ?string $link = null): bool
    {
        global $notificationSystem, $connection;

        /* Fall back to a live connection if the global wasn't set up */
        if (!isset($notificationSystem) || !$notificationSystem) {
            if (!isset($connection) || !$connection) {
                require_once __DIR__ . '/../config/database.php';
                $connection = getDbConnection();
            }
            $notificationSystem = new NotificationSystem($connection);
        }

        return $notificationSystem->createRespectingPreferences($userId, $type, $title, $message, $link);
    }
}

if (!function_exists('sendClassNotification')) {
    function sendClassNotification(int $classId, string $type, string $title, string $message, ?string $link = null): int
    {
        global $notificationSystem, $connection;
        if (!isset($notificationSystem) || !$notificationSystem) {
            if (!isset($connection) || !$connection) {
                require_once __DIR__ . '/../config/database.php';
                $connection = getDbConnection();
            }
            $notificationSystem = new NotificationSystem($connection);
        }
        return $notificationSystem->sendToClass($classId, $type, $title, $message, $link);
    }
}

/* =====================================================================
   Trigger helpers — used by enrollment, live-stream start, etc.
   ===================================================================== */

/**
 * Called by classes/class.php when a student enrolls.
 */
if (!function_exists('liveteach_notify_enrollment')) {
    function liveteach_notify_enrollment(mysqli $conn, int $classId, int $studentId): void
    {
        $stmt = @$conn->prepare("
            SELECT c.title, c.meeting_link, u.fullname AS teacher_name
            FROM live_classes c
            LEFT JOIN users u ON u.id = c.teacher_id
            WHERE c.id = ?
            LIMIT 1
        ");
        if (!$stmt) return;
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$class) return;

        $ns = new NotificationSystem($conn);

        /* Enrollment confirmation */
        $ns->create(
            $studentId,
            'enrollment',
            '✅ Enrollment Successful',
            "You have successfully enrolled in '{$class['title']}' with {$class['teacher_name']}.",
            '../dashboard/my-classes.php'
        );

        /* Meeting link if one is already set */
        if (!empty($class['meeting_link'])) {
            $ns->create(
                $studentId,
                'live_stream',
                '🔗 Class meeting link available',
                "Your class '{$class['title']}' has a meeting link ready. Click to open it: {$class['meeting_link']}",
                '../classes/class.php?id=' . $classId
            );
        }
    }
}

/**
 * Called by teacher/live-stream.php when a teacher starts or promotes a stream.
 * Returns the number of students notified.
 */
if (!function_exists('liveteach_notify_live_started')) {
    function liveteach_notify_live_started(mysqli $conn, int $classId, int $streamId = 0): int
    {
        $stmt = @$conn->prepare("
            SELECT c.title, u.fullname AS teacher_name
            FROM live_classes c
            LEFT JOIN users u ON u.id = c.teacher_id
            WHERE c.id = ?
            LIMIT 1
        ");
        if (!$stmt) return 0;
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$class) return 0;

        $ns = new NotificationSystem($conn);
        return $ns->sendToClass(
            $classId,
            'live_stream',
            '🔴 Class is live now',
            "{$class['teacher_name']} has started the live session for '{$class['title']}'. "
          . "Join now — click to enter the classroom.",
            '../classes/class.php?id=' . $classId . '#live-stream'
        );
    }
}