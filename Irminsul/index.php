<?php
// Load database connection, Catalogue service (0.2), and Circulation service (0.3)
require_once 'config/db.php';
require_once 'services/CatalogueService.php';
require_once 'services/CirculationService.php';

// Handle Logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

$feedback = '';
$feedbackSuccess = false;

// Handle Borrow Book button click (Module 0.3.1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'borrow') {
    if (!isset($_SESSION['member_id'])) {
        header("Location: auth.php");
        exit;
    }

    $bookId = (int)($_POST['book_id'] ?? 0);
    $result = borrowBook($pdo, (int)$_SESSION['member_id'], $bookId);
    $feedback = $result['message'];
    $feedbackSuccess = $result['success'];
}

// Get search keyword from URL (e.g., index.php?q=Dune)
$searchQuery = $_GET['q'] ?? '';

// Call Module 0.2.1 (Search Book)
$books = searchBooks($pdo, $searchQuery);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Open Library | Library Management System</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<!-- Top Navigation -->
<nav class="navbar">
    <div class="brand">Open Library</div>
    <div>
        <a href="index.php">Browse Books</a>
        <?php if (isset($_SESSION['member_id'])): ?>
            <a href="my_loans.php">My Loans</a>
            <?php if (in_array($_SESSION['role'] ?? '', ['Librarian', 'Cataloger', 'System Manager'], true)): ?>
                <a href="admin_dashboard.php" style="color: #ffd166; font-weight: bold;">Staff Dashboard</a>
            <?php endif; ?>
            <span style="margin-left: 20px;">Hello, <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong> (<?= htmlspecialchars($_SESSION['role']) ?>)</span>
            <a href="index.php?action=logout">Logout</a>
        <?php else: ?>
            <a href="login.php">Log In / Sign Up</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Hero Search Section -->
<section class="hero">
    <h1>Welcome to Open Library</h1>
    <p>Search millions of records, check shelf availability, and borrow books.</p>
    
    <form class="search-form" method="GET" action="index.php">
        <input type="text" name="q" placeholder="Search by Title, Author, ISBN, or Subject..." value="<?= htmlspecialchars($searchQuery) ?>">
        <button type="submit" class="btn">Search</button>
    </form>
</section>

<!-- Main Book Catalog Grid -->
<main class="container">

    <!-- Borrow Status Banner -->
    <?php if ($feedback): ?>
        <div style="padding: 12px 18px; margin-bottom: 20px; border-radius: 5px; font-weight: bold;
                    background: <?= $feedbackSuccess ? '#d4edda' : '#f8d7da' ?>;
                    color: <?= $feedbackSuccess ? '#155724' : '#721c24' ?>;">
            <?= htmlspecialchars($feedback) ?>
            <?php if ($feedbackSuccess): ?>
                &mdash; <a href="my_loans.php" style="color: inherit; text-decoration: underline;">View My Loans</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2><?= $searchQuery ? "Search Results for '" . htmlspecialchars($searchQuery) . "'" : "Trending & Available Books" ?></h2>

    <?php if (empty($books)): ?>
        <p style="margin-top:20px;">No books matched your search.</p>
    <?php else: ?>
        <div class="book-grid">
            <?php foreach ($books as $book): ?>
                <div class="book-card">
                    <div>
                        <img class="book-cover" 
                             src="https://covers.openlibrary.org/b/isbn/<?= htmlspecialchars($book['isbn']) ?>-M.jpg" 
                             alt="Book Cover">
                        
                        <h3><?= htmlspecialchars($book['title']) ?></h3>
                        <p style="color:#666; font-size:0.9rem;">by <?= htmlspecialchars($book['author']) ?></p>
                        <p style="font-size:0.8rem; margin-top:4px;">Rack: <strong><?= htmlspecialchars($book['rack_location']) ?></strong></p>

                        <?php if ($book['available_copies'] > 0): ?>
                            <span class="badge available">Available (<?= $book['available_copies'] ?>/<?= $book['total_copies'] ?>)</span>
                        <?php else: ?>
                            <span class="badge unavailable">Checked Out</span>
                        <?php endif; ?>
                    </div>

                    <!-- Borrow Button (Connected to Module 0.3.1) -->
                    <?php if (isset($_SESSION['member_id'])): ?>
                        <?php if ($book['available_copies'] > 0): ?>
                            <form method="POST" action="index.php<?= $searchQuery ? '?q=' . urlencode($searchQuery) : '' ?>" style="margin-top:10px;">
                                <input type="hidden" name="action" value="borrow">
                                <input type="hidden" name="book_id" value="<?= $book['book_id'] ?>">
                                <button type="submit" class="btn" style="width:100%;">Borrow Book</button>
                            </form>
                        <?php else: ?>
                            <button class="btn" style="width:100%; margin-top:10px; background:#aaa; cursor:not-allowed;" disabled>Out of Stock</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="auth.php" class="btn" style="width:100%; text-align:center; margin-top:10px; background:#555;">Log in to Borrow</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>