<?php
session_start();
require_once __DIR__ . '/theme.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request']);
    exit;
}
$t = $_POST['theme'] ?? null;
$f = $_POST['font'] ?? null;
if (($t === null && $f === null) || ($t !== null && !theme_valid($t)) || ($f !== null && !font_valid($f))) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Unknown setting']);
    exit;
}

$uid = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($uid) require_once __DIR__ . '/db.php';
if ($t !== null) {
    $_SESSION['theme'] = $t; theme_cookie_set($t);
    if ($uid) theme_save_for_user($pdo, $uid, $t);
}
if ($f !== null) {
    $_SESSION['font'] = $f; font_cookie_set($f);
    if ($uid) font_save_for_user($pdo, $uid, $f);
}
echo json_encode(['ok' => true]);
