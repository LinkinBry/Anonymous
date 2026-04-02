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

$notif_count   = 0;
$notifications = [];
$notif_res     = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id='$user_id' ORDER BY created_at DESC LIMIT 5");
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

/* ── Review submit ─────────────────────────────────────────────────────── */
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
- Negative opinions about teaching style, punctuality, or performance WITHOUT profanity are NOT toxic.
- When in doubt about profanity, DO flag as toxic.

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
    header("Location: dashboard.php?submitted=1"); exit();
}

/* ── Review edit ───────────────────────────────────────────────────────── */
if (isset($_POST['edit_review'])) {
    $review_id   = intval($_POST['review_id']);
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) { header("Location: dashboard.php?error=empty"); exit(); }

    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];
    $normalized = preg_replace('/tanginamo|tangina|punyeta|gago|putangina|ulol|bobo|tanga|hinayupak|pakingshet|pakshet|tarantado|bwisit|lintik|ampota|inamo|kingina|kupalmerda|leche|pesteng yawa/i',
        ' [PROFANITY] ', $review_text);
    $prompt  = 'You are a multilingual content moderator. Return valid JSON only:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}
IMPORTANT RULES:
- Flag as toxic for: insults, slurs, personal attacks, threats, offensive language, harassment, profanity.
- [PROFANITY] markers = detected profanity — ALWAYS flag as toxic.
- Do NOT flag Filipino/Tagalog text without profanity.
- Negative opinions about teaching WITHOUT profanity are NOT toxic.
Review: "' . addslashes($normalized) . '"';

    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response = curl_exec($ch); curl_close($ch);
    $data     = json_decode($response, true);
    $ai_raw   = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai       = json_decode(trim($ai_raw), true);
    $is_toxic = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;

    if ($is_toxic) { header("Location: dashboard.php?error=toxic"); exit(); }

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

    if (!empty($_FILES['edit_review_photos']['name'][0])) {
        $allowed_types    = ['image/jpeg','image/png','image/webp','image/gif'];
        $env2             = parse_ini_file(__DIR__ . '/.env');
        $groq_key         = $env2['GROQ_API_KEY'] ?? '';
        $uploaded_edit    = [];
        $count            = count($_FILES['edit_review_photos']['name']);
        for ($pi = 0; $pi < $count; $pi++) {
            if ($_FILES['edit_review_photos']['error'][$pi] !== UPLOAD_ERR_OK) continue;
            $ftype = mime_content_type($_FILES['edit_review_photos']['tmp_name'][$pi]);
            if (!in_array($ftype, $allowed_types) || $_FILES['edit_review_photos']['size'][$pi] > 5*1024*1024) continue;
            $img_data = base64_encode(file_get_contents($_FILES['edit_review_photos']['tmp_name'][$pi]));
            $img_safe = true;
            if ($groq_key) {
                $vp = json_encode(['model'=>'meta-llama/llama-4-scout-17b-16e-instruct','max_tokens'=>80,'messages'=>[['role'=>'user','content'=>[['type'=>'image_url','image_url'=>['url'=>'data:'.$ftype.';base64,'.$img_data]],['type'=>'text','text'=>'Does this image contain explicit nudity, graphic violence, hate symbols, or illegal content? Reply ONLY: {"safe": true} or {"safe": false}.']]]]]);
                $ch3 = curl_init('https://api.groq.com/openai/v1/chat/completions');
                curl_setopt_array($ch3,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$vp,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$groq_key]]);
                $vr  = curl_exec($ch3); curl_close($ch3);
                $vd  = json_decode($vr, true);
                $vraw= preg_replace('/```json|```/', '', ($vd['choices'][0]['message']['content'] ?? '{"safe":true}'));
                $vres= json_decode(trim($vraw), true);
                if (isset($vres['safe']) && $vres['safe'] === false) $img_safe = false;
            }
            if ($img_safe) {
                $ext      = pathinfo($_FILES['edit_review_photos']['name'][$pi], PATHINFO_EXTENSION);
                $filename = 'uploads/review_' . $review_id . '_' . time() . '_' . $pi . '.' . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0755, true);
                if (move_uploaded_file($_FILES['edit_review_photos']['tmp_name'][$pi], $filename)) {
                    $uploaded_edit[] = $filename;
                }
            }
        }
        if (!empty($uploaded_edit)) {
            @mysqli_query($conn, "ALTER TABLE reviews MODIFY COLUMN photo TEXT DEFAULT NULL");
            $ep = mysqli_real_escape_string($conn, json_encode($uploaded_edit));
            mysqli_query($conn, "UPDATE reviews SET photo='$ep' WHERE id='$review_id' AND user_id='$user_id'");
        }
    }
    header("Location: dashboard.php?edited=1"); exit();
}

/* ── Resubmit ──────────────────────────────────────────────────────────── */
if (isset($_POST['resubmit_review'])) {
    $old_id      = intval($_POST['old_review_id']);
    $faculty_id  = intval($_POST['faculty_id']);
    $review_text = trim($_POST['review_text'] ?? '');

    if (empty($review_text)) { header("Location: dashboard.php?error=empty"); exit(); }

    mysqli_query($conn, "DELETE FROM reviews WHERE id='$old_id' AND user_id='$user_id' AND status='rejected'");

    $env     = parse_ini_file(__DIR__ . '/.env');
    $api_key = $env['GROQ_API_KEY'];
    $prompt  = 'You are a multilingual content moderator. Return valid JSON only:
{
  "sentiment": "positive or negative or neutral",
  "is_toxic": true or false,
  "is_hateful": true or false,
  "summary": "one sentence summary in English"
}
IMPORTANT RULES:
- Only flag as toxic for CLEAR insults, slurs, personal attacks, threats, explicit offensive language, harassment, or discriminatory content.
- Do NOT flag Filipino/Tagalog text without profanity.
- Negative opinions about teaching are NOT toxic.
- When in doubt, do NOT flag as toxic.
Review: "' . addslashes($review_text) . '"';

    $payload = json_encode(['model' => 'llama-3.3-70b-versatile', 'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key]]);
    $response  = curl_exec($ch); curl_close($ch);
    $data      = json_decode($response, true);
    $ai_raw    = preg_replace('/```json|```/', '', $data['choices'][0]['message']['content'] ?? '{}');
    $ai        = json_decode(trim($ai_raw), true);
    $sentiment = mysqli_real_escape_string($conn, $ai['sentiment'] ?? 'neutral');
    $is_toxic  = (!empty($ai['is_toxic']) || !empty($ai['is_hateful'])) ? 1 : 0;
    $summary   = mysqli_real_escape_string($conn, $ai['summary'] ?? '');

    if ($is_toxic) { header("Location: dashboard.php?error=toxic"); exit(); }

    $review_text_safe = mysqli_real_escape_string($conn, $review_text);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status, sentiment, is_toxic, summary)
                         VALUES ('$user_id','$faculty_id','$review_text_safe','pending','$sentiment','$is_toxic','$summary')");
    header("Location: dashboard.php?submitted=1"); exit();
}

/* ── Delete review ─────────────────────────────────────────────────────── */
if (isset($_POST['delete_review'])) {
    $review_id = intval($_POST['review_id']);
    mysqli_query($conn, "DELETE FROM reviews WHERE id='$review_id' AND user_id='$user_id'");
    header("Location: dashboard.php?deleted=1"); exit();
}

/* ── Fetch data for view ───────────────────────────────────────────────── */
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

$review_filter = isset($_GET['review_filter']) ? $_GET['review_filter'] : 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — AnonymousReview</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>

<!-- ══ Sidebar ══════════════════════════════════════════════════════════ -->
<div class="sidebar">
  <div class="sidebar-top">
    <div class="sidebar-brand-row">
      <div class="sidebar-logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
      </div>
      <div>
        <div class="sidebar-brand-name">AnonymousReview</div>
        <div class="sidebar-brand-sub">Faculty Evaluation</div>
      </div>
    </div>
    <a href="profile.php" class="sidebar-user" style="text-decoration:none;" title="Edit Profile">
      <div class="sidebar-user-av">
        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar">
      </div>
      <div>
        <div class="sidebar-user-name"><?php echo htmlspecialchars($user['fullname']); ?></div>
        <div class="sidebar-user-role">Student</div>
      </div>
    </a>
  </div>
  <nav>
    <div class="nav-label">Menu</div>
    <a href="dashboard.php" class="active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <a href="#">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      My Reviews
      <?php if ($total_reviews > 0): ?><span class="sidebar-nav-badge"><?php echo $total_reviews; ?></span><?php endif; ?>
    </a>
    <a href="profile.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Profile
    </a>
    <div class="nav-label" style="margin-top:8px">Support</div>
    <a href="#" onclick="toggleChat(); return false;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
      FAQ Chat
    </a>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Logout
    </a>
  </div>
</div>

<!-- ══ Main ═════════════════════════════════════════════════════════════ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <h1>Good <?php echo (date('H') < 12) ? 'morning' : ((date('H') < 18) ? 'afternoon' : 'evening'); ?>, <?php echo htmlspecialchars($user['username']); ?>!</h1>
      <p><?php echo date("l, F j, Y"); ?></p>
    </div>
    <div class="topbar-right">
      <form class="tb-search" method="GET" action="dashboard.php" style="text-decoration:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="search" placeholder="Search faculty…"
               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
      </form>
      <!-- Notification Bell -->
      <div class="notif-wrap" id="notifWrap">
        <div class="notif-btn">
          <svg width="15" height="15" fill="none" stroke="#4b5563" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
        </div>
        <?php if ($notif_count > 0): ?><div class="notif-badge"><?php echo $notif_count; ?></div><?php endif; ?>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dropdown-header">
            <span style="display:flex;align-items:center;gap:6px;">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
              Notifications
            </span>
            <?php if (!empty($notifications)): ?>
            <button onclick="clearNotifications(event)"
                    style="font-size:10px;color:var(--maroon);background:none;border:1px solid var(--maroon);border-radius:8px;padding:2px 8px;cursor:pointer;font-family:'DM Sans',sans-serif;"
                    onmouseover="this.style.background='var(--maroon)';this.style.color='white'"
                    onmouseout="this.style.background='none';this.style.color='var(--maroon)'">Clear all</button>
            <?php endif; ?>
          </div>
          <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $n): ?>
            <div class="notif-item notif-<?php echo $n['status']; ?>">
              <span style="display:flex;align-items:flex-start;gap:7px;">
                <?php if (strpos($n['message'], 'approved') !== false): ?>
                  <svg width="13" height="13" style="flex-shrink:0;margin-top:1px;color:#10b981;" fill="none" stroke="#10b981" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                  <svg width="13" height="13" style="flex-shrink:0;margin-top:1px;" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($n['message']); ?></span>
              </span>
              <small><?php echo date("M j, g:i A", strtotime($n['created_at'])); ?></small>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
          <div class="notif-empty">
            <svg width="26" height="26" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
            <p>No notifications yet</p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <!-- ── Stat Cards ──────────────────────────────────────────────────── -->
    <div class="stats-grid stats-row-4">
      <div class="stat-card total">
        <div class="stat-label">Total Reviews</div>
        <div class="stat-value"><?php echo $total_reviews; ?></div>
        <div class="stat-sub">This semester</div>
        <div class="stat-icon" style="background:#F3F4F6">
          <svg viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="1.8"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
      </div>
      <div class="stat-card pending">
        <div class="stat-label">Pending</div>
        <div class="stat-value"><?php echo $pending_count; ?></div>
        <div class="stat-sub">Awaiting approval</div>
        <div class="stat-icon" style="background:#FEF3C7">
          <svg viewBox="0 0 24 24" fill="none" stroke="#B45309" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
      </div>
      <div class="stat-card approved">
        <div class="stat-label">Approved</div>
        <div class="stat-value"><?php echo $approved_count; ?></div>
        <div class="stat-sub">Published</div>
        <div class="stat-icon" style="background:#D1FAE5">
          <svg viewBox="0 0 24 24" fill="none" stroke="#065F46" stroke-width="1.8"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
      </div>
      <div class="stat-card rejected">
        <div class="stat-label">Rejected</div>
        <div class="stat-value"><?php echo $rejected_count; ?></div>
        <div class="stat-sub">Needs resubmission</div>
        <div class="stat-icon" style="background:#FEE2E2">
          <svg viewBox="0 0 24 24" fill="none" stroke="#991B1B" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
      </div>
    </div>

    <!-- ── Flash messages ──────────────────────────────────────────────── -->
    <?php if (isset($_GET['submitted'])): ?>
    <div class="success-banner"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Your review has been submitted and is pending admin approval.</div>
    <?php endif; ?>
    <?php if (isset($_GET['edited'])): ?>
    <div class="success-banner"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Your review has been updated and is pending re-approval.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
    <div class="success-banner" style="background:#fee2e2;color:#991b1b;"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>Your review has been deleted.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
      <?php if ($_GET['error'] === 'toxic'): ?>
      <div class="success-banner" style="background:#fee2e2;color:#991b1b;"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Your review was flagged as offensive. Please keep feedback respectful and constructive.</div>
      <?php elseif ($_GET['error'] === 'duplicate'): ?>
      <div class="success-banner" style="background:#fef3c7;color:#92400e;"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>You have already submitted a review for this faculty member.</div>
      <?php elseif ($_GET['error'] === 'empty'): ?>
      <div class="success-banner" style="background:#fef3c7;color:#92400e;"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Review cannot be empty.</div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- ── Faculty Section ─────────────────────────────────────────────── -->
    <div class="faculty-section-header">
      <div class="faculty-section-title">
        Faculty Members
        <span class="section-badge"><?php echo count($faculties); ?></span>
      </div>
      <div class="faculty-controls">
        <select id="deptFilter" class="filter-select" onchange="filterFaculty()">
          <option value="all">All Departments</option>
          <?php foreach (array_keys($departments) as $dept): ?>
          <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
          <?php endforeach; ?>
        </select>
        <form class="search-form" method="GET" action="dashboard.php">
          <?php if ($review_filter !== 'all'): ?><input type="hidden" name="review_filter" value="<?php echo htmlspecialchars($review_filter); ?>"><?php endif; ?>
          <svg width="13" height="13" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="searchInput" name="search" placeholder="Search faculty…"
                 value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
          <span class="search-clear" id="clearSearch">×</span>
          <button type="submit">Search</button>
        </form>
      </div>
    </div>

    <?php if (!empty($faculties)): ?>
    <div class="faculty-grid" id="facultyGrid">
      <?php foreach ($faculties as $i => $faculty):
        $user_review   = $user_reviews_map[$faculty['id']] ?? null;
        $has_reviewed  = $user_review !== null;
        $review_status = $has_reviewed ? $user_review['status'] : null;
        $fav_src       = (!empty($faculty['photo']) && file_exists($faculty['photo']))
            ? htmlspecialchars($faculty['photo'])
            : 'https://ui-avatars.com/api/?name='.urlencode($faculty['name']).'&background=7C0A02&color=fff&size=80';
        $avg = floatval($faculty['avg_stars'] ?? 0);
        $full_stars = floor($avg);
      ?>
      <div class="faculty-card" data-index="<?php echo $i; ?>" data-dept="<?php echo htmlspecialchars($faculty['department'] ?? ''); ?>">
        <img src="<?php echo $fav_src; ?>" alt="<?php echo htmlspecialchars($faculty['name']); ?>">
        <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
        <p><?php echo htmlspecialchars($faculty['department'] ?? ''); ?></p>

        <?php if ($avg > 0): ?>
        <div class="fac-stars">
          <?php for ($s = 1; $s <= 5; $s++): ?>
          <span class="<?php echo $s <= $full_stars ? 'lit' : ''; ?>">★</span>
          <?php endfor; ?>
        </div>
        <div class="fac-rating-count"><?php echo number_format($avg,1); ?> &middot; <?php echo $faculty['review_count']; ?> reviews</div>
        <?php endif; ?>

        <?php if ($has_reviewed): ?>
          <?php if ($review_status === 'approved'): ?>
          <div style="margin-bottom:6px;">
            <span class="btn-reviewed">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              Reviewed
            </span>
          </div>
          <button class="btn-evaluate" style="opacity:1;"
                  onclick="openEditModal(<?php echo $user_review['id']; ?>, '<?php echo htmlspecialchars(addslashes($user_review['review_text'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>', <?php echo $faculty['id']; ?>, <?php echo intval($user_review['rating_teaching']); ?>, <?php echo intval($user_review['rating_communication']); ?>, <?php echo intval($user_review['rating_punctuality']); ?>, <?php echo intval($user_review['rating_fairness']); ?>, <?php echo intval($user_review['rating_overall']); ?>)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Review
          </button>
          <?php elseif ($review_status === 'pending'): ?>
          <span class="status-badge status-pending">
            <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Pending
          </span>
          <?php else: ?>
          <button class="btn-rejected-card"
                  onclick="openDeleteAndResubmitModal(<?php echo $user_review['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', <?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            Resubmit
          </button>
          <?php endif; ?>
        <?php else: ?>
          <button class="btn-evaluate"
                  onclick="openModalForFaculty(<?php echo $faculty['id']; ?>, '<?php echo htmlspecialchars(addslashes($faculty['name'])); ?>', '<?php echo htmlspecialchars(addslashes($faculty['department'] ?? '')); ?>', '<?php echo $fav_src; ?>')">
            Evaluate
          </button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="pagination" id="pagination"></div>
    <?php else: ?>
    <div class="empty-state">
      <svg width="56" height="56" fill="none" stroke="#9ca3af" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      <p>No faculty found<?php echo isset($_GET['search']) ? ' for your search.' : '.'; ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Lower: My Reviews Table + Activity Feed ─────────────────────── -->
    <div class="dash-two-col">

      <!-- My Reviews table -->
      <div class="dash-card">
        <div class="dash-card-header">
          <div class="dash-card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--maroon)" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            My Reviews
            <span class="section-badge"><?php echo count($recent_reviews); ?></span>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div class="filter-tabs-inline" id="reviewFilterTabs">
              <button class="filter-tab <?php echo $review_filter==='all'      ? 'active':''; ?>" onclick="setReviewFilter('all',event)">All</button>
              <button class="filter-tab <?php echo $review_filter==='pending'  ? 'active':''; ?>" onclick="setReviewFilter('pending',event)">Pending</button>
              <button class="filter-tab <?php echo $review_filter==='approved' ? 'active':''; ?>" onclick="setReviewFilter('approved',event)">Approved</button>
              <button class="filter-tab <?php echo $review_filter==='rejected' ? 'active':''; ?>" onclick="setReviewFilter('rejected',event)">Rejected</button>
            </div>
            <button class="write-review-btn" onclick="openReviewModal()">
              <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Write a review
            </button>
          </div>
        </div>

        <?php if (empty($recent_reviews)): ?>
        <div class="review-empty" id="reviewEmptyState">
          <div class="review-empty-icon">
            <svg width="28" height="28" fill="none" stroke="var(--maroon)" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
          </div>
          <p>No reviews yet. Share your feedback!</p>
          <button class="write-review-btn" onclick="openReviewModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="width:11px;height:11px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Write Your First Review
          </button>
        </div>
        <?php else: ?>
        <table class="rev-table">
          <thead>
            <tr>
              <th style="width:20%">Faculty</th>
              <th style="width:16%">Dept</th>
              <th style="width:28%">Review</th>
              <th style="width:15%">Rating</th>
              <th style="width:10%">Status</th>
              <th style="width:11%"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recent_reviews as $rev): ?>
          <tr class="review-row" data-status="<?php echo $rev['status']; ?>">
            <td style="font-weight:500"><?php echo htmlspecialchars($rev['faculty_name']); ?></td>
            <td><span class="dept-chip"><?php echo htmlspecialchars($rev['department'] ?? ''); ?></span></td>
            <td class="rev-td-trunc"><?php echo htmlspecialchars($rev['review_text']); ?></td>
            <td>
              <?php $ro = intval($rev['rating_overall']); if ($ro > 0): ?>
              <div class="fac-stars-row">
                <?php for ($s=1;$s<=5;$s++): ?><span class="<?php echo $s<=$ro?'lit':''; ?>">★</span><?php endfor; ?>
                <span class="score"><?php echo $ro; ?>.0</span>
              </div>
              <?php else: ?><span style="color:var(--gray-400);font-size:10px;">—</span><?php endif; ?>
            </td>
            <td><span class="status-badge status-<?php echo $rev['status']; ?>"><?php echo ucfirst($rev['status']); ?></span></td>
            <td>
              <div style="display:flex;gap:4px;">
                <?php if ($rev['status'] === 'approved'): ?>
                <button class="action-btn" title="Edit"
                        onclick="openEditModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['review_text'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['department'])); ?>', <?php echo $rev['faculty_id']; ?>, <?php echo intval($rev['rating_teaching']); ?>, <?php echo intval($rev['rating_communication']); ?>, <?php echo intval($rev['rating_punctuality']); ?>, <?php echo intval($rev['rating_fairness']); ?>, <?php echo intval($rev['rating_overall']); ?>)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4z"/></svg>
                  Edit
                </button>
                <?php elseif ($rev['status'] === 'rejected'): ?>
                <button class="action-btn" title="Resubmit"
                        onclick="openDeleteAndResubmitModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>', <?php echo $rev['faculty_id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>', '<?php echo htmlspecialchars(addslashes($rev['department'])); ?>')"
                        style="color:#991B1B;border-color:#FCA5A5;">
                  Resubmit
                </button>
                <?php endif; ?>
                <button class="action-btn danger" title="Delete"
                        onclick="openDeleteModal(<?php echo $rev['id']; ?>, '<?php echo htmlspecialchars(addslashes($rev['faculty_name'])); ?>')">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <!-- Activity Feed -->
      <div class="dash-card">
        <div class="feed-header">
          <h3>Recent activity</h3>
          <?php if (!empty($notifications)): ?>
          <span class="feed-header-link" onclick="clearNotifications(event)">Mark all read</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($notifications)): ?>
          <?php foreach ($notifications as $n): ?>
          <div class="feed-item">
            <div class="feed-dot" style="background:<?php echo strpos($n['message'],'approved')!==false ? '#10B981' : (strpos($n['message'],'rejected')!==false ? '#E24B4A' : '#F59E0B'); ?>"></div>
            <div>
              <div class="feed-text"><?php echo htmlspecialchars($n['message']); ?></div>
              <div class="feed-time"><?php echo date("M j, g:i A", strtotime($n['created_at'])); ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
        <div style="padding:24px 14px;text-align:center;color:var(--gray-400);font-size:12px;">
          No recent activity.
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end dash-two-col -->

  </div><!-- end .content -->
</div><!-- end .main -->

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
                    <div style="font-size:11px;font-weight:600;color:var(--gray-600);margin-bottom:6px;">Update Photos <span style="font-weight:400;color:var(--gray-400);">(optional — replaces existing, up to 5)</span></div>
                    <input type="file" name="edit_review_photos[]" id="editReviewPhotosInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="previewEditReviewPhotos(this)">
                    <div id="editPhotoDropzone" onclick="document.getElementById('editReviewPhotosInput').click()"
                         style="border:2px dashed var(--gray-200);border-radius:var(--radius-sm);padding:12px;text-align:center;cursor:pointer;background:var(--gray-100);">
                        <svg width="20" height="20" fill="none" stroke="var(--gray-400)" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 4px;display:block;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                        <div style="font-size:11px;color:var(--gray-500);">Click to upload new photos</div>
                        <div style="font-size:10px;color:var(--gray-400);margin-top:1px;">JPG, PNG, WEBP · Max 5MB · Up to 5</div>
                    </div>
                    <div id="editReviewPhotosPreviewGrid" style="display:none;margin-top:7px;grid-template-columns:repeat(auto-fill,minmax(70px,1fr));gap:6px;"></div>
                    <div id="editPhotosAddMore" style="display:none;margin-top:5px;">
                        <button type="button" onclick="document.getElementById('editReviewPhotosInput').click()" style="font-size:10px;color:var(--maroon);background:var(--maroon-pale);border:1px solid rgba(124,10,2,0.12);border-radius:20px;padding:2px 9px;cursor:pointer;font-family:'DM Sans',sans-serif;">+ Add more</button>
                        <span id="editPhotosCount" style="font-size:10px;color:var(--gray-400);margin-left:6px;"></span>
                    </div>
                </div>
                <input type="hidden" name="review_id" id="editReviewId">
                <p style="font-size:11px;color:var(--gray-400);margin-top:8px;">⚠️ Editing will reset your review to pending status for re-approval.</p>
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
    <div class="modal-box" style="max-width:400px;">
        <div class="modal-header">
            <h3>Delete Review</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body" style="text-align:center;padding:24px 20px;">
                <div style="width:50px;height:50px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                    <svg width="22" height="22" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </div>
                <p style="font-size:14px;font-weight:500;color:var(--gray-800);margin-bottom:6px;">Delete this review?</p>
                <p style="font-size:12px;color:var(--gray-400);">Your review for <strong id="deleteFacultyName"></strong> will be permanently removed.</p>
                <input type="hidden" name="review_id" id="deleteReviewId">
            </div>
            <div class="modal-footer" style="justify-content:center;gap:10px;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" name="delete_review" style="padding:8px 20px;border-radius:20px;background:#ef4444;color:white;border:none;font-size:12px;font-weight:500;cursor:pointer;font-family:'DM Sans',sans-serif;">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Resubmit Modal ════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="resubmitModal">
    <div class="modal-box" style="max-width:440px;">
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
                        <span style="font-size:11px;color:#991b1b;">Previously rejected</span>
                    </div>
                </div>
                <p style="font-size:12px;color:var(--gray-600);margin-bottom:10px;margin-top:6px;line-height:1.5;">Your previous review was rejected. Write a new one below — it will replace the rejected one.</p>
                <textarea class="modal-textarea" name="review_text" id="resubmitText" placeholder="Write your new review here..." required></textarea>
                <input type="hidden" name="old_review_id" id="resubmitReviewId">
                <input type="hidden" name="faculty_id"    id="resubmitFacultyId">
                <p style="font-size:11px;color:var(--gray-400);margin-top:6px;">🔒 Your identity remains anonymous.</p>
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
                            <span><?php echo htmlspecialchars($dept); ?> <span style="font-weight:400;color:var(--gray-400);font-size:11px;">(<?php echo count($available); ?>)</span></span>
                            <span class="chevron">▼</span>
                        </div>
                        <div class="dept-body">
                            <?php foreach ($available as $f):
                                $fa = (!empty($f['photo']) && file_exists($f['photo']))
                                    ? htmlspecialchars($f['photo'])
                                    : 'https://ui-avatars.com/api/?name='.urlencode($f['name']).'&background=7C0A02&color=fff&size=40';
                            ?>
                            <div class="faculty-option"
                                 onclick="selectFaculty(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($f['name'])); ?>', '<?php echo htmlspecialchars(addslashes($dept)); ?>', '<?php echo $fa; ?>')">
                                <img src="<?php echo $fa; ?>" alt="">
                                <?php echo htmlspecialchars($f['name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$has_any_option): ?>
                    <div style="text-align:center;padding:28px 20px;color:var(--gray-400);">
                        <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;opacity:0.4;"><polyline points="20 6 9 17 4 12"/></svg>
                        <div style="font-size:13px;font-weight:500;margin-bottom:3px;">All faculties reviewed!</div>
                        <div style="font-size:11px;">You've already submitted a review for every faculty member.</div>
                    </div>
                    <?php endif; ?>
                </div>

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
                            <label for="star_<?php echo $cat[0].$i; ?>" title="<?php echo $i; ?> star<?php echo $i>1?'s':''; ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="rating-section-title" style="margin-top:16px;">Write Your Review</div>
                    <textarea class="modal-textarea" name="review_text" id="reviewText"
                              placeholder="Write your anonymous review here... Be honest, constructive, and respectful."
                              required></textarea>
                    <div style="margin-top:12px;">
                        <div style="font-size:11px;font-weight:600;color:var(--gray-600);margin-bottom:6px;">Attach Photos <span style="font-weight:400;color:var(--gray-400);">(optional — up to 5)</span></div>
                        <input type="file" name="review_photos[]" id="reviewPhotosInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="previewReviewPhotos(this)">
                        <div id="reviewPhotoDropzone" onclick="document.getElementById('reviewPhotosInput').click()"
                             style="border:2px dashed var(--gray-200);border-radius:var(--radius-sm);padding:16px 12px;text-align:center;cursor:pointer;background:var(--gray-100);">
                            <svg width="24" height="24" fill="none" stroke="var(--gray-400)" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 6px;display:block;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                            <div style="font-size:12px;font-weight:500;color:var(--gray-600);margin-bottom:2px;">Click to upload photos</div>
                            <div style="font-size:10px;color:var(--gray-400);">JPG, PNG, WEBP · Max 5MB · Up to 5 · AI safety checked</div>
                        </div>
                        <div id="reviewPhotosPreviewGrid" style="display:none;margin-top:8px;grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:7px;"></div>
                        <div id="reviewPhotosAddMore" style="display:none;margin-top:5px;">
                            <button type="button" onclick="document.getElementById('reviewPhotosInput').click()" style="font-size:10px;color:var(--maroon);background:var(--maroon-pale);border:1px solid rgba(124,10,2,0.12);border-radius:20px;padding:2px 9px;cursor:pointer;font-family:'DM Sans',sans-serif;">+ Add more photos</button>
                            <span id="reviewPhotosCount" style="font-size:10px;color:var(--gray-400);margin-left:6px;"></span>
                        </div>
                    </div>
                    <input type="hidden" name="faculty_id" id="facultyIdInput">
                    <p style="font-size:11px;color:var(--gray-400);margin-top:6px;">🔒 Your identity remains anonymous. Reviews are moderated before publishing.</p>
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
    <svg width="22" height="22" viewBox="0 0 24 24" fill="white"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
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
        <div class="chat-msg bot">Hi! Ask me anything about using AnonymousReview. 👋</div>
    </div>
    <div id="chat-footer">
        <input id="chat-input" type="text" placeholder="Type a question..." onkeydown="if(event.key==='Enter') sendChat()">
        <button id="chat-send" onclick="sendChat()">&#9658;</button>
    </div>
</div>

<script src="assets/js/dashboard.js"></script>
<script src="assets/js/session_timeout.js"></script>
</body>
</html>