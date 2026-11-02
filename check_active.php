<?php
// Include right after session_start() on every protected page.
// If an admin archived the account while the user is logged in, end their session immediately.
require_once __DIR__ . '/db.php';
if (!empty($_SESSION['user_id'])) {
    $st = $pdo->prepare("SELECT status FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    $s = $st->fetchColumn();
    if ($s === false || $s === 'archived') {
        $_SESSION = [];
        session_destroy();
        header("Location: login.php?archived=1");
        exit;
    }
}
