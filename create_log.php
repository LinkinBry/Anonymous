<?php
// Force create chatbot log file
$log_file = __DIR__ . '/chatbot_errors.log';
$message = "[" . date('Y-m-d H:i:s') . "] Log file created manually\n";

$result = file_put_contents($log_file, $message, FILE_APPEND | LOCK_EX);

if ($result !== false) {
    echo "✅ Log file created successfully!<br>";
    echo "Path: $log_file<br>";
    echo "Size: " . filesize($log_file) . " bytes<br>";
    echo "<a href='check_log.php'>Check log contents</a><br>";
    echo "<a href='index.php'>Back to site</a>";
} else {
    echo "❌ Failed to create log file<br>";
    echo "Directory writable: " . (is_writable(__DIR__) ? 'YES' : 'NO') . "<br>";
    echo "Directory: " . __DIR__ . "<br>";
}
?>