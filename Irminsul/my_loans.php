<?php
require_once 'config/db.php';
require_once 'services/CirculationService.php';

// Redirect guests to login page
if (!isset($_SESSION['member_id'])) {
    header("Location: auth.php");
    exit;
}

$memberId = (int)$_SESSION['member_id'];
$feedback = '';
$feedbackType = 'info';

// Handle Return Book button click (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return') {
    $borrowingId = (int)($_POST['borrowing_id'] ?? 0);
    $result = returnBook($pdo, $borrowingId, $memberId);
    $feedback = $result['message'];
    $feedbackType = $result['success'] ? 'success' : 'error';
}

// Fetch all borrowed & returned books for this member (Module 0.3.2)
$loans = getMemberBorrowings($pdo, $memberId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Loans | Open Library</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .alert { padding: 12px 20px; border-radius: 5px; margin-bottom: 20px; font-weight: 600; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .loan-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .loan-table th, .loan-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #eee; }
        .loan-table th { background: #2b3a4a; color: #fff; }
        .mini-cover { width: 42px; height: 60px; object-fit: cover; border-radius: 3px; vertical-align: middle; margin-right: 10px; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="brand">Open Library</div>
    <div>
        <a href="index.php">Browse Books</a>
        <a href="my_loans.php" style="text-decoration: underline;">My Loans</a>
        <span style="margin-left: 20px;">Hello, <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong></span>
        <a href="index.php?action=logout">Logout</a>
    </div>
</nav>

<main class="container">
    <h2 style="margin-bottom: 15px;">My Borrowed Books & History</h2>

    <?php if ($feedback): ?>
        <div class="alert <?= $feedbackType === 'success' ? 'alert-success' : 'alert-error' ?>">
            <?= htmlspecialchars($feedback) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($loans)): ?>
        <div class="card-box" style="max-width: 600px; text-align: center;">
            <p>You haven't borrowed any books yet.</p>
            <a href="index.php" class="btn" style="margin-top: 15px;">Explore the Catalog</a>
        </div>
    <?php else: ?>
        <table class="loan-table">
            <thead>
                <tr>
                    <th>Book</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Fine</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loans as $loan): ?>
                    <?php 
                        $isIssued = ($loan['loan_status'] === 'Issued');
                        $overdueDays = (int)$loan['overdue_days'];
                        $liveFine = $isIssued ? ($overdueDays * 10) : (float)$loan['fine_amount'];
                    ?>
                    <tr>
                        <td>
                            <img class="mini-cover" src="https://covers.openlibrary.org/b/isbn/<?= htmlspecialchars($loan['isbn']) ?>-S.jpg" alt="Cover">
                            <strong><?= htmlspecialchars($loan['title']) ?></strong><br>
                            <small style="color:#666; margin-left: 52px;">by <?= htmlspecialchars($loan['author']) ?> (Rack: <?= htmlspecialchars($loan['rack_location']) ?>)</small>
                        </td>
                        <td><?= htmlspecialchars($loan['issue_date']) ?></td>
                        <td>
                            <?= htmlspecialchars($loan['due_date']) ?>
                            <?php if ($isIssued && $overdueDays > 0): ?>
                                <br><small style="color:#dc3545; font-weight:bold;">Overdue by <?= $overdueDays ?> days</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isIssued): ?>
                                <span class="badge unavailable" style="background:#fff3cd; color:#856404;">Checked Out</span>
                            <?php else: ?>
                                <span class="badge available">Returned (<?= htmlspecialchars($loan['return_date']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td>₹<?= number_format($liveFine, 2) ?></td>
                        <td>
                            <?php if ($isIssued): ?>
                                <form method="POST" action="my_loans.php" style="margin:0;">
                                    <input type="hidden" name="action" value="return">
                                    <input type="hidden" name="borrowing_id" value="<?= $loan['borrowing_id'] ?>">
                                    <button type="submit" class="btn" style="background:#28a745; padding: 7px 12px; font-size: 0.85rem;">Return Book</button>
                                </form>
                            <?php else: ?>
                                <span style="color:#888; font-size:0.85rem;">Completed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>

</body>
</html>