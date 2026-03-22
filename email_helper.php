<?php
function sendBrevoEmail($to_email, $to_name, $subject, $html_content) {
    $env      = parse_ini_file(__DIR__ . '/.env');
    $api_key  = $env['BREVO_API_KEY'];
    $from_email = $env['MAIL_FROM'];
    $from_name  = $env['MAIL_FROM_NAME'] ?? 'AnonymousReview';

    $payload = json_encode([
        'sender'     => ['name' => $from_name, 'email' => $from_email],
        'to'         => [['email' => $to_email, 'name' => $to_name]],
        'subject'    => $subject,
        'htmlContent' => $html_content
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . $api_key
        ]
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 201;
}

function approvedEmailHtml($username, $faculty_name) {
    return '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
        <div style="max-width:560px;margin:40px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#8B0000;padding:32px;text-align:center;">
                <h1 style="color:white;margin:0;font-size:22px;">AnonymousReview</h1>
                <p style="color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:14px;">Faculty Performance Evaluation System</p>
            </div>
            <div style="padding:32px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:56px;height:56px;background:#d1fae5;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <span style="font-size:28px;">✅</span>
                    </div>
                    <h2 style="color:#1f2937;margin:0;font-size:20px;">Review Approved!</h2>
                </div>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">Hi <strong>' . htmlspecialchars($username) . '</strong>,</p>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">
                    Great news! Your review for <strong style="color:#8B0000;">' . htmlspecialchars($faculty_name) . '</strong> has been approved by the admin and is now published.
                </p>
                <div style="background:#f9fafb;border-left:4px solid #8B0000;padding:14px 18px;border-radius:4px;margin:20px 0;">
                    <p style="margin:0;color:#4b5563;font-size:14px;">Your feedback helps improve the quality of education. Thank you for taking the time to share your experience!</p>
                </div>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">You can log in to view your published review on your dashboard.</p>
            </div>
            <div style="background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;">
                <p style="color:#9ca3af;font-size:12px;margin:0;">This is an automated message from AnonymousReview. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>';
}

function rejectedEmailHtml($username, $faculty_name) {
    return '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
        <div style="max-width:560px;margin:40px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#8B0000;padding:32px;text-align:center;">
                <h1 style="color:white;margin:0;font-size:22px;">AnonymousReview</h1>
                <p style="color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:14px;">Faculty Performance Evaluation System</p>
            </div>
            <div style="padding:32px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:56px;height:56px;background:#fee2e2;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <span style="font-size:28px;">❌</span>
                    </div>
                    <h2 style="color:#1f2937;margin:0;font-size:20px;">Review Rejected</h2>
                </div>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">Hi <strong>' . htmlspecialchars($username) . '</strong>,</p>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">
                    Unfortunately, your review for <strong style="color:#8B0000;">' . htmlspecialchars($faculty_name) . '</strong> has been rejected by the admin.
                </p>
                <div style="background:#f9fafb;border-left:4px solid #ef4444;padding:14px 18px;border-radius:4px;margin:20px 0;">
                    <p style="margin:0;color:#4b5563;font-size:14px;">Reviews may be rejected if they do not meet our community guidelines. Please ensure your feedback is respectful, constructive, and relevant.</p>
                </div>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">You may submit a new review following our guidelines. Log in to your dashboard to try again.</p>
            </div>
            <div style="background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;">
                <p style="color:#9ca3af;font-size:12px;margin:0;">This is an automated message from AnonymousReview. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>';
}

function welcomeEmailHtml($username) {
    return '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
        <div style="max-width:560px;margin:40px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#8B0000;padding:32px;text-align:center;">
                <h1 style="color:white;margin:0;font-size:22px;">AnonymousReview</h1>
                <p style="color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:14px;">Faculty Performance Evaluation System</p>
            </div>
            <div style="padding:32px;">
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:56px;height:56px;background:#dbeafe;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
                        <span style="font-size:28px;">👋</span>
                    </div>
                    <h2 style="color:#1f2937;margin:0;font-size:20px;">Welcome to AnonymousReview!</h2>
                </div>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">Hi <strong>' . htmlspecialchars($username) . '</strong>,</p>
                <p style="color:#4b5563;font-size:15px;line-height:1.6;">
                    Your account has been successfully created. You can now log in and start submitting anonymous reviews for your faculty members.
                </p>
                <div style="background:#f9fafb;border-left:4px solid #8B0000;padding:14px 18px;border-radius:4px;margin:20px 0;">
                    <p style="margin:0;color:#4b5563;font-size:14px;"><strong>Remember:</strong> Your reviews are completely anonymous. Help improve education by sharing honest, respectful feedback.</p>
                </div>
            </div>
            <div style="background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;">
                <p style="color:#9ca3af;font-size:12px;margin:0;">This is an automated message from AnonymousReview. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>';
}
?>