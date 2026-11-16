<?php
require_once 'config/db.php';
require_once 'services/AccountService.php';

$feedback = '';

// Check if a form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'register') {
        // Call Module 0.1.2 (Register Member)
        $result = registerMember($pdo, $_POST);
        $feedback = $result['message'];
    } elseif ($formType === 'login') {
        // Call Module 0.1.1 (Process Login)
        $result = loginMember($pdo, $_POST['email'], $_POST['password']);
        if ($result['success']) {
            header("Location: index.php"); // Redirect to Open Library home on success
            exit;
        }
        $feedback = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account Access | Open Library</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<nav class="navbar">
    <div class="brand">Open Library</div>
    <a href="index.php">&larr; Back to Catalog</a>
</nav>

<?php if ($feedback): ?>
    <p style="text-align:center; background:#fff3cd; padding:12px; font-weight:bold;"><?= htmlspecialchars($feedback) ?></p>
<?php endif; ?>

<!-- Login Form (0.1.1) -->
<div class="card-box">
    <h2>Member Log In</h2>
    <form method="POST" action="auth.php" style="margin-top:15px;">
        <input type="hidden" name="form_type" value="login">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn" style="width:100%;">Log In</button>
    </form>
</div>

<!-- Registration Form (0.1.2) -->
<div class="card-box">
    <h2>Create an Open Library Account</h2>
    <form method="POST" action="auth.php" style="margin-top:15px;">
        <input type="hidden" name="form_type" value="register">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" required>
        </div>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required>
        </div>
        <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone_number">
        </div>
        <div class="form-group">
            <label>Address</label>
            <textarea name="address" rows="2"></textarea>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn" style="width:100%; background:#28a745;">Sign Up</button>
    </form>
</div>

</body>
</html>