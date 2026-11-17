<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

// Redirect home if already logged in
if (isset($_SESSION['member_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Handle Registration submission (Module 0.1.2 Register Member)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($password !== $confirmPassword) {
        $error = 'Passwords do not match. Please re-enter them.';
    } else {
        $result = registerMember($pdo, $_POST);
        if ($result['success']) {
            header("Location: login.php?registered=1");
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sign Up | The Reading Room</title>
    <link rel="stylesheet" href="assets/style.css" />
    <link href="[https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Libre+Baskerville:ital,wght@0,400;1,400&display=swap](https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Libre+Baskerville:ital,wght@0,400;1,400&display=swap)" rel="stylesheet">
  </head>
  <body>
    <div class="reading-room">
      <!-- Left Editorial Panel (Sign Up Copy) -->
      <aside class="editorial">
        <header class="brand">
          <a href="index.php" class="brand-link">
            <strong>THE READING ROOM</strong><br />
            <span>YOUR NEXT CHAPTER AWAITS</span>
          </a>
        </header>

        <section class="editorial-intro">
          <p class="eyebrow">02&nbsp;&nbsp;/&nbsp;&nbsp;BECOME A MEMBER</p>
          <h1>
            Every reader<br />
            belongs here.
          </h1>
          <p class="intro-copy">
            Create your account and make room for new ideas, old favourites, and unexpected discoveries.
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

      <!-- Right Account Panel: Sign Up View -->
      <main class="account-panel signup-panel">
        <p class="page-crumb">THE READING ROOM&nbsp;&nbsp;/&nbsp;&nbsp;NEW MEMBERSHIP</p>
        
        <form class="account-form signup-form" method="POST" action="register.php">
          <p class="form-eyebrow">A NEW CHAPTER STARTS HERE</p>
          <h2>Join the reading room.</h2>
          <p class="form-intro">A few details, and you’re part of the story.</p>

          <?php if ($error): ?>
            <div class="alert-banner alert-error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="signup-fields">
            <label class="field full-width">
              <span>Full name</span>
              <input type="text" name="full_name" placeholder="Enter your full name" required />
            </label>
            <label class="field">
              <span>Email ID</span>
              <input type="email" name="email" placeholder="you@example.com" required />
            </label>
            <label class="field">
              <span>Phone number</span>
              <input type="tel" name="phone_number" placeholder="Enter phone number" />
            </label>
            <label class="field full-width">
              <span>Address</span>
              <textarea name="address" placeholder="House, street, city and postal code"></textarea>
            </label>
            <label class="field full-width">
              <span>New password</span>
              <input type="password" name="password" placeholder="Create a password" required />
            </label>
            <label class="field full-width">
              <span>Retype new password</span>
              <input type="password" name="confirm_password" placeholder="Re-enter your new password" required />
            </label>
          </div>
          
          <button class="primary-button" type="submit">Create account</button>
          
          <div class="member-link">
            <span>Already a member?</span>
            <a href="login.php">Log in</a>
          </div>
          <p class="privacy-note">Your details are used to manage your library membership.</p>
        </form>
      </main>
    </div>
  </body>
</html>