<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// Make sure connection exists
if (!isset($connection) || $connection === null) {
    $connection = getDbConnection();
}

require_once '../includes/notifications.php';

// Re-initialize notification system
$notificationSystem = new NotificationSystem($connection);

$data = json_decode(file_get_contents('php://input'), true);
$notificationId = $data['id'] ?? 0;

$result = $notificationSystem->markAsRead($notificationId, $_SESSION['user']['id']);
$unreadCount = $notificationSystem->getUnreadCount($_SESSION['user']['id']);

echo json_encode([
    'success' => $result,
    'unread_count' => $unreadCount
]);
?>