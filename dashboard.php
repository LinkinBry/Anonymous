<?php
include "config.php";

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    header("Location: login.php");
    exit();
}

$query = "SELECT fullname, username, profile_pic FROM users WHERE id='$user_id' LIMIT 1";
$result = mysqli_query($conn, $query);
$user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : ['fullname' => 'User', 'username' => 'user', 'profile_pic' => null];

$avatar = !empty($user['profile_pic']) && file_exists($user['profile_pic'])
    ? $user['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($user['fullname']) . '&background=6B0000&color=fff&size=80';

$notif_count = 0;
$notifications = [];
$notif_res = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id='$user_id' ORDER BY created_at DESC LIMIT 5");
if ($notif_res && mysqli_num_rows($notif_res) > 0) {
    while ($row = mysqli_fetch_assoc($notif_res)) {
        $notifications[] = $row;
        if ($row['status'] === 'unread') $notif_count++;
    }
}

$faculties = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = mysqli_real_escape_string($conn, $_GET['search']);
    $faculty_query = "SELECT f.id, f.name, f.department,
        COALESCE(f.photo,'') AS photo,
        ROUND((AVG(r.rating_teaching)+AVG(r.rating_communication)+AVG(r.rating_punctuality)+AVG(r.rating_fairness)+AVG(r.rating_overall))/5,1) AS avg_stars,
        COUNT(r.id) AS review_count
        FROM faculties f LEFT JOIN reviews r ON r.faculty_id=f.id AND r.status='approved'
        WHERE f.name LIKE '%$search%' OR f.department LIKE '%$search%'
        GROUP BY f.id ORDER BY f.name ASC";
} else {
    $faculty_query = "SELECT f.id, f.name, f.department,
        COALESCE(f.photo,'') AS photo,
        ROUND((AVG(r.rating_teaching)+AVG(r.rating_communication)+AVG(r.rating_punctuality)+AVG(r.rating_fairness)+AVG(r.rating_overall))/5,1) AS avg_stars,
        COUNT(r.id) AS review_count
        FROM faculties f LEFT JOIN reviews r ON r.faculty_id=f.id AND r.status='approved'
        GROUP BY f.id ORDER BY f.name ASC";
}
$faculty_result = mysqli_query($conn, $faculty_query);
if ($faculty_result && mysqli_num_rows($faculty_result) > 0) {
    while ($row = mysqli_fetch_assoc($faculty_result)) $faculties[] = $row;
}

// Stats
$total_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id'"))['count'];
$pending_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='pending'"))['count'];
$approved_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='approved'"))['count'];
$rejected_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='rejected'"))['count'];

// Handle submit
if (isset($_POST['submit_review'])) {
    $faculty_id  = intval($_POST['faculty_id']);
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) {
        header("Location: dashboard.php?error=empty"); exit();
    }

    $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM reviews WHERE user_id='$user_id' AND faculty_id='$faculty_id' LIMIT 1"));
    if ($exists) {
        header("Location: dashboard.php?error=duplicate"); exit();
    }

    // Groq AI toxic check
    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];

    // Pre-process: add spaces to known concatenated Filipino profanity
    $normalized = preg_replace('/tanginamo|tangina|punyeta|gago|putangina|ulol|bobo|tanga|hinayupak|pakingshet|pakshet|tarantado|bwisit|lintik|ampota|inamo|kingina|kupalmerda|leche|pesteng yawa/i',
        ' [PROFANITY] ', $review_text);

    $prompt  = 'You are a multilingual content moderator. The review below may be written in English, Filipino, Tagalog, or a mix (Taglish). Analyze it carefully considering the language and cultural context, then return valid JSON only, no explanation, no markdown:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}

IMPORTANT RULES:
- Flag as toxic if the review contains ANY of: insults, slurs, personal attacks, threats, explicit offensive language, harassment, discriminatory content, or profanity.
- [PROFANITY] markers in the text indicate detected Filipino/Tagalog profanity — ALWAYS flag these as toxic.
- Words like "tanginamo", "putangina", "gago", "tanga", "ulol", "ampota" — with or without spaces — are profanity and MUST be flagged as toxic.
- Concatenated or misspelled profanity (e.g. "tanginamo", "pukinamo", "t4ngina") must still be flagged.
- Do NOT flag a review just because it is written in Filipino or Tagalog without profanity.
- Negative opinions about teaching style, punctuality, or performance WITHOUT profanity are NOT toxic — they are valid feedback.
- When in doubt about profanity, DO flag as toxic.

Review: "' . addslashes($normalized) . '"';

    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data   = json_decode($response, true);
    $ai_raw = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai     = json_decode(trim($ai_raw), true);

    $sentiment = mysqli_real_escape_string($conn, $ai['sentiment'] ?? 'neutral');
    $is_toxic  = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;
    $summary   = mysqli_real_escape_string($conn, $ai['summary'] ?? '');

    if ($is_toxic) {
        header("Location: dashboard.php?error=toxic"); exit();
    }

    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    $r_teaching      = intval($_POST['rating_teaching']      ?? 0);
    $r_communication = intval($_POST['rating_communication'] ?? 0);
    $r_punctuality   = intval($_POST['rating_punctuality']   ?? 0);
    $r_fairness      = intval($_POST['rating_fairness']      ?? 0);
    $r_overall       = intval($_POST['rating_overall']       ?? 0);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary, rating_teaching, rating_communication, rating_punctuality, rating_fairness, rating_overall)
                         VALUES ('$user_id','$faculty_id','$review_text_safe','pending','$sentiment','$is_toxic','$summary','$r_teaching','$r_communication','$r_punctuality','$r_fairness','$r_overall')");
    $new_review_id = mysqli_insert_id($conn);

    // Handle review photo upload with AI safety check
    if (!empty($_FILES['review_photo']['name']) && $_FILES['review_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
        $ftype = mime_content_type($_FILES['review_photo']['tmp_name']);
        if (in_array($ftype, $allowed_types) && $_FILES['review_photo']['size'] <= 5*1024*1024) {
            // AI image safety check via Groq vision
            $img_data  = base64_encode(file_get_contents($_FILES['review_photo']['tmp_name']));
            $img_safe  = true;
            $groq_key  = $env['GROQ_API_KEY'] ?? '';
            if ($groq_key) {
                $vision_payload = json_encode([
                    'model'      => 'meta-llama/llama-4-scout-17b-16e-instruct',
                    'max_tokens' => 80,
                    'messages'   => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$ftype.';base64,'.$img_data]],
                            ['type' => 'text',      'text'      => 'Does this image contain any of: explicit nudity, graphic violence, hate symbols, weapons used for harm, or illegal content? Reply ONLY with valid JSON: {"safe": true} or {"safe": false}']
                        ]
                    ]]
                ]);
                $ch2 = curl_init('https://api.groq.com/openai/v1/chat/completions');
                curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>$vision_payload, CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>false,
                    CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Authorization: Bearer '.$groq_key]]);
                $vresp = curl_exec($ch2); curl_close($ch2);
                $vdata = json_decode($vresp, true);
                $vraw  = preg_replace('/```json|```/', '', $vdata['choices'][0]['message']['content'] ?? '{"safe":true}');
                $vres  = json_decode(trim($vraw), true);
                if (isset($vres['safe']) && $vres['safe'] === false) {
                    $img_safe = false;
                }
            }
            if ($img_safe) {
                $ext      = pathinfo($_FILES['review_photo']['name'], PATHINFO_EXTENSION);
                $filename = 'uploads/review_' . $new_review_id . '_' . time() . '.' . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0755, true);
                if (move_uploaded_file($_FILES['review_photo']['tmp_name'], $filename)) {
                    $rphoto = mysqli_real_escape_string($conn, $filename);
                    mysqli_query($conn, "UPDATE reviews SET photo='$rphoto' WHERE id='$new_review_id'");
                }
            } else {
                header("Location: dashboard.php?submitted=1&photo_rejected=1"); exit();
            }
        }
    }
    header("Location: dashboard.php?submitted=1"); exit();
}
if (isset($_POST['edit_review'])) {
    $review_id   = intval($_POST['review_id']);
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) {
        header("Location: dashboard.php?error=empty"); exit();
    }

    // Groq toxic check on edit too
    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];
    $normalized = preg_replace('/tanginamo|tangina|punyeta|gago|putangina|ulol|bobo|tanga|hinayupak|pakingshet|pakshet|tarantado|bwisit|lintik|ampota|inamo|kingina|kupalmerda|leche|pesteng yawa/i',
        ' [PROFANITY] ', $review_text);
    $prompt  = 'You are a multilingual content moderator. The review below may be written in English, Filipino, Tagalog, or a mix (Taglish). Analyze it carefully considering the language and cultural context, then return valid JSON only, no explanation, no markdown:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}
IMPORTANT RULES:
- Flag as toxic if the review contains ANY of: insults, slurs, personal attacks, threats, explicit offensive language, harassment, discriminatory content, or profanity.
- [PROFANITY] markers in the text indicate detected Filipino/Tagalog profanity — ALWAYS flag these as toxic.
- Words like "tanginamo", "putangina", "gago", "tanga", "ulol", "ampota" — with or without spaces — are profanity and MUST be flagged as toxic.
- Do NOT flag a review just because it is written in Filipino or Tagalog without profanity.
- Negative opinions about teaching style, punctuality, or performance WITHOUT profanity are NOT toxic.
- When in doubt about profanity, DO flag as toxic.
Review: "' . addslashes($normalized) . '"';

    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data   = json_decode($response, true);
    $ai_raw = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai     = json_decode(trim($ai_raw), true);
    $is_toxic = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;

    if ($is_toxic) {
        header("Location: dashboard.php?error=toxic"); exit();
    }

    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    $r_teaching      = intval($_POST['rating_teaching']      ?? 0);
    $r_communication = intval($_POST['rating_communication'] ?? 0);
    $r_punctuality   = intval($_POST['rating_punctuality']   ?? 0);
    $r_fairness      = intval($_POST['rating_fairness']      ?? 0);
    $r_overall       = intval($_POST['rating_overall']       ?? 0);
    mysqli_query($conn, "UPDATE reviews SET review_text='$review_text_safe', status='pending',
        rating_teaching='$r_teaching', rating_communication='$r_communication',
        rating_punctuality='$r_punctuality', rating_fairness='$r_fairness', rating_overall='$r_overall'
        WHERE id='$review_id' AND user_id='$user_id'");
    header("Location: dashboard.php?edited=1"); exit();
}

// Handle resubmit (delete rejected + insert new)
if (isset($_POST['resubmit_review'])) {
    $old_id      = intval($_POST['old_review_id']);
    $faculty_id  = intval($_POST['faculty_id']);
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) { header("Location: dashboard.php?error=empty"); exit(); }

    // Delete old rejected review
    mysqli_query($conn, "DELETE FROM reviews WHERE id='$old_id' AND user_id='$user_id' AND status='rejected'");

    // Groq toxic check
    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];
    $prompt  = 'You are a multilingual content moderator. The review below may be written in English, Filipino, Tagalog, or a mix (Taglish). Analyze it carefully considering the language and cultural context, then return valid JSON only, no explanation, no markdown:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}
IMPORTANT RULES:
- Only flag as toxic or hateful if the review contains CLEAR insults, slurs, personal attacks, threats, explicit offensive language, harassment, or discriminatory content.
- Do NOT flag a review just because it is written in Filipino or Tagalog.
- Negative opinions about teaching style, punctuality, or performance are NOT toxic.
- When in doubt, do NOT flag as toxic.
Review: "' . addslashes($review_text) . '"';

    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch); curl_close($ch);
    $data   = json_decode($response, true);
    $ai_raw = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai     = json_decode(trim($ai_raw), true);
    $sentiment = mysqli_real_escape_string($conn, $ai['sentiment'] ?? 'neutral');
    $is_toxic  = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;
    $summary   = mysqli_real_escape_string($conn, $ai['summary'] ?? '');

    if ($is_toxic) { header("Location: dashboard.php?error=toxic"); exit(); }

    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary)
                         VALUES ('$user_id','$faculty_id','$review_text_safe','pending','$sentiment','$is_toxic','$summary')");
    header("Location: dashboard.php?submitted=1"); exit();
}

// Handle delete
if (isset($_POST['delete_review'])) {
    $review_id = intval($_POST['review_id']);
    mysqli_query($conn, "DELETE FROM reviews WHERE id='$review_id' AND user_id='$user_id'");
    header("Location: dashboard.php?deleted=1");
    exit();
}

// Fetch faculties grouped by department for the modal
$departments = [];
$dept_res = mysqli_query($conn, "SELECT id, name, department FROM faculties ORDER BY department ASC, name ASC");
if ($dept_res && mysqli_num_rows($dept_res) > 0) {
    while ($row = mysqli_fetch_assoc($dept_res)) {
        $dept = $row['department'] ?: 'General';
        $departments[$dept][] = $row;
    }
}

// Build map of faculty_id => user's review (include ratings)
$user_reviews_map = [];
$urev_res = mysqli_query($conn, "SELECT r.id, r.faculty_id, r.review_text, r.status, r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness, r.rating_overall FROM reviews r WHERE r.user_id='$user_id'");
if ($urev_res) {
    while ($row = mysqli_fetch_assoc($urev_res)) {
        $user_reviews_map[$row['faculty_id']] = $row;
    }
}

// Fetch user's reviews (all - filtering done client-side)
$review_filter = isset($_GET['review_filter']) ? $_GET['review_filter'] : 'all';
$recent_reviews = [];
$recent_res = mysqli_query($conn, "
    SELECT r.id, r.faculty_id, r.review_text, r.status, r.created_at, f.name AS faculty_name, f.department,
        r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness, r.rating_overall
    FROM reviews r
    JOIN faculties f ON r.faculty_id = f.id
    WHERE r.user_id='$user_id'
    ORDER BY r.created_at DESC
");
if ($recent_res && mysqli_num_rows($recent_res) > 0) {
    while ($row = mysqli_fetch_assoc($recent_res)) $recent_reviews[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<style>
:root {
    --maroon: #8B0000;
    --maroon-dark: #6B0000;
    --maroon-light: #a30000;
    --maroon-pale: #fff5f5;
    --sidebar-w: 240px;
    --white: #ffffff;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-400: #9ca3af;
    --gray-600: #4b5563;
    --gray-800: #1f2937;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.10);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.13);
    --radius: 14px;
    --radius-sm: 8px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--gray-100);
    color: var(--gray-800);
    min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
    position: fixed; left: 0; top: 0;
    width: var(--sidebar-w); height: 100%;
    background: var(--maroon);
    display: flex; flex-direction: column;
    padding: 28px 16px 20px;
    box-shadow: 2px 0 12px rgba(139,0,0,0.18);
    z-index: 100;
}

.sidebar-brand {
    font-family: 'Playfair Display', serif;
    font-size: 17px;
    color: white;
    text-align: center;
    margin-bottom: 24px;
    line-height: 1.3;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.15);
}

.sidebar-avatar {
    width: 70px; height: 70px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,0.4);
    display: block; margin: 0 auto 10px;
    object-fit: cover;
}

.sidebar-name {
    text-align: center;
    color: white;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 6px;
}

.sidebar-role {
    text-align: center;
    color: rgba(255,255,255,0.6);
    font-size: 11px;
    margin-bottom: 24px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.sidebar nav { flex: 1; }

.nav-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: rgba(255,255,255,0.4);
    padding: 0 10px;
    margin-bottom: 6px;
    margin-top: 16px;
}

.sidebar a {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    margin-bottom: 2px;
}
.sidebar a:hover, .sidebar a.active {
    background: rgba(255,255,255,0.15);
    color: white;
}
.sidebar a svg { flex-shrink: 0; opacity: 0.8; }

.sidebar-footer {
    border-top: 1px solid rgba(255,255,255,0.15);
    padding-top: 14px;
}

/* ── Main ── */
.main { margin-left: var(--sidebar-w); padding: 32px 32px 60px; min-height: 100vh; }

/* ── Top bar ── */
.topbar {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 28px;
}
.topbar-left h1 {
    font-family: 'Playfair Display', serif;
    font-size: 26px; color: var(--gray-800);
}
.topbar-left p { color: var(--gray-400); font-size: 14px; margin-top: 3px; }
.topbar-right { display: flex; align-items: center; gap: 14px; }
.today-date {
    font-size: 13px; color: var(--gray-400);
    background: white; padding: 6px 14px;
    border-radius: 20px; border: 1px solid var(--gray-200);
}

/* Notification bell */
.notif-wrap { position: relative; cursor: pointer; }
.notif-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: white; border: 1px solid var(--gray-200);
    display: flex; align-items: center; justify-content: center;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.2s;
}
.notif-btn:hover { box-shadow: var(--shadow-md); }
.notif-badge {
    position: absolute; top: -3px; right: -3px;
    background: var(--maroon); color: white;
    font-size: 10px; font-weight: 700;
    border-radius: 50%; width: 18px; height: 18px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid var(--gray-100);
}
.notif-dropdown {
    display: none; position: absolute; right: 0; top: 46px;
    background: white; border-radius: var(--radius); border: 1px solid var(--gray-200);
    width: 320px; box-shadow: var(--shadow-lg); z-index: 200; overflow: hidden;
}
.notif-dropdown-header {
    padding: 14px 16px; border-bottom: 1px solid var(--gray-100);
    font-weight: 600; font-size: 14px; color: var(--gray-800);
    display: flex; justify-content: space-between; align-items: center;
}
.notif-item { padding: 12px 16px; border-bottom: 1px solid var(--gray-100); font-size: 13px; }
.notif-item.notif-unread { background: #fafafa; }
.notif-item small { display: block; color: var(--gray-400); font-size: 11px; margin-top: 4px; }
.notif-empty { padding: 28px 16px; color: var(--gray-400); font-size: 13px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 8px; }

/* ── Stat Cards ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card {
    background: white; border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    border-top: 3px solid transparent;
    transition: box-shadow 0.2s, transform 0.2s;
    position: relative; overflow: hidden;
}
.stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.stat-card.total  { border-top-color: var(--gray-400); }
.stat-card.pending { border-top-color: #f59e0b; }
.stat-card.approved { border-top-color: #10b981; }
.stat-card.rejected { border-top-color: #ef4444; }
.stat-label { font-size: 12px; color: var(--gray-400); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
.stat-value { font-size: 32px; font-weight: 700; line-height: 1; }
.stat-card.total .stat-value  { color: var(--gray-600); }
.stat-card.pending .stat-value { color: #f59e0b; }
.stat-card.approved .stat-value { color: #10b981; }
.stat-card.rejected .stat-value { color: #ef4444; }
.stat-icon {
    position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
}

/* ── Search ── */
.section-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px;
}
.section-title { font-size: 18px; font-weight: 600; color: var(--gray-800); }
.search-form {
    display: flex; align-items: center; gap: 8px;
    background: white; border: 1px solid var(--gray-200);
    border-radius: 30px; padding: 6px 14px;
    box-shadow: var(--shadow-sm);
}
.search-form input {
    border: none; outline: none; font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: var(--gray-800); width: 200px; background: transparent;
}
.search-form button {
    background: var(--maroon); color: white;
    border: none; border-radius: 20px;
    padding: 5px 14px; font-size: 13px; cursor: pointer;
    font-family: 'DM Sans', sans-serif; font-weight: 500;
    transition: background 0.2s;
}
.search-form button:hover { background: var(--maroon-light); }
.search-clear {
    cursor: pointer; color: var(--gray-400);
    font-size: 16px; line-height: 1;
    display: none;
}

/* ── Faculty Grid ── */
.faculty-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 18px;
    margin-bottom: 32px;
}
.faculty-card {
    background: white; border-radius: var(--radius);
    padding: 24px 20px; text-align: center;
    box-shadow: var(--shadow-sm); border: 1px solid var(--gray-200);
    transition: all 0.25s; cursor: pointer;
}
.faculty-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
    border-color: var(--maroon);
}
.faculty-card img {
    width: 70px; height: 70px; border-radius: 50%;
    margin-bottom: 14px;
    border: 3px solid var(--maroon-pale);
}
.faculty-card h3 { font-size: 15px; font-weight: 600; color: var(--gray-800); margin-bottom: 4px; }
.faculty-card p { font-size: 12px; color: var(--gray-400); margin-bottom: 14px; }
.faculty-card a {
    display: inline-block; text-decoration: none;
    background: var(--maroon); color: white;
    padding: 7px 18px; border-radius: 20px;
    font-size: 13px; font-weight: 500;
    transition: background 0.2s;
}
.faculty-card a:hover { background: var(--maroon-light); }

/* ── Empty state ── */
.empty-state {
    text-align: center; padding: 60px 20px;
    background: white; border-radius: var(--radius);
    border: 1px solid var(--gray-200);
}
.empty-state svg { margin-bottom: 16px; opacity: 0.35; }
.empty-state p { color: var(--gray-400); font-size: 15px; }

/* ── Review Section ── */
.review-section {
    background: white; border-radius: var(--radius);
    padding: 28px; box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200); margin-bottom: 28px;
}
.review-section h2 {
    font-size: 18px; font-weight: 600;
    color: var(--maroon); margin-bottom: 20px;
    padding-bottom: 14px; border-bottom: 1px solid var(--gray-100);
}
.review-list { list-style: none; }
.review-item {
    padding: 14px 0; border-bottom: 1px solid var(--gray-100);
    font-size: 14px; color: var(--gray-600); line-height: 1.6;
}
.review-item:last-child { border-bottom: none; }
.review-meta { font-size: 12px; color: var(--gray-400); margin-top: 5px; }
.status-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 20px; margin-left: 6px;
}
.status-pending  { background: #fef3c7; color: #92400e; }
.status-approved { background: #d1fae5; color: #065f46; }
.status-rejected { background: #fee2e2; color: #991b1b; }

/* Submit form */
.submit-form h3 { font-size: 15px; font-weight: 600; color: var(--gray-800); margin-bottom: 12px; margin-top: 20px; }
.submit-form textarea {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--gray-200); border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    resize: vertical; outline: none; color: var(--gray-800);
    transition: border-color 0.2s;
    min-height: 100px;
}
.submit-form textarea:focus { border-color: var(--maroon); }
.submit-btn {
    margin-top: 10px;
    background: var(--maroon); color: white;
    border: none; padding: 10px 24px;
    border-radius: 20px; font-size: 14px; font-weight: 500;
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: background 0.2s;
}
.submit-btn:hover { background: var(--maroon-light); }
.success-msg {
    background: #d1fae5; color: #065f46;
    padding: 10px 16px; border-radius: var(--radius-sm);
    font-size: 13px; margin-bottom: 14px;
}

/* ── Review Card ── */
.review-card {
    background: white; border-radius: var(--radius);
    padding: 24px 26px; box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200); margin-bottom: 28px;
}
.review-card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; padding-bottom: 14px;
    border-bottom: 1px solid var(--gray-100);
}
.review-card-title {
    font-size: 16px; font-weight: 600; color: var(--gray-800);
    display: flex; align-items: center; gap: 8px;
}
.write-review-btn {
    display: inline-flex; align-items: center; gap: 7px;
    background: var(--maroon); color: white;
    border: none; border-radius: 20px;
    padding: 8px 18px; font-size: 13px; font-weight: 500;
    cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: background 0.2s, transform 0.15s;
    text-decoration: none;
}
.write-review-btn:hover { background: var(--maroon-light); transform: translateY(-1px); }

/* Empty review state */
.review-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 40px 20px; text-align: center;
}
.review-empty-icon {
    width: 72px; height: 72px; border-radius: 50%;
    background: var(--maroon-pale);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 16px;
}
.review-empty p { color: var(--gray-400); font-size: 14px; margin-bottom: 18px; }

/* Review rows */
.review-row {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 0; border-bottom: 1px solid var(--gray-100);
}
.review-row:last-child { border-bottom: none; }
.review-row-icon {
    width: 38px; height: 38px; border-radius: 50%;
    background: var(--maroon-pale);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; margin-top: 2px;
}
.review-row-body { flex: 1; min-width: 0; }
.review-row-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
.review-row-faculty { font-size: 14px; font-weight: 600; color: var(--gray-800); }
.review-row-dept { font-size: 11px; color: var(--gray-400); margin-bottom: 4px; }
.review-row-text { font-size: 13px; color: var(--gray-600); line-height: 1.5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 480px; }
.review-row-date { font-size: 11px; color: var(--gray-400); margin-top: 4px; }

/* ── Modal ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.5); z-index: 1000;
    align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: var(--radius);
    width: 100%; max-width: 560px; max-height: 88vh;
    overflow-y: auto; box-shadow: var(--shadow-lg);
    animation: slideUp 0.25s ease;
}
@keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid var(--gray-100);
    position: sticky; top: 0; background: white; z-index: 2;
}
.modal-header h3 { font-size: 17px; font-weight: 600; color: var(--gray-800); }
.modal-close {
    width: 30px; height: 30px; border-radius: 50%;
    background: var(--gray-100); border: none; cursor: pointer;
    font-size: 18px; display: flex; align-items: center; justify-content: center;
    color: var(--gray-600); transition: background 0.2s;
}
.modal-close:hover { background: var(--gray-200); }
.modal-body { padding: 24px; }

/* Steps */
.step { display: none; }
.step.active { display: block; }
.step-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--gray-400); margin-bottom: 14px; font-weight: 600; }

/* Department accordion */
.dept-group { margin-bottom: 10px; border: 1px solid var(--gray-200); border-radius: var(--radius-sm); overflow: hidden; }
.dept-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; cursor: pointer;
    background: var(--gray-50); font-size: 14px; font-weight: 600;
    color: var(--gray-800); transition: background 0.2s;
    user-select: none;
}
.dept-header:hover { background: var(--gray-100); }
.dept-header .chevron { transition: transform 0.2s; font-size: 12px; color: var(--gray-400); }
.dept-header.open .chevron { transform: rotate(180deg); }
.dept-body { display: none; padding: 8px; background: white; }
.dept-body.open { display: block; }
.faculty-option {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: var(--radius-sm);
    cursor: pointer; transition: background 0.15s;
    font-size: 14px; color: var(--gray-700);
}
.faculty-option:hover { background: var(--maroon-pale); color: var(--maroon); }
.faculty-option img { width: 34px; height: 34px; border-radius: 50%; }
.faculty-option.selected { background: var(--maroon-pale); color: var(--maroon); font-weight: 600; }

/* Selected faculty preview */
.selected-preview {
    display: none; align-items: center; gap: 12px;
    background: var(--maroon-pale); border-radius: var(--radius-sm);
    padding: 12px 16px; margin-bottom: 16px;
}
.selected-preview.show { display: flex; }
.selected-preview img { width: 42px; height: 42px; border-radius: 50%; }
.selected-preview-info strong { font-size: 14px; color: var(--maroon); display: block; }
.selected-preview-info span { font-size: 12px; color: var(--gray-400); }
.change-faculty-btn {
    margin-left: auto; font-size: 12px; color: var(--maroon);
    background: none; border: 1px solid var(--maroon);
    border-radius: 12px; padding: 4px 10px; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: all 0.2s;
}
.change-faculty-btn:hover { background: var(--maroon); color: white; }

/* Textarea */
.modal-textarea {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--gray-200); border-radius: var(--radius-sm);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    resize: vertical; outline: none; color: var(--gray-800);
    min-height: 120px; transition: border-color 0.2s;
}
.modal-textarea:focus { border-color: var(--maroon); }
.modal-footer {
    display: flex; justify-content: flex-end; gap: 10px;
    padding: 16px 24px; border-top: 1px solid var(--gray-100);
    position: sticky; bottom: 0; background: white;
}
.btn-secondary {
    padding: 9px 20px; border-radius: 20px;
    border: 1px solid var(--gray-200); background: white;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; color: var(--gray-600);
    transition: background 0.2s;
}
.btn-secondary:hover { background: var(--gray-100); }
.btn-primary {
    padding: 9px 22px; border-radius: 20px;
    background: var(--maroon); color: white; border: none;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: background 0.2s;
}
.btn-primary:hover { background: var(--maroon-light); }
.btn-primary:disabled { background: var(--gray-400); cursor: not-allowed; }

.success-banner {
    background: #d1fae5; color: #065f46; border-radius: var(--radius-sm);
    padding: 12px 16px; font-size: 13px; margin-bottom: 20px;
    display: flex; align-items: center; gap: 8px;
}

/* ── Filter Tabs ── */
.filter-tabs {
    display: flex; gap: 6px; margin-bottom: 18px; flex-wrap: wrap;
}
.filter-tab {
    padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500;
    text-decoration: none; color: var(--gray-600);
    border: 1px solid var(--gray-200); background: white;
    transition: all 0.18s;
}
.filter-tab:hover { border-color: var(--maroon); color: var(--maroon); }
.filter-tab.active { background: var(--maroon); color: white; border-color: var(--maroon); }

/* Filter select */
.filter-select {
    padding: 7px 12px; border-radius: 20px;
    border: 1px solid var(--gray-200); background: white;
    font-size: 13px; font-family: 'DM Sans', sans-serif;
    color: var(--gray-600); outline: none; cursor: pointer;
    transition: border-color 0.2s;
}
.filter-select:focus { border-color: var(--maroon); }

.btn-evaluate {
    display: inline-flex; align-items: center; gap: 5px;
    text-decoration: none;
    background: var(--maroon); color: white;
    border: none; padding: 7px 18px; border-radius: 20px;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: background 0.2s;
}
.btn-evaluate:hover { background: var(--maroon-light); }
.btn-edit { background: var(--maroon); }
.btn-edit:hover { background: var(--maroon-light); }
.btn-reviewed { background: var(--gray-400); cursor: default; pointer-events: none; }
.btn-reviewed:hover { background: var(--gray-400); }
.btn-rejected-card { background: #ef4444; pointer-events: auto; cursor: pointer; }
.btn-rejected-card:hover { background: #dc2626; }

/* Action icon buttons */
.action-icon-btn {
    width: 26px; height: 26px; border-radius: 6px;
    border: 1px solid var(--gray-200); background: white;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--gray-400); transition: all 0.18s;
}
.action-icon-btn:hover { border-color: #1d4ed8; color: #1d4ed8; background: #eff6ff; }
.action-icon-del:hover { border-color: #ef4444; color: #ef4444; background: #fee2e2; }

/* ── Pagination ── */
.pagination {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; margin: 18px 0 28px;
}
.page-btn {
    min-width: 36px; height: 36px; border-radius: 8px;
    border: 1px solid var(--gray-200); background: white;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: 'DM Sans', sans-serif; color: var(--gray-600);
    transition: all 0.18s; display: flex; align-items: center; justify-content: center;
    padding: 0 10px;
}
.page-btn:hover { border-color: var(--maroon); color: var(--maroon); }
.page-btn.active { background: var(--maroon); color: white; border-color: var(--maroon); }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-info { font-size: 12px; color: var(--gray-400); margin: 0 6px; }

/* FAQ suggestions */
.faq-suggestions {
    padding: 8px 10px 6px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; flex-direction: column; gap: 5px;
    background: white;
}
.faq-label {
    font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
    color: var(--gray-400); font-weight: 600; margin-bottom: 2px; padding: 0 2px;
}
.faq-chip {
    display: block; padding: 6px 10px;
    background: var(--maroon-pale); color: var(--maroon);
    border: 1px solid rgba(139,0,0,0.15); border-radius: 14px;
    font-size: 12px; cursor: pointer; text-align: left;
    font-family: 'DM Sans', sans-serif; transition: all 0.18s; width: 100%;
}
.faq-chip:hover { background: var(--maroon); color: white; border-color: var(--maroon); }

/* ── Star Rating ── */
.rating-group { margin-bottom: 14px; }
.rating-group-label { font-size: 13px; font-weight: 500; color: var(--gray-700, #374151); margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; }
.rating-group-label span { font-size: 11px; color: var(--gray-400); font-weight: 400; }
.stars { display: flex; gap: 4px; flex-direction: row-reverse; justify-content: flex-end; }
.stars input { display: none; }
.stars label {
    font-size: 26px; cursor: pointer; color: #d1d5db;
    transition: color 0.15s; line-height: 1;
}
.stars label:hover,
.stars label:hover ~ label,
.stars input:checked ~ label { color: #f59e0b; }
.rating-section-title {
    font-size: 12px; text-transform: uppercase; letter-spacing: 1px;
    color: var(--gray-400); font-weight: 600; margin: 16px 0 10px;
    padding-bottom: 6px; border-bottom: 1px solid var(--gray-100);
}
.avg-rating { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--gray-600); }
.avg-stars { color: #f59e0b; font-size: 14px; letter-spacing: 1px; }

/* Review rows pagination */
.review-pagination {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; padding: 14px 0 2px;
    border-top: 1px solid var(--gray-100); margin-top: 6px;
}

#chat-bubble {
    position: fixed; bottom: 24px; right: 24px;
    width: 54px; height: 54px;
    background: var(--maroon); border-radius: 50%;
    cursor: pointer; z-index: 9999;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 16px rgba(139,0,0,0.35);
    transition: transform 0.2s, box-shadow 0.2s;
}
#chat-bubble:hover { transform: scale(1.08); box-shadow: 0 6px 24px rgba(139,0,0,0.4); }
#chat-window {
    display: none; position: fixed; bottom: 90px; right: 24px;
    width: 330px; max-height: 500px;
    background: white; border-radius: var(--radius);
    box-shadow: var(--shadow-lg); z-index: 9999;
    flex-direction: column; overflow: hidden;
    border: 1px solid var(--gray-200);
}
#chat-header {
    background: var(--maroon); color: white;
    padding: 14px 16px; font-weight: 500; font-size: 14px;
    display: flex; justify-content: space-between; align-items: center;
}
#chat-header span { cursor: pointer; font-size: 20px; opacity: 0.8; }
#chat-messages {
    flex: 1; overflow-y: auto; padding: 14px;
    display: flex; flex-direction: column; gap: 8px;
}
.chat-msg {
    border-radius: 10px; padding: 9px 12px;
    max-width: 88%; font-size: 13px; line-height: 1.5; word-wrap: break-word;
}
.chat-msg.bot { background: var(--gray-100); align-self: flex-start; color: var(--gray-800); }
.chat-msg.user { background: var(--maroon); color: white; align-self: flex-end; }
#chat-footer {
    padding: 10px; border-top: 1px solid var(--gray-100);
    display: flex; gap: 8px;
}
#chat-input {
    flex: 1; padding: 9px 12px;
    border: 1px solid var(--gray-200); border-radius: 20px;
    font-size: 13px; outline: none; font-family: 'DM Sans', sans-serif;
}
#chat-input:focus { border-color: var(--maroon); }
#chat-send {
    background: var(--maroon); color: white; border: none;
    border-radius: 50%; width: 36px; height: 36px;
    cursor: pointer; font-size: 15px; display: flex;
    align-items: center; justify-content: center;
    transition: background 0.2s;
}
#chat-send:hover { background: var(--maroon-light); }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">AnonymousReview</div>
    <a href="profile.php" style="display:block;text-align:center;margin-bottom:0;" title="Edit Profile">
        <img src="<?php echo htmlspecialchars($avatar); ?>" class="sidebar-avatar" alt="Avatar" style="transition:opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
    </a>
    <div class="sidebar-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
    <div class="sidebar-role">@<?php echo htmlspecialchars($user['username']); ?></div>
    <nav>
        <div class="nav-label">Menu</div>
        <a href="dashboard.php" class="active">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="#">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Evaluation History
        </a>
        <a href="avatar.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profile
        </a>
        <a href="#" onclick="toggleChat(); return false;">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            FAQ Chat
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Welcome back, <?php echo htmlspecialchars($user['username']); ?>!</h1>
            <p>Here's what's happening with your faculty evaluations.</p>
        </div>
        <div class="topbar-right">
            <div class="today-date">
                📅 <?php echo date("F j, Y"); ?>
            </div>
            <!-- Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <div class="notif-btn">
                    <svg width="18" height="18" fill="none" stroke="#4b5563" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                </div>
                <?php if ($notif_count > 0): ?>
                    <div class="notif-badge"><?php echo $notif_count; ?></div>
                <?php endif; ?>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-dropdown-header">
                        <span style="display:flex;align-items:center;gap:7px;">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                            Notifications
                        </span>
                        <?php if (!empty($notifications)): ?>
                        <button onclick="clearNotifications(event)" style="font-size:11px;color:var(--maroon);background:none;border:1px solid var(--maroon);border-radius:10px;padding:2px 10px;cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;transition:all 0.2s;" onmouseover="this.style.background='var(--maroon)';this.style.color='white'" onmouseout="this.style.background='none';this.style.color='var(--maroon)'">
                            Clear all
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                            <div class="notif-item notif-<?php echo $n['status']; ?>">
                                <span style="display:flex;align-items:flex-start;gap:8px;">
                                    <?php if (strpos($n['message'], 'approved') !== false): ?>
                                        <svg width="14" height="14" style="flex-shrink:0;margin-top:2px;color:#10b981;" fill="none" stroke="#10b981" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    <?php else: ?>
                                        <svg width="14" height="14" style="flex-shrink:0;margin-top:2px;" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($n['message']); ?></span>
                                </span>
                                <small><?php echo date("M j, g:i A", strtotime($n['created_at'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty">
                            <svg width="28" height="28" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                            <p>No notifications yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-label">Total Reviews</div>
            <div class="stat-value"><?php echo $total_reviews; ?></div>
            <div class="stat-icon">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(75,85,99,0.1);display:flex;align-items:center;justify-content:center;">
                    <svg width="22" height="22" fill="none" stroke="#4b5563" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </div>
            </div>
        </div>
        <div class="stat-card pending">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo $pending_count; ?></div>
            <div class="stat-icon">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;">
                    <svg width="22" height="22" fill="none" stroke="#f59e0b" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
        </div>
        <div class="stat-card approved">
            <div class="stat-label">Approved</div>
            <div class="stat-value"><?php echo $approved_count; ?></div>
            <div class="stat-icon">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(16,185,129,0.12);display:flex;align-items:center;justify-content:center;">
                    <svg width="22" height="22" fill="none" stroke="#10b981" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-label">Rejected</div>
            <div class="stat-value"><?php echo $rejected_count; ?></div>
            <div class="stat-icon">
                <div style="width:48px;height:48px;border-radius:50%;background:rgba(239,68,68,0.12);display:flex;align-items:center;justify-content:center;">
                    <svg width="22" height="22" fill="none" stroke="#ef4444" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Faculty Section -->
    <div class="section-header">
        <div class="section-title">Faculty Members</div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <!-- Dept filter -->
            <select id="deptFilter" class="filter-select" onchange="filterFaculty()">
                <option value="all">All Departments</option>
                <?php foreach (array_keys($departments) as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                <?php endforeach; ?>
            </select>
            <!-- Search -->
            <form class="search-form" method="GET" action="dashboard.php">
                <?php if ($review_filter !== 'all'): ?><input type="hidden" name="review_filter" value="<?php echo htmlspecialchars($review_filter); ?>"><?php endif; ?>
                <svg width="15" height="15" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" name="search" placeholder="Search faculty..."
                    value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                <span class="search-clear" id="clearSearch">×</span>
                <button type="submit">Search</button>
            </form>
        </div>
    </div>

    <!-- Faculty Grid -->
    <?php if (!empty($faculties)): ?>
    <div class="faculty-grid" id="facultyGrid">
        <?php foreach ($faculties as $i => $faculty): 
            $user_review = $user_reviews_map[$faculty['id']] ?? null;
            $has_reviewed = $user_review !== null;
            $review_status = $has_reviewed ? $user_review['status'] : null;
        ?>
        <div class="faculty-card" data-index="<?php echo $i; ?>" data-dept="<?php echo htmlspecialchars($faculty['department'] ?? ''); ?>">
            <img src="<?php echo (!empty($faculty['photo']) && file_exists($faculty['photo'])) ? htmlspecialchars($faculty['photo']) : 'https://ui-avatars.com/api/?name='.urlencode($faculty['name']).'&background=8B0000&color=fff&size=80'; ?>" alt="Faculty">
            <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
            <p><?php echo htmlspecialchars($faculty['department'] ?? ''); ?></p>
            <?php
            $avg = floatval($faculty['avg_stars'] ?? 0);
            if ($avg > 0):
                // SVG clipPath stars — pixel-perfect
                $pct  = min(100, ($avg / 5) * 100);
                $sz   = 16; $gap = 3;
                $w    = $sz * 5 + $gap * 4;
                $uid  = 'ds' . substr(md5($faculty['id'] . $avg), 0, 7);
                $cw   = round($pct / 100 * $w, 2);
                $empty = $filled = '';
                for ($i = 0; $i < 5; $i++) {
                    $x = $i * ($sz + $gap);
                    $empty  .= '<text x="'.$x.'" y="'.$sz.'" font-size="'.$sz.'" fill="#d1d5db">★</text>';
                    $filled .= '<text x="'.$x.'" y="'.$sz.'" font-size="'.$sz.'" fill="#f59e0b">★</text>';
                }
            ?>
            <div style="margin-bottom:10px;display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;">
                <svg width="<?php echo $w; ?>" height="<?php echo $sz; ?>" viewBox="0 0 <?php echo $w; ?> <?php echo $sz; ?>" xmlns="http://www.w3.org/2000/svg" style="display:block;vertical-align:middle;">
                    <defs><clipPath id="<?php echo $uid; ?>"><rect x="0" y="0" width="<?php echo $cw; ?>" height="<?php echo $sz; ?>"/></clipPath></defs>
                    <?php echo $empty; ?>
                    <g clip-path="url(#<?php echo $uid; ?>)"><?php echo $filled; ?></g>
                </svg>
                <span style="font-size:12px;color:var(--gray-400);"><?php echo number_format($avg,1); ?> (<?php echo $faculty['review_count']; ?>)</span>
            </div>
            <?php endif; ?>
            <?php if ($has_reviewed): ?>
                <span class="status-badge status-<?php echo $review_status; ?>" style="display:inline-flex;align-items:center;gap:4px;margin-bottom:10px;">
                    <?php if ($review_status === 'pending'): ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending
                    <?php elseif ($review_status === 'approved'): ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Approved
                    <?php else: ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rejected
                    <?php endif; ?>
                </span><br>
                <?php if ($review_status === 'approved'): ?>
                    <button class="btn-evaluate btn-edit" onclick="openEditModal(<?php echo $user_review['id']; ?>, '<?php echo htmlspecialchars(addslashes($user_review['review_text'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>', <?php echo $faculty['id']; ?>, <?php echo intval($user_review['rating_teaching']); ?>, <?php echo intval($user_review['rating_communication']); ?>, <?php echo intval($user_review['rating_punctuality']); ?>, <?php echo intval($user_review['rating_fairness']); ?>, <?php echo intval($user_review['rating_overall']); ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit Review
                    </button>
                <?php elseif ($review_status === 'pending'): ?>
                    <span class="btn-evaluate btn-reviewed">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Awaiting Review
                    </span>
                <?php else: ?>
                    <button class="btn-evaluate btn-reviewed btn-rejected-card" onclick="openDeleteAndResubmitModal(<?php echo $user_review['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', <?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>')">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Resubmit
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <button class="btn-evaluate" onclick="openModalForFaculty(<?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>')">
                    Evaluate
                </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- Pagination -->
    <div class="pagination" id="pagination"></div>
    <?php else: ?>
    <div class="empty-state">
        <svg width="64" height="64" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <p>No faculty found<?php echo isset($_GET['search']) ? ' for your search.' : '.'; ?></p>
    </div>
    <?php endif; ?>

    <!-- Recent Reviews Card -->
    <div class="review-card">
        <div class="review-card-header">
            <div class="review-card-title">
                <svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                My Reviews
            </div>
            <button class="write-review-btn" onclick="openReviewModal()">
                <svg width="14" height="14" fill="none" stroke="white" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Write a Review
            </button>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs" id="reviewFilterTabs">
            <a href="#reviews" onclick="setReviewFilter('all',event)" class="filter-tab <?php echo $review_filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="#reviews" onclick="setReviewFilter('pending',event)" class="filter-tab <?php echo $review_filter === 'pending' ? 'active' : ''; ?>">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending
            </a>
            <a href="#reviews" onclick="setReviewFilter('approved',event)" class="filter-tab <?php echo $review_filter === 'approved' ? 'active' : ''; ?>">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Approved
            </a>
            <a href="#reviews" onclick="setReviewFilter('rejected',event)" class="filter-tab <?php echo $review_filter === 'rejected' ? 'active' : ''; ?>">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rejected
            </a>
        </div>

        <?php if (isset($_GET['submitted'])): ?>
            <div class="success-banner"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Your review has been submitted and is pending admin approval.
            <?php if (isset($_GET['photo_rejected'])): ?> <span style="color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:6px;font-size:11px;margin-left:6px;">Note: your photo was flagged by AI safety check and was not attached.</span><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['edited'])): ?>
            <div class="success-banner"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Your review has been updated and is pending re-approval.</div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="success-banner" style="background:#fee2e2;color:#991b1b;"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg> Your review has been deleted.</div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'toxic'): ?>
            <div class="success-banner" style="background:#fee2e2;color:#991b1b;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Your review was flagged as hateful or offensive and could not be submitted. Please keep feedback respectful and constructive.
            </div>
            <?php elseif ($_GET['error'] === 'duplicate'): ?>
            <div class="success-banner" style="background:#fef3c7;color:#92400e;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                You have already submitted a review for this faculty member.
            </div>
            <?php elseif ($_GET['error'] === 'empty'): ?>
            <div class="success-banner" style="background:#fef3c7;color:#92400e;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Review cannot be empty.
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($recent_reviews)): ?>
        <div class="review-empty" id="reviewEmptyState">
            <div class="review-empty-icon">
                <svg width="32" height="32" fill="none" stroke="var(--maroon)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <p><?php echo $review_filter !== 'all' ? "No $review_filter reviews found." : "You haven't submitted any reviews yet.<br>Share your feedback on a faculty member!"; ?></p>
            <?php if ($review_filter === 'all'): ?>
            <button class="write-review-btn" onclick="openReviewModal()">
                <svg width="14" height="14" fill="none" stroke="white" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Write Your First Review
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <?php foreach ($recent_reviews as $rev): ?>
        <div class="review-row" data-status="<?php echo $rev['status']; ?>">
            <div class="review-row-icon">
                <svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div class="review-row-body">
                <div class="review-row-top">
                    <div class="review-row-faculty"><?php echo htmlspecialchars($rev['faculty_name']); ?></div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="status-badge status-<?php echo $rev['status']; ?>"><?php echo ucfirst($rev['status']); ?></span>
                        <?php if ($rev['status'] === 'approved'): ?>
                        <button class="action-icon-btn" title="Edit" onclick="openEditModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['review_text'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['department'])); ?>', <?php echo $rev['faculty_id']; ?>, <?php echo intval($rev['rating_teaching']); ?>, <?php echo intval($rev['rating_communication']); ?>, <?php echo intval($rev['rating_punctuality']); ?>, <?php echo intval($rev['rating_fairness']); ?>, <?php echo intval($rev['rating_overall']); ?>)">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <?php endif; ?>
                        <button class="action-icon-btn action-icon-del" title="Delete" onclick="openDeleteModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>')">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </div>
                <div class="review-row-dept"><?php echo htmlspecialchars($rev['department'] ?? ''); ?></div>
                <div class="review-row-text"><?php echo htmlspecialchars($rev['review_text']); ?></div>
                <div class="review-row-date"><?php echo date("F j, Y · g:i A", strtotime($rev['created_at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Edit Review Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <h3>Edit Review</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <div class="modal-body">
                <div class="selected-preview show" id="editPreview">
                    <img id="editPreviewImg" src="" alt="">
                    <div class="selected-preview-info">
                        <strong id="editPreviewName"></strong>
                        <span id="editPreviewDept"></span>
                    </div>
                </div>
                <!-- Star Ratings for Edit -->
                <div class="rating-section-title">Update Ratings</div>
                <?php
                $rating_categories = [
                    ['teaching',      'Teaching Effectiveness'],
                    ['communication', 'Communication Skills'],
                    ['punctuality',   'Punctuality & Availability'],
                    ['fairness',      'Fairness in Grading'],
                    ['overall',       'Overall Satisfaction'],
                ];
                foreach ($rating_categories as $cat):
                ?>
                <div class="rating-group">
                    <div class="rating-group-label"><?php echo $cat[1]; ?></div>
                    <div class="stars" id="edit_stars_<?php echo $cat[0]; ?>">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" name="rating_<?php echo $cat[0]; ?>" id="edit_star_<?php echo $cat[0].$i; ?>" value="<?php echo $i; ?>">
                        <label for="edit_star_<?php echo $cat[0].$i; ?>">★</label>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="rating-section-title" style="margin-top:16px;">Update Review Text</div>
                <textarea class="modal-textarea" name="review_text" id="editReviewText" placeholder="Update your review..." required></textarea>
                <input type="hidden" name="review_id" id="editReviewId">
                <p style="font-size:12px;color:var(--gray-400);margin-top:8px;">⚠️ Editing will reset your review to pending status for re-approval.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="edit_review" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h3>Delete Review</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body" style="text-align:center;padding:30px 24px;">
                <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg width="26" height="26" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </div>
                <p style="font-size:15px;font-weight:600;color:var(--gray-800);margin-bottom:8px;">Delete this review?</p>
                <p style="font-size:13px;color:var(--gray-400);">Your review for <strong id="deleteFacultyName"></strong> will be permanently removed.</p>
                <input type="hidden" name="review_id" id="deleteReviewId">
            </div>
            <div class="modal-footer" style="justify-content:center;gap:12px;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" name="delete_review" style="padding:9px 22px;border-radius:20px;background:#ef4444;color:white;border:none;font-size:13px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Resubmit Modal (after rejection) -->
<div class="modal-overlay" id="resubmitModal">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-header">
            <h3>Resubmit Review</h3>
            <button class="modal-close" onclick="closeResubmitModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="selected-preview show">
                    <img id="resubmitPreviewImg" src="" alt="">
                    <div class="selected-preview-info">
                        <strong id="resubmitPreviewName"></strong>
                        <span style="font-size:12px;color:#991b1b;">Previously rejected</span>
                    </div>
                </div>
                <p style="font-size:13px;color:var(--gray-600);margin-bottom:12px;margin-top:8px;line-height:1.6;">Your previous review was rejected. Write a new one below — it will replace the rejected one.</p>
                <textarea class="modal-textarea" name="review_text" id="resubmitText" placeholder="Write your new review here..." required></textarea>
                <input type="hidden" name="old_review_id" id="resubmitReviewId">
                <input type="hidden" name="faculty_id" id="resubmitFacultyId">
                <p style="font-size:12px;color:var(--gray-400);margin-top:8px;">🔒 Your identity remains anonymous.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeResubmitModal()">Cancel</button>
                <button type="submit" name="resubmit_review" class="btn-primary">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>
                <span id="modalStepTitle">Select a Faculty Member</span>
            </h3>
            <button class="modal-close" onclick="closeReviewModal()">&times;</button>
        </div>
        <form method="POST" id="reviewForm" enctype="multipart/form-data">
            <div class="modal-body">

                <!-- Step 1: Choose Faculty -->
                <div class="step active" id="step1">
                    <div class="step-label">Step 1 of 2 · Choose Faculty</div>
                    <?php foreach ($departments as $dept => $dept_faculties): ?>
                    <div class="dept-group">
                        <div class="dept-header" onclick="toggleDept(this)">
                            <span><?php echo htmlspecialchars($dept); ?> <span style="font-weight:400;color:var(--gray-400);font-size:12px;">(<?php echo count($dept_faculties); ?>)</span></span>
                            <span class="chevron">▼</span>
                        </div>
                        <div class="dept-body">
                            <?php foreach ($dept_faculties as $f): ?>
                            <div class="faculty-option" onclick="selectFaculty(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($f['name'])); ?>', '<?php echo htmlspecialchars(addslashes($dept)); ?>')">
                                <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($f['name']); ?>&background=8B0000&color=fff&size=40" alt="">
                                <?php echo htmlspecialchars($f['name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Step 2: Write Review -->
                <div class="step" id="step2">
                    <div class="step-label">Step 2 of 2 · Rate &amp; Write Your Review</div>
                    <div class="selected-preview show" id="selectedPreview">
                        <img id="previewImg" src="" alt="">
                        <div class="selected-preview-info">
                            <strong id="previewName"></strong>
                            <span id="previewDept"></span>
                        </div>
                        <button type="button" class="change-faculty-btn" onclick="goStep(1)">Change</button>
                    </div>

                    <!-- Star Ratings -->
                    <div class="rating-section-title">Rate the Faculty</div>
                    <?php
                    $rating_categories = [
                        ['teaching',       'Teaching Effectiveness',  'How well does the faculty explain and deliver lessons?'],
                        ['communication',  'Communication Skills',    'How clear and approachable is the faculty?'],
                        ['punctuality',    'Punctuality & Availability', 'Does the faculty arrive on time and is available when needed?'],
                        ['fairness',       'Fairness in Grading',     'Are grades given fairly and consistently?'],
                        ['overall',        'Overall Satisfaction',    'Your overall experience with this faculty.'],
                    ];
                    foreach ($rating_categories as $cat):
                    ?>
                    <div class="rating-group">
                        <div class="rating-group-label">
                            <?php echo $cat[1]; ?>
                            <span><?php echo $cat[2]; ?></span>
                        </div>
                        <div class="stars" id="stars_<?php echo $cat[0]; ?>">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" name="rating_<?php echo $cat[0]; ?>" id="star_<?php echo $cat[0].$i; ?>" value="<?php echo $i; ?>" required>
                            <label for="star_<?php echo $cat[0].$i; ?>" title="<?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="rating-section-title" style="margin-top:18px;">Write Your Review</div>
                    <textarea class="modal-textarea" name="review_text" id="reviewText" placeholder="Write your anonymous review here... Be honest, constructive, and respectful." required></textarea>

                    <!-- Photo upload for documentation -->
                    <div style="margin-top:14px;padding:12px;background:var(--gray-100);border-radius:var(--radius-sm);border:1px dashed var(--gray-200);">
                        <div style="font-size:12px;font-weight:600;color:var(--gray-600);margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            Attach Photo <span style="font-weight:400;color:var(--gray-400);">(optional — for documentation)</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <input type="file" name="review_photo" id="reviewPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="previewReviewPhoto(this)">
                            <button type="button" class="btn btn-outline" style="font-size:12px;" onclick="document.getElementById('reviewPhotoInput').click()">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                                Upload Image
                            </button>
                            <div id="reviewPhotoPreviewWrap" style="display:none;align-items:center;gap:8px;">
                                <img id="reviewPhotoPreview" src="" style="width:48px;height:48px;border-radius:6px;object-fit:cover;border:1px solid var(--gray-200);">
                                <button type="button" onclick="clearReviewPhoto()" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:18px;line-height:1;">&times;</button>
                            </div>
                        </div>
                        <div style="font-size:11px;color:var(--gray-400);margin-top:6px;">JPG, PNG, WEBP · Max 5MB · Checked for inappropriate content by AI</div>
                    </div>

                    <input type="hidden" name="faculty_id" id="facultyIdInput">
                    <p style="font-size:12px;color:var(--gray-400);margin-top:8px;">🔒 Your identity remains anonymous. Reviews are reviewed by admin before publishing.</p>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="backBtn" style="display:none;" onclick="goStep(1)">← Back</button>
                <button type="button" class="btn-secondary" onclick="closeReviewModal()">Cancel</button>
                <button type="button" class="btn-primary" id="nextBtn" disabled onclick="goStep(2)">Next →</button>
                <button type="submit" name="submit_review" class="btn-primary" id="submitBtn" style="display:none;">Submit Review</button>
            </div>
        </form>
    </div>
</div>


<!-- Chatbot Bubble -->
<div id="chat-bubble" onclick="toggleChat()">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
</div>

<!-- Chatbot Window -->
<div id="chat-window">
    <div id="chat-header">
        <span>FAQ Assistant</span>
        <span onclick="toggleChat()">&times;</span>
    </div>
    <div class="faq-suggestions" id="faqSuggestions">
        <div class="faq-label">Frequently Asked</div>
        <button class="faq-chip" onclick="askFaq('How do I submit a review?')">How do I submit a review?</button>
        <button class="faq-chip" onclick="askFaq('Are my reviews anonymous?')">Are my reviews anonymous?</button>
        <button class="faq-chip" onclick="askFaq('Why is my review still pending?')">Why is my review still pending?</button>
        <button class="faq-chip" onclick="askFaq('Can I edit or delete my review?')">Can I edit or delete my review?</button>
        <button class="faq-chip" onclick="askFaq('What happens after my review is approved?')">What happens after approval?</button>
        <button class="faq-chip" onclick="askFaq('Why was my review rejected?')">Why was my review rejected?</button>
    </div>
    <div id="chat-messages">
        <div class="chat-msg bot">Hi! Ask me anything about using AnonymousReview. 👋</div>
    </div>
    <div id="chat-footer">
        <input id="chat-input" type="text" placeholder="Type a question..." onkeydown="if(event.key==='Enter') sendChat()">
        <button id="chat-send" onclick="sendChat()">&#9658;</button>
    </div>
</div>

<script>
// Resubmit modal
function openDeleteAndResubmitModal(reviewId, facultyName, facultyId, name, dept) {
    document.getElementById('resubmitReviewId').value = reviewId;
    document.getElementById('resubmitFacultyId').value = facultyId;
    document.getElementById('resubmitPreviewName').textContent = facultyName;
    document.getElementById('resubmitPreviewImg').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=42';
    document.getElementById('resubmitText').value = '';
    document.getElementById('resubmitModal').classList.add('open');
}
function closeResubmitModal() { document.getElementById('resubmitModal').classList.remove('open'); }
document.getElementById('resubmitModal').addEventListener('click', function(e) { if (e.target === this) closeResubmitModal(); });

// Open modal pre-selected for a specific faculty (from card)
function openModalForFaculty(id, name, dept) {
    openReviewModal();
    selectFaculty(id, name, dept);
    setTimeout(() => goStep(2), 100);
}

// Edit modal
function openEditModal(reviewId, reviewText, facultyName, dept, facultyId, rt, rc, rp, rf, ro) {
    document.getElementById('editReviewId').value = reviewId;
    document.getElementById('editReviewText').value = reviewText;
    document.getElementById('editPreviewName').textContent = facultyName;
    document.getElementById('editPreviewDept').textContent = dept;
    document.getElementById('editPreviewImg').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(facultyName) + '&background=8B0000&color=fff&size=42';
    // Prefill star ratings
    const ratings = {teaching:rt, communication:rc, punctuality:rp, fairness:rf, overall:ro};
    Object.entries(ratings).forEach(([cat, val]) => {
        const input = document.getElementById('edit_star_' + cat + val);
        if (input) input.checked = true;
    });
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });

// Delete modal
function openDeleteModal(reviewId, facultyName) {
    document.getElementById('deleteReviewId').value = reviewId;
    document.getElementById('deleteFacultyName').textContent = facultyName;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });

// Department filter (client-side)
function filterFaculty() {
    const dept = document.getElementById('deptFilter').value;
    const allCards = document.querySelectorAll('.faculty-card');
    allCards.forEach(card => {
        card.style.display = (dept === 'all' || card.dataset.dept === dept) ? '' : 'none';
    });
    // Reset pagination
    const visible = [...allCards].filter(c => c.style.display !== 'none');
    paginateCards(visible);
}

// Faculty Pagination (updated to work with filter)
const CARDS_PER_PAGE = 6;
let currentPage = 1;
const pagination = document.getElementById('pagination');

function paginateCards(cards) {
    const totalPages = Math.ceil(cards.length / CARDS_PER_PAGE);
    currentPage = 1;
    renderPage(cards, currentPage, totalPages);
}

function renderPage(cards, page, totalPages) {
    const start = (page - 1) * CARDS_PER_PAGE;
    const end = start + CARDS_PER_PAGE;
    // Hide all visible cards first, then show only current page
    const allCards = document.querySelectorAll('.faculty-card');
    const dept = document.getElementById('deptFilter') ? document.getElementById('deptFilter').value : 'all';
    allCards.forEach(card => {
        const inDept = dept === 'all' || card.dataset.dept === dept;
        card.style.display = 'none';
    });
    cards.slice(start, end).forEach(card => card.style.display = '');
    renderPagination(cards, page, totalPages);
}

function renderPagination(cards, page, totalPages) {
    if (!pagination) return;
    pagination.innerHTML = '';
    if (totalPages <= 1) return;

    const prev = document.createElement('button');
    prev.className = 'page-btn';
    prev.innerHTML = '← Prev';
    prev.disabled = page === 1;
    prev.onclick = () => { currentPage--; renderPage(cards, currentPage, totalPages); };
    pagination.appendChild(prev);

    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `Page ${page} of ${totalPages}`;
    pagination.appendChild(info);

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'page-btn' + (i === page ? ' active' : '');
        btn.textContent = i;
        btn.onclick = () => { currentPage = i; renderPage(cards, currentPage, totalPages); };
        pagination.appendChild(btn);
    }

    const next = document.createElement('button');
    next.className = 'page-btn';
    next.innerHTML = 'Next →';
    next.disabled = page === totalPages;
    next.onclick = () => { currentPage++; renderPage(cards, currentPage, totalPages); };
    pagination.appendChild(next);
}

// Init pagination
const allFacultyCards = [...document.querySelectorAll('.faculty-card')];
if (allFacultyCards.length > 0) paginateCards(allFacultyCards);

// Modal
let selectedFacultyId = null;

function openReviewModal() {
    document.getElementById('reviewModal').classList.add('open');
    goStep(1);
}
function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('open');
    selectedFacultyId = null;
    document.getElementById('reviewText').value = '';
    document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
    document.getElementById('nextBtn').disabled = true;
}
function goStep(n) {
    document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
    document.getElementById('step' + n).classList.add('active');
    document.getElementById('modalStepTitle').textContent = n === 1 ? 'Select a Faculty Member' : 'Write Your Review';
    document.getElementById('backBtn').style.display = n === 2 ? 'inline-flex' : 'none';
    document.getElementById('nextBtn').style.display = n === 1 ? 'inline-flex' : 'none';
    document.getElementById('submitBtn').style.display = n === 2 ? 'inline-flex' : 'none';
}
function selectFaculty(id, name, dept) {
    selectedFacultyId = id;
    document.getElementById('facultyIdInput').value = id;
    document.getElementById('previewName').textContent = name;
    document.getElementById('previewDept').textContent = dept;
    document.getElementById('previewImg').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(name) + '&background=8B0000&color=fff&size=42';
    document.querySelectorAll('.faculty-option').forEach(o => o.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    document.getElementById('nextBtn').disabled = false;
}
function toggleDept(header) {
    header.classList.toggle('open');
    const body = header.nextElementSibling;
    body.classList.toggle('open');
}
// Close modal on overlay click
document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeReviewModal();
});
// Open first dept by default
document.addEventListener('DOMContentLoaded', () => {
    const first = document.querySelector('.dept-header');
    if (first) { first.classList.add('open'); first.nextElementSibling.classList.add('open'); }
});

const searchInput = document.getElementById('searchInput');
const clearSearch = document.getElementById('clearSearch');
function toggleClear() { clearSearch.style.display = searchInput.value.length > 0 ? 'inline' : 'none'; }
toggleClear();
searchInput.addEventListener('input', toggleClear);
clearSearch.addEventListener('click', () => { searchInput.value = ''; toggleClear(); window.location.href = 'dashboard.php'; });

// Clear notifications
function clearNotifications(e) {
    e.stopPropagation();
    fetch('clear_notifications.php', { method: 'POST' })
        .then(() => {
            // Clear dropdown content
            const dropdown = document.getElementById('notifDropdown');
            dropdown.querySelector('.notif-dropdown-header').innerHTML = `
                <span style="display:flex;align-items:center;gap:7px;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
                    Notifications
                </span>`;
            // Replace items with empty state
            const items = dropdown.querySelectorAll('.notif-item');
            items.forEach(i => i.remove());
            const existing = dropdown.querySelector('.notif-empty');
            if (!existing) {
                const empty = document.createElement('div');
                empty.className = 'notif-empty';
                empty.innerHTML = `<svg width="28" height="28" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg><p>No notifications yet</p>`;
                dropdown.appendChild(empty);
            }
            // Remove badge
            const badge = document.getElementById('notifWrap').querySelector('.notif-badge');
            if (badge) badge.remove();
        });
}

// Notification bell - mark as read on open
const notifWrap = document.getElementById('notifWrap');
const notifDropdown = document.getElementById('notifDropdown');
notifWrap.addEventListener('click', () => {
    const isOpen = notifDropdown.style.display === 'block';
    notifDropdown.style.display = isOpen ? 'none' : 'block';
    if (!isOpen) {
        // Mark notifications as read via AJAX
        fetch('mark_notifications_read.php', { method: 'POST' })
            .then(() => {
                const badge = notifWrap.querySelector('.notif-badge');
                if (badge) badge.remove();
            });
    }
});
document.addEventListener('click', (e) => { if (!notifWrap.contains(e.target)) notifDropdown.style.display = 'none'; });

// Client-side review filter (no page reload, no scroll jump)
function setReviewFilter(filter, e) {
    if (e) e.preventDefault();
    document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
    if (e) e.currentTarget.classList.add('active');

    const rows = document.querySelectorAll('.review-row');
    rows.forEach(row => {
        const match = filter === 'all' || row.dataset.status === filter;
        row.dataset.filtered = match ? 'show' : 'hide';
    });

    const visibleCount = [...rows].filter(r => r.dataset.filtered === 'show').length;
    const emptyState = document.getElementById('reviewEmptyState');
    if (emptyState) emptyState.style.display = visibleCount === 0 ? 'flex' : 'none';

    reviewPage = 1;
    paginateReviews();
}

// Review rows pagination
const REVIEWS_PER_PAGE = 5;
let reviewPage = 1;

function paginateReviews() {
    // Get all rows that pass the filter
    const allRows = [...document.querySelectorAll('.review-row')];
    const visibleRows = allRows.filter(r => r.dataset.filtered !== 'hide');
    const total      = visibleRows.length;
    const totalPages = Math.ceil(total / REVIEWS_PER_PAGE);
    const start      = (reviewPage - 1) * REVIEWS_PER_PAGE;
    const end        = start + REVIEWS_PER_PAGE;

    // Hide ALL rows first
    allRows.forEach(r => r.style.display = 'none');
    // Show only current page of visible rows
    visibleRows.slice(start, end).forEach(r => r.style.display = '');

    // Render pagination controls
    let pag = document.getElementById('reviewPagination');
    if (!pag) {
        pag = document.createElement('div');
        pag.id = 'reviewPagination';
        pag.className = 'review-pagination';
        document.querySelector('.review-card').appendChild(pag);
    }
    pag.innerHTML = '';
    if (totalPages <= 1) return;

    const prev = document.createElement('button');
    prev.className = 'page-btn'; prev.textContent = '← Prev';
    prev.disabled = reviewPage === 1;
    prev.onclick = () => { reviewPage--; paginateReviews(); };
    pag.appendChild(prev);

    const info = document.createElement('span');
    info.className = 'page-info';
    info.textContent = `${reviewPage} / ${totalPages}`;
    pag.appendChild(info);

    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement('button');
        btn.className = 'page-btn' + (i === reviewPage ? ' active' : '');
        btn.textContent = i;
        btn.onclick = () => { reviewPage = i; paginateReviews(); };
        pag.appendChild(btn);
    }

    const next = document.createElement('button');
    next.className = 'page-btn'; next.textContent = 'Next →';
    next.disabled = reviewPage === totalPages;
    next.onclick = () => { reviewPage++; paginateReviews(); };
    pag.appendChild(next);
}

// Init review pagination and mark all rows as visible by default
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.review-row').forEach(r => r.dataset.filtered = 'show');
    if (document.querySelectorAll('.review-row').length > REVIEWS_PER_PAGE) paginateReviews();

    // Auto-dismiss success/error banners after 8 seconds
    document.querySelectorAll('.success-banner').forEach(banner => {
        setTimeout(() => {
            banner.style.transition = 'opacity 0.6s ease';
            banner.style.opacity = '0';
            setTimeout(() => banner.style.display = 'none', 600);
        }, 8000);
    });
});

// FAQ suggestions
function askFaq(question) {
    // Hide suggestions after first click
    document.getElementById('faqSuggestions').style.display = 'none';
    // Put question in input and send
    document.getElementById('chat-input').value = question;
    sendChat();
}

// Chatbot
function toggleChat() {
    var w = document.getElementById('chat-window');
    w.style.display = w.style.display === 'flex' ? 'none' : 'flex';
    if (w.style.display === 'flex') {
        w.style.flexDirection = 'column';
        document.getElementById('chat-input').focus();
    }
}
// Hide FAQ on manual input
document.getElementById('chat-input').addEventListener('input', function() {
    if (this.value.trim()) document.getElementById('faqSuggestions').style.display = 'none';
});
function sendChat() {
    var input = document.getElementById('chat-input');
    var msg = input.value.trim();
    if (!msg) return;
    addBubble(msg, 'user');
    input.value = '';
    var typing = addBubble('Typing...', 'bot', 'typing-indicator');
    fetch('chatbot.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'message=' + encodeURIComponent(msg)
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('typing-indicator').remove();
        addBubble(data.reply || 'Sorry, try again.', 'bot');
    })
    .catch(() => {
        document.getElementById('typing-indicator').remove();
        addBubble('Connection error. Please try again.', 'bot');
    });
}
function addBubble(text, from, id) {
    var box = document.getElementById('chat-messages');
    var d = document.createElement('div');
    d.className = 'chat-msg ' + from;
    if (id) d.id = id;
    d.textContent = text;
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
}
// Review photo preview
function previewReviewPhoto(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be under 5MB.');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('reviewPhotoPreview').src = e.target.result;
            document.getElementById('reviewPhotoPreviewWrap').style.display = 'flex';
        };
        reader.readAsDataURL(file);
    }
}
function clearReviewPhoto() {
    document.getElementById('reviewPhotoInput').value = '';
    document.getElementById('reviewPhotoPreview').src = '';
    document.getElementById('reviewPhotoPreviewWrap').style.display = 'none';
}
// Reset photo when review modal closes
const _origCloseReviewModal = closeReviewModal;
closeReviewModal = function() {
    _origCloseReviewModal();
    clearReviewPhoto();
};
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash;
    if (hash) {
        const el = document.getElementById(hash.slice(1));
        if (el) setTimeout(() => el.scrollIntoView({ behavior: 'auto', block: 'start' }), 60);
    }
});
</script>
<script src="session_timeout.js"></script>
</body>
</html>