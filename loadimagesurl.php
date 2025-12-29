<?php
    /* -------------------------------------------------------------
    loadimagesurl.php (v3 – NO LIMIT + FULL PER-FOLDER STATS)
    • Saves ALL unique image URLs (no 200 cap)
    • Shows: total URLs, per-folder count, which folders are logged
    ------------------------------------------------------------- */

    $host = 'sql201.infinityfree.com';
    $dbname = 'if0_40367004_jpgsvault';
    $username = 'if0_40367004';
    $password = 'NkwFAH15FRIlvCf'; 

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("DB Error: " . $e->getMessage());
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS jpgsvault_table (id INT AUTO_INCREMENT PRIMARY KEY)");
    $pdo->prepare("INSERT IGNORE INTO jpgsvault_table (id) VALUES (1)")->execute();

    if (!columnExists($pdo, 'copied_links')) {
        $pdo->exec("ALTER TABLE jpgsvault_table ADD COLUMN copied_links JSON DEFAULT NULL");
    }

    function columnExists($pdo, $col) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM jpgsvault_table LIKE ?");
        $stmt->execute([$col]);
        return $stmt->rowCount() > 0;
    }
    function getImagesInFolder($pdo, $folder) {
        $stmt = $pdo->prepare("SELECT `$folder` FROM jpgsvault_table WHERE id = 1");
        $stmt->execute();
        $json = $stmt->fetchColumn();
        return $json ? json_decode($json, true) : [];
    }
    function baseUrl() {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
    }

    /* ---------- Load current logs ---------- */
    $stmt = $pdo->query("SELECT copied_links FROM jpgsvault_table WHERE id = 1");
    $json = $stmt->fetchColumn();
    $logs = $json ? json_decode($json, true) : [];
    $urlMap = []; // url => entry

    foreach ($logs as $entry) {
        $url = $entry['url'];
        if (!isset($urlMap[$url]) || strtotime($entry['timestamp']) > strtotime($urlMap[$url]['timestamp'])) {
            $urlMap[$url] = $entry;
        }
    }

    /* ---------- Scan ALL folders ---------- */
    $columns = [];
    $folderStats = []; // folder => image count
    $folderInLog = []; // folder => count of URLs in final log

    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault_table");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && $field !== 'copied_links') {
            $columns[] = $field;
        }
    }

    $base = rtrim(baseUrl(), '/');
    $newEntries = [];

    foreach ($columns as $folder) {
        $paths = getImagesInFolder($pdo, $folder);
        $count = is_array($paths) ? count($paths) : 0;
        $folderStats[$folder] = $count;

        foreach ($paths as $path) {
            $fullUrl = $base . '/' . ltrim($path, '/');

            if (isset($urlMap[$fullUrl])) {
                $urlMap[$fullUrl]['timestamp'] = date('c');
                $urlMap[$fullUrl]['folder'] = $folder;
            } else {
                $newEntries[] = $fullUrl;
                $urlMap[$fullUrl] = [
                    'url' => $fullUrl,
                    'folder' => $folder,
                    'timestamp' => date('c')
                ];
            }
        }
    }

    /* ---------- Build final list (no limit) ---------- */
    $final = array_values($urlMap);
    usort($final, fn($a,$b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

    /* Count per folder in final log */
    foreach ($final as $e) {
        $f = $e['folder'];
        $folderInLog[$f] = ($folderInLog[$f] ?? 0) + 1;
    }

    /* ---------- Save ALL URLs ---------- */
    $pdo->prepare("UPDATE jpgsvault_table SET copied_links = ? WHERE id = 1")
        ->execute([json_encode($final)]);

    /* ---------- Output ---------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>copied_links – FULL LOG (No Limit)</title>
<style>
    body{font-family:system-ui,Arial,sans-serif;background:#f9f9f9;color:#1a1a1a;margin:2rem;line-height:1.6}
    h1{font-size:1.6rem;margin-bottom:.5rem}
    .stats{font-weight:600;color:#333;margin-bottom:1rem}
    .section{margin-bottom:2.5rem;background:#fff;padding:1.5rem;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
    table{width:100%;border-collapse:collapse}
    th{background:#0d6efd;color:#fff;padding:.8rem}
    td{padding:.7rem 1rem;border-bottom:1px solid #eee}
    tr:hover{background:#f8f9fa}
    .count-new{color:#16a34a;font-weight:bold}
    .zero{color:#dc3545}
    .url{font-family:monospace;font-size:.9rem;color:#0066cc;word-break:break-all}
    .folder-tag{background:#e0f2fe;color:#0c4a6e;padding:.2rem .5rem;border-radius:6px;font-size:.8rem}
    .time{color:#666;font-size:.8rem}
    .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem}
    .card{background:#fff;padding:1rem;border-radius:8px;text-align:center;box-shadow:0 1px 6px rgba(0,0,0,.1)}
    .card h3{font-size:1.1rem;margin:0 0 .5rem}
    .card p{font-size:1.4rem;font-weight:bold;margin:0;color:#0d6efd}
</style>
</head>
<body>

<h1>copied_links – FULL LOG (No 200 Limit)</h1>
<div class="stats">
    <span class="count-new"><?= count($newEntries) ?> new URLs added</span> |
    <strong><?= count($final) ?> total unique URLs saved</strong>
</div>

<div class="summary-grid">
    <div class="card">
        <h3>Total Folders</h3>
        <p><?= count($columns) ?></p>
    </div>
    <div class="card">
        <h3>Folders with Images</h3>
        <p><?= count(array_filter($folderStats, fn($c)=>$c>0)) ?></p>
    </div>
    <div class="card">
        <h3>Total Images</h3>
        <p><?= array_sum($folderStats) ?></p>
    </div>
    <div class="card">
        <h3>Unique URLs Saved</h3>
        <p><?= count($final) ?></p>
    </div>
</div>

<div class="section">
    <h2>Folder Image Counts</h2>
    <table>
        <tr><th>Folder</th><th>Images in DB</th><th>In copied_links</th></tr>
        <?php foreach ($folderStats as $folder => $count): ?>
            <tr>
                <td><strong><?= htmlspecialchars($folder) ?></strong></td>
                <td class="<?= $count == 0 ? 'zero' : '' ?>"><?= $count ?></td>
                <td><?= $folderInLog[$folder] ?? 0 ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="section">
    <h2>All Saved URLs (<?= count($final) ?>)</h2>
    <div style="max-height:600px;overflow-y:auto;border:1px solid #ddd;border-radius:8px">
        <?php foreach ($final as $e): ?>
            <div style="padding:.75rem 1rem;border-bottom:1px solid #eee;display:flex;flex-wrap:wrap;gap:1rem;align-items:center">
                <div class="url"><?= htmlspecialchars($e['url']) ?></div>
                <div class="folder-tag"><?= htmlspecialchars($e['folder']) ?></div>
                <div class="time"><?= $e['timestamp'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<p style="margin-top:2rem;font-size:.9rem;color:#555;">
    <strong>All URLs are now saved</strong> — no 200 limit. <br>
    Frontend will show <strong>all</strong> in history (even Sunshine_uploaded’s 1 image).
</p>

</body>
</html>