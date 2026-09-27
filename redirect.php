<?php
// redirect.php
require_once 'config.php';

if (!isset($_GET['code']) || empty($_GET['code'])) {
    header('Location: index.php');
    exit;
}

$code = $_GET['code'];

try {
    // Find the link
    $stmt = $pdo->prepare("SELECT * FROM links WHERE short_code = ? LIMIT 1");
    $stmt->execute([$code]);
    $link = $stmt->fetch();

    if ($link) {
        $destinationUrl = $link['original_url'];
        $isFallbackLink = ($link['link_type'] ?? 'direct') === 'fallback';

        if ($isFallbackLink) {
            $resolved = resolveFallbackUrl($pdo, $link);
            if ($resolved && !empty($resolved['url'])) {
                $destinationUrl = $resolved['url'];
            } else {
                // All targets offline/unreachable
                http_response_code(503);
                ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layanan Sedang Tidak Dapat Diakses - s.pknstan.id</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #151c2c;
            --border-color: #232f48;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --danger-bg: rgba(239, 68, 68, 0.12);
            --danger-border: rgba(239, 68, 68, 0.3);
            --danger-text: #f87171;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .error-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .error-icon {
            width: 64px;
            height: 64px;
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        h1 {
            font-size: 1.375rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--text-main);
        }
        p {
            font-size: 0.9375rem;
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 1.75rem;
        }
        .btn-retry {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: var(--primary);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.9375rem;
            padding: 0.75rem 1.75rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .btn-retry:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }
        .brand-footer {
            margin-top: 2rem;
            font-size: 0.8125rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon" aria-hidden="true">
            <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h1>Layanan Sedang Mengalami Gangguan</h1>
        <p>Sistem mendeteksi tautan tujuan utama dan seluruh server alternatif sedang offline atau tidak dapat dijangkau saat ini. Silakan coba kembali beberapa saat lagi.</p>
        <a href="" onclick="window.location.reload(); return false;" class="btn-retry">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Muat Ulang Halaman
        </a>
        <div class="brand-footer">s.pknstan.id &bull; Politeknik Keuangan Negara STAN</div>
    </div>
</body>
</html>
                <?php
                exit;
            }
        }

        // Increment the click counter
        $updateStmt = $pdo->prepare("UPDATE links SET clicks = clicks + 1 WHERE id = ?");
        $updateStmt->execute([$link['id']]);

        // Redirect to the resolved URL (302 instead of 301 to prevent browser cache and ensure all clicks/health are fresh)
        header('Location: ' . $destinationUrl, true, 302);
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

