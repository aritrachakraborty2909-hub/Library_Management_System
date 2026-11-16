<?php

// Module 0.3.1: Process Book Access Request (Borrow a Book)
function borrowBook(PDO $pdo, int $memberId, int $bookId): array {
    try {
        // Start an ACID transaction (all steps succeed together, or none do)
        $pdo->beginTransaction();

        // 1. Check member's status and borrowing limit (D1 Member Store)
        $memberStmt = $pdo->prepare("SELECT account_status, max_borrowing_limit FROM members WHERE member_id = :mid");
        $memberStmt->execute([':mid' => $memberId]);
        $member = $memberStmt->fetch();

        if (!$member || $member['account_status'] !== 'Active') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Your account is not active for borrowing.'];
        }

        // 2. Count how many books this member currently has issued (D3 Borrowing Store)
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id = :mid AND loan_status = 'Issued'");
        $countStmt->execute([':mid' => $memberId]);
        $activeLoans = (int)$countStmt->fetchColumn();

        if ($activeLoans >= (int)$member['max_borrowing_limit']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => "Borrowing limit reached ({$member['max_borrowing_limit']} books max). Return a book first."];
        }

        // 3. Prevent borrowing two copies of the exact same book simultaneously
        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM borrowings WHERE member_id = :mid AND book_id = :bid AND loan_status = 'Issued'");
        $dupStmt->execute([':mid' => $memberId, ':bid' => $bookId]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'You already have a copy of this book checked out.'];
        }

        // 4. Lock the book row (FOR UPDATE) so two users can't grab the last copy at the same millisecond
        $bookStmt = $pdo->prepare("SELECT title, available_copies FROM books WHERE book_id = :bid AND is_deleted = FALSE FOR UPDATE");
        $bookStmt->execute([':bid' => $bookId]);
        $book = $bookStmt->fetch();

        if (!$book || (int)$book['available_copies'] <= 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Sorry, this book is currently out of stock.'];
        }

        // 5. Decrement available_copies in D2 (books)
        $updateBook = $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE book_id = :bid");
        $updateBook->execute([':bid' => $bookId]);

        // 6. Insert new loan record into D3 (borrowings) with a 14-day due date
        $insertLoan = $pdo->prepare("
            INSERT INTO borrowings (member_id, book_id, issue_date, due_date, loan_status)
            VALUES (:mid, :bid, CURRENT_DATE, CURRENT_DATE + INTERVAL '14 days', 'Issued')
        ");
        $insertLoan->execute([':mid' => $memberId, ':bid' => $bookId]);

        // Commit both changes permanently!
        $pdo->commit();
        return ['success' => true, 'message' => "Success! You borrowed '{$book['title']}'. Please return within 14 days."];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Borrowing failed: ' . $e->getMessage()];
    }
}

// Module 0.3.2: View Borrowing History & Active Shelf (Reads D3 + D2)
function getMemberBorrowings(PDO $pdo, int $memberId): array {
    $sql = "SELECT 
                br.borrowing_id,
                br.issue_date,
                br.due_date,
                br.return_date,
                br.loan_status,
                br.fine_amount,
                b.book_id,
                b.isbn,
                b.title,
                b.author,
                b.rack_location,
                GREATEST(0, CURRENT_DATE - br.due_date) AS overdue_days
            FROM borrowings br
            JOIN books b ON br.book_id = b.book_id
            WHERE br.member_id = :mid
            ORDER BY 
                CASE WHEN br.loan_status = 'Issued' THEN 0 ELSE 1 END,
                br.issue_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':mid' => $memberId]);
    return $stmt->fetchAll();
}

// Module 0.3.3: Process Book Return (Updates D2 + D3 atomically)
function returnBook(PDO $pdo, int $borrowingId, int $memberId): array {
    try {
        $pdo->beginTransaction();

        // 1. Fetch the active loan and lock the row
        $stmt = $pdo->prepare("
            SELECT borrowing_id, book_id, due_date, loan_status,
                   GREATEST(0, CURRENT_DATE - due_date) AS overdue_days
            FROM borrowings
            WHERE borrowing_id = :brid AND member_id = :mid
            FOR UPDATE
        ");
        $stmt->execute([':brid' => $borrowingId, ':mid' => $memberId]);
        $loan = $stmt->fetch();

        if (!$loan || $loan['loan_status'] !== 'Issued') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Invalid or already returned loan record.'];
        }

        // Calculate fine at ₹10 per overdue day
        $overdueDays = (int)$loan['overdue_days'];
        $fineAmount = $overdueDays * 10.00;

        // 2. Module 0.3.3.3: Update Borrow Status in D3 (borrowings)
        $updateLoan = $pdo->prepare("
            UPDATE borrowings 
            SET return_date = CURRENT_DATE,
                loan_status = 'Returned',
                fine_amount = :fine
            WHERE borrowing_id = :brid
        ");
        $updateLoan->execute([
            ':fine' => $fineAmount,
            ':brid' => $borrowingId
        ]);

        // 3. Module 0.3.3.2: Update Book Count in D2 (books)
        $updateBook = $pdo->prepare("
            UPDATE books 
            SET available_copies = LEAST(total_copies, available_copies + 1)
            WHERE book_id = :bid
        ");
        $updateBook->execute([':bid' => $loan['book_id']]);

        $pdo->commit();

        if ($fineAmount > 0) {
            return ['success' => true, 'message' => "Book returned! Note: ₹{$fineAmount} late fine recorded ({$overdueDays} days overdue)."];
        }
        return ['success' => true, 'message' => 'Book returned on time! Shelf stock updated.'];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Return failed: ' . $e->getMessage()];
    }
}
?>