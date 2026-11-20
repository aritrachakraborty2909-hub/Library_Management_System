<?php
require_once 'config/db.php';

// Security Guard 1: Only logged-in users can borrow books
if (!isset($_SESSION['member_id'])) {
    header("Location: login.php");
    exit;
}

// Security Guard 2: Staff accounts (Cataloger / Librarian / System Manager) are view-only in the catalog
$staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
if (in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['book_id'])) {
    header("Location: index.php");
    exit;
}

$memberId = (int)$_SESSION['member_id'];
$bookId   = (int)$_POST['book_id'];

try {
    // Start an atomic PostgreSQL transaction so stock decrement & loan creation succeed or fail together
    $pdo->beginTransaction();

    // 1. Check Member Account Status, Role & Borrowing Limit (D1 Member Store)
    $memberStmt = $pdo->prepare("
        SELECT role, account_status, max_borrowing_limit 
        FROM members 
        WHERE member_id = :mid
        FOR UPDATE
    ");
    $memberStmt->execute([':mid' => $memberId]);
    $member = $memberStmt->fetch();

    if (!$member || in_array($member['role'] ?? '', $staffRoles, true)) {
        $pdo->rollBack();
        header("Location: index.php");
        exit;
    }

    if ($member['account_status'] !== 'Active') {
        $pdo->rollBack();
        header("Location: my_loans.php?err=" . urlencode("Your account is not active or is locked due to overdue items."));
        exit;
    }

    // 2. Count how many books this member currently has borrowed (D3 Borrowing Store)
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM borrowings 
        WHERE member_id = :mid AND loan_status IN ('Pending', 'Issued')
    ");
    $countStmt->execute([':mid' => $memberId]);
    $activeCount = (int)$countStmt->fetchColumn();

    if ($activeCount >= (int)$member['max_borrowing_limit']) {
        $pdo->rollBack();
        header("Location: my_loans.php?err=" . urlencode("Borrowing limit reached ({$member['max_borrowing_limit']} books max). Return a book first."));
        exit;
    }

    // 3. Prevent borrowing a duplicate copy of the exact same book already on loan
    $dupStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM borrowings 
        WHERE member_id = :mid AND book_id = :bid AND loan_status IN ('Pending', 'Issued')
    ");
    $dupStmt->execute([':mid' => $memberId, ':bid' => $bookId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        header("Location: my_loans.php?err=" . urlencode("You already have this book checked out."));
        exit;
    }

    // 4. Lock the Book row and verify available copies > 0 (D2 Book Store)
    $bookStmt = $pdo->prepare("
        SELECT title, available_copies 
        FROM books 
        WHERE book_id = :bid AND is_deleted = FALSE 
        FOR UPDATE
    ");
    $bookStmt->execute([':bid' => $bookId]);
    $book = $bookStmt->fetch();

    if (!$book || (int)$book['available_copies'] < 1) {
        $pdo->rollBack();
        header("Location: my_loans.php?err=" . urlencode("Sorry, no copies of this book are currently available."));
        exit;
    }

    // 5. Decrement available_copies in D2 Book Store
    $decStmt = $pdo->prepare("
        UPDATE books 
        SET available_copies = available_copies - 1 
        WHERE book_id = :bid
    ");
    $decStmt->execute([':bid' => $bookId]);

    // 6. Insert new loan record in D3 Borrowing Store (14-day loan period)
    $loanStmt = $pdo->prepare("
        INSERT INTO borrowings (member_id, book_id, issue_date, due_date, loan_status)
        VALUES (:mid, :bid, CURRENT_DATE, CURRENT_DATE + INTERVAL '14 days', 'Issued')
    ");
    $loanStmt->execute([':mid' => $memberId, ':bid' => $bookId]);

    // Commit both database updates atomically
    $pdo->commit();

    header("Location: my_loans.php?borrowed=" . urlencode($book['title']));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: my_loans.php?err=" . urlencode("Could not process borrow request. Please try again."));
    exit;
}
?>