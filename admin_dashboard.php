<?php
include "config.php";

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$result  = mysqli_query($conn, "SELECT id, fullname, role FROM users WHERE id='$user_id' LIMIT 1");
if ($result && mysqli_num_rows($result) > 0) {
    $current_user = mysqli_fetch_assoc($result);
} else { header("Location: logout.php"); exit(); }

if ($current_user['role'] != 'admin') { header("Location: dashboard.php"); exit(); }

// ── Bulk Approve ──────────────────────────────────────────────────────────────
if (isset($_POST['bulk_approve']) && !empty($_POST['selected_reviews'])) {
    foreach ($_POST['selected_reviews'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "UPDATE reviews SET status='approved' WHERE id='$rid'");

        $res = mysqli_query($conn, "SELECT user_id, faculty_id FROM reviews WHERE id='$rid' LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $rev         = mysqli_fetch_assoc($res);
            $target      = $rev['user_id'];
            $fres        = mysqli_query($conn, "SELECT name FROM faculties WHERE id='{$rev['faculty_id']}' LIMIT 1");
            $fname       = ($fres && mysqli_num_rows($fres) > 0) ? mysqli_fetch_assoc($fres)['name'] : 'Unknown Faculty';
            $msg         = mysqli_real_escape_string($conn, "Your review for $fname has been approved and is now published.");
            mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$target','$msg','unread',NOW())");
        }
    }
    header("Location: admin_dashboard.php#pending"); exit();
}

// ── Bulk Reject ───────────────────────────────────────────────────────────────
if (isset($_POST['bulk_reject']) && !empty($_POST['selected_reviews'])) {
    foreach ($_POST['selected_reviews'] as $rid) {
        $rid = intval($rid);
        mysqli_query($conn, "UPDATE reviews SET status='rejected' WHERE id='$rid'");

        $res = mysqli_query($conn, "SELECT user_id, faculty_id FROM reviews WHERE id='$rid' LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            $rev         = mysqli_fetch_assoc($res);
            $target      = $rev['user_id'];
            $fres        = mysqli_query($conn, "SELECT name FROM faculties WHERE id='{$rev['faculty_id']}' LIMIT 1");
            $fname       = ($fres && mysqli_num_rows($fres) > 0) ? mysqli_fetch_assoc($fres)['name'] : 'Unknown Faculty';
            $msg         = mysqli_real_escape_string($conn, "Your review for $fname has been rejected by the admin.");
            mysqli_query($conn, "INSERT INTO notifications (user_id, message, status, created_at) VALUES ('$target','$msg','unread',NOW())");
        }
    }
    header("Location: admin_dashboard.php#pending"); exit();
}

// ── Bulk Delete Users ─────────────────────────────────────────────────────────
if (isset($_POST['bulk_delete_users']) && !empty($_POST['selected_users'])) {
    foreach ($_POST['selected_users'] as $uid) {
        $uid = intval($uid);
        if ($uid != $user_id) mysqli_query($conn, "DELETE FROM users WHERE id='$uid' AND role='user'");
    }
    header("Location: admin_dashboard.php#users"); exit();
}

// ── Data ──────────────────────────────────────────────────────────────────────
$users            = mysqli_query($conn, "SELECT id, fullname, username, email FROM users WHERE role='user'");
$faculties        = mysqli_query($conn, "SELECT * FROM faculties");
$total_users      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='user'"))['c'];
$total_admins     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='admin'"))['c'];
$total_faculties  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM faculties"))['c'];
$total_reviews    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews"))['c'];
$pending_count    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='pending'"))['c'];
$approved_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='approved'"))['c'];
$rejected_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM reviews WHERE status='rejected'"))['c'];

$pending_reviews  = mysqli_query($conn, "
    SELECT r.id, u.username AS user_name, f.name AS faculty_name, r.review_text, r.sentiment, r.is_toxic, r.summary
    FROM reviews r JOIN users u ON r.user_id=u.id JOIN faculties f ON r.faculty_id=f.id
    WHERE r.status='pending'");

$approved_reviews = mysqli_query($conn, "
    SELECT r.id, f.name AS faculty_name, u.username AS user_name, r.review_text
    FROM reviews r JOIN faculties f ON r.faculty_id=f.id JOIN users u ON r.user_id=u.id
    WHERE r.status='approved' ORDER BY r.created_at DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Dashboard - AnonymousReview</title>
<style>
body{margin:0;font-family:Arial,sans-serif;background:#f5f5f5;}
.sidebar{position:fixed;left:0;top:0;width:220px;height:100%;background:maroon;color:white;display:flex;flex-direction:column;align-items:center;padding-top:20px;}
.sidebar img{width:80px;height:80px;border-radius:50%;margin-bottom:10px;}
.sidebar h2{margin-bottom:30px;text-align:center;font-size:18px;}
.sidebar a{width:90%;padding:12px;margin:5px 0;text-decoration:none;color:white;display:block;border-radius:8px;transition:0.2s;}
.sidebar a:hover{background:white;color:maroon;}
.main-content{margin-left:220px;padding:30px;}
table{width:100%;border-collapse:collapse;margin-bottom:30px;}
table,th,td{border:1px solid #ddd;}
th,td{padding:10px;text-align:left;}
th{background:#8B0000;color:white;}
a.button{text-decoration:none;background:#8B0000;color:white;padding:5px 10px;border-radius:5px;cursor:pointer;display:inline-block;}
a.button:hover{background:#a30000;}
button.button{background:#8B0000;color:white;padding:5px 10px;border-radius:5px;cursor:pointer;border:none;}
button.button:hover{background:#a30000;}
h1{color:#8B0000;margin-bottom:15px;}
.badge{display:inline-block;padding:3px 8px;border-radius:12px;font-size:12px;color:white;}
.badge-positive{background:#28a745;} .badge-negative{background:#dc3545;} .badge-neutral{background:#6c757d;} .badge-toxic{background:#ff6600;}
.modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);justify-content:center;align-items:center;}
.modal-content{background:#fff;padding:20px;border-radius:8px;max-width:600px;width:80%;}
.modal-header{font-weight:bold;margin-bottom:10px;}
.close{float:right;cursor:pointer;color:red;font-weight:bold;}
</style>
</head>
<body>

<div class="sidebar">
    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($current_user['fullname']); ?>&background=333&color=fff&size=80" alt="Admin">
    <h2><?php echo htmlspecialchars($current_user['fullname']); ?></h2>
    <a href="#users">User List</a>
    <a href="#faculties">Faculty List</a>
    <a href="#pending">Pending Reviews</a>
    <a href="#approved">Approved Reviews</a>
    <a href="#reports">Reports</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main-content">

    <!-- Users -->
    <h1 id="users">User List</h1>
    <div style="position:relative;">
        <button id="edit_users_btn" class="button" style="position:absolute;top:-40px;right:0;">Edit</button>
    </div>
    <form method="POST">
    <table>
        <tr>
            <th style="display:none;" id="select_all_th"><input type="checkbox" id="select_all_users"></th>
            <th>ID</th><th>Full Name</th><th>Username</th><th>Email</th>
        </tr>
        <?php while($u = mysqli_fetch_assoc($users)): ?>
        <tr>
            <td style="display:none;" class="checkbox_td"><input type="checkbox" name="selected_users[]" value="<?php echo $u['id']; ?>" class="user_checkbox"></td>
            <td><?php echo $u['id']; ?></td>
            <td><?php echo htmlspecialchars($u['fullname']); ?></td>
            <td><?php echo htmlspecialchars($u['username']); ?></td>
            <td><?php echo htmlspecialchars($u['email']); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
    <div id="bulk_actions" style="display:none;margin-top:10px;">
        <button type="submit" name="bulk_delete_users" class="button" onclick="return confirm('Delete selected users?')">Delete Selected</button>
    </div>
    </form>

    <!-- Faculties -->
    <h1 id="faculties">Faculty List</h1>
    <table>
        <tr><th>ID</th><th>Name</th><th>Action</th></tr>
        <?php while($f = mysqli_fetch_assoc($faculties)): ?>
        <tr>
            <td><?php echo $f['id']; ?></td>
            <td><?php echo htmlspecialchars($f['name']); ?></td>
            <td><a class="button" onclick="toggleFacultyActions(<?php echo $f['id']; ?>)">Edit</a></td>
        </tr>
        <tr id="faculty-actions-<?php echo $f['id']; ?>" style="display:none;">
            <td colspan="3">
                <a href="edit_faculty.php?id=<?php echo $f['id']; ?>" class="button">Edit Faculty</a>
                <a href="delete_faculty.php?id=<?php echo $f['id']; ?>" class="button" onclick="return confirm('Delete this faculty?')">Delete Faculty</a>
            </td>
        </tr>
        <?php endwhile; ?>
        <tr><td colspan="3" style="text-align:center;"><a href="add_faculty.php" class="button">Add Faculty</a></td></tr>
    </table>

    <!-- Pending Reviews -->
    <h1 id="pending">Pending Reviews</h1>
    <form method="POST">
    <table>
        <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.review_cb').forEach(c=>c.checked=this.checked)"></th>
            <th>User</th><th>Faculty</th><th>AI Analysis</th><th>Action</th>
        </tr>
        <?php while($r = mysqli_fetch_assoc($pending_reviews)): ?>
        <tr>
            <td><input type="checkbox" name="selected_reviews[]" value="<?php echo $r['id']; ?>" class="review_cb"></td>
            <td><?php echo htmlspecialchars($r['user_name']); ?></td>
            <td><?php echo htmlspecialchars($r['faculty_name']); ?></td>
            <td>
                <?php
                $s = $r['sentiment'] ?? 'neutral';
                $cls = $s === 'positive' ? 'badge-positive' : ($s === 'negative' ? 'badge-negative' : 'badge-neutral');
                echo "<span class='badge $cls'>".ucfirst($s)."</span>";
                if ($r['is_toxic']) echo "<span class='badge badge-toxic' style='margin-left:4px;'>Toxic</span>";
                if (!empty($r['summary'])) echo "<br><small style='color:#666;font-style:italic;'>".htmlspecialchars($r['summary'])."</small>";
                ?>
            </td>
            <td>
                <a class="button" onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>')">View</a>
                <a href="approve_review.php?id=<?php echo $r['id']; ?>" class="button">Approve</a>
                <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="button">Reject</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
    <div style="margin-bottom:30px;display:flex;gap:8px;">
        <button type="submit" name="bulk_approve" class="button" onclick="return confirm('Approve selected?')">Bulk Approve</button>
        <button type="submit" name="bulk_reject" class="button" style="background:#555;" onclick="return confirm('Reject selected?')">Bulk Reject</button>
    </div>
    </form>

    <!-- Approved Reviews -->
    <h1 id="approved">Approved Reviews</h1>
    <table>
        <tr><th>Faculty</th><th>User</th><th>Action</th></tr>
        <?php while($r = mysqli_fetch_assoc($approved_reviews)): ?>
        <tr>
            <td><?php echo htmlspecialchars($r['faculty_name']); ?></td>
            <td><?php echo htmlspecialchars($r['user_name']); ?></td>
            <td>
                <a class="button" onclick="openModal('<?php echo htmlspecialchars(addslashes($r['review_text'])); ?>')">View</a>
                <a href="reject_review.php?id=<?php echo $r['id']; ?>" class="button" onclick="return confirm('Delete this review?')">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <!-- Reports -->
    <h1 id="reports">System Reports</h1>
    <div style="display:flex;flex-wrap:wrap;gap:20px;">
        <?php foreach([['Total Users',$total_users],['Total Admins',$total_admins],['Total Faculties',$total_faculties],['Total Reviews',$total_reviews],['Pending',$pending_count],['Approved',$approved_count],['Rejected',$rejected_count]] as $c): ?>
        <div style="background:#f9f9f9;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1);min-width:160px;">
            <h3 style="color:#8B0000;margin-top:0;"><?php echo $c[0]; ?></h3>
            <p style="font-size:24px;font-weight:bold;color:#333;"><?php echo $c[1]; ?></p>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- Modal -->
<div id="reviewModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <div class="modal-header">Review Content</div>
        <div id="modalBody" style="margin-top:10px;line-height:1.6;white-space:pre-wrap;"></div>
    </div>
</div>

<script>
function toggleFacultyActions(id) {
    var row = document.getElementById("faculty-actions-" + id);
    row.style.display = row.style.display === "none" ? "table-row" : "none";
}
function openModal(text) {
    document.getElementById('modalBody').innerText = text;
    document.getElementById('reviewModal').style.display = 'flex';
}
function closeModal() { document.getElementById('reviewModal').style.display = 'none'; }
window.onclick = e => { if (e.target == document.getElementById('reviewModal')) closeModal(); }

document.addEventListener('DOMContentLoaded', function() {
    const editBtn = document.getElementById('edit_users_btn');
    editBtn.addEventListener('click', function() {
        const th  = document.getElementById('select_all_th');
        const tds = document.querySelectorAll('.checkbox_td');
        const showing = th.style.display !== 'none';
        th.style.display = showing ? 'none' : 'table-cell';
        tds.forEach(td => td.style.display = showing ? 'none' : 'table-cell');
        this.textContent = showing ? 'Edit' : 'Cancel';
        if (showing) {
            document.querySelectorAll('.user_checkbox').forEach(c => c.checked = false);
            document.getElementById('select_all_users').checked = false;
            toggleBulk();
        }
    });
    document.getElementById('select_all_users').addEventListener('change', function() {
        document.querySelectorAll('.user_checkbox').forEach(c => c.checked = this.checked);
        toggleBulk();
    });
    document.querySelectorAll('.user_checkbox').forEach(c => c.addEventListener('change', toggleBulk));
    function toggleBulk() {
        document.getElementById('bulk_actions').style.display =
            document.querySelectorAll('.user_checkbox:checked').length > 0 ? 'block' : 'none';
    }
});
</script>
</body>
</html>