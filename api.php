<?php
// api.php
require_once 'config.php';

header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
    exit;
}

// Get Headers for API Key validation
$headers = function_exists('getallheaders') ? getallheaders() : [];
$apiKeyHeader = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// Get the URL and possibly api_key from POST data (supports both JSON and form data)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$url = null;
$providedApiKey = null;

if (isset($input['url'])) {
    $url = $input['url'];
    $providedApiKey = $input['api_key'] ?? null;
} elseif (isset($_POST['url'])) {
    $url = $_POST['url'];
    $providedApiKey = $_POST['api_key'] ?? null;
}

// Check API Key (Header takes precedence over body)
$finalApiKey = $apiKeyHeader ?: $providedApiKey;

if ($finalApiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Invalid API Key.']);
    exit;
}

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing URL provided.']);
    exit;
}

try {
    // Check if the URL already exists to prevent duplicates (optional but good practice)
    $stmt = $pdo->prepare("SELECT short_code FROM links WHERE original_url = ? LIMIT 1");
    $stmt->execute([$url]);
    $existing = $stmt->fetch();

    if ($existing) {
        $shortCode = $existing['short_code'];
    } else {
        // Generate a unique short code
        $shortCode = generateShortCode();
        $isUnique = false;

        while (!$isUnique) {
            $stmt = $pdo->prepare("SELECT id FROM links WHERE short_code = ?");
            $stmt->execute([$shortCode]);
            if ($stmt->rowCount() == 0) {
                $isUnique = true;
            } else {
                $shortCode = generateShortCode();
            }
        }

        // Insert into database
        $stmt = $pdo->prepare("INSERT INTO links (short_code, original_url) VALUES (?, ?)");
        $stmt->execute([$shortCode, $url]);
    }

    $shortUrl = BASE_URL . $shortCode;

    echo json_encode([
        'success' => true,
        'original_url' => $url,
        'short_code' => $shortCode,
        'short_url' => $shortUrl
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
