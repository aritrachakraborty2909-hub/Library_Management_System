<?php
require_once 'config/db.php';
require_once 'services/LibrarianService.php';

// Role-Based Access Guard: Only Librarians allowed
if (!isset($_SESSION['member_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: login.php");
    exit;
}

$message = '';

// Handle Librarian Actions (0.1.3 Approval Decision or 0.3.3 Book Return Release)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_member' || $action === 'reject_member') {
        $decision = ($action === 'approve_member') ? 'Approve' : 'Reject';
        if (decideRegistration($pdo, (int)$_POST['member_id'], $decision)) {
            $message = "Member application " . strtolower($decision) . "d.";
        }
    } elseif ($action === 'return_book') {
        if (processBookReturn($pdo, (int)$_POST['borrowing_id'])) {
            $message = "Book return recorded and available copy count updated.";
        }
    }
}

$pendingMembers = getPendingApplications($pdo);
$activeLoans    = getActiveCirculation($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Librarian Desk | The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="topbar">
    <div><strong>THE READING ROOM</strong> &nbsp;/&nbsp; LIBRARIAN DESK</div>
    <div>
        <span>Signed in as <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong></span>
        &nbsp;|&nbsp; <a href="index.php" class="topbar-nav-link">Catalog</a>
        &nbsp;|&nbsp; <a href="index.php?action=logout" class="topbar-logout-link">Log out</a>
    </div>
</header>

<main class="container">
    <?php if ($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Module 0.1.3: Approve Registration -->
    <section class="panel">
        <h2>Pending Member Applications</h2>
        <?php if (empty($pendingMembers)): ?>
            <p>No pending registration applications right now.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Applied On</th>
                        <th>Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingMembers as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['full_name']) ?></td>
                            <td><?= htmlspecialchars($m['email']) ?></td>
                            <td><?= htmlspecialchars($m['phone_number'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(date('d M Y', strtotime($m['created_at']))) ?></td>
                            <td>
                                <form method="POST" class="form-inline">
                                    <input type="hidden" name="member_id" value="<?= $m['member_id'] ?>">
                                    <button class="btn" type="submit" name="action" value="approve_member">Approve</button>
                                    <button class="btn btn-reject" type="submit" name="action" value="reject_member">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- Module 0.3.3: Book Access Release (Returns) -->
    <section class="panel">
        <h2>Active Book Loans &amp; Returns</h2>
        <?php if (empty($activeLoans)): ?>
            <p>No active borrowed books at the moment.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Book Title</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeLoans as $loan): ?>
                        <tr>
                            <td><?= htmlspecialchars($loan['full_name']) ?></td>
                            <td><?= htmlspecialchars($loan['title']) ?></td>
                            <td><?= htmlspecialchars($loan['issue_date']) ?></td>
                            <td><?= htmlspecialchars($loan['due_date']) ?></td>
                            <td><strong><?= htmlspecialchars($loan['loan_status']) ?></strong></td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="borrowing_id" value="<?= $loan['borrowing_id'] ?>">
                                    <button class="btn" type="submit" name="action" value="return_book">Mark Returned</button>
                                </form>
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