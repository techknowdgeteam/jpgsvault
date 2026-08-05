<?php
// view_copied_folders.php - Standalone script to view all folders in copied links
session_start();

// Check if user is authenticated
$isAuthenticated = isset($_SESSION['jpgsvault_authenticated']) && $_SESSION['jpgsvault_authenticated'] === true;

// Check inactivity timeout (30 minutes)
if ($isAuthenticated && isset($_SESSION['jpgsvault_last_activity'])) {
    if (time() - $_SESSION['jpgsvault_last_activity'] > 1800) {
        session_destroy();
        $isAuthenticated = false;
    } else {
        $_SESSION['jpgsvault_last_activity'] = time();
    }
}

// If not authenticated, redirect to login page
if (!$isAuthenticated) {
    header('Location: login.php');
    exit;
}

// Database configuration
$host = 'sql201.infinityfree.com';
$dbname = 'if0_40367004_automation_tree';
$username = 'if0_40367004';
$password = 'NkwFAH15FRIlvCf';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get copied links data
function getCopiedLinksData($pdo) {
    try {
        $stmt = $pdo->query("SELECT copied_links FROM jpgsvault WHERE id = 1");
        $json = $stmt->fetchColumn();
        if (!$json) return ['error' => 'No copied links data found'];
        
        $logs = json_decode($json, true);
        if (!is_array($logs)) return ['error' => 'Invalid JSON data'];
        
        // Group by folder
        $folderData = [];
        $totalUrls = 0;
        $urlsWithoutFolder = 0;
        
        foreach ($logs as $index => $log) {
            if (!isset($log['url'])) continue;
            
            $url = $log['url'];
            $folder = isset($log['folder']) ? trim($log['folder']) : 'Uncategorized';
            
            // Skip empty folder names
            if (empty($folder)) {
                $folder = 'Uncategorized';
                $urlsWithoutFolder++;
            }
            
            if (!isset($folderData[$folder])) {
                $folderData[$folder] = [
                    'folder' => $folder,
                    'urls' => [],
                    'count' => 0,
                    'first_url' => null,
                    'last_url' => null
                ];
            }
            
            // Store unique URLs per folder
            if (!in_array($url, $folderData[$folder]['urls'])) {
                $folderData[$folder]['urls'][] = $url;
                $folderData[$folder]['count']++;
                
                // Track first and last URL in folder
                if ($folderData[$folder]['first_url'] === null) {
                    $folderData[$folder]['first_url'] = $url;
                }
                $folderData[$folder]['last_url'] = $url;
                
                $totalUrls++;
            }
        }
        
        // Sort folders by count (most URLs first)
        uasort($folderData, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return [
            'success' => true,
            'folders' => $folderData,
            'total_folders' => count($folderData),
            'total_urls' => $totalUrls,
            'urls_without_folder' => $urlsWithoutFolder,
            'raw_data_count' => count($logs)
        ];
        
    } catch (PDOException $e) {
        return ['error' => 'Database error: ' . $e->getMessage()];
    }
}

$result = getCopiedLinksData($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copied Links - Folder Viewer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #e8f4f8 0%, #d4e9f0 50%, #e8f5e9 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
        }
        h1 {
            color: #2c3e50;
            font-size: 28px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .subtitle {
            color: #5a7a8a;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: linear-gradient(135deg, #f8fcf9, #f0faf5);
            padding: 15px 20px;
            border-radius: 12px;
            border: 1px solid #e0ece8;
        }
        .stat-card .label {
            font-size: 12px;
            color: #8aa8b8;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-top: 5px;
        }
        .stat-card .value.primary { color: #2ecc71; }
        .stat-card .value.warning { color: #f39c12; }
        .stat-card .value.info { color: #3498db; }
        
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .folder-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e0ece8;
            transition: all 0.3s;
            cursor: pointer;
        }
        .folder-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(46,204,113,0.12);
            border-color: #2ecc71;
        }
        .folder-card .folder-name {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .folder-card .folder-name .badge {
            background: linear-gradient(135deg, #2ecc71, #1abc9c);
            color: white;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .folder-card .folder-details {
            font-size: 14px;
            color: #5a7a8a;
        }
        .folder-card .folder-details .url-preview {
            margin-top: 10px;
            padding: 10px;
            background: #f8fcf9;
            border-radius: 8px;
            font-size: 12px;
            color: #5a7a8a;
            word-break: break-all;
            max-height: 80px;
            overflow-y: auto;
            border: 1px solid #e8f0ec;
        }
        .folder-card .folder-details .url-preview::-webkit-scrollbar {
            width: 4px;
        }
        .folder-card .folder-details .url-preview::-webkit-scrollbar-thumb {
            background: #2ecc71;
            border-radius: 4px;
        }
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #5a7a8a;
        }
        .no-data .icon { font-size: 48px; margin-bottom: 15px; }
        .back-btn {
            display: inline-block;
            padding: 10px 25px;
            background: linear-gradient(135deg, #2ecc71, #1abc9c);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            margin-top: 20px;
        }
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(46,204,113,0.3);
        }
        .error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .refresh-btn {
            padding: 8px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            margin-left: 15px;
        }
        .refresh-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        .detail-view {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #f8fcf9;
            border-radius: 12px;
            border: 1px solid #e0ece8;
        }
        .detail-view.active {
            display: block;
        }
        .detail-view .url-list {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 15px;
        }
        .detail-view .url-list .url-item {
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 5px;
            font-size: 13px;
            word-break: break-all;
            border: 1px solid #e8f0ec;
        }
        .detail-view .url-list .url-item:hover {
            background: #f0faf5;
            border-color: #2ecc71;
        }
        .detail-view .close-detail {
            float: right;
            padding: 4px 12px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
        }
        .detail-view .close-detail:hover {
            background: #c0392b;
        }
        @media (max-width: 768px) {
            .folder-grid {
                grid-template-columns: 1fr;
            }
            .stats {
                grid-template-columns: 1fr 1fr;
            }
            .container { padding: 15px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h1>
                📁 Copied Links Folders
                <button class="refresh-btn" onclick="location.reload()">🔄 Refresh</button>
            </h1>
            <a href="serenum.php" class="back-btn">← Back to Serenum</a>
        </div>
        
        <?php if (isset($result['error'])): ?>
            <div class="error">
                <strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?>
            </div>
        <?php else: ?>
            <p class="subtitle">All folders found in the copied_links data from JPGS Vault</p>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="label">Total Folders</div>
                    <div class="value primary"><?php echo $result['total_folders']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Total URLs</div>
                    <div class="value info"><?php echo $result['total_urls']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Raw Entries</div>
                    <div class="value"><?php echo $result['raw_data_count']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Uncategorized</div>
                    <div class="value warning"><?php echo $result['urls_without_folder']; ?></div>
                </div>
            </div>
            
            <?php if ($result['total_folders'] > 0): ?>
                <div class="folder-grid">
                    <?php foreach ($result['folders'] as $folder => $data): ?>
                        <div class="folder-card" onclick="toggleDetail('<?php echo addslashes($folder); ?>')">
                            <div class="folder-name">
                                <span><?php echo htmlspecialchars($folder); ?></span>
                                <span class="badge"><?php echo $data['count']; ?> URLs</span>
                            </div>
                            <div class="folder-details">
                                <?php if ($data['count'] > 0): ?>
                                    <div class="url-preview">
                                        <?php 
                                        $previewUrls = array_slice($data['urls'], 0, 3);
                                        foreach ($previewUrls as $url): ?>
                                            <?php echo htmlspecialchars($url); ?><br>
                                        <?php endforeach; ?>
                                        <?php if ($data['count'] > 3): ?>
                                            <em style="color:#8aa8b8;">... and <?php echo ($data['count'] - 3); ?> more</em>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Detail View -->
                <div id="detailView" class="detail-view">
                    <button class="close-detail" onclick="closeDetail()">✕ Close</button>
                    <h3 id="detailFolderName" style="margin-bottom:10px; color:#2c3e50;"></h3>
                    <div id="detailUrlCount" style="color:#5a7a8a; font-size:14px; margin-bottom:10px;"></div>
                    <div class="url-list" id="detailUrlList"></div>
                </div>
                
            <?php else: ?>
                <div class="no-data">
                    <div class="icon">📭</div>
                    <p>No folders found in copied links data.</p>
                    <p style="font-size:14px; margin-top:10px;">Make sure you've copied some image links from JPGS Vault first.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        // Store folder data for detail view
        const folderData = <?php echo json_encode($result['folders'] ?? []); ?>;
        
        function toggleDetail(folderName) {
            const detailView = document.getElementById('detailView');
            const folderNameEl = document.getElementById('detailFolderName');
            const urlCountEl = document.getElementById('detailUrlCount');
            const urlListEl = document.getElementById('detailUrlList');
            
            // Get data for this folder
            const data = folderData[folderName];
            if (!data) return;
            
            folderNameEl.textContent = '📁 ' + folderName;
            urlCountEl.textContent = data.count + ' URL(s) in this folder';
            
            // Build URL list
            urlListEl.innerHTML = '';
            data.urls.forEach(function(url, index) {
                const div = document.createElement('div');
                div.className = 'url-item';
                div.innerHTML = (index + 1) + '. ' + url;
                urlListEl.appendChild(div);
            });
            
            // Show detail view
            detailView.classList.add('active');
            
            // Scroll to detail view
            detailView.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        
        function closeDetail() {
            document.getElementById('detailView').classList.remove('active');
        }
        
        // Close detail on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDetail();
            }
        });
        
        // Log data to console for debugging
        console.log('Total folders found:', Object.keys(folderData).length);
        console.log('Folder names:', Object.keys(folderData));
    </script>
</body>
</html>