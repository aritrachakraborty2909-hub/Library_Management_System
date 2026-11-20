<?php
require_once 'config/db.php';

// 1. Verify Authentication
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$memberId   = (int) $_SESSION['member_id'];
$successMsg = '';
$errorMsg   = '';

// Curated Preset Default Avatars
$presetAvatars = [
    'scholar' => [
        'label' => 'Classic Scholar',
        'url'   => 'https://api.dicebear.com/9.x/notionists/svg?seed=Scholar&backgroundColor=d9b97e'
    ],
    'owl' => [
        'label' => 'Night Reader',
        'url'   => 'https://api.dicebear.com/9.x/bottts-neutral/svg?seed=MidnightOwl&backgroundColor=1e3a5f'
    ],
    'poet' => [
        'label' => 'Editorial Poet',
        'url'   => 'https://api.dicebear.com/9.x/lorelei/svg?seed=Poet&backgroundColor=e6dcc8'
    ],
    'archivist' => [
        'label' => 'Archivist',
        'url'   => 'https://api.dicebear.com/9.x/adventurer/svg?seed=Archivist&backgroundColor=c7d2fe'
    ],
    'fox' => [
        'label' => 'Kitsune Reader',
        'url'   => 'https://api.dicebear.com/9.x/thumbs/svg?seed=Kitsune&backgroundColor=fed7aa'
    ]
];

// 2. Fetch current member details
$stmt = $pdo->prepare("
    SELECT member_id, full_name, email, phone_number, role, account_status, expiry_date, max_borrowing_limit, profile_pic
    FROM members
    WHERE member_id = :id
    LIMIT 1
");
$stmt->execute([':id' => $memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    session_destroy();
    header('Location: login.php');
    exit;
}

function isValidProfilePic(?string $path): bool {
    if (empty($path)) return false;
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return true;
    return file_exists(__DIR__ . '/' . $path);
}

// 3. Handle Profile Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName       = trim($_POST['full_name'] ?? '');
    $phoneNumber    = trim($_POST['phone_number'] ?? '');
    $selectedPreset = trim($_POST['selected_preset'] ?? '');
    $profilePicPath = $member['profile_pic'];

    if ($fullName === '') {
        $errorMsg = 'Full name cannot be empty.';
    } else {
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'Error uploading image. Please try again.';
            } else {
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($_FILES['profile_pic']['tmp_name']);

                if (!isset($allowedMimes[$mime])) {
                    $errorMsg = 'Only JPG, PNG, and WEBP images are allowed.';
                } elseif ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
                    $errorMsg = 'Profile image must be smaller than 2 MB.';
                } else {
                    $avatarDir = __DIR__ . '/uploads/avatars/';
                    if (!is_dir($avatarDir)) {
                        mkdir($avatarDir, 0755, true);
                    }

                    $ext      = $allowedMimes[$mime];
                    $fileName = 'avatar_' . $memberId . '_' . time() . '.' . $ext;
                    $destPath = $avatarDir . $fileName;

                    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $destPath)) {
                        if (!empty($profilePicPath) && !str_starts_with($profilePicPath, 'http') && file_exists(__DIR__ . '/' . $profilePicPath)) {
                            @unlink(__DIR__ . '/' . $profilePicPath);
                        }
                        $profilePicPath = 'uploads/avatars/' . $fileName;
                    } else {
                        $errorMsg = 'Failed to save uploaded image.';
                    }
                }
            }
        } elseif ($selectedPreset === 'initial') {
            if (!empty($profilePicPath) && !str_starts_with($profilePicPath, 'http') && file_exists(__DIR__ . '/' . $profilePicPath)) {
                @unlink(__DIR__ . '/' . $profilePicPath);
            }
            $profilePicPath = null;
        } elseif ($selectedPreset !== '' && isset($presetAvatars[$selectedPreset])) {
            if (!empty($profilePicPath) && !str_starts_with($profilePicPath, 'http') && file_exists(__DIR__ . '/' . $profilePicPath)) {
                @unlink(__DIR__ . '/' . $profilePicPath);
            }
            $profilePicPath = $presetAvatars[$selectedPreset]['url'];
        }

        if ($errorMsg === '') {
            $updateStmt = $pdo->prepare("
                UPDATE members
                SET full_name = :full_name,
                    phone_number = :phone_number,
                    profile_pic = :profile_pic
                WHERE member_id = :id
            ");
            $updateStmt->execute([
                ':full_name'    => $fullName,
                ':phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
                ':profile_pic'  => $profilePicPath,
                ':id'           => $memberId
            ]);

            $_SESSION['full_name']   = $fullName;
            $_SESSION['profile_pic'] = $profilePicPath;

            $member['full_name']    = $fullName;
            $member['phone_number'] = $phoneNumber;
            $member['profile_pic']  = $profilePicPath;

            $successMsg = 'Profile updated successfully!';
        }
    }
}

$staffRoles = ['Librarian', 'Cataloger', 'System Manager'];
$isStaff    = in_array($member['role'] ?? '', $staffRoles, true);

// 4 & 5. Fetch Loans & Wishlist only for regular Members
$borrowedBooks = [];
$wishlistBooks = [];

if (!$isStaff) {
    $loansStmt = $pdo->prepare("
        SELECT 
            br.borrowing_id,
            br.due_date,
            br.loan_status,
            bk.book_id,
            bk.title,
            bk.author,
            bk.isbn,
            bk.genre_category,
            bk.pdf_path
        FROM borrowings br
        JOIN books bk ON br.book_id = bk.book_id
        WHERE br.member_id = :mid 
          AND br.loan_status IN ('Issued', 'Pending')
        ORDER BY br.issue_date DESC, br.borrowing_id DESC
    ");
    $loansStmt->execute([':mid' => $memberId]);
    $borrowedBooks = $loansStmt->fetchAll(PDO::FETCH_ASSOC);

    $wishStmt = $pdo->prepare("
        SELECT w.wishlist_id, b.book_id, b.title, b.author, b.isbn, b.genre_category, b.available_copies, b.pdf_path
        FROM wishlist w
        JOIN books b ON w.book_id = b.book_id
        WHERE w.member_id = :mid AND b.is_deleted = FALSE
        ORDER BY w.added_at DESC
    ");
    $wishStmt->execute([':mid' => $memberId]);
    $wishlistBooks = $wishStmt->fetchAll(PDO::FETCH_ASSOC);
}

$initial     = strtoupper(mb_substr($member['full_name'], 0, 1));
$displayRole = ($member['role'] === 'Librarian') ? 'Cataloger' : $member['role'];
$maxLimit    = (int) ($member['max_borrowing_limit'] ?? 5);
$slotsLeft   = max(0, $maxLimit - count($borrowedBooks));
$hasValidPic = isValidProfilePic($member['profile_pic']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($member['full_name']) ?> — Profile | The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css?v=13">
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
        <?php if (!$isStaff): ?>
            <a href="my_loans.php">My Loans</a>
        <?php endif; ?>
        <?php if ($isStaff): ?>
            <a href="admin_dashboard.php" class="nav-staff-link">Staff Dashboard</a>
        <?php endif; ?>
        <a href="profile.php" class="nav-profile-pill" title="Edit Profile">
            <?php if ($hasValidPic): ?>
                <img src="<?= htmlspecialchars($member['profile_pic']) ?>" alt="Avatar" class="nav-avatar">
            <?php else: ?>
                <span class="nav-avatar-fallback"><?= htmlspecialchars($initial) ?></span>
            <?php endif; ?>
            <span><?= htmlspecialchars($member['full_name']) ?></span>
        </a>
        <a href="index.php?action=logout">Logout</a>
    </div>
</nav>

<main class="container" style="margin-top: 24px;">
    <?php if ($successMsg !== ''): ?>
        <div class="alert-banner alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="alert-banner alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="profile-dashboard<?= $isStaff ? ' is-staff-profile' : '' ?>">
        
        <!-- LEFT SIDEBAR -->
        <aside class="profile-sidebar">
            <!-- Top: Avatar & Role -->
            <div class="profile-avatar-center">
                <div class="profile-avatar-ring">
                    <?php if ($hasValidPic): ?>
                        <img id="avatar-preview" src="<?= htmlspecialchars($member['profile_pic']) ?>" alt="Profile Picture" class="profile-avatar-xl">
                        <div id="avatar-fallback" class="profile-avatar-xl-fallback hidden"><?= htmlspecialchars($initial) ?></div>
                    <?php else: ?>
                        <div id="avatar-fallback" class="profile-avatar-xl-fallback"><?= htmlspecialchars($initial) ?></div>
                        <img id="avatar-preview" src="" alt="Profile Picture" class="profile-avatar-xl hidden">
                    <?php endif; ?>
                    <label for="profile_pic" class="avatar-edit-badge" title="Upload Custom Photo">&#9998;</label>
                </div>

                <h2 class="profile-sidebar-name"><?= htmlspecialchars($member['full_name']) ?></h2>
                <p class="profile-sidebar-role"><?= htmlspecialchars($displayRole) ?> &bull; <?= htmlspecialchars($member['account_status']) ?></p>
            </div>

            <?php if (!$isStaff): ?>
                <div class="profile-stats-row">
                    <div>
                        <strong><?= count($borrowedBooks) ?></strong>
                        <span>Borrowed</span>
                    </div>
                    <div>
                        <strong><?= count($wishlistBooks) ?></strong>
                        <span>Wishlist</span>
                    </div>
                    <div>
                        <strong><?= $slotsLeft ?></strong>
                        <span>Slots Left</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="default-avatars-box">
                <span class="default-avatars-label">Quick Avatars</span>
                <div class="default-avatars-row">
                    <button type="button"
                            class="default-avatar-option default-avatar-initial <?= !$hasValidPic ? 'is-selected' : '' ?>"
                            onclick="selectPresetAvatar('initial', '', this)"
                            title="Use Letter Initial">
                        <?= htmlspecialchars($initial) ?>
                    </button>
                    <?php foreach ($presetAvatars as $key => $preset): ?>
                        <button type="button"
                                class="default-avatar-option <?= ($member['profile_pic'] === $preset['url']) ? 'is-selected' : '' ?>"
                                onclick="selectPresetAvatar('<?= htmlspecialchars($key) ?>', '<?= htmlspecialchars($preset['url']) ?>', this)"
                                title="<?= htmlspecialchars($preset['label']) ?>">
                            <img src="<?= htmlspecialchars($preset['url']) ?>" alt="<?= htmlspecialchars($preset['label']) ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr class="profile-divider">

            <!-- Middle: Edit Details Form -->
            <form method="POST" action="profile.php" enctype="multipart/form-data" class="profile-sidebar-form">
                <input type="file" id="profile_pic" name="profile_pic" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewAvatar(event)">
                <input type="hidden" id="selected_preset" name="selected_preset" value="">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($member['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" value="<?= htmlspecialchars($member['email']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="phone_number">Contact Number</label>
                    <input type="tel" id="phone_number" name="phone_number" value="<?= htmlspecialchars($member['phone_number'] ?? '') ?>" placeholder="Add phone number">
                </div>

                <button type="submit" class="btn btn-full-width" style="margin-top: 4px;">Save Changes</button>
            </form>

            <!-- Bottom: Appearance / Reading Themes -->
            <div class="theme-switcher-box">
                <hr class="profile-divider">
                <span class="default-avatars-label" style="text-align: left; margin-bottom: 8px;">Reading Theme</span>
                <div class="theme-options-grid">
                    <button type="button" class="theme-pill-btn" data-theme-choice="default" onclick="setTheme('default')">
                        <span class="theme-swatch" style="background: #eef2f7; border-color: #1e3a5f;"></span>
                        <span>Ice Blue</span>
                    </button>
                    <button type="button" class="theme-pill-btn" data-theme-choice="light" onclick="setTheme('light')">
                        <span class="theme-swatch" style="background: #ffffff; border-color: #94a3b8;"></span>
                        <span>Pure White</span>
                    </button>
                    <button type="button" class="theme-pill-btn" data-theme-choice="parchment" onclick="setTheme('parchment')">
                        <span class="theme-swatch" style="background: #f5efe0; border-color: #7c5832;"></span>
                        <span>Parchment</span>
                    </button>
                    <button type="button" class="theme-pill-btn" data-theme-choice="dark" onclick="setTheme('dark')">
                        <span class="theme-swatch" style="background: #0f172a; border-color: #38bdf8;"></span>
                        <span>Dark Mode</span>
                    </button>
                </div>
            </div>
        </aside>

        <?php if (!$isStaff): ?>
            <!-- RIGHT MAIN AREA (Members Only) -->
            <section class="profile-main-content">
                
                <!-- Top Editorial Banner -->
                <div class="profile-banner">
                    <div class="profile-banner-overlay">
                        <span class="profile-banner-tag">MEMBER BOOKSHELF</span>
                        <h1>MY READING ROOM</h1>
                        <p>Manage your active loans, saved wishlist, and personal library profile.</p>
                    </div>
                </div>

                <!-- Middle Section: Currently Borrowed Books -->
                <div class="profile-section-card">
                    <div class="profile-section-header">
                        <h3>Currently Borrowed Books (<?= count($borrowedBooks) ?>/<?= $maxLimit ?>)</h3>
                        <a href="my_loans.php" class="profile-view-all">Manage Loans &rarr;</a>
                    </div>

                    <?php if (empty($borrowedBooks)): ?>
                        <p class="profile-empty-note">You haven't borrowed any books yet. <a href="index.php">Explore the catalog</a> to start reading!</p>
                    <?php else: ?>
                        <div class="mini-book-grid">
                            <?php foreach ($borrowedBooks as $book): ?>
                                <div class="mini-book-card">
                                    <img class="mini-book-cover"
                                         src="https://covers.openlibrary.org/b/isbn/<?= htmlspecialchars($book['isbn']) ?>-M.jpg"
                                         alt="Cover">
                                    <h4 title="<?= htmlspecialchars($book['title']) ?>"><?= htmlspecialchars($book['title']) ?></h4>
                                    <p class="mini-book-author"><?= htmlspecialchars($book['author']) ?></p>
                                    <span class="mini-due-tag">Due: <?= date('M d', strtotime($book['due_date'])) ?></span>
                                    
                                    <div class="mini-book-actions">
                                        <?php if ($book['loan_status'] === 'Issued' && !empty($book['pdf_path'])): ?>
                                            <a href="read_book.php?book_id=<?= (int)$book['book_id'] ?>" class="btn mini-btn">Read</a>
                                        <?php elseif ($book['loan_status'] === 'Pending'): ?>
                                            <span class="mini-due-tag" style="margin:0;">Pending</span>
                                        <?php endif; ?>

                                        <?php if ($book['loan_status'] === 'Issued'): ?>
                                            <form method="POST" action="return_action.php" style="flex:1;" onsubmit="return confirm('Return &quot;<?= htmlspecialchars(addslashes($book['title'])) ?>&quot; now?');">
                                                <input type="hidden" name="borrowing_id" value="<?= (int)$book['borrowing_id'] ?>">
                                                <button type="submit" class="btn mini-btn mini-btn-outline">Return</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Lower Section: User Wishlist -->
                <div class="profile-section-card" style="flex: 1;">
                    <div class="profile-section-header">
                        <h3>My Wishlist (<?= count($wishlistBooks) ?>)</h3>
                        <a href="index.php" class="profile-view-all">+ Add More Books</a>
                    </div>

                    <?php if (empty($wishlistBooks)): ?>
                        <p class="profile-empty-note">Your wishlist is empty. Click the heart icon on any book in the catalog to save it here.</p>
                    <?php else: ?>
                        <div class="mini-book-grid">
                            <?php foreach ($wishlistBooks as $wBook): ?>
                                <?php $isAvail = ($wBook['available_copies'] > 0 && !empty($wBook['pdf_path'])); ?>
                                <div class="mini-book-card">
                                    <img class="mini-book-cover"
                                         src="https://covers.openlibrary.org/b/isbn/<?= htmlspecialchars($wBook['isbn']) ?>-M.jpg"
                                         alt="Cover">
                                    <h4 title="<?= htmlspecialchars($wBook['title']) ?>"><?= htmlspecialchars($wBook['title']) ?></h4>
                                    <p class="mini-book-author"><?= htmlspecialchars($wBook['author']) ?></p>
                                    
                                    <?php if ($isAvail): ?>
                                        <span class="badge available" style="font-size: 0.6rem; padding: 1px 5px; margin: 2px 0;">Available</span>
                                    <?php else: ?>
                                        <span class="badge unavailable" style="font-size: 0.6rem; padding: 1px 5px; margin: 2px 0;">Unavailable</span>
                                    <?php endif; ?>

                                    <div class="mini-book-actions">
                                        <?php if ($isAvail): ?>
                                            <form method="POST" action="borrow_action.php" style="flex:1;">
                                                <input type="hidden" name="book_id" value="<?= (int)$wBook['book_id'] ?>">
                                                <button type="submit" class="btn mini-btn">Borrow</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="wishlist_action.php">
                                            <input type="hidden" name="book_id" value="<?= (int)$wBook['book_id'] ?>">
                                            <input type="hidden" name="redirect" value="profile.php">
                                            <button type="submit" class="btn mini-btn mini-btn-danger" title="Remove from Wishlist">&times;</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </section>
        <?php endif; ?>
    </div>
</main>

<script>
function previewAvatar(event) {
    const file = event.target.files[0];
    if (!file) return;
    document.getElementById('selected_preset').value = '';
    document.querySelectorAll('.default-avatar-option').forEach(el => el.classList.remove('is-selected'));

    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('avatar-preview');
        const fallback = document.getElementById('avatar-fallback');
        preview.src = e.target.result;
        preview.classList.remove('hidden');
        if (fallback) fallback.classList.add('hidden');
    };
    reader.readAsDataURL(file);
}

function selectPresetAvatar(presetKey, url, btn) {
    const fileInput = document.getElementById('profile_pic');
    if (fileInput) fileInput.value = '';

    document.getElementById('selected_preset').value = presetKey;
    document.querySelectorAll('.default-avatar-option').forEach(el => el.classList.remove('is-selected'));
    btn.classList.add('is-selected');

    const preview = document.getElementById('avatar-preview');
    const fallback = document.getElementById('avatar-fallback');

    if (presetKey === 'initial') {
        preview.classList.add('hidden');
        if (fallback) fallback.classList.remove('hidden');
    } else {
        preview.src = url;
        preview.classList.remove('hidden');
        if (fallback) fallback.classList.add('hidden');
    }
}

function setTheme(themeName) {
    if (themeName === 'default') {
        document.documentElement.removeAttribute('data-theme');
    } else {
        document.documentElement.setAttribute('data-theme', themeName);
    }
    localStorage.setItem('rr_theme', themeName);
    updateThemeButtons(themeName);
}

function updateThemeButtons(activeTheme) {
    document.querySelectorAll('.theme-pill-btn').forEach(btn => {
        btn.classList.toggle('is-active', btn.dataset.themeChoice === activeTheme);
    });
}

// Highlight active theme button on load
updateThemeButtons(localStorage.getItem('rr_theme') || 'default');
</script>

</body>
</html>