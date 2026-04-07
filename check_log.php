<?php
// Simple script to check chatbot log file
$log_file = __DIR__ . '/chatbot_errors.log';

echo "<h2>Chatbot Log File Status</h2>";
echo "<p><strong>Log file path:</strong> $log_file</p>";
echo "<p><strong>Directory writable:</strong> " . (is_writable(__DIR__) ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>') . "</p>";
echo "<p><strong>Log file exists:</strong> " . (file_exists($log_file) ? '<span style="color:green">YES</span>' : '<span style="color:red">NO</span>') . "</p>";

if (file_exists($log_file)) {
    $size = filesize($log_file);
    $modified = date('Y-m-d H:i:s', filemtime($log_file));
    echo "<p><strong>Log file size:</strong> $size bytes</p>";
    echo "<p><strong>Last modified:</strong> $modified</p>";
    
    $content = file_get_contents($log_file);
    if (!empty($content)) {
        echo "<h3>Log contents:</h3>";
        echo "<div style='background:#f5f5f5; padding:10px; border:1px solid #ccc; max-height:400px; overflow:auto;'>";
        echo "<pre style='margin:0;'>" . htmlspecialchars($content) . "</pre>";
        echo "</div>";
    } else {
        echo "<p><em>Log file is empty</em></p>";
    }
} else {
    echo "<p><strong>Status:</strong> <span style='color:red'>Log file does not exist yet</span></p>";
    echo "<p>Use the chatbot first, or <a href='create_log.php'>create it manually</a></p>";
}

echo "<hr>";
echo "<p><a href='index.php'>← Back to site</a> | <a href='create_log.php'>Create log file</a></p>";
?>