<?php
include "config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['faculty_id']) && !isset($_POST['faculty_id'])) {
    die("No faculty selected.");
}

$faculty_id = intval($_POST['faculty_id'] ?? $_GET['faculty_id']);
$user_id    = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) {
        header("Location: dashboard.php?error=empty");
        exit();
    }

    // Check if user already reviewed this faculty
    $exists = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM reviews WHERE user_id='$user_id' AND faculty_id='$faculty_id' LIMIT 1"));
    if ($exists) {
        header("Location: dashboard.php?error=duplicate");
        exit();
    }

    // ── Groq AI Analysis ─────────────────────────────────────────────────────
    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];

    $prompt = 'Analyze this faculty review and return valid JSON only, no explanation, no markdown:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}

A review is toxic or hateful if it contains: insults, slurs, personal attacks, threats, offensive language, harassment, or discriminatory content.

Review: "' . addslashes($review_text) . '"';

    $payload = json_encode([
        'model'    => 'llama-3.3-70b-versatile',
        'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data   = json_decode($response, true);
    $ai_raw = $data['choices'][0]['message']['content'] ?? '{}';

    // Strip markdown code fences if any
    $ai_raw = preg_replace('/```json|```/', '', $ai_raw);
    $ai     = json_decode(trim($ai_raw), true);

    $sentiment  = $ai['sentiment']  ?? 'neutral';
    $is_toxic   = !empty($ai['is_toxic'])   || !empty($ai['is_hateful']) ? 1 : 0;
    $summary    = $ai['summary']    ?? '';

    // ── Block toxic/hateful reviews ──────────────────────────────────────────
    if ($is_toxic) {
        header("Location: dashboard.php?error=toxic");
        exit();
    }

    // ── Insert review ────────────────────────────────────────────────────────
    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    $sentiment_safe   = mysqli_real_escape_string($conn, $sentiment);
    $summary_safe     = mysqli_real_escape_string($conn, $summary);

    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary)
                         VALUES ('$user_id', '$faculty_id', '$review_text_safe', 'pending', '$sentiment_safe', '$is_toxic', '$summary_safe')");

    header("Location: dashboard.php?submitted=1");
    exit();
}

$faculty_result = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
$faculty        = mysqli_fetch_assoc($faculty_result);
?>
<!DOCTYPE html>
<html>
<head><title>Submit Review</title></head>
<body>
<h2>Submit Review for <?php echo htmlspecialchars($faculty['name']); ?></h2>
<form method="POST">
    <textarea name="review_text" required placeholder="Write your anonymous review"></textarea><br><br>
    <input type="hidden" name="faculty_id" value="<?php echo $faculty_id; ?>">
    <button type="submit">Submit Review</button>
</form>
</body>
</html>