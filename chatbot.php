<?php
include "config.php";
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

header('Content-Type: application/json');

$user_message = trim($_POST['message'] ?? '');
if (empty($user_message)) {
    echo json_encode(['error' => 'Empty message']);
    exit();
}

$api_key = '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';

$system_prompt = 'You are a helpful FAQ assistant for AnonymousReview, an Anonymous Online Faculty Performance Evaluation and Feedback System. Help users with: submitting a faculty review (go to Dashboard, click Evaluate next to a faculty member, write your review and submit), reviews are anonymous and go to admin for approval before being published, users get notified when their review is approved or rejected, searching for faculty using the search bar on the dashboard, account issues such as registration login and password, what happens after submitting a review (it shows as pending until admin approves), and admins can approve or reject reviews. Keep answers short, friendly, and helpful. If you do not know something specific to this system, say so politely.';

$payload = json_encode([
    'model' => 'llama3-8b-8192',
    'max_tokens' => 300,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $user_message]
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
$reply = $data['choices'][0]['message']['content'] ?? 'Sorry, I could not process that.';

echo json_encode(['reply' => $reply]);
?>