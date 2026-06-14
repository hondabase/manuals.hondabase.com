<?php
$root = '/var/www/manuals.hondabase.com';
$db = new PDO('mysql:host=localhost;dbname=manuals_db;charset=utf8mb4', 'manuals_usr', 'manuals_pass');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/cars'));
$pdfFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
        $pdfFiles[] = $file->getPathname();
    }
}

echo "Found " . count($pdfFiles) . " PDF files.\n";

$stmtCheck = $db->prepare('SELECT last_modified FROM pdf_search WHERE file_path = ?');
$stmtInsert = $db->prepare('INSERT INTO pdf_search (file_path, content, last_modified) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), last_modified = VALUES(last_modified)');

foreach ($pdfFiles as $i => $file) {
    $relPath = ltrim(substr($file, strlen($root)), '/');
    $mtime = filemtime($file);
    
    $stmtCheck->execute([$relPath]);
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $row['last_modified'] == $mtime) {
        continue;
    }
    
    echo "Indexing [" . ($i+1) . "/" . count($pdfFiles) . "]: $relPath\n";
    
    $cmd = 'pdftotext ' . escapeshellarg($file) . ' - 2>/dev/null';
    $text = shell_exec($cmd);
    $text = trim((string)$text);
    
    $filesizeMB = filesize($file) / 1024 / 1024;
    $needsOcr = false;
    
    // Heuristic: If less than 150 characters per MB, it's likely a scanned image PDF
    if (strlen($text) < ($filesizeMB * 150) || empty($text)) { 
        $needsOcr = true;
    }
    
    if ($needsOcr) {
        echo "  -> Text is very short or empty. Attempting OCR...\n";
        $tmpDir = sys_get_temp_dir() . '/ocr_' . uniqid();
        mkdir($tmpDir);
        
        // Convert to images (150 DPI is a good balance for OCR speed vs accuracy)
        $cmd = 'pdftoppm -r 150 -jpeg ' . escapeshellarg($file) . ' ' . escapeshellarg($tmpDir . '/page') . ' 2>/dev/null';
        shell_exec($cmd);
        
        $images = glob($tmpDir . '/page-*.jpg');
        $ocrText = '';
        foreach ($images as $img) {
            $cmd = 'tesseract ' . escapeshellarg($img) . ' stdout -l eng quiet 2>/dev/null';
            $ocrText .= shell_exec($cmd) . " ";
            unlink($img);
        }
        
        // Clean up temp dir
        $images = glob($tmpDir . '/page-*.jpg');
        foreach ($images as $img) { unlink($img); }
        rmdir($tmpDir);
        
        $text .= " " . trim($ocrText);
    }
    
    // Clean up text: remove excess whitespace to save DB space
    $text = preg_replace('/[\s]+/', ' ', $text);
    
    $stmtInsert->execute([$relPath, $text, $mtime]);
}
echo "Indexing complete.\n";
