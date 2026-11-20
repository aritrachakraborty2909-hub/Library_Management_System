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

// Role & Borrowing Permissions (driven directly by SQL role in session)
$workbenchRoles     = ['Cataloger', 'System Manager'];
$staffRoles         = ['Librarian', 'Cataloger', 'System Manager'];
$canAccessWorkbench = isset($_SESSION['role']) && in_array($_SESSION['role'], $workbenchRoles, true);
$isStaff            = isset($_SESSION['role']) && in_array($_SESSION['role'], $staffRoles, true);
$isLibrarian        = isset($_SESSION['role']) && $_SESSION['role'] === 'Librarian';
$canBorrow          = isset($_SESSION['member_id']) && !$isStaff;

// Fetch logged-in regular member's wishlist book IDs & active borrowed book IDs
$wishlistedIds     = [];
$activeBorrowedIds = [];

if ($canBorrow) {
    $currentMemberId = (int)$_SESSION['member_id'];

    // Wishlist IDs
    $wStmt = $pdo->prepare("SELECT book_id FROM wishlist WHERE member_id = :mid");
    $wStmt->execute([':mid' => $currentMemberId]);
    $wishlistedIds = array_map('intval', $wStmt->fetchAll(PDO::FETCH_COLUMN));

    // Active Borrowed Book IDs (only for THIS logged-in member)
    $bStmt = $pdo->prepare("
        SELECT DISTINCT book_id 
        FROM borrowings 
        WHERE member_id = :mid 
          AND return_date IS NULL
          AND loan_status IN ('Pending', 'Issued')
    ");
    $bStmt->execute([':mid' => $currentMemberId]);
    $activeBorrowedIds = array_map('intval', $bStmt->fetchAll(PDO::FETCH_COLUMN));
}

// Group books dynamically by Genre and grab 1 featured cover ISBN per genre
$genreStacks  = [];
$allCoverIsbn = '';

foreach ($books as $b) {
    $isbn = trim($b['isbn'] ?? '');
    if ($allCoverIsbn === '' && $isbn !== '') {
        $allCoverIsbn = $isbn;
    }

    $gName = trim($b['genre_category'] ?? '');
    if ($gName === '') {
        $gName = 'General';
    }
    if (!isset($genreStacks[$gName])) {
        $genreStacks[$gName] = [
            'name'  => $gName,
            'cover' => ''
        ];
    }
    if ($genreStacks[$gName]['cover'] === '' && $isbn !== '') {
        $genreStacks[$gName]['cover'] = $isbn;
    }
}
ksort($genreStacks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Reading Room | Library Management System</title>
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

<nav class="navbar">
    <div class="brand">THE READING ROOM</div>
    <div>
        <a href="index.php">Browse Books</a>
        <?php if ($canBorrow): ?>
            <a href="my_loans.php">My Loans</a>
        <?php endif; ?>
        <?php if ($canAccessWorkbench): ?>
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
                <span><?= htmlspecialchars($_SESSION['full_name']) ?> (<?= htmlspecialchars($_SESSION['role'] ?? 'Member') ?>)</span>
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
    <!-- Two-Column Catalog Workspace: Left Genre Shelf Rail + Right Bookshelf -->
    <div class="catalog-stacks-layout">

        <!-- LEFT SIDEBAR: Genre Book-Cover Background Buttons -->
        <aside class="stacks-sidebar" aria-label="Browse by Genre">
            <div class="stacks-sidebar-header">
                <span class="stacks-eyebrow">CURATED AISLES</span>
                <h3>Explore the Shelves</h3>
            </div>

            <div class="stacks-list" id="stacks-list">
                <!-- Default "All Books" Button with Featured Book Cover Background -->
                <?php $allBg = $allCoverIsbn !== '' ? "https://covers.openlibrary.org/b/isbn/" . htmlspecialchars($allCoverIsbn) . "-M.jpg" : ""; ?>
                <button type="button"
                        class="stack-item stack-cover-btn is-active"
                        data-genre="all"
                        <?= $allBg !== '' ? 'style="background-image: url(\'' . $allBg . '\');"' : '' ?>>
                    <span class="stack-aisle-code">00</span>
                    <div class="stack-meta">
                        <span class="stack-title">All Books</span>
                        <span class="stack-sub">Complete Catalog</span>
                    </div>
                    <span class="stack-arrow" aria-hidden="true">&rarr;</span>
                </button>

                <!-- Dynamic Genre Buttons with Genre Book Cover Background -->
                <?php $aisleNum = 1; ?>
                <?php foreach ($genreStacks as $gKey => $stack): ?>
                    <?php $coverUrl = $stack['cover'] !== '' ? "https://covers.openlibrary.org/b/isbn/" . htmlspecialchars($stack['cover']) . "-M.jpg" : ""; ?>
                    <button type="button"
                            class="stack-item stack-cover-btn"
                            data-genre="<?= htmlspecialchars(strtolower($gKey)) ?>"
                            <?= $coverUrl !== '' ? 'style="background-image: url(\'' . $coverUrl . '\');"' : '' ?>>
                        <span class="stack-aisle-code"><?= str_pad((string)$aisleNum++, 2, '0', STR_PAD_LEFT) ?></span>
                        <div class="stack-meta">
                            <span class="stack-title"><?= htmlspecialchars($stack['name']) ?></span> 
                        </div>
                        <span class="stack-arrow" aria-hidden="true">&rarr;</span>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- RIGHT MAIN SHELF AREA -->
        <section class="stacks-shelf-area">
            <!-- Editorial Shelf Banner -->
            <div class="shelf-header-card">
                <div>
                    <span class="shelf-eyebrow" id="shelf-eyebrow">AISLE 00 // COMPLETE COLLECTION</span>
                    <h2 id="shelf-heading">
                        <?= $searchQuery !== '' 
                            ? "Search Results for '" . htmlspecialchars($searchQuery) . "'" 
                            : "All Stacks & Trending Books" ?>
                    </h2>
                </div>

                <!-- Quick Filter Pills inside the Shelf Header -->
                <div class="shelf-filter-pills">
                    <button type="button" class="shelf-pill is-active" data-avail="all">All Editions</button>
                    <button type="button" class="shelf-pill" data-avail="available">Available Now</button>
                    <?php if ($canBorrow): ?>
                        <button type="button" class="shelf-pill" data-avail="wishlist">Saved &#9829;</button>
                    <?php endif; ?>
                </div>
            </div>

            <p id="no-match-msg" class="no-match-msg<?= empty($books) ? ' is-visible' : '' ?>">
                No books matched this shelf or filter. Try selecting another genre or clearing your search.
            </p>

            <?php if (!empty($books)): ?>
                <div class="book-grid catalog-4col" id="book-grid">
                    <?php foreach ($books as $book): ?>
                        <?php
                            $bookId     = (int)$book['book_id'];
                            $rawGenre   = trim($book['genre_category'] ?? 'General');
                            $searchBlob = strtolower(
                                ($book['title'] ?? '') . ' ' .
                                ($book['author'] ?? '') . ' ' .
                                ($book['isbn'] ?? '') . ' ' .
                                $rawGenre . ' ' .
                                ($book['publisher'] ?? '') . ' ' .
                                ($book['book_index'] ?? '') . ' ' .
                                ($book['publication_year'] ?? '')
                            );

                            $isBookAvailable  = ($book['available_copies'] > 0 && !empty($book['pdf_path']));
                            $inWishlist       = in_array($bookId, $wishlistedIds, true);
                            $isBorrowedByUser = in_array($bookId, $activeBorrowedIds, true);
                        ?>
                        <div class="book-card"
                             data-search="<?= htmlspecialchars($searchBlob) ?>"
                             data-genre="<?= htmlspecialchars(strtolower($rawGenre)) ?>"
                             data-genre-label="<?= htmlspecialchars($rawGenre) ?>"
                             data-available="<?= $isBookAvailable ? '1' : '0' ?>"
                             data-wishlist="<?= $inWishlist ? '1' : '0' ?>">
                            <div style="position: relative;">
                                <?php if ($canBorrow): ?>
                                    <form method="POST" action="wishlist_action.php" class="wishlist-heart-form">
                                        <input type="hidden" name="book_id" value="<?= $bookId ?>">
                                        <input type="hidden" name="redirect" value="index.php">
                                        <button type="submit" class="wishlist-heart-btn <?= $inWishlist ? 'is-saved' : '' ?>" title="<?= $inWishlist ? 'Remove from Wishlist' : 'Add to Wishlist' ?>">
                                            <?= $inWishlist ? '&#9829;' : '&#9825;' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <img class="book-cover" 
                                     src="https://covers.openlibrary.org/b/isbn/<?= htmlspecialchars($book['isbn']) ?>-M.jpg" 
                                     alt="Book Cover">
                                
                                <span class="card-genre-chip"><?= htmlspecialchars($rawGenre) ?></span>
                                <h3><?= htmlspecialchars($book['title']) ?></h3>
                                <p class="book-author">by <?= htmlspecialchars($book['author']) ?></p>
                                <p class="book-publisher">
                                    <?= !empty($book['publication_year']) ? 'Published ' . (int)$book['publication_year'] : 'Library Edition' ?>
                                </p>   
                                <p class="book-meta-info">
                                    Index: <strong><?= htmlspecialchars($book['book_index']) ?></strong> &bull; ISBN: <?= htmlspecialchars($book['isbn']) ?>
                                </p>

                                <?php if ($isBookAvailable): ?>
                                    <?php if ($isStaff): ?>
                                        <span class="badge available">Available (<?= (int)$book['available_copies'] ?>/<?= (int)$book['total_copies'] ?>)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge unavailable">Currently Unavailable</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isLibrarian): ?>
                                <?php if (!empty($book['pdf_path'])): ?>
                                    <div class="borrow-form">
                                        <a href="read_book.php?book_id=<?= $bookId ?>" class="btn btn-full-width">Read Book</a>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($canBorrow): ?>
                                <?php if ($isBorrowedByUser): ?>
                                    <div class="borrow-form">
                                        <button type="button" class="btn btn-full-width btn-borrowed" disabled>Borrowed</button>
                                    </div>
                                <?php elseif ($isBookAvailable): ?>
                                    <form method="POST" action="borrow_action.php" class="borrow-form">
                                        <input type="hidden" name="book_id" value="<?= $bookId ?>">
                                        <button type="submit" class="btn btn-full-width">Borrow Book</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif (!isset($_SESSION['member_id']) && $isBookAvailable): ?>
                                <a href="login.php" class="btn btn-login-borrow">Log in to Borrow</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
  const searchInput   = document.getElementById('catalog-search-input');
  const bookCards     = Array.from(document.querySelectorAll('.book-card'));
  const stackButtons  = Array.from(document.querySelectorAll('.stack-item'));
  const availPills    = Array.from(document.querySelectorAll('.shelf-pill'));
  const noMatchMsg    = document.getElementById('no-match-msg');
  const shelfHeading  = document.getElementById('shelf-heading');
  const shelfEyebrow  = document.getElementById('shelf-eyebrow');

  let activeGenre = 'all';
  let activeAvail = 'all';

  function applyCatalogFilters() {
    const words = searchInput
      ? searchInput.value.toLowerCase().trim().split(/\s+/).filter(Boolean)
      : [];

    let visibleCount = 0;

    bookCards.forEach(card => {
      const haystack   = card.dataset.search || '';
      const cardGenre  = card.dataset.genre || '';
      const isAvail    = card.dataset.available === '1';
      const isWishlist = card.dataset.wishlist === '1';

      const matchesSearch = words.every(word => haystack.includes(word));
      const matchesGenre  = (activeGenre === 'all' || cardGenre === activeGenre);
      const matchesAvail  = (activeAvail === 'all') ||
                            (activeAvail === 'available' && isAvail) ||
                            (activeAvail === 'wishlist' && isWishlist);

      const show = matchesSearch && matchesGenre && matchesAvail;
      card.style.display = show ? 'flex' : 'none';
      if (show) visibleCount++;
    });

    if (noMatchMsg) noMatchMsg.style.display = (visibleCount === 0) ? 'block' : 'none';
  }

  // Left Sidebar Shelf Selection
  stackButtons.forEach((btn, index) => {
    btn.addEventListener('click', () => {
      stackButtons.forEach(b => b.classList.remove('is-active'));
      btn.classList.add('is-active');

      activeGenre = btn.dataset.genre;
      const titleText = btn.querySelector('.stack-title').textContent;

      if (shelfEyebrow) {
        shelfEyebrow.textContent = activeGenre === 'all'
          ? 'AISLE 00 // COMPLETE COLLECTION'
          : `AISLE ${String(index).padStart(2, '0')} // GENRE SHELF`;
      }
      if (shelfHeading) {
        shelfHeading.textContent = activeGenre === 'all'
          ? 'All Stacks & Trending Books'
          : titleText;
      }

      applyCatalogFilters();
    });
  });

  // Top Shelf Availability / Saved Pills
  availPills.forEach(pill => {
    pill.addEventListener('click', () => {
      availPills.forEach(p => p.classList.remove('is-active'));
      pill.classList.add('is-active');
      activeAvail = pill.dataset.avail;
      applyCatalogFilters();
    });
  });

  // Live Search Input
  if (searchInput && bookCards.length > 0) {
    searchInput.addEventListener('input', applyCatalogFilters);
  }
</script>

</body>
</html>