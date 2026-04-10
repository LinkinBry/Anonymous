<?php
include "config.php";

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    header("Location: login.php");
    exit();
}

$query  = "SELECT fullname, username, profile_pic FROM users WHERE id='$user_id' LIMIT 1";
$result = mysqli_query($conn, $query);
$user   = ($result && mysqli_num_rows($result) > 0)
    ? mysqli_fetch_assoc($result)
    : ['fullname' => 'User', 'username' => 'user', 'profile_pic' => null];

$avatar = !empty($user['profile_pic']) && file_exists($user['profile_pic'])
    ? $user['profile_pic']
    : 'https://ui-avatars.com/api/?name=' . urlencode($user['fullname']) . '&background=6B0000&color=fff&size=80';

// ── Notifications ──────────────────────────────────────────
$notif_count   = 0;
$notifications = [];
$notif_res = mysqli_query($conn, "
    SELECT * FROM notifications
    WHERE user_id='$user_id'
      AND (message LIKE '%approved%' OR message LIKE '%rejected%')
    ORDER BY created_at DESC
    LIMIT 5
");
if ($notif_res && mysqli_num_rows($notif_res) > 0) {
    while ($row = mysqli_fetch_assoc($notif_res)) {
        $notifications[] = $row;
        if ($row['status'] === 'unread') $notif_count++;
    }
}

$faculties = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search        = mysqli_real_escape_string($conn, $_GET['search']);
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

$total_reviews  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id'"))['count'];
$pending_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='pending'"))['count'];
$approved_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='approved'"))['count'];
$rejected_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE user_id='$user_id' AND status='rejected'"))['count'];

/* ── Review submit ─────────────────────────────────────────── */
if (isset($_POST['submit_review'])) {
    $faculty_id  = intval($_POST['faculty_id']);
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) { header("Location: dashboard.php?error=empty"); exit(); }

    $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM reviews WHERE user_id='$user_id' AND faculty_id='$faculty_id' LIMIT 1"));
    if ($exists) { header("Location: dashboard.php?error=duplicate"); exit(); }

    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];

    $normalized = preg_replace('/tanginamo|tangina|punyeta|gago|putangina|ulol|bobo|tanga|hinayupak|pakingshet|pakshet|tarantado|bwisit|lintik|ampota|inamo|kingina|kupalmerda|leche|pesteng yawa/i',
        ' [PROFANITY] ', $review_text);

    $prompt = 'You are a multilingual content moderator. The review below may be written in English, Filipino, Tagalog, or a mix (Taglish). Analyze it carefully considering the language and cultural context, then return valid JSON only, no explanation, no markdown:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}
IMPORTANT RULES:
- Flag as toxic if the review contains ANY of: insults, slurs, personal attacks, threats, explicit offensive language, harassment, discriminatory content, or profanity.
- [PROFANITY] markers in the text indicate detected Filipino/Tagalog profanity — ALWAYS flag these as toxic.
- Concatenated or misspelled profanity must still be flagged.
- Do NOT flag a review just because it is written in Filipino or Tagalog without profanity.
- Negative opinions about teaching style, punctuality, or performance WITHOUT profanity are NOT toxic.
Review: "' . addslashes($normalized) . '"';

    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch); curl_close($ch);

    $data      = json_decode($response, true);
    $ai_raw    = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai        = json_decode(trim($ai_raw), true);
    $sentiment = mysqli_real_escape_string($conn, $ai['sentiment'] ?? 'neutral');
    $is_toxic  = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;
    $summary   = mysqli_real_escape_string($conn, $ai['summary'] ?? '');

    if ($is_toxic) { header("Location: dashboard.php?error=toxic"); exit(); }

    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    $r_teaching      = intval($_POST['rating_teaching']      ?? 0);
    $r_communication = intval($_POST['rating_communication'] ?? 0);
    $r_punctuality   = intval($_POST['rating_punctuality']   ?? 0);
    $r_fairness      = intval($_POST['rating_fairness']      ?? 0);
    $r_overall       = intval($_POST['rating_overall']       ?? 0);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary, rating_teaching, rating_communication, rating_punctuality, rating_fairness, rating_overall)
                         VALUES ('$user_id','$faculty_id','$review_text_safe','pending','$sentiment','$is_toxic','$summary','$r_teaching','$r_communication','$r_punctuality','$r_fairness','$r_overall')");
    $new_review_id = mysqli_insert_id($conn);

    $uploaded_photos = [];
    if (!empty($_FILES['review_photos']['name'][0])) {
        $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
        $groq_key      = $api_key;
        $count         = count($_FILES['review_photos']['name']);
        for ($pi = 0; $pi < $count; $pi++) {
            if ($_FILES['review_photos']['error'][$pi] !== UPLOAD_ERR_OK) continue;
            $ftype = mime_content_type($_FILES['review_photos']['tmp_name'][$pi]);
            if (!in_array($ftype, $allowed_types) || $_FILES['review_photos']['size'][$pi] > 5*1024*1024) continue;
            $img_data = base64_encode(file_get_contents($_FILES['review_photos']['tmp_name'][$pi]));
            $img_safe = true;
            if ($groq_key) {
                $vp = json_encode(['model'=>'meta-llama/llama-4-scout-17b-16e-instruct','max_tokens'=>80,'messages'=>[['role'=>'user','content'=>[['type'=>'image_url','image_url'=>['url'=>'data:'.$ftype.';base64,'.$img_data]],['type'=>'text','text'=>'Does this image contain explicit nudity, graphic violence, hate symbols, or illegal content? Reply ONLY: {"safe": true} or {"safe": false}.']]]]]);
                $ch2 = curl_init('https://api.groq.com/openai/v1/chat/completions');
                curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$vp,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$groq_key]]);
                $vr  = curl_exec($ch2); curl_close($ch2);
                $vd  = json_decode($vr, true);
                $vraw= preg_replace('/```json|```/', '', ($vd['choices'][0]['message']['content'] ?? '{"safe":true}'));
                $vres= json_decode(trim($vraw), true);
                if (isset($vres['safe']) && $vres['safe'] === false) $img_safe = false;
            }
            if ($img_safe) {
                $ext      = pathinfo($_FILES['review_photos']['name'][$pi], PATHINFO_EXTENSION);
                $filename = 'uploads/review_' . $new_review_id . '_' . time() . '_' . $pi . '.' . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0755, true);
                if (move_uploaded_file($_FILES['review_photos']['tmp_name'][$pi], $filename)) {
                    $uploaded_photos[] = $filename;
                }
            }
        }
    }
    if (!empty($uploaded_photos)) {
        @mysqli_query($conn, "ALTER TABLE reviews MODIFY COLUMN photo TEXT DEFAULT NULL");
        $photos_json = mysqli_real_escape_string($conn, json_encode($uploaded_photos));
        mysqli_query($conn, "UPDATE reviews SET photo='$photos_json' WHERE id='$new_review_id'");
    }
    $fn_sub = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
    if ($fn_sub && mysqli_num_rows($fn_sub) > 0) {
        $fn_subname = mysqli_real_escape_string($conn, mysqli_fetch_assoc($fn_sub)['name']);
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$user_id', 'You submitted a review for $fn_subname. It is pending approval.', 'read', NOW())");
    }
    header("Location: dashboard.php?submitted=1#reviews"); exit();
}

/* ── Review edit ──────────────────────────────────────────── */
if (isset($_POST['edit_review'])) {
    $review_id   = intval($_POST['review_id']);
    $review_text = trim($_POST['review_text'] ?? '');
    if (empty($review_text)) { header("Location: dashboard.php?error=empty"); exit(); }
    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];
    $normalized = preg_replace('/tanginamo|tangina|punyeta|gago|putangina|ulol|bobo|tanga|hinayupak|pakingshet|pakshet|tarantado|bwisit|lintik|ampota|inamo|kingina|kupalmerda|leche|pesteng yawa/i',
        ' [PROFANITY] ', $review_text);
    $prompt = 'You are a multilingual content moderator. Return valid JSON only:
{"sentiment":"positive or negative or neutral","is_toxic":true or false,"is_hateful":true or false,"summary":"one sentence summary in English"}
IMPORTANT: Flag as toxic for insults, slurs, personal attacks, threats, offensive language, harassment, profanity. [PROFANITY] markers = always toxic. Negative teaching opinions without profanity are NOT toxic.
Review: "' . addslashes($normalized) . '"';
    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch); curl_close($ch);
    $data = json_decode($response, true);
    $ai_raw = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai = json_decode(trim($ai_raw), true);
    $is_toxic = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;
    if ($is_toxic) { header("Location: dashboard.php?error=toxic"); exit(); }
    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    $r_teaching      = intval($_POST['rating_teaching'] ?? 0);
    $r_communication = intval($_POST['rating_communication'] ?? 0);
    $r_punctuality   = intval($_POST['rating_punctuality'] ?? 0);
    $r_fairness      = intval($_POST['rating_fairness'] ?? 0);
    $r_overall       = intval($_POST['rating_overall'] ?? 0);
    mysqli_query($conn, "UPDATE reviews SET review_text='$review_text_safe', status='pending',
        rating_teaching='$r_teaching', rating_communication='$r_communication',
        rating_punctuality='$r_punctuality', rating_fairness='$r_fairness', rating_overall='$r_overall'
        WHERE id='$review_id' AND user_id='$user_id'");
    if (!empty($_FILES['edit_review_photos']['name'][0])) {
        $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
        $uploaded_edit = [];
        $count = count($_FILES['edit_review_photos']['name']);
        for ($pi = 0; $pi < $count; $pi++) {
            if ($_FILES['edit_review_photos']['error'][$pi] !== UPLOAD_ERR_OK) continue;
            $ftype = mime_content_type($_FILES['edit_review_photos']['tmp_name'][$pi]);
            if (!in_array($ftype, $allowed_types) || $_FILES['edit_review_photos']['size'][$pi] > 5*1024*1024) continue;
            $ext = pathinfo($_FILES['edit_review_photos']['name'][$pi], PATHINFO_EXTENSION);
            $filename = 'uploads/review_' . $review_id . '_' . time() . '_' . $pi . '.' . $ext;
            if (!is_dir('uploads')) mkdir('uploads', 0755, true);
            if (move_uploaded_file($_FILES['edit_review_photos']['tmp_name'][$pi], $filename)) $uploaded_edit[] = $filename;
        }
        if (!empty($uploaded_edit)) {
            @mysqli_query($conn, "ALTER TABLE reviews MODIFY COLUMN photo TEXT DEFAULT NULL");
            $ep = mysqli_real_escape_string($conn, json_encode($uploaded_edit));
            mysqli_query($conn, "UPDATE reviews SET photo='$ep' WHERE id='$review_id' AND user_id='$user_id'");
        }
    }
    $fn_res = mysqli_query($conn, "SELECT f.name AS fn FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.id='$review_id' AND r.user_id='$user_id' LIMIT 1");
    if ($fn_res && mysqli_num_rows($fn_res) > 0) {
        $fn_row  = mysqli_fetch_assoc($fn_res);
        $fn_safe = mysqli_real_escape_string($conn, $fn_row['fn']);
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$user_id', 'You edited your review for $fn_safe. It is now pending re-approval.', 'read', NOW())");
    }
    header("Location: dashboard.php?edited=1#reviews"); exit();
}

/* ── Resubmit ─────────────────────────────────────────────── */
if (isset($_POST['resubmit_review'])) {
    $old_id      = intval($_POST['old_review_id']);
    $faculty_id  = intval($_POST['faculty_id']);
    $review_text = trim($_POST['review_text'] ?? '');
    if (empty($review_text)) { header("Location: dashboard.php?error=empty"); exit(); }
    mysqli_query($conn, "DELETE FROM reviews WHERE id='$old_id' AND user_id='$user_id' AND status='rejected'");
    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];
    $prompt = 'You are a multilingual content moderator. Return valid JSON only:
{"sentiment":"positive or negative or neutral","is_toxic":true or false,"is_hateful":true or false,"summary":"one sentence summary in English"}
IMPORTANT: Only flag as toxic for CLEAR insults, slurs, personal attacks, threats, explicit offensive language, harassment, or discriminatory content. Negative opinions about teaching are NOT toxic. When in doubt, do NOT flag.
Review: "' . addslashes($review_text) . '"';
    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch); curl_close($ch);
    $data = json_decode($response, true);
    $ai_raw = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai = json_decode(trim($ai_raw), true);
    $sentiment = mysqli_real_escape_string($conn, $ai['sentiment'] ?? 'neutral');
    $is_toxic  = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;
    $summary   = mysqli_real_escape_string($conn, $ai['summary'] ?? '');
    if ($is_toxic) { header("Location: dashboard.php?error=toxic"); exit(); }
    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary)
                         VALUES ('$user_id','$faculty_id','$review_text_safe','pending','$sentiment','$is_toxic','$summary')");
    $fn_rsub = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
    if ($fn_rsub && mysqli_num_rows($fn_rsub) > 0) {
        $fn_rsubname = mysqli_real_escape_string($conn, mysqli_fetch_assoc($fn_rsub)['name']);
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$user_id', 'You resubmitted your review for $fn_rsubname.', 'read', NOW())");
    }
    header("Location: dashboard.php?submitted=1#reviews"); exit();
}
if (isset($_POST['delete_review'])) {
    $review_id = intval($_POST['review_id']);
    $rev = mysqli_fetch_assoc(mysqli_query($conn, "SELECT f.name AS fn FROM reviews r JOIN faculties f ON r.faculty_id=f.id WHERE r.id='$review_id' AND r.user_id='$user_id' LIMIT 1"));
    mysqli_query($conn, "DELETE FROM reviews WHERE id='$review_id' AND user_id='$user_id'");
    if ($rev) {
        $fn_safe = mysqli_real_escape_string($conn, $rev['fn']);
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$user_id', 'You deleted your review for $fn_safe.', 'read', NOW())");
    }
    header("Location: dashboard.php?deleted=1#reviews"); exit();
}

/* ── Bulk delete reviews ───────────────────────────────────── */
if (isset($_POST['bulk_delete_reviews']) && !empty($_POST['selected_reviews'])) {
    $deleted_count = 0;
    foreach ($_POST['selected_reviews'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "DELETE FROM reviews WHERE id='$rid' AND user_id='$user_id'");
        $deleted_count++;
    }
    if ($deleted_count > 0) {
        $del_msg = mysqli_real_escape_string($conn, "You deleted $deleted_count review(s).");
        mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$user_id', '$del_msg', 'read', NOW())");
    }
    header("Location: dashboard.php?deleted=1#reviews"); exit();
}

/* ── Fetch data for view ───────────────────────────────────── */
$departments = [];
$dept_res    = mysqli_query($conn, "SELECT id, name, department, COALESCE(photo,'') AS photo FROM faculties ORDER BY department ASC, name ASC");
if ($dept_res && mysqli_num_rows($dept_res) > 0) {
    while ($row = mysqli_fetch_assoc($dept_res)) {
        $dept = $row['department'] ?: 'General';
        $departments[$dept][] = $row;
    }
}

$user_reviews_map = [];
$urev_res = mysqli_query($conn, "SELECT r.id, r.faculty_id, r.review_text, r.status, r.rating_teaching, r.rating_communication, r.rating_punctuality, r.rating_fairness, r.rating_overall FROM reviews r WHERE r.user_id='$user_id'");
if ($urev_res) {
    while ($row = mysqli_fetch_assoc($urev_res)) {
        $user_reviews_map[$row['faculty_id']] = $row;
    }
}

$recent_reviews = [];
$recent_res     = mysqli_query($conn, "
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

/* ── Recent Activity ──────────────────────────────────────── */
$activity_res = mysqli_query($conn, "
    SELECT message, status, created_at FROM notifications
    WHERE user_id='$user_id'
    ORDER BY created_at DESC LIMIT 5
");
$activities = [];
if ($activity_res) {
    while ($row = mysqli_fetch_assoc($activity_res)) $activities[] = $row;
}

$review_filter = isset($_GET['review_filter']) ? $_GET['review_filter'] : 'all';

// Cards shown initially in the faculty grid (6th spot reserved for Show More)
define('FACULTY_INITIAL', 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — OlshcoReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>

<!-- ══ Sidebar Toggle Button ═══════════════════════════════════════════ -->
<button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <polyline points="15 18 9 12 15 6"/>
    </svg>
</button>

<!-- ══ Sidebar ══════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-logo">
            <img src="image/logo.png" alt="Logo" onerror="this.parentElement.innerHTML='<svg width=&quot;20&quot; height=&quot;20&quot; fill=&quot;none&quot; stroke=&quot;rgba(255,255,255,0.9)&quot; stroke-width=&quot;2&quot; viewBox=&quot;0 0 24 24&quot;><path d=&quot;M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z&quot;/></svg>'">
        </div>
        <div class="sidebar-brand-text">
            OlshcoReview
            <span class="sidebar-brand-sub">Faculty Evaluation System</span>
        </div>
    </div>

    <div class="sidebar-user-wrap">
        <a href="profile.php" style="display:block;text-align:center;" title="Edit Profile">
            <img src="<?php echo htmlspecialchars($avatar); ?>" class="sidebar-avatar" alt="Avatar">
        </a>
        <div class="sidebar-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
        <div class="sidebar-role">@<?php echo htmlspecialchars($user['username']); ?></div>
    </div>

    <nav>
        <div class="nav-label">Menu</div>
        <a href="dashboard.php" class="active">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span class="nav-link-text">Dashboard</span>
        </a>
        <a href="#reviews-section" id="myReviewsNavLink">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            <span class="nav-link-text">My Reviews</span>
        </a>
        <a href="profile.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <span class="nav-link-text">Profile</span>
        </a>
        <a href="#" onclick="toggleChat(); return false;">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <span class="nav-link-text">FAQ Chat</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            <span class="nav-link-text">Logout</span>
        </a>
    </div>
</div>

<!-- ══ Main ═════════════════════════════════════════════════════════════ -->
<div class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Welcome back, <?php echo htmlspecialchars($user['username']); ?>!</h1>
            <p>Here's what's happening with your faculty evaluations.</p>
        </div>
        <div class="topbar-right">
            <div class="today-date">📅 <?php echo date("F j, Y"); ?></div>

            <button class="quick-action-btn" onclick="openReviewModal()"
                    style="background:var(--maroon);color:white;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Write Review
            </button>

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
                        <button onclick="clearNotifications(event)"
                                style="font-size:11px;color:var(--maroon);background:none;border:1px solid var(--maroon);border-radius:10px;padding:2px 10px;cursor:pointer;font-family:'DM Sans',sans-serif;font-weight:500;"
                                onmouseover="this.style.background='var(--maroon)';this.style.color='white'"
                                onmouseout="this.style.background='none';this.style.color='var(--maroon)'">
                            Clear all
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                            <div class="notif-item notif-<?php echo $n['status']; ?>">
                                <span style="display:flex;align-items:flex-start;gap:8px;">
                                    <?php if (strpos($n['message'], 'approved') !== false): ?>
                                        <svg width="14" height="14" style="flex-shrink:0;margin-top:2px;" fill="none" stroke="#10b981" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    <?php elseif (strpos($n['message'], 'deleted') !== false || strpos($n['message'], 'removed') !== false): ?>
                                        <svg width="14" height="14" style="flex-shrink:0;margin-top:2px;" fill="none" stroke="#6b7280" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
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

    <!-- ══ STAT CARDS — New flat style ══════════════════════════════════ -->
    <div class="stats-grid stats-row-4">

        <!-- Total Reviews -->
        <div class="stat-card total">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Total Reviews</div>
                    <div class="stat-value"><?php echo $total_reviews; ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="22" height="22" fill="none" stroke="#4b5563" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
            </div>
            <div class="stat-bar"></div>
        </div>

        <!-- Pending -->
        <div class="stat-card pending">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo $pending_count; ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="22" height="22" fill="none" stroke="#f59e0b" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
            <div class="stat-bar"></div>
        </div>

        <!-- Approved -->
        <div class="stat-card approved">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Approved</div>
                    <div class="stat-value"><?php echo $approved_count; ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="22" height="22" fill="none" stroke="#10b981" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
            </div>
            <div class="stat-bar"></div>
        </div>

        <!-- Rejected -->
        <div class="stat-card rejected">
            <div class="stat-card-inner">
                <div>
                    <div class="stat-label">Rejected</div>
                    <div class="stat-value"><?php echo $rejected_count; ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="22" height="22" fill="none" stroke="#ef4444" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
            </div>
            <div class="stat-bar"></div>
        </div>

    </div><!-- end stats-grid -->

    <!-- ══ Faculty Section ══════════════════════════════════════════════ -->
    <div class="section-header" style="margin-bottom:18px;">
        <div class="section-title">Faculty Members</div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <select id="deptFilter" class="filter-select" onchange="filterFaculty()">
                <option value="all">All Departments</option>
                <?php foreach (array_keys($departments) as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                <?php endforeach; ?>
            </select>
            <form class="search-form" method="GET" action="dashboard.php">
                <svg width="15" height="15" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" name="search" placeholder="Search faculty..."
                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                <span class="search-clear" id="clearSearch">×</span>
                <button type="submit">Search</button>
            </form>
        </div>
    </div>

    <?php if (!empty($faculties)): ?>
    <div class="faculty-grid" id="facultyGrid">
        <?php foreach ($faculties as $i => $faculty):
            $user_review  = $user_reviews_map[$faculty['id']] ?? null;
            $has_reviewed = $user_review !== null;
            $review_status= $has_reviewed ? $user_review['status'] : null;
            $f_avatar     = (!empty($faculty['photo']) && file_exists($faculty['photo']))
                ? htmlspecialchars($faculty['photo'])
                : 'https://ui-avatars.com/api/?name='.urlencode($faculty['name']).'&background=8B0000&color=fff&size=80';
            // Cards beyond the initial count are hidden until Show More is clicked
            $hidden_class = ($i >= FACULTY_INITIAL) ? ' hidden-card' : '';
        ?>
        <div class="faculty-card<?php echo $hidden_class; ?>"
             data-index="<?php echo $i; ?>"
             data-dept="<?php echo htmlspecialchars($faculty['department'] ?? ''); ?>">

            <!-- Top: avatar + name/dept -->
            <div class="faculty-card-top">
                <img src="<?php echo $f_avatar; ?>" alt="<?php echo htmlspecialchars($faculty['name']); ?>">
                <div class="faculty-card-info">
                    <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
                    <p><?php echo htmlspecialchars($faculty['department'] ?? ''); ?></p>
                </div>
            </div>

            <!-- Stars (if rated) -->
            <?php
            $avg = floatval($faculty['avg_stars'] ?? 0);
            if ($avg > 0):
                $pct = min(100, ($avg / 5) * 100);
                $sz  = 15; $gap = 2;
                $w   = $sz * 5 + $gap * 4;
                $uid = 'ds' . substr(md5($faculty['id'] . $avg), 0, 7);
                $cw  = round($pct / 100 * $w, 2);
                $empty_s = $filled_s = '';
                for ($si = 0; $si < 5; $si++) {
                    $x = $si * ($sz + $gap);
                    $empty_s  .= '<text x="'.$x.'" y="'.$sz.'" font-size="'.$sz.'" fill="#d1d5db">★</text>';
                    $filled_s .= '<text x="'.$x.'" y="'.$sz.'" font-size="'.$sz.'" fill="#f59e0b">★</text>';
                }
            ?>
            <div class="faculty-card-stars" style="margin-top:8px;">
                <svg width="<?php echo $w; ?>" height="<?php echo $sz; ?>" viewBox="0 0 <?php echo $w; ?> <?php echo $sz; ?>" xmlns="http://www.w3.org/2000/svg" style="display:block;vertical-align:middle;">
                    <defs><clipPath id="<?php echo $uid; ?>"><rect x="0" y="0" width="<?php echo $cw; ?>" height="<?php echo $sz; ?>"/></clipPath></defs>
                    <?php echo $empty_s; ?>
                    <g clip-path="url(#<?php echo $uid; ?>)"><?php echo $filled_s; ?></g>
                </svg>
                <span style="font-size:12px;color:var(--gray-400);"><?php echo number_format($avg,1); ?> (<?php echo $faculty['review_count']; ?>)</span>
            </div>
            <?php else: ?>
            <div style="margin-top:8px;font-size:12px;color:var(--gray-400);">No ratings yet</div>
            <?php endif; ?>

            <!-- Divider -->
            <div class="faculty-card-divider"></div>

            <!-- Status badge if reviewed -->
            <?php if ($has_reviewed): ?>
                <span class="status-badge status-<?php echo $review_status; ?>" style="display:inline-flex;align-items:center;gap:4px;margin-bottom:10px;">
                    <?php if ($review_status === 'pending'): ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending
                    <?php elseif ($review_status === 'approved'): ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Approved
                    <?php else: ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rejected
                    <?php endif; ?>
                </span>
            <?php endif; ?>

            <!-- Action button -->
            <?php if ($has_reviewed): ?>
                <?php if ($review_status === 'approved'): ?>
                    <button class="btn-evaluate btn-edit"
                            onclick="openEditModal(<?php echo $user_review['id']; ?>, '<?php echo htmlspecialchars(addslashes($user_review['review_text'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>', <?php echo $faculty['id']; ?>, <?php echo intval($user_review['rating_teaching']); ?>, <?php echo intval($user_review['rating_communication']); ?>, <?php echo intval($user_review['rating_punctuality']); ?>, <?php echo intval($user_review['rating_fairness']); ?>, <?php echo intval($user_review['rating_overall']); ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit Review
                    </button>
                <?php elseif ($review_status === 'pending'): ?>
                    <span class="btn-evaluate btn-reviewed">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Awaiting Review
                    </span>
                <?php else: ?>
                    <button class="btn-evaluate btn-rejected-card"
                            onclick="openDeleteAndResubmitModal(<?php echo $user_review['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', <?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>')">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Resubmit
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <button class="btn-evaluate"
                        onclick="openModalForFaculty(<?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>', '<?php echo $f_avatar; ?>')">
                    Evaluate
                </button>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>

        <!-- Show More card (only if there are more than FACULTY_INITIAL cards) -->
        <?php if (count($faculties) > FACULTY_INITIAL): ?>
        <div class="faculty-card-showmore" id="showMoreCard" onclick="showMoreFaculty()">
            <div class="showmore-icon">
                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            </div>
            <span>Show more</span>
        </div>
        <?php endif; ?>

    </div><!-- end faculty-grid -->

    <!-- Pagination (hidden until Show More is clicked) -->
    <div class="pagination" id="pagination" style="display:none;"></div>

    <?php else: ?>
    <div class="empty-state">
        <svg width="64" height="64" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <p>No faculty found<?php echo isset($_GET['search']) ? ' for your search.' : '.'; ?></p>
    </div>
    <?php endif; ?>

    <!-- ══ My Reviews Section ════════════════════════════════════════════ -->
    <div class="review-card" id="reviews-section">
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

        <div class="filter-tabs">
            <div class="filter-tabs-left">
                <button onclick="setReviewFilter('all',event)"      class="filter-tab <?php echo $review_filter === 'all'      ? 'active' : ''; ?>">All</button>
                <button onclick="setReviewFilter('pending',event)"  class="filter-tab <?php echo $review_filter === 'pending'  ? 'active' : ''; ?>">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Pending
                </button>
                <button onclick="setReviewFilter('approved',event)" class="filter-tab <?php echo $review_filter === 'approved' ? 'active' : ''; ?>">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Approved
                </button>
                <button onclick="setReviewFilter('rejected',event)" class="filter-tab <?php echo $review_filter === 'rejected' ? 'active' : ''; ?>">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rejected
                </button>
            </div>
            <?php if (!empty($recent_reviews)): ?>
            <button type="button" id="deleteModeBtn"
                onclick="toggleDeleteMode()"
                class="btn btn-outline"
                style="padding:6px 14px;border-radius:20px;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                Delete
            </button>
            <?php endif; ?>
        </div>

        <form method="POST" id="bulkDeleteForm">
        <div class="reviews-bulk-bar" id="reviewsBulkBar">
            <span id="reviewsBulkCount">0 reviews selected</span>
            <button type="submit" name="bulk_delete_reviews" class="btn btn-red"
                    onclick="return confirm('Delete selected reviews permanently?')">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                Delete Selected
            </button>
            <button type="button" class="btn btn-outline" onclick="toggleDeleteMode()" style="font-size:12px;padding:5px 12px;">Cancel</button>
        </div>

        <?php if (isset($_GET['submitted'])): ?>
            <div class="success-banner"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Your review has been submitted and is pending admin approval.</div>
        <?php endif; ?>
        <?php if (isset($_GET['edited'])): ?>
            <div class="success-banner"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Your review has been updated and is pending re-approval.</div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="success-banner" style="background:#fee2e2;color:#991b1b;"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg> Review(s) deleted successfully.</div>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'toxic'): ?>
            <div class="success-banner" style="background:#fee2e2;color:#991b1b;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Your review was flagged as hateful or offensive and could not be submitted.
            </div>
            <?php elseif ($_GET['error'] === 'duplicate'): ?>
            <div class="success-banner" style="background:#fef3c7;color:#92400e;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                You have already submitted a review for this faculty member.
            </div>
            <?php elseif ($_GET['error'] === 'empty'): ?>
            <div class="success-banner" style="background:#fef3c7;color:#92400e;">Review cannot be empty.</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($recent_reviews)): ?>
        <div class="review-empty" id="reviewEmptyState">
            <div class="review-empty-icon">
                <svg width="32" height="32" fill="none" stroke="var(--maroon)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <p>You haven't submitted any reviews yet.<br>Share your feedback on a faculty member!</p>
            <button class="write-review-btn" onclick="openReviewModal()">
                <svg width="14" height="14" fill="none" stroke="white" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Write Your First Review
            </button>
        </div>
        <?php else: ?>
        <?php foreach ($recent_reviews as $rev): ?>
        <div class="review-row" data-status="<?php echo $rev['status']; ?>">
            <div class="review-row-checkbox">
                <input type="checkbox" name="selected_reviews[]" value="<?php echo $rev['id']; ?>"
                       class="review-row-cb" form="bulkDeleteForm">
            </div>
            <div class="review-row-icon">
                <svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div class="review-row-body">
                <div class="review-row-top">
                    <div class="review-row-top-left">
                        <div class="review-row-faculty"><?php echo htmlspecialchars($rev['faculty_name']); ?></div>
                        <div class="review-row-dept"><?php echo htmlspecialchars($rev['department'] ?? ''); ?></div>
                    </div>
                    <div class="review-row-top-right">
                        <span class="status-badge status-<?php echo $rev['status']; ?>"><?php echo ucfirst($rev['status']); ?></span>
                        <?php if ($rev['status'] === 'approved'): ?>
                        <button type="button" class="action-icon-btn" title="Edit"
                                onclick="openEditModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['review_text'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['department'])); ?>', <?php echo $rev['faculty_id']; ?>, <?php echo intval($rev['rating_teaching']); ?>, <?php echo intval($rev['rating_communication']); ?>, <?php echo intval($rev['rating_punctuality']); ?>, <?php echo intval($rev['rating_fairness']); ?>, <?php echo intval($rev['rating_overall']); ?>)">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="action-icon-btn action-icon-del" title="Delete"
                                onclick="openDeleteModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>')">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </div>
                </div>
                <div class="review-row-text"><?php echo htmlspecialchars($rev['review_text']); ?></div>
                <div class="review-row-date"><?php echo date("F j, Y · g:i A", strtotime($rev['created_at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <div id="reviewEmptyState" style="display:none;" class="review-empty">
            <p style="color:var(--gray-400);">No reviews match this filter.</p>
        </div>
        <?php endif; ?>
        </form>
    </div>

    <!-- ══ Recent Activity ═══════════════════════════════════════════════ -->
    <div class="review-card">
        <div class="review-card-header">
            <div class="review-card-title">
                <svg width="18" height="18" fill="none" stroke="var(--maroon)" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Recent Activity
            </div>
        </div>
        <?php if (empty($activities)): ?>
        <div class="review-empty">
            <div class="review-empty-icon">
                <svg width="28" height="28" fill="none" stroke="var(--maroon)" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <p>No recent activity yet.</p>
        </div>
        <?php else: ?>
        <div class="activity-feed">
            <?php foreach ($activities as $act):
                $msg = $act['message'];
                $isApproved = strpos($msg, 'approved') !== false;
                $isRejected = strpos($msg, 'rejected') !== false;
                $isDeleted  = strpos($msg, 'deleted') !== false || strpos($msg, 'removed') !== false;
                $dotColor   = $isApproved ? '#d1fae5' : ($isRejected ? '#fee2e2' : ($isDeleted ? '#f3f4f6' : '#dbeafe'));
                $iconColor  = $isApproved ? '#10b981' : ($isRejected ? '#ef4444' : ($isDeleted ? '#6b7280' : '#1d4ed8'));
                $iconPath   = $isApproved
                    ? 'M22 11.08V12a10 10 0 11-5.93-9.14 M22 4L12 14.01l-3-3'
                    : ($isRejected
                        ? 'M18 6L6 18M6 6l12 12'
                        : ($isDeleted
                            ? 'M3 6h18M19 6l-1 14H6L5 6M9 6V4h6v2'
                            : 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z'));
            ?>
            <div class="activity-item">
                <div class="activity-dot" style="background:<?php echo $dotColor; ?>;">
                    <svg width="16" height="16" fill="none" stroke="<?php echo $iconColor; ?>" stroke-width="2" viewBox="0 0 24 24"><path d="<?php echo $iconPath; ?>"/></svg>
                </div>
                <div class="activity-body">
                    <div class="activity-title"><?php echo htmlspecialchars($msg); ?></div>
                    <div class="activity-sub"><?php echo date("M j, Y · g:i A", strtotime($act['created_at'])); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ══ Edit Review Modal ═════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <h3>Edit Review</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editForm" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="selected-preview show" id="editPreview">
                    <img id="editPreviewImg" src="" alt="">
                    <div class="selected-preview-info">
                        <strong id="editPreviewName"></strong>
                        <span id="editPreviewDept"></span>
                    </div>
                </div>
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
                <div style="margin-top:12px;">
                    <div style="font-size:13px;font-weight:600;color:var(--gray-600);margin-bottom:7px;">Update Photos <span style="font-weight:400;color:var(--gray-400);">(optional — replaces existing, up to 5)</span></div>
                    <input type="file" name="edit_review_photos[]" id="editReviewPhotosInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="previewEditReviewPhotos(this)">
                    <div id="editPhotoDropzone" onclick="document.getElementById('editReviewPhotosInput').click()"
                         style="border:2px dashed var(--gray-200);border-radius:var(--radius-sm);padding:14px;text-align:center;cursor:pointer;background:var(--gray-100);">
                        <svg width="22" height="22" fill="none" stroke="var(--gray-400)" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 5px;display:block;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                        <div style="font-size:13px;color:var(--gray-500);">Click to upload new photos</div>
                    </div>
                    <div id="editReviewPhotosPreviewGrid" style="display:none;margin-top:8px;grid-template-columns:repeat(auto-fill,minmax(70px,1fr));gap:6px;"></div>
                    <div id="editPhotosAddMore" style="display:none;margin-top:6px;">
                        <button type="button" onclick="document.getElementById('editReviewPhotosInput').click()" style="font-size:12px;color:var(--maroon);background:var(--maroon-pale);border:1px solid rgba(139,0,0,0.15);border-radius:20px;padding:3px 10px;cursor:pointer;font-family:'DM Sans',sans-serif;">+ Add more</button>
                        <span id="editPhotosCount" style="font-size:12px;color:var(--gray-400);margin-left:8px;"></span>
                    </div>
                </div>
                <input type="hidden" name="review_id" id="editReviewId">
                <p style="font-size:13px;color:var(--gray-400);margin-top:8px;">⚠️ Editing will reset your review to pending status for re-approval.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" name="edit_review" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Delete Confirm Modal ══════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h3>Delete Review</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body" style="text-align:center;padding:30px 24px;">
                <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg width="26" height="26" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                </div>
                <p style="font-size:15px;font-weight:600;color:var(--gray-800);margin-bottom:8px;">Delete this review?</p>
                <p style="font-size:14px;color:var(--gray-400);">Your review for <strong id="deleteFacultyName"></strong> will be permanently removed.</p>
                <input type="hidden" name="review_id" id="deleteReviewId">
            </div>
            <div class="modal-footer" style="justify-content:center;gap:12px;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" name="delete_review" style="padding:10px 22px;border-radius:20px;background:#ef4444;color:white;border:none;font-size:14px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Resubmit Modal ════════════════════════════════════════════════════ -->
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
                <p style="font-size:14px;color:var(--gray-600);margin-bottom:12px;margin-top:8px;line-height:1.6;">Write a new review below — it will replace the rejected one.</p>
                <textarea class="modal-textarea" name="review_text" id="resubmitText" placeholder="Write your new review here..." required></textarea>
                <input type="hidden" name="old_review_id" id="resubmitReviewId">
                <input type="hidden" name="faculty_id"    id="resubmitFacultyId">
                <p style="font-size:13px;color:var(--gray-400);margin-top:8px;">🔒 Your identity remains anonymous.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeResubmitModal()">Cancel</button>
                <button type="submit" name="resubmit_review" class="btn-primary">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Write Review Modal ════════════════════════════════════════════════ -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3><span id="modalStepTitle">Select a Faculty Member</span></h3>
            <button class="modal-close" onclick="closeReviewModal()">&times;</button>
        </div>
        <form method="POST" id="reviewForm" enctype="multipart/form-data">
            <div class="modal-body">
                <!-- Step 1 -->
                <div class="step active" id="step1">
                    <div class="step-label">Step 1 of 2 · Choose Faculty</div>
                    <?php
                    $has_any_option = false;
                    foreach ($departments as $dept => $dept_faculties):
                        $available = array_filter($dept_faculties, fn($f) => !isset($user_reviews_map[$f['id']]));
                        if (empty($available)) continue;
                        $has_any_option = true;
                    ?>
                    <div class="dept-group">
                        <div class="dept-header" onclick="toggleDept(this)">
                            <span><?php echo htmlspecialchars($dept); ?> <span style="font-weight:400;color:var(--gray-400);font-size:12px;">(<?php echo count($available); ?>)</span></span>
                            <span class="chevron">▼</span>
                        </div>
                        <div class="dept-body">
                            <?php foreach ($available as $f):
                                $f_avatar_modal = (!empty($f['photo']) && file_exists($f['photo']))
                                    ? htmlspecialchars($f['photo'])
                                    : 'https://ui-avatars.com/api/?name='.urlencode($f['name']).'&background=8B0000&color=fff&size=40';
                            ?>
                            <div class="faculty-option"
                                 onclick="selectFaculty(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($f['name'])); ?>', '<?php echo htmlspecialchars(addslashes($dept)); ?>', '<?php echo $f_avatar_modal; ?>')">
                                <img src="<?php echo $f_avatar_modal; ?>" alt="">
                                <?php echo htmlspecialchars($f['name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$has_any_option): ?>
                    <div style="text-align:center;padding:30px 20px;color:var(--gray-400);">
                        <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 10px;display:block;opacity:0.4;"><polyline points="20 6 9 17 4 12"/></svg>
                        <div style="font-size:15px;font-weight:600;margin-bottom:4px;">All faculties reviewed!</div>
                        <div style="font-size:13px;">You've already submitted a review for every faculty member.</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Step 2 -->
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
                    <div class="rating-section-title">Rate the Faculty</div>
                    <?php
                    $rating_categories = [
                        ['teaching',      'Teaching Effectiveness',     'How well does the faculty explain and deliver lessons?'],
                        ['communication', 'Communication Skills',       'How clear and approachable is the faculty?'],
                        ['punctuality',   'Punctuality & Availability', 'Does the faculty arrive on time and is available when needed?'],
                        ['fairness',      'Fairness in Grading',        'Are grades given fairly and consistently?'],
                        ['overall',       'Overall Satisfaction',       'Your overall experience with this faculty.'],
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
                    <textarea class="modal-textarea" name="review_text" id="reviewText"
                              placeholder="Write your anonymous review here... Be honest, constructive, and respectful."
                              required></textarea>
                    <div style="margin-top:14px;">
                        <div style="font-size:13px;font-weight:600;color:var(--gray-600);margin-bottom:8px;">Attach Photos <span style="font-weight:400;color:var(--gray-400);">(optional — up to 5)</span></div>
                        <input type="file" name="review_photos[]" id="reviewPhotosInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="previewReviewPhotos(this)">
                        <div id="reviewPhotoDropzone" onclick="document.getElementById('reviewPhotosInput').click()"
                             style="border:2px dashed var(--gray-200);border-radius:var(--radius-sm);padding:18px 14px;text-align:center;cursor:pointer;background:var(--gray-100);">
                            <svg width="28" height="28" fill="none" stroke="var(--gray-400)" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                            <div style="font-size:14px;font-weight:500;color:var(--gray-600);margin-bottom:2px;">Click to upload photos</div>
                            <div style="font-size:12px;color:var(--gray-400);">JPG, PNG, WEBP · Max 5MB each · Up to 5 photos · AI safety checked</div>
                        </div>
                        <div id="reviewPhotosPreviewGrid" style="display:none;margin-top:10px;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:8px;"></div>
                        <div id="reviewPhotosAddMore" style="display:none;margin-top:6px;">
                            <button type="button" onclick="document.getElementById('reviewPhotosInput').click()" style="font-size:12px;color:var(--maroon);background:var(--maroon-pale);border:1px solid rgba(139,0,0,0.15);border-radius:20px;padding:3px 10px;cursor:pointer;font-family:'DM Sans',sans-serif;">+ Add more photos</button>
                            <span id="reviewPhotosCount" style="font-size:12px;color:var(--gray-400);margin-left:8px;"></span>
                        </div>
                    </div>
                    <input type="hidden" name="faculty_id" id="facultyIdInput">
                    <p style="font-size:13px;color:var(--gray-400);margin-top:8px;">🔒 Your identity remains anonymous. Reviews are reviewed by admin before publishing.</p>
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

<!-- ══ Chatbot Bubble ════════════════════════════════════════════════════ -->
<div id="chat-bubble" onclick="toggleChat()">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
</div>

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
        <div class="chat-msg bot">Hi! Ask me anything about using OlshcoReview. 👋</div>
    </div>
    <div id="chat-footer">
        <input id="chat-input" type="text" placeholder="Type a question..." onkeydown="if(event.key==='Enter') sendChat()">
        <button id="chat-send" onclick="sendChat()">&#9658;</button>
    </div>
</div>

<script>window.GROQ_API_KEY = '<?php $env = parse_ini_file(__DIR__ . '/.env'); echo $env['GROQ_API_KEY']; ?>';</script>

<script src="assets/js/dashboard.js"></script>
<script src="assets/js/session_timeout.js"></script>

<script>
// Wire pagination visibility to Show More
(function() {
    const pag = document.getElementById('pagination');
    if (pag) {
        // Pagination div starts hidden; showMoreFaculty() will reveal it via paginateCards()
        // We override renderPagination to also show the pagination container
        const origRenderPag = window.renderPagination;
        // Ensure pagination div is visible when pagination renders
        const origPaginateCards = window.paginateCards;
        window.paginateCards = function(cards) {
            if (pag) pag.style.display = 'flex';
            // Call the original
            const totalPages = Math.ceil(cards.length / 8);
            window.currentPage = 1;
            window.renderPage(cards, 1, totalPages);
        };
    }
})();
</script>
</body>
</html>