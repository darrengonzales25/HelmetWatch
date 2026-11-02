<?php
require 'db.php';

// Philippine Time (UTC+8) for file names, PHP dates and the database session
date_default_timezone_set('Asia/Manila');
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $e) { /* ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image'])) {
    $imgData = $_POST['image'];
    $violationType = $_POST['violation_type'] ?? 'Violation';
    $confidence = $_POST['confidence'] ?? 0;
    $location = trim((string)($_POST['location'] ?? ''));
    $location = function_exists('mb_substr') ? mb_substr($location, 0, 120) : substr($location, 0, 120);

    // Clean base64 image data header
    $imgData = str_replace('data:image/jpeg;base64,', '', $imgData);
    $imgData = str_replace(' ', '+', $imgData);
    $imgRaw = base64_decode($imgData);

    // Auto-create captures directory if missing
    $uploadDir = 'captures/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Add the "location" column the first time it is needed (where the violation was captured)
    try {
        $pdo->query("SELECT location FROM violations LIMIT 1");
    } catch (Throwable $e) {
        try { $pdo->exec("ALTER TABLE violations ADD COLUMN location VARCHAR(120) NULL DEFAULT NULL"); } catch (Throwable $e2) { /* ignore */ }
    }

    // Generate unique timestamp filename (Philippine Time)
    $filename = $uploadDir . 'violation_' . date('Ymd_His') . '_' . uniqid() . '.jpg';

    if (file_put_contents($filename, $imgRaw)) {
        // Insert record into MySQL database with the Philippine Time stamp
        $stmt = $pdo->prepare("INSERT INTO violations (violation_type, confidence, filepath, timestamp, location) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$violationType, $confidence, $filename, date('Y-m-d H:i:s'), $location !== '' ? $location : null]);
        echo "Success";
    } else {
        echo "Failed to save image file.";
    }
}
?>