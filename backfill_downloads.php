<?php
/**
 * Backfill download counts for manuals.hondabase.com from nginx access logs.
 *
 * Scans /var/log/nginx/access.log* for GET /cars/... requests that returned 302,
 * resolves legacy redirects, filters out crawlers/bots, and updates download_count
 * in the manuals_db.files table.
 *
 * Usage: php backfill_downloads.php [--dry-run] [--include-bots]
 */

require __DIR__ . '/config.local.php';

$options = getopt('', ['include-bots', 'dry-run']);
$includeBots = isset($options['include-bots']);
$dryRun = isset($options['dry-run']);

$db = new PDO(DB_DSN, DB_USER, DB_PASS);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Legacy file mappings defined in index.php
$legacyFileMapping = [
    // 9th Gen
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT-Road_Test_3412.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT-Road_Test_3412.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_Electronic_Control_System_Circuit_Diagram_3777.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_Electronic_Control_System_Circuit_Diagram_3777.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_System_Component_Location_Index_4588.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_System_Component_Location_Index_4588.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_System_Description-Electronic_Control_System_4258.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_System_Description-Electronic_Control_System_4258.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_System_Description-General_Operation_4257.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_System_Description-General_Operation_4257.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_System_Description-Hydraulic_Controls_4259.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_System_Description-Hydraulic_Controls_4259.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_System_Description-Lock-Up_System_4267.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_System_Description-Lock-Up_System_4267.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-CVT_System_Symptom_Troubleshooting_Index_3783.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-CVT_System_Symptom_Troubleshooting_Index_3783.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-DomeLight.pdf' => 'honda/civic/2012-2015_9th_Gen/2012-2015_Honda_Civic-DomeLight.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-Entry_Lights_Control_System_Circuit_Diagram_2929.pdf' => 'honda/civic/2012-2015_9th_Gen/2012-2015_Honda_Civic-Entry_Lights_Control_System_Circuit_Diagram_2929.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-Entry_Lights_Control_System_MICU_Input_Test_2932.pdf' => 'honda/civic/2012-2015_9th_Gen/2012-2015_Honda_Civic-Entry_Lights_Control_System_MICU_Input_Test_2932.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-How_to_Troubleshoot_the_CVT_System_3775.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-How_to_Troubleshoot_the_CVT_System_3775.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-Interior_Lights_System_Description-Control_Function_4195.pdf' => 'honda/civic/2012-2015_9th_Gen/2012-2015_Honda_Civic-Interior_Lights_System_Description-Control_Function_4195.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-PCM_CVT_Control_System_PCM_Connector_for_Inputs_and_Outputs_3776.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-PCM_CVT_Control_System_PCM_Connector_for_Inputs_and_Outputs_3776.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-RemoteStart.pdf' => 'honda/civic/2012-2015_9th_Gen/2014_Honda_Civic-RemoteStart.pdf',
    'honda/civic/2011-2016/2011-2016_Honda_Civic-Shift_Position_Indicator_Circuit_Diagram_3780.pdf' => 'honda/civic/2012-2015_9th_Gen/2014-2015_Honda_Civic-Shift_Position_Indicator_Circuit_Diagram_3780.pdf',

    // 3rd Gen
    'honda/civic/1984-1987_Honda_Civic.pdf' => 'honda/civic/1984-1987_3rd_Gen/1984-1987_Honda_Civic.pdf',
    'honda/civic/1986_Honda_Civic-Supplement.pdf' => 'honda/civic/1984-1987_3rd_Gen/1986_Honda_Civic-Supplement.pdf',

    // 4th Gen
    'honda/civic/1987_Honda_Civic-Service_Manual-62SH300.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic-Service_Manual-62SH300.pdf',
    'honda/civic/1988-1991/1988-1991_Honda_Civic-62SH300.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic-Service_Manual-62SH300.pdf',
    'honda/civic/1988-1991/1988-1991_Honda_Civic-Manual.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic-Service_Manual-62SH300.pdf',
    'honda/civic/1988-1990/1988-1990_Honda_Civic_CRX-EF-supplement.pdf' => 'honda/civic/1988-1991_4th_Gen/1989-1991_Honda_Civic_CRX-EF-VTEC_Supplement.pdf',
    'honda/civic/1988-1991/Honda_Civic_EF-VTEC_Supplement.pdf' => 'honda/civic/1988-1991_4th_Gen/1989-1991_Honda_Civic_CRX-EF-VTEC_Supplement.pdf',
    'honda/civic/1988-1990/1988-1990_Honda_Civic_CRX-Service_Manual_EN.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1990_Honda_Civic_CRX-Service_Manual_EN.pdf',
    'honda/civic/Honda_CRX-Workshop_Manual.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1990_Honda_Civic_CRX-Service_Manual_EN.pdf',
    'honda/civic/1988-1991/1988-1991_Honda_Civic_EF-Power_Mirror_Diagram.jpg' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic_EF-Power_Mirror_Diagram.jpg',
    'honda/civic/1988-1991/1988-1991_Honda_Civic_EF-Sedan_Outer_Panel_Replacement.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic_EF-Sedan_Outer_Panel_Replacement.pdf',
    'honda/civic/1988-1991/1988-1991_Honda_Civic_EF-Wiring_Diagrams.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic_EF-Wiring_Diagrams.pdf',
    'honda/civic/1988-1991/1988-1991_Honda_Civic-62SH320.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic-62SH320.pdf',
    'honda/civic/1988-1991/1988-1991_Honda_Civic-Body_Repair_Manual.pdf' => 'honda/civic/1988-1991_4th_Gen/1988-1991_Honda_Civic-Body_Repair_Manual.pdf',
    'honda/civic/1988-1991/1989-1991_Honda_Civic_CRX-EF-VTEC_Supplement.pdf' => 'honda/civic/1988-1991_4th_Gen/1989-1991_Honda_Civic_CRX-EF-VTEC_Supplement.pdf',
    'honda/civic/1988-1991/1990_Honda_Civic_Sedan_Wagon-Manual.pdf' => 'honda/civic/1988-1991_4th_Gen/1990_Honda_Civic_Sedan_Wagon-Manual.pdf',
    'honda/civic/1988-1991/1990_Honda_Civic_Wagon-Manual.pdf' => 'honda/civic/1988-1991_4th_Gen/1990_Honda_Civic_Wagon-Manual.pdf',
    'honda/civic/1988-1991/1991_Honda_Civic-Supplement.pdf' => 'honda/civic/1988-1991_4th_Gen/1991_Honda_Civic-Supplement.pdf',
];

$botPattern = '/bot|crawl|spider|slurp|curl|wget|meta-webindexer|hatchfeed|msh-pdfmin|preview|chatgpt/i';
$logPattern = '/var/log/nginx/access.log*';
$logFiles = glob($logPattern);

if (empty($logFiles)) {
    echo "No log files found matching {$logPattern}\n";
    exit(1);
}

$findStmt = $db->prepare('SELECT id, name, rel_path FROM files WHERE rel_path = ?');
$totalRequests = 0;
$botRequests = 0;
$validDownloads = 0;
$fileCounts = [];

foreach ($logFiles as $filePath) {
    $isGz = str_ends_with($filePath, '.gz');
    $fp = $isGz ? gzopen($filePath, 'r') : fopen($filePath, 'r');
    if (!$fp) continue;

    while (($line = ($isGz ? gzgets($fp) : fgets($fp))) !== false) {
        if (!preg_match('/"GET \/cars\/(.+?) HTTP\/[0-9.]+" 302 /', $line, $m)) {
            continue;
        }

        $totalRequests++;
        $path = rawurldecode(rtrim($m[1], '/'));

        // Check user agent
        if (preg_match_all('/"([^"]*)"/', $line, $quoted) && isset($quoted[1][2])) {
            $ua = $quoted[1][2];
            if (!$includeBots && preg_match($botPattern, $ua)) {
                $botRequests++;
                continue;
            }
        }

        // Apply legacy mapping if exists
        if (isset($legacyFileMapping[$path])) {
            $path = $legacyFileMapping[$path];
        }

        $findStmt->execute([$path]);
        $row = $findStmt->fetch();

        if (!$row && str_ends_with($path, '.pdf')) {
            // Also try matching filename alone if unique
            $base = basename($path);
            $byName = $db->prepare('SELECT id, rel_path FROM files WHERE name = ?');
            $byName->execute([$base]);
            $candidates = $byName->fetchAll();
            if (count($candidates) === 1) {
                $row = $candidates[0];
            }
        }

        if ($row) {
            $id = (int)$row['id'];
            $fileCounts[$id] = ($fileCounts[$id] ?? 0) + 1;
            $validDownloads++;
        }
    }

    $isGz ? gzclose($fp) : fclose($fp);
}

echo "Scanned " . count($logFiles) . " log files.\n";
echo "Total /cars/... 302 requests: {$totalRequests}\n";
echo "Filtered bot/crawler requests: {$botRequests}\n";
echo "Counted valid downloads: {$validDownloads} across " . count($fileCounts) . " distinct manuals.\n\n";

if (empty($fileCounts)) {
    echo "No valid downloads found to backfill.\n";
    exit(0);
}

$updateStmt = $db->prepare('UPDATE files SET download_count = ? WHERE id = ?');
$fetchStmt = $db->prepare('SELECT name, rel_path FROM files WHERE id = ?');
$updated = 0;

arsort($fileCounts);

foreach ($fileCounts as $id => $count) {
    if (!$dryRun) {
        $updateStmt->execute([$count, $id]);
    }
    $updated++;
}

echo ($dryRun ? "[DRY-RUN] Would update: " : "Updated: ") . "{$updated} manuals.\n\n";

echo "Top 10 backfilled manuals:\n";
$i = 0;
foreach ($fileCounts as $id => $count) {
    $fetchStmt->execute([$id]);
    $row = $fetchStmt->fetch();
    if (!$row) continue;
    printf("%3d. [ID %-5d] %-45s (%d downloads)\n", ++$i, $id, mb_strimwidth($row['name'], 0, 45, '...'), $count);
    if ($i >= 10) break;
}
