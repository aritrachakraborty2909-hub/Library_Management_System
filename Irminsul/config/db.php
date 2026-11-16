<?php
// Start a PHP session on every page so we remember who is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection settings
$host = 'localhost';
$port = '5432';
$dbname = 'lms';
$user = 'postgres';
$password = 'saikat123'; // <-- CHANGE THIS TO YOUR PGADMIN PASSWORD

// DSN (Data Source Name) tells PDO we are using PostgreSQL (pgsql)
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    // Create the PDO connection object
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Show clear errors if SQL fails
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC // Return rows as clean key-value arrays
    ]);
} catch (PDOException $e) {
    // Stop safely and display an error message if the connection fails
    die("Database connection failed: " . $e->getMessage());
}
?>