<?php
require __DIR__ . '/config.local.php';
$root = '/var/www/manuals.hondabase.com';

// --- AJAX Deep Search Endpoint using MariaDB ---
if (isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q']);
    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    try {
        $db = new PDO(DB_DSN, DB_USER, DB_PASS);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $words = preg_split('/\s+/', $q);
        $searchQuery = '';
        foreach ($words as $word) {
            $searchQuery .= '+' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $word) . '* ';
        }
        $searchQuery = trim($searchQuery);
        
        // Search the pdf_pages table and join with pdf_files
        $stmt = $db->prepare('
            SELECT f.file_path, p.page_number, p.content, MATCH(p.content) AGAINST(? IN BOOLEAN MODE) as score
            FROM pdf_pages p
            JOIN pdf_files f ON p.file_id = f.id
            WHERE MATCH(p.content) AGAINST(? IN BOOLEAN MODE)
            ORDER BY score DESC
            LIMIT 50
        ');
        $stmt->execute([$searchQuery, $searchQuery]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sizeStmt = $db->prepare('SELECT size FROM files WHERE rel_path = ?');

        $results = [];
        foreach ($rows as $row) {
            // file_path is stored as "cars/<rel path under cars>"
            $relPath = ltrim($row['file_path'], '/');
            $catalogRelPath = str_starts_with($relPath, 'cars/') ? substr($relPath, 5) : $relPath;

            $sizeStmt->execute([$catalogRelPath]);
            $sizeRow = $sizeStmt->fetch();
            if ($sizeRow === false) continue; // not in the catalog (removed/renamed)

            $pathParts = explode('/', $relPath);
            $encodedPathParts = array_map('rawurlencode', $pathParts);
            $urlPath = implode('/', $encodedPathParts);

            $dir = dirname('/' . $relPath);
            if ($dir === '\\' || $dir === '/') $dir = '/';

            $snippet = '';
            $firstWord = preg_replace('/[^a-zA-Z0-9_.-]/', '', $words[0]);
            if (!empty($firstWord)) {
                $pos = stripos($row['content'], $firstWord);
                if ($pos !== false) {
                    $start = max(0, $pos - 40);
                    $length = 100;
                    $snippetText = substr($row['content'], $start, $length);
                    $snippetText = htmlspecialchars($snippetText);
                    $snippetText = preg_replace('/(' . preg_quote($firstWord, '/') . ')/i', '<strong style="color:var(--red);">$1</strong>', $snippetText);
                    $snippet = '... ' . $snippetText . ' ...';
                }
            }

            $results[] = [
                'name' => basename($relPath),
                'dir'  => $dir,
                'path' => '/' . ltrim($urlPath, '/') . '#page=' . $row['page_number'],
                'page' => $row['page_number'],
                'size' => round($sizeRow['size'] / 1024 / 1024, 2) . ' MB',
                'snippet' => $snippet
            ];
        }
        echo json_encode($results);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}
// --- End AJAX Endpoint ---

$requestUriRaw = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = urldecode($requestUriRaw);

$underCars = $requestUri === '/cars' || $requestUri === '/cars/' || str_starts_with($requestUri, '/cars/');

if ($underCars) {
    // /cars content lives in R2 (hondabase-manuals bucket), catalogued in the folders/files tables.
    $db = new PDO(DB_DSN, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $relative = ($requestUri === '/cars' || $requestUri === '/cars/') ? '' : substr($requestUri, strlen('/cars/'));
    $relative = trim($relative, '/');
    $carsParts = $relative === '' ? [] : explode('/', $relative);

    $folderStmt = $db->prepare('SELECT id FROM folders WHERE parent_id <=> ? AND name = ?');
    $parentId = null;
    $resolvedAsFolder = true;
    foreach ($carsParts as $i => $part) {
        $folderStmt->execute([$parentId, $part]);
        $row = $folderStmt->fetch();
        if ($row) {
            $parentId = (int)$row['id'];
            continue;
        }
        // Last segment might be a file rather than a folder — redirect straight to the CDN.
        if ($i === count($carsParts) - 1) {
            $fileStmt = $db->prepare('SELECT id FROM files WHERE rel_path = ?');
            $fileStmt->execute([implode('/', $carsParts)]);
            if ($fileStmt->fetch()) {
                $cdnKey = implode('/', array_map('rawurlencode', $carsParts));
                header('Location: https://cdn.hondabase.com/manuals/' . $cdnKey, true, 302);
                exit;
            }
        }
        $resolvedAsFolder = false;
        break;
    }

    if (!$resolvedAsFolder) {
        header("HTTP/1.1 404 Not Found");
        exit("404 Not Found");
    }

    if (substr($requestUri, -1) !== '/') {
        $encodedParts = array_map('rawurlencode', $carsParts);
        header("Location: /cars/" . implode('/', $encodedParts) . "/");
        exit;
    }

    // If the folder itself has an index.html (e.g. an extracted HTML manual),
    // serve that page instead of the directory listing.
    $indexStmt = $db->prepare('SELECT 1 FROM files WHERE folder_id <=> ? AND name = ?');
    $indexStmt->execute([$parentId, 'index.html']);
    if ($indexStmt->fetch()) {
        $cdnKey = implode('/', array_map('rawurlencode', $carsParts));
        $cdnKey = ($cdnKey === '' ? '' : $cdnKey . '/') . 'index.html';
        header('Location: https://cdn.hondabase.com/manuals/' . $cdnKey, true, 302);
        exit;
    }

    $directories = [];
    $files = [];

    if ($requestUri !== '/cars/') {
        $parentParts = $carsParts;
        array_pop($parentParts);
        $parentUrl = $parentParts === [] ? '/cars/' : '/cars/' . implode('/', array_map('rawurlencode', $parentParts)) . '/';
        $directories[] = ['name' => 'Parent Directory', 'path' => $parentUrl, 'icon' => 'arrow_back'];
    }

    $subFolderStmt = $db->prepare('SELECT name FROM folders WHERE parent_id <=> ? ORDER BY name');
    $subFolderStmt->execute([$parentId]);
    foreach ($subFolderStmt->fetchAll() as $f) {
        $childParts = array_merge($carsParts, [$f['name']]);
        $directories[] = [
            'name' => $f['name'],
            'path' => '/cars/' . implode('/', array_map('rawurlencode', $childParts)) . '/',
            'icon' => 'folder'
        ];
    }

    $subFileStmt = $db->prepare('SELECT name, size FROM files WHERE folder_id <=> ? ORDER BY name');
    $subFileStmt->execute([$parentId]);
    foreach ($subFileStmt->fetchAll() as $fRow) {
        $childParts = array_merge($carsParts, [$fRow['name']]);
        $files[] = [
            'name' => $fRow['name'],
            'path' => '/cars/' . implode('/', array_map('rawurlencode', $childParts)),
            'size' => round($fRow['size'] / 1024 / 1024, 2) . ' MB',
            'icon' => 'description'
        ];
    }
} else {
    // Everything else (app root, /assets/) is small app-owned content and stays on local disk.
    if (substr($requestUri, -1) !== '/') {
        if (is_dir($root . $requestUri)) {
            $parts = explode('/', ltrim($requestUri, '/'));
            $encodedParts = array_map('rawurlencode', $parts);
            header("Location: /" . implode('/', $encodedParts) . "/");
            exit;
        }
    }

    $path = realpath($root . $requestUri);

    if (!$path || strpos($path, $root) !== 0 || !is_dir($path)) {
        header("HTTP/1.1 404 Not Found");
        exit("404 Not Found");
    }

    $items = scandir($path);
    $directories = [];
    $files = [];

    if ($requestUri !== '/') {
        $parent = dirname($requestUri);
        if ($parent === DIRECTORY_SEPARATOR || $parent === '\\' || $parent === '/') {
            $parentUrl = '/';
        } else {
            $parentParts = explode('/', ltrim($parent, '/'));
            $parentUrl = '/' . implode('/', array_map('rawurlencode', $parentParts)) . '/';
        }
        $directories[] = [
            'name' => 'Parent Directory',
            'path' => $parentUrl,
            'icon' => 'arrow_back'
        ];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === 'index.php' || strpos($item, '.') === 0 || $item === 'indexer.php') continue;

        $fullPath = $path . DIRECTORY_SEPARATOR . $item;
        $relPath = ltrim(substr($fullPath, strlen($root)), DIRECTORY_SEPARATOR);

        $pathParts = explode(DIRECTORY_SEPARATOR, $relPath);
        $encodedPathParts = array_map('rawurlencode', $pathParts);
        $urlPath = implode('/', $encodedPathParts);

        if (is_dir($fullPath)) {
            $directories[] = [
                'name' => $item,
                'path' => '/' . $urlPath . '/',
                'icon' => 'folder'
            ];
        } else {
            $files[] = [
                'name' => $item,
                'path' => '/' . $urlPath,
                'size' => round(filesize($fullPath) / 1024 / 1024, 2) . ' MB',
                'icon' => 'description'
            ];
        }
    }
}

$parts = array_filter(explode('/', ltrim($requestUri, '/')));
$breadcrumbs = [['name' => 'Manuals', 'path' => '/']];
$currentPath = '';
foreach ($parts as $part) {
    $currentPath .= '/' . rawurlencode($part);
    $breadcrumbs[] = ['name' => $part, 'path' => $currentPath . '/'];
}

$currentUrl = 'https://manuals.hondabase.com' . ($_SERVER['REQUEST_URI'] ?? '/');
$loginUrl = 'https://www.hondabase.com/auth/login?return=' . rawurlencode($currentUrl);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(end($breadcrumbs)['name']) ?> - Manuals - Hondabase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&family=IBM+Plex+Mono:ital,wght@0,400;0,500;1,400&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://www.hondabase.com/assets/base.css">
    <style>
        .breadcrumb { font-family: var(--font-mono); font-size: 0.8rem; margin-bottom: 1.5rem; color: var(--muted); }
        .breadcrumb a { color: var(--amber); }
        .breadcrumb span { margin: 0 0.5rem; }
        
        .search-container { margin-bottom: 1.5rem; position: relative; }
        .search-container input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border-2);
            border-radius: 0.25rem;
            background: var(--bg-2);
            color: var(--txt);
            font-family: var(--font-mono);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-container input:focus { border-color: var(--amber); }
        .search-container .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 1.2rem;
            pointer-events: none;
        }
        
        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--bg-2);
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 0.25rem 0.25rem;
            z-index: 50;
            max-height: 500px;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: none;
        }
        .autocomplete-item {
            display: flex;
            align-items: flex-start;
            padding: 1rem;
            text-decoration: none;
            color: var(--txt);
            border-bottom: 1px solid var(--border-2);
            transition: background 0.15s;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: var(--panel-hover); }
        .autocomplete-icon { margin-right: 1rem; color: var(--amber); font-size: 1.5rem; margin-top: 0.2rem; }
        .autocomplete-content { flex-grow: 1; min-width: 0; }
        .autocomplete-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.4rem; gap: 1rem; }
        .autocomplete-name { font-family: var(--font-sans); font-weight: 600; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--head); }
        .autocomplete-dir { font-family: var(--font-mono); font-size: 0.7rem; color: var(--amber); white-space: nowrap; flex-shrink: 0; }
        .autocomplete-page { background: var(--red); color: white; padding: 0.1rem 0.4rem; border-radius: 2px; font-size: 0.65rem; font-family: var(--font-mono); font-weight: 600; margin-left: 0.5rem; }
        .autocomplete-snippet { font-family: var(--font-mono); font-size: 0.8rem; color: var(--txt-dim); line-height: 1.4; word-break: break-word; }
        .autocomplete-empty { padding: 1rem; text-align: center; color: var(--muted); font-family: var(--font-mono); font-size: 0.85rem; }
        .autocomplete-loader { padding: 1rem; text-align: center; color: var(--amber); font-family: var(--font-mono); font-size: 0.85rem; }

        .item-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .item-row { 
            display: flex; align-items: center; padding: 0.75rem 1rem; 
            background: var(--panel); border: 1px solid var(--border);
            transition: background 0.2s, border-color 0.2s;
            text-decoration: none; color: inherit;
        }
        .item-row:hover { background: var(--panel-hover); border-color: var(--amber); }
        .item-link { display: flex; align-items: center; flex-grow: 1; min-width: 0; color: inherit; text-decoration: none; }
        .item-icon { margin-right: 1rem; color: var(--red); font-size: 1.2rem; }
        .item-name { flex-grow: 1; font-family: var(--font-sans); font-weight: 500; word-break: break-all; }
        .item-meta { font-family: var(--font-mono); font-size: 0.7rem; color: var(--muted); white-space: nowrap; margin-left: 1rem; }
        .favorite-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            margin-left: 0.75rem;
            border: 1px solid var(--border-2);
            border-radius: 999px;
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            flex-shrink: 0;
            transition: color 0.15s, border-color 0.15s, background 0.15s;
        }
        .favorite-btn:hover { color: var(--amber); border-color: var(--amber); background: var(--panel-hover); }
        .favorite-btn.is-saved { color: var(--amber); border-color: rgba(251,191,36,.6); }
        .favorite-btn .material-icons { font-size: 1.1rem; }
        
        .section-head { margin-top: 0; text-transform: none; letter-spacing: normal; display: flex; justify-content: flex-start; align-items: center; margin-bottom: 1.5rem; }
        .section-head h2 { margin: 0; font-size: 1.5rem; }
        
        @media (max-width: 600px) {
                        .autocomplete-header { flex-direction: column; gap: 0.2rem; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="wrap header-wrap">
            <a href="https://www.hondabase.com/" class="brand" style="color:inherit">
                <h1>Honda<b>base</b></h1>
                <p>Community-Driven Honda Knowledgebase</p>
            </a>
            <nav class="nav" id="primaryNav">
                <a href="https://www.hondabase.com/pgmfi/">pgmfi.org</a>
                <a href="https://files.hondabase.com">Files</a>
                <a href="/">Manuals</a>
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="nav-signin" id="authLink">Sign in</a>
            </nav>
            <div class="flex items-center gap-4">
                <button type="button" class="burger-btn" id="navToggle" aria-label="Toggle navigation" aria-controls="primaryNav" aria-expanded="false">
                    <span class="burger-line"></span>
                    <span class="burger-line"></span>
                    <span class="burger-line"></span>
                </button>
            </div>
        </div>
    </header>

    <main class="wrap">
        <section>
            <div class="breadcrumb">
                <?php foreach ($breadcrumbs as $i => $bc): ?>
                    <a href="<?= $bc['path'] ?>"><?= htmlspecialchars($bc['name']) ?></a>
                    <?php if ($i < count($breadcrumbs) - 1): ?><span>/</span><?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="section-head">
                <h2><?= htmlspecialchars(end($breadcrumbs)['name']) ?></h2>
            </div>

            <div class="search-container">
                <span class="material-icons search-icon">search</span>
                <input type="search" id="deepSearch" placeholder="Search content inside all manuals..." autocomplete="off">
                <div id="autocompleteDropdown" class="autocomplete-dropdown"></div>
            </div>

            <div class="item-list" id="defaultList">
                <?php foreach ($directories as $dir): ?>
                    <a href="<?= $dir['path'] ?>" class="item-row">
                        <span class="material-icons item-icon"><?= $dir['icon'] ?></span>
                        <span class="item-name"><?= htmlspecialchars($dir['name']) ?></span>
                        <span class="item-meta">Directory</span>
                    </a>
                <?php endforeach; ?>

                <?php foreach ($files as $file): ?>
                    <?php $fileUrl = 'https://manuals.hondabase.com' . $file['path']; ?>
                    <div class="item-row manual-file" data-path="<?= htmlspecialchars($file['path']) ?>" data-name="<?= htmlspecialchars($file['name']) ?>" data-url="<?= htmlspecialchars($fileUrl) ?>">
                        <a href="<?= $file['path'] ?>" class="item-link" target="_blank">
                            <span class="material-icons item-icon">description</span>
                            <span class="item-name"><?= htmlspecialchars($file['name']) ?></span>
                            <span class="item-meta"><?= $file['size'] ?></span>
                        </a>
                        <button type="button" class="favorite-btn" title="Save this manual" aria-label="Save <?= htmlspecialchars($file['name']) ?>" aria-pressed="false">
                            <span class="material-icons">star_border</span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="wrap">
            <span>&copy; <?= date('Y') ?> <b>Hondabase</b> - open &amp; ad-free</span>
            <span>Content preserved on <a href="https://github.com/Hondabase">GitHub</a></span>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var navToggle = document.getElementById('navToggle');
            var primaryNav = document.getElementById('primaryNav');

            if (navToggle && primaryNav) {
                navToggle.addEventListener('click', function() {
                    var isOpen = primaryNav.classList.toggle('is-active');
                    navToggle.classList.toggle('is-active', isOpen);
                    navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
            }

            var favoriteRows = Array.prototype.slice.call(document.querySelectorAll('.manual-file'));
            var favoriteButtons = Array.prototype.slice.call(document.querySelectorAll('.favorite-btn'));
            var favoriteApi = 'https://www.hondabase.com/manuals/favorites';
            var loginUrl = '<?= addslashes($loginUrl) ?>';
            var isAuthenticated = false;

            function setFavoriteButton(button, saved) {
                button.classList.toggle('is-saved', saved);
                button.setAttribute('aria-pressed', saved ? 'true' : 'false');
                button.title = saved ? 'Saved to your Hondabase' : (isAuthenticated ? 'Save this manual' : 'Sign in to save this manual');
                var icon = button.querySelector('.material-icons');
                if (icon) icon.textContent = saved ? 'star' : 'star_border';
            }

            var params = new URLSearchParams();
            favoriteRows.forEach(function(row) { params.append('paths[]', row.dataset.path); });

            fetch(favoriteApi + (params.toString() ? '?' + params.toString() : ''), { credentials: 'include' })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    isAuthenticated = !!data.authenticated;
                    if (authLink && isAuthenticated && data.user) {
                        if (!document.getElementById('profileLink')) {
                            var profileLink = document.createElement('a');
                            profileLink.id = 'profileLink';
                            profileLink.href = data.user.profile_url;
                            profileLink.textContent = 'Profile';
                            primaryNav.insertBefore(profileLink, authLink);

                            var favoritesLink = document.createElement('a');
                            favoritesLink.id = 'favoritesLink';
                            favoritesLink.href = data.user.favorites_url;
                            favoritesLink.textContent = 'Favorites';
                            primaryNav.insertBefore(favoritesLink, authLink);
                        }

                        authLink.href = '#logout';
                        authLink.textContent = 'Sign out';
                        authLink.dataset.logoutUrl = data.user.logout_url;
                    }
                    var saved = new Set(data.saved || []);
                    favoriteRows.forEach(function(row) {
                        var button = row.querySelector('.favorite-btn');
                        if (button) setFavoriteButton(button, saved.has(row.dataset.path));
                    });
                })
                .catch(function() {});

            if (authLink) {
                authLink.addEventListener('click', function(event) {
                    if (!authLink.dataset.logoutUrl) return;

                    event.preventDefault();
                    fetch(authLink.dataset.logoutUrl, {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).finally(function() {
                        window.location.reload();
                    });
                });
            }

            if (favoriteRows.length) {
                favoriteButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        var row = button.closest('.manual-file');
                        if (!row) return;

                        if (!isAuthenticated) {
                            window.location.href = loginUrl;
                            return;
                        }

                        button.disabled = true;
                        fetch(favoriteApi, {
                            method: 'POST',
                            credentials: 'include',
                            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({
                                path: row.dataset.path,
                                name: row.dataset.name,
                                url: row.dataset.url,
                                return: window.location.href
                            })
                        })
                            .then(function(res) {
                                if (res.status === 401) {
                                    window.location.href = loginUrl;
                                    return null;
                                }
                                return res.json();
                            })
                            .then(function(data) {
                                if (data) setFavoriteButton(button, !!data.saved);
                            })
                            .finally(function() { button.disabled = false; });
                    });
                });
            }

            var searchInput = document.getElementById('deepSearch');
            var dropdown = document.getElementById('autocompleteDropdown');
            var debounceTimer;

            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });

            searchInput.addEventListener('focus', function() {
                if (searchInput.value.trim().length >= 2) {
                    dropdown.style.display = 'block';
                }
            });

            searchInput.addEventListener('input', function(e) {
                var term = e.target.value.trim();
                clearTimeout(debounceTimer);
                
                if (term.length < 2) {
                    dropdown.style.display = 'none';
                    return;
                }
                
                dropdown.style.display = 'block';
                dropdown.innerHTML = '<div class="autocomplete-loader">Searching...</div>';
                
                debounceTimer = setTimeout(function() {
                    fetch('?q=' + encodeURIComponent(term))
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            dropdown.innerHTML = '';
                            
                            if (data.length === 0) {
                                dropdown.innerHTML = '<div class="autocomplete-empty">No results found for "' + term + '"</div>';
                                return;
                            }
                            
                            data.forEach(function(item) {
                                var a = document.createElement('a');
                                a.href = item.path;
                                a.className = 'autocomplete-item';
                                a.target = '_blank';
                                
                                var snippetHtml = item.snippet ? '<div class="autocomplete-snippet">' + item.snippet + '</div>' : '';
                                var pageHtml = '<span class="autocomplete-page">PAGE ' + item.page + '</span>';
                                
                                a.innerHTML = 
                                    '<span class="material-icons autocomplete-icon">description</span>' +
                                    '<div class="autocomplete-content">' +
                                        '<div class="autocomplete-header">' +
                                            '<span class="autocomplete-name">' + item.name + pageHtml + '</span>' +
                                            '<span class="autocomplete-dir">' + item.dir + '</span>' +
                                        '</div>' +
                                        snippetHtml +
                                    '</div>';
                                dropdown.appendChild(a);
                            });
                        })
                        .catch(function(err) {
                            dropdown.innerHTML = '<div class="autocomplete-empty" style="color:var(--red);">Search error occurred.</div>';
                        });
                }, 250);
            });
        });
    </script>
</body>
</html>
