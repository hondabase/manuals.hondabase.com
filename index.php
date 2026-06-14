<?php
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
        $db = new PDO('mysql:host=localhost;dbname=manuals_db;charset=utf8mb4', 'manuals_usr', 'manuals_pass');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Use Boolean Mode to allow partial matching on full words
        // Prepend + and append * to each word for required prefix matching
        $words = preg_split('/\s+/', $q);
        $searchQuery = '';
        foreach ($words as $word) {
            $searchQuery .= '+' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $word) . '* ';
        }
        $searchQuery = trim($searchQuery);
        
        $stmt = $db->prepare('
            SELECT file_path
            FROM pdf_search 
            WHERE MATCH(content) AGAINST(? IN BOOLEAN MODE)
            LIMIT 50
        ');
        $stmt->execute([$searchQuery]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($rows as $row) {
            $relPath = ltrim($row['file_path'], '/');
            $fullPath = $root . '/' . $relPath;
            
            if (!file_exists($fullPath)) continue;
            
            $pathParts = explode('/', $relPath);
            $encodedPathParts = array_map('rawurlencode', $pathParts);
            $urlPath = implode('/', $encodedPathParts);
            
            $dir = dirname('/' . $relPath);
            if ($dir === '\\' || $dir === '/') $dir = '/';
            
            $results[] = [
                'name' => basename($fullPath),
                'dir'  => $dir,
                'path' => '/' . ltrim($urlPath, '/'),
                'size' => round(filesize($fullPath) / 1024 / 1024, 2) . ' MB',
            ];
        }
        echo json_encode($results);
    } catch (Exception $e) {
        // Fallback to empty array on DB error
        echo json_encode([]);
    }
    exit;
}
// --- End AJAX Endpoint ---

$requestUriRaw = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = urldecode($requestUriRaw);

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

$parts = array_filter(explode('/', ltrim($requestUri, '/')));
$breadcrumbs = [['name' => 'Manuals', 'path' => '/']];
$currentPath = '';
foreach ($parts as $part) {
    $currentPath .= '/' . rawurlencode($part);
    $breadcrumbs[] = ['name' => $part, 'path' => $currentPath . '/'];
}

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
    <link rel="stylesheet" href="https://hondabase.com/assets/base.css">
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
            max-height: 400px;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: none;
        }
        .autocomplete-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: var(--txt);
            border-bottom: 1px solid var(--border-2);
            transition: background 0.15s;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: var(--panel-hover); }
        .autocomplete-icon { margin-right: 0.75rem; color: var(--amber); font-size: 1.2rem; }
        .autocomplete-name { flex-grow: 1; font-family: var(--font-sans); font-weight: 500; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .autocomplete-dir { font-family: var(--font-mono); font-size: 0.75rem; color: var(--muted); white-space: nowrap; margin-left: 1rem; }
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
        .item-icon { margin-right: 1rem; color: var(--red); font-size: 1.2rem; }
        .item-name { flex-grow: 1; font-family: var(--font-sans); font-weight: 500; word-break: break-all; }
        .item-meta { font-family: var(--font-mono); font-size: 0.7rem; color: var(--muted); white-space: nowrap; margin-left: 1rem; }
        
        .section-head { margin-top: 0; text-transform: none; letter-spacing: normal; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .section-head h2 { margin: 0; font-size: 1.5rem; }
        
        @media (max-width: 600px) {
            .item-meta, .autocomplete-dir { display: none; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="wrap">
            <a href="https://hondabase.com" class="brand" style="color:inherit">
                <h1>Honda<b>base</b></h1>
                <p>Community-Driven Honda Knowledgebase</p>
            </a>
            <nav class="nav">
                <a href="https://hondabase.com/pgmfi/wiki/">Wiki</a>
                <a href="/">Manuals</a>
                <a href="https://hondabase.com/reference/error-codes/">Error&nbsp;Codes</a>
            </nav>
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
                <input type="search" id="deepSearch" placeholder="Search all manuals..." autocomplete="off">
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
                    <a href="<?= $file['path'] ?>" class="item-row" target="_blank">
                        <span class="material-icons item-icon">description</span>
                        <span class="item-name"><?= htmlspecialchars($file['name']) ?></span>
                        <span class="item-meta"><?= $file['size'] ?></span>
                    </a>
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
                                
                                a.innerHTML = 
                                    '<span class="material-icons autocomplete-icon">description</span>' +
                                    '<span class="autocomplete-name">' + item.name + '</span>' +
                                    '<span class="autocomplete-dir">' + item.dir + '</span>';
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
