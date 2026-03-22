<?php
include "config.php"; // assumes session_start() is inside

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch current user info
$user_id = $_SESSION['user_id'];
$query = "SELECT id, fullname, role FROM users WHERE id='$user_id' LIMIT 1";
$result = mysqli_query($conn, $query);
if ($result && mysqli_num_rows($result) > 0) {
    $current_user = mysqli_fetch_assoc($result);
} else {
    header("Location: logout.php");
    exit();
}

// Only allow admins
if ($current_user['role'] != 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle bulk actions
if (isset($_POST['bulk_approve'])) {
    if (!empty($_POST['selected_reviews'])) {
        foreach ($_POST['selected_reviews'] as $review_id) {
            $review_id = intval($review_id);
            mysqli_query($conn, "UPDATE reviews SET status='approved' WHERE id='$review_id'");
            
            // Insert notification
            $res = mysqli_query($conn, "SELECT user_id, faculty_id, review_text FROM reviews WHERE id='$review_id' LIMIT 1");
            if ($res && mysqli_num_rows($res) > 0) {
                $review = mysqli_fetch_assoc($res);
                $target_user = $review['user_id'];
                $faculty_id = $review['faculty_id'];
                $review_text = $review['review_text'];
                
                $faculty_res = mysqli_query($conn, "SELECT name FROM faculties WHERE id='$faculty_id' LIMIT 1");
                $faculty_name = ($faculty_res && mysqli_num_rows($faculty_res) > 0) ? mysqli_fetch_assoc($faculty_res)['name'] : 'Unknown Faculty';
                
                $short_review = strlen($review_text) > 100 ? substr($review_text, 0, 100) . '...' : $review_text;
                $message = "Your review: \"$short_review\" for $faculty_name has been approved.";
                mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) 
                             VALUES ('$target_user', '$message', 'unread', NOW())");
            }
        }
    }
    header("Location: admin_dashboard.php#pending");
    exit();
}

if (isset($_POST['bulk_reject'])) {
    if (!empty($_POST['selected_reviews'])) {
        foreach ($_POST['selected_reviews'] as $review_id) {
            $review_id = intval($review_id);
            mysqli_query($conn, "UPDATE reviews SET status='rejected' WHERE id='$review_id'");
        }
    }
    header("Location: admin_dashboard.php#pending");
    exit();
}

// Handle bulk delete users
if (isset($_POST['bulk_delete_users'])) {
    if (!empty($_POST['selected_users'])) {
        foreach ($_POST['selected_users'] as $user_id_del) {
            $user_id_del = intval($user_id_del);
            if ($user_id_del != $user_id) {
                mysqli_query($conn, "DELETE FROM users WHERE id='$user_id_del' AND role='user'");
            }
        }
    }
    header("Location: admin_dashboard.php#users");
    exit();
}

// Fetch users
$users = mysqli_query($conn, "SELECT id, fullname, username, email FROM users WHERE role='user'");

// Fetch faculties
$faculties = mysqli_query($conn, "SELECT * FROM faculties");

// Fetch report data
$total_users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users"))['count'];
$total_faculties = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM faculties"))['count'];
$total_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews"))['count'];
$pending_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='pending'"))['count'];
$approved_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='approved'"))['count'];
$rejected_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='rejected'"))['count'];

// Fetch report data
$total_users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users"))['count'];
$total_admins = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role='admin'"))['count'];
$total_faculties = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM faculties"))['count'];
$total_reviews = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews"))['count'];
$pending_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='pending'"))['count'];
$approved_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='approved'"))['count'];
$rejected_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM reviews WHERE status='rejected'"))['count'];

// Fetch pending reviews
$pending_reviews = mysqli_query($conn, "
    SELECT r.id, u.username AS user_name, f.name AS faculty_name, r.review_text,
           r.sentiment, r.is_toxic, r.summary
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    JOIN faculties f ON r.faculty_id = f.id
    WHERE r.status='pending'
");


// Fetch approved reviews
$approved_reviews = mysqli_query($conn, "
    SELECT r.id, f.name AS faculty_name, u.username AS user_name, r.review_text
    FROM reviews r
    JOIN faculties f ON r.faculty_id = f.id
    JOIN users u ON r.user_id = u.id
    WHERE r.status='approved'
    ORDER BY r.created_at DESC
");

?>

<!DOCTYPE html>
<html>
<head>
<title>Admin Dashboard - AnonymousReview</title>
<style>
body { margin:0; font-family:Arial, sans-serif; background:#f5f5f5; }
.sidebar { position:fixed; left:0; top:0; width:220px; height:100%; background: maroon; color:white; display:flex; flex-direction:column; align-items:center; padding-top:20px; }
.sidebar img { width:80px; height:80px; border-radius:50%; margin-bottom:10px; }
.sidebar h2 { margin-bottom:30px; text-align:center; font-size:18px; }
.sidebar a { width:90%; padding:12px; margin:5px 0; text-decoration:none; color:white; display:block; border-radius:8px; transition:0.2s; }
.sidebar a:hover { background: white; color: maroon; }
.main-content { margin-left:220px; padding:30px; }
table { width:100%; border-collapse: collapse; margin-bottom:30px; }
table, th, td { border:1px solid #ddd; }
th, td { padding:10px; text-align:left; }
th { background:#8B0000; color:white; }
a.button { text-decoration:none; background:#8B0000; color:white; padding:5px 10px; border-radius:5px; cursor:pointer; }
a.button:hover { background:#a30000; }
button.button { background:#8B0000; color:white; padding:5px 10px; border-radius:5px; cursor:pointer; border:none; }
button.button:hover { background:#a30000; }
h1 { color:#8B0000; margin-bottom:15px; }

/* Modal styles */
.modal {
  display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%;
  background:rgba(0,0,0,0.6); justify-content:center; align-items:center;
}
.modal-content {
  background:#fff; padding:20px; border-radius:8px; max-width:600px; width:80%;
}
.modal-header { font-weight:bold; margin-bottom:10px; }
.close { float:right; cursor:pointer; color:red; font-weight:bold; }
</style>
<script>
function toggleFacultyActions(id) {
    var row = document.getElementById("faculty-actions-" + id);
    if (row.style.display === "none") {
        row.style.display = "table-row";
    } else {
        row.style.display = "none";
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('edit_users_btn').addEventListener('click', function() {
        var checkboxTh = document.getElementById('select_all_th');
        var checkboxTds = document.querySelectorAll('.checkbox_td');
        if (checkboxTh.style.display === 'none') {
            checkboxTh.style.display = 'table-cell';
            checkboxTds.forEach(function(td) {
                td.style.display = 'table-cell';
            });
            this.textContent = 'Cancel';
        } else {
            checkboxTh.style.display = 'none';
            checkboxTds.forEach(function(td) {
                td.style.display = 'none';
            });
            this.textContent = 'Edit';
            // Uncheck all
            document.querySelectorAll('.user_checkbox').forEach(function(cb) {
                cb.checked = false;
            });
            document.getElementById('select_all_users').checked = false;
            toggleBulkActions();
        }
    });

    // Select all functionality
    document.getElementById('select_all_users').addEventListener('change', function() {
        var checkboxes = document.querySelectorAll('.user_checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = this.checked;
        });
        toggleBulkActions();
    });

    // Toggle bulk actions
    function toggleBulkActions() {
        var checkboxes = document.querySelectorAll('.user_checkbox:checked');
        var bulkActions = document.getElementById('bulk_actions');
        if (checkboxes.length > 0) {
            bulkActions.style.display = 'block';
        } else {
            bulkActions.style.display = 'none';
        }
    }

    // Add change listeners to individual checkboxes
    document.querySelectorAll('.user_checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', toggleBulkActions);
    });
});
</script>
</head>
<body>

<div class="sidebar">
    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($current_user['fullname']); ?>&background=333&color=fff&size=80" alt="Admin Avatar">
    <h2><?php echo htmlspecialchars($current_user['fullname']); ?></h2>
    <a href="#users">User List</a>
    <a href="#faculties">Faculty List</a>
    <a href="#pending">Pending Reviews</a>
    <a href="#approved">Approved Reviews</a>
    <a href="#reports">Reports</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main-content">
    <h1 id="users">User List</h1>
    <div style="position:relative;">
        <button id="edit_users_btn" class="button" style="position:absolute; top:-40px; right:0;">Edit</button>
    </div>
    <form method="POST">
    <table>
        <tr><th style="display:none;" id="select_all_th"><input type="checkbox" id="select_all_users"></th><th>ID</th><th>Full Name</th><th>Username</th><th>Email</th></tr>
        <?php while($user = mysqli_fetch_assoc($users)): ?>
        <tr>
            <td style="display:none;" class="checkbox_td"><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="user_checkbox"></td>
            <td><?php echo $user['id']; ?></td>
            <td><?php echo htmlspecialchars($user['fullname']); ?></td>
            <td><?php echo htmlspecialchars($user['username']); ?></td>
            <td><?php echo htmlspecialchars($user['email']); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
    <div id="bulk_actions" style="display:none; margin-top:10px;">
        <button type="submit" name="bulk_delete_users" class="button">Delete Selected</button>
    </div>
    </form>

    <h1 id="faculties">Faculty List</h1>
<table>
    <tr><th>ID</th><th>Name</th><th>Action</th></tr>
    <?php while($faculty = mysqli_fetch_assoc($faculties)): ?>
    <tr>
        <td><?php echo $faculty['id']; ?></td>
        <td><?php echo htmlspecialchars($faculty['name']); ?></td>
        <td>
            <a class="button" onclick="toggleFacultyActions(<?php echo $faculty['id']; ?>)">Edit</a>
        </td>
    </tr>
    <tr id="faculty-actions-<?php echo $faculty['id']; ?>" style="display:none;">
        <td colspan="3">
            <a href="edit_faculty.php?id=<?php echo $faculty['id']; ?>" class="button">Edit Faculty</a>
            <a href="delete_faculty.php?id=<?php echo $faculty['id']; ?>" class="button" onclick="return confirm('Delete this faculty?')">Delete Faculty</a>
        </td>
    </tr>
    <?php endwhile; ?>
    <tr>
        <td colspan="3" style="text-align:center;">
            <a href="add_faculty.php" class="button">Add Faculty</a>
        </td>
    </tr>
</table>


    <h1 id="pending">Pending Reviews</h1>
    <table>
        <tr><th>ID</th><th>User</th><th>Faculty</th><th>AI Analysis</th><th>Action</th></tr>
<?php while($review = mysqli_fetch_assoc($pending_reviews)): ?>
<tr>
    <td><?php echo $review['id']; ?></td>
    <td><?php echo htmlspecialchars($review['user_name']); ?></td>
    <td><?php echo htmlspecialchars($review['faculty_name']); ?></td>
    <td>
        <?php
        $sentiment = $review['sentiment'] ?? 'neutral';
        $is_toxic = $review['is_toxic'];
        $summary = $review['summary'] ?? '';

        $badge = match($sentiment) {
            'positive' => '<span style="background:#28a745;color:white;padding:3px 8px;border-radius:12px;font-size:12px;">🟢 Positive</span>',
            'negative' => '<span style="background:#dc3545;color:white;padding:3px 8px;border-radius:12px;font-size:12px;">🔴 Negative</span>',
            default    => '<span style="background:#6c757d;color:white;padding:3px 8px;border-radius:12px;font-size:12px;">⚪ Neutral</span>',
        };

        $toxic_badge = $is_toxic 
            ? '<span style="background:#ff6600;color:white;padding:3px 8px;border-radius:12px;font-size:12px;margin-left:5px;">🚩 Toxic</span>' 
            : '';

        echo $badge . $toxic_badge;
        echo $summary ? '<br><small style="color:#666;font-style:italic;">' . htmlspecialchars($summary) . '</small>' : '';
        ?>
    </td>
    <td>
        <a class="button" onclick="openModal('<?php echo htmlspecialchars(addslashes($review['review_text'])); ?>')">View</a>
        <a href="approve_review.php?id=<?php echo $review['id']; ?>" class="button">Approve</a>
        <a href="reject_review.php?id=<?php echo $review['id']; ?>" class="button">Reject</a>
    </td>
</tr>
        <?php endwhile; ?>
    </table>

    <h1 id="approved">Approved Reviews</h1>
    <table>
        <tr><th>ID</th><th>Faculty</th><th>User</th><th>Action</th></tr>
        <?php while($review = mysqli_fetch_assoc($approved_reviews)): ?>
        <tr>
            <td><?php echo $review['id']; ?></td>
            <td><?php echo htmlspecialchars($review['faculty_name']); ?></td>
            <td><?php echo htmlspecialchars($review['user_name']); ?></td>
            <td>
                <a class="button" onclick="openModal('<?php echo htmlspecialchars(addslashes($review['review_text'])); ?>')">View</a>
                <a href="reject_review.php?id=<?php echo $review['id']; ?>" class="button">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <h1 id="reports">System Reports</h1>
    <div style="display: flex; flex-wrap: wrap; gap: 20px;">
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Total Users</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $total_users; ?></p>
        </div>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Total Admins</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $total_admins; ?></p>
        </div>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Total Faculties</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $total_faculties; ?></p>
        </div>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Total Reviews</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $total_reviews; ?></p>
        </div>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Pending Reviews</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $pending_count; ?></p>
        </div>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Approved Reviews</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $approved_count; ?></p>
        </div>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 200px;">
            <h3 style="color: #8B0000; margin-top: 0;">Rejected Reviews</h3>
            <p style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $rejected_count; ?></p>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="reviewModal" class="modal">
  <div class="modal-content">
    <span class="close" onclick="closeModal()">&times;</span>
    <div class="modal-header">Review Message</div>
    <div id="modalBody"></div>
  </div>
</div>

<script>
function openModal(text) {
    document.getElementById('modalBody').innerText = text;
    document.getElementById('reviewModal').style.display = 'flex';
}
function closeModal() {
    document.getElementById('reviewModal').style.display = 'none';
}
window.onclick = function(event) {
    if (event.target == document.getElementById('reviewModal')) {
        closeModal();
    }
}
</script>

</body>
</html>
