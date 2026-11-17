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
              <span>New Password</span>
              <input type="password" name="new_password" placeholder="Create a new password" required />
            </label>
            <label class="field">
              <span>Confirm New Password</span>
              <input type="password" name="confirm_password" placeholder="Re-enter your new password" required />
            </label>
          </div>

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