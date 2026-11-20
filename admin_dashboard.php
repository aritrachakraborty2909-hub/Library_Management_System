<?php
// admin_dashboard.php
// Staff Workbench: Add Books + PDF Upload, Link/Replace PDFs on Existing Books & Soft-Delete
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/CatalogueService.php';

// 1. Verify Staff Authorization
$staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
if (!isset($_SESSION['member_id']) || !in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    header('Location: login.php');
    exit;
}

$role             = $_SESSION['role'];
$catalogueService = new CatalogueService($pdo);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Workbench — The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css?v=13">
    <script>
      (function() {
        const savedTheme = localStorage.getItem('rr_theme') || 'default';
        if (savedTheme !== 'default') {
          document.documentElement.setAttribute('data-theme', savedTheme);
        }
      })();
    </script>
</head>
<body>

    <nav class="top-nav">
        <a href="index.php" class="brand">The Reading Room — Staff Workbench</a>
        <div class="nav-links">
            <a href="index.php">Public Catalog</a>
            <a href="admin_dashboard.php" class="active">Workbench (<?= htmlspecialchars($role === 'Librarian' ? 'Cataloger' : $role) ?>)</a>
            <a href="index.php?action=logout">Back to Dashboard</a>
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