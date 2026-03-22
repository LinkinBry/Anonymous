<?php
include "config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['faculty_id'])) {
    die("No faculty selected.");
}

$faculty_id = intval($_GET['faculty_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $review_text = mysqli_real_escape_string($conn, $_POST['review_text']);

    // --- Groq AI Analysis ---
    $env = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];

    $prompt = 'Analyze this faculty review and return valid JSON only, no explanation:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "summary": "one sentence summary in English"
}

Review: "' . $_POST['review_text'] . '"';

    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'max_tokens' => 200,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $ai_raw = $data['choices'][0]['message']['content'] ?? '{}';
    $ai = json_decode($ai_raw, true);

    $sentiment = mysqli_real_escape_string($conn, $ai['sentiment'] ?? 'neutral');
    $is_toxic = isset($ai['is_toxic']) && $ai['is_toxic'] ? 1 : 0;
    $summary = mysqli_real_escape_string($conn, $ai['summary'] ?? '');
    // --- End Groq Analysis ---

    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary) 
                         VALUES ('$user_id', '$faculty_id', '$review_text', 'pending', '$sentiment', '$is_toxic', '$summary')");

    header("Location: dashboard.php?submitted=1");
    exit();
}

$faculty_result = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
$faculty = mysqli_fetch_assoc($faculty_result);
?>
<!DOCTYPE html>
<html>
<head><title>Submit Review</title></head>
<body>
<h2>Submit Review for <?php echo htmlspecialchars($faculty['name']); ?></h2>
<form method="POST">
    <textarea name="review_text" required placeholder="Write your anonymous review"></textarea><br><br>
    <button type="submit">Submit Review</button>
</form>
</body>
</html>