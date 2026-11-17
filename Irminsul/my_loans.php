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
    <style>
        :root {
            --parchment-bg: #f3e7cb;
            --parchment-card: #faf3e3;
            --parchment-border: #d8bd89;
            --espresso-dark: #65452f;
            --espresso-hover: #4e3423;
            --ink-text: #2c1e14;
            --muted-ink: #5c4636;
            --danger-red: #9e2a2b;
            --danger-bg: #f9e2e2;
            --success-green: #2d6a4f;
            --success-bg: #e3f3eb;
        }

        body {
            background-color: var(--parchment-bg);
            color: var(--ink-text);
            font-family: 'Georgia', 'Times New Roman', serif;
            margin: 0;
            padding: 0;
        }

        /* Navbar */
        .top-nav {
            background-color: var(--espresso-dark);
            color: #fffaf0;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 3px solid var(--parchment-border);
        }

        .brand {
            font-size: 1.35rem;
            font-weight: bold;
            color: #fffaf0;
            text-decoration: none;
            letter-spacing: 0.4px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 18px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.92rem;
        }

        .nav-links a {
            color: #f3e7cb;
            text-decoration: none;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background-color: rgba(255, 255, 255, 0.14);
            color: #ffffff;
        }

        /* Main Container */
        .container {
            max-width: 1120px;
            margin: 28px auto;
            padding: 0 20px 50px;
        }

        /* Banners */
        .alert-banner {
            padding: 13px 18px;
            border-radius: 6px;
            margin-bottom: 22px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.94rem;
            font-weight: 500;
            border: 1px solid;
        }

        .alert-success {
            background-color: var(--success-bg);
            color: var(--success-green);
            border-color: #95d5b2;
        }

        .alert-error {
            background-color: var(--danger-bg);
            color: var(--danger-red);
            border-color: #e5989b;
        }

        /* Account Summary Strip */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background-color: var(--parchment-card);
            border: 1px solid var(--parchment-border);
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 2px 5px rgba(101, 69, 47, 0.08);
        }

        .stat-label {
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--muted-ink);
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 1.45rem;
            font-weight: bold;
            color: var(--espresso-dark);
        }

        .stat-value.overdue-text {
            color: var(--danger-red);
        }

        .stat-sub {
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.8rem;
            color: var(--muted-ink);
            margin-top: 4px;
        }

        /* Section Headings */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            border-bottom: 2px solid var(--parchment-border);
            padding-bottom: 8px;
            margin: 30px 0 18px;
        }

        .section-header h2 {
            margin: 0;
            font-size: 1.35rem;
            color: var(--espresso-dark);
        }

        /* Active Loan Cards */
        .loan-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .loan-card {
            background-color: var(--parchment-card);
            border: 1px solid var(--parchment-border);
            border-left: 5px solid var(--espresso-dark);
            border-radius: 8px;
            padding: 18px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            box-shadow: 0 2px 6px rgba(101, 69, 47, 0.08);
        }

        .loan-card.is-overdue {
            border-left-color: var(--danger-red);
            background-color: #fdf6f5;
        }

        .loan-info {
            flex: 1;
            min-width: 260px;
        }

        .book-index-tag {
            display: inline-block;
            font-family: monospace;
            font-size: 0.8rem;
            background-color: #ead6ac;
            color: var(--espresso-dark);
            padding: 2px 7px;
            border-radius: 4px;
            border: 1px solid var(--parchment-border);
            margin-bottom: 6px;
        }

        .loan-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--ink-text);
            margin: 2px 0 4px;
        }

        .loan-author {
            font-size: 0.94rem;
            color: var(--muted-ink);
            margin-bottom: 10px;
        }

        .loan-dates {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.85rem;
            color: var(--muted-ink);
        }

        .due-pill {
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 4px;
            background-color: #ead6ac;
            color: var(--espresso-dark);
        }

        .due-pill.overdue {
            background-color: var(--danger-bg);
            color: var(--danger-red);
        }

        /* Actions Column */
        .loan-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
            font-family: system-ui, -apple-system, sans-serif;
        }

        .btn-read-pdf {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: var(--espresso-dark);
            color: #fffaf0;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.92rem;
            transition: background-color 0.2s ease, transform 0.1s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.14);
        }

        .btn-read-pdf:hover {
            background-color: var(--espresso-hover);
            transform: translateY(-1px);
        }

        .no-pdf-badge {
            font-size: 0.82rem;
            color: var(--muted-ink);
            background-color: #ede0c4;
            padding: 7px 12px;
            border-radius: 5px;
            border: 1px dashed var(--parchment-border);
        }

        .fine-tag {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--danger-red);
        }

        /* History Table */
        .table-wrapper {
            background-color: var(--parchment-card);
            border: 1px solid var(--parchment-border);
            border-radius: 8px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.9rem;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--parchment-border);
        }

        th {
            background-color: #ead6ac;
            color: var(--espresso-dark);
            font-weight: 700;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status-returned {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background-color: var(--success-bg);
            color: var(--success-green);
        }

        .empty-state {
            background-color: var(--parchment-card);
            border: 1px dashed var(--parchment-border);
            border-radius: 8px;
            padding: 32px 20px;
            text-align: center;
            color: var(--muted-ink);
        }

        .empty-state a {
            display: inline-block;
            margin-top: 12px;
            background-color: var(--espresso-dark);
            color: #fffaf0;
            padding: 9px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
        }

        @media (max-width: 640px) {
            .loan-actions {
                align-items: flex-start;
                width: 100%;
            }
            .btn-read-pdf {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
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
                <div class="stat-value" style="font-size: 1.2rem;"><?= htmlspecialchars($member['full_name']) ?></div>
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
                <div class="stat-value" style="font-size: 1.2rem;">
                    <?= !empty($member['expiry_date']) ? htmlspecialchars(date('M d, Y', strtotime($member['expiry_date']))) : 'Active' ?>
                </div>
                <div class="stat-sub"><?= htmlspecialchars($member['role']) ?> Account</div>
            </div>
        </section>

        <!-- Active Loans Section -->
        <div class="section-header">
            <h2>Active Loans & Digital Editions</h2>
            <span style="font-family: system-ui, sans-serif; font-size: 0.85rem; color: var(--muted-ink);">
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
                            <span class="book-index-tag" style="background: transparent;"><?= htmlspecialchars($loan['genre_category']) ?></span>
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
                                    <span style="color: var(--muted-ink); font-size: 0.83rem;"><?= htmlspecialchars($past['author']) ?></span>
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