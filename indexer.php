<?php
require __DIR__ . '/config.local.php';
require __DIR__ . '/git_sync.php';
$root = '/var/www/manuals.hondabase.com';
$webhookUrl = DISCORD_WEBHOOK_URL;

function getDb() {
    $db = new PDO(DB_DSN, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

function notifyDiscord($url, $msg) {
    if (!$url) return;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $msg]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

/** Ensure the folder chain exists in the catalog, returning the leaf folder id (null = root). */
function ensureFolderPath(PDO $db, array $parts): ?int {
    $parentId = null;
    foreach ($parts as $part) {
        $stmt = $db->prepare('SELECT id FROM folders WHERE parent_id <=> ? AND name = ?');
        $stmt->execute([$parentId, $part]);
        $row = $stmt->fetch();
        if ($row) {
            $parentId = (int)$row['id'];
        } else {
            $db->prepare('INSERT INTO folders (parent_id, name) VALUES (?, ?)')->execute([$parentId, $part]);
            $parentId = (int)$db->lastInsertId();
        }
    }
    return $parentId;
}

/** Insert or update this file's catalog entry (folders/files tables), keyed by its cars-relative path. */
function catalogFile(PDO $db, string $carsRelPath, int $size): void {
    $parts = explode('/', $carsRelPath);
    $filename = array_pop($parts);
    $folderId = ensureFolderPath($db, $parts);
    $stmt = $db->prepare('SELECT id FROM files WHERE rel_path = ?');
    $stmt->execute([$carsRelPath]);
    if ($stmt->fetch()) {
        $db->prepare('UPDATE files SET size = ? WHERE rel_path = ?')->execute([$size, $carsRelPath]);
    } else {
        $db->prepare('INSERT INTO files (folder_id, name, size, rel_path) VALUES (?, ?, ?, ?)')
            ->execute([$folderId, $filename, $size, $carsRelPath]);
    }
}

/** Upload one file to the R2 manuals bucket at the matching key. Logs on failure, never throws. */
function r2Upload(string $localPath, string $carsRelPath): bool {
    $cmd = 'rclone copyto --config=' . escapeshellarg(__DIR__ . '/.rclone.conf') . ' '
        . escapeshellarg($localPath) . ' '
        . escapeshellarg('r2manuals:hondabase-manuals/' . $carsRelPath) . ' 2>&1';
    exec($cmd, $output, $code);
    if ($code !== 0) error_log("r2Upload failed for {$carsRelPath}: " . implode("\n", $output));
    return $code === 0;
}

$db = getDb();

$dirIterator = new RecursiveDirectoryIterator($root . '/cars', RecursiveDirectoryIterator::SKIP_DOTS);
$filteredIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) {
    return $current->getFilename() !== '.git';
});
$iterator = new RecursiveIteratorIterator($filteredIterator);
$pdfFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
        $pdfFiles[] = $file->getPathname();
    }
}

echo "Found " . count($pdfFiles) . " PDF files.\n";

$processedCount = 0;

foreach ($pdfFiles as $i => $file) {
    $relPath = ltrim(substr($file, strlen($root)), '/');
    $mtime = filemtime($file);
    $filename = basename($file);
    
    // 1. Get/Create file entry
    try {
        $stmtFile = $db->prepare('SELECT id, last_modified FROM pdf_files WHERE file_path = ?');
        $stmtFile->execute([$relPath]);
        $fileRow = $stmtFile->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $db = getDb();
        $stmtFile = $db->prepare('SELECT id, last_modified FROM pdf_files WHERE file_path = ?');
        $stmtFile->execute([$relPath]);
        $fileRow = $stmtFile->fetch(PDO::FETCH_ASSOC);
    }

    // Skip if completely finished and not modified
    if ($fileRow && $fileRow['last_modified'] == $mtime) {
        continue;
    }

    $startTime = microtime(true);

    if ($fileRow) {
        $fileId = $fileRow['id'];
        // If modified since last time, wipe old pages to start fresh
        if ($fileRow['last_modified'] != 0 && $fileRow['last_modified'] != $mtime) {
            echo "File modified, clearing old index: $relPath\n";
            $db->prepare('DELETE FROM pdf_pages WHERE file_id = ?')->execute([$fileId]);
            $db->prepare('UPDATE pdf_files SET last_modified = 0 WHERE id = ?')->execute([$fileId]);
        }
    } else {
        $stmtInsertFile = $db->prepare('INSERT INTO pdf_files (file_path, last_modified) VALUES (?, 0)');
        $stmtInsertFile->execute([$relPath]);
        $fileId = $db->lastInsertId();
    }

    echo "Indexing [" . ($i+1) . "/" . count($pdfFiles) . "]: $relPath\n";

    // 2. Extract Text / Pages
    $cmd = 'pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null';
    $fullText = shell_exec($cmd);
    $pages = explode("\f", (string)$fullText);
    if (empty(trim(end($pages)))) array_pop($pages);

    $needsOcr = false;
    $totalChars = strlen(trim(implode('', $pages)));
    $filesizeMB = filesize($file) / 1024 / 1024;
    if ($totalChars < ($filesizeMB * 150) || count($pages) === 0) { 
        $needsOcr = true;
    }

    if ($needsOcr) {
        $pageCount = (int)shell_exec("pdfinfo " . escapeshellarg($file) . " | grep Pages | awk '{print $2}'");
        
        // Get already indexed pages to support resuming
        $stmtCheckPage = $db->prepare('SELECT page_number FROM pdf_pages WHERE file_id = ?');
        $stmtCheckPage->execute([$fileId]);
        $donePages = $stmtCheckPage->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($donePages) > 0) {
            echo "  -> Resuming OCR (" . count($donePages) . " pages already indexed)...\n";
            notifyDiscord($webhookUrl, "🔄 **Resuming OCR:** `$filename` (" . count($donePages) . "/$pageCount pages already indexed).");
        } else {
            notifyDiscord($webhookUrl, "🔍 **Starting OCR:** `$filename` ($pageCount pages)...");
        }
        
        $tmpDir = sys_get_temp_dir() . '/ocr_' . uniqid();
        mkdir($tmpDir);
        
        $stmtInsertPage = $db->prepare('INSERT INTO pdf_pages (file_id, page_number, content) VALUES (?, ?, ?)');

        for ($p = 1; $p <= $pageCount; $p++) {
            if (in_array($p, $donePages)) continue;

            $cmd = "pdftoppm -f $p -l $p -r 150 -jpeg " . escapeshellarg($file) . " " . escapeshellarg($tmpDir . '/page') . " 2>/dev/null";
            shell_exec($cmd);
            
            $images = glob($tmpDir . '/page-*.jpg');
            $pageText = '';
            foreach ($images as $img) {
                $cmd = 'tesseract ' . escapeshellarg($img) . ' stdout -l eng quiet 2>/dev/null';
                $pageText .= shell_exec($cmd) . " ";
                unlink($img);
            }
            
            $cleanText = preg_replace('/[\s]+/', ' ', trim($pageText));
            $stmtInsertPage->execute([$fileId, $p, $cleanText]);

            if ($p % 50 === 0) {
                notifyDiscord($webhookUrl, "⏳ **OCR Progress:** `$filename` ($p/$pageCount pages)");
            }
        }
        rmdir($tmpDir);
    } else {
        // Standard Text Insertion (Always wipe and redo for standard text as it's fast)
        $db->prepare('DELETE FROM pdf_pages WHERE file_id = ?')->execute([$fileId]);
        $stmtInsertPage = $db->prepare('INSERT INTO pdf_pages (file_id, page_number, content) VALUES (?, ?, ?)');
        foreach ($pages as $pNum => $content) {
            $cleanText = preg_replace('/[\s]+/', ' ', trim($content));
            if (empty($cleanText)) continue;
            $stmtInsertPage->execute([$fileId, $pNum + 1, $cleanText]);
        }
    }
    
    // 3. Mark as completely finished
    $db->prepare('UPDATE pdf_files SET last_modified = ? WHERE id = ?')->execute([$mtime, $fileId]);

    // 4. Keep the browse catalog and R2 copy in sync (relPath is "cars/..."; catalog/R2 keys drop that prefix)
    $carsRelPath = str_starts_with($relPath, 'cars/') ? substr($relPath, 5) : $relPath;
    catalogFile($db, $carsRelPath, filesize($file));
    r2Upload($file, $carsRelPath);
    $processedCount++;

    $duration = microtime(true) - $startTime;
    $method = $needsOcr ? 'Tesseract OCR' : 'Standard Extraction';
    notifyDiscord($webhookUrl, "✅ **Indexed:** `$filename` (" . ($needsOcr ? $pageCount : count($pages)) . " pages, $method, " . round($duration, 2) . "s)");
}

if ($processedCount > 0) {
    echo "Committing $processedCount file(s) to the archive mirror...\n";
    manuals_git_commit_all("Index run: $processedCount file(s) added/updated (" . date('Y-m-d H:i') . ')');
}

echo "Indexing complete.\n";
