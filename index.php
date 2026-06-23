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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

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
            max-width: 600px;
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
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

        input[type="url"] {
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

        input[type="url"]:focus {
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

        button:active {
            transform: translateY(0);
        }

        /* Loading spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

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

        @keyframes slideUp {
            to { opacity: 1; transform: translateY(0); }
        }

        .short-url-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
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
        }

        .short-url:hover {
            text-decoration: underline;
        }

        .copy-btn {
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            box-shadow: none;
        }

        .copy-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: none;
            box-shadow: none;
        }

        footer {
            margin-top: 3rem;
            color: var(--text-muted);
            font-size: 0.9rem;
            text-align: center;
        }

        /* Decorative elements */
        .blob {
            position: absolute;
            filter: blur(80px);
            z-index: 0;
            opacity: 0.5;
        }
        .blob-1 {
            top: -10%; right: -10%;
            width: 300px; height: 300px;
            background: #4f46e5;
            border-radius: 50%;
        }
        .blob-2 {
            bottom: -10%; left: -10%;
            width: 250px; height: 250px;
            background: #db2777;
            border-radius: 50%;
        }

        @media (min-width: 768px) {
            .input-group {
                flex-direction: row;
            }
            button {
                width: 140px;
            }
            .input-wrapper {
                flex: 1;
            }
        }
    </style>
</head>
<body>

    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

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

            <form id="shortener-form">
                <div class="input-group">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                        <input type="url" id="long-url" placeholder="Enter long URL here..." required autocomplete="off">
                    </div>
                    <button type="submit" id="submit-btn">
                        <span>Shorten</span>
                        <div class="spinner" id="btn-spinner"></div>
                    </button>
                </div>
            </form>

            <div class="result-container" id="result-container">
                <p style="color: #34d399; font-weight: 500; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Link successfully shortened!
                </p>
                <div class="short-url-box">
                    <a href="#" target="_blank" class="short-url" id="short-url-display">s.pknstan.my.id/...</a>
                    <button class="copy-btn" id="copy-btn">Copy</button>
                </div>
            </div>

            <div class="result-container error-container" id="error-container">
                <p id="error-msg">An error occurred.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <footer>
            &copy; <?php echo date("Y"); ?> PKNSTAN. All rights reserved.
        </footer>
    </div>

    <?php if ($isLoggedIn): ?>
    <script>
        document.getElementById('shortener-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const urlInput = document.getElementById('long-url').value;
            const submitBtn = document.getElementById('submit-btn');
            const btnText = submitBtn.querySelector('span');
            const spinner = document.getElementById('btn-spinner');
            const resultContainer = document.getElementById('result-container');
            const errorContainer = document.getElementById('error-container');
            const shortUrlDisplay = document.getElementById('short-url-display');
            
            // Reset UI state
            resultContainer.style.display = 'none';
            errorContainer.style.display = 'none';
            
            // Loading state
            btnText.style.display = 'none';
            spinner.style.display = 'block';
            submitBtn.disabled = true;
            
            try {
                // Pass API Key in headers
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-Key': '<?php echo API_KEY; ?>'
                    },
                    body: JSON.stringify({ url: urlInput })
                });
                
                const data = await response.json();
                
                if (response.ok && data.success) {
                    shortUrlDisplay.href = data.short_url;
                    shortUrlDisplay.textContent = data.short_url;
                    resultContainer.style.display = 'flex';
                } else {
                    throw new Error(data.error || 'An error occurred while shortening the link.');
                }
            } catch (error) {
                document.getElementById('error-msg').textContent = error.message;
                errorContainer.style.display = 'flex';
            } finally {
                // Restore button state
                btnText.style.display = 'block';
                spinner.style.display = 'none';
                submitBtn.disabled = false;
            }
        });

        document.getElementById('copy-btn').addEventListener('click', function() {
            const shortUrl = document.getElementById('short-url-display').textContent;
            navigator.clipboard.writeText(shortUrl).then(() => {
                const btn = this;
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.background = 'rgba(16, 185, 129, 0.3)';
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = 'rgba(255,255,255,0.1)';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
