<?php
// index.php
session_start();
require_once 'config.php';
require_once __DIR__ . '/fallback_helper.php';
ensureFallbackSchema($pdo);
require_once 'lang.php';

// Handle Login
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === ADMIN_USER && $password === ADMIN_PASS) {
        $_SESSION['logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $loginError = __t('login_error');
    }
}

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$actionMessage = '';
$actionError = '';

if (isset($_GET['updated'])) {
    $actionMessage = __t('link_updated');
}
if (isset($_GET['deleted'])) {
    $actionMessage = __t('link_deleted');
}
if (isset($_GET['fallback_saved'])) {
    $actionMessage = __t('fallback_saved');
}

// Handle Delete, Edit, & Fallback Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
    if ($_POST['action'] === 'delete') {
        if (isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            header("Location: index.php?deleted=1");
            exit;
        }
    } elseif ($_POST['action'] === 'edit') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $url = filter_var($_POST['original_url'] ?? '', FILTER_VALIDATE_URL);
        $customCode = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['short_code'] ?? ''));
        $title = !empty($_POST['title']) ? trim(strip_tags($_POST['title'])) : null;

        if (!$id || !$url || empty($customCode) || empty($title)) {
            $actionError = __t('invalid_data_edit');
        } else {
            try {
                // Check if custom code belongs to another link
                $checkStmt = $pdo->prepare("SELECT id FROM links WHERE short_code = ? AND id != ?");
                $checkStmt->execute([$customCode, $id]);
                if ($checkStmt->rowCount() > 0) {
                    $actionError = __t('code_in_use', ['code' => htmlspecialchars($customCode)]);
                } else {
                    $updateStmt = $pdo->prepare("UPDATE links SET short_code = ?, original_url = ?, title = ? WHERE id = ?");
                    $updateStmt->execute([$customCode, $url, $title, $id]);
                    header("Location: index.php?updated=1");
                    exit;
                }
            } catch (PDOException $e) {
                $actionError = __t('db_error');
            }
        }
    } elseif ($_POST['action'] === 'save_fallback') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $primaryUrl = filter_var(trim($_POST['primary_url'] ?? ''), FILTER_VALIDATE_URL);
        $alt1 = trim($_POST['alt_url_1'] ?? '');
        $alt2 = trim($_POST['alt_url_2'] ?? '');

        $alt1Valid = !empty($alt1) ? filter_var($alt1, FILTER_VALIDATE_URL) : null;
        $alt2Valid = !empty($alt2) ? filter_var($alt2, FILTER_VALIDATE_URL) : null;

        if (!$id || !$primaryUrl) {
            $actionError = __t('invalid_primary_url');
        } elseif (!empty($alt1) && !$alt1Valid) {
            $actionError = __t('invalid_alt1_url');
        } elseif (!empty($alt2) && !$alt2Valid) {
            $actionError = __t('invalid_alt2_url');
        } else {
            try {
                $hasFallback = ($alt1Valid !== null || $alt2Valid !== null);
                $newType = $hasFallback ? 'fallback' : 'direct';

                // Update original_url and link_type
                $updLink = $pdo->prepare("UPDATE links SET original_url = ?, link_type = ? WHERE id = ?");
                $updLink->execute([$primaryUrl, $newType, $id]);

                // Reset previous targets
                $delTargets = $pdo->prepare("DELETE FROM link_targets WHERE link_id = ?");
                $delTargets->execute([$id]);

                if ($hasFallback) {
                    // Priority 1: Primary URL
                    $h1 = checkUrlHealth($primaryUrl, 1500);
                    $ins = $pdo->prepare("INSERT INTO link_targets (link_id, url, priority, is_healthy, last_status_code, last_checked_at, response_time_ms, error_message) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)");
                    $ins->execute([$id, $primaryUrl, 1, $h1['is_healthy'] ? 1 : 0, $h1['http_code'] ?: null, $h1['response_time_ms'], $h1['error'] ?: null]);

                    // Priority 2: Alternative 1
                    if ($alt1Valid) {
                        $h2 = checkUrlHealth($alt1Valid, 1500);
                        $ins->execute([$id, $alt1Valid, 2, $h2['is_healthy'] ? 1 : 0, $h2['http_code'] ?: null, $h2['response_time_ms'], $h2['error'] ?: null]);
                    }

                    // Priority 3: Alternative 2
                    if ($alt2Valid) {
                        $h3 = checkUrlHealth($alt2Valid, 1500);
                        $ins->execute([$id, $alt2Valid, 3, $h3['is_healthy'] ? 1 : 0, $h3['http_code'] ?: null, $h3['response_time_ms'], $h3['error'] ?: null]);
                    }
                }

                header("Location: index.php?fallback_saved=1");
                exit;
            } catch (PDOException $e) {
                $actionError = __t('db_error') . ': ' . $e->getMessage();
            }
        }
    }
}

$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$linksData = [];
$targetsByLinkId = [];
$totalLinksCount = 0;

if ($isLoggedIn) {
    try {
        $totalLinksCount = (int) $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();

        if ($searchQuery !== '') {
            $like = '%' . $searchQuery . '%';
            $stmt = $pdo->prepare("SELECT id, short_code, original_url, title, link_type, check_interval, clicks, created_at 
                                   FROM links 
                                   WHERE short_code LIKE :search 
                                      OR original_url LIKE :search 
                                      OR title LIKE :search 
                                   ORDER BY created_at DESC LIMIT 100");
            $stmt->execute([':search' => $like]);
            $linksData = $stmt->fetchAll();
        } else {
            $stmt = $pdo->query("SELECT id, short_code, original_url, title, link_type, check_interval, clicks, created_at FROM links ORDER BY created_at DESC LIMIT 100");
            $linksData = $stmt->fetchAll();
        }

        $linkIds = array_column($linksData, 'id');
        if (!empty($linkIds)) {
            $inClause = implode(',', array_fill(0, count($linkIds), '?'));
            $targetStmt = $pdo->prepare("SELECT * FROM link_targets WHERE link_id IN ($inClause) ORDER BY priority ASC");
            $targetStmt->execute($linkIds);
            while ($row = $targetStmt->fetch()) {
                $targetsByLinkId[$row['link_id']][] = $row;
            }
        }
    } catch (PDOException $e) {
        $linksData = [];
        $totalLinksCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__t('brand_title')); ?> | <?php echo htmlspecialchars(__t('brand_badge')); ?></title>
    <meta name="description" content="Official link shortener and QR manager for s.pknstan.id">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script>
        // Synchronous theme initialization to prevent flash
        (function() {
            const saved = localStorage.getItem('shortener_theme');
            if (saved === 'light' || saved === 'dark') {
                document.documentElement.setAttribute('data-theme', saved);
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                document.documentElement.setAttribute('data-theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    
    <style>
        /* Design System CSS Custom Properties */
        :root {
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            
            /* Light Theme Tokens */
            --bg-page: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-muted: #f1f5f9;
            --bg-surface-elevated: #ffffff;
            --border-subtle: #e2e8f0;
            --border-strong: #cbd5e1;
            --border-focus: #4338ca;
            
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            
            --accent: #4338ca;
            --accent-hover: #3730a3;
            --accent-active: #312e81;
            --accent-subtle: #eef2ff;
            --accent-text: #3730a3;
            --accent-contrast: #ffffff;
            
            --success: #15803d;
            --success-subtle: #f0fdf4;
            --success-border: #bbf7d0;
            --success-text: #166534;
            
            --danger: #dc2626;
            --danger-hover: #b91c1c;
            --danger-subtle: #fef2f2;
            --danger-border: #fecaca;
            --danger-text: #991b1b;
            
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-pill: 9999px;
            
            --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.08), 0 2px 4px -2px rgba(15, 23, 42, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(15, 23, 42, 0.08), 0 4px 6px -4px rgba(15, 23, 42, 0.04);
            --shadow-modal: 0 20px 25px -5px rgba(15, 23, 42, 0.15), 0 8px 10px -6px rgba(15, 23, 42, 0.1);
        }

        [data-theme="dark"] {
            /* Dark Theme Tokens */
            --bg-page: #090d16;
            --bg-surface: #111827;
            --bg-surface-muted: #1e293b;
            --bg-surface-elevated: #1a2234;
            --border-subtle: #1e293b;
            --border-strong: #334155;
            --border-focus: #6366f1;
            
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --accent-active: #4338ca;
            --accent-subtle: rgba(99, 102, 241, 0.14);
            --accent-text: #a5b4fc;
            --accent-contrast: #ffffff;
            
            --success: #22c55e;
            --success-subtle: rgba(34, 197, 94, 0.12);
            --success-border: rgba(34, 197, 94, 0.28);
            --success-text: #4ade80;
            
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --danger-subtle: rgba(239, 68, 68, 0.12);
            --danger-border: rgba(239, 68, 68, 0.28);
            --danger-text: #f87171;
            
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4), 0 2px 4px -2px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.45), 0 4px 6px -4px rgba(0, 0, 0, 0.3);
            --shadow-modal: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
        }

        /* Reset and Base Styles */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-page);
            color: var(--text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 1rem;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        /* Focus Ring Standard */
        :focus-visible {
            outline: 2px solid var(--border-focus);
            outline-offset: 2px;
        }

        /* Layout Container */
        .app-layout {
            width: 100%;
            max-width: 1120px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            margin: 0 auto;
        }

        .app-layout.login-mode {
            max-width: 460px;
            margin: 2.5rem auto;
        }

        /* Header Component */
        .app-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.25rem 0;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: inherit;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            background: var(--accent);
            color: var(--accent-contrast);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-sm);
        }

        .brand-title {
            font-size: 1.125rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .brand-badge {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Language Switcher */
        .lang-switcher {
            display: inline-flex;
            align-items: center;
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 2px;
            gap: 2px;
        }

        .lang-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 28px;
            padding: 0 0.45rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            transition: all 0.15s ease;
        }

        .lang-btn:hover {
            color: var(--text-primary);
        }

        .lang-btn.active {
            background: var(--accent);
            color: var(--accent-contrast);
        }

        /* Icon Buttons */
        .btn-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .btn-icon:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.5rem 0.875rem;
            min-height: 38px;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: var(--radius-md);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .btn-outline:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        /* Main Surface Card */
        .surface-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            margin-bottom: 1.25rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.015em;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .card-desc {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        /* Form Controls */
        .form-stack {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 0.875rem;
            color: var(--text-muted);
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .input-text {
            width: 100%;
            height: 44px;
            padding: 0 0.875rem 0 2.5rem;
            font-size: 0.9375rem;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--bg-surface);
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-md);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .input-text:focus {
            border-color: var(--border-focus);
        }

        .input-text::placeholder {
            color: var(--text-muted);
        }

        .input-text.no-icon {
            padding-left: 0.875rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .form-row.two-col {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Buttons */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            height: 44px;
            padding: 0 1.25rem;
            font-size: 0.9375rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--accent-contrast);
            background: var(--accent);
            border: 1px solid transparent;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: background-color 0.15s ease, opacity 0.15s ease;
            white-space: nowrap;
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--accent-hover);
        }

        .btn-primary:active:not(:disabled) {
            background: var(--accent-active);
        }

        .btn-primary:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            height: 36px;
            padding: 0 0.875rem;
            font-size: 0.8125rem;
            font-weight: 500;
            font-family: inherit;
            color: var(--text-secondary);
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }

        .btn-secondary:hover {
            background: var(--bg-surface);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        .btn-danger-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            padding: 0 0.625rem;
            font-size: 0.75rem;
            font-weight: 500;
            font-family: inherit;
            color: var(--danger-text);
            background: transparent;
            border: 1px solid var(--danger-border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .btn-danger-ghost:hover {
            background: var(--danger-subtle);
            color: var(--danger);
        }

        /* Spinner for Loading State */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Result & Notification Panels */
        .alert-box {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            line-height: 1.4;
            margin-bottom: 1rem;
        }

        .alert-error {
            background: var(--danger-subtle);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
        }

        .alert-success {
            background: var(--success-subtle);
            border: 1px solid var(--success-border);
            color: var(--success-text);
        }

        .result-panel {
            margin-top: 1.25rem;
            padding: 1.25rem;
            border-radius: var(--radius-md);
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }


        .result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .result-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .short-url-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            background: var(--bg-surface);
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-md);
            padding: 0.625rem 0.875rem;
        }

        .short-url-link {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
            word-break: break-all;
        }

        .short-url-link:hover {
            text-decoration: underline;
        }

        .btn-group {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            flex-shrink: 0;
        }

        /* Inline QR Preview */
        .inline-qr-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.875rem;
            padding: 1.25rem 0 0.5rem;
            border-top: 1px solid var(--border-subtle);
        }

        .qr-canvas-box {
            background: #ffffff;
            padding: 12px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-canvas-box canvas, .qr-canvas-box img {
            display: block;
        }

        /* Table & Inventory Section */
        .inventory-section {
            margin-top: 1.5rem;
        }

        .inventory-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.875rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .inventory-title-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .inventory-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .count-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-pill);
            background: var(--bg-surface-muted);
            color: var(--text-secondary);
            border: 1px solid var(--border-subtle);
            transition: all 0.15s ease;
        }

        /* Search Form & Input Styles */
        .search-form-wrap {
            display: flex;
            align-items: center;
            position: relative;
            width: 100%;
            max-width: 340px;
        }

        @media (max-width: 640px) {
            .search-form-wrap {
                max-width: 100%;
            }
            .inventory-header {
                flex-direction: column;
                align-items: stretch;
            }
        }

        .search-input-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .search-icon-left {
            position: absolute;
            left: 0.75rem;
            color: var(--text-muted);
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .search-input {
            width: 100%;
            height: 38px;
            padding: 0 4.25rem 0 2.25rem;
            font-size: 0.84375rem;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--bg-surface);
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-md);
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }

        .search-input:focus {
            border-color: var(--border-focus);
            background: var(--bg-surface);
            box-shadow: 0 0 0 3px var(--accent-subtle);
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        .search-clear-btn {
            position: absolute;
            right: 2.15rem;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: all 0.15s ease;
        }

        .search-clear-btn:hover {
            background: var(--border-strong);
            color: var(--text-primary);
        }

        .search-kbd-hint {
            position: absolute;
            right: 0.5rem;
            font-family: inherit;
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: 4px;
            padding: 1px 6px;
            line-height: 1.4;
            pointer-events: none;
            user-select: none;
            transition: opacity 0.15s ease;
        }

        /* Active Filter Indicator Bar */
        .active-filter-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.875rem;
            padding: 0.625rem 0.875rem;
            background: var(--accent-subtle);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: var(--radius-md);
            font-size: 0.8125rem;
            color: var(--text-primary);
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .filter-chip-remove {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--accent);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .filter-chip-remove:hover {
            background: var(--danger-subtle);
            color: var(--danger);
            border-color: var(--danger-border);
        }

        /* Keyword Highlighting */
        mark.search-highlight {
            background: rgba(234, 179, 8, 0.28);
            color: inherit;
            padding: 0 2px;
            border-radius: 2px;
            font-weight: 600;
        }

        [data-theme="dark"] mark.search-highlight {
            background: rgba(234, 179, 8, 0.35);
            color: #fef08a;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            background: var(--bg-surface);
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.875rem;
        }

        table.data-table th {
            background: var(--bg-surface-muted);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.8125rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-subtle);
            white-space: nowrap;
        }

        table.data-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--border-subtle);
            color: var(--text-primary);
            vertical-align: middle;
        }

        table.data-table tr:last-child td {
            border-bottom: none;
        }

        table.data-table tr:hover td {
            background: var(--bg-surface-muted);
        }

        .link-info-stack {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            max-width: 520px;
        }

        .link-title-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .col-url {
            max-width: 520px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
            color: var(--text-secondary);
            font-size: 0.8125rem;
        }

        .col-short {
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
            white-space: nowrap;
        }

        .col-short:hover {
            text-decoration: underline;
        }

        .col-date {
            color: var(--text-muted);
            font-size: 0.8125rem;
            white-space: nowrap;
        }

        .col-clicks {
            font-weight: 600;
            color: var(--text-primary);
            text-align: center;
        }

        /* Fallback Badge & Indicators */
        .badge-fallback {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 600;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            background: rgba(99, 102, 241, 0.12);
            color: #818cf8;
            border: 1px solid rgba(99, 102, 241, 0.28);
            margin-left: 0.35rem;
            vertical-align: middle;
        }

        .action-count-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 17px;
            height: 17px;
            padding: 0 4px;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 9999px;
            background: var(--accent);
            color: #ffffff;
            line-height: 1;
            margin-left: 0.25rem;
        }

        .health-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            margin-right: 0.3rem;
            vertical-align: middle;
        }
        .health-dot.health-up {
            background-color: #10b981;
            box-shadow: 0 0 6px rgba(16, 185, 129, 0.6);
        }
        .health-dot.health-down {
            background-color: #ef4444;
            box-shadow: 0 0 6px rgba(239, 68, 68, 0.6);
        }
        .health-dot.health-unknown {
            background-color: #94a3b8;
        }

        /* Fallback Modal Styles */
        .fallback-slot-box {
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 0.875rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .fallback-slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .slot-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .health-pill {
            font-size: 0.6875rem;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .health-pill.up {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .health-pill.down {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .health-pill.checking {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .health-pill.untested {
            background: var(--bg-surface-elevated);
            color: var(--text-muted);
            border: 1px solid var(--border-subtle);
        }
        .fallback-hint-card {
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: var(--radius-sm);
            padding: 0.625rem 0.875rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.4;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            padding: 2.5rem 1.5rem;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
        }

        .empty-state-icon {
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .empty-state-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .empty-state-desc {
            font-size: 0.8125rem;
        }

        /* Modal Component */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .modal-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-dialog {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            max-width: 440px;
            width: 100%;
            padding: 1.5rem;
            box-shadow: var(--shadow-modal);
            position: relative;
            transform: scale(0.96);
            transition: transform 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .modal-backdrop.is-open .modal-dialog {
            transform: scale(1);
        }

        .modal-dialog.centered {
            align-items: center;
            text-align: center;
        }

        .modal-close-btn {
            position: absolute;
            top: 0.875rem;
            right: 0.875rem;
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .modal-close-btn:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
        }

        .modal-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-url-badge {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            padding: 0.375rem 0.625rem;
            width: 100%;
            word-break: break-all;
        }

        .modal-actions-row {
            display: flex;
            gap: 0.5rem;
            width: 100%;
            margin-top: 0.5rem;
        }

        .modal-actions-row > * {
            flex: 1;
        }

        /* App Footer */
        .app-footer {
            margin-top: 1rem;
            text-align: center;
            font-size: 0.8125rem;
            color: var(--text-muted);
        }

        /* Responsive Breakpoints */
        @media (max-width: 640px) {
            .surface-card {
                padding: 1.25rem;
            }
            .short-url-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .btn-group {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>

    <div class="app-layout <?php echo !$isLoggedIn ? 'login-mode' : ''; ?>">
        <!-- App Header -->
        <header class="app-header">
            <a href="index.php" class="brand-block" title="<?php echo htmlspecialchars(__t('brand_title') . ' - ' . __t('brand_badge')); ?>">
                <div class="brand-icon" aria-hidden="true">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                </div>
                <div>
                    <div class="brand-title"><?php echo htmlspecialchars(__t('brand_title')); ?></div>
                    <div class="brand-badge"><?php echo htmlspecialchars(__t('brand_badge')); ?></div>
                </div>
            </a>

            <div class="header-actions">
                <!-- Language Switcher -->
                <div class="lang-switcher" aria-label="<?php echo htmlspecialchars(__t('language')); ?>">
                    <a href="<?php echo getLangToggleUrl('id'); ?>" class="lang-btn <?php echo $currentLang === 'id' ? 'active' : ''; ?>" title="Bahasa Indonesia">ID</a>
                    <a href="<?php echo getLangToggleUrl('en'); ?>" class="lang-btn <?php echo $currentLang === 'en' ? 'active' : ''; ?>" title="English">EN</a>
                </div>

                <?php if ($isLoggedIn): ?>
                <a href="tree.php?manage=1&tab=trees" class="btn-outline" title="<?php echo htmlspecialchars(__t('linktree_nav')); ?>">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    <span><?php echo htmlspecialchars(__t('linktree_nav')); ?></span>
                </a>
                <?php endif; ?>

                <!-- Theme Toggle Button -->
                <button type="button" class="btn-icon" id="theme-toggle-btn" aria-label="<?php echo htmlspecialchars(__t('theme_toggle')); ?>" title="<?php echo htmlspecialchars(__t('theme_toggle')); ?>">
                    <svg id="theme-icon-moon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <svg id="theme-icon-sun" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display: none;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </button>

                <?php if ($isLoggedIn): ?>
                <a href="logout.php" class="btn-outline" title="<?php echo htmlspecialchars(__t('logout')); ?>">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    <span><?php echo htmlspecialchars(__t('logout')); ?></span>
                </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Main Workspace Card -->
        <main class="surface-card">
            <?php if (!$isLoggedIn): ?>
            <!-- Login View -->
            <div class="card-header">
                <h1 class="card-title"><?php echo htmlspecialchars(__t('login_title')); ?></h1>
                <p class="card-desc"><?php echo htmlspecialchars(__t('login_desc')); ?></p>
            </div>

            <?php if ($loginError): ?>
            <div class="alert-box alert-error" role="alert">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span><?php echo htmlspecialchars($loginError); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="index.php" class="form-stack" style="margin-top: 1.25rem;">
                <input type="hidden" name="action" value="login">
                
                <div class="form-field">
                    <label class="form-label" for="username"><?php echo htmlspecialchars(__t('username')); ?></label>
                    <div class="input-group">
                        <span class="input-icon-left" aria-hidden="true">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </span>
                        <input type="text" name="username" id="username" class="input-text" placeholder="<?php echo htmlspecialchars(__t('username_placeholder')); ?>" required autocomplete="username">
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label" for="password"><?php echo htmlspecialchars(__t('password')); ?></label>
                    <div class="input-group">
                        <span class="input-icon-left" aria-hidden="true">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </span>
                        <input type="password" name="password" id="password" class="input-text" placeholder="<?php echo htmlspecialchars(__t('password_placeholder')); ?>" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 0.5rem;">
                    <span><?php echo htmlspecialchars(__t('sign_in')); ?></span>
                </button>
            </form>

            <?php else: ?>
            <!-- Authenticated Shortener View -->
            <div class="card-header">
                <h1 class="card-title"><?php echo htmlspecialchars(__t('create_title')); ?></h1>
                <p class="card-desc"><?php echo htmlspecialchars(__t('create_desc')); ?></p>
            </div>

            <?php if ($actionMessage): ?>
            <div class="alert-box alert-success" role="alert">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <span><?php echo htmlspecialchars($actionMessage); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($actionError): ?>
            <div class="alert-box alert-error" role="alert">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span><?php echo htmlspecialchars($actionError); ?></span>
            </div>
            <?php endif; ?>

            <!-- Creation Form -->
            <form id="shortener-form" class="form-stack">
                <div class="form-field">
                    <label class="form-label" for="long-url"><?php echo htmlspecialchars(__t('dest_url')); ?> <span style="color: var(--danger);">*</span></label>
                    <div class="input-group">
                        <span class="input-icon-left" aria-hidden="true">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        </span>
                        <input type="url" id="long-url" class="input-text" placeholder="<?php echo htmlspecialchars(__t('dest_placeholder')); ?>" required autocomplete="off">
                    </div>
                </div>

                <div class="form-row two-col">
                    <div class="form-field">
                        <label class="form-label" for="link-title"><?php echo htmlspecialchars(__t('title_desc')); ?> <span style="color: var(--danger);">*</span></label>
                        <div class="input-group">
                            <span class="input-icon-left" aria-hidden="true">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                            </span>
                            <input type="text" id="link-title" class="input-text" placeholder="<?php echo htmlspecialchars(__t('title_placeholder')); ?>" required autocomplete="off">
                        </div>
                    </div>

                    <div class="form-field">
                        <label class="form-label" for="custom-code"><?php echo htmlspecialchars(__t('custom_alias')); ?></label>
                        <div class="input-group">
                            <span class="input-icon-left" aria-hidden="true">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>
                            </span>
                            <input type="text" id="custom-code" class="input-text" placeholder="<?php echo htmlspecialchars(__t('custom_alias_placeholder')); ?>" autocomplete="off">
                        </div>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="btn-primary" style="margin-top: 0.25rem;">
                    <span id="btn-text"><?php echo htmlspecialchars(__t('shorten_btn')); ?></span>
                    <div class="spinner" id="btn-spinner" style="display: none;" aria-hidden="true"></div>
                </button>
            </form>

            <!-- Error Banner -->
            <div id="error-container" class="alert-box alert-error" style="display: none;" role="alert">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span id="error-msg"><?php echo htmlspecialchars(__t('unexpected_error')); ?></span>
            </div>

            <!-- Success Result Card -->
            <div id="result-container" class="result-panel" style="display: none;">
                <div class="result-header">
                    <span class="result-title">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        <?php echo htmlspecialchars(__t('link_created')); ?>
                    </span>
                </div>

                <div class="short-url-card">
                    <a href="#" target="_blank" class="short-url-link" id="short-url-display" rel="noopener noreferrer">s.pknstan.id/...</a>
                    <div class="btn-group">
                        <button type="button" class="btn-secondary" id="copy-btn" title="<?php echo htmlspecialchars(__t('copy')); ?>">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <span id="copy-btn-text"><?php echo htmlspecialchars(__t('copy')); ?></span>
                        </button>
                        <button type="button" class="btn-secondary" id="result-qr-toggle-btn" title="<?php echo htmlspecialchars(__t('toggle_qr_preview')); ?>">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                            <span><?php echo htmlspecialchars(__t('qr_code')); ?></span>
                        </button>
                    </div>
                </div>

                <!-- Inline QR Container -->
                <div class="inline-qr-wrap" id="inline-qr-wrap" style="display: none;">
                    <div class="qr-canvas-box" id="inline-qr-canvas"></div>
                    <button type="button" class="btn-secondary" id="inline-qr-download-btn">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span><?php echo htmlspecialchars(__t('download_qr')); ?></span>
                    </button>
                </div>
            </div>

            <!-- Links Inventory Section -->
            <section class="inventory-section">
                <div class="inventory-header">
                    <div class="inventory-title-group">
                        <h2 class="inventory-title">
                            <span><?php echo htmlspecialchars(__t('recent_links')); ?></span>
                            <span class="count-badge" id="links-count-badge"><?php echo count($linksData); ?></span>
                        </h2>
                    </div>

                    <?php if (!empty($linksData) || $searchQuery !== ''): ?>
                    <form method="GET" action="index.php" class="search-form-wrap" id="search-form" role="search">
                        <div class="search-input-group">
                            <span class="search-icon-left" aria-hidden="true">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </span>
                            <input 
                                type="search" 
                                name="q" 
                                id="link-search-input" 
                                class="search-input" 
                                placeholder="<?php echo htmlspecialchars(__t('search_placeholder')); ?>" 
                                value="<?php echo htmlspecialchars($searchQuery); ?>" 
                                autocomplete="off" 
                                spellcheck="false" 
                                aria-label="<?php echo htmlspecialchars(__t('search_links')); ?>"
                            >
                            <button type="button" class="search-clear-btn" id="search-clear-btn" aria-label="<?php echo htmlspecialchars(__t('search_clear')); ?>" title="<?php echo htmlspecialchars(__t('search_clear')); ?>" style="display: <?php echo $searchQuery !== '' ? 'flex' : 'none'; ?>;">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                            <kbd class="search-kbd-hint" id="search-kbd-hint" title="Press / to search">/</kbd>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ($searchQuery !== ''): ?>
                <div class="active-filter-bar">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <span><?php echo htmlspecialchars(__t('search_filter_active', ['query' => $searchQuery])); ?> (<?php echo count($linksData); ?>)</span>
                    </div>
                    <a href="index.php" class="filter-chip-remove">
                        ✕ <?php echo htmlspecialchars(__t('clear_filter')); ?>
                    </a>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="data-table" id="links-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo htmlspecialchars(__t('col_short')); ?></th>
                                <th scope="col"><?php echo htmlspecialchars(__t('col_title_dest')); ?></th>
                                <th scope="col" style="text-align: center;"><?php echo htmlspecialchars(__t('col_clicks')); ?></th>
                                <th scope="col"><?php echo htmlspecialchars(__t('col_date')); ?></th>
                                <th scope="col" style="text-align: right;"><?php echo htmlspecialchars(__t('col_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="links-table-body">
                            <?php if (empty($linksData)): ?>
                                <?php if ($searchQuery !== ''): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <div class="empty-state-icon" aria-hidden="true">
                                                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                            </div>
                                            <div class="empty-state-title"><?php echo htmlspecialchars(__t('search_no_results')); ?> "<?php echo htmlspecialchars($searchQuery); ?>"</div>
                                            <div class="empty-state-desc"><?php echo htmlspecialchars(__t('search_no_results_desc')); ?></div>
                                            <a href="index.php" class="btn-secondary" style="margin-top: 0.5rem; text-decoration: none;">
                                                <?php echo htmlspecialchars(__t('clear_filter')); ?>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <div class="empty-state-icon" aria-hidden="true">
                                                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                            </div>
                                            <div class="empty-state-title"><?php echo htmlspecialchars(__t('empty_links')); ?></div>
                                            <div class="empty-state-desc"><?php echo htmlspecialchars(__t('empty_links_desc')); ?></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Client-side Search No-Results Row -->
                                <tr id="client-search-empty" style="display: none;">
                                    <td colspan="5">
                                        <div class="empty-state" style="padding: 2.25rem 1rem;">
                                            <div class="empty-state-icon" aria-hidden="true">
                                                <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                                    <circle cx="11" cy="11" r="8"></circle>
                                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                </svg>
                                            </div>
                                            <div class="empty-state-title"><?php echo htmlspecialchars(__t('search_no_results')); ?> "<span id="client-empty-query"></span>"</div>
                                            <div class="empty-state-desc"><?php echo htmlspecialchars(__t('search_no_results_desc')); ?></div>
                                            <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; justify-content: center;">
                                                <button type="button" class="btn-secondary" id="client-empty-reset-btn">
                                                    <?php echo htmlspecialchars(__t('search_reset')); ?>
                                                </button>
                                                <button type="submit" form="search-form" class="btn-primary" style="height: 36px; font-size: 0.8125rem;">
                                                    <?php echo htmlspecialchars(__t('search_entire_db')); ?>
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <?php foreach ($linksData as $link): 
                                    $shortUrl = BASE_URL . htmlspecialchars($link['short_code']);
                                    $code = htmlspecialchars($link['short_code']);
                                    $originalUrl = htmlspecialchars($link['original_url']);
                                    $titleText = !empty($link['title']) ? htmlspecialchars($link['title']) : '';

                                    // Targets and fallback info
                                    $targets = $targetsByLinkId[$link['id']] ?? [];
                                    $altTargets = array_filter($targets, fn($t) => (int)$t['priority'] > 1);
                                    $altCount = count($altTargets);
                                    $isFallbackActive = ($link['link_type'] ?? 'direct') === 'fallback' && $altCount > 0;

                                    $p1Target = null;
                                    foreach ($targets as $t) {
                                        if ((int)$t['priority'] === 1) {
                                            $p1Target = $t;
                                            break;
                                        }
                                    }
                                    $isP1Healthy = $p1Target ? ((int)$p1Target['is_healthy'] === 1) : true;
                                ?>
                                <tr class="link-data-row" 
                                    data-short="<?php echo htmlspecialchars(strtolower($link['short_code'])); ?>" 
                                    data-title="<?php echo htmlspecialchars(strtolower($link['title'] ?? '')); ?>" 
                                    data-url="<?php echo htmlspecialchars(strtolower($link['original_url'])); ?>">
                                    <td>
                                        <a href="<?php echo $shortUrl; ?>" target="_blank" rel="noopener noreferrer" class="col-short search-target-short">
                                            /<?php echo $code; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="link-info-stack">
                                            <div style="display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
                                                <?php if ($titleText): ?>
                                                    <span class="link-title-text search-target-title"><?php echo $titleText; ?></span>
                                                <?php endif; ?>
                                                <?php if ($isFallbackActive): ?>
                                                    <span class="badge-fallback" title="<?php echo htmlspecialchars(__t('fallback_flow_hint')); ?>">
                                                        <span class="health-dot <?php echo $isP1Healthy ? 'health-up' : 'health-down'; ?>" title="<?php echo htmlspecialchars($isP1Healthy ? __t('primary_online_tooltip') : __t('primary_down_tooltip')); ?>"></span>
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                                        <?php echo htmlspecialchars(__t('fallback_badge_active', ['count' => $altCount])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="col-url search-target-url" title="<?php echo $originalUrl; ?>">
                                                <?php echo $originalUrl; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="col-clicks">
                                        <?php echo (int)$link['clicks']; ?>
                                    </td>
                                    <td class="col-date">
                                        <?php echo date('d M Y', strtotime($link['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" style="justify-content: flex-end;">
                                            <button
                                                type="button"
                                                class="btn-secondary"
                                                style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;"
                                                onclick="openQRModal('<?php echo $shortUrl; ?>', '<?php echo $code; ?>')"
                                                title="<?php echo htmlspecialchars(__t('qr_code')); ?>"
                                            >
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                                                <span>QR</span>
                                            </button>

                                            <button
                                                type="button"
                                                class="btn-secondary"
                                                style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;"
                                                onclick="openFallbackModal(<?php echo (int)$link['id']; ?>, '<?php echo addslashes($code); ?>', '<?php echo addslashes($titleText); ?>', '<?php echo addslashes($originalUrl); ?>', <?php echo htmlspecialchars(json_encode(array_values($targets)), ENT_QUOTES, 'UTF-8'); ?>)"
                                                title="<?php echo htmlspecialchars(__t('fallback_title')); ?>"
                                            >
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                                <span><?php echo htmlspecialchars(__t('fallback_btn')); ?></span>
                                                <?php if ($altCount > 0): ?>
                                                    <span class="action-count-pill" title="<?php echo htmlspecialchars(__t('fallback_active_tooltip', ['count' => $altCount])); ?>"><?php echo $altCount; ?></span>
                                                <?php endif; ?>
                                            </button>

                                            <button
                                                type="button"
                                                class="btn-secondary"
                                                style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;"
                                                onclick="openEditModal(<?php echo (int)$link['id']; ?>, '<?php echo addslashes($code); ?>', '<?php echo addslashes($originalUrl); ?>', '<?php echo addslashes($titleText); ?>')"
                                                title="<?php echo htmlspecialchars(__t('edit')); ?>"
                                            >
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                <span><?php echo htmlspecialchars(__t('edit')); ?></span>
                                            </button>
                                            
                                            <form method="POST" action="index.php" onsubmit="return confirm('<?php echo addslashes(__t('confirm_delete')); ?>');" style="margin: 0;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                                                <button type="submit" class="btn-danger-ghost" title="<?php echo htmlspecialchars(__t('delete')); ?>">
                                                    <?php echo htmlspecialchars(__t('delete')); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>
        </main>

        <footer class="app-footer">
            <p><?php echo htmlspecialchars(__t('footer_text')); ?></p>
        </footer>
    </div>

    <!-- QR Code Modal Dialog -->
    <div class="modal-backdrop" id="qr-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title" tabindex="-1">
        <div class="modal-dialog centered">
            <button type="button" class="modal-close-btn" id="qr-modal-close-btn" aria-label="<?php echo htmlspecialchars(__t('close')); ?>">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            
            <h2 class="modal-title" id="qr-modal-title"><?php echo htmlspecialchars(__t('qr_code')); ?></h2>
            <div class="modal-url-badge" id="qr-modal-url-text"></div>
            
            <div class="qr-canvas-box" id="qr-modal-canvas"></div>
            
            <div class="modal-actions-row">
                <button type="button" class="btn-primary" id="qr-modal-download-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span><?php echo htmlspecialchars(__t('download_png')); ?></span>
                </button>
                <button type="button" class="btn-secondary" id="qr-modal-copy-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <span id="qr-modal-copy-text"><?php echo htmlspecialchars(__t('copy_url')); ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Link Modal Dialog -->
    <div class="modal-backdrop" id="edit-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title" tabindex="-1">
        <div class="modal-dialog">
            <button type="button" class="modal-close-btn" id="edit-modal-close-btn" aria-label="<?php echo htmlspecialchars(__t('close')); ?>">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            
            <h2 class="modal-title" id="edit-modal-title" style="text-align: left;"><?php echo htmlspecialchars(__t('edit_link_title')); ?></h2>
            
            <form method="POST" action="index.php" class="form-stack">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-modal-id">
                
                <div class="form-field">
                    <label class="form-label" for="edit-modal-title-input"><?php echo htmlspecialchars(__t('title_desc')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="title" id="edit-modal-title-input" class="input-text no-icon" required placeholder="<?php echo htmlspecialchars(__t('title_placeholder')); ?>">
                </div>

                <div class="form-field">
                    <label class="form-label" for="edit-modal-code-input"><?php echo htmlspecialchars(__t('short_alias')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="short_code" id="edit-modal-code-input" class="input-text no-icon" required placeholder="<?php echo htmlspecialchars(__t('custom_alias_placeholder')); ?>">
                </div>

                <div class="form-field">
                    <label class="form-label" for="edit-modal-url-input"><?php echo htmlspecialchars(__t('dest_url')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="url" name="original_url" id="edit-modal-url-input" class="input-text no-icon" required placeholder="<?php echo htmlspecialchars(__t('dest_placeholder')); ?>">
                </div>

                <div class="modal-actions-row">
                    <button type="submit" class="btn-primary">
                        <span><?php echo htmlspecialchars(__t('save_changes')); ?></span>
                    </button>
                    <button type="button" class="btn-secondary" id="edit-modal-cancel-btn">
                        <span><?php echo htmlspecialchars(__t('cancel')); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Fallback & Alternative Links Modal Dialog -->
    <div class="modal-backdrop" id="fallback-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="fallback-modal-title" tabindex="-1">
        <div class="modal-dialog" style="max-width: 540px;">
            <button type="button" class="modal-close-btn" id="fallback-modal-close-btn" aria-label="<?php echo htmlspecialchars(__t('close')); ?>">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            
            <div style="display: flex; align-items: center; gap: 0.625rem;">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(99, 102, 241, 0.15); color: #818cf8; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <div>
                    <h2 class="modal-title" id="fallback-modal-title" style="margin: 0; font-size: 1.125rem; text-align: left;"><?php echo htmlspecialchars(__t('fallback_title')); ?></h2>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-align: left;" id="fallback-modal-shortcode">s.pknstan.id/...</div>
                </div>
            </div>

            <div class="fallback-hint-card">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink: 0; margin-top: 1px;"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span><?php echo htmlspecialchars(__t('fallback_desc')); ?></span>
            </div>
            
            <form method="POST" action="index.php" class="form-stack" id="fallback-form">
                <input type="hidden" name="action" value="save_fallback">
                <input type="hidden" name="id" id="fallback-modal-id">
                
                <!-- Priority 1: Primary Destination URL -->
                <div class="fallback-slot-box">
                    <div class="fallback-slot-header">
                        <span class="slot-label">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #3b82f6;"></span>
                            <?php echo htmlspecialchars(__t('fallback_primary_label')); ?> <span style="color: var(--danger);">*</span>
                        </span>
                        <span id="fb-status-p1" class="health-pill untested"><?php echo htmlspecialchars(__t('status_untested')); ?></span>
                    </div>
                    <input type="url" name="primary_url" id="fb-primary-url" class="input-text no-icon" required placeholder="<?php echo htmlspecialchars(__t('fallback_primary_placeholder')); ?>" style="font-size: 0.8125rem;">
                </div>

                <!-- Priority 2: Alternative URL 1 -->
                <div class="fallback-slot-box">
                    <div class="fallback-slot-header">
                        <span class="slot-label">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #f59e0b;"></span>
                            <?php echo htmlspecialchars(__t('fallback_alt1_label')); ?>
                        </span>
                        <span id="fb-status-p2" class="health-pill untested"><?php echo htmlspecialchars(__t('status_untested')); ?></span>
                    </div>
                    <input type="url" name="alt_url_1" id="fb-alt-1" class="input-text no-icon" placeholder="<?php echo htmlspecialchars(__t('fallback_alt1_placeholder')); ?>" style="font-size: 0.8125rem;">
                </div>

                <!-- Priority 3: Alternative URL 2 -->
                <div class="fallback-slot-box">
                    <div class="fallback-slot-header">
                        <span class="slot-label">
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #a855f7;"></span>
                            <?php echo htmlspecialchars(__t('fallback_alt2_label')); ?>
                        </span>
                        <span id="fb-status-p3" class="health-pill untested"><?php echo htmlspecialchars(__t('status_untested')); ?></span>
                    </div>
                    <input type="url" name="alt_url_2" id="fb-alt-2" class="input-text no-icon" placeholder="<?php echo htmlspecialchars(__t('fallback_alt2_placeholder')); ?>" style="font-size: 0.8125rem;">
                </div>

                <!-- Test Connection Trigger & Result Info -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.25rem;">
                    <button type="button" class="btn-secondary" id="fb-test-conn-btn" style="height: 32px; font-size: 0.75rem; padding: 0 0.75rem;">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span id="fb-test-btn-text"><?php echo htmlspecialchars(__t('fallback_test_btn')); ?></span>
                        <div class="spinner" id="fb-test-spinner" style="display: none; width: 12px; height: 12px;" aria-hidden="true"></div>
                    </button>
                    <span style="font-size: 0.7rem; color: var(--text-muted); text-align: right; max-width: 260px;">
                        <?php echo htmlspecialchars(__t('fallback_clear_hint')); ?>
                    </span>
                </div>

                <div class="modal-actions-row">
                    <button type="submit" class="btn-primary">
                        <span><?php echo htmlspecialchars(__t('fallback_save_btn')); ?></span>
                    </button>
                    <button type="button" class="btn-secondary" id="fallback-modal-cancel-btn">
                        <span><?php echo htmlspecialchars(__t('cancel')); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Application JavaScript Logic -->
    <script>
    /* =====================================================
       Theme Manager
    ===================================================== */
    const ThemeManager = (() => {
        const toggleBtn = document.getElementById('theme-toggle-btn');
        const iconMoon = document.getElementById('theme-icon-moon');
        const iconSun = document.getElementById('theme-icon-sun');

        function updateIcons(theme) {
            if (theme === 'dark') {
                iconMoon.style.display = 'block';
                iconSun.style.display = 'none';
            } else {
                iconMoon.style.display = 'none';
                iconSun.style.display = 'block';
            }
        }

        function init() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
            updateIcons(currentTheme);

            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => {
                    const activeTheme = document.documentElement.getAttribute('data-theme') || 'dark';
                    const newTheme = activeTheme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', newTheme);
                    localStorage.setItem('shortener_theme', newTheme);
                    updateIcons(newTheme);
                });
            }
        }

        return { init };
    })();

    /* =====================================================
       QR Code Engine
    ===================================================== */
    const QRManager = (() => {
        function generate(containerId, text, size) {
            size = size || 180;
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            return new QRCode(container, {
                text: text,
                width: size,
                height: size,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });
        }

        function download(containerId, filename) {
            setTimeout(() => {
                const container = document.getElementById(containerId);
                if (!container) return;
                const canvasEl = container.querySelector('canvas') || container.querySelector('img');
                if (!canvasEl) return;
                
                const link = document.createElement('a');
                link.download = filename;
                link.href = canvasEl.tagName === 'CANVAS' ? canvasEl.toDataURL('image/png') : canvasEl.src;
                link.click();
            }, 80);
        }

        return { generate, download };
    })();

    // Initialize Theme
    ThemeManager.init();

    <?php if ($isLoggedIn): ?>
    /* =====================================================
       Shortener Form Handler
    ===================================================== */
    const shortenerForm = document.getElementById('shortener-form');
    const submitBtn = document.getElementById('submit-btn');
    const btnText = document.getElementById('btn-text');
    const btnSpinner = document.getElementById('btn-spinner');
    const resultContainer = document.getElementById('result-container');
    const errorContainer = document.getElementById('error-container');
    const errorMsg = document.getElementById('error-msg');
    const shortUrlDisplay = document.getElementById('short-url-display');
    const inlineQrWrap = document.getElementById('inline-qr-wrap');

    shortenerForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const urlInput = document.getElementById('long-url').value.trim();
        const titleInput = document.getElementById('link-title').value.trim();
        const customCodeInput = document.getElementById('custom-code').value.trim();

        resultContainer.style.display = 'none';
        errorContainer.style.display = 'none';
        inlineQrWrap.style.display = 'none';

        btnText.style.display = 'none';
        btnSpinner.style.display = 'inline-block';
        submitBtn.disabled = true;

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key': '<?php echo API_KEY; ?>'
                },
                body: JSON.stringify({ url: urlInput, title: titleInput, custom_code: customCodeInput })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                shortUrlDisplay.href = data.short_url;
                shortUrlDisplay.textContent = data.short_url;
                resultContainer.style.display = 'flex';

                // Render Inline QR Preview
                QRManager.generate('inline-qr-canvas', data.short_url, 150);
                inlineQrWrap.style.display = 'flex';
                
                // Clear input fields for next creation
                document.getElementById('long-url').value = '';
                document.getElementById('link-title').value = '';
                document.getElementById('custom-code').value = '';
            } else {
                throw new Error(data.error || '<?php echo addslashes(__t('error_shorten_failed')); ?>');
            }
        } catch (err) {
            errorMsg.textContent = err.message;
            errorContainer.style.display = 'flex';
        } finally {
            btnText.style.display = 'inline';
            btnSpinner.style.display = 'none';
            submitBtn.disabled = false;
        }
    });

    /* =====================================================
       Result Panel Actions
    ===================================================== */
    const copyBtn = document.getElementById('copy-btn');
    const copyBtnText = document.getElementById('copy-btn-text');

    copyBtn.addEventListener('click', function() {
        const textToCopy = shortUrlDisplay.textContent;
        navigator.clipboard.writeText(textToCopy).then(() => {
            copyBtnText.textContent = '<?php echo addslashes(__t('copied')); ?>';
            setTimeout(() => {
                copyBtnText.textContent = '<?php echo addslashes(__t('copy')); ?>';
            }, 2000);
        }).catch(() => {
            copyBtnText.textContent = 'Failed';
            setTimeout(() => { copyBtnText.textContent = '<?php echo addslashes(__t('copy')); ?>'; }, 2000);
        });
    });

    const qrToggleBtn = document.getElementById('result-qr-toggle-btn');
    qrToggleBtn.addEventListener('click', function() {
        const isHidden = inlineQrWrap.style.display === 'none' || inlineQrWrap.style.display === '';
        if (isHidden) {
            inlineQrWrap.style.display = 'flex';
            const url = shortUrlDisplay.href;
            if (url && url !== '#') {
                QRManager.generate('inline-qr-canvas', url, 150);
            }
        } else {
            inlineQrWrap.style.display = 'none';
        }
    });

    const inlineDownloadBtn = document.getElementById('inline-qr-download-btn');
    inlineDownloadBtn.addEventListener('click', function() {
        const url = shortUrlDisplay.href;
        const filename = 'qr-' + url.replace(/https?:\/\//, '').replace(/[\/\s]/g, '-') + '.png';
        QRManager.download('inline-qr-canvas', filename);
    });

    /* =====================================================
       QR Modal Dialog Controller
    ===================================================== */
    const qrModalBackdrop = document.getElementById('qr-modal-backdrop');
    const qrModalCloseBtn = document.getElementById('qr-modal-close-btn');
    const qrModalUrlText = document.getElementById('qr-modal-url-text');
    const qrModalDownloadBtn = document.getElementById('qr-modal-download-btn');
    const qrModalCopyBtn = document.getElementById('qr-modal-copy-btn');
    const qrModalCopyText = document.getElementById('qr-modal-copy-text');

    let currentModalUrl = '';

    function openQRModal(fullUrl, code) {
        currentModalUrl = fullUrl;
        qrModalUrlText.textContent = fullUrl;
        QRManager.generate('qr-modal-canvas', fullUrl, 200);
        qrModalBackdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        qrModalCloseBtn.focus();
    }

    function closeQRModal() {
        qrModalBackdrop.classList.remove('is-open');
        document.body.style.overflow = '';
        setTimeout(() => {
            const canvasContainer = document.getElementById('qr-modal-canvas');
            if (canvasContainer) canvasContainer.innerHTML = '';
        }, 200);
    }

    qrModalCloseBtn.addEventListener('click', closeQRModal);
    qrModalBackdrop.addEventListener('click', (e) => {
        if (e.target === qrModalBackdrop) closeQRModal();
    });

    qrModalDownloadBtn.addEventListener('click', () => {
        const filename = 'qr-' + currentModalUrl.replace(/https?:\/\//, '').replace(/[\/\s]/g, '-') + '.png';
        QRManager.download('qr-modal-canvas', filename);
    });

    qrModalCopyBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(currentModalUrl).then(() => {
            qrModalCopyText.textContent = '<?php echo addslashes(__t('copied')); ?>';
            setTimeout(() => { qrModalCopyText.textContent = '<?php echo addslashes(__t('copy_url')); ?>'; }, 2000);
        });
    });

    /* =====================================================
       Edit Modal Dialog Controller
    ===================================================== */
    const editModalBackdrop = document.getElementById('edit-modal-backdrop');
    const editModalCloseBtn = document.getElementById('edit-modal-close-btn');
    const editModalCancelBtn = document.getElementById('edit-modal-cancel-btn');
    const editModalIdInput = document.getElementById('edit-modal-id');
    const editModalCodeInput = document.getElementById('edit-modal-code-input');
    const editModalUrlInput = document.getElementById('edit-modal-url-input');
    const editModalTitleInput = document.getElementById('edit-modal-title-input');

    function openEditModal(id, code, url, title) {
        editModalIdInput.value = id;
        editModalCodeInput.value = code;
        editModalUrlInput.value = url;
        editModalTitleInput.value = title || '';
        editModalBackdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        editModalTitleInput.focus();
    }

    function closeEditModal() {
        editModalBackdrop.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    editModalCloseBtn.addEventListener('click', closeEditModal);
    editModalCancelBtn.addEventListener('click', closeEditModal);
    editModalBackdrop.addEventListener('click', (e) => {
        if (e.target === editModalBackdrop) closeEditModal();
    });

    /* =====================================================
       Fallback Modal Dialog Controller
    ===================================================== */
    const fallbackModalBackdrop = document.getElementById('fallback-modal-backdrop');
    const fallbackModalCloseBtn = document.getElementById('fallback-modal-close-btn');
    const fallbackModalCancelBtn = document.getElementById('fallback-modal-cancel-btn');
    const fallbackModalIdInput = document.getElementById('fallback-modal-id');
    const fallbackModalShortcode = document.getElementById('fallback-modal-shortcode');
    const fbPrimaryUrlInput = document.getElementById('fb-primary-url');
    const fbAlt1Input = document.getElementById('fb-alt-1');
    const fbAlt2Input = document.getElementById('fb-alt-2');
    const fbStatusP1 = document.getElementById('fb-status-p1');
    const fbStatusP2 = document.getElementById('fb-status-p2');
    const fbStatusP3 = document.getElementById('fb-status-p3');
    const fbTestConnBtn = document.getElementById('fb-test-conn-btn');
    const fbTestBtnText = document.getElementById('fb-test-btn-text');
    const fbTestSpinner = document.getElementById('fb-test-spinner');

    function renderHealthPill(element, isHealthy, statusCode, responseTimeMs, isChecking) {
        if (!element) return;
        if (isChecking) {
            element.className = 'health-pill checking';
            element.textContent = '<?php echo addslashes(__t('status_checking')); ?>';
            return;
        }
        if (statusCode === null || statusCode === undefined) {
            element.className = 'health-pill untested';
            element.textContent = '<?php echo addslashes(__t('status_untested')); ?>';
            return;
        }
        if (isHealthy) {
            element.className = 'health-pill up';
            element.innerHTML = '● <?php echo addslashes(__t('status_online')); ?> (' + statusCode + (responseTimeMs ? ' - ' + responseTimeMs + 'ms' : '') + ')';
        } else {
            element.className = 'health-pill down';
            element.innerHTML = '▲ <?php echo addslashes(__t('status_offline')); ?> (' + (statusCode ? statusCode : 'Timeout') + ')';
        }
    }

    function openFallbackModal(id, code, title, primaryUrl, targets) {
        fallbackModalIdInput.value = id;
        fallbackModalShortcode.textContent = 's.pknstan.id/' + code + (title ? ' • ' + title : '');
        fbPrimaryUrlInput.value = primaryUrl || '';
        fbAlt1Input.value = '';
        fbAlt2Input.value = '';

        renderHealthPill(fbStatusP1, null, null);
        renderHealthPill(fbStatusP2, null, null);
        renderHealthPill(fbStatusP3, null, null);

        // Populate targets if present
        if (Array.isArray(targets) && targets.length > 0) {
            targets.forEach(t => {
                const priority = parseInt(t.priority, 10);
                const isHealthy = parseInt(t.is_healthy, 10) === 1;
                const statusCode = t.last_status_code ? parseInt(t.last_status_code, 10) : (t.last_checked_at ? 0 : null);
                const respTime = t.response_time_ms ? parseInt(t.response_time_ms, 10) : null;

                if (priority === 1) {
                    if (t.url) fbPrimaryUrlInput.value = t.url;
                    renderHealthPill(fbStatusP1, isHealthy, statusCode, respTime);
                } else if (priority === 2) {
                    fbAlt1Input.value = t.url || '';
                    renderHealthPill(fbStatusP2, isHealthy, statusCode, respTime);
                } else if (priority === 3) {
                    fbAlt2Input.value = t.url || '';
                    renderHealthPill(fbStatusP3, isHealthy, statusCode, respTime);
                }
            });
        }

        fallbackModalBackdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        fbPrimaryUrlInput.focus();
    }

    function closeFallbackModal() {
        fallbackModalBackdrop.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    if (fallbackModalCloseBtn) fallbackModalCloseBtn.addEventListener('click', closeFallbackModal);
    if (fallbackModalCancelBtn) fallbackModalCancelBtn.addEventListener('click', closeFallbackModal);
    if (fallbackModalBackdrop) {
        fallbackModalBackdrop.addEventListener('click', (e) => {
            if (e.target === fallbackModalBackdrop) closeFallbackModal();
        });
    }

    // Test Connection Button Trigger
    if (fbTestConnBtn) {
        fbTestConnBtn.addEventListener('click', async () => {
            const u1 = fbPrimaryUrlInput.value.trim();
            const u2 = fbAlt1Input.value.trim();
            const u3 = fbAlt2Input.value.trim();

            const urlsToTest = [];
            if (u1) { urlsToTest.push(u1); renderHealthPill(fbStatusP1, null, null, null, true); }
            if (u2) { urlsToTest.push(u2); renderHealthPill(fbStatusP2, null, null, null, true); } else { renderHealthPill(fbStatusP2, null, null); }
            if (u3) { urlsToTest.push(u3); renderHealthPill(fbStatusP3, null, null, null, true); } else { renderHealthPill(fbStatusP3, null, null); }

            if (urlsToTest.length === 0) return;

            fbTestBtnText.style.display = 'none';
            fbTestSpinner.style.display = 'inline-block';
            fbTestConnBtn.disabled = true;

            try {
                const resp = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'test_health', urls: urlsToTest })
                });
                const data = await resp.json();
                if (data.success && Array.isArray(data.results)) {
                    data.results.forEach(res => {
                        if (res.url === u1) {
                            renderHealthPill(fbStatusP1, res.is_healthy, res.http_code, res.response_time_ms);
                        } else if (res.url === u2) {
                            renderHealthPill(fbStatusP2, res.is_healthy, res.http_code, res.response_time_ms);
                        } else if (res.url === u3) {
                            renderHealthPill(fbStatusP3, res.is_healthy, res.http_code, res.response_time_ms);
                        }
                    });
                }
            } catch (err) {
                console.error('Test health error:', err);
            } finally {
                fbTestBtnText.style.display = 'inline';
                fbTestSpinner.style.display = 'none';
                fbTestConnBtn.disabled = false;
            }
        });
    }

    /* =====================================================
       Link Search & Instant Filter Manager
    ===================================================== */
    const SearchManager = (() => {
        const searchInput = document.getElementById('link-search-input');
        const clearBtn = document.getElementById('search-clear-btn');
        const kbdHint = document.getElementById('search-kbd-hint');
        const countBadge = document.getElementById('links-count-badge');
        const emptyRow = document.getElementById('client-search-empty');
        const emptyQuerySpan = document.getElementById('client-empty-query');
        const emptyResetBtn = document.getElementById('client-empty-reset-btn');
        const tableBody = document.getElementById('links-table-body');

        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function highlightElement(el, words) {
            if (!el) return;
            if (!el.hasAttribute('data-original-html')) {
                el.setAttribute('data-original-html', el.innerHTML);
            }

            if (!words || words.length === 0) {
                el.innerHTML = el.getAttribute('data-original-html');
                return;
            }

            const baseText = el.getAttribute('data-original-html').replace(/<\/?mark[^>]*>/gi, '');
            const pattern = words.map(w => escapeRegExp(w)).join('|');
            const regex = new RegExp(`(${pattern})`, 'gi');
            el.innerHTML = baseText.replace(regex, '<mark class="search-highlight">$1</mark>');
        }

        function clearElementHighlight(el) {
            if (!el) return;
            if (el.hasAttribute('data-original-html')) {
                el.innerHTML = el.getAttribute('data-original-html');
            }
        }

        function filterRows() {
            if (!searchInput) return;
            const rawVal = searchInput.value;
            const query = rawVal.trim().toLowerCase();

            // Toggle clear button and kbd badge
            if (clearBtn) {
                clearBtn.style.display = rawVal.length > 0 ? 'flex' : 'none';
            }
            if (kbdHint) {
                kbdHint.style.display = rawVal.length > 0 ? 'none' : 'block';
            }

            const rows = tableBody ? Array.from(tableBody.querySelectorAll('.link-data-row')) : [];
            const total = rows.length;

            if (query === '') {
                rows.forEach(row => {
                    row.style.display = '';
                    row.querySelectorAll('.search-target-short, .search-target-title, .search-target-url').forEach(clearElementHighlight);
                });
                if (emptyRow) emptyRow.style.display = 'none';
                if (countBadge) countBadge.textContent = total;
                return;
            }

            const words = query.split(/\s+/).filter(Boolean);
            let matchCount = 0;

            rows.forEach(row => {
                const shortVal = (row.dataset.short || '');
                const titleVal = (row.dataset.title || '');
                const urlVal = (row.dataset.url || '');
                const haystack = `${shortVal} ${titleVal} ${urlVal}`;

                const isMatch = words.every(w => haystack.includes(w));

                if (isMatch) {
                    row.style.display = '';
                    matchCount++;
                    row.querySelectorAll('.search-target-short, .search-target-title, .search-target-url').forEach(el => highlightElement(el, words));
                } else {
                    row.style.display = 'none';
                    row.querySelectorAll('.search-target-short, .search-target-title, .search-target-url').forEach(clearElementHighlight);
                }
            });

            if (countBadge) {
                countBadge.textContent = `${matchCount} / ${total}`;
            }

            if (emptyRow) {
                if (matchCount === 0) {
                    emptyRow.style.display = '';
                    if (emptyQuerySpan) emptyQuerySpan.textContent = rawVal.trim();
                } else {
                    emptyRow.style.display = 'none';
                }
            }
        }

        function reset() {
            if (!searchInput) return;
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('q')) {
                urlParams.delete('q');
                const newSearch = urlParams.toString();
                window.location.href = window.location.pathname + (newSearch ? '?' + newSearch : '');
                return;
            }
            searchInput.value = '';
            filterRows();
            searchInput.focus();
        }

        function init() {
            if (!searchInput) return;

            searchInput.addEventListener('input', filterRows);
            searchInput.addEventListener('search', filterRows);

            if (clearBtn) {
                clearBtn.addEventListener('click', reset);
            }

            if (emptyResetBtn) {
                emptyResetBtn.addEventListener('click', reset);
            }

            // Keyboard shortcut: '/' focuses search input if not already typing in an input
            document.addEventListener('keydown', (e) => {
                const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
                const isFormInput = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select';

                if (e.key === '/' && !isFormInput) {
                    e.preventDefault();
                    searchInput.focus();
                    searchInput.select();
                } else if (e.key === 'Escape' && document.activeElement === searchInput) {
                    if (searchInput.value !== '') {
                        reset();
                    } else {
                        searchInput.blur();
                    }
                }
            });

            // Initial filter run if input already populated (e.g. from server query)
            if (searchInput.value.trim() !== '') {
                filterRows();
            }
        }

        return { init, reset, filterRows };
    })();

    SearchManager.init();

    /* =====================================================
       Global Keyboard Listeners
    ===================================================== */
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (qrModalBackdrop && qrModalBackdrop.classList.contains('is-open')) {
                closeQRModal();
            }
            if (editModalBackdrop && editModalBackdrop.classList.contains('is-open')) {
                closeEditModal();
            }
            if (fallbackModalBackdrop && fallbackModalBackdrop.classList.contains('is-open')) {
                closeFallbackModal();
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
