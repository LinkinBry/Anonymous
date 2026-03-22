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

$api_key = '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'; // <-- paste your key

$system_prompt = "You are a helpful FAQ assistant for AnonymousReview, an Anonymous Online Faculty Performance Evaluation and Feedback System.

You help users with:
- How to submit a faculty review (go to Dashboard, click Evaluate next to a faculty member, write your review, submit)
- Reviews are anonymous and go to admin for approval before being published
- Users get notified when their review is approved or rejected
- How to search for faculty (use the search bar on the dashboard)
- Account issues: registration, login, password
- What happens after submitting a review (it shows as 'pending' until admin approves)
- Admins can approve or reject reviews

Keep answers short, friendly, and helpful. If you don't know something specific to this system, say so politely.";

$payload = json_encode([
    'model' => 'claude-haiku-4-5-20251001',
    'max_tokens' => 300,
    'system' => $system_prompt,
    'messages' => [
        ['role' => 'user', 'content' => $user_message]
    ]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ]
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$reply = $data['content'][0]['text'] ?? 'Sorry, I could not process that.';

echo json_encode(['reply' => $reply]);
?>