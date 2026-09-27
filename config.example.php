<?php
// config.example.php
// Database Configuration
define('DB_FILE', __DIR__ . '/database.sqlite');

// Base URL for the shortened links (must include trailing slash)
define('BASE_URL', 'https://s.pknstan.id/');

// Authentication Credentials
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'your_secure_password_here');
define('API_KEY', 'your_secure_api_key_here');

// Establish Database Connection
try {
    $pdo = new PDO("sqlite:" . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        // Automatically create the table if it doesn't exist (SQLite)
        $pdo->exec("CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            short_code TEXT NOT NULL UNIQUE,
            original_url TEXT NOT NULL,
            title TEXT DEFAULT NULL,
            link_type TEXT DEFAULT 'direct',
            check_interval INTEGER DEFAULT 60,
            clicks INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Safe migration: check if title, link_type, check_interval columns exist in existing SQLite databases
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

        // Automatically create link_targets table for Smart Fallback shorteners
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

        // Automatically create link_trees table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_trees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            bio TEXT DEFAULT NULL,
            avatar_type TEXT DEFAULT 'initials',
            avatar_value TEXT DEFAULT NULL,
            instagram_url TEXT DEFAULT NULL,
            youtube_url TEXT DEFAULT NULL,
            telegram_url TEXT DEFAULT NULL,
            website_url TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Automatically create link_tree_items table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_tree_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tree_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            subtitle TEXT DEFAULT NULL,
            url TEXT NOT NULL,
            icon TEXT DEFAULT 'link',
            badge TEXT DEFAULT NULL,
            sort_order INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            clicks INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tree_id) REFERENCES link_trees (id) ON DELETE CASCADE
        )");
    } elseif ($driver === 'mysql') {
        // Automatically create the table if it doesn't exist (MySQL / MariaDB)
        $pdo->exec("CREATE TABLE IF NOT EXISTS links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            short_code VARCHAR(191) NOT NULL UNIQUE,
            original_url TEXT NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            link_type VARCHAR(20) DEFAULT 'direct',
            check_interval INT DEFAULT 60,
            clicks INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Safe migration: check if title, link_type, check_interval columns exist in existing MySQL databases
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

        // Automatically create link_targets table for Smart Fallback shorteners
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

        // Automatically create link_trees table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_trees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(191) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            bio TEXT DEFAULT NULL,
            avatar_type VARCHAR(50) DEFAULT 'initials',
            avatar_value VARCHAR(255) DEFAULT NULL,
            instagram_url VARCHAR(500) DEFAULT NULL,
            youtube_url VARCHAR(500) DEFAULT NULL,
            telegram_url VARCHAR(500) DEFAULT NULL,
            website_url VARCHAR(500) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Automatically create link_tree_items table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS link_tree_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tree_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) DEFAULT NULL,
            url TEXT NOT NULL,
            icon VARCHAR(50) DEFAULT 'link',
            badge VARCHAR(50) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            clicks INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tree_id) REFERENCES link_trees (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to generate a random string for the short code
function generateShortCode($length = 6)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Check health of a single target URL using cURL (HEAD with GET fallback).
 */
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
        CURLOPT_NOBODY => true,
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

/**
 * Resolve the highest priority active healthy destination for a fallback link.
 */
function resolveFallbackUrl(PDO $pdo, array $link): ?array
{
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
