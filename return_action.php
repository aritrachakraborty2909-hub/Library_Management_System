<?php
// return_action.php
// Module 0.3.3: Handle Member Self-Return Request
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/CirculationService.php';

// 1. Verify Authentication
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

// 2. Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_loans.php');
    exit;
}

$memberId    = (int) $_SESSION['member_id'];
$borrowingId = (int) ($_POST['borrowing_id'] ?? 0);

if ($borrowingId <= 0) {
    header('Location: my_loans.php?error=invalid_loan');
    exit;
}

// 3. Call existing Module 0.3.3 function from CirculationService.php
$result = returnBook($pdo, $borrowingId, $memberId);

if ($result['success']) {
    header('Location: my_loans.php?returned=1');
} else {
    header('Location: my_loans.php?error=invalid_loan');
}
exit;