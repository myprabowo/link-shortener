<?php
// tree.php
session_start();
require_once 'config.php';
require_once 'lang.php';

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// 1. Handle Click Tracking & Redirection
if (isset($_GET['click'])) {
    $itemId = filter_var($_GET['click'], FILTER_VALIDATE_INT);
    if ($itemId) {
        $stmt = $pdo->prepare("SELECT id, url FROM link_tree_items WHERE id = ? LIMIT 1");
        $stmt->execute([$itemId]);
        $target = $stmt->fetch();

        if ($target && !empty($target['url'])) {
            $upd = $pdo->prepare("UPDATE link_tree_items SET clicks = clicks + 1 WHERE id = ?");
            $upd->execute([$itemId]);
            header('Location: ' . $target['url'], true, 302);
            exit;
        }
    }
    header('Location: tree.php');
    exit;
}

// Fetch all trees for admin selection and overview
$allTrees = $pdo->query("
    SELECT t.*, 
           COUNT(i.id) AS total_items, 
           COALESCE(SUM(i.clicks), 0) AS total_clicks 
    FROM link_trees t 
    LEFT JOIN link_tree_items i ON t.id = i.tree_id 
    GROUP BY t.id 
    ORDER BY t.created_at ASC
")->fetchAll();

// Determine active tree
$requestedSlug = isset($_GET['slug']) ? trim($_GET['slug']) : (isset($_GET['tree_slug']) ? trim($_GET['tree_slug']) : '');
$tree = null;
$treeNotFound = false;

if (!empty($requestedSlug)) {
    foreach ($allTrees as $t) {
        if ($t['slug'] === $requestedSlug) {
            $tree = $t;
            break;
        }
    }
    if (!$tree) {
        $treeNotFound = true;
    }
}

// Determine current mode and active tab
$manageMode = $isLoggedIn && (isset($_GET['manage']) && $_GET['manage'] === '1');
if ($isLoggedIn && !isset($_GET['slug']) && !isset($_GET['click'])) {
    $manageMode = true;
}

$currentTab = 'links';
if ($manageMode) {
    if (isset($_GET['tab'])) {
        $reqTab = trim($_GET['tab']);
        if (in_array($reqTab, ['links', 'profile', 'trees'], true)) {
            $currentTab = $reqTab;
        }
    } elseif (isset($_GET['view']) && $_GET['view'] === 'trees') {
        $currentTab = 'trees';
    } elseif (empty($requestedSlug)) {
        $currentTab = 'trees';
    }
}
$viewAllTrees = ($currentTab === 'trees');

// Resolve active tree
if (!$tree && !empty($allTrees)) {
    if (!$treeNotFound || $manageMode) {
        $tree = $allTrees[0];
    }
}

$treeId = $tree['id'] ?? ($allTrees[0]['id'] ?? 1);
$activeSlug = $tree['slug'] ?? ($allTrees[0]['slug'] ?? '');

$flashMessage = '';
$flashError = '';

if (isset($_GET['created_tree'])) {
    $flashMessage = $currentLang === 'en' ? 'New tree created successfully and ready to configure.' : 'Tree baru berhasil dibuat dan siap dikonfigurasi.';
} elseif (isset($_GET['deleted_tree'])) {
    $flashMessage = $currentLang === 'en' ? 'Tree deleted successfully.' : 'Tree berhasil dihapus.';
} elseif (isset($_GET['saved'])) {
    $flashMessage = $currentLang === 'en' ? 'Tree settings and profile updated successfully.' : 'Pengaturan dan profil tree berhasil diperbarui.';
} elseif (isset($_GET['added'])) {
    $flashMessage = $currentLang === 'en' ? 'New link added to this tree.' : 'Tautan baru berhasil ditambahkan ke tree ini.';
} elseif (isset($_GET['edited'])) {
    $flashMessage = $currentLang === 'en' ? 'Link details updated successfully.' : 'Data tautan berhasil diperbarui.';
} elseif (isset($_GET['deleted'])) {
    $flashMessage = $currentLang === 'en' ? 'Link removed from this tree.' : 'Tautan berhasil dihapus dari tree ini.';
} elseif (isset($_GET['reordered'])) {
    $flashMessage = $currentLang === 'en' ? 'Link order updated.' : 'Urutan tautan berhasil diubah.';
} elseif (isset($_GET['status_changed'])) {
    $flashMessage = $currentLang === 'en' ? 'Link active status changed.' : 'Status aktif tautan berhasil diubah.';
}

// 2. Handle Admin POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $action = $_POST['action'] ?? '';

    // Create New Tree
    if ($action === 'create_tree') {
        $title = trim(strip_tags($_POST['title'] ?? ''));
        $rawSlug = trim($_POST['slug'] ?? '');
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($rawSlug));
        $bio = trim(strip_tags($_POST['bio'] ?? ''));
        $websiteUrl = filter_var(trim($_POST['website_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $instagramUrl = filter_var(trim($_POST['instagram_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $youtubeUrl = filter_var(trim($_POST['youtube_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $telegramUrl = filter_var(trim($_POST['telegram_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;

        if (empty($title) || empty($slug)) {
            $flashError = $currentLang === 'en' ? 'Tree title and custom slug are required.' : 'Judul tree dan slug URL kustom wajib diisi.';
        } else {
            // Check slug uniqueness
            $checkStmt = $pdo->prepare("SELECT id FROM link_trees WHERE slug = ?");
            $checkStmt->execute([$slug]);
            if ($checkStmt->rowCount() > 0) {
                $flashError = ($currentLang === 'en' ? 'Slug URL "/' : 'Slug URL "/') . htmlspecialchars($slug) . ($currentLang === 'en' ? '" is already in use by another tree.' : '" sudah digunakan oleh tree lain. Silakan pilih slug lain.');
            } else {
                $ins = $pdo->prepare("INSERT INTO link_trees (slug, title, bio, avatar_type, avatar_value, website_url, instagram_url, youtube_url, telegram_url) VALUES (?, ?, ?, 'initials', 'STAN', ?, ?, ?, ?)");
                $ins->execute([$slug, $title, $bio, $websiteUrl, $instagramUrl, $youtubeUrl, $telegramUrl]);
                header('Location: tree.php?manage=1&slug=' . urlencode($slug) . '&tab=links&created_tree=1');
                exit;
            }
        }
    }
    // Save / Edit Active Tree Profile and Slug
    elseif ($action === 'save_profile') {
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim(strip_tags($_POST['title'] ?? ''));
        $rawSlug = trim($_POST['slug'] ?? '');
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($rawSlug));
        $bio = trim(strip_tags($_POST['bio'] ?? ''));
        $websiteUrl = filter_var(trim($_POST['website_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $instagramUrl = filter_var(trim($_POST['instagram_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $youtubeUrl = filter_var(trim($_POST['youtube_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;
        $telegramUrl = filter_var(trim($_POST['telegram_url'] ?? ''), FILTER_VALIDATE_URL) ?: null;

        if (!$targetTreeId || empty($title) || empty($slug)) {
            $flashError = $currentLang === 'en' ? 'Tree title and custom slug are required.' : 'Judul tree dan slug URL kustom wajib diisi.';
        } else {
            // Check slug uniqueness excluding current tree
            $checkStmt = $pdo->prepare("SELECT id FROM link_trees WHERE slug = ? AND id != ?");
            $checkStmt->execute([$slug, $targetTreeId]);
            if ($checkStmt->rowCount() > 0) {
                $flashError = ($currentLang === 'en' ? 'Slug URL "/' : 'Slug URL "/') . htmlspecialchars($slug) . ($currentLang === 'en' ? '" is already in use by another tree.' : '" sudah digunakan oleh tree lain.');
            } else {
                $upd = $pdo->prepare("UPDATE link_trees SET slug = ?, title = ?, bio = ?, website_url = ?, instagram_url = ?, youtube_url = ?, telegram_url = ? WHERE id = ?");
                $upd->execute([$slug, $title, $bio, $websiteUrl, $instagramUrl, $youtubeUrl, $telegramUrl, $targetTreeId]);
                header('Location: tree.php?manage=1&slug=' . urlencode($slug) . '&tab=profile&saved=1');
                exit;
            }
        }
    }
    // Delete Tree
    elseif ($action === 'delete_tree') {
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetTreeId) {
            $countTrees = (int) $pdo->query("SELECT COUNT(*) FROM link_trees")->fetchColumn();
            if ($countTrees <= 1) {
                $flashError = $currentLang === 'en' ? 'You cannot delete this tree because there must be at least 1 tree in the system.' : 'Anda tidak dapat menghapus tree ini karena minimal harus ada 1 tree di sistem.';
            } else {
                $del = $pdo->prepare("DELETE FROM link_trees WHERE id = ?");
                $del->execute([$targetTreeId]);
                header('Location: tree.php?manage=1&tab=trees&deleted_tree=1');
                exit;
            }
        }
    }
    // Add Item to Active Tree
    elseif ($action === 'add_item') {
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim(strip_tags($_POST['title'] ?? ''));
        $subtitle = trim(strip_tags($_POST['subtitle'] ?? ''));
        $url = trim($_POST['url'] ?? '');
        $icon = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['icon'] ?? 'link'));
        $badge = trim(strip_tags($_POST['badge'] ?? ''));

        if (!$targetTreeId || empty($title) || empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $flashError = $currentLang === 'en' ? 'Link title and a valid destination URL are required.' : 'Judul tautan dan URL tujuan valid wajib diisi.';
        } else {
            $maxOrder = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), -1) FROM link_tree_items WHERE tree_id = {$targetTreeId}")->fetchColumn();
            $newOrder = $maxOrder + 1;

            $ins = $pdo->prepare("INSERT INTO link_tree_items (tree_id, title, subtitle, url, icon, badge, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $ins->execute([$targetTreeId, $title, !empty($subtitle) ? $subtitle : null, $url, !empty($icon) ? $icon : 'link', !empty($badge) ? $badge : null, $newOrder]);
            header('Location: tree.php?manage=1&slug=' . urlencode($activeSlug) . '&tab=links&added=1');
            exit;
        }
    }
    // Edit Item
    elseif ($action === 'edit_item') {
        $itemId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim(strip_tags($_POST['title'] ?? ''));
        $subtitle = trim(strip_tags($_POST['subtitle'] ?? ''));
        $url = trim($_POST['url'] ?? '');
        $icon = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['icon'] ?? 'link'));
        $badge = trim(strip_tags($_POST['badge'] ?? ''));

        if (!$itemId || !$targetTreeId || empty($title) || empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $flashError = $currentLang === 'en' ? 'Invalid edit data. Please ensure title and destination URL are valid.' : 'Data edit tidak valid. Pastikan judul dan URL terisi dengan benar.';
        } else {
            $upd = $pdo->prepare("UPDATE link_tree_items SET title = ?, subtitle = ?, url = ?, icon = ?, badge = ? WHERE id = ? AND tree_id = ?");
            $upd->execute([$title, !empty($subtitle) ? $subtitle : null, $url, $icon, !empty($badge) ? $badge : null, $itemId, $targetTreeId]);
            header('Location: tree.php?manage=1&slug=' . urlencode($activeSlug) . '&tab=links&edited=1');
            exit;
        }
    }
    // Delete Item
    elseif ($action === 'delete_item') {
        $itemId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId && $targetTreeId) {
            $del = $pdo->prepare("DELETE FROM link_tree_items WHERE id = ? AND tree_id = ?");
            $del->execute([$itemId, $targetTreeId]);
            header('Location: tree.php?manage=1&slug=' . urlencode($activeSlug) . '&tab=links&deleted=1');
            exit;
        }
    }
    // Toggle Active
    elseif ($action === 'toggle_active') {
        $itemId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId && $targetTreeId) {
            $upd = $pdo->prepare("UPDATE link_tree_items SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ? AND tree_id = ?");
            $upd->execute([$itemId, $targetTreeId]);
            header('Location: tree.php?manage=1&slug=' . urlencode($activeSlug) . '&tab=links&status_changed=1');
            exit;
        }
    }
    // Reorder
    elseif ($action === 'reorder') {
        $itemId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $targetTreeId = filter_var($_POST['tree_id'] ?? null, FILTER_VALIDATE_INT);
        $direction = $_POST['direction'] ?? '';

        if ($itemId && $targetTreeId && in_array($direction, ['up', 'down'], true)) {
            $items = $pdo->query("SELECT id, sort_order FROM link_tree_items WHERE tree_id = {$targetTreeId} ORDER BY sort_order ASC, id ASC")->fetchAll();
            $currentIndex = null;
            foreach ($items as $idx => $it) {
                if ((int)$it['id'] === $itemId) {
                    $currentIndex = $idx;
                    break;
                }
            }

            if ($currentIndex !== null) {
                $targetIndex = ($direction === 'up') ? $currentIndex - 1 : $currentIndex + 1;
                if ($targetIndex >= 0 && $targetIndex < count($items)) {
                    $itemA = $items[$currentIndex];
                    $itemB = $items[$targetIndex];

                    $pdo->beginTransaction();
                    $stmtA = $pdo->prepare("UPDATE link_tree_items SET sort_order = ? WHERE id = ?");
                    $stmtA->execute([$itemB['sort_order'], $itemA['id']]);
                    $stmtB = $pdo->prepare("UPDATE link_tree_items SET sort_order = ? WHERE id = ?");
                    $stmtB->execute([$itemA['sort_order'], $itemB['id']]);
                    $pdo->commit();

                    header('Location: tree.php?manage=1&slug=' . urlencode($activeSlug) . '&tab=links&reordered=1');
                    exit;
                }
            }
        }
    }
}

// Fetch items for active linktree
if ($tree) {
    if ($manageMode) {
        $itemsStmt = $pdo->prepare("SELECT * FROM link_tree_items WHERE tree_id = ? ORDER BY sort_order ASC, id ASC");
        $itemsStmt->execute([$treeId]);
        $treeItems = $itemsStmt->fetchAll();

        // Shortlinks for quick import
        $shortLinksStmt = $pdo->query("SELECT id, short_code, original_url, title FROM links ORDER BY created_at DESC LIMIT 50");
        $availableShortLinks = $shortLinksStmt->fetchAll();
    } else {
        $itemsStmt = $pdo->prepare("SELECT * FROM link_tree_items WHERE tree_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
        $itemsStmt->execute([$treeId]);
        $treeItems = $itemsStmt->fetchAll();
    }
} else {
    $treeItems = [];
}

$pageTitle = htmlspecialchars($tree['title'] ?? __t('tree_not_found'));
$pageBio = htmlspecialchars($tree['bio'] ?? '');
$publicSlug = htmlspecialchars($tree['slug'] ?? '');
$publicTreeUrl = BASE_URL . 'tree/' . $publicSlug;

// Calculate active summary metrics
$activeLinksCount = 0;
$totalClicksCount = 0;
if ($manageMode) {
    foreach ($treeItems as $it) {
        if ((int)$it['is_active'] === 1) $activeLinksCount++;
        $totalClicksCount += (int)$it['clicks'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang); ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | <?php echo htmlspecialchars(__t('brand_title')); ?></title>
    <meta name="description" content="<?php echo $pageBio ?: 'Official linktree profile for ' . $pageTitle; ?>">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script>
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
            --radius-md: 10px;
            --radius-lg: 14px;
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
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        :focus-visible {
            outline: 2px solid var(--border-focus);
            outline-offset: 2px;
        }

        /* Unified App Navbar */
        .app-navbar {
            width: 100%;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-subtle);
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.625rem 1.25rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 0.875rem;
        }

        .brand-link {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 700;
            font-size: 0.9375rem;
            letter-spacing: -0.01em;
        }

        .brand-badge {
            background: var(--accent-subtle);
            color: var(--accent-text);
            font-size: 0.6875rem;
            font-weight: 600;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-pill);
            letter-spacing: 0.02em;
        }

        /* Tree Selector Dropdown in Navbar */
        .tree-picker-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .tree-select {
            appearance: none;
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            padding: 0.35rem 1.85rem 0.35rem 0.65rem;
            font-family: inherit;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-primary);
            cursor: pointer;
            transition: border-color 0.15s ease;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tree-select:hover, .tree-select:focus {
            border-color: var(--border-strong);
            outline: none;
        }

        .tree-picker-wrapper::after {
            content: "";
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 5px solid var(--text-muted);
            pointer-events: none;
        }

        /* Navigation Tabs Segmented Pill */
        .navbar-tabs {
            display: inline-flex;
            align-items: center;
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 3px;
            gap: 3px;
        }

        .tab-item {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-secondary);
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .tab-item:hover {
            color: var(--text-primary);
        }

        .tab-item.active {
            background: var(--bg-surface);
            color: var(--text-primary);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-subtle);
        }

        .tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            border-radius: var(--radius-pill);
            background: var(--bg-surface-muted);
            font-size: 0.6875rem;
            font-weight: 700;
            color: var(--text-muted);
        }

        .tab-item.active .tab-badge {
            background: var(--accent-subtle);
            color: var(--accent-text);
        }

        .navbar-right {
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
            border-radius: var(--radius-sm);
            padding: 2px;
            gap: 2px;
        }

        .lang-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 24px;
            padding: 0 0.35rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 4px;
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

        .btn-nav-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.625rem;
            border-radius: var(--radius-sm);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-nav-chip:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        /* Main Workspace Container */
        .admin-main-container {
            width: 100%;
            max-width: 1120px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        /* Tree Summary Header Card */
        .tree-summary-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .tree-summary-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .tree-avatar-mini {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            color: var(--accent-text);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            flex-shrink: 0;
            letter-spacing: 0.05em;
        }

        .tree-meta-group h1 {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tree-meta-sub {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 2px;
            flex-wrap: wrap;
        }

        .tree-slug-pill {
            font-family: inherit;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
        }

        .tree-summary-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Buttons & Utility Styles */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            height: 38px;
            padding: 0 0.875rem;
            font-size: 0.8125rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--accent-contrast);
            background: var(--accent);
            border: 1px solid transparent;
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            height: 38px;
            padding: 0 0.75rem;
            font-size: 0.8125rem;
            font-weight: 500;
            font-family: inherit;
            color: var(--text-secondary);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-secondary:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        .btn-danger-outline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.625rem;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--danger-text);
            background: transparent;
            border: 1px solid var(--danger-border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-danger-outline:hover {
            background: var(--danger-subtle);
            color: var(--danger);
        }

        /* Section Container & Cards */
        .section-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .section-header-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .admin-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .admin-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .admin-card-desc {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        /* Form Controls */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.875rem;
        }

        @media (min-width: 640px) {
            .form-grid-2 {
                grid-template-columns: 1fr 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            margin-bottom: 0.875rem;
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .input-text, .input-select, .input-textarea {
            width: 100%;
            height: 40px;
            padding: 0 0.75rem;
            font-size: 0.875rem;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--bg-surface);
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-sm);
            transition: border-color 0.15s ease;
        }

        .input-textarea {
            height: 72px;
            padding: 0.5rem 0.75rem;
            resize: vertical;
        }

        .input-text:focus, .input-select:focus, .input-textarea:focus {
            border-color: var(--border-focus);
            outline: none;
        }

        .input-slug-prefix {
            display: flex;
            align-items: center;
        }

        .input-slug-prefix .slug-addon {
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-strong);
            border-right: none;
            border-radius: var(--radius-sm) 0 0 var(--radius-sm);
            padding: 0 0.625rem;
            height: 40px;
            display: flex;
            align-items: center;
            font-size: 0.8125rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .input-slug-prefix .input-text {
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
        }

        /* Items Stack & Link Card Rows */
        .items-stack {
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
        }

        .link-admin-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.875rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.15s ease;
        }

        .link-admin-card:hover {
            border-color: var(--border-strong);
        }

        .link-admin-card.is-inactive {
            opacity: 0.65;
            background: var(--bg-surface-muted);
        }

        .link-card-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
        }

        .reorder-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-shrink: 0;
        }

        .btn-reorder {
            width: 22px;
            height: 18px;
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            border-radius: 3px;
            font-size: 0.625rem;
            line-height: 1;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }

        .btn-reorder:hover:not(:disabled) {
            background: var(--bg-surface);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        .btn-reorder:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .link-icon-box {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            background: var(--bg-surface-muted);
            border: 1px solid var(--border-subtle);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .link-content-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .link-title-line {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            flex-wrap: wrap;
        }

        .link-title-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .link-url-text {
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 520px;
        }

        .link-card-right {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            flex-shrink: 0;
        }

        .pill-badge {
            font-size: 0.6875rem;
            font-weight: 600;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-pill);
            background: var(--accent-subtle);
            color: var(--accent-text);
            letter-spacing: 0.02em;
        }

        .pill-badge-muted {
            background: var(--bg-surface-muted);
            color: var(--text-secondary);
        }

        /* Danger Zone Card */
        .danger-card {
            background: var(--danger-subtle);
            border: 1px solid var(--danger-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .danger-card-info h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--danger-text);
        }

        .danger-card-info p {
            font-size: 0.8125rem;
            color: var(--danger-text);
            opacity: 0.9;
        }

        /* All Trees Grid */
        .trees-catalog-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .trees-catalog-grid {
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            }
        }

        .tree-grid-item {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            padding: 1.125rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 1rem;
            transition: all 0.15s ease;
        }

        .tree-grid-item:hover {
            border-color: var(--border-strong);
            box-shadow: var(--shadow-sm);
        }

        .tree-grid-item.is-active {
            border-color: var(--accent);
        }

        /* Alerts & Modals */
        .alert-box {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
        }

        .alert-success {
            background: var(--success-subtle);
            border: 1px solid var(--success-border);
            color: var(--success-text);
        }

        .alert-error {
            background: var(--danger-subtle);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-dialog {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 440px;
            padding: 1.5rem;
            box-shadow: var(--shadow-modal);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .modal-title {
            font-size: 1.0625rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .btn-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            border-radius: var(--radius-sm);
        }

        .btn-close:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
        }

        .qr-center-box {
            background: #ffffff;
            padding: 16px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1rem auto;
            width: 232px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e2e8f0;
        }

        .toast-msg {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--text-primary);
            color: var(--bg-page);
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-pill);
            font-size: 0.8125rem;
            font-weight: 600;
            box-shadow: var(--shadow-lg);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 2000;
            opacity: 0;
            pointer-events: none;
        }

        .toast-msg.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        .empty-box {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
            background: var(--bg-surface);
            border: 1px dashed var(--border-strong);
            border-radius: var(--radius-lg);
        }

        /* Public Mode CSS */
        .public-wrapper {
            width: 100%;
            max-width: 520px;
            padding: 2.5rem 1.25rem 3rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 0 auto;
        }

        .tree-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 100%;
            margin-bottom: 2rem;
        }

        .avatar-box {
            width: 76px;
            height: 76px;
            border-radius: 20px;
            background: var(--bg-surface-elevated);
            border: 1.5px solid var(--border-subtle);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.375rem;
            font-weight: 700;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
            letter-spacing: 0.05em;
        }

        .tree-title-row {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            justify-content: center;
            margin-bottom: 0.375rem;
        }

        .tree-title {
            font-size: 1.375rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text-primary);
        }

        .verified-badge {
            color: #3b82f6;
            display: flex;
            align-items: center;
        }

        .tree-bio {
            font-size: 0.9375rem;
            color: var(--text-secondary);
            max-width: 420px;
            line-height: 1.45;
            margin-bottom: 1.25rem;
        }

        .social-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .social-link {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-pill);
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .social-link:hover {
            background: var(--accent-subtle);
            color: var(--accent-text);
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .link-stack {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 0.875rem;
            margin-bottom: 2.5rem;
        }

        .tree-btn {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            min-height: 54px;
            padding: 0.75rem 1.125rem;
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            text-decoration: none;
            box-shadow: var(--shadow-sm);
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        .tree-btn:hover {
            border-color: var(--border-strong);
            transform: translateY(-1.5px);
            box-shadow: var(--shadow-md);
            background: var(--bg-surface-elevated);
        }

        .tree-btn-left {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            min-width: 0;
        }

        .tree-btn-icon {
            width: 34px;
            height: 34px;
            border-radius: var(--radius-sm);
            background: var(--bg-surface-muted);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .tree-btn-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
            text-align: left;
        }

        .tree-btn-title {
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tree-btn-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tree-btn-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
            margin-left: 0.75rem;
        }

        .tree-btn-arrow {
            color: var(--text-muted);
            transition: transform 0.15s ease, color 0.15s ease;
        }

        .tree-btn:hover .tree-btn-arrow {
            color: var(--accent);
            transform: translateX(2px);
        }

        .tree-footer {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.25rem;
            width: 100%;
        }

        .public-actions-bar {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-action-round {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.45rem 0.875rem;
            border-radius: 8px;
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-action-round:hover {
            background: var(--bg-surface-muted);
            color: var(--text-primary);
            border-color: var(--border-strong);
        }

        .tree-brand-footer {
            font-size: 0.8125rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .tree-brand-footer a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
        }

        .tree-brand-footer a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        @media (max-width: 640px) {
            .app-navbar {
                padding: 0.625rem 0.875rem;
            }
            .navbar-tabs {
                order: 3;
                width: 100%;
                justify-content: center;
            }
            .hide-mobile {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <!-- Toast Component -->
    <div id="toast" class="toast-msg" role="status" aria-live="polite"><?php echo htmlspecialchars(__t('url_copied_toast')); ?></div>

    <?php if ($manageMode): ?>
    <!-- ============================================== -->
    <!-- UNIFIED TOP NAVBAR (ADMIN MODE)                -->
    <!-- ============================================== -->
    <header class="app-navbar">
        <div class="navbar-left">
            <a href="index.php" class="brand-link" title="<?php echo htmlspecialchars(__t('brand_title')); ?>">
                <span><?php echo htmlspecialchars(__t('brand_title')); ?></span>
                <span class="brand-badge"><?php echo htmlspecialchars(__t('linktree_nav')); ?></span>
            </a>

            <!-- Quick Tree Selector Pill -->
            <div class="tree-picker-wrapper" title="<?php echo htmlspecialchars(__t('select_tree')); ?>">
                <select class="tree-select" onchange="window.location.href='tree.php?manage=1&slug=' + encodeURIComponent(this.value) + '&tab=<?php echo htmlspecialchars($currentTab); ?>';">
                    <?php foreach ($allTrees as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['slug']); ?>" <?php echo $t['slug'] === $activeSlug ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['title']); ?> (/<?php echo htmlspecialchars($t['slug']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Segmented Tab Navigation -->
        <nav class="navbar-tabs" aria-label="Navigation Tabs">
            <a href="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>&tab=links" class="tab-item <?php echo $currentTab === 'links' ? 'active' : ''; ?>">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                <span><?php echo htmlspecialchars(__t('tab_links')); ?></span>
                <span class="tab-badge"><?php echo count($treeItems); ?></span>
            </a>

            <a href="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>&tab=profile" class="tab-item <?php echo $currentTab === 'profile' ? 'active' : ''; ?>">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span><?php echo htmlspecialchars(__t('tab_profile')); ?></span>
            </a>

            <a href="tree.php?manage=1&tab=trees" class="tab-item <?php echo $currentTab === 'trees' ? 'active' : ''; ?>">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                <span><?php echo htmlspecialchars(__t('tab_all_trees')); ?></span>
                <span class="tab-badge"><?php echo count($allTrees); ?></span>
            </a>
        </nav>

        <div class="navbar-right">
            <!-- Language Switcher -->
            <div class="lang-switcher" aria-label="<?php echo htmlspecialchars(__t('language')); ?>">
                <a href="<?php echo getLangToggleUrl('id'); ?>" class="lang-btn <?php echo $currentLang === 'id' ? 'active' : ''; ?>" title="Bahasa Indonesia">ID</a>
                <a href="<?php echo getLangToggleUrl('en'); ?>" class="lang-btn <?php echo $currentLang === 'en' ? 'active' : ''; ?>" title="English">EN</a>
            </div>

            <!-- Theme Toggle -->
            <button type="button" class="btn-nav-chip" id="theme-toggle-btn" aria-label="<?php echo htmlspecialchars(__t('theme_toggle')); ?>" title="<?php echo htmlspecialchars(__t('theme_toggle')); ?>">
                <svg id="theme-icon-moon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                <svg id="theme-icon-sun" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display: none;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </button>

            <!-- Back to Shortener -->
            <a href="index.php" class="btn-nav-chip" title="<?php echo htmlspecialchars(__t('shortener_nav')); ?>">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hide-mobile"><?php echo htmlspecialchars(__t('btn_back_to_shortener')); ?></span>
            </a>
        </div>
    </header>

    <!-- Main Workspace Container -->
    <main class="admin-main-container">
        <?php if ($flashMessage): ?>
        <div class="alert-box alert-success" role="alert">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <span><?php echo htmlspecialchars($flashMessage); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
        <div class="alert-box alert-error" role="alert">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?php echo htmlspecialchars($flashError); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($currentTab === 'links'): ?>
        <!-- ============================================== -->
        <!-- TAB 1: KELOLA TAUTAN (LINKS MANAGEMENT)         -->
        <!-- ============================================== -->
        <!-- Tree Summary Card -->
        <div class="tree-summary-card">
            <div class="tree-summary-left">
                <div class="tree-avatar-mini" aria-hidden="true">
                    <?php 
                        $words = preg_split("/\s+/", $tree['title'] ?? '');
                        $initials = '';
                        foreach ($words as $w) {
                            if (!empty($w)) $initials .= mb_strtoupper(mb_substr($w, 0, 1));
                            if (strlen($initials) >= 4) break;
                        }
                        echo htmlspecialchars($initials ?: 'STAN');
                    ?>
                </div>
                <div class="tree-meta-group">
                    <h1>
                        <?php echo htmlspecialchars($tree['title'] ?? ''); ?>
                    </h1>
                    <div class="tree-meta-sub">
                        <a href="<?php echo $publicTreeUrl; ?>" target="_blank" rel="noopener noreferrer" class="tree-slug-pill">
                            /tree/<?php echo $publicSlug; ?> &nearr;
                        </a>
                        <span>&bull;</span>
                        <span><?php echo htmlspecialchars(__t('stats_active_links', ['count' => $activeLinksCount])); ?></span>
                        <span>&bull;</span>
                        <span><?php echo htmlspecialchars(__t('stats_total_clicks', ['count' => $totalClicksCount])); ?></span>
                    </div>
                </div>
            </div>

            <div class="tree-summary-actions">
                <button type="button" class="btn-secondary" onclick="copyUrlToClipboard('<?php echo $publicTreeUrl; ?>')" title="<?php echo htmlspecialchars(__t('copy_url')); ?>">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <span><?php echo htmlspecialchars(__t('copy_url')); ?></span>
                </button>

                <button type="button" class="btn-secondary" onclick="openQrModal('<?php echo $publicTreeUrl; ?>')" title="<?php echo htmlspecialchars(__t('qr_code')); ?>">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                    <span><?php echo htmlspecialchars(__t('qr_code')); ?></span>
                </button>

                <a href="<?php echo $publicTreeUrl; ?>" class="btn-primary" target="_blank" rel="noopener noreferrer">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    <span><?php echo htmlspecialchars(__t('view_public')); ?></span>
                </a>
            </div>
        </div>

        <!-- Section Header Bar with Add Link Toggle -->
        <div class="section-header-bar">
            <h2 class="section-header-title"><?php echo htmlspecialchars(__t('links_in_tree')); ?> (<?php echo count($treeItems); ?>)</h2>
            <button type="button" class="btn-primary" id="toggle-add-btn" onclick="toggleAddForm()">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span id="add-btn-text"><?php echo htmlspecialchars(__t('btn_add_link')); ?></span>
            </button>
        </div>

        <!-- Add Link Card (Collapsible) -->
        <section class="admin-card" id="add-link-card" style="display: <?php echo empty($treeItems) ? 'block' : 'none'; ?>;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div>
                    <h3 class="admin-card-title"><?php echo htmlspecialchars(__t('add_link_to_tree')); ?></h3>
                    <p class="admin-card-desc" style="margin-bottom: 0;"><?php echo htmlspecialchars(__t('add_link_desc')); ?></p>
                </div>
                <button type="button" class="btn-secondary" onclick="toggleAddForm()" style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;">
                    <?php echo htmlspecialchars(__t('btn_hide_form')); ?>
                </button>
            </div>

            <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">

                <!-- Quick Import from Shortlinks -->
                <?php if (!empty($availableShortLinks)): ?>
                <div class="form-group">
                    <label class="form-label" for="quick-import"><?php echo htmlspecialchars(__t('quick_import_label')); ?></label>
                    <select id="quick-import" class="input-select" onchange="applyShortlink(this)">
                        <option value=""><?php echo htmlspecialchars(__t('quick_import_placeholder')); ?></option>
                        <?php foreach ($availableShortLinks as $sl): 
                            $sTitle = !empty($sl['title']) ? $sl['title'] : 'Shortlink /' . $sl['short_code'];
                            $sFull = BASE_URL . $sl['short_code'];
                        ?>
                        <option value="<?php echo htmlspecialchars($sFull); ?>" data-title="<?php echo htmlspecialchars($sTitle); ?>" data-sub="s.pknstan.id/<?php echo htmlspecialchars($sl['short_code']); ?>">
                            /<?php echo htmlspecialchars($sl['short_code']); ?> : <?php echo htmlspecialchars($sTitle); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="add-title"><?php echo htmlspecialchars(__t('link_title')); ?> <span style="color: var(--danger);">*</span></label>
                        <input type="text" id="add-title" name="title" class="input-text" placeholder="<?php echo htmlspecialchars(__t('link_title_placeholder')); ?>" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="add-sub"><?php echo htmlspecialchars(__t('link_sub')); ?></label>
                        <input type="text" id="add-sub" name="subtitle" class="input-text" placeholder="<?php echo htmlspecialchars(__t('link_sub_placeholder')); ?>" autocomplete="off">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="add-url"><?php echo htmlspecialchars(__t('link_url')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="url" id="add-url" name="url" class="input-text" placeholder="https://..." required autocomplete="off">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="add-icon"><?php echo htmlspecialchars(__t('icon_choice')); ?></label>
                        <select id="add-icon" name="icon" class="input-select">
                            <option value="link"><?php echo htmlspecialchars(__t('icon_link')); ?></option>
                            <option value="globe"><?php echo htmlspecialchars(__t('icon_globe')); ?></option>
                            <option value="document"><?php echo htmlspecialchars(__t('icon_document')); ?></option>
                            <option value="calendar"><?php echo htmlspecialchars(__t('icon_calendar')); ?></option>
                            <option value="megaphone"><?php echo htmlspecialchars(__t('icon_megaphone')); ?></option>
                            <option value="chat"><?php echo htmlspecialchars(__t('icon_chat')); ?></option>
                            <option value="star"><?php echo htmlspecialchars(__t('icon_star')); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="add-badge"><?php echo htmlspecialchars(__t('badge_label')); ?></label>
                        <input type="text" id="add-badge" name="badge" class="input-text" placeholder="<?php echo htmlspecialchars(__t('badge_placeholder')); ?>" autocomplete="off">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                    <button type="button" class="btn-secondary" onclick="toggleAddForm()"><?php echo htmlspecialchars(__t('cancel')); ?></button>
                    <button type="submit" class="btn-primary" style="width: auto;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        <span><?php echo htmlspecialchars(__t('add_to_tree_btn')); ?></span>
                    </button>
                </div>
            </form>
        </section>

        <!-- Links List -->
        <?php if (empty($treeItems)): ?>
        <div class="empty-box">
            <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom: 0.75rem; color: var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            <p style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;"><?php echo htmlspecialchars(__t('empty_tree_links')); ?></p>
            <p style="font-size: 0.8125rem; margin-bottom: 1rem;"><?php echo htmlspecialchars(__t('empty_tree_links_desc')); ?></p>
            <button type="button" class="btn-primary" onclick="toggleAddForm(true)" style="width: auto;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span><?php echo htmlspecialchars(__t('empty_tree_cta')); ?></span>
            </button>
        </div>
        <?php else: ?>
        <div class="items-stack">
            <?php foreach ($treeItems as $idx => $it): 
                $iId = (int)$it['id'];
                $iTitle = htmlspecialchars($it['title']);
                $iSub = !empty($it['subtitle']) ? htmlspecialchars($it['subtitle']) : '';
                $iUrl = htmlspecialchars($it['url']);
                $iIcon = htmlspecialchars($it['icon'] ?? 'link');
                $iBadge = !empty($it['badge']) ? htmlspecialchars($it['badge']) : '';
                $iActive = (int)$it['is_active'] === 1;
                $iClicks = (int)$it['clicks'];
            ?>
            <div class="link-admin-card <?php echo !$iActive ? 'is-inactive' : ''; ?>" id="item-card-<?php echo $iId; ?>">
                <div class="link-card-left">
                    <!-- Reorder Buttons -->
                    <div class="reorder-group">
                        <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>" style="display: inline;">
                            <input type="hidden" name="action" value="reorder">
                            <input type="hidden" name="id" value="<?php echo $iId; ?>">
                            <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn-reorder" title="<?php echo htmlspecialchars(__t('move_up')); ?>" <?php echo $idx === 0 ? 'disabled' : ''; ?>>▲</button>
                        </form>
                        <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>" style="display: inline;">
                            <input type="hidden" name="action" value="reorder">
                            <input type="hidden" name="id" value="<?php echo $iId; ?>">
                            <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn-reorder" title="<?php echo htmlspecialchars(__t('move_down')); ?>" <?php echo $idx === count($treeItems) - 1 ? 'disabled' : ''; ?>>▼</button>
                        </form>
                    </div>

                    <!-- Icon -->
                    <div class="link-icon-box" aria-hidden="true">
                        <?php if ($iIcon === 'globe'): ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                        <?php elseif ($iIcon === 'document'): ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <?php elseif ($iIcon === 'calendar'): ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php elseif ($iIcon === 'megaphone'): ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                        <?php elseif ($iIcon === 'chat'): ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <?php elseif ($iIcon === 'star'): ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                        <?php else: ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                        <?php endif; ?>
                    </div>

                    <!-- Meta Information -->
                    <div class="link-content-meta">
                        <div class="link-title-line">
                            <span class="link-title-text"><?php echo $iTitle; ?></span>
                            <?php if ($iBadge): ?>
                                <span class="pill-badge"><?php echo $iBadge; ?></span>
                            <?php endif; ?>
                            <?php if (!$iActive): ?>
                                <span style="font-size: 0.6875rem; color: var(--text-muted); font-weight: 500;"><?php echo htmlspecialchars(__t('inactive_label')); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="link-url-text" title="<?php echo $iUrl; ?>">
                            <?php echo $iSub ? $iSub . ' &bull; ' : ''; ?><?php echo $iUrl; ?>
                        </span>
                    </div>
                </div>

                <div class="link-card-right">
                    <span class="pill-badge pill-badge-muted" title="Clicks">
                        <?php echo htmlspecialchars(__t('clicks_count', ['count' => $iClicks])); ?>
                    </span>

                    <!-- Toggle Active Switch/Button -->
                    <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>" style="display: inline;">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="id" value="<?php echo $iId; ?>">
                        <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">
                        <button type="submit" class="btn-secondary" style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;">
                            <?php echo $iActive ? htmlspecialchars(__t('active_status')) : htmlspecialchars(__t('inactive_status')); ?>
                        </button>
                    </form>

                    <!-- Edit Button -->
                    <button type="button" class="btn-secondary" style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                        'id' => $iId,
                        'tree_id' => $treeId,
                        'title' => $it['title'],
                        'subtitle' => $it['subtitle'] ?? '',
                        'url' => $it['url'],
                        'icon' => $it['icon'] ?? 'link',
                        'badge' => $it['badge'] ?? ''
                    ]), ENT_QUOTES, 'UTF-8'); ?>)">
                        <?php echo htmlspecialchars(__t('edit')); ?>
                    </button>

                    <!-- Delete Button -->
                    <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>" style="display: inline;" onsubmit="return confirm('<?php echo addslashes(__t('confirm_delete')); ?>');">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="id" value="<?php echo $iId; ?>">
                        <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">
                        <button type="submit" class="btn-danger-outline" title="<?php echo htmlspecialchars(__t('delete')); ?>">
                            <?php echo htmlspecialchars(__t('delete')); ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($currentTab === 'profile'): ?>
        <!-- ============================================== -->
        <!-- TAB 2: PROFIL & PENGATURAN TREE AKTIF           -->
        <!-- ============================================== -->
        <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>" style="display: flex; flex-direction: column; gap: 1.25rem; max-width: 800px; margin: 0 auto; width: 100%;">
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">

            <!-- Identity Card -->
            <section class="admin-card">
                <h2 class="admin-card-title"><?php echo htmlspecialchars(__t('identity_section_title')); ?></h2>
                <p class="admin-card-desc"><?php echo htmlspecialchars(__t('tree_slug_desc')); ?></p>

                <div class="form-group">
                    <label class="form-label" for="edit-slug"><?php echo htmlspecialchars(__t('custom_slug')); ?> <span style="color: var(--danger);">*</span></label>
                    <div class="input-slug-prefix">
                        <span class="slug-addon"><?php echo BASE_URL; ?>tree/</span>
                        <input type="text" id="edit-slug" name="slug" class="input-text" value="<?php echo htmlspecialchars($tree['slug'] ?? ''); ?>" required pattern="[a-zA-Z0-9_-]+" title="Gunakan huruf, angka, tanda minus (-), atau underscore (_)">
                    </div>
                    <small style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; display: block;"><?php echo htmlspecialchars(__t('slug_help')); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="prof-title"><?php echo htmlspecialchars(__t('tree_title')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="prof-title" name="title" class="input-text" value="<?php echo htmlspecialchars($tree['title'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="prof-bio"><?php echo htmlspecialchars(__t('tree_bio')); ?></label>
                    <textarea id="prof-bio" name="bio" class="input-textarea"><?php echo htmlspecialchars($tree['bio'] ?? ''); ?></textarea>
                </div>
            </section>

            <!-- Social Links Card -->
            <section class="admin-card">
                <h2 class="admin-card-title"><?php echo htmlspecialchars(__t('social_section_title')); ?></h2>
                <p class="admin-card-desc"><?php echo htmlspecialchars(__t('add_link_desc')); ?></p>

                <div class="form-group">
                    <label class="form-label" for="prof-web"><?php echo htmlspecialchars(__t('website_url')); ?></label>
                    <input type="url" id="prof-web" name="website_url" class="input-text" placeholder="https://..." value="<?php echo htmlspecialchars($tree['website_url'] ?? ''); ?>">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="prof-ig"><?php echo htmlspecialchars(__t('instagram_url')); ?></label>
                        <input type="url" id="prof-ig" name="instagram_url" class="input-text" placeholder="https://instagram.com/..." value="<?php echo htmlspecialchars($tree['instagram_url'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="prof-yt"><?php echo htmlspecialchars(__t('youtube_url')); ?></label>
                        <input type="url" id="prof-yt" name="youtube_url" class="input-text" placeholder="https://youtube.com/..." value="<?php echo htmlspecialchars($tree['youtube_url'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="prof-tg"><?php echo htmlspecialchars(__t('telegram_url')); ?></label>
                    <input type="url" id="prof-tg" name="telegram_url" class="input-text" placeholder="https://t.me/..." value="<?php echo htmlspecialchars($tree['telegram_url'] ?? ''); ?>">
                </div>
            </section>

            <button type="submit" class="btn-primary" style="height: 42px;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <span><?php echo htmlspecialchars(__t('save_tree_btn')); ?></span>
            </button>
        </form>

        <!-- Danger Zone -->
        <?php if (count($allTrees) > 1): ?>
        <div class="danger-card" style="max-width: 800px; margin: 0 auto; width: 100%;">
            <div class="danger-card-info">
                <h3><?php echo htmlspecialchars(__t('danger_zone')); ?></h3>
                <p><?php echo htmlspecialchars(__t('danger_zone_desc')); ?></p>
            </div>
            <form method="POST" action="tree.php?manage=1&tab=trees" onsubmit="return confirm('<?php echo addslashes(__t('delete_tree_confirm')); ?>');">
                <input type="hidden" name="action" value="delete_tree">
                <input type="hidden" name="tree_id" value="<?php echo $treeId; ?>">
                <button type="submit" class="btn-danger-outline" style="padding: 0.5rem 0.875rem;">
                    <?php echo htmlspecialchars(__t('delete_tree_btn')); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>

        <?php elseif ($currentTab === 'trees'): ?>
        <!-- ============================================== -->
        <!-- TAB 3: DAFTAR SEMUA TREE & FORM BUAT BARU       -->
        <!-- ============================================== -->
        <!-- Section Header with Create Tree CTA -->
        <div class="section-header-bar">
            <h2 class="section-header-title"><?php echo htmlspecialchars(__t('available_trees')); ?> (<?php echo count($allTrees); ?>)</h2>
            <button type="button" class="btn-primary" onclick="toggleCreateTreeForm()">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span><?php echo htmlspecialchars(__t('create_new_tree')); ?></span>
            </button>
        </div>

        <!-- Form Buat Tree Baru (Collapsible) -->
        <section class="admin-card" id="create-tree-card" style="display: none;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div>
                    <h3 class="admin-card-title"><?php echo htmlspecialchars(__t('create_tree_title')); ?></h3>
                    <p class="admin-card-desc" style="margin-bottom: 0;"><?php echo htmlspecialchars(__t('create_tree_desc')); ?></p>
                </div>
                <button type="button" class="btn-secondary" onclick="toggleCreateTreeForm()" style="height: 32px; padding: 0 0.5rem; font-size: 0.75rem;">
                    <?php echo htmlspecialchars(__t('btn_hide_form')); ?>
                </button>
            </div>

            <form method="POST" action="tree.php?manage=1&tab=trees">
                <input type="hidden" name="action" value="create_tree">

                <div class="form-group">
                    <label class="form-label" for="new-slug"><?php echo htmlspecialchars(__t('custom_slug')); ?> <span style="color: var(--danger);">*</span></label>
                    <div class="input-slug-prefix">
                        <span class="slug-addon"><?php echo BASE_URL; ?>tree/</span>
                        <input type="text" id="new-slug" name="slug" class="input-text" placeholder="spmb-2026" required autocomplete="off" pattern="[a-zA-Z0-9_-]+" title="Gunakan huruf, angka, tanda minus (-), atau underscore (_)">
                    </div>
                    <small style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; display: block;"><?php echo htmlspecialchars(__t('slug_help')); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new-title"><?php echo htmlspecialchars(__t('tree_title')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="new-title" name="title" class="input-text" placeholder="<?php echo htmlspecialchars(__t('tree_title_placeholder')); ?>" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="new-bio"><?php echo htmlspecialchars(__t('tree_bio')); ?></label>
                    <textarea id="new-bio" name="bio" class="input-textarea" placeholder="..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new-web"><?php echo htmlspecialchars(__t('website_url')); ?></label>
                    <input type="url" id="new-web" name="website_url" class="input-text" placeholder="https://...">
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="new-ig"><?php echo htmlspecialchars(__t('instagram_url')); ?></label>
                        <input type="url" id="new-ig" name="instagram_url" class="input-text" placeholder="https://instagram.com/...">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="new-tg"><?php echo htmlspecialchars(__t('telegram_url')); ?></label>
                        <input type="url" id="new-tg" name="telegram_url" class="input-text" placeholder="https://t.me/...">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                    <button type="button" class="btn-secondary" onclick="toggleCreateTreeForm()"><?php echo htmlspecialchars(__t('cancel')); ?></button>
                    <button type="submit" class="btn-primary" style="width: auto;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        <span><?php echo htmlspecialchars(__t('create_tree_btn')); ?></span>
                    </button>
                </div>
            </form>
        </section>

        <!-- Grid of All Existing Trees -->
        <div class="trees-catalog-grid">
            <?php foreach ($allTrees as $t): 
                $tSlug = htmlspecialchars($t['slug']);
                $tTitle = htmlspecialchars($t['title']);
                $tBio = !empty($t['bio']) ? htmlspecialchars($t['bio']) : '';
                $tItemsCount = (int)$t['total_items'];
                $tClicksCount = (int)$t['total_clicks'];
                $isCurrent = ($t['slug'] === $activeSlug);
                $tUrl = BASE_URL . 'tree/' . $tSlug;
            ?>
            <div class="tree-grid-item <?php echo $isCurrent ? 'is-active' : ''; ?>">
                <div>
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.25rem;">
                        <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--text-primary);"><?php echo $tTitle; ?></h3>
                    </div>

                    <div style="margin-bottom: 0.375rem;">
                        <a href="<?php echo $tUrl; ?>" target="_blank" rel="noopener noreferrer" style="font-size: 0.75rem; color: var(--accent); text-decoration: none; font-weight: 600;">
                            /tree/<?php echo $tSlug; ?> &nearr;
                        </a>
                    </div>

                    <?php if ($tBio): ?>
                        <p style="font-size: 0.8125rem; color: var(--text-secondary); line-height: 1.4; margin-bottom: 0.75rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            <?php echo $tBio; ?>
                        </p>
                    <?php endif; ?>

                    <div style="display: flex; gap: 0.625rem; font-size: 0.75rem; color: var(--text-muted);">
                        <span><strong><?php echo $tItemsCount; ?></strong> <?php echo htmlspecialchars(__t('total_links_count', ['count' => ''])); ?></span>
                        <span>&bull;</span>
                        <span><strong><?php echo $tClicksCount; ?></strong> <?php echo htmlspecialchars(__t('total_clicks_count', ['count' => ''])); ?></span>
                    </div>
                </div>

                <div style="display: flex; gap: 0.375rem; align-items: center; justify-content: flex-end; border-top: 1px solid var(--border-subtle); padding-top: 0.75rem; flex-wrap: wrap;">
                    <a href="tree.php?manage=1&slug=<?php echo urlencode($tSlug); ?>&tab=links" class="btn-primary" style="height: 30px; padding: 0 0.625rem; font-size: 0.75rem;">
                        <span><?php echo htmlspecialchars(__t('manage_links')); ?></span>
                    </a>
                    <a href="tree.php?manage=1&slug=<?php echo urlencode($tSlug); ?>&tab=profile" class="btn-secondary" style="height: 30px; padding: 0 0.5rem; font-size: 0.75rem;" title="<?php echo htmlspecialchars(__t('tab_profile')); ?>">
                        <span><?php echo htmlspecialchars(__t('edit')); ?></span>
                    </a>
                    <a href="<?php echo $tUrl; ?>" class="btn-secondary" target="_blank" rel="noopener noreferrer" style="height: 30px; padding: 0 0.5rem; font-size: 0.75rem;" title="<?php echo htmlspecialchars(__t('see')); ?>">
                        <span><?php echo htmlspecialchars(__t('see')); ?></span>
                    </a>
                    <?php if (count($allTrees) > 1): ?>
                    <form method="POST" action="tree.php?manage=1&tab=trees" style="display: inline;" onsubmit="return confirm('<?php echo addslashes(__t('delete_tree_confirm')); ?>');">
                        <input type="hidden" name="action" value="delete_tree">
                        <input type="hidden" name="tree_id" value="<?php echo (int)$t['id']; ?>">
                        <button type="submit" class="btn-danger-outline" title="<?php echo htmlspecialchars(__t('delete')); ?>">
                            <?php echo htmlspecialchars(__t('delete')); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <!-- Modal Edit Tautan -->
    <div id="edit-modal" class="modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title" id="edit-modal-title"><?php echo htmlspecialchars(__t('edit_tree_link_title')); ?></h3>
                <button type="button" class="btn-close" onclick="closeEditModal()" aria-label="<?php echo htmlspecialchars(__t('close')); ?>">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="tree.php?manage=1&slug=<?php echo urlencode($activeSlug); ?>&tab=links">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" id="edit-id" name="id" value="">
                <input type="hidden" id="edit-tree-id" name="tree_id" value="<?php echo $treeId; ?>">

                <div class="form-group">
                    <label class="form-label" for="edit-title"><?php echo htmlspecialchars(__t('link_title')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="edit-title" name="title" class="input-text" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="edit-sub"><?php echo htmlspecialchars(__t('link_sub')); ?></label>
                    <input type="text" id="edit-sub" name="subtitle" class="input-text">
                </div>

                <div class="form-group">
                    <label class="form-label" for="edit-url"><?php echo htmlspecialchars(__t('link_url')); ?> <span style="color: var(--danger);">*</span></label>
                    <input type="url" id="edit-url" name="url" class="input-text" required>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="edit-icon"><?php echo htmlspecialchars(__t('icon_choice')); ?></label>
                        <select id="edit-icon" name="icon" class="input-select">
                            <option value="link"><?php echo htmlspecialchars(__t('icon_link')); ?></option>
                            <option value="globe"><?php echo htmlspecialchars(__t('icon_globe')); ?></option>
                            <option value="document"><?php echo htmlspecialchars(__t('icon_document')); ?></option>
                            <option value="calendar"><?php echo htmlspecialchars(__t('icon_calendar')); ?></option>
                            <option value="megaphone"><?php echo htmlspecialchars(__t('icon_megaphone')); ?></option>
                            <option value="chat"><?php echo htmlspecialchars(__t('icon_chat')); ?></option>
                            <option value="star"><?php echo htmlspecialchars(__t('icon_star')); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="edit-badge"><?php echo htmlspecialchars(__t('badge_label')); ?></label>
                        <input type="text" id="edit-badge" name="badge" class="input-text">
                    </div>
                </div>

                <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.5rem;">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()"><?php echo htmlspecialchars(__t('cancel')); ?></button>
                    <button type="submit" class="btn-primary" style="width: auto;">
                        <?php echo htmlspecialchars(__t('save_changes')); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ============================================== -->
    <!-- PUBLIC VIEW / BIO LINK VISITOR INTERFACE       -->
    <!-- ============================================== -->
    <main class="public-wrapper">
        <?php if (!$tree): ?>
            <!-- Not Found State -->
            <div class="empty-box" style="padding: 4rem 1rem;">
                <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;"><?php echo htmlspecialchars(__t('tree_not_found')); ?></h1>
                <p style="color: var(--text-secondary); margin-bottom: 1.5rem;"><?php echo htmlspecialchars(__t('tree_not_found_desc')); ?></p>
                <a href="index.php" class="btn-primary" style="width: auto; padding: 0.625rem 1.25rem;">
                    <span><?php echo htmlspecialchars(__t('go_home')); ?></span>
                </a>
            </div>
        <?php else: ?>
            <!-- Profile Header -->
            <header class="tree-header">
                <div class="avatar-box" aria-hidden="true">
                    <span><?php 
                        $words = preg_split("/\s+/", $tree['title'] ?? '');
                        $initials = '';
                        foreach ($words as $w) {
                            if (!empty($w)) $initials .= mb_strtoupper(mb_substr($w, 0, 1));
                            if (strlen($initials) >= 4) break;
                        }
                        echo htmlspecialchars($initials ?: 'STAN');
                    ?></span>
                </div>

                <div class="tree-title-row">
                    <h1 class="tree-title"><?php echo $pageTitle; ?></h1>
                    <span class="verified-badge" title="Verified" aria-label="Verified">
                        <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </span>
                </div>

                <?php if (!empty($pageBio)): ?>
                    <p class="tree-bio"><?php echo $pageBio; ?></p>
                <?php endif; ?>

                <!-- Social Links -->
                <?php 
                    $hasSocial = !empty($tree['website_url']) || !empty($tree['instagram_url']) || !empty($tree['youtube_url']) || !empty($tree['telegram_url']);
                ?>
                <?php if ($hasSocial): ?>
                <nav class="social-bar" aria-label="Social Media">
                    <?php if (!empty($tree['website_url'])): ?>
                    <a href="<?php echo htmlspecialchars($tree['website_url']); ?>" class="social-link" target="_blank" rel="noopener noreferrer" title="Official Website" aria-label="Official Website">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($tree['instagram_url'])): ?>
                    <a href="<?php echo htmlspecialchars($tree['instagram_url']); ?>" class="social-link" target="_blank" rel="noopener noreferrer" title="Instagram" aria-label="Instagram">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37zm1.5-4.87h.01"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($tree['youtube_url'])): ?>
                    <a href="<?php echo htmlspecialchars($tree['youtube_url']); ?>" class="social-link" target="_blank" rel="noopener noreferrer" title="YouTube" aria-label="YouTube">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M22.54 6.42a2.78 2.78 0 00-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 00-1.94 2A29 29 0 001 11.75a29 29 0 00.46 5.33A2.78 2.78 0 003.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 001.94-2 29 29 0 00.46-5.25 29 29 0 00-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($tree['telegram_url'])): ?>
                    <a href="<?php echo htmlspecialchars($tree['telegram_url']); ?>" class="social-link" target="_blank" rel="noopener noreferrer" title="Telegram" aria-label="Telegram">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.5 2L2 9.5l7 3 1.5 6.5 4-4 5.5 4 1.5-17z"/></svg>
                    </a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </header>

            <!-- Links Stack -->
            <section class="link-stack" aria-label="Official Links">
                <?php if (empty($treeItems)): ?>
                <div class="empty-box">
                    <p style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars(__t('no_active_links')); ?></p>
                    <p style="font-size: 0.8125rem;"><?php echo htmlspecialchars(__t('check_back_later')); ?></p>
                </div>
                <?php else: ?>
                    <?php foreach ($treeItems as $it): 
                        $itemId = (int)$it['id'];
                        $title = htmlspecialchars($it['title']);
                        $subtitle = !empty($it['subtitle']) ? htmlspecialchars($it['subtitle']) : '';
                        $iconType = $it['icon'] ?? 'link';
                        $badge = !empty($it['badge']) ? htmlspecialchars($it['badge']) : '';
                        $clickUrl = 'tree.php?click=' . $itemId;
                    ?>
                    <a href="<?php echo $clickUrl; ?>" class="tree-btn" rel="noopener noreferrer">
                        <div class="tree-btn-left">
                            <div class="tree-btn-icon" aria-hidden="true">
                                <?php if ($iconType === 'globe'): ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                                <?php elseif ($iconType === 'document'): ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <?php elseif ($iconType === 'calendar'): ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <?php elseif ($iconType === 'megaphone'): ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                                <?php elseif ($iconType === 'chat'): ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                <?php elseif ($iconType === 'star'): ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                                <?php else: ?>
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                <?php endif; ?>
                            </div>

                            <div class="tree-btn-text">
                                <span class="tree-btn-title"><?php echo $title; ?></span>
                                <?php if ($subtitle): ?>
                                    <span class="tree-btn-sub"><?php echo $subtitle; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tree-btn-right">
                            <?php if ($badge): ?>
                                <span class="pill-badge"><?php echo $badge; ?></span>
                            <?php endif; ?>
                            <div class="tree-btn-arrow" aria-hidden="true">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- Public Footer Actions -->
            <footer class="tree-footer">
                <div class="public-actions-bar">
                    <!-- Language Switcher in Public View -->
                    <div class="lang-switcher" aria-label="<?php echo htmlspecialchars(__t('language')); ?>">
                        <a href="<?php echo getLangToggleUrl('id'); ?>" class="lang-btn <?php echo $currentLang === 'id' ? 'active' : ''; ?>" title="Bahasa Indonesia">ID</a>
                        <a href="<?php echo getLangToggleUrl('en'); ?>" class="lang-btn <?php echo $currentLang === 'en' ? 'active' : ''; ?>" title="English">EN</a>
                    </div>

                    <button type="button" class="btn-action-round" onclick="shareTreePage('<?php echo $publicTreeUrl; ?>', '<?php echo addslashes($pageTitle); ?>')" title="<?php echo htmlspecialchars(__t('share')); ?>">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                        <span><?php echo htmlspecialchars(__t('share')); ?></span>
                    </button>

                    <button type="button" class="btn-action-round" onclick="openQrModal('<?php echo $publicTreeUrl; ?>')" title="<?php echo htmlspecialchars(__t('qr_code')); ?>">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                        <span><?php echo htmlspecialchars(__t('qr_code')); ?></span>
                    </button>

                    <?php if (!$isLoggedIn): ?>
                    <button type="button" class="btn-action-round" id="theme-toggle-btn" aria-label="<?php echo htmlspecialchars(__t('theme_toggle')); ?>" title="<?php echo htmlspecialchars(__t('theme_toggle')); ?>">
                        <svg id="theme-icon-moon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                        <svg id="theme-icon-sun" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display: none;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </button>
                    <?php endif; ?>
                </div>

                <div class="tree-brand-footer">
                    <span><?php echo htmlspecialchars(__t('footer_text')); ?></span>
                    <span style="opacity: 0.4;">&bull;</span>
                    <a href="index.php"><?php echo htmlspecialchars(__t('brand_title')); ?></a>
                </div>
            </footer>
        <?php endif; ?>
    </main>

    <!-- Modal QR Code -->
    <div id="qr-modal" class="modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="qr-modal-title">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title" id="qr-modal-title"><?php echo htmlspecialchars(__t('qr_code_title')); ?></h3>
                <button type="button" class="btn-close" onclick="closeQrModal()" aria-label="<?php echo htmlspecialchars(__t('close')); ?>">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="qr-center-box">
                <div id="qr-canvas-holder"></div>
            </div>

            <p style="font-size: 0.8125rem; color: var(--text-secondary); text-align: center;" id="qr-modal-url">
                <?php echo $publicTreeUrl; ?>
            </p>

            <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.5rem;">
                <button type="button" class="btn-secondary" onclick="closeQrModal()"><?php echo htmlspecialchars(__t('close')); ?></button>
                <button type="button" class="btn-primary" id="download-qr-btn" style="width: auto;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span><?php echo htmlspecialchars(__t('download_qr_btn')); ?></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const themeBtn = document.getElementById('theme-toggle-btn');
        const iconMoon = document.getElementById('theme-icon-moon');
        const iconSun = document.getElementById('theme-icon-sun');

        function updateThemeIcons(theme) {
            if (iconMoon && iconSun) {
                if (theme === 'light') {
                    iconMoon.style.display = 'none';
                    iconSun.style.display = 'block';
                } else {
                    iconMoon.style.display = 'block';
                    iconSun.style.display = 'none';
                }
            }
        }

        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        updateThemeIcons(currentTheme);

        if (themeBtn) {
            themeBtn.addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-theme');
                const next = current === 'light' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('shortener_theme', next);
                updateThemeIcons(next);
            });
        }

        function showToast(text) {
            const toast = document.getElementById('toast');
            if (toast) {
                toast.textContent = text;
                toast.classList.add('show');
                setTimeout(function() {
                    toast.classList.remove('show');
                }, 2600);
            }
        }

        function shareTreePage(url, title) {
            const shareUrl = url || window.location.href;
            const shareTitle = title || document.title;

            if (navigator.share) {
                navigator.share({
                    title: shareTitle,
                    text: shareTitle,
                    url: shareUrl
                }).catch(function() {
                    copyUrlToClipboard(shareUrl);
                });
            } else {
                copyUrlToClipboard(shareUrl);
            }
        }

        function copyUrlToClipboard(url) {
            const msg = '<?php echo addslashes(__t('url_copied_toast')); ?>';
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function() {
                    showToast(msg);
                }).catch(function() {
                    fallbackCopy(url);
                });
            } else {
                fallbackCopy(url);
            }
        }

        function fallbackCopy(text) {
            const msg = '<?php echo addslashes(__t('url_copied_toast')); ?>';
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showToast(msg);
        }

        function openQrModal(url) {
            const modal = document.getElementById('qr-modal');
            if (!modal) return;
            modal.style.display = 'flex';

            const targetUrl = url || window.location.href;
            const holder = document.getElementById('qr-canvas-holder');
            const urlText = document.getElementById('qr-modal-url');
            if (urlText) urlText.textContent = targetUrl;

            holder.innerHTML = '';
            new QRCode(holder, {
                text: targetUrl,
                width: 200,
                height: 200,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.H
            });

            const downloadBtn = document.getElementById('download-qr-btn');
            if (downloadBtn) {
                downloadBtn.onclick = function() {
                    const img = holder.querySelector('img');
                    if (img && img.src) {
                        const link = document.createElement('a');
                        link.download = 'linktree-qr.png';
                        link.href = img.src;
                        link.click();
                    }
                };
            }
        }

        function closeQrModal() {
            const modal = document.getElementById('qr-modal');
            if (modal) modal.style.display = 'none';
        }

        function openEditModal(data) {
            const modal = document.getElementById('edit-modal');
            if (!modal) return;
            document.getElementById('edit-id').value = data.id || '';
            document.getElementById('edit-tree-id').value = data.tree_id || '';
            document.getElementById('edit-title').value = data.title || '';
            document.getElementById('edit-sub').value = data.subtitle || '';
            document.getElementById('edit-url').value = data.url || '';
            document.getElementById('edit-icon').value = data.icon || 'link';
            document.getElementById('edit-badge').value = data.badge || '';
            modal.style.display = 'flex';
        }

        function closeEditModal() {
            const modal = document.getElementById('edit-modal');
            if (modal) modal.style.display = 'none';
        }

        function toggleAddForm(forceOpen) {
            const card = document.getElementById('add-link-card');
            if (!card) return;
            if (forceOpen === true || card.style.display === 'none') {
                card.style.display = 'block';
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                const titleInput = document.getElementById('add-title');
                if (titleInput) titleInput.focus();
            } else {
                card.style.display = 'none';
            }
        }

        function toggleCreateTreeForm(forceOpen) {
            const card = document.getElementById('create-tree-card');
            if (!card) return;
            if (forceOpen === true || card.style.display === 'none') {
                card.style.display = 'block';
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                const slugInput = document.getElementById('new-slug');
                if (slugInput) slugInput.focus();
            } else {
                card.style.display = 'none';
            }
        }

        function applyShortlink(selectElem) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            if (!selectedOpt || !selectElem.value) return;

            const urlInput = document.getElementById('add-url');
            const titleInput = document.getElementById('add-title');
            const subInput = document.getElementById('add-sub');

            if (urlInput) urlInput.value = selectedOpt.value;
            if (titleInput && !titleInput.value) {
                titleInput.value = selectedOpt.getAttribute('data-title') || '';
            }
            if (subInput && !subInput.value) {
                subInput.value = selectedOpt.getAttribute('data-sub') || '';
            }
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeQrModal();
                closeEditModal();
            }
        });
    </script>
</body>
</html>
