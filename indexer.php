<?php
$root = '/var/www/manuals.hondabase.com';
$webhookUrl = 'https://discord.com/api/webhooks/1515689249922613351/S15-0DnXqdkrP_AjGnUX6zb6jq7e1wlnbBmxo8ZbZRJKp4LS58cboZpuE92s0rHYX90s';

function getDb() {
    $db = new PDO('mysql:host=localhost;dbname=manuals_db;charset=utf8mb4', 'manuals_usr', 'manuals_pass');
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

$db = getDb();

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/cars'));
$pdfFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
        $pdfFiles[] = $file->getPathname();
    }
}

echo "Found " . count($pdfFiles) . " PDF files.\n";

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
    
    $duration = microtime(true) - $startTime;
    $method = $needsOcr ? 'Tesseract OCR' : 'Standard Extraction';
    notifyDiscord($webhookUrl, "✅ **Indexed:** `$filename` (" . ($needsOcr ? $pageCount : count($pages)) . " pages, $method, " . round($duration, 2) . "s)");
}
echo "Indexing complete.\n";
