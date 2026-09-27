<?php
// api.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once __DIR__ . '/fallback_helper.php';
ensureFallbackSchema($pdo);

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

// Read JSON input or POST form data
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?: [];

$providedApiKey = $input['api_key'] ?? $_POST['api_key'] ?? null;
$finalApiKey = $apiKeyHeader ?: $providedApiKey;

// Authorization check: either valid session or valid API Key
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$isAuthorized = $isLoggedIn || ($finalApiKey === API_KEY);

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Invalid API Key or unauthenticated session.']);
    exit;
}

$action = $input['action'] ?? $_POST['action'] ?? 'create';

// Action: Test Health of URLs in real time
if ($action === 'test_health') {
    $urls = $input['urls'] ?? $_POST['urls'] ?? [];
    if (!is_array($urls)) {
        $urls = !empty($urls) ? [$urls] : [];
    }

    $results = [];
    foreach ($urls as $testUrl) {
        $testUrl = trim((string)$testUrl);
        if (empty($testUrl)) continue;

        $health = checkUrlHealth($testUrl, 2000);
        $results[] = [
            'url' => $testUrl,
            'is_healthy' => $health['is_healthy'],
            'http_code' => $health['http_code'],
            'response_time_ms' => $health['response_time_ms'],
            'error' => $health['error']
        ];
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// Standard Link Creation
$url = $input['url'] ?? $_POST['url'] ?? null;
$customCode = $input['custom_code'] ?? $_POST['custom_code'] ?? null;
$title = $input['title'] ?? $_POST['title'] ?? null;
$fallbackUrls = $input['fallback_urls'] ?? $_POST['fallback_urls'] ?? [];

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing destination URL provided.']);
    exit;
}

$sanitizedTitle = !empty($title) ? trim(strip_tags($title)) : '';
if (empty($sanitizedTitle)) {
    http_response_code(400);
    echo json_encode(['error' => 'Title or description is required.']);
    exit;
}

// Validate fallback URLs (up to 2 allowed)
$validFallbacks = [];
if (is_array($fallbackUrls)) {
    foreach (array_slice($fallbackUrls, 0, 2) as $fb) {
        $fbTrim = trim((string)$fb);
        if (!empty($fbTrim) && filter_var($fbTrim, FILTER_VALIDATE_URL)) {
            $validFallbacks[] = $fbTrim;
        }
    }
}

$linkType = !empty($validFallbacks) ? 'fallback' : 'direct';

try {
    // Determine short code
    if (!empty($customCode)) {
        $customCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $customCode);
        if (empty($customCode)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid custom alias provided.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM links WHERE short_code = ?");
        $stmt->execute([$customCode]);
        if ($stmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Custom short code is already in use.']);
            exit;
        }
        $shortCode = $customCode;
    } else {
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
    }

    // Insert into links table
    $stmt = $pdo->prepare("INSERT INTO links (short_code, original_url, title, link_type, check_interval) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$shortCode, $url, $sanitizedTitle, $linkType, 60]);
    $newLinkId = (int) $pdo->lastInsertId();

    // If fallback URLs provided, insert targets
    $targetResults = [];
    if ($linkType === 'fallback') {
        // Priority 1: Primary URL
        $h1 = checkUrlHealth($url, 1500);
        $insT = $pdo->prepare("INSERT INTO link_targets (link_id, url, priority, is_healthy, last_status_code, last_checked_at, response_time_ms, error_message) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)");
        $insT->execute([$newLinkId, $url, 1, $h1['is_healthy'] ? 1 : 0, $h1['http_code'] ?: null, $h1['response_time_ms'], $h1['error'] ?: null]);
        $targetResults[] = ['url' => $url, 'priority' => 1, 'is_healthy' => $h1['is_healthy'], 'http_code' => $h1['http_code']];

        // Priority 2 & 3: Fallback URLs
        $p = 2;
        foreach ($validFallbacks as $fbUrl) {
            $h = checkUrlHealth($fbUrl, 1500);
            $insT->execute([$newLinkId, $fbUrl, $p, $h['is_healthy'] ? 1 : 0, $h['http_code'] ?: null, $h['response_time_ms'], $h['error'] ?: null]);
            $targetResults[] = ['url' => $fbUrl, 'priority' => $p, 'is_healthy' => $h['is_healthy'], 'http_code' => $h['http_code']];
            $p++;
        }
    }

    $shortUrl = BASE_URL . $shortCode;

    echo json_encode([
        'success' => true,
        'id' => $newLinkId,
        'title' => $sanitizedTitle,
        'original_url' => $url,
        'short_code' => $shortCode,
        'short_url' => $shortUrl,
        'link_type' => $linkType,
        'targets' => $targetResults
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
