<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

// Security Check: Ensure user actually verified their email on forgot_password.php first
if (!isset($_SESSION['reset_member_id'])) {
    header("Location: forgot_password.php");
    exit;
}

$error = '';
$memberName = $_SESSION['reset_member_name'] ?? 'Reader';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please re-enter them.";
    } else {
        // Save the new hashed password into PostgreSQL
        $updated = updateMemberPassword($pdo, (int)$_SESSION['reset_member_id'], $newPassword);

        if ($updated) {
            // Clear the temporary reset session variables
            unset($_SESSION['reset_member_id'], $_SESSION['reset_member_name']);

            // Redirect to login.php with a success banner
            header("Location: login.php?reset=1");
            exit;
        } else {
            $error = "Something went wrong while updating your password. Please try again.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Create New Password | The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css" />
  </head>
  <script>
  function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const eyeOpen = btn.querySelector('.eye-open');
    const eyeClosed = btn.querySelector('.eye-closed');

    if (input.type === 'password') {
      input.type = 'text';
      eyeOpen.classList.add('hidden');
      eyeClosed.classList.remove('hidden');
      btn.setAttribute('aria-label', 'Hide password');
    } else {
      input.type = 'password';
      eyeOpen.classList.remove('hidden');
      eyeClosed.classList.add('hidden');
      btn.setAttribute('aria-label', 'Show password');
    }
  }
</script>
  <body>
    <div class="reading-room">
      <!-- Left Editorial Panel -->
      <aside class="editorial">
        <header class="brand">
          <a href="index.php" class="brand-link">
            <strong>THE READING ROOM</strong><br />
            <span>YOUR NEXT CHAPTER AWAITS</span>
          </a>
        </header>

        <section class="editorial-intro">
          <p class="eyebrow">04&nbsp;&nbsp;/&nbsp;&nbsp;NEW CREDENTIALS</p>
          <h1>
            A fresh key<br />
            for your shelf.
          </h1>
          <p class="intro-copy">
            Welcome back, <strong><?= htmlspecialchars($memberName) ?></strong>. Choose a strong new password to secure your library account.
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

      <!-- Right Account Panel: Create New Password Form -->
      <main class="account-panel login-panel">
        <p class="page-crumb">THE READING ROOM&nbsp;&nbsp;/&nbsp;&nbsp;STEP 2 OF 2</p>

        <form class="account-form login-form" method="POST" action="reset_password.php">
          <p class="form-eyebrow">VERIFIED ACCOUNT</p>
          <h2>Create new password.</h2>
          <p class="form-intro">Enter and confirm your new password below.</p>

          <?php if ($error): ?>
            <div class="alert-banner alert-error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="login-fields">
            <label class="field">
    <span>Enter new Password</span>
    <div class="password-wrapper">
      <input
        type="password"
        id="create-password"
        name="create_password"
        placeholder="Enter your password"
        required
      />
      <button
        type="button"
        class="toggle-password-btn"
        onclick="togglePassword('create-password', this)"
        aria-label="Show password"
      >
        <!-- Open Eye SVG Icon -->
        <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
          <circle cx="12" cy="12" r="3"></circle>
        </svg>
        <!-- Closed/Slashed Eye SVG Icon (Hidden by default) -->
        <svg class="eye-closed hidden" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
          <line x1="1" y1="1" x2="23" y2="23"></line>
        </svg>
      </button>
    </div>
  </label>
            <label class="field">
    <span>COnfirm your new Password</span>
    <div class="password-wrapper">
      <input
        type="password"
        id="confirm-password"
        name="confirm_password"
        placeholder="Enter your password"
        required
      />
      <button
        type="button"
        class="toggle-password-btn"
        onclick="togglePassword('confirm-password', this)"
        aria-label="Show password"
      >
        <!-- Open Eye SVG Icon -->
        <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
          <circle cx="12" cy="12" r="3"></circle>
        </svg>
        <!-- Closed/Slashed Eye SVG Icon (Hidden by default) -->
        <svg class="eye-closed hidden" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
          <line x1="1" y1="1" x2="23" y2="23"></line>
        </svg>
      </button>
    </div>
  </label>

          <button class="primary-button" type="submit">Update Password</button>

          <div class="login-links">
            <a href="forgot_password.php">&larr; Use a different email</a>
            <a href="login.php">Cancel</a>
          </div>

          <p class="form-note">Your new password takes effect immediately.</p>
        </form>

        <p class="support-note">Need a hand? Contact the library.</p>
      </main>
    </div>
  </body>
</html>