<?php

// Fetch all members from D1 Member Store
function getAllMembers(PDO $pdo): array {
    $sql = "SELECT member_id, full_name, email, phone_number, role, account_status, expiry_date, max_borrowing_limit, created_at
            FROM members
            ORDER BY member_id ASC";
    return $pdo->query($sql)->fetchAll();
}

// Module 0.1.3 / 0.3.4: Update Member Account Status (Active, Locked, Pending, Rejected)
function updateMemberStatus(PDO $pdo, int $memberId, string $newStatus): array {
    $allowed = ['Active', 'Locked', 'Pending', 'Rejected'];
    if (!in_array($newStatus, $allowed, true)) {
        return ['success' => false, 'message' => 'Invalid account status selected.'];
    }

    $stmt = $pdo->prepare("UPDATE members SET account_status = :status WHERE member_id = :mid");
    $stmt->execute([
        ':status' => $newStatus,
        ':mid'    => $memberId
    ]);

    return ['success' => true, 'message' => "Member #{$memberId} status updated to {$newStatus}."];
}

// Module 0.4.3: Renew Membership (+1 Year) & Log Payment in D4 (transactions)
function renewMemberAccount(PDO $pdo, int $memberId, float $feeAmount = 500.00, string $method = 'Cash'): array {
    try {
        $pdo->beginTransaction();

        // 1. Extend expiry_date by 1 year (from today if already expired, or from current expiry_date)
        $updateMember = $pdo->prepare("
            UPDATE members 
            SET expiry_date = GREATEST(CURRENT_DATE, expiry_date) + INTERVAL '1 year',
                account_status = 'Active'
            WHERE member_id = :mid
        ");
        $updateMember->execute([':mid' => $memberId]);

        // 2. Record payment in D4 Transaction Store
        $insertTxn = $pdo->prepare("
            INSERT INTO transactions (member_id, payment_type, amount, payment_method, gateway_ref_id, status)
            VALUES (:mid, 'Renewal', :amount, :method, :ref, 'Success')
        ");
        $insertTxn->execute([
            ':mid'    => $memberId,
            ':amount' => $feeAmount,
            ':method' => $method,
            ':ref'    => 'REN-' . strtoupper(uniqid())
        ]);

        $pdo->commit();
        return ['success' => true, 'message' => "Membership renewed for 1 year (₹{$feeAmount} recorded in Transactions)."];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Renewal failed: ' . $e->getMessage()];
    }
}

// Module 0.3.5: Fetch Active & Overdue Loans across all members (Reads D3 + D1 + D2)
function getActiveAndOverdueLoans(PDO $pdo): array {
    $sql = "SELECT 
                br.borrowing_id,
                br.issue_date,
                br.due_date,
                GREATEST(0, CURRENT_DATE - br.due_date) AS overdue_days,
                m.member_id,
                m.full_name,
                m.email,
                m.account_status,
                b.title,
                b.isbn
            FROM borrowings br
            JOIN members m ON br.member_id = m.member_id
            JOIN books b ON br.book_id = b.book_id
            WHERE br.loan_status = 'Issued'
            ORDER BY br.due_date ASC";
    return $pdo->query($sql)->fetchAll();
}

// Fetch recent payment records from D4 Transaction Store
function getRecentTransactions(PDO $pdo): array {
    $sql = "SELECT t.*, m.full_name, m.email
            FROM transactions t
            JOIN members m ON t.member_id = m.member_id
            ORDER BY t.created_at DESC
            LIMIT 15";
    return $pdo->query($sql)->fetchAll();
}
?>