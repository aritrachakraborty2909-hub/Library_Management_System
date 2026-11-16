<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

// Redirect home if already logged in
if (isset($_SESSION['member_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

// Check URL flags for registration or password reset completion
if (isset($_GET['registered'])) {
    $success = 'Account created! Log in to access your bookshelf.';
} elseif (isset($_GET['reset'])) {
    $success = 'Password updated! Log in with your new password.';
}

// Handle Login submission (Module 0.1.1 Process Login)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = loginMember($pdo, $_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($result['success']) {
        if ($_SESSION['role'] === 'Librarian') {
            header("Location: librarian_dashboard.php");
        } else {
            header("Location: index.php");
        }
        exit;
    }
    else {
        $error = $result['message'];
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Log In | The Reading Room</title>
    <link rel="stylesheet" href="assets/auth.css" />
    <link href="[https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Libre+Baskerville:ital,wght@0,400;1,400&display=swap](https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Libre+Baskerville:ital,wght@0,400;1,400&display=swap)" rel="stylesheet">
  </head>
  <body>
    <div class="reading-room">
      <!-- Left Editorial Panel (Login Copy) -->
      <aside class="editorial">
        <header class="brand">
          <a href="index.php" style="text-decoration:none; color:inherit;">
            <strong>THE READING ROOM</strong><br />
            <span>YOUR NEXT CHAPTER AWAITS</span>
          </a>
        </header>

        <section class="editorial-intro">
          <p class="eyebrow">01&nbsp;&nbsp;/&nbsp;&nbsp;WELCOME TO YOUR LIBRARY</p>
          <h1>
            A world of stories.<br />
            A place for you.
          </h1>
          <p class="intro-copy">
            Step inside. Find your next favourite book, and pick up right where you left off.
          </p>
        </section>

        <section class="shelves" aria-label="From the shelves">
          <h2>FROM THE SHELVES</h2>
          <ol>
            <li>
              <span class="book-number">01</span>
              <div>
                <h3>Pride and Prejudice</h3>
                <p>Jane Austen</p>
              </div>
            </li>
            <li>
              <span class="book-number">02</span>
              <div>
                <h3>The Secret Garden</h3>
                <p>Frances Hodgson Burnett</p>
              </div>
            </li>
            <li>
              <span class="book-number">03</span>
              <div>
                <h3>A Room of One’s Own</h3>
                <p>Virginia Woolf</p>
              </div>
            </li>
          </ol>
        </section>

        <footer class="editorial-footer">
          <em>A little curiosity. A lifetime of discovery.</em>
          <span>THE READING ROOM&nbsp;&nbsp;/&nbsp;&nbsp;OPEN TO EVERY MIND</span>
        </footer>
      </aside>

      <!-- Right Account Panel: Login View -->
      <main class="account-panel login-panel">
        <p class="page-crumb">THE READING ROOM&nbsp;&nbsp;/&nbsp;&nbsp;MEMBER ACCESS</p>
        <a href="index.php"><p class="page-crumb2">&larr; BACK TO DASHBOARD</p></a>
        
        <form class="account-form login-form" method="POST" action="login.php">
          <p class="form-eyebrow">YOUR PERSONAL BOOKSHELF</p>
          <h2>Welcome back.</h2>
          <p class="form-intro">Log in to continue your reading journey.</p>

          <?php if ($success): ?>
            <div class="alert-banner alert-success"><?= htmlspecialchars($success) ?></div>
          <?php endif; ?>

          <?php if ($error): ?>
            <div class="alert-banner alert-error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="login-fields">
            <label class="field">
              <span>Email ID</span>
              <input type="email" name="email" placeholder="you@example.com" required />
            </label>
            <label class="field">
              <span>Password</span>
              <input type="password" name="password" placeholder="Enter your password" required />
            </label>
          </div>
          
          <button class="primary-button" type="submit">Log in</button>
          
          <div class="login-links">
            <a href="register.php">New user? Create an account</a>
            <a href="forgot_password.php">Forgot Password</a>
          </div>
          <p class="form-note">One account. Endless possibilities.</p>
        </form>
        <p class="support-note">Need a hand? Contact the library.</p>
      </main>
    </div>
  </body>
</html>