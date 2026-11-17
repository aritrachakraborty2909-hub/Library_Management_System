<?php
// admin_dashboard.php
// Staff Workbench: Add Books + PDF Upload, Link/Replace PDFs on Existing Books, Soft-Delete, Member Approvals & Returns
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/CatalogueService.php';
if (file_exists(__DIR__ . '/services/LibrarianService.php')) {
    require_once __DIR__ . '/services/LibrarianService.php';
}

// 1. Verify Staff Authorization
$staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
if (!isset($_SESSION['member_id']) || !in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    header('Location: login.php');
    exit;
}

$role             = $_SESSION['role'];
$catalogueService = new CatalogueService($pdo);
$librarianService = class_exists('LibrarianService') ? new LibrarianService($pdo) : null;

$flashMessage = '';
$flashType    = '';

// 2. Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_book':
                $created = $catalogueService->addBook($_POST, $_FILES['book_pdf'] ?? null);
                $pdfNote = !empty($_FILES['book_pdf']['name']) ? ' with linked PDF' : '';
                $flashMessage = "Book [{$created['book_index']}] added successfully{$pdfNote}!";
                $flashType = 'success';
                break;

            case 'upload_pdf':
                $bookId = (int) ($_POST['book_id'] ?? 0);
                $fileName = $catalogueService->attachOrReplacePdf($bookId, $_FILES['replacement_pdf'] ?? []);
                $flashMessage = "PDF ({$fileName}) linked to book successfully!";
                $flashType = 'success';
                break;

            case 'remove_pdf':
                $bookId = (int) ($_POST['book_id'] ?? 0);
                $catalogueService->removePdf($bookId);
                $flashMessage = "Digital PDF unlinked and removed from server.";
                $flashType = 'success';
                break;

            case 'toggle_delete':
                $bookId    = (int) ($_POST['book_id'] ?? 0);
                $isDeleted = ($_POST['is_deleted'] ?? '0') === '1';
                $catalogueService->setDeletedStatus($bookId, $isDeleted);
                $flashMessage = $isDeleted ? "Book soft-deleted from public catalog." : "Book restored to public catalog.";
                $flashType = 'success';
                break;

            case 'approve_member':
                $targetId = (int) ($_POST['member_id'] ?? 0);
                if ($librarianService && method_exists($librarianService, 'approveMember')) {
                    $librarianService->approveMember($targetId);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE members 
                        SET account_status = 'Active', 
                            expiry_date = COALESCE(expiry_date, CURRENT_DATE + INTERVAL '1 year')
                        WHERE member_id = :member_id
                    ");
                    $stmt->execute([':member_id' => $targetId]);
                }
                $flashMessage = "Member account approved and activated.";
                $flashType = 'success';
                break;

            case 'reject_member':
                $targetId = (int) ($_POST['member_id'] ?? 0);
                if ($librarianService && method_exists($librarianService, 'rejectMember')) {
                    $librarianService->rejectMember($targetId);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE members 
                        SET account_status = 'Rejected' 
                        WHERE member_id = :member_id
                    ");
                    $stmt->execute([':member_id' => $targetId]);
                }
                $flashMessage = "Member registration rejected.";
                $flashType = 'success';
                break;

            case 'return_book':
                $borrowingId = (int) ($_POST['borrowing_id'] ?? 0);
                if ($librarianService && method_exists($librarianService, 'processBookReturn')) {
                    $librarianService->processBookReturn($borrowingId);
                } else {
                    // Atomic Module 0.3.3 Book Return Transaction
                    $pdo->beginTransaction();
                    try {
                        $loanStmt = $pdo->prepare("
                            SELECT borrowing_id, book_id, due_date 
                            FROM borrowings 
                            WHERE borrowing_id = :borrowing_id AND loan_status = 'Issued' 
                            FOR UPDATE
                        ");
                        $loanStmt->execute([':borrowing_id' => $borrowingId]);
                        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$loan) {
                            throw new RuntimeException('Active loan record not found or already returned.');
                        }

                        // Calculate final overdue fine (₹10/day)
                        $today = new DateTimeImmutable('today');
                        $dueDate = new DateTimeImmutable(date('Y-m-d', strtotime($loan['due_date'])));
                        $fine = ($today > $dueDate) ? ($dueDate->diff($today)->days * 10) : 0;

                        $updLoan = $pdo->prepare("
                            UPDATE borrowings 
                            SET loan_status = 'Returned', 
                                return_date = CURRENT_DATE, 
                                fine_amount = :fine 
                            WHERE borrowing_id = :borrowing_id
                        ");
                        $updLoan->execute([
                            ':fine'         => $fine,
                            ':borrowing_id' => $borrowingId,
                        ]);

                        $updBook = $pdo->prepare("
                            UPDATE books 
                            SET available_copies = LEAST(total_copies, available_copies + 1) 
                            WHERE book_id = :book_id
                        ");
                        $updBook->execute([':book_id' => $loan['book_id']]);

                        $pdo->commit();
                    } catch (Throwable $txErr) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $txErr;
                    }
                }
                $flashMessage = "Book returned and available copies updated.";
                $flashType = 'success';
                break;
        }
    } catch (Throwable $e) {
        $flashMessage = 'Error: ' . $e->getMessage();
        $flashType = 'error';
    }
}

// 3. Fetch Workbench Data
$searchQuery = trim($_GET['q'] ?? '');
$books       = $catalogueService->searchBooks($searchQuery, true);

// Precompute next index preview per genre for JS
$nextIndexMap = [];
foreach (array_keys(CatalogueService::GENRE_PREFIXES) as $genreName) {
    $nextIndexMap[$genreName] = $catalogueService->generateNextBookIndex($genreName);
}

// Pending members (D1)
$pendingMembers = $pdo->query("
    SELECT member_id, full_name, email, phone_number, created_at 
    FROM members 
    WHERE account_status = 'Pending' 
    ORDER BY created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Active Issued Loans (D3)
$activeLoans = $pdo->query("
    SELECT br.borrowing_id, br.issue_date, br.due_date, m.full_name, m.email, bk.book_index, bk.title
    FROM borrowings br
    JOIN members m ON br.member_id = m.member_id
    JOIN books bk ON br.book_id = bk.book_id
    WHERE br.loan_status = 'Issued' AND br.return_date IS NULL
    ORDER BY br.due_date ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Workbench — The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <nav class="top-nav">
        <a href="index.php" class="brand">The Reading Room — Staff Workbench</a>
        <div class="nav-links">
            <a href="index.php">Public Catalog</a>
            <a href="my_loans.php">My Loans</a>
            <a href="admin_dashboard.php" class="active">Workbench (<?= htmlspecialchars($role) ?>)</a>
            <a href="index.php">Logout</a>
        </div>
    </nav>

    <main class="container">

        <?php if ($flashMessage !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($flashType) ?>">
                <?= htmlspecialchars($flashMessage) ?>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: ADD NEW BOOK & ATTACH PDF -->
        <section class="card">
            <h2>Add New Book &amp; Link Digital PDF</h2>
            <form method="POST" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="add_book">

                <div class="form-group">
                    <label for="isbn">ISBN *</label>
                    <div class="isbn-row">
                        <input type="text" id="isbn" name="isbn" placeholder="e.g. 9780140449136" required>
                        <button type="button" class="btn btn-outline btn-sm" onclick="fetchIsbnMetadata()">Auto-Fill</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="title">Book Title *</label>
                    <input type="text" id="title" name="title" required>
                </div>

                <div class="form-group">
                    <label for="author">Author *</label>
                    <input type="text" id="author" name="author" required>
                </div>

                <div class="form-group">
                    <label for="publisher">Publisher</label>
                    <input type="text" id="publisher" name="publisher">
                </div>

                <div class="form-group">
                    <label for="genre_category">
                        Genre Category &bull; Auto Index: <span id="indexPreview" class="index-preview">CS-01</span>
                    </label>
                    <select id="genre_category" name="genre_category" onchange="updateIndexPreview()">
                        <?php foreach (array_keys(CatalogueService::GENRE_PREFIXES) as $genre): ?>
                            <option value="<?= htmlspecialchars($genre) ?>"><?= htmlspecialchars($genre) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="publication_year">Publication Year</label>
                    <input type="number" id="publication_year" name="publication_year" min="1000" max="2099" placeholder="2024">
                </div>

                <div class="form-group">
                    <label for="total_copies">Total Copies *</label>
                    <input type="number" id="total_copies" name="total_copies" value="3" min="1" required>
                </div>

                <div class="pdf-upload-box">
                    <div>
                        <strong class="pdf-upload-title">Digital Edition (Optional PDF):</strong>
                        <div class="pdf-upload-desc">
                            Upload a .pdf file now so borrowers can read it directly.
                        </div>
                    </div>
                    <input type="file" name="book_pdf" accept="application/pdf,.pdf">
                    <button type="submit" class="btn">+ Add Book to Catalog</button>
                </div>
            </form>
        </section>

        <!-- SECTION 2: MANAGE CATALOG & LINK/REPLACE PDFs ON EXISTING BOOKS -->
        <section class="card">
            <div class="catalog-header-bar">
                <h2 class="catalog-heading">Catalog Books &amp; Linked Digital PDFs</h2>
                <form method="GET" class="catalog-search-form">
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search title, index, ISBN..." class="catalog-search-input">
                    <button type="submit" class="btn btn-sm">Search</button>
                    <?php if ($searchQuery !== ''): ?>
                        <a href="admin_dashboard.php" class="btn btn-outline btn-sm">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Index</th>
                            <th>Title &amp; Author</th>
                            <th>Copies</th>
                            <th>Digital PDF Status</th>
                            <th>Upload / Replace PDF</th>
                            <th>Catalog Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books as $bk): ?>
                            <tr class="<?= $bk['is_deleted'] ? 'row-deleted' : '' ?>">
                                <td><code><strong><?= htmlspecialchars($bk['book_index']) ?></strong></code></td>
                                <td>
                                    <strong><?= htmlspecialchars($bk['title']) ?></strong><br>
                                    <span class="text-muted-sm">
                                        <?= htmlspecialchars($bk['author']) ?> &bull; <?= htmlspecialchars($bk['genre_category']) ?>
                                    </span>
                                </td>
                                <td><?= (int) $bk['available_copies'] ?> / <?= (int) $bk['total_copies'] ?></td>
                                <td>
                                    <?php if (!empty($bk['pdf_path'])): ?>
                                        <span class="badge-pdf">PDF Linked</span><br>
                                        <div class="pdf-action-row">
                                            <a href="read_book.php?book_id=<?= (int) $bk['book_id'] ?>" target="_blank" class="btn btn-outline btn-sm">
                                                Preview PDF
                                            </a>
                                            <form method="POST" class="form-inline" onsubmit="return confirm('Unlink and delete this PDF file?');">
                                                <input type="hidden" name="action" value="remove_pdf">
                                                <input type="hidden" name="book_id" value="<?= (int) $bk['book_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Unlink</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge-nopdf">No PDF Attached</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" enctype="multipart/form-data" class="inline-pdf-form">
                                        <input type="hidden" name="action" value="upload_pdf">
                                        <input type="hidden" name="book_id" value="<?= (int) $bk['book_id'] ?>">
                                        <input type="file" name="replacement_pdf" accept="application/pdf,.pdf" required>
                                        <button type="submit" class="btn btn-sm">
                                            <?= !empty($bk['pdf_path']) ? 'Replace' : 'Upload PDF' ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_delete">
                                        <input type="hidden" name="book_id" value="<?= (int) $bk['book_id'] ?>">
                                        <input type="hidden" name="is_deleted" value="<?= $bk['is_deleted'] ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm <?= $bk['is_deleted'] ? '' : 'btn-outline' ?>">
                                            <?= $bk['is_deleted'] ? 'Restore' : 'Soft-Delete' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- SECTION 3: PENDING MEMBER APPROVALS & ACTIVE RETURNS -->
        <div class="admin-two-col-grid">
            <section class="card">
                <h2>Pending Member Approvals (<?= count($pendingMembers) ?>)</h2>
                <?php if (empty($pendingMembers)): ?>
                    <p class="no-records-msg">No pending registrations.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name &amp; Email</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingMembers as $pm): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($pm['full_name']) ?></strong><br>
                                        <span class="text-muted-sm"><?= htmlspecialchars($pm['email']) ?></span>
                                    </td>
                                    <td>
                                        <form method="POST" class="form-inline">
                                            <input type="hidden" name="action" value="approve_member">
                                            <input type="hidden" name="member_id" value="<?= (int) $pm['member_id'] ?>">
                                            <button type="submit" class="btn btn-sm">Approve</button>
                                        </form>
                                        <form method="POST" class="form-inline">
                                            <input type="hidden" name="action" value="reject_member">
                                            <input type="hidden" name="member_id" value="<?= (int) $pm['member_id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Active Issued Loans (<?= count($activeLoans) ?>)</h2>
                <?php if (empty($activeLoans)): ?>
                    <p class="no-records-msg">No books currently issued.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Borrower</th>
                                <th>Due</th>
                                <th>Return</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeLoans as $al): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($al['book_index']) ?></code> <?= htmlspecialchars($al['title']) ?></td>
                                    <td><?= htmlspecialchars($al['full_name']) ?></td>
                                    <td><?= htmlspecialchars(date('M d', strtotime($al['due_date']))) ?></td>
                                    <td>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="return_book">
                                            <input type="hidden" name="borrowing_id" value="<?= (int) $al['borrowing_id'] ?>">
                                            <button type="submit" class="btn btn-sm">Return</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>

    </main>

    <script>
        const nextIndexMap = <?= json_encode($nextIndexMap) ?>;

        function updateIndexPreview() {
            const genre = document.getElementById('genre_category').value;
            document.getElementById('indexPreview').textContent = nextIndexMap[genre] || 'GN-01';
        }
        updateIndexPreview();

        async function fetchIsbnMetadata() {
            const isbnInput = document.getElementById('isbn');
            const cleanIsbn = isbnInput.value.replace(/[^0-9Xx]/g, '');
            if (!cleanIsbn) {
                alert('Enter an ISBN first.');
                return;
            }
            try {
                const res = await fetch(`https://openlibrary.org/api/books?bibkeys=ISBN:${cleanIsbn}&format=json&jscmd=data`);
                const data = await res.json();
                const bookData = data[`ISBN:${cleanIsbn}`];
                if (!bookData) {
                    alert('No book found on Open Library for this ISBN.');
                    return;
                }
                if (bookData.title) document.getElementById('title').value = bookData.title;
                if (bookData.authors && bookData.authors.length > 0) {
                    document.getElementById('author').value = bookData.authors.map(a => a.name).join(', ');
                }
                if (bookData.publishers && bookData.publishers.length > 0) {
                    document.getElementById('publisher').value = bookData.publishers[0].name;
                }
                if (bookData.publish_date) {
                    const yearMatch = bookData.publish_date.match(/\d{4}/);
                    if (yearMatch) document.getElementById('publication_year').value = yearMatch[0];
                }
            } catch (err) {
                alert('Failed to fetch metadata from Open Library.');
            }
        }
    </script>

</body>
</html>