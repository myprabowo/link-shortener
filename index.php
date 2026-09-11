<?php
// index.php
session_start();
require_once 'config.php';

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
        $loginError = 'Incorrect username or password. Please try again.';
    }
}

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$actionMessage = '';
$actionError = '';

if (isset($_GET['updated'])) {
    $actionMessage = 'Link updated successfully.';
}

// Handle Delete & Edit Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $isLoggedIn) {
    if ($_POST['action'] === 'delete') {
        if (isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            header("Location: index.php");
            exit;
        }
    } elseif ($_POST['action'] === 'edit') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $url = filter_var($_POST['original_url'] ?? '', FILTER_VALIDATE_URL);
        $customCode = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['short_code'] ?? ''));
        $title = !empty($_POST['title']) ? trim(strip_tags($_POST['title'])) : null;

        if (!$id || !$url || empty($customCode)) {
            $actionError = 'Invalid data provided for link edit.';
        } else {
            try {
                // Check if custom code belongs to another link
                $checkStmt = $pdo->prepare("SELECT id FROM links WHERE short_code = ? AND id != ?");
                $checkStmt->execute([$customCode, $id]);
                if ($checkStmt->rowCount() > 0) {
                    $actionError = 'The short code "/' . htmlspecialchars($customCode) . '" is already in use by another link.';
                } else {
                    $updateStmt = $pdo->prepare("UPDATE links SET short_code = ?, original_url = ?, title = ? WHERE id = ?");
                    $updateStmt->execute([$customCode, $url, $title, $id]);
                    header("Location: index.php?updated=1");
                    exit;
                }
            } catch (PDOException $e) {
                $actionError = 'Database error while saving changes.';
            }
        }
    }
}

$linksData = [];
if ($isLoggedIn) {
    try {
        $stmt = $pdo->query("SELECT id, short_code, original_url, title, clicks, created_at FROM links ORDER BY created_at DESC LIMIT 100");
        $linksData = $stmt->fetchAll();
    } catch (PDOException $e) {
        $linksData = [];
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKNSTAN Link Shortener</title>
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
            max-width: <?php echo $isLoggedIn ? '920px' : '480px'; ?>;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            margin: auto 0;
        }

        /* App Header & Brand */
        .app-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: var(--text-primary);
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            background: var(--accent-subtle);
            border: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            flex-shrink: 0;
        }

        .brand-title {
            font-size: 1.125rem;
            font-weight: 700;
            letter-spacing: -0.015em;
            line-height: 1.2;
        }

        .brand-badge {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Utility Buttons */
        .btn-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
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
            text-align: left;
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
            max-width: 320px;
        }

        .link-title-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .col-url {
            max-width: 320px;
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

    <div class="app-layout">
        <!-- App Header -->
        <header class="app-header">
            <a href="index.php" class="brand-block" title="PKNSTAN Link Shortener">
                <div class="brand-icon" aria-hidden="true">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                </div>
                <div>
                    <div class="brand-title">s.pknstan.id</div>
                    <div class="brand-badge">Link Shortener</div>
                </div>
            </a>

            <div class="header-actions">
                <!-- Theme Toggle Button -->
                <button type="button" class="btn-icon" id="theme-toggle-btn" aria-label="Toggle light or dark theme" title="Toggle theme">
                    <svg id="theme-icon-moon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <svg id="theme-icon-sun" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display: none;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </button>

                <?php if ($isLoggedIn): ?>
                <a href="logout.php" class="btn-outline" title="Log out from administration">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    <span>Logout</span>
                </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Main Workspace Card -->
        <main class="surface-card">
            <?php if (!$isLoggedIn): ?>
            <!-- Login View -->
            <div class="card-header">
                <h1 class="card-title">Administrator Login</h1>
                <p class="card-desc">Sign in to create, manage, and track shortened links.</p>
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
                    <label class="form-label" for="username">Username</label>
                    <div class="input-group">
                        <span class="input-icon-left" aria-hidden="true">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </span>
                        <input type="text" name="username" id="username" class="input-text" placeholder="Enter administrator username" required autocomplete="username">
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-icon-left" aria-hidden="true">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </span>
                        <input type="password" name="password" id="password" class="input-text" placeholder="Enter password" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top: 0.5rem;">
                    <span>Sign In</span>
                </button>
            </form>

            <?php else: ?>
            <!-- Authenticated Shortener View -->
            <div class="card-header">
                <h1 class="card-title">Create Short Link</h1>
                <p class="card-desc">Generate a concise link, title, and QR code for any URL.</p>
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
                    <label class="form-label" for="long-url">Destination URL <span style="color: var(--danger);">*</span></label>
                    <div class="input-group">
                        <span class="input-icon-left" aria-hidden="true">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        </span>
                        <input type="url" id="long-url" class="input-text" placeholder="https://example.com/very-long-path" required autocomplete="off">
                    </div>
                </div>

                <div class="form-row two-col">
                    <div class="form-field">
                        <label class="form-label" for="link-title">Title / Description (Optional)</label>
                        <div class="input-group">
                            <span class="input-icon-left" aria-hidden="true">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                            </span>
                            <input type="text" id="link-title" class="input-text" placeholder="e.g. STAN Registration Guide" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-field">
                        <label class="form-label" for="custom-code">Custom Alias (Optional)</label>
                        <div class="input-group">
                            <span class="input-icon-left" aria-hidden="true">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>
                            </span>
                            <input type="text" id="custom-code" class="input-text" placeholder="e.g. stan-reg" autocomplete="off">
                        </div>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="btn-primary" style="margin-top: 0.25rem;">
                    <span id="btn-text">Shorten Link</span>
                    <div class="spinner" id="btn-spinner" style="display: none;" aria-hidden="true"></div>
                </button>
            </form>

            <!-- Error Banner -->
            <div id="error-container" class="alert-box alert-error" style="display: none;" role="alert">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span id="error-msg">An unexpected error occurred.</span>
            </div>

            <!-- Success Result Card -->
            <div id="result-container" class="result-panel" style="display: none;">
                <div class="result-header">
                    <span class="result-title">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Link created successfully
                    </span>
                </div>

                <div class="short-url-card">
                    <a href="#" target="_blank" class="short-url-link" id="short-url-display" rel="noopener noreferrer">s.pknstan.id/...</a>
                    <div class="btn-group">
                        <button type="button" class="btn-secondary" id="copy-btn" title="Copy shortened URL">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <span id="copy-btn-text">Copy</span>
                        </button>
                        <button type="button" class="btn-secondary" id="result-qr-toggle-btn" title="Toggle QR Code preview">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                            <span>QR Code</span>
                        </button>
                    </div>
                </div>

                <!-- Inline QR Container -->
                <div class="inline-qr-wrap" id="inline-qr-wrap" style="display: none;">
                    <div class="qr-canvas-box" id="inline-qr-canvas"></div>
                    <button type="button" class="btn-secondary" id="inline-qr-download-btn">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download QR (PNG)</span>
                    </button>
                </div>
            </div>

            <!-- Links Inventory Section -->
            <section class="inventory-section">
                <div class="inventory-header">
                    <h2 class="inventory-title">
                        <span>Recent Links</span>
                        <span class="count-badge"><?php echo count($linksData); ?></span>
                    </h2>
                </div>

                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Short Link</th>
                                <th scope="col">Title & Destination</th>
                                <th scope="col" style="text-align: center;">Clicks</th>
                                <th scope="col">Created Date</th>
                                <th scope="col" style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($linksData)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <div class="empty-state-icon" aria-hidden="true">
                                            <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                        </div>
                                        <div class="empty-state-title">No shortened links yet</div>
                                        <div class="empty-state-desc">Enter a destination URL above to generate your first link and QR code.</div>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($linksData as $link): 
                                    $shortUrl = BASE_URL . htmlspecialchars($link['short_code']);
                                    $code = htmlspecialchars($link['short_code']);
                                    $originalUrl = htmlspecialchars($link['original_url']);
                                    $titleText = !empty($link['title']) ? htmlspecialchars($link['title']) : '';
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo $shortUrl; ?>" target="_blank" rel="noopener noreferrer" class="col-short">
                                            /<?php echo $code; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="link-info-stack">
                                            <?php if ($titleText): ?>
                                                <span class="link-title-text"><?php echo $titleText; ?></span>
                                            <?php endif; ?>
                                            <span class="col-url" title="<?php echo $originalUrl; ?>">
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
                                                title="View and download QR code"
                                            >
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                                                <span>QR</span>
                                            </button>

                                            <button
                                                type="button"
                                                class="btn-secondary"
                                                style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;"
                                                onclick="openEditModal(<?php echo (int)$link['id']; ?>, '<?php echo addslashes($code); ?>', '<?php echo addslashes($originalUrl); ?>', '<?php echo addslashes($titleText); ?>')"
                                                title="Edit link details"
                                            >
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                <span>Edit</span>
                                            </button>
                                            
                                            <form method="POST" action="index.php" onsubmit="return confirm('Are you sure you want to delete /<?php echo $code; ?>?');" style="margin: 0;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                                                <button type="submit" class="btn-danger-ghost" title="Delete short link">
                                                    Delete
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
            <p>&copy; <?php echo date("Y"); ?> Muhammad Yoga Prabowo &bull; s.pknstan.id</p>
        </footer>
    </div>

    <!-- QR Code Modal Dialog -->
    <div class="modal-backdrop" id="qr-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title" tabindex="-1">
        <div class="modal-dialog centered">
            <button type="button" class="modal-close-btn" id="qr-modal-close-btn" aria-label="Close QR Code dialog">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            
            <h2 class="modal-title" id="qr-modal-title">QR Code</h2>
            <div class="modal-url-badge" id="qr-modal-url-text"></div>
            
            <div class="qr-canvas-box" id="qr-modal-canvas"></div>
            
            <div class="modal-actions-row">
                <button type="button" class="btn-primary" id="qr-modal-download-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Download PNG</span>
                </button>
                <button type="button" class="btn-secondary" id="qr-modal-copy-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <span id="qr-modal-copy-text">Copy URL</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Link Modal Dialog -->
    <div class="modal-backdrop" id="edit-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title" tabindex="-1">
        <div class="modal-dialog">
            <button type="button" class="modal-close-btn" id="edit-modal-close-btn" aria-label="Close edit dialog">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            
            <h2 class="modal-title" id="edit-modal-title" style="text-align: left;">Edit Short Link</h2>
            
            <form method="POST" action="index.php" class="form-stack">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-modal-id">
                
                <div class="form-field">
                    <label class="form-label" for="edit-modal-title-input">Title / Description</label>
                    <input type="text" name="title" id="edit-modal-title-input" class="input-text no-icon" placeholder="e.g. STAN Registration Guide">
                </div>

                <div class="form-field">
                    <label class="form-label" for="edit-modal-code-input">Short Alias <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="short_code" id="edit-modal-code-input" class="input-text no-icon" required placeholder="e.g. stan-reg">
                </div>

                <div class="form-field">
                    <label class="form-label" for="edit-modal-url-input">Destination URL <span style="color: var(--danger);">*</span></label>
                    <input type="url" name="original_url" id="edit-modal-url-input" class="input-text no-icon" required placeholder="https://example.com/long-url">
                </div>

                <div class="modal-actions-row">
                    <button type="submit" class="btn-primary">
                        <span>Save Changes</span>
                    </button>
                    <button type="button" class="btn-secondary" id="edit-modal-cancel-btn">
                        <span>Cancel</span>
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
                throw new Error(data.error || 'Failed to shorten URL. Please check input values.');
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
            copyBtnText.textContent = 'Copied!';
            setTimeout(() => {
                copyBtnText.textContent = 'Copy';
            }, 2000);
        }).catch(() => {
            copyBtnText.textContent = 'Failed';
            setTimeout(() => { copyBtnText.textContent = 'Copy'; }, 2000);
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
            qrModalCopyText.textContent = 'Copied!';
            setTimeout(() => { qrModalCopyText.textContent = 'Copy URL'; }, 2000);
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
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
