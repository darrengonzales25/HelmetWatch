<?php
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

if ($isLocal) {
    $host = 'localhost';
    $dbname = 'helmetwatch_db';
    $user = 'root';
    $pass = '';
} else {
    $host = 'sql304.infinityfree.com';
    $dbname = 'if0_43269972_helmet';
    $user = 'if0_43269972';
    $pass = 'psTKu6WwxB';
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>