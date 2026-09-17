<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Security check failed. Please reload the page and try again.');
    }
}

if (!defined('APP_BASE')) {
    define('APP_BASE', '/live-teaching-system');
}

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