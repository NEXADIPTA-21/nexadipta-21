<?php
require_once __DIR__ . '/../includes/functions.php';

$adminId = !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
$adminUsername = (string) ($_SESSION['admin_username'] ?? '');

if ($adminId !== null) {
    admin_audit(
        'admin.logout',
        'admin',
        $adminId,
        'Logout admin: ' . $adminUsername
    );
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'] ?? '',
        (bool) $params['secure'],
        (bool) $params['httponly']
    );
}
session_destroy();
redirect('login.php');
