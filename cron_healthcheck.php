<?php
// cron_healthcheck.php
// Proactive health monitoring for smart fallback links.
// Can be run via CLI cron: php cron_healthcheck.php
// Or via HTTP GET with API Key: https://s.pknstan.id/cron_healthcheck.php?key=rahasia123

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fallback_helper.php';
ensureFallbackSchema($pdo);

// Verify access if running from web
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json');
    $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    if ($providedKey !== API_KEY) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$startTime = microtime(true);
$checkedCount = 0;
$healthyCount = 0;
$downCount = 0;
$details = [];

try {
    // Fetch all active targets belonging to fallback links
    $stmt = $pdo->query("
        SELECT lt.id, lt.link_id, lt.url, lt.priority, lt.is_healthy, l.short_code 
        FROM link_targets lt 
        JOIN links l ON lt.link_id = l.id 
        WHERE l.link_type = 'fallback' AND lt.is_active = 1 
        ORDER BY lt.link_id ASC, lt.priority ASC
    ");
    $targets = $stmt->fetchAll();

    foreach ($targets as $target) {
        $checkedCount++;
        $health = checkUrlHealth($target['url'], 2000);
        $isHealthy = $health['is_healthy'] ? 1 : 0;

        if ($isHealthy) {
            $healthyCount++;
        } else {
            $downCount++;
        }

        // Update database
        $upd = $pdo->prepare("
            UPDATE link_targets 
            SET is_healthy = ?, 
                last_status_code = ?, 
                last_checked_at = CURRENT_TIMESTAMP, 
                response_time_ms = ?, 
                error_message = ? 
            WHERE id = ?
        ");
        $upd->execute([
            $isHealthy,
            $health['http_code'] ?: null,
            $health['response_time_ms'],
            $health['error'] ?: null,
            $target['id']
        ]);

        $details[] = [
            'short_code' => $target['short_code'],
            'priority' => (int) $target['priority'],
            'url' => $target['url'],
            'is_healthy' => $isHealthy === 1,
            'http_code' => $health['http_code'],
            'response_time_ms' => $health['response_time_ms'],
            'error' => $health['error']
        ];
    }

    $elapsed = round(microtime(true) - $startTime, 2);

    $summary = [
        'status' => 'success',
        'timestamp' => date('Y-m-d H:i:s'),
        'elapsed_seconds' => $elapsed,
        'total_checked' => $checkedCount,
        'healthy' => $healthyCount,
        'offline' => $downCount,
        'details' => $details
    ];

    if ($isCli) {
        echo "[" . date('Y-m-d H:i:s') . "] Checked {$checkedCount} targets ({$healthyCount} UP, {$downCount} DOWN) in {$elapsed}s\n";
    } else {
        echo json_encode($summary, JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    if ($isCli) {
        echo "Error running healthcheck: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
