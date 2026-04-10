<?php
/**
 * chatbot.php — FAQ-only backend (InfinityFree compatible)
 * InfinityFree blocks outgoing curl to external APIs on free plans.
 * This file handles the FAQ matching server-side.
 * The AI enhancement is handled client-side via fetch in dashboard.js.
 */

header('Content-Type: application/json');

// Must include config for session
include 'config.php';

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

echo json_encode(['reply' => getFAQResponse($user_message)]);

// ── FAQ matcher ────────────────────────────────────────────────────────────
function getFAQResponse(string $msg): string {
    $lower = mb_strtolower($msg);

    // Submit / How to review
    if (preg_match('/submit|how.*(review|evaluate)|write.*review|evaluate.*faculty/i', $lower)) {
        return "To submit a review:\n1. Go to your Dashboard.\n2. Find the faculty member and click \"Evaluate\".\n3. Step 1: Select the faculty. Step 2: Rate them on 5 categories using stars, then write your review.\n4. Click Submit Review — it'll be pending until an admin approves it.";
    }

    // Anonymous / privacy
    if (preg_match('/anon(ymous)?|private|identit|who.*(see|know)|track/i', $lower)) {
        return "Yes — all reviews are 100% anonymous. Admins only see your username during moderation, never your real identity. Your review is never linked to your full name publicly.";
    }

    // Pending status
    if (preg_match('/pend(ing)?|still.*wait|not.*approved|how long/i', $lower)) {
        return "Your review is pending because it needs admin approval before it goes public. This usually takes a short time. You'll receive an in-app notification and an email once it's approved or rejected.";
    }

    // Approved status
    if (preg_match('/approv(ed)?|publish(ed)?|visible|public/i', $lower)) {
        return "Approved reviews are published and visible on the faculty's profile. You'll get a notification and email when your review is approved.";
    }

    // Rejected status / resubmit
    if (preg_match('/reject(ed)?|resubmit|denied|not accept/i', $lower)) {
        return "Rejected reviews didn't meet the guidelines — usually due to inappropriate language. You can resubmit by clicking \"Resubmit\" on the faculty card. Make sure your review is respectful and constructive.";
    }

    // Edit review
    if (preg_match('/edit|update|change.*review|modify/i', $lower)) {
        return "You can edit an approved review directly from your Dashboard — look for the edit icon next to the review. After editing, your review goes back to pending status and needs re-approval before it's published again.";
    }

    // Delete review
    if (preg_match('/delet(e|ed)|remov(e|ed)|cancel.*review/i', $lower)) {
        return "To delete a review, click the trash/delete icon on the review in your Dashboard. You can delete any of your own reviews at any time.";
    }

    // Notifications / email
    if (preg_match('/notif(ication)?|email|alert|message/i', $lower)) {
        return "You'll receive both an in-app notification and an email when your review is approved or rejected. Make sure your email address is correct in your Profile settings.";
    }

    // Profile / account
    if (preg_match('/profile|account|password|username|picture|photo|avatar/i', $lower)) {
        return "Visit the Profile page (click your avatar in the sidebar) to:\n• Update your pseudonym, username, or email\n• Change your password\n• Upload a profile picture";
    }

    // Toxic / flagged
    if (preg_match('/toxic|flag(ged)?|block(ed)?|not submit|cant submit|offensive|hate/i', $lower)) {
        return "Reviews are automatically scanned for toxic or hateful content using AI. If your review was flagged, it means it contained language that violates our guidelines. Please keep feedback respectful and constructive — focus on the faculty's teaching, not personal attacks.";
    }

    // Rating / stars
    if (preg_match('/rat(e|ing)|star(s)?|score|categor/i', $lower)) {
        return "You rate each faculty member on 5 categories (1–5 stars each):\n1. Teaching Effectiveness\n2. Communication Skills\n3. Punctuality & Availability\n4. Fairness in Grading\n5. Overall Satisfaction\n\nAll 5 ratings are required to submit a review.";
    }

    // Multiple reviews / one per faculty
    if (preg_match('/multiple|more than one|again|another review|twice|second/i', $lower)) {
        return "You can only submit one review per faculty member. If your review was rejected, you can resubmit it. If it was approved, you can edit it. You cannot submit a brand-new review for the same faculty.";
    }

    // Login / access
    if (preg_match('/login|log in|sign in|access|cant.*log|forgot.*pass/i', $lower)) {
        return "If you're having trouble logging in:\n• Double-check your username and password\n• Passwords are case-sensitive\n• Contact your administrator if you've forgotten your password — there's no self-service reset currently.";
    }

    // Greeting
    if (preg_match('/^(hi|hello|hey|good\s*(morning|afternoon|evening)|sup|yo)\b/i', $lower)) {
        return "Hi there! 👋 I'm the OlshcoReview FAQ Assistant. Ask me anything about submitting reviews, your review status, anonymity, editing, notifications, or your account!";
    }

    // Thank you
    if (preg_match('/thank(s|you)|ty\b|appreciate/i', $lower)) {
        return "You're welcome! 😊 Feel free to ask if you have more questions about OlshcoReview.";
    }

    // Default fallback
    return "I'm the OlshcoReview FAQ Assistant. I can help with:\n• How to submit or edit a review\n• Review status (pending, approved, rejected)\n• Anonymity & privacy\n• Notifications & emails\n• Profile & account settings\n• Star ratings\n\nWhat would you like to know?";
}