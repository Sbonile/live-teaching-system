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

$result = $notificationSystem->markAllAsRead($_SESSION['user']['id']);

echo json_encode([
    'success' => $result
]);
?>