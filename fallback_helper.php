<?php
// fallback_helper.php
// Helper functions and automated schema ensure for Fallback & Health Check features.

/**
 * Ensure database schema contains required columns and tables for both SQLite and MySQL.
 */
if (!function_exists('ensureFallbackSchema')) {
    function ensureFallbackSchema(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) return;

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info(links)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('title', $cols, true)) {
                    $pdo->exec("ALTER TABLE links ADD COLUMN title TEXT DEFAULT NULL");
                }
                if (!in_array('link_type', $cols, true)) {
                    $pdo->exec("ALTER TABLE links ADD COLUMN link_type TEXT DEFAULT 'direct'");
                }
                if (!in_array('check_interval', $cols, true)) {
                    $pdo->exec("ALTER TABLE links ADD COLUMN check_interval INTEGER DEFAULT 60");
                }

                $pdo->exec("CREATE TABLE IF NOT EXISTS link_targets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    link_id INTEGER NOT NULL,
                    url TEXT NOT NULL,
                    priority INTEGER NOT NULL DEFAULT 1,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    is_healthy INTEGER NOT NULL DEFAULT 1,
                    last_status_code INTEGER DEFAULT NULL,
                    last_checked_at DATETIME DEFAULT NULL,
                    response_time_ms INTEGER DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    clicks INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE
                )");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_targets_link_priority ON link_targets (link_id, priority)");
            } elseif ($driver === 'mysql') {
                $cols = $pdo->query("SHOW COLUMNS FROM links")->fetchAll(PDO::FETCH_COLUMN, 0);
                if (!in_array('title', $cols, true)) {
                    $pdo->exec("ALTER TABLE links ADD COLUMN title VARCHAR(255) DEFAULT NULL AFTER original_url");
                }
                if (!in_array('link_type', $cols, true)) {
                    $pdo->exec("ALTER TABLE links ADD COLUMN link_type VARCHAR(20) DEFAULT 'direct' AFTER title");
                }
                if (!in_array('check_interval', $cols, true)) {
                    $pdo->exec("ALTER TABLE links ADD COLUMN check_interval INT DEFAULT 60 AFTER link_type");
                }

                $pdo->exec("CREATE TABLE IF NOT EXISTS link_targets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    link_id INT NOT NULL,
                    url TEXT NOT NULL,
                    priority INT NOT NULL DEFAULT 1,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    is_healthy TINYINT(1) NOT NULL DEFAULT 1,
                    last_status_code INT DEFAULT NULL,
                    last_checked_at DATETIME DEFAULT NULL,
                    response_time_ms INT DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    clicks INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_link_targets_link_priority (link_id, priority),
                    FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            $ensured = true;
        } catch (Exception $e) {
            // Silently ignore if already migrated or permission restricted
        }
    }
}

/**
 * Check health of a single target URL using cURL (HEAD with GET fallback).
 * Considers 2xx and 3xx as Healthy/Online.
 */
if (!function_exists('checkUrlHealth')) {
    function checkUrlHealth(string $url, int $timeoutMs = 1500): array
    {
        $startTime = microtime(true);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'is_healthy' => false,
                'http_code' => 0,
                'response_time_ms' => 0,
                'error' => 'Invalid URL syntax'
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true, // HEAD probe
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 1000),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_USERAGENT => 'PKNSTAN-HealthBot/1.0 (+https://s.pknstan.id)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        // If server rejects HEAD request with 405 Method Not Allowed or 403 Forbidden, fallback to minimal GET
        if ($curlErrno === 0 && ($httpCode === 405 || $httpCode === 403)) {
            $chGet = curl_init();
            curl_setopt_array($chGet, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPGET => true,
                CURLOPT_RANGE => '0-512',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 1000),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            curl_exec($chGet);
            $getErrno = curl_errno($chGet);
            $getHttpCode = (int) curl_getinfo($chGet, CURLINFO_HTTP_CODE);
            if ($getErrno === 0 && $getHttpCode > 0) {
                $httpCode = $getHttpCode;
                $curlErrno = 0;
                $curlError = '';
            }
            unset($chGet);
        }

        $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
        $isHealthy = ($curlErrno === 0 && $httpCode >= 200 && $httpCode < 400);

        return [
            'is_healthy' => $isHealthy,
            'http_code' => $httpCode,
            'response_time_ms' => $elapsedMs,
            'error' => $curlErrno !== 0 ? $curlError : ($isHealthy ? null : "HTTP status $httpCode")
        ];
    }
}

/**
 * Resolve the highest priority active healthy destination for a fallback link.
 * Uses TTL cache to prevent probe latency for visitors.
 */
if (!function_exists('resolveFallbackUrl')) {
    function resolveFallbackUrl(PDO $pdo, array $link): ?array
    {
        ensureFallbackSchema($pdo);

        $linkId = (int) $link['id'];
        $cacheInterval = isset($link['check_interval']) && (int)$link['check_interval'] > 0 ? (int)$link['check_interval'] : 60;

        $stmt = $pdo->prepare("SELECT * FROM link_targets WHERE link_id = ? AND is_active = 1 ORDER BY priority ASC, id ASC");
        $stmt->execute([$linkId]);
        $targets = $stmt->fetchAll();

        if (empty($targets)) {
            return [
                'target_id' => null,
                'url' => $link['original_url'],
                'is_healthy' => 1,
                'priority' => 1,
                'is_fallback' => false
            ];
        }

        $currentTime = time();
        $chosenTarget = null;

        foreach ($targets as $target) {
            $lastChecked = !empty($target['last_checked_at']) ? strtotime($target['last_checked_at']) : 0;
            $isCacheFresh = ($currentTime - $lastChecked) < $cacheInterval;

            if ($isCacheFresh) {
                if ((int)$target['is_healthy'] === 1) {
                    $chosenTarget = $target;
                    break;
                }
                continue;
            }

            $health = checkUrlHealth($target['url'], 1200);
            $isHealthy = $health['is_healthy'] ? 1 : 0;

            try {
                $upd = $pdo->prepare("UPDATE link_targets SET 
                    is_healthy = ?, 
                    last_status_code = ?, 
                    last_checked_at = CURRENT_TIMESTAMP, 
                    response_time_ms = ?, 
                    error_message = ? 
                    WHERE id = ?");
                $upd->execute([
                    $isHealthy,
                    $health['http_code'] ?: null,
                    $health['response_time_ms'],
                    $health['error'] ?: null,
                    $target['id']
                ]);
            } catch (PDOException $e) {
            }

            if ($isHealthy) {
                $chosenTarget = $target;
                break;
            }
        }

        if ($chosenTarget) {
            try {
                $updClick = $pdo->prepare("UPDATE link_targets SET clicks = clicks + 1 WHERE id = ?");
                $updClick->execute([$chosenTarget['id']]);
            } catch (PDOException $e) {
            }

            return [
                'target_id' => (int) $chosenTarget['id'],
                'url' => $chosenTarget['url'],
                'is_healthy' => 1,
                'priority' => (int) $chosenTarget['priority'],
                'is_fallback' => true
            ];
        }

        return null;
    }
}
