<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    // Check PostgreSQL D1 Member Store for this email
    $user = verifyMemberEmail($pdo, $email);

    if ($user) {
        // User exists! Store verified member ID in session so reset_password.php knows who they are
        $_SESSION['reset_member_id'] = $user['member_id'];
        $_SESSION['reset_member_name'] = $user['full_name'];

        // Redirect to Step 2: Create New Password page
        header("Location: reset_password.php");
        exit;
    } else {
        // Email not found in database
        $error = "No such user exists with that email address.";
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Forgot Password | The Reading Room</title>
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
          <p class="eyebrow">03&nbsp;&nbsp;/&nbsp;&nbsp;ACCOUNT RECOVERY</p>
          <h1>
            Lost your key?<br />
            We’ll let you back in.
          </h1>
          <p class="intro-copy">
            Verify your registered email address first so we can locate your library membership card.
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

      <!-- Right Account Panel: Email Verification Form -->
      <main class="account-panel login-panel">
        <p class="page-crumb">THE READING ROOM&nbsp;&nbsp;/&nbsp;&nbsp;STEP 1 OF 2</p>

        <form class="account-form login-form" method="POST" action="forgot_password.php">
          <p class="form-eyebrow">VERIFY MEMBERSHIP</p>
          <h2>Find your account.</h2>
          <p class="form-intro">Enter your registered email ID to verify your account.</p>

          <?php if ($error): ?>
            <div class="alert-banner alert-error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="login-fields">
            <label class="field">
              <span>Registered Email ID</span>
              <input
                type="email"
                name="email"
                placeholder="you@example.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
              />
            </label>
          </div>

          <button class="primary-button" type="submit">Verify Email</button>

          <div class="login-links">
            <a href="login.php">&larr; Back to Log in</a>
            <a href="register.php">Create a new account</a>
          </div>

          <p class="form-note">Step 1: Email verification &rarr; Step 2: New password.</p>
        </form>

        <p class="support-note">Need a hand? Contact the library.</p>
      </main>
    </div>
  </body>
</html>