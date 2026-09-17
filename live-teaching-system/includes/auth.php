<?php
/**
 * Auth Helper - shared auth guard functions
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin($redirectTo = '../login.php') {
    if (!isset($_SESSION['user'])) {
        header("Location: $redirectTo");
        exit;
    }
}

function requireRole($role, $redirectTo = '../login.php') {
    requireLogin($redirectTo);
    if ($_SESSION['user']['role'] !== $role) {
        header("Location: $redirectTo");
        exit;
    }
}

function isLoggedIn() {
    return isset($_SESSION['user']);
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function hasRole($role) {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === $role;
}
