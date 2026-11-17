<?php
// my_loans.php
// Module 0.3.2: View Active Loans, Live Overdue Fine Calculation (₹10/day), Loan History & Digital Reader Access
session_start();
require_once __DIR__ . '/config/db.php';

// 1. Verify Authentication
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$memberId = (int) $_SESSION['member_id'];
$role     = $_SESSION['role'] ?? 'Member';

// 2. Fetch Member Account Info from D1 (members)
$memberStmt = $pdo->prepare("
    SELECT full_name, email, role, account_status, expiry_date, max_borrowing_limit 
    FROM members 
    WHERE member_id = :member_id
    LIMIT 1
");
$memberStmt->execute([':member_id' => $memberId]);
$member = $memberStmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// 3. Fetch All Borrowings (D3) Joined with Books (D2) including pdf_path
$loansStmt = $pdo->prepare("
    SELECT 
        br.borrowing_id,
        br.book_id,
        br.issue_date,
        br.due_date,
        br.return_date,
        br.loan_status,
        br.fine_amount,
        bk.book_index,
        bk.isbn,
        bk.title,
        bk.author,
        bk.genre_category,
        bk.pdf_path
    FROM borrowings br
    JOIN books bk ON br.book_id = bk.book_id
    WHERE br.member_id = :member_id
    ORDER BY 
        CASE br.loan_status 
            WHEN 'Issued' THEN 1 
            WHEN 'Pending' THEN 2 
            ELSE 3 
        END,
        br.issue_date DESC,
        br.borrowing_id DESC
");
$loansStmt->execute([':member_id' => $memberId]);
$allLoans = $loansStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Separate Active/Pending Loans from Returned History & Calculate Live Fines (₹10/day)
$activeLoans     = [];
$pastLoans       = [];
$totalLiveFines  = 0.0;
$dailyFineRate   = 10; // ₹10 per overdue day

$today = new DateTimeImmutable('today');

foreach ($allLoans as $loan) {
    if ($loan['loan_status'] === 'Issued' || $loan['loan_status'] === 'Pending') {
        $dueDate      = new DateTimeImmutable(date('Y-m-d', strtotime($loan['due_date'])));
        $daysOverdue  = 0;
        $liveFine     = 0.0;

        if ($loan['loan_status'] === 'Issued' && $today > $dueDate) {
            $daysOverdue = (int) $dueDate->diff($today)->days;
            $liveFine    = $daysOverdue * $dailyFineRate;
            $totalLiveFines += $liveFine;
        }

        $loan['days_overdue'] = $daysOverdue;
        $loan['live_fine']    = $liveFine;
        $activeLoans[]        = $loan;
    } else {
        $pastLoans[] = $loan;
    }
}

$activeCount = count($activeLoans);
$maxLimit    = (int) ($member['max_borrowing_limit'] ?? 3);

// 5. Handle Status & Error Banners
$bannerMessage = '';
$bannerType    = '';

if (isset($_GET['borrowed']) && $_GET['borrowed'] === '1') {
    $bannerMessage = 'Book borrowed successfully! Your 14-day reading period has started.';
    $bannerType    = 'success';
} elseif (isset($_GET['error'])) {
    $bannerType = 'error';
    switch ($_GET['error']) {
        case 'no_active_loan':
            $bannerMessage = 'Access Denied: You must have an active issued loan for that book to open the digital reader.';
            break;
        case 'limit_reached':
            $bannerMessage = "Borrowing limit reached ({$maxLimit} books). Please return a book before borrowing another.";
            break;
        case 'account_locked':
            $bannerMessage = 'Your account is currently locked due to overdue items or pending verification.';
            break;
        default:
            $bannerMessage = 'Unable to complete your request. Please try again.';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loans & Digital Shelf — The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <nav class="top-nav">
        <a href="index.php" class="brand">The Reading Room</a>
        <div class="nav-links">
            <a href="index.php">Catalog Shelf</a>
            <a href="my_loans.php" class="active">My Loans (<?= $activeCount ?>/<?= $maxLimit ?>)</a>
            <?php if (in_array($role, ['Librarian', 'Cataloger', 'System Manager'], true)): ?>
                <a href="admin_dashboard.php">Staff Workbench</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </nav>

    <main class="container">

        <?php if ($bannerMessage !== ''): ?>
            <div class="alert-banner alert-<?= htmlspecialchars($bannerType) ?>">
                <?= htmlspecialchars($bannerMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Member Account & Borrowing Overview -->
        <section class="summary-grid">
            <div class="stat-card">
                <div class="stat-label">Reader</div>
                <div class="stat-value stat-value-sm"><?= htmlspecialchars($member['full_name']) ?></div>
                <div class="stat-sub">Status: <strong><?= htmlspecialchars($member['account_status']) ?></strong></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Active Borrowings</div>
                <div class="stat-value"><?= $activeCount ?> / <?= $maxLimit ?></div>
                <div class="stat-sub"><?= max(0, $maxLimit - $activeCount) ?> slot(s) remaining</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Accrued Live Overdue Fines</div>
                <div class="stat-value <?= $totalLiveFines > 0 ? 'overdue-text' : '' ?>">
                    ₹<?= number_format($totalLiveFines, 2) ?>
                </div>
                <div class="stat-sub">Rate: ₹<?= $dailyFineRate ?>/day past due date</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Membership Valid Until</div>
                <div class="stat-value stat-value-sm">
                    <?= !empty($member['expiry_date']) ? htmlspecialchars(date('M d, Y', strtotime($member['expiry_date']))) : 'Active' ?>
                </div>
                <div class="stat-sub"><?= htmlspecialchars($member['role']) ?> Account</div>
            </div>
        </section>

        <!-- Active Loans Section -->
        <div class="section-header">
            <h2>Active Loans & Digital Editions</h2>
            <span class="section-counter">
                Digital PDF access remains active until the book is returned
            </span>
        </div>

        <?php if (empty($activeLoans)): ?>
            <div class="empty-state">
                <p>You do not have any active books checked out right now.</p>
                <a href="index.php">Browse the Library Catalog</a>
            </div>
        <?php else: ?>
            <div class="loan-list">
                <?php foreach ($activeLoans as $loan): ?>
                    <?php $isOverdue = ($loan['days_overdue'] > 0); ?>
                    <article class="loan-card <?= $isOverdue ? 'is-overdue' : '' ?>">
                        <div class="loan-info">
                            <span class="book-index-tag"><?= htmlspecialchars($loan['book_index']) ?></span>
                            <span class="book-index-tag book-genre-tag"><?= htmlspecialchars($loan['genre_category']) ?></span>
                            <h3 class="loan-title"><?= htmlspecialchars($loan['title']) ?></h3>
                            <div class="loan-author">by <?= htmlspecialchars($loan['author']) ?> &bull; ISBN: <?= htmlspecialchars($loan['isbn']) ?></div>

                            <div class="loan-dates">
                                <span>Issued: <strong><?= htmlspecialchars(date('M d, Y', strtotime($loan['issue_date']))) ?></strong></span>
                                <span class="due-pill <?= $isOverdue ? 'overdue' : '' ?>">
                                    Due: <?= htmlspecialchars(date('M d, Y', strtotime($loan['due_date']))) ?>
                                    <?php if ($isOverdue): ?>
                                        (<?= (int) $loan['days_overdue'] ?> day<?= $loan['days_overdue'] > 1 ? 's' : '' ?> overdue)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <div class="loan-actions">
                            <?php if ($loan['loan_status'] === 'Issued' && !empty($loan['pdf_path'])): ?>
                                <a href="read_book.php?book_id=<?= (int) $loan['book_id'] ?>" class="btn-read-pdf">
                                    &#128214; Read Book (PDF)
                                </a>
                            <?php elseif ($loan['loan_status'] === 'Pending'): ?>
                                <span class="no-pdf-badge">Awaiting Librarian Issue</span>
                            <?php else: ?>
                                <span class="no-pdf-badge">Physical Copy Only (No PDF)</span>
                            <?php endif; ?>

                            <?php if ($isOverdue): ?>
                                <span class="fine-tag">
                                    Live Fine: ₹<?= number_format($loan['live_fine'], 2) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Past Borrowing History -->
        <div class="section-header">
            <h2>Returned Borrowing History</h2>
        </div>

        <?php if (empty($pastLoans)): ?>
            <div class="empty-state">
                <p>No returned books in your history yet.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Index</th>
                            <th>Title &amp; Author</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Settled Fine</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pastLoans as $past): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($past['book_index']) ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($past['title']) ?></strong><br>
                                    <span class="author-muted"><?= htmlspecialchars($past['author']) ?></span>
                                </td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($past['issue_date']))) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($past['due_date']))) ?></td>
                                <td>
                                    <?= !empty($past['return_date']) ? htmlspecialchars(date('M d, Y', strtotime($past['return_date']))) : '—' ?>
                                </td>
                                <td>₹<?= number_format((float) ($past['fine_amount'] ?? 0), 2) ?></td>
                                <td><span class="status-returned"><?= htmlspecialchars($past['loan_status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </main>

</body>
</html>