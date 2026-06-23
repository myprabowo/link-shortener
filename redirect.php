<?php
// redirect.php
require_once 'config.php';

if (!isset($_GET['code']) || empty($_GET['code'])) {
    // No code provided, maybe redirect to main page or show 404
    header('Location: index.php');
    exit;
}

$code = $_GET['code'];

try {
    // Find the original URL
    $stmt = $pdo->prepare("SELECT id, original_url FROM links WHERE short_code = ? LIMIT 1");
    $stmt->execute([$code]);
    $link = $stmt->fetch();

    if ($link) {
        // Increment the click counter
        $updateStmt = $pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?");
        $updateStmt->execute([$link['id']]);

        // Redirect to the original URL (302 instead of 301 to prevent browser cache and ensure all clicks are counted)
        header('Location: ' . $link['original_url'], true, 302);
        exit;
    } else {
        // Short code not found
        http_response_code(404);
        echo "<h1>404 Not Found</h1>";
        echo "<p>The requested shortened link does not exist.</p>";
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}
