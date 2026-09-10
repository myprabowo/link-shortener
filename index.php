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
        $loginError = 'Incorrect username or password!';
    }
}

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $isLoggedIn) {
    if (isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header("Location: index.php");
        exit;
    }
}

$linksData = [];
if ($isLoggedIn) {
    try {
        $stmt = $pdo->query("SELECT id, short_code, original_url, clicks, created_at FROM links ORDER BY created_at DESC LIMIT 100");
        $linksData = $stmt->fetchAll();
    } catch (PDOException $e) {
        $linksData = [];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKNSTAN - Link Shortener</title>
    <meta name="description" content="Official link shortener application for pknstan.my.id">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <style>
        :root {
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.1);
            --success: #10b981;
            --error: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .container {
            width: 100%;
            max-width: <?php echo $isLoggedIn ? '900px' : '600px'; ?>;
            padding: 2rem;
            z-index: 10;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 3rem 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.025em;
        }

        p.subtitle {
            color: var(--text-muted);
            margin-bottom: 2.5rem;
            font-size: 1.1rem;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            position: relative;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1.25rem;
            color: var(--text-muted);
        }

        input[type="url"], input.custom-input {
            width: 100%;
            padding: 1.25rem 1.25rem 1.25rem 3.5rem;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            background: rgba(15, 23, 42, 0.6);
            color: var(--text-main);
            font-size: 1.1rem;
            outline: none;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }

        input[type="url"]:focus, input.custom-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2), inset 0 2px 4px rgba(0,0,0,0.1);
            background: rgba(15, 23, 42, 0.8);
        }

        button {
            background: linear-gradient(135deg, var(--primary), #6366f1);
            color: white;
            border: none;
            padding: 1.25rem;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.39);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.5);
            background: linear-gradient(135deg, var(--primary-hover), #4f46e5);
        }

        button:active { transform: translateY(0); }

        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .result-container {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: 16px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            display: none;
            flex-direction: column;
            gap: 1rem;
            animation: slideUp 0.4s ease forwards;
            opacity: 0;
            transform: translateY(10px);
        }

        .error-container {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        @keyframes slideUp { to { opacity: 1; transform: translateY(0); } }

        /* Table */
        .table-container {
            margin-top: 2rem;
            width: 100%;
            overflow-x: auto;
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid var(--border-color);
        }
        .table-container table { width: 100%; border-collapse: collapse; text-align: left; }
        .table-container th, .table-container td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            font-size: 0.95rem;
        }
        .table-container th { background: rgba(255,255,255,0.05); font-weight: 600; white-space: nowrap; }
        .table-container tr:last-child td { border-bottom: none; }
        .table-container tr:hover td { background: rgba(255,255,255,0.02); }

        .truncate {
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: middle;
        }

        .table-link { color: #60a5fa; text-decoration: none; font-weight: 500; }
        .table-link:hover { text-decoration: underline; }

        .short-url-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            background: rgba(0,0,0,0.3);
            padding: 1rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .short-url {
            color: #60a5fa;
            font-weight: 500;
            font-size: 1.1rem;
            text-decoration: none;
            word-break: break-all;
            flex: 1;
        }
        .short-url:hover { text-decoration: underline; }

        .btn-group { display: flex; gap: 0.5rem; flex-shrink: 0; }

        .copy-btn {
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            box-shadow: none;
        }
        .copy-btn:hover { background: rgba(255,255,255,0.2); transform: none; box-shadow: none; }

        /* QR Toggle button (result area) */
        .qr-toggle-btn {
            background: rgba(139,92,246,0.2);
            border: 1px solid rgba(139,92,246,0.35);
            color: #c4b5fd;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            box-shadow: none;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .qr-toggle-btn:hover { background: rgba(139,92,246,0.35); transform: none; box-shadow: none; }

        /* QR Preview section inside result */
        .qr-preview-section {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 0.85rem;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255,255,255,0.08);
            animation: slideUp 0.3s ease forwards;
        }

        .qr-preview-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        #qr-preview-canvas {
            border-radius: 12px;
            padding: 12px;
            background: white;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 8px 24px rgba(0,0,0,0.4);
        }
        #qr-preview-canvas canvas, #qr-preview-canvas img { display: block; border-radius: 4px; }

        .qr-download-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            padding: 0.6rem 1.25rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(16,185,129,0.3);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .qr-download-btn:hover { background: linear-gradient(135deg, #059669, #047857); box-shadow: 0 6px 16px rgba(16,185,129,0.45); }

        /* QR button in table rows */
        .qr-show-btn {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            color: white;
            border: none;
            padding: 0.38rem 0.8rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .qr-show-btn:hover { background: linear-gradient(135deg, #7c3aed, #5b21b6); box-shadow: 0 4px 12px rgba(139,92,246,0.35); transform: translateY(-1px); }

        .action-group { display: flex; align-items: center; justify-content: flex-end; gap: 0.5rem; }

        /* QR Modal */
        .qr-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .qr-modal-overlay.active { opacity: 1; pointer-events: all; }

        .qr-modal {
            background: rgba(15,23,42,0.97);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 24px;
            padding: 2.5rem 2rem;
            max-width: 420px;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            box-shadow: 0 32px 64px rgba(0,0,0,0.6);
            transform: scale(0.9) translateY(20px);
            transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1);
            position: relative;
        }
        .qr-modal-overlay.active .qr-modal { transform: scale(1) translateY(0); }

        .qr-modal-close {
            position: absolute;
            top: 1rem; right: 1rem;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50%;
            width: 36px; height: 36px;
            padding: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            transition: all 0.2s ease;
            box-shadow: none;
            font-size: 1rem;
        }
        .qr-modal-close:hover { background: rgba(255,255,255,0.15); color: var(--text-main); transform: none; box-shadow: none; }

        .qr-modal-header { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; width: 100%; }
        .qr-modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text-main); }
        .qr-modal-url {
            font-size: 0.82rem;
            color: var(--text-muted);
            text-align: center;
            word-break: break-all;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            width: 100%;
        }

        #qr-modal-canvas {
            border-radius: 16px;
            padding: 16px;
            background: white;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 12px 32px rgba(0,0,0,0.5);
        }
        #qr-modal-canvas canvas, #qr-modal-canvas img { display: block; border-radius: 6px; }

        .qr-modal-actions { display: flex; gap: 0.75rem; width: 100%; }

        .qr-modal-download {
            flex: 1;
            background: linear-gradient(135deg, #10b981, #059669);
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            box-shadow: 0 4px 14px rgba(16,185,129,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .qr-modal-download:hover { background: linear-gradient(135deg, #059669, #047857); box-shadow: 0 6px 18px rgba(16,185,129,0.45); transform: translateY(-1px); }

        .qr-modal-copy {
            flex: 1;
            background: rgba(255,255,255,0.08);
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            box-shadow: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .qr-modal-copy:hover { background: rgba(255,255,255,0.14); transform: none; box-shadow: none; }

        footer { margin-top: 3rem; color: var(--text-muted); font-size: 0.9rem; text-align: center; }

        .blob { position: absolute; filter: blur(80px); z-index: 0; opacity: 0.5; }
        .blob-1 { top: -10%; right: -10%; width: 300px; height: 300px; background: #4f46e5; border-radius: 50%; }
        .blob-2 { bottom: -10%; left: -10%; width: 250px; height: 250px; background: #db2777; border-radius: 50%; }

        @media (min-width: 768px) {
            .input-group { flex-direction: row; }
            button { width: 140px; }
            .input-wrapper { flex: 1; }
        }
    </style>
</head>
<body>
    <div style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; overflow: hidden; z-index: -1; pointer-events: none;">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
    </div>

    <div class="container">
        <?php if ($isLoggedIn): ?>
        <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
            <a href="logout.php" style="color: var(--text-muted); text-decoration: none; font-size: 0.9rem; background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 8px; transition: all 0.3s ease;">Logout</a>
        </div>
        <?php endif; ?>

        <div class="glass-card">
            <h1>Link Shortener</h1>
            
            <?php if (!$isLoggedIn): ?>
            <p class="subtitle">Please login to shorten links</p>
            
            <?php if ($loginError): ?>
            <div class="result-container error-container" style="display: flex; margin-bottom: 1.5rem; margin-top: 0; opacity: 1; transform: translateY(0);">
                <p><?php echo htmlspecialchars($loginError); ?></p>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <div class="input-group" style="flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                    <input type="text" name="username" placeholder="Username" required autocomplete="off" style="width: 100%; padding: 1.25rem; border-radius: 16px; border: 1px solid var(--border-color); background: rgba(15, 23, 42, 0.6); color: var(--text-main); font-size: 1.1rem; outline: none; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);">
                    <input type="password" name="password" placeholder="Password" required style="width: 100%; padding: 1.25rem; border-radius: 16px; border: 1px solid var(--border-color); background: rgba(15, 23, 42, 0.6); color: var(--text-main); font-size: 1.1rem; outline: none; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);">
                </div>
                <button type="submit" style="width: 100%;">Login</button>
            </form>
            
            <?php else: ?>
            <p class="subtitle">Shorten long links into <b>s.pknstan.my.id</b></p>

            <form id="shortener-form" style="max-width: 500px; margin: 0 auto;">
                <div class="input-group" style="margin-bottom: 1rem;">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                        <input type="url" id="long-url" placeholder="Enter long URL here..." required autocomplete="off">
                    </div>
                    <button type="submit" id="submit-btn">
                        <span>Shorten</span>
                        <div class="spinner" id="btn-spinner"></div>
                    </button>
                </div>
                <div class="input-group">
                    <div class="input-wrapper" style="width: 100%;">
                        <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                        <input type="text" class="custom-input" id="custom-code" placeholder="Custom alias (optional)" autocomplete="off">
                    </div>
                </div>
            </form>

            <!-- Success Result -->
            <div class="result-container" id="result-container">
                <p style="color: #34d399; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Link successfully shortened!
                </p>
                <div class="short-url-box">
                    <a href="#" target="_blank" class="short-url" id="short-url-display">s.pknstan.my.id/...</a>
                    <div class="btn-group">
                        <button class="copy-btn" id="copy-btn">Copy</button>
                        <button class="qr-toggle-btn" id="result-qr-btn" title="Toggle QR Code">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                            QR
                        </button>
                    </div>
                </div>
                <!-- Inline QR Preview -->
                <div class="qr-preview-section" id="qr-preview-section">
                    <span class="qr-preview-label">&#x25A3; QR Code</span>
                    <div id="qr-preview-canvas"></div>
                    <button class="qr-download-btn" id="qr-download-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download PNG
                    </button>
                </div>
            </div>

            <!-- Error Result -->
            <div class="result-container error-container" id="error-container">
                <p id="error-msg">An error occurred.</p>
            </div>
            
            <!-- Link Dashboard -->
            <div class="table-container" style="margin-top: 3rem;">
                <table>
                    <thead>
                        <tr>
                            <th>Short URL</th>
                            <th>Original URL</th>
                            <th>Clicks</th>
                            <th>Date</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($linksData)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No links created yet.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($linksData as $link): 
                                $shortUrl = BASE_URL . htmlspecialchars($link['short_code']);
                                $code = htmlspecialchars($link['short_code']);
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo $shortUrl; ?>" target="_blank" class="table-link">
                                        /<?php echo $code; ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="truncate" title="<?php echo htmlspecialchars($link['original_url']); ?>">
                                        <?php echo htmlspecialchars($link['original_url']); ?>
                                    </span>
                                </td>
                                <td><?php echo (int)$link['clicks']; ?></td>
                                <td><?php echo date('M j, Y', strtotime($link['created_at'])); ?></td>
                                <td>
                                    <div class="action-group">
                                        <button
                                            type="button"
                                            class="qr-show-btn"
                                            onclick="openQRModal('<?php echo $shortUrl; ?>', '/<?php echo $code; ?>')"
                                            title="View QR Code"
                                        >
                                            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                                            QR
                                        </button>
                                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this link?');" style="margin: 0;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                                            <button type="submit" style="background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.3); padding: 0.4rem 0.8rem; font-size: 0.85rem; border-radius: 8px; width: auto; cursor: pointer; transition: all 0.2s;">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>
        </div>
        
        <footer>
            &copy; <?php echo date("Y"); ?> Muhammad Yoga Prabowo. All rights reserved.
        </footer>
    </div>

    <!-- QR Code Modal -->
    <div class="qr-modal-overlay" id="qr-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title-text">
        <div class="qr-modal">
            <button class="qr-modal-close" id="qr-modal-close" aria-label="Close">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="qr-modal-header">
                <svg width="30" height="30" fill="none" stroke="#a78bfa" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h4v4H4V4zm12 0h4v4h-4V4zM4 16h4v4H4v-4z"/></svg>
                <h2 class="qr-modal-title" id="qr-modal-title-text">QR Code</h2>
                <p class="qr-modal-url" id="qr-modal-url"></p>
            </div>
            <div id="qr-modal-canvas"></div>
            <div class="qr-modal-actions">
                <button class="qr-modal-download" id="qr-modal-download">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download PNG
                </button>
                <button class="qr-modal-copy" id="qr-modal-copy-url">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    Copy URL
                </button>
            </div>
        </div>
    </div>

    <?php if ($isLoggedIn): ?>
    <script>
    /* =====================================================
       QR Code Manager
    ===================================================== */
    const QRManager = (() => {
        function generate(containerId, url, size) {
            size = size || 180;
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            return new QRCode(container, {
                text: url,
                width: size,
                height: size,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H,
            });
        }

        function getCanvas(containerId) {
            const container = document.getElementById(containerId);
            return container.querySelector('canvas') || container.querySelector('img');
        }

        function download(containerId, filename) {
            // qrcode.js renders async; wait a tick
            setTimeout(function() {
                const el = getCanvas(containerId);
                if (!el) return;
                const link = document.createElement('a');
                link.download = filename;
                if (el.tagName === 'CANVAS') {
                    link.href = el.toDataURL('image/png');
                } else {
                    link.href = el.src;
                }
                link.click();
            }, 100);
        }

        return { generate: generate, getCanvas: getCanvas, download: download };
    })();

    /* =====================================================
       Shortener Form
    ===================================================== */
    document.getElementById('shortener-form').addEventListener('submit', async function(e) {
        e.preventDefault();

        const urlInput = document.getElementById('long-url').value;
        const customCodeInput = document.getElementById('custom-code').value;
        const submitBtn = document.getElementById('submit-btn');
        const btnText = submitBtn.querySelector('span');
        const spinner = document.getElementById('btn-spinner');
        const resultContainer = document.getElementById('result-container');
        const errorContainer = document.getElementById('error-container');
        const shortUrlDisplay = document.getElementById('short-url-display');
        const qrPreviewSection = document.getElementById('qr-preview-section');

        resultContainer.style.display = 'none';
        errorContainer.style.display = 'none';
        qrPreviewSection.style.display = 'none';

        btnText.style.display = 'none';
        spinner.style.display = 'block';
        submitBtn.disabled = true;

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key': '<?php echo API_KEY; ?>'
                },
                body: JSON.stringify({ url: urlInput, custom_code: customCodeInput })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                shortUrlDisplay.href = data.short_url;
                shortUrlDisplay.textContent = data.short_url;
                resultContainer.style.display = 'flex';

                // Auto-generate QR
                QRManager.generate('qr-preview-canvas', data.short_url, 160);
                qrPreviewSection.style.display = 'flex';
            } else {
                throw new Error(data.error || 'An error occurred while shortening the link.');
            }
        } catch (error) {
            document.getElementById('error-msg').textContent = error.message;
            errorContainer.style.display = 'flex';
        } finally {
            btnText.style.display = 'block';
            spinner.style.display = 'none';
            submitBtn.disabled = false;
        }
    });

    /* =====================================================
       Copy Button
    ===================================================== */
    document.getElementById('copy-btn').addEventListener('click', function() {
        const shortUrl = document.getElementById('short-url-display').textContent;
        navigator.clipboard.writeText(shortUrl).then(() => {
            const btn = this;
            const originalText = btn.textContent;
            btn.textContent = 'Copied!';
            btn.style.background = 'rgba(16,185,129,0.3)';
            setTimeout(() => {
                btn.textContent = originalText;
                btn.style.background = '';
            }, 2000);
        }).catch(err => console.error('Failed to copy:', err));
    });

    /* =====================================================
       Result QR Toggle
    ===================================================== */
    document.getElementById('result-qr-btn').addEventListener('click', function() {
        const section = document.getElementById('qr-preview-section');
        const isHidden = section.style.display === 'none' || section.style.display === '';
        if (isHidden) {
            section.style.display = 'flex';
            const url = document.getElementById('short-url-display').href;
            if (url && url !== '#' && !document.getElementById('qr-preview-canvas').querySelector('canvas, img')) {
                QRManager.generate('qr-preview-canvas', url, 160);
            }
        } else {
            section.style.display = 'none';
        }
    });

    /* =====================================================
       QR Download (result preview)
    ===================================================== */
    document.getElementById('qr-download-btn').addEventListener('click', function() {
        const url = document.getElementById('short-url-display').href;
        const filename = 'qr-' + url.replace(/https?:\/\//, '').replace(/[\/\s]/g, '-') + '.png';
        QRManager.download('qr-preview-canvas', filename);
    });

    /* =====================================================
       QR Modal
    ===================================================== */
    const modalOverlay = document.getElementById('qr-modal-overlay');
    let modalCurrentUrl = '';

    function openQRModal(fullUrl, code) {
        modalCurrentUrl = fullUrl;
        document.getElementById('qr-modal-url').textContent = fullUrl;
        QRManager.generate('qr-modal-canvas', fullUrl, 200);
        modalOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeQRModal() {
        modalOverlay.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => { document.getElementById('qr-modal-canvas').innerHTML = ''; }, 350);
    }

    document.getElementById('qr-modal-close').addEventListener('click', closeQRModal);
    modalOverlay.addEventListener('click', function(e) { if (e.target === modalOverlay) closeQRModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeQRModal(); });

    document.getElementById('qr-modal-download').addEventListener('click', function() {
        const filename = 'qr-' + modalCurrentUrl.replace(/https?:\/\//, '').replace(/[\/\s]/g, '-') + '.png';
        QRManager.download('qr-modal-canvas', filename);
    });

    document.getElementById('qr-modal-copy-url').addEventListener('click', function() {
        navigator.clipboard.writeText(modalCurrentUrl).then(() => {
            const btn = this;
            const original = btn.innerHTML;
            btn.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Copied!';
            btn.style.background = 'rgba(16,185,129,0.2)';
            btn.style.color = '#34d399';
            setTimeout(() => {
                btn.innerHTML = original;
                btn.style.background = '';
                btn.style.color = '';
            }, 2000);
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
