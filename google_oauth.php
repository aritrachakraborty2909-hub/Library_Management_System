<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

$clientId     = $_ENV['GOOGLE_CLIENT_ID']     ?? getenv('GOOGLE_CLIENT_ID')     ?: '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';
$redirectUri  = $_ENV['GOOGLE_REDIRECT_URI']  ?? getenv('GOOGLE_REDIRECT_URI')  ?: 'https://the-reading-room-6hsg.onrender.com/google_oauth.php';

// Helper function for clean redirects back to login.php with an error message
function failGoogleAuth(string $message): void
{
    $_SESSION['error'] = $message;
    header("Location: login.php");
    exit;
}

if ($clientId === '' || $clientSecret === '') {
    failGoogleAuth('Google OAuth is not configured on this server. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to your .env file.');
}

if (!isset($_GET['code'])) {
    if (isset($_GET['error'])) {
        failGoogleAuth('Google sign-in was cancelled or denied.');
    }

    // Generate CSRF state token
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account'
    ]);

    header("Location: " . $authUrl);
    exit;
}

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
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
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
        if (in_array($member['account_status'], ['Locked', 'Rejected'], true)) {
            failGoogleAuth('Your library account is currently ' . strtolower($member['account_status']) . '. Please contact the librarian.');
        }

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

    if ($_SESSION['role'] === 'Librarian') {
        header("Location: librarian_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit;

} catch (PDOException $e) {
    failGoogleAuth('Database error during Google sign-in: ' . $e->getMessage());
}