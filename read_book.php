<?php
// read_book.php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$memberId = (int) $_SESSION['member_id'];
$role     = $_SESSION['role'] ?? 'Member';
$bookId   = isset($_GET['book_id']) ? (int) $_GET['book_id'] : 0;

if ($bookId <= 0) {
    header('Location: my_loans.php');
    exit;
}

// Fetch book details
$stmt = $pdo->prepare("
    SELECT book_id, book_index, title, author, genre_category, pdf_path 
    FROM books 
    WHERE book_id = :book_id
    LIMIT 1
");
$stmt->execute([':book_id' => $bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book || empty($book['pdf_path'])) {
    die("Digital edition not available for this book.");
}

// Verify access rights to display appropriate status banner
$staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
$isStaff    = in_array($role, $staffRoles, true);
$loanInfo   = null;

if (!$isStaff) {
    $loanStmt = $pdo->prepare("
        SELECT borrowing_id, issue_date, due_date 
        FROM borrowings 
        WHERE member_id = :member_id 
          AND book_id = :book_id 
          AND loan_status = 'Issued' 
          AND return_date IS NULL
        LIMIT 1
    ");
    $loanStmt->execute([
        ':member_id' => $memberId,
        ':book_id'   => $bookId
    ]);
    $loanInfo = $loanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$loanInfo) {
        header('Location: my_loans.php?error=no_active_loan');
        exit;
    }
}

$backUrl   = $isStaff ? 'admin_dashboard.php' : 'my_loans.php';
$streamUrl = 'stream_pdf.php?book_id=' . urlencode((string) $book['book_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reading: <?= htmlspecialchars($book['title']) ?> — The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="reader-body">

    <header class="reader-header">
        <div class="reader-left">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="btn-back">&larr; Back to Shelf</a>
            <div class="book-meta">
                <div class="book-title">
                    [<?= htmlspecialchars($book['book_index']) ?>] <?= htmlspecialchars($book['title']) ?>
                </div>
                <div class="book-sub">
                    by <?= htmlspecialchars($book['author']) ?> &bull; <?= htmlspecialchars($book['genre_category']) ?>
                </div>
            </div>
        </div>

        <div class="reader-right">
            <?php if ($isStaff): ?>
                <span class="loan-badge">Staff Preview Mode</span>
            <?php elseif ($loanInfo): ?>
                <span class="loan-badge">
                    Due: <?= htmlspecialchars(date('M d, Y', strtotime($loanInfo['due_date']))) ?>
                </span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($streamUrl) ?>" target="_blank" class="btn-raw">Open in New Tab</a>
        </div>
    </header>

    <main class="reader-viewport">
        <!-- #toolbar=1 enables built-in zoom, page jump, and search; navpanes=0 keeps sidebar clean -->
        <object 
            data="<?= htmlspecialchars($streamUrl) ?>#toolbar=1&navpanes=0&scrollbar=1" 
            type="application/pdf" 
            class="pdf-frame">
            <iframe 
                src="<?= htmlspecialchars($streamUrl) ?>#toolbar=1&navpanes=0" 
                class="pdf-frame" 
                title="<?= htmlspecialchars($book['title']) ?>">
                <div class="pdf-fallback">
                    <p>Your browser does not support inline PDF viewing.</p>
                    <a href="<?= htmlspecialchars($streamUrl) ?>" target="_blank">Open Digital Book (PDF)</a>
                </div>
            </iframe>
        </object>
    </main>

</body>
</html>