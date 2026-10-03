<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
$pollId = (int)($_GET['poll_id'] ?? $_POST['poll_id'] ?? 0);
if ($pollId > 0) {
    redirect('settings.php?action=edit&id=' . $pollId . '#questions');
}
redirect('settings.php');
