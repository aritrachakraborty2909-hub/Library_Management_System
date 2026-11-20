<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';

// =========================================================================
// 1. GOOGLE OAUTH 2.0 CONFIGURATION
// Replace these with your Google Cloud Console OAuth 2.0 Web Client credentials
// =========================================================================
define('GOOGLE_CLIENT_ID', '139938837787-r8npjmi74f1j9tcv627dqqin2ol3v1rf.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', 'http://localhost/Library_Management_System/google_oauth.php');

// Helper function for clean redirects back to login.php with an error message
function failGoogleAuth(string $message): void
{
    $_SESSION['error'] = $message;
    header("Location: login.php");
    exit;
}

// =========================================================================
// 2. STEP A: REDIRECT USER TO GOOGLE CONSENT SCREEN (No ?code yet)
// =========================================================================
if (!isset($_GET['code'])) {
    if (isset($_GET['error'])) {
        failGoogleAuth('Google sign-in was cancelled or denied.');
    }

    // Generate CSRF state token
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account'
    ]);

    header("Location: " . $authUrl);
    exit;
}

// =========================================================================
// 3. STEP B: VERIFY STATE & EXCHANGE AUTHORIZATION CODE FOR ACCESS TOKEN
// =========================================================================
$returnedState = $_GET['state'] ?? '';
$expectedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if ($returnedState === '' || !hash_equals($expectedState, $returnedState)) {
    failGoogleAuth('Invalid OAuth session state. Please try signing in with Google again.');
}

$tokenCh = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($tokenCh, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ])
]);

$tokenResponse = curl_exec($tokenCh);
$tokenHttpCode = curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
curl_close($tokenCh);

$tokenData = json_decode((string)$tokenResponse, true);

if ($tokenHttpCode !== 200 || empty($tokenData['access_token'])) {
    failGoogleAuth('Could not verify your Google login token. Check your Client ID, Secret, and Redirect URI.');
}

// =========================================================================
// 4. STEP C: FETCH VERIFIED USER PROFILE FROM GOOGLE
// =========================================================================
$userCh = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($userCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']]
]);

$userResponse = curl_exec($userCh);
$userHttpCode = curl_getinfo($userCh, CURLINFO_HTTP_CODE);
curl_close($userCh);

$googleUser = json_decode((string)$userResponse, true);

if ($userHttpCode !== 200 || empty($googleUser['email'])) {
    failGoogleAuth('Unable to retrieve your email address from Google.');
}

$email     = strtolower(trim($googleUser['email']));
$fullName  = trim($googleUser['name'] ?? explode('@', $email)[0]);
$avatarUrl = trim($googleUser['picture'] ?? '');

// =========================================================================
// 5. STEP D: FIND OR CREATE MEMBER IN NEON POSTGRESQL (`members` TABLE)
// =========================================================================
try {
    $stmt = $pdo->prepare("
        SELECT member_id, full_name, email, role, account_status, profile_pic 
        FROM members 
        WHERE LOWER(email) = :email 
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        // Block locked or rejected accounts just like standard login
        if (in_array($member['account_status'], ['Locked', 'Rejected'], true)) {
            failGoogleAuth('Your library account is currently ' . strtolower($member['account_status']) . '. Please contact the librarian.');
        }

        // Auto-activate Pending accounts since Google verified their email,
        // and sync their Google profile picture if they don't already have one
        $updatedPic = !empty($member['profile_pic']) ? $member['profile_pic'] : ($avatarUrl !== '' ? $avatarUrl : null);

        if ($member['account_status'] === 'Pending' || (empty($member['profile_pic']) && $updatedPic !== null)) {
            $upd = $pdo->prepare("
                UPDATE members 
                SET account_status = 'Active',
                    profile_pic = :pic
                WHERE member_id = :mid
            ");
            $upd->execute([
                ':pic' => $updatedPic,
                ':mid' => $member['member_id']
            ]);
        }

        $_SESSION['member_id']   = (int)$member['member_id'];
        $_SESSION['full_name']   = $member['full_name'];
        $_SESSION['email']       = $member['email'];
        $_SESSION['role']        = $member['role'];
        $_SESSION['profile_pic'] = $updatedPic ?? '';

    } else {
        // Auto-register new Google user as an Active 'Member'
        // Generate a random password_hash to satisfy NOT NULL constraint on `members.password_hash`
        $randomPasswordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

        $ins = $pdo->prepare("
            INSERT INTO members (full_name, email, password_hash, role, account_status, profile_pic)
            VALUES (:full_name, :email, :password_hash, 'Member', 'Active', :profile_pic)
            RETURNING member_id, full_name, email, role, profile_pic
        ");
        $ins->execute([
            ':full_name'     => $fullName,
            ':email'         => $email,
            ':password_hash' => $randomPasswordHash,
            ':profile_pic'   => $avatarUrl !== '' ? $avatarUrl : null
        ]);

        $newMember = $ins->fetch(PDO::FETCH_ASSOC);

        $_SESSION['member_id']   = (int)$newMember['member_id'];
        $_SESSION['full_name']   = $newMember['full_name'];
        $_SESSION['email']       = $newMember['email'];
        $_SESSION['role']        = $newMember['role'];
        $_SESSION['profile_pic'] = $newMember['profile_pic'] ?? '';
    }

    // Redirect based on role (matching your login.php routing)
    if ($_SESSION['role'] === 'Librarian') {
        header("Location: librarian_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit;

} catch (PDOException $e) {
    failGoogleAuth('Database error during Google sign-in: ' . $e->getMessage());
}