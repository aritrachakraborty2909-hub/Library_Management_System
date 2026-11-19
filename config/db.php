<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Read key=value pairs from local .env file
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

$host    = $_ENV['DB_HOST'] ?? 'localhost';
$port    = $_ENV['DB_PORT'] ?? '5432';
$dbname  = $_ENV['DB_NAME'] ?? 'neondb';
$user    = $_ENV['DB_USER'] ?? 'neondb_owner';
$pass    = $_ENV['DB_PASS'] ?? '';
$sslmode = $_ENV['DB_SSLMODE'] ?? 'require';

// Extract the Endpoint ID (the 'ep-...' part before the first dot in DB_HOST)
$endpointId = explode('.', $host)[0];

// Pass options=endpoint=<endpointId> so XAMPP's libpq works with Neon
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode;options=endpoint=$endpointId";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>