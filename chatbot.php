<?php
// Custom logging function
function chatbot_log($message) {
    $log_file = __DIR__ . '/chatbot_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message\n";

    error_log($message);
    $fp = fopen($log_file, 'a');
    if ($fp) {
        fwrite($fp, $entry);
        fclose($fp);
    }
}

header('Content-Type: application/json');

function provideFAQResponse($message) {
    $lower = strtolower($message);

    if (strpos($lower, 'submit') !== false || strpos($lower, 'review') !== false) {
        return "To submit a review: Go to Dashboard, find the faculty member, click 'Evaluate', fill out the rating form, and click 'Submit Review'. Your review will be pending until admin approval.";
    }
    if (strpos($lower, 'anonymous') !== false || strpos($lower, 'private') !== false) {
        return "Yes! All reviews are completely anonymous. Only your username is visible to admins during moderation, never your full identity.";
    }
    if (strpos($lower, 'pending') !== false || strpos($lower, 'approved') !== false || strpos($lower, 'rejected') !== false) {
        return "Review statuses: Pending = waiting for approval, Approved = published and visible, Rejected = not accepted. You can resubmit rejected reviews.";
    }
    if (strpos($lower, 'edit') !== false || strpos($lower, 'delete') !== false) {
        return "You can edit approved reviews directly from your dashboard. After editing, your review goes back to pending status. Rejected reviews can be resubmitted.";
    }
    if (strpos($lower, 'notification') !== false || strpos($lower, 'email') !== false) {
        return "You'll receive both in-app and email notifications when your review is approved or rejected.";
    }
    if (strpos($lower, 'profile') !== false) {
        return "Visit the Profile page to update your information, change your password, and upload a profile picture.";
    }
    if (strpos($lower, 'toxic') !== false || strpos($lower, 'hateful') !== false) {
        return "Reviews are automatically checked for toxic or hateful content. Please keep feedback respectful and constructive.";
    }

    return "I'm the FAQ Assistant for OlshcoReview. Ask me about submitting reviews, anonymity, review status, editing, notifications, or your profile. What would you like to know?";
}

try {
    include 'config.php';

    chatbot_log('Chatbot initialized');

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not logged in']);
        exit();
    }

    $user_message = trim($_POST['message'] ?? '');
    if (empty($user_message)) {
        echo json_encode(['error' => 'Empty message']);
        exit();
    }

    $env_file = __DIR__ . '/.env';
    if (!file_exists($env_file)) {
        chatbot_log("Chatbot: .env file not found at $env_file");
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    $env = parse_ini_file($env_file);
    if (!$env || !isset($env['GROQ_API_KEY'])) {
        chatbot_log('Chatbot: GROQ_API_KEY not set or parse_ini_file failed');
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    $api_key = trim($env['GROQ_API_KEY']);
    if (empty($api_key)) {
        chatbot_log('Chatbot: GROQ_API_KEY is empty');
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    $system_prompt = <<<'PROMPT'
You are a helpful FAQ assistant for AnonymousReview, an Anonymous Online Faculty Performance Evaluation and Feedback System. Help users with the following topics:

SUBMITTING A REVIEW:
- Go to the Dashboard, find the faculty member you want to evaluate, and click "Evaluate".
- A modal will appear. Step 1: select the faculty. Step 2: rate them on 5 categories (Teaching Effectiveness, Communication Skills, Punctuality & Availability, Fairness in Grading, Overall Satisfaction) using star ratings, then write your review text.
- Click Submit Review. Your review will be submitted as pending until approved by admin.

EDITING A REVIEW:
- Users CAN edit their own review directly from the dashboard — NO need to contact admin.
- You can only edit a review that has been APPROVED. Look for the "Edit Review" button on the faculty card or the edit icon in your My Reviews section.
- After editing, the review goes back to PENDING status and must be re-approved by the admin before it is published again.
- Pending or rejected reviews cannot be edited directly, but rejected reviews can be resubmitted.

ANONYMITY:
- All reviews are completely anonymous. Admins only see your username, not your full identity in the context of moderation.

REVIEW STATUS:
- Pending: submitted and waiting for admin approval.
- Approved: published and visible.
- Rejected: not approved. You can resubmit a rejected review by clicking "Resubmit" on the faculty card.

STAR RATINGS:
- You rate the faculty on 5 categories from 1 to 5 stars.
- Categories: Teaching Effectiveness, Communication Skills, Punctuality & Availability, Fairness in Grading, Overall Satisfaction.

NOTIFICATIONS:
- You will receive an in-app notification AND an email when your review is approved or rejected.

ACCOUNT & PROFILE:
- You can update your profile info and upload a profile picture from the Profile page.
- If you have login issues, try resetting your password or contact your administrator.

CONTENT MODERATION:
- Reviews are automatically scanned for toxic or hateful content using AI before submission.
- If your review is flagged, it will not be submitted. Please keep feedback respectful and constructive.

Keep answers short, friendly, and helpful. If you do not know something specific to this system, say so politely.
PROMPT;

    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'max_tokens' => 400,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_message]
        ]
    ]);

    if (!function_exists('curl_init')) {
        chatbot_log('Chatbot: curl extension not available');
        echo json_encode(['reply' => provideFAQResponse($user_message) . "\n\n(Chatbot running in FAQ mode)"]);
        exit();
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    if (!$ch) {
        chatbot_log('Chatbot: curl_init failed');
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curl_errno) {
        chatbot_log("Chatbot Curl Error #$curl_errno: $curl_error (HTTP $http_code)");
        curl_close($ch);
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    curl_close($ch);

    if ($response === false) {
        chatbot_log('Chatbot: curl_exec returned false');
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    if ($http_code !== 200) {
        chatbot_log("Chatbot: API returned HTTP $http_code - Response: " . substr($response, 0, 200));
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['choices'][0]['message']['content'])) {
        chatbot_log('Chatbot: Invalid API response - Data: ' . substr($response, 0, 300));
        echo json_encode(['reply' => provideFAQResponse($user_message)]);
        exit();
    }

    $reply = $data['choices'][0]['message']['content'];
    echo json_encode(['reply' => $reply]);

} catch (Exception $e) {
    chatbot_log('Chatbot Exception: ' . $e->getMessage());
    echo json_encode(['reply' => provideFAQResponse($user_message)]);
}
