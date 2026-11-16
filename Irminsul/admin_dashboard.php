<?php
require_once 'config/db.php';
require_once 'services/CatalogueService.php';
require_once 'services/AdminService.php';

// Role-Based Access Control (RBAC): Only staff roles can enter
$staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
if (!isset($_SESSION['member_id']) || !in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    header("Location: index.php");
    exit;
}

$feedback = '';
$feedbackSuccess = false;

// Handle Staff Form Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_book') {
        // Module 0.2.2: Add Book
        $res = addBook($pdo, $_POST);
        $feedback = $res['message'];
        $feedbackSuccess = $res['success'];
    } elseif ($action === 'delete_book') {
        // Module 0.2.3: Flag Book Deleted (Soft-Delete)
        $res = softDeleteBook($pdo, (int)($_POST['book_id'] ?? 0));
        $feedback = $res['message'];
        $feedbackSuccess = $res['success'];
    } elseif ($action === 'update_member_status') {
        // Module 0.1.3 / 0.3.4: Activate or Lock Member
        $res = updateMemberStatus($pdo, (int)($_POST['member_id'] ?? 0), $_POST['new_status'] ?? '');
        $feedback = $res['message'];
        $feedbackSuccess = $res['success'];
    } elseif ($action === 'renew_member') {
        // Module 0.4.3: Renew Membership & Record Transaction
        $res = renewMemberAccount($pdo, (int)($_POST['member_id'] ?? 0), 500.00, $_POST['payment_method'] ?? 'Cash');
        $feedback = $res['message'];
        $feedbackSuccess = $res['success'];
    }
}

// Fetch live dashboard datasets from D1, D2, D3, D4
$allBooks        = getAllBooksAdmin($pdo);
$allMembers      = getAllMembers($pdo);
$activeLoans     = getActiveAndOverdueLoans($pdo);
$recentTxns      = getRecentTransactions($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard | Open Library</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: #fff; padding: 18px; border-radius: 6px; border-left: 5px solid #0376b8; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card h3 { font-size: 1.8rem; color: #2b3a4a; }
        .stat-card p { color: #666; font-size: 0.9rem; }
        .admin-section { background: #fff; padding: 22px; border-radius: 6px; margin-bottom: 28px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .admin-section h2 { margin-bottom: 15px; color: #2b3a4a; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .grid-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .admin-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .admin-table th, .admin-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
        .admin-table th { background: #f4f1ea; color: #2b3a4a; }
        .btn-sm { padding: 5px 10px; font-size: 0.82rem; border-radius: 4px; border: none; cursor: pointer; color: #fff; font-weight: 600; }
        .btn-danger { background: #dc3545; }
        .btn-success { background: #28a745; }
        .btn-warning { background: #e0a800; color: #222; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="brand">Open Library &mdash; Staff Portal</div>
    <div>
        <a href="index.php">Public Catalog</a>
        <a href="my_loans.php">My Loans</a>
        <a href="admin_dashboard.php" style="text-decoration: underline;">Staff Dashboard</a>
        <span style="margin-left: 20px;"><strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong> (<?= htmlspecialchars($_SESSION['role']) ?>)</span>
        <a href="index.php?action=logout">Logout</a>
    </div>
</nav>

<main class="container">

    <?php if ($feedback): ?>
        <div style="padding: 12px 18px; margin-bottom: 20px; border-radius: 5px; font-weight: bold;
                    background: <?= $feedbackSuccess ? '#d4edda' : '#f8d7da' ?>;
                    color: <?= $feedbackSuccess ? '#155724' : '#721c24' ?>;">
            <?= htmlspecialchars($feedback) ?>
        </div>
    <?php endif; ?>

    <!-- Quick KPI Summary -->
    <div class="stats-row">
        <div class="stat-card">
            <h3><?= count($allBooks) ?></h3>
            <p>Active Catalog Titles (D2)</p>
        </div>
        <div class="stat-card" style="border-left-color: #28a745;">
            <h3><?= count($allMembers) ?></h3>
            <p>Registered Users (D1)</p>
        </div>
        <div class="stat-card" style="border-left-color: #e0a800;">
            <h3><?= count($activeLoans) ?></h3>
            <p>Books Currently Issued (D3)</p>
        </div>
        <div class="stat-card" style="border-left-color: #6f42c1;">
            <h3><?= count($recentTxns) ?></h3>
            <p>Recorded Payments (D4)</p>
        </div>
    </div>

    <!-- SECTION 1: Module 0.2.2 Add Book to Catalog -->
    <section class="admin-section">
        <h2>1. Add New Book to Catalog (Module 0.2.2)</h2>
        <form method="POST" action="admin_dashboard.php" class="grid-form">
            <input type="hidden" name="action" value="add_book">
            <div class="form-group">
                <label>ISBN (10 or 13 digits) *</label>
                <input type="text" name="isbn" placeholder="e.g. 9780141439518" required>
            </div>
            <div class="form-group">
                <label>Book Title *</label>
                <input type="text" name="title" placeholder="e.g. Pride and Prejudice" required>
            </div>
            <div class="form-group">
                <label>Author *</label>
                <input type="text" name="author" placeholder="e.g. Jane Austen" required>
            </div>
            <div class="form-group">
                <label>Publisher</label>
                <input type="text" name="publisher" placeholder="e.g. Penguin Classics">
            </div>
            <div class="form-group">
                <label>Genre / Subject</label>
                <input type="text" name="genre_category" placeholder="e.g. Classic Literature">
            </div>
            <div class="form-group">
                <label>Publication Year</label>
                <input type="number" name="publication_year" placeholder="1813">
            </div>
            <div class="form-group">
                <label>Rack Location</label>
                <input type="text" name="rack_location" placeholder="e.g. B-04">
            </div>
            <div class="form-group">
                <label>Total Copies *</label>
                <input type="number" name="total_copies" value="3" min="1" required>
            </div>
            <div class="form-group" style="display:flex; align-items:flex-end;">
                <button type="submit" class="btn" style="width:100%;">+ Add Book to Shelf</button>
            </div>
        </form>
    </section>

    <!-- SECTION 2: Module 0.2.3 Manage Catalog & Soft-Delete -->
    <section class="admin-section">
        <h2>2. Catalog Inventory & Soft-Delete (Module 0.2.3)</h2>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ISBN</th>
                    <th>Title & Author</th>
                    <th>Genre</th>
                    <th>Rack</th>
                    <th>Availability</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allBooks as $b): ?>
                    <tr>
                        <td>#<?= $b['book_id'] ?></td>
                        <td><?= htmlspecialchars($b['isbn']) ?></td>
                        <td><strong><?= htmlspecialchars($b['title']) ?></strong><br><small><?= htmlspecialchars($b['author']) ?></small></td>
                        <td><?= htmlspecialchars($b['genre_category']) ?></td>
                        <td><?= htmlspecialchars($b['rack_location']) ?></td>
                        <td><strong><?= $b['available_copies'] ?></strong> / <?= $b['total_copies'] ?></td>
                        <td>
                            <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Flag this book as deleted? It will be hidden from the public catalog.');">
                                <input type="hidden" name="action" value="delete_book">
                                <input type="hidden" name="book_id" value="<?= $b['book_id'] ?>">
                                <button type="submit" class="btn-sm btn-danger">Remove (Soft-Delete)</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- SECTION 3: Module 0.1.3 & 0.4 Member Accounts & Renewals -->
    <section class="admin-section">
        <h2>3. Member Accounts, Status & Renewals (Modules 0.1.3 & 0.4)</h2>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Member</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Membership Expiry</th>
                    <th>Account Control & Renewal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allMembers as $m): ?>
                    <tr>
                        <td>#<?= $m['member_id'] ?></td>
                        <td><strong><?= htmlspecialchars($m['full_name']) ?></strong><br><small><?= htmlspecialchars($m['email']) ?></small></td>
                        <td><?= htmlspecialchars($m['role']) ?></td>
                        <td>
                            <span class="badge <?= $m['account_status'] === 'Active' ? 'available' : 'unavailable' ?>">
                                <?= htmlspecialchars($m['account_status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($m['expiry_date']) ?></td>
                        <td style="display:flex; gap:6px; flex-wrap:wrap;">
                            <?php if ($m['account_status'] !== 'Active'): ?>
                                <form method="POST" action="admin_dashboard.php">
                                    <input type="hidden" name="action" value="update_member_status">
                                    <input type="hidden" name="member_id" value="<?= $m['member_id'] ?>">
                                    <input type="hidden" name="new_status" value="Active">
                                    <button type="submit" class="btn-sm btn-success">Activate</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($m['account_status'] === 'Active' && $m['role'] === 'Member'): ?>
                                <form method="POST" action="admin_dashboard.php">
                                    <input type="hidden" name="action" value="update_member_status">
                                    <input type="hidden" name="member_id" value="<?= $m['member_id'] ?>">
                                    <input type="hidden" name="new_status" value="Locked">
                                    <button type="submit" class="btn-sm btn-warning">Lock Account</button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" action="admin_dashboard.php">
                                <input type="hidden" name="action" value="renew_member">
                                <input type="hidden" name="member_id" value="<?= $m['member_id'] ?>">
                                <input type="hidden" name="payment_method" value="Cash">
                                <button type="submit" class="btn-sm" style="background:#0376b8;">Renew +1 Yr (₹500)</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- SECTION 4: Module 0.3.5 Active & Overdue Circulation Monitor -->
    <section class="admin-section">
        <h2>4. Active & Overdue Borrowings Monitor (Module 0.3.5)</h2>
        <?php if (empty($activeLoans)): ?>
            <p style="color:#666;">No books are currently checked out.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Loan ID</th>
                        <th>Member</th>
                        <th>Book</th>
                        <th>Issued</th>
                        <th>Due Date</th>
                        <th>Overdue / Fine</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeLoans as $loan): ?>
                        <?php $od = (int)$loan['overdue_days']; ?>
                        <tr>
                            <td>#<?= $loan['borrowing_id'] ?></td>
                            <td><?= htmlspecialchars($loan['full_name']) ?><br><small><?= htmlspecialchars($loan['email']) ?></small></td>
                            <td><strong><?= htmlspecialchars($loan['title']) ?></strong></td>
                            <td><?= htmlspecialchars($loan['issue_date']) ?></td>
                            <td><?= htmlspecialchars($loan['due_date']) ?></td>
                            <td>
                                <?php if ($od > 0): ?>
                                    <span class="badge unavailable"><?= $od ?> days overdue (₹<?= $od * 10 ?>)</span>
                                <?php else: ?>
                                    <span class="badge available">On Schedule</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

</main>

</body>
</html>