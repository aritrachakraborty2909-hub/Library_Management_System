<?php
// Module 0.1.3.1: Get Pending Applications (Reads from D1 Member Store)
function getPendingApplications(PDO $pdo): array {
    $stmt = $pdo->query("SELECT member_id, full_name, email, phone_number, address, created_at 
                         FROM members 
                         WHERE account_status = 'Pending' AND role = 'Member'
                         ORDER BY created_at ASC");
    return $stmt->fetchAll();
}

// Module 0.1.3.4: Update Account Status (Writes to D1 Member Store)
function decideRegistration(PDO $pdo, int $memberId, string $decision): bool {
    $status = ($decision === 'Approve') ? 'Active' : 'Rejected';
    $stmt = $pdo->prepare("UPDATE members SET account_status = :status WHERE member_id = :id");
    return $stmt->execute([':status' => $status, ':id' => $memberId]);
}

// Fetch Active & Pending Borrowings for Librarian Circulation Desk (Reads D1, D2, D3)
function getActiveCirculation(PDO $pdo): array {
    $sql = "SELECT b.borrowing_id, m.full_name, m.email, bk.title, bk.isbn, 
                   b.issue_date, b.due_date, b.loan_status
            FROM borrowings b
            JOIN members m ON b.member_id = m.member_id
            JOIN books bk ON b.book_id = bk.book_id
            WHERE b.loan_status IN ('Pending', 'Issued')
            ORDER BY b.due_date ASC";
    return $pdo->query($sql)->fetchAll();
}

// Module 0.3.3: Release Book Access / Process Return (Atomic PDO Transaction on D2 & D3)
function processBookReturn(PDO $pdo, int $borrowingId): bool {
    try {
        $pdo->beginTransaction();

        // Find the book_id linked to this loan
        $stmt = $pdo->prepare("SELECT book_id FROM borrowings WHERE borrowing_id = :id AND loan_status = 'Issued'");
        $stmt->execute([':id' => $borrowingId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $pdo->rollBack();
            return false;
        }

        // 0.3.3.3 Update Borrow Status (D3 Borrowing Store)
        $updateLoan = $pdo->prepare("UPDATE borrowings 
                                     SET loan_status = 'Returned', return_date = CURRENT_DATE 
                                     WHERE borrowing_id = :id");
        $updateLoan->execute([':id' => $borrowingId]);

        // 0.3.3.2 Update Book Count (D2 Book Store)
        $updateBook = $pdo->prepare("UPDATE books 
                                     SET available_copies = available_copies + 1 
                                     WHERE book_id = :book_id");
        $updateBook->execute([':book_id' => $loan['book_id']]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}
?>