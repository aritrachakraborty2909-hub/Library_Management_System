<?php
require_once 'config/db.php';

if (!isset($_SESSION['member_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = (int) $_SESSION['member_id'];
    $bookId   = (int) ($_POST['book_id'] ?? 0);
    $redirect = $_POST['redirect'] ?? 'index.php';

    // Whitelist safe local redirects
    if (!in_array($redirect, ['index.php', 'profile.php'], true)) {
        $redirect = 'index.php';
    }

    if ($bookId > 0) {
        // Check if already in wishlist -> toggle it
        $checkStmt = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE member_id = :mid AND book_id = :bid");
        $checkStmt->execute([':mid' => $memberId, ':bid' => $bookId]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $delStmt = $pdo->prepare("DELETE FROM wishlist WHERE wishlist_id = :wid");
            $delStmt->execute([':wid' => $existing['wishlist_id']]);
        } else {
            $insStmt = $pdo->prepare("INSERT INTO wishlist (member_id, book_id) VALUES (:mid, :bid) ON CONFLICT DO NOTHING");
            $insStmt->execute([':mid' => $memberId, ':bid' => $bookId]);
        }
    }

    header("Location: " . $redirect);
    exit;
}

header("Location: index.php");
exit;