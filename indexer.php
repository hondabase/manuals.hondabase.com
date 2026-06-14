<?php
$root = '/var/www/manuals.hondabase.com';
$webhookUrl = 'https://discord.com/api/webhooks/1515689249922613351/S15-0DnXqdkrP_AjGnUX6zb6jq7e1wlnbBmxo8ZbZRJKp4LS58cboZpuE92s0rHYX90s';

function getDb() {
    $db = new PDO('mysql:host=localhost;dbname=manuals_db;charset=utf8mb4', 'manuals_usr', 'manuals_pass');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Increase packet size for this session
    return $db;
}

function notifyDiscord($url, $file, $needsOcr, $duration) {
    if (!$url) return;
    $method = $needsOcr ? 'Tesseract OCR' : 'Standard Extraction';
    $msg = "📚 **Indexed PDF:** `" . basename($file) . "`\n" . 
           "📂 **Path:** `" . dirname($file) . "`\n" . 
           "⚙️ **Method:** " . $method . "\n" . 
           "⏱️ **Time taken:** " . round($duration, 2) . "s";
           
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
    
    // Check if already indexed
    try {
        $stmtCheck = $db->prepare('SELECT last_modified FROM pdf_search WHERE file_path = ?');
        $stmtCheck->execute([$relPath]);
    } catch (PDOException $e) {
        // Reconnect if connection lost
        $db = getDb();
        $stmtCheck = $db->prepare('SELECT last_modified FROM pdf_search WHERE file_path = ?');
        $stmtCheck->execute([$relPath]);
    }
    
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['last_modified'] == $mtime) {
        continue;
    }
    
    echo "Indexing [" . ($i+1) . "/" . count($pdfFiles) . "]: $relPath\n";
    $startTime = microtime(true);
    
    $cmd = 'pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null';
    $text = shell_exec($cmd);
    $text = trim((string)$text);
    
    $filesizeMB = filesize($file) / 1024 / 1024;
    $needsOcr = false;
    
    if (strlen($text) < ($filesizeMB * 150) || empty($text)) { 
        $needsOcr = true;
    }
    
    if ($needsOcr) {
        echo "  -> Text is very short or empty. Attempting OCR...\n";
        $tmpDir = sys_get_temp_dir() . '/ocr_' . uniqid();
        mkdir($tmpDir);
        
        $cmd = 'pdftoppm -r 150 -jpeg ' . escapeshellarg($file) . ' ' . escapeshellarg($tmpDir . '/page') . ' 2>/dev/null';
        shell_exec($cmd);
        
        $images = glob($tmpDir . '/page-*.jpg');
        $ocrText = '';
        foreach ($images as $img) {
            $cmd = 'tesseract ' . escapeshellarg($img) . ' stdout -l eng quiet 2>/dev/null';
            $ocrText .= shell_exec($cmd) . " ";
            unlink($img);
        }
        
        $images = glob($tmpDir . '/page-*.jpg');
        foreach ($images as $img) { unlink($img); }
        rmdir($tmpDir);
        
        $text .= " " . trim($ocrText);
    }
    
    $text = preg_replace('/[\s]+/', ' ', $text);
    
    // Insert/Update
    try {
        $stmtInsert = $db->prepare('INSERT INTO pdf_search (file_path, content, last_modified) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), last_modified = VALUES(last_modified)');
        $stmtInsert->execute([$relPath, $text, $mtime]);
    } catch (PDOException $e) {
        // Reconnect if connection lost
        $db = getDb();
        $stmtInsert = $db->prepare('INSERT INTO pdf_search (file_path, content, last_modified) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), last_modified = VALUES(last_modified)');
        $stmtInsert->execute([$relPath, $text, $mtime]);
    }
    
    $duration = microtime(true) - $startTime;
    notifyDiscord($webhookUrl, $relPath, $needsOcr, $duration);
}
echo "Indexing complete.\n";
