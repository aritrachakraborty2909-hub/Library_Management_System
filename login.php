<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

// Redirect home if already logged in
if (isset($_SESSION['member_id'])) {
    header("Location: index.php");
    exit;
}

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

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
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Member Access — The Reading Room</title>
  <link rel="stylesheet" href="assets/style.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Libre+Baskerville:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
</head>
<body>
  <div class="reading-room">
    <!-- Left Editorial Panel -->
    <aside class="editorial">
      <div>
        <div class="brand">
          <strong>THE READING ROOM</strong>
          <span>YOUR NEXT CHAPTER AWAITS</span>
        </div>

        <div class="editorial-intro">
          <div class="eyebrow">01 / WELCOME TO YOUR LIBRARY</div>
          <h1>A world of stories.<br>A place for you.</h1>
          <p class="intro-copy">Step inside. Find your next favourite book, and pick up right where you left off.</p>
        </div>

        <div class="shelves">
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
                <h3>A Room of One's Own</h3>
                <p>Virginia Woolf</p>
              </div>
            </li>
          </ol>
        </div>
      </div>

      <footer class="editorial-footer">
        <em>A little curiosity. A lifetime of discovery.</em>
        <span>THE READING ROOM &nbsp;/&nbsp; OPEN TO EVERY MIND</span>
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

      <div class="account-form">
        <!-- Top Red Box Area: Continue with Google Icon Button -->
        <div class="form-top-row">
          <span class="form-eyebrow">YOUR PERSONAL BOOKSHELF</span>
          <a href="google_oauth.php" class="btn-google-icon" title="Continue with Google">
            <svg width="15" height="15" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M23.49 12.275c0-.85-.075-1.675-.215-2.475H12v4.69h6.445c-.28 1.49-1.125 2.755-2.395 3.605v2.995h3.875c2.265-2.085 3.565-5.16 3.565-8.815z"/>
              <path fill="#34A853" d="M12 24c3.24 0 5.955-1.075 7.94-2.91l-3.875-2.995c-1.075.72-2.45 1.15-4.065 1.15-3.125 0-5.77-2.11-6.715-4.945H1.28v3.09C3.255 21.31 7.31 24 12 24z"/>
              <path fill="#FBBC05" d="M5.285 14.3c-.24-.72-.38-1.49-.38-2.3s.14-1.58.38-2.3V6.61H1.28C.465 8.235 0 10.065 0 12s.465 3.765 1.28 5.39l4.005-3.09z"/>
              <path fill="#EA4335" d="M12 4.755c1.76 0 3.34.605 4.585 1.795l3.435-3.435C17.95 1.19 15.235 0 12 0 7.31 0 3.255 2.69 1.28 6.61l4.005 3.09c.945-2.835 3.59-4.945 6.715-4.945z"/>
            </svg>
            <span>Continue with Google</span>
          </a>
        </div>

        <h2>Welcome back.</h2>
        <p class="form-intro">Log in to continue your reading journey.</p>

        <?php if (!empty($error)): ?>
          <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
          <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
          <div class="login-fields">
            <label class="field">
              <span>Email ID</span>
              <input type="email" name="email" placeholder="you@example.com" required>
            </label>

            <label class="field">
              <span>Password</span>
              <div class="password-wrapper">
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
                <button type="button" class="toggle-password-btn" onclick="togglePassword()" aria-label="Toggle password visibility">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
            </label>
          </div>

          <button type="submit" class="primary-button">Log in</button>
        </form>

        <div class="login-links">
          <a href="register.php">New user? Create an account</a>
          <a href="forgot_password.php">Forgot Password</a>
        </div>

        <!-- Second Red Box Area: Continue with OTP Button -->
        <div class="otp-login-wrapper">
          <a href="login_otp.php" class="btn-otp-login">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            <span>Continue with OTP</span>
          </a>
        </div>

        <p class="form-note">One account. Endless possibilities.</p>
      </div>

      <div class="member-link">
        <span>Need a hand?</span>
        <a href="mailto:support@thereadingroom.local">Contact the library.</a>
      </div>
    </main>
  </div>

  <script>
    function togglePassword() {
      const input = document.getElementById('password');
      input.type = input.type === 'password' ? 'text' : 'password';
    }
  </script>
</body>
</html>