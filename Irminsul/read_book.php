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
    <style>
        :root {
            --parchment-bg: #f3e7cb;
            --parchment-border: #d8bd89;
            --espresso-dark: #65452f;
            --espresso-hover: #4e3423;
            --ink-text: #2c1e14;
            --muted-ink: #5c4636;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background-color: var(--parchment-bg);
            color: var(--ink-text);
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* Top Reader Toolbar */
        .reader-header {
            background-color: var(--parchment-bg);
            border-bottom: 2px solid var(--parchment-border);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            box-shadow: 0 2px 6px rgba(101, 69, 47, 0.12);
            z-index: 10;
        }

        .reader-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: var(--espresso-dark);
            color: #fffaf0;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 5px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.88rem;
            font-weight: 600;
            white-space: nowrap;
            transition: background-color 0.2s ease;
        }

        .btn-back:hover {
            background-color: var(--espresso-hover);
        }

        .book-meta {
            min-width: 0;
        }

        .book-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--espresso-dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .book-sub {
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.8rem;
            color: var(--muted-ink);
        }

        .reader-right {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 0.82rem;
            white-space: nowrap;
        }

        .loan-badge {
            background-color: #ead6ac;
            border: 1px solid var(--parchment-border);
            color: var(--espresso-dark);
            padding: 5px 10px;
            border-radius: 4px;
            font-weight: 600;
        }

        .btn-raw {
            color: var(--espresso-dark);
            text-decoration: underline;
            font-weight: 500;
        }

        /* Full-Height PDF Container */
        .reader-viewport {
            flex: 1;
            width: 100%;
            background-color: #3a322d;
            position: relative;
        }

        .pdf-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        .pdf-fallback {
            padding: 40px 20px;
            text-align: center;
            color: var(--parchment-bg);
            font-family: system-ui, -apple-system, sans-serif;
        }

        .pdf-fallback a {
            display: inline-block;
            margin-top: 12px;
            padding: 10px 18px;
            background-color: var(--parchment-bg);
            color: var(--espresso-dark);
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }

        @media (max-width: 680px) {
            .loan-badge {
                display: none;
            }
            .book-title {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>

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