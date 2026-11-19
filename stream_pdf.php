<?php
// stream_pdf.php
session_start();
require_once __DIR__ . '/config/db.php';

// 1. Verify User Authentication
if (!isset($_SESSION['member_id'])) {
    http_response_code(401);
    exit('Unauthorized: Please log in to access digital books.');
}

$memberId = (int) $_SESSION['member_id'];
$role     = $_SESSION['role'] ?? 'Member';
$bookId   = isset($_GET['book_id']) ? (int) $_GET['book_id'] : 0;

if ($bookId <= 0) {
    http_response_code(400);
    exit('Invalid book request.');
}

try {
    // 2. Fetch Book Details & PDF Path from D2 (books)
    $stmt = $pdo->prepare("
        SELECT book_id, title, pdf_path, is_deleted 
        FROM books 
        WHERE book_id = :book_id
        LIMIT 1
    ");
    $stmt->execute([':book_id' => $bookId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book || empty($book['pdf_path'])) {
        http_response_code(404);
        exit('Digital copy not found for this title.');
    }

    // 3. Access Control Check (Staff OR Member with Active 'Issued' Loan)
    $staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
    $hasAccess  = in_array($role, $staffRoles, true);

    if (!$hasAccess) {
        // Verify active loan in D3 (borrowings) and check account isn't locked
        $loanStmt = $pdo->prepare("
            SELECT b.borrowing_id 
            FROM borrowings b
            JOIN members m ON b.member_id = m.member_id
            WHERE b.member_id = :member_id 
              AND b.book_id = :book_id 
              AND b.loan_status = 'Issued'
              AND b.return_date IS NULL
              AND m.account_status = 'Active'
            LIMIT 1
        ");
        $loanStmt->execute([
            ':member_id' => $memberId,
            ':book_id'   => $bookId
        ]);

        if ($loanStmt->fetch()) {
            $hasAccess = true;
        }
    }

    if (!$hasAccess) {
        http_response_code(403);
        exit('Access Denied: You must have an active loan for this book to read it.');
    }

    // 3.5. Redirect to Cloud/Online PDF URL (e.g., Supabase Storage or GitHub Raw)
    if (preg_match('/^https?:\/\//i', trim($book['pdf_path']))) {
        header('Location: ' . trim($book['pdf_path']));
        exit;
    }

    // 4. Resolve & Sanitize Local File Path (Prevents Directory Traversal)
    $baseDir  = realpath(__DIR__ . '/uploads/pdfs');
    $fileName = basename($book['pdf_path']); // Strips any ../ attempts
    $filePath = $baseDir ? realpath($baseDir . DIRECTORY_SEPARATOR . $fileName) : false;

    if (!$filePath || strpos($filePath, $baseDir) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit('PDF file is missing on the server.');
    }

    // 5. Clean Output Buffers (Prevents corrupted PDF headers)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $fileSize = filesize($filePath);
    $safeTitle = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $book['title']) ?: 'book';

    // 6. Send Inline PDF Headers & Support Byte-Range Streaming
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safeTitle . '.pdf"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    $fp = fopen($filePath, 'rb');
    if (!$fp) {
        http_response_code(500);
        exit('Unable to open file stream.');
    }

    // Handle HTTP Range requests (crucial for fast scrolling/jumping pages in browser PDF viewers)
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $start = (int) $matches[1];
        $end   = ($matches[2] !== '') ? (int) $matches[2] : ($fileSize - 1);

        if ($start > $end || $start >= $fileSize) {
            http_response_code(416);
            header("Content-Range: bytes */$fileSize");
            fclose($fp);
            exit;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
        header("Content-Length: $length");

        fseek($fp, $start);
        $bytesRemaining = $length;
        while ($bytesRemaining > 0 && !feof($fp)) {
            $chunkSize = min(8192, $bytesRemaining);
            echo fread($fp, $chunkSize);
            flush();
            $bytesRemaining -= $chunkSize;
        }
    } else {
        header('Content-Length: ' . $fileSize);
        fpassthru($fp);
    }

    fclose($fp);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error while verifying reading access.');
}