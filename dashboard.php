<?php
include "config.php"; // session_start() should already be inside config.php

// Ensure user_id is set
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
} else {
    header("Location: login.php");
    exit();
}

// Now safe to use $user_id
$query = "SELECT fullname FROM users WHERE id='$user_id' LIMIT 1";
$result = mysqli_query($conn, $query);
$user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : ['fullname' => 'User'];

// Count unread notifications for this user
$notif_count = 0;
$notif_res = mysqli_query($conn, "SELECT COUNT(*) AS total 
                                  FROM notifications 
                                  WHERE user_id='$user_id' AND status='unread'");
if ($notif_res) {
    $notif_row = mysqli_fetch_assoc($notif_res);
    $notif_count = $notif_row['total'];
}

// Get user info from database
$user_id = $_SESSION['user_id'];
$query = "SELECT fullname FROM users WHERE id='$user_id' LIMIT 1";
$result = mysqli_query($conn, $query);
$user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : ['fullname' => 'User'];

// Count unread notifications
$notif_count = 0;
$notifications = [];
$notif_res = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id='$user_id' ORDER BY created_at DESC LIMIT 5");
if ($notif_res && mysqli_num_rows($notif_res) > 0) {
    while ($row = mysqli_fetch_assoc($notif_res)) {
        $notifications[] = $row;
        if ($row['status'] === 'unread') {
            $notif_count++;
        }
    }
}


// Fetch faculties with search filter
$faculties = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = mysqli_real_escape_string($conn, $_GET['search']);
    $faculty_query = "SELECT id, name, department 
                      FROM faculties 
                      WHERE name LIKE '%$search%' OR department LIKE '%$search%' 
                      ORDER BY name ASC";
} else {
    $faculty_query = "SELECT id, name, department FROM faculties ORDER BY name ASC";
}

$faculty_result = mysqli_query($conn, $faculty_query);
if ($faculty_result && mysqli_num_rows($faculty_result) > 0) {
    while ($row = mysqli_fetch_assoc($faculty_result)) {
        $faculties[] = $row;
    }
}

// Handle review submission (PRG pattern)
if (isset($_POST['submit_review'])) {
    $faculty_id = intval($_POST['faculty_id']);
    $review_text = mysqli_real_escape_string($conn, $_POST['review_text']);
    mysqli_query($conn, "INSERT INTO reviews (user_id, faculty_id, review_text, status) 
                         VALUES ('$user_id','$faculty_id','$review_text','pending')");
    header("Location: dashboard.php?faculty_id=" . $faculty_id . "&submitted=1");
    exit();
}

// Fetch selected faculty and reviews
$selected_faculty = null;
$faculty_reviews = [];
if (isset($_GET['faculty_id'])) {
    $faculty_id = intval($_GET['faculty_id']);
    $faculty_res = mysqli_query($conn, "SELECT * FROM faculties WHERE id='$faculty_id'");
    if ($faculty_res && mysqli_num_rows($faculty_res) > 0) {
        $selected_faculty = mysqli_fetch_assoc($faculty_res);
    }

    $review_res = mysqli_query($conn, "
        SELECT r.*, u.fullname 
        FROM reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.faculty_id='$faculty_id'
        ORDER BY r.created_at DESC
    ");
    if ($review_res && mysqli_num_rows($review_res) > 0) {
        while ($row = mysqli_fetch_assoc($review_res)) {
            $faculty_reviews[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>User Dashboard - AnonymousReview</title>
<style>
body { margin:0; font-family:Arial, sans-serif; background:#f5f5f5; }

/* Sidebar */
.sidebar {
    position:fixed; left:0; top:0; width:220px; height:100%;
    background:#8B0000; color:white; display:flex; flex-direction:column;
    align-items:center; padding-top:20px; box-shadow:2px 0 5px rgba(0,0,0,0.1);
}
.sidebar img.avatar { background: white; width:80px; height:80px; border-radius: 50%; border: 2px solid #fff; margin-bottom:10px; object-fit:cover; }
.sidebar h2 { margin-bottom:30px; font-size:18px; text-align:center; }
.sidebar a { width:90%; padding:12px; margin:5px 0; text-decoration:none; color:white; display:flex; align-items:center; gap:10px; border-radius:8px; transition:0.2s; }
.sidebar a:hover { background:rgba(255,255,255,0.2); }

/* Main content */
.main-content { margin-left:220px; padding:30px; }
.dashboard-header { background:maroon; padding:25px; border-radius:15px; margin-top:25px; margin-bottom:30px; box-shadow:0 4px 6px rgba(0,0,0,0.1); text-align:center; }
.dashboard-header h1{ color:white; margin-bottom:10px; }
.dashboard-header p { color:white; margin-bottom:10px; }

/* Search Bar */
.search-container {
    display: flex;
    justify-content: flex-end; /* pushes search bar to the right */
    margin: 10px 20px;
}
.search-container form {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}
.search-container input {
    padding: 8px 30px 8px 8px;
    width: 250px;
    border: 1px solid #ccc;
    border-radius: 4px;
}
.search-container #clearSearch {
    position: absolute;
    right: 80px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #888;
    font-weight: bold;
    background: #eee;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    text-align: center;
    line-height: 18px;
    display: none; /* hidden until text entered */
}
.search-container button {
    padding: 8px 12px;
    background: #800000;
    color: #fff;
    border: none;
    border-radius: 4px;
    margin-left: 5px;
}

/* Faculty Grid */
.faculty-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:20px; }
.faculty-card { background:white; padding:20px; border-radius:15px; box-shadow:0 4px 8px rgba(0,0,0,0.1); text-align:center; transition:0.3s; }
.faculty-card:hover { transform:translateY(-5px); box-shadow:0 6px 12px rgba(0,0,0,0.2); }
.faculty-card img { width:80px; height:80px; border-radius:50%; margin-bottom:15px; }
.faculty-card h3 { color:#8B0000; margin-bottom:10px; font-size:18px; }
.faculty-card a { text-decoration:none; color:white; background:#8B0000; padding:8px 15px; border-radius:10px; font-weight:bold; transition:0.2s; }
.faculty-card a:hover { background:#a30000; }

/* Review Section */
.review-section { margin-top:40px; background:white; padding:20px; border-radius:10px; }
.review-section h2 { color:#8B0000; }
.review-section ul { list-style:none; padding:0; }
.review-section li { margin-bottom:10px; padding:10px; border-bottom:1px solid #ddd; }

/* Notification Bell */
.notification {
    position: absolute;
    top: 10px;
    right: 20px;
    cursor: pointer;
}

.bell-icon {
    width: 28px;
    height: 28px;
    margin-bottom: 10px;
    /* no filter here */
}

.badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: red;
    color: white;
    font-size: 12px;
    font-weight: bold;
    border-radius: 50%;
    padding: 3px 6px;
}

/* Dropdown */
.dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 35px;
    background: white;
    color: black;
    border: 1px solid #ccc;
    border-radius: 6px;
    width: 350px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    z-index: 100;
}
.dropdown ul { list-style:none; margin:0; padding:0; }
.dropdown li { padding:10px; border-bottom:1px solid #eee; font-size:14px; }
.dropdown li small { display:block; color:#666; font-size:12px; }
.dropdown p { padding:10px; margin:0; }


</style>
</head>
<body>
<!-- Sidebar -->
<div class="sidebar">
    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($user['fullname']); ?>&background=8B0000&color=fff&size=80" class="avatar" alt="User Avatar">
    <h2><?php echo htmlspecialchars($user['fullname']); ?></h2>
    <a href="index.php">Home</a>
    <a href="dashboard.php">Dashboard</a>
    <a href="#">Evaluation History</a>
    <a href="avatar.php">Profile</a>
    <a href="#">Analytics</a>
    <a href="logout.php">Logout</a>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="dashboard-header">
    <h1>Anonymous Online Faculty Performance Evaluation and Feedback System</h1>
    <p>Select a faculty member below to provide anonymous evaluation.</p>

    <!-- Notification Bell -->
    <div class="notification">
        <svg xmlns="http://www.w3.org/2000/svg" class="bell-icon" viewBox="0 0 24 24" width="28" height="28" fill="#800000">
          <path d="M12 24c1.104 0 2-.896 2-2h-4c0 1.104.896 2 2 2zm6.364-6c-.828 0-1.5-.672-1.5-1.5V11c0-3.309-2.691-6-6-6s-6 2.691-6 6v5.5c0 .828-.672 1.5-1.5 1.5H4v2h16v-2h-1.636z"/>
        </svg>
        <?php if ($notif_count > 0): ?>
            <span class="badge"><?php echo $notif_count; ?></span>
        <?php endif; ?>

        <!-- Dropdown -->
        <div class="dropdown" id="notifDropdown">
            <?php if (!empty($notifications)): ?>
                <ul>
                    <?php foreach ($notifications as $n): ?>
                        <li>
                            <?php echo htmlspecialchars($n['message']); ?>
                            <small><?php echo date("M j, g:i A", strtotime($n['created_at'])); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No notifications</p>
            <?php endif; ?>
        </div>
    </div>

</div>


    <!-- Search Bar -->
    <div class="search-container">
        <form method="GET" action="dashboard.php">
            <input type="text" id="searchInput" name="search" placeholder="Search faculty..." 
                value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            <span id="clearSearch">×</span>
            <button type="submit">Search</button>
        </form>
    </div>
        <!-- Faculty Grid -->
    <div class="faculty-grid">
        <?php if (!empty($faculties)): ?>
            <?php foreach ($faculties as $faculty): ?>
                <div class="faculty-card">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($faculty['name']); ?>&background=8B0000&color=fff&size=80" alt="Faculty Avatar">
                    <h3><?php echo $faculty['name']; ?></h3>
                    <p><?php echo $faculty['department']; ?></p>
                    <a href="dashboard.php?faculty_id=<?php echo $faculty['id']; ?>">Evaluate</a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color:red;">No faculty found for your search.</p>
        <?php endif; ?>
    </div>

    <!-- Review Section -->
    <?php if ($selected_faculty): ?>
        <div class="review-section">
            <h2>Reviews for <?php echo htmlspecialchars($selected_faculty['name']); ?></h2>

            <?php if (!empty($faculty_reviews)): ?>
                <h3>All Submitted Reviews</h3>
                <ul>
                    <?php foreach ($faculty_reviews as $rev): ?>
                        <li>
                            <strong>Anonymous:</strong>
                            <?php echo htmlspecialchars($rev['review_text']); ?>
                            <?php if ($rev['status'] != 'approved'): ?>
                                <em>(Status: <?php echo htmlspecialchars($rev['status']); ?>)</em>
                            <?php endif; ?>
                            <br>
                            <small>Submitted on: <?php echo date("F j, Y g:i A", strtotime($rev['created_at'])); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No reviews yet for this faculty.</p>
            <?php endif; ?>

            <h3>Submit a New Review</h3>
            <?php if (isset($_GET['submitted'])) echo "<p style='color:green;'>Your review has been submitted and is pending admin approval.</p>"; ?>
            <form method="POST">
                <textarea name="review_text" rows="4" cols="50" required></textarea><br><br>
                <input type="hidden" name="faculty_id" value="<?php echo $selected_faculty['id']; ?>">
                <button type="submit" name="submit_review" style="background:#8B0000; color:white; padding:10px 20px; border:none; border-radius:8px;">Submit Review</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript for Search Clear + Notification Dropdown -->
<script>
// Show/hide X icon depending on input value
const searchInput = document.getElementById('searchInput');
const clearSearch = document.getElementById('clearSearch');

function toggleClearIcon() {
    clearSearch.style.display = searchInput.value.length > 0 ? 'inline' : 'none';
}
toggleClearIcon();
searchInput.addEventListener('input', toggleClearIcon);
clearSearch.addEventListener('click', function() {
    searchInput.value = '';
    toggleClearIcon();
    window.location.href = 'dashboard.php';
});

// Notification dropdown toggle
const bell = document.querySelector('.notification');
const dropdown = document.getElementById('notifDropdown');
bell.addEventListener('click', () => {
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', (e) => {
    if (!bell.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});
</script>

</body>
</html>
