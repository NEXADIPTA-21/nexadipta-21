<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

function admin_require_login(): array
{
    if (empty($_SESSION['admin_id'])) {
        redirect('login.php');
    }
    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? '',
    ];
}
