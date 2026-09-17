<?php
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'live_teaching_system';
$DB_CHARSET = 'utf8mb4';

// Video upload settings
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('VIDEO_DIR', UPLOAD_DIR . 'videos/');
define('RECORDING_DIR', UPLOAD_DIR . 'recordings/');
define('THUMBNAIL_DIR', UPLOAD_DIR . 'thumbnails/');
define('MAX_VIDEO_SIZE', 500 * 1024 * 1024); // 500MB
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/ogg']);

function getDbConnection() {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_CHARSET;
    $connection = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($connection->connect_errno) {
        die('Database connection failed: ' . $connection->connect_error);
    }
    $connection->set_charset($DB_CHARSET);
    return $connection;
}

function tableExists($connection, $table) {
    $table = $connection->real_escape_string($table);
    $result = $connection->query("SHOW TABLES LIKE '{$table}'");
    return $result && $result->num_rows > 0;
}
?>