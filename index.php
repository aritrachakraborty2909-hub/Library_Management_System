<?php
require_once 'config/db.php';
require_once 'services/CatalogueService.php';

// Handle Logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Read search keyword from URL (?q=...)
$searchQuery = trim($_GET['q'] ?? '');

// Call Module 0.2.1
$books = searchBooks($pdo, $searchQuery);

// Fetch logged-in user's wishlist book IDs so we can highlight saved hearts
$wishlistedIds = [];
if (isset($_SESSION['member_id'])) {
    $wStmt = $pdo->prepare("SELECT book_id FROM wishlist WHERE member_id = :mid");
    $wStmt->execute([':mid' => (int)$_SESSION['member_id']]);
    $wishlistedIds = array_map('intval', $wStmt->fetchAll(PDO::FETCH_COLUMN));
}

$staffRoles = ['Librarian', 'Cataloger'];
$isStaff = isset($_SESSION['role']) && in_array($_SESSION['role'], $staffRoles, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Reading Room | Library Management System</title>
    <link rel="stylesheet" href="assets/style.css?v=9">
    <script>
      // Apply saved theme before paint to prevent flash
      (function() {
        const savedTheme = localStorage.getItem('rr_theme') || 'default';
        if (savedTheme !== 'default') {
          document.documentElement.setAttribute('data-theme', savedTheme);
        }
      })();
    </script>
</head>
<body>

<nav class="navbar">
    <div class="brand">THE READING ROOM</div>
    <div>
        <a href="index.php">Browse Books</a>
        <?php if (isset($_SESSION['member_id'])): ?>
            <a href="my_loans.php">My Loans</a>
        <?php endif; ?>
        <?php if ($isStaff): ?>
            <a href="admin_dashboard.php" class="nav-staff-link">Staff Dashboard</a>
        <?php endif; ?>

        <?php if (isset($_SESSION['member_id'])): ?>
            <?php
                $navInitial  = strtoupper(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1));
                $sessionPic  = $_SESSION['profile_pic'] ?? '';
                $hasValidPic = $sessionPic !== '' && (str_starts_with($sessionPic, 'http://') || str_starts_with($sessionPic, 'https://') || file_exists(__DIR__ . '/' . $sessionPic));
            ?>
            <a href="profile.php" class="nav-profile-pill" title="View & Edit Profile">
                <?php if ($hasValidPic): ?>
                    <img src="<?= htmlspecialchars($sessionPic) ?>" alt="Profile" class="nav-avatar">
                <?php else: ?>
                    <span class="nav-avatar-fallback"><?= htmlspecialchars($navInitial) ?></span>
                <?php endif; ?>
                <span><?= htmlspecialchars($_SESSION['full_name']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</span>
            </a>
            <a href="index.php?action=logout">Logout</a>
        <?php else: ?>
            <a href="login.php">Log In</a>
            <a href="register.php" class="btn btn-signup">Sign Up</a>
        <?php endif; ?>
    </div>
</nav>

<section class="hero">
    <h1>Welcome to The Reading Room</h1>
    <p>Search millions of records, check shelf availability, and borrow books.</p>
    
    <form class="search-form" method="GET" action="index.php">
        <input type="text" 
               id="catalog-search-input"
               name="q" 
               placeholder="Search by Title, Author, ISBN, Genre, Publisher, or Book Index..." 
               value="<?= htmlspecialchars($searchQuery) ?>">
        <button type="submit" class="btn">Search</button>
        <?php if ($searchQuery !== ''): ?>
            <a href="index.php" class="btn btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</section>

<main class="container">
    <h2 id="shelf-heading">
        <?= $searchQuery !== '' 
            ? "Search Results for '" . htmlspecialchars($searchQuery) . "' (" . count($books) . " found)" 
            : "Trending & Available Books" ?>
    </h2>

    <p id="no-match-msg" class="no-match-msg<?= empty($books) ? ' is-visible' : '' ?>">
        No books matched your search. Try another title, author, ISBN, or genre.
    </p>

    <?php if (!empty($books)): ?>
        <div class="book-grid" id="book-grid">
            <?php foreach ($books as $book): ?>
                <?php
                    $searchBlob = strtolower(
                        ($book['title'] ?? '') . ' ' .
                        ($book['author'] ?? '') . ' ' .
                        ($book['isbn'] ?? '') . ' ' .
                        ($book['genre_category'] ?? '') . ' ' .
                        ($book['publisher'] ?? '') . ' ' .
                        ($book['book_index'] ?? '') . ' ' .
                        ($book['publication_year'] ?? '')
                    );

                    $isBookAvailable = ($book['available_copies'] > 0 && !empty($book['pdf_path']));
                    $inWishlist      = in_array((int)$book['book_id'], $wishlistedIds, true);
                ?>
                <div class="book-card" data-search="<?= htmlspecialchars($searchBlob) ?>">
                    <div style="position: relative;">
                        <?php if (isset($_SESSION['member_id'])): ?>
                            <form method="POST" action="wishlist_action.php" class="wishlist-heart-form">
                                <input type="hidden" name="book_id" value="<?= (int)$book['book_id'] ?>">
                                <input type="hidden" name="redirect" value="index.php">
                                <button type="submit" class="wishlist-heart-btn <?= $inWishlist ? 'is-saved' : '' ?>" title="<?= $inWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' ?>">
                                    <?= $inWishlist ? '&#9829;' : '&#9825;' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <img class="book-cover" 
                             src="https://covers.openlibrary.org/b/isbn/<?= htmlspecialchars($book['isbn']) ?>-M.jpg" 
                             alt="Book Cover">
                        
                        <h3><?= htmlspecialchars($book['title']) ?></h3>
                        <p class="book-author">by <?= htmlspecialchars($book['author']) ?></p>
                        <p class="book-publisher">
                            <?= htmlspecialchars($book['genre_category']) ?>
                            <?= !empty($book['publication_year']) ? ' &bull; ' . (int)$book['publication_year'] : '' ?>
                        </p>   
                        <p class="book-meta-info">
                            Book Index: <strong><?= htmlspecialchars($book['book_index']) ?></strong><br>
                            ISBN: <?= htmlspecialchars($book['isbn']) ?>
                        </p>

                        <?php if ($isBookAvailable): ?>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Librarian'): ?>
                                <span class="badge available">Available (<?= (int)$book['available_copies'] ?>/<?= (int)$book['total_copies'] ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge unavailable">Currently Unavailable</span>
                        <?php endif; ?>
                    </div>

                    <?php if (isset($_SESSION['member_id']) && $isBookAvailable): ?>
                        <form method="POST" action="borrow_action.php" class="borrow-form">
                            <input type="hidden" name="book_id" value="<?= (int)$book['book_id'] ?>">
                            <button type="submit" class="btn btn-full-width">Borrow Book</button>
                        </form>
                    <?php elseif (!isset($_SESSION['member_id']) && $isBookAvailable): ?>
                        <a href="login.php" class="btn btn-login-borrow">Log in to Borrow</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<script>
  const searchInput = document.getElementById('catalog-search-input');
  const bookCards   = Array.from(document.querySelectorAll('.book-card'));
  const noMatchMsg  = document.getElementById('no-match-msg');

  if (searchInput && bookCards.length > 0) {
    searchInput.addEventListener('input', () => {
      const words = searchInput.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
      let visibleCount = 0;

      bookCards.forEach(card => {
        const haystack = card.dataset.search || '';
        const isMatch = words.every(word => haystack.includes(word));
        card.style.display = isMatch ? 'flex' : 'none';
        if (isMatch) visibleCount++;
      });

      noMatchMsg.style.display = (visibleCount === 0) ? 'block' : 'none';
    });
  }
</script>

</body>
</html>