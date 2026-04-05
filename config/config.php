<?php
// Start session so user data persists across pages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load the .env file
$lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Loop through each line and store it in $_ENV
foreach ($lines as $line) {
    // Split the line at the "=" sign into key and value
    list($key, $value) = explode('=', $line, 2);
    // Save the key-value pair into $_ENV
    $_ENV[$key] = $value;
}

// Connect to the database using the .env values
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);

// Check if the connection failed
if ($conn->connect_error) {
    // Stop the page and show the error
    die('Connection failed: ' . $conn->connect_error);
}
?>