<?php
// debug_sync.php - Standalone debug script for JPGS Vault sync issues

// DATABASE CONNECTION
$host = 'sql201.infinityfree.com';
$dbname = 'if0_40367004_automation_tree';
$username = 'if0_40367004';
$password = 'NkwFAH15FRIlvCf';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Helper functions
function columnExists($pdo, $col) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM jpgsvault LIKE ?");
    $stmt->execute([$col]);
    return $stmt->rowCount() > 0;
}

function getImagesInFolder($pdo, $folder) {
    $stmt = $pdo->prepare("SELECT `$folder` FROM jpgsvault WHERE id = 1");
    $stmt->execute();
    $json = $stmt->fetchColumn();
    return $json ? json_decode($json, true) : [];
}

function saveImagesToFolder($pdo, $folder, $images) {
    $json = json_encode($images);
    $pdo->prepare("UPDATE jpgsvault SET `$folder` = ? WHERE id = 1")
        ->execute([$json]);
}

// Ensure base table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS jpgsvault (id INT AUTO_INCREMENT PRIMARY KEY)");
$pdo->prepare("INSERT IGNORE INTO jpgsvault (id) VALUES (1)")->execute();

echo "<!DOCTYPE html>
<html>
<head>
    <title>JPGS Vault Debug</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 15px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        h2 { margin-top: 0; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>🔍 JPGS Vault Debug Tool</h1>
";

// =============================================
// SECTION 1: Check Database
// =============================================
echo "<div class='section'>";
echo "<h2>📊 1. Database Structure</h2>";

// Check if table exists
$tableCheck = $pdo->query("SHOW TABLES LIKE 'jpgsvault'");
if ($tableCheck->rowCount() > 0) {
    echo "<p class='success'>✅ Table 'jpgsvault' exists</p>";
} else {
    echo "<p class='error'>❌ Table 'jpgsvault' does NOT exist!</p>";
}

// Get all columns
$stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
$columns = [];
echo "<h3>All Columns:</h3>";
echo "<table><tr><th>Column</th><th>Type</th><th>Null</th><th>Default</th></tr>";
while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $col['Field'];
    echo "<tr><td><strong>{$col['Field']}</strong></td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Default']}</td></tr>";
}
echo "</table>";

// Check row 1 data
$stmt = $pdo->query("SELECT * FROM jpgsvault WHERE id = 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "<p class='success'>✅ Row ID 1 exists</p>";
    echo "<h3>Row Data (non-JSON columns):</h3>";
    echo "<pre>";
    foreach ($row as $key => $value) {
        if (!is_null($value) && !in_array($key, ['copied_links', 'server_passkey'])) {
            if (!str_starts_with($key, 'all_urls')) {
                echo "$key: " . (strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value) . "\n";
            }
        }
    }
    echo "</pre>";
} else {
    echo "<p class='error'>❌ Row ID 1 does NOT exist!</p>";
}

echo "</div>";

// =============================================
// SECTION 2: Check Filesystem
// =============================================
echo "<div class='section'>";
echo "<h2>📁 2. Filesystem Structure</h2>";

$jpgsDir = 'jpgs/';
if (is_dir($jpgsDir)) {
    echo "<p class='success'>✅ Directory 'jpgs/' exists</p>";
    
    // List all folders
    $folders = array_filter(scandir($jpgsDir), function($f) use ($jpgsDir) {
        return $f !== '.' && $f !== '..' && is_dir($jpgsDir . $f);
    });
    
    if (empty($folders)) {
        echo "<p class='error'>❌ No folders found in jpgs/ directory!</p>";
    } else {
        echo "<h3>Folders found:</h3>";
        echo "<table><tr><th>Folder Name</th><th>Images Count</th><th>In Database?</th></tr>";
        
        foreach ($folders as $folder) {
            $folderPath = $jpgsDir . $folder;
            $files = scandir($folderPath);
            $imageCount = 0;
            $images = [];
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (is_file($folderPath . '/' . $file) && 
                    preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $file)) {
                    $imageCount++;
                    $images[] = $file;
                }
            }
            
            $inDb = columnExists($pdo, $folder) ? '✅ Yes' : '❌ No';
            $style = $inDb === '✅ Yes' ? 'color: green;' : 'color: red;';
            
            echo "<tr>
                <td><strong>$folder</strong></td>
                <td>$imageCount</td>
                <td style='$style'>$inDb</td>
            </tr>";
            
            // Show first 5 images
            if ($imageCount > 0) {
                echo "<tr><td colspan='3' style='font-size: 0.9em; color: #666;'>";
                echo "Sample images: " . implode(', ', array_slice($images, 0, 5));
                if ($imageCount > 5) echo " ... and " . ($imageCount - 5) . " more";
                echo "</td></tr>";
            }
        }
        echo "</table>";
    }
    
    // Check write permissions
    echo "<h3>Permissions:</h3>";
    echo "<p>jpgs/ is " . (is_writable($jpgsDir) ? "<span class='success'>writable</span>" : "<span class='error'>NOT writable</span>") . "</p>";
    
} else {
    echo "<p class='error'>❌ Directory 'jpgs/' does NOT exist!</p>";
    echo "<p>Current directory: " . __DIR__ . "</p>";
}

echo "</div>";

// =============================================
// SECTION 3: Check Sync Functionality
// =============================================
echo "<div class='section'>";
echo "<h2>🔄 3. Sync Test</h2>";

$jpgsDir = 'jpgs/';
if (is_dir($jpgsDir)) {
    $folders = array_filter(scandir($jpgsDir), function($f) use ($jpgsDir) {
        return $f !== '.' && $f !== '..' && is_dir($jpgsDir . $f);
    });
    
    $totalSynced = 0;
    echo "<h3>Attempting to sync folders:</h3>";
    echo "<table><tr><th>Folder</th><th>Images Found</th><th>Action</th><th>Status</th></tr>";
    
    foreach ($folders as $folder) {
        $folderPath = $jpgsDir . $folder;
        $images = [];
        $files = scandir($folderPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_file($folderPath . '/' . $file) && 
                preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $file)) {
                $images[] = 'jpgs/' . $folder . '/' . $file;
            }
        }
        
        $action = '';
        $status = '';
        
        if (!columnExists($pdo, $folder)) {
            try {
                $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$folder` JSON DEFAULT NULL");
                $action = 'Created column';
                $status = "<span class='success'>✅ Created</span>";
            } catch (Exception $e) {
                $action = 'Error';
                $status = "<span class='error'>❌ " . $e->getMessage() . "</span>";
            }
        } else {
            $action = 'Column exists';
            $status = "<span class='info'>⏭️ Skipped</span>";
        }
        
        if (!empty($images)) {
            if (columnExists($pdo, $folder)) {
                saveImagesToFolder($pdo, $folder, $images);
                $totalSynced += count($images);
                $action = 'Synced ' . count($images) . ' images';
                $status = "<span class='success'>✅ Done</span>";
                
                // Verify the data was saved
                $saved = getImagesInFolder($pdo, $folder);
                if (count($saved) === count($images)) {
                    $status = "<span class='success'>✅ Verified (" . count($saved) . " images)</span>";
                } else {
                    $status = "<span class='error'>⚠️ Mismatch: Found " . count($images) . ", saved " . count($saved) . "</span>";
                }
            }
        } else {
            if ($action === 'Column exists') {
                $action = 'No images';
                $status = "<span class='info'>⏭️ Skipped</span>";
            }
        }
        
        echo "<tr>
            <td><strong>$folder</strong></td>
            <td>" . count($images) . "</td>
            <td>$action</td>
            <td>$status</td>
        </tr>";
    }
    echo "</table>";
    echo "<p><strong>Total images synced: $totalSynced</strong></p>";
} else {
    echo "<p class='error'>❌ Cannot sync - jpgs/ directory not found</p>";
}

echo "</div>";

// =============================================
// SECTION 4: Verify Database Data
// =============================================
echo "<div class='section'>";
echo "<h2>📋 4. Database Data Verification</h2>";

// Get all folder columns (non-system)
$stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
$folderColumns = [];
while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $field = $col['Field'];
    if ($field !== 'id' && $field !== 'copied_links' && $field !== 'server_passkey' && 
        !str_starts_with($field, 'all_urls')) {
        $folderColumns[] = $field;
    }
}

echo "<h3>Folder columns:</h3>";
echo "<table><tr><th>Column</th><th>Images in DB</th><th>Images on Disk</th><th>Status</th></tr>";

foreach ($folderColumns as $col) {
    $dbImages = getImagesInFolder($pdo, $col);
    $validDbImages = array_filter($dbImages, function($p) { return file_exists($p); });
    $dbCount = count($dbImages);
    $validCount = count($validDbImages);
    
    // Check disk
    $diskPath = 'jpgs/' . $col . '/';
    $diskCount = 0;
    if (is_dir($diskPath)) {
        $files = scandir($diskPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (is_file($diskPath . $file) && 
                preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $file)) {
                $diskCount++;
            }
        }
    }
    
    $status = '';
    if ($dbCount > 0 && $diskCount > 0) {
        $status = "<span class='success'>✅ Synced</span>";
    } elseif ($dbCount > 0 && $diskCount === 0) {
        $status = "<span class='error'>❌ DB has data but disk empty</span>";
    } elseif ($dbCount === 0 && $diskCount > 0) {
        $status = "<span class='error'>⚠️ Disk has data but DB empty</span>";
    } else {
        $status = "<span class='info'>⏭️ Empty</span>";
    }
    
    echo "<tr>
        <td><strong>$col</strong></td>
        <td>$dbCount ($validCount valid)</td>
        <td>$diskCount</td>
        <td>$status</td>
    </tr>";
}
echo "</table>";

echo "</div>";

// =============================================
// SECTION 5: Actions
// =============================================
echo "<div class='section'>";
echo "<h2>🛠️ 5. Actions</h2>";

echo "<p><a href='?force_sync=true' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Force Full Sync</a></p>";

if (isset($_GET['force_sync']) && $_GET['force_sync'] === 'true') {
    echo "<div style='background: #e8f5e9; padding: 15px; border-radius: 4px; margin-top: 10px;'>";
    echo "<h3>Sync Results:</h3>";
    
    $jpgsDir = 'jpgs/';
    if (is_dir($jpgsDir)) {
        $folders = array_filter(scandir($jpgsDir), function($f) use ($jpgsDir) {
            return $f !== '.' && $f !== '..' && is_dir($jpgsDir . $f);
        });
        
        $total = 0;
        foreach ($folders as $folder) {
            $folderPath = $jpgsDir . $folder;
            $images = [];
            $files = scandir($folderPath);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (is_file($folderPath . '/' . $file) && 
                    preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $file)) {
                    $images[] = 'jpgs/' . $folder . '/' . $file;
                }
            }
            
            if (!columnExists($pdo, $folder)) {
                $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$folder` JSON DEFAULT NULL");
                echo "<p>✅ Created column: $folder</p>";
            }
            
            if (!empty($images)) {
                saveImagesToFolder($pdo, $folder, $images);
                $total += count($images);
                echo "<p>✅ Synced " . count($images) . " images to: $folder</p>";
            }
        }
        echo "<p><strong>Total synced: $total images</strong></p>";
    }
    echo "</div>";
}

echo "</div>";

echo "
    <div class='section'>
        <h2>💡 Summary</h2>
        <ul>
            <li>If you see 'Disk has data but DB empty' → The sync function needs to run</li>
            <li>If you see 'DB has data but disk empty' → The database has stale data</li>
            <li>If you see no folders in jpgs/ → Your images might be in a different location</li>
            <li>If columns are missing → The sync will create them</li>
        </ul>
        <p><a href='debug_sync.php' style='background: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Refresh Debug</a></p>
    </div>
";

echo "</body></html>";
?>