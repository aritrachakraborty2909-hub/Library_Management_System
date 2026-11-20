<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

// Redirect home if already logged in
if (isset($_SESSION['member_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';$success = '';

// Check URL flags for registration or password reset completion
if (isset($_GET['registered'])) {$success = 'Account created! Log in to access your bookshelf.';
} elseif (isset($_GET['reset'])) {$success = 'Password updated! Log in with your new password.';
}

// Handle Login submission (Module 0.1.1 Process Login)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = loginMember($pdo, $_POST['email'] ?? '',$_POST['password'] ?? '');
    if ($result['success']) {
        if ($_SESSION['role'] === 'Librarian') {
            header("Location: librarian_dashboard.php");
        } else {
            header("Location: index.php");
        }
        exit;
    } else {
        $error =$result['message'];
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Log In | The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Libre+Baskerville:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
  </head>
  <body>
    <div class="reading-room">
      <!-- Left Editorial Panel (Login Copy) -->
      <aside class="editorial">
        <header class="brand">
          <a href="index.php" class="brand-link">
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
      <main class="account-panel">
        <div class="login-panel">
          <p class="page-crumb">THE READING ROOM&nbsp;&nbsp;/&nbsp;&nbsp;MEMBER ACCESS</p>
          <a href="index.php"><p class="page-crumb2">&larr; BACK TO DASHBOARD</p></a>
        </div>
        
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

            <div class="field">
              <span>Password</span>
              <div class="password-wrapper">
                <input
                  type="password"
                  id="login-password"
                  name="password"
                  placeholder="Enter your password"
                  required
                />
                <button
                  type="button"
                  class="toggle-password-btn"
                  onclick="togglePassword('login-password', this)"
                  aria-label="Show password"
                >
                  <!-- Open Eye SVG Icon (Hidden when masked) -->
                  <svg class="eye-open hidden" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                  <!-- Closed/Slashed Eye SVG Icon (Shown by default when masked) -->
                  <svg class="eye-closed" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                  </svg>
                </button>
              </div>
            </div>
          </div>
          
          <button class="primary-button" type="submit">Log in</button>
          
          <div class="login-links">
            <a href="register.php">New user? Create an account</a>
            <a href="