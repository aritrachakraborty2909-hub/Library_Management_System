<?php
require_once 'config/db.php';

if (!isset($_SESSION['member_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: my_loans.php");
    exit;
}

$memberId    = (int)$_SESSION['member_id'];
$borrowingId = (int)($_POST['borrowing_id'] ?? $_POST['loan_id'] ?? 0);

if ($borrowingId <= 0) {
    header("Location: my_loans.php?err=" . urlencode("Unable to return book: Invalid loan reference."));
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Lock the active borrowing record belonging to this member
    $loanStmt = $pdo->prepare("
        SELECT borrowing_id, book_id
        FROM borrowings
        WHERE borrowing_id = :bid
          AND member_id = :mid
          AND return_date IS NULL
          AND loan_status IN ('Issued', 'Pending')
        FOR UPDATE
    ");
    $loanStmt->execute([
        ':bid' => $borrowingId,
        ':mid' => $memberId
    ]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        $pdo->rollBack();
        header("Location: my_loans.php?err=" . urlencode("Unable to return book: Active loan record not found."));
        exit;
    }

    // 2. Mark the borrowing record as Returned
    $updLoan = $pdo->prepare("
        UPDATE borrowings
        SET loan_status = 'Returned',
            return_date = CURRENT_DATE
        WHERE borrowing_id = :bid
    ");
    $updLoan->execute([':bid' => $borrowingId]);

    // 3. Increment available_copies back in the books table
    $updBook = $pdo->prepare("
        UPDATE books
        SET available_copies = LEAST(total_copies, available_copies + 1)
        WHERE book_id = :book_id
    ");
    $updBook->execute([':book_id' => (int)$loan['book_id']]);

    $pdo->commit();

    header("Location: my_loans.php?returned=1");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: my_loans.php?err=" . urlencode("Unable to return book: " . $e->getMessage()));
    exit;
}