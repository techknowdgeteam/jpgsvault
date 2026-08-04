<?php
// jpgsvault.php - Main Gallery Application
// ---------------------------------------------------------------
// SESSION & AUTHENTICATION CHECK
// ---------------------------------------------------------------
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

// ---------------------------------------------------------------
// DATABASE CONNECTION
// ---------------------------------------------------------------
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

// Ensure base table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS jpgsvault (id INT AUTO_INCREMENT PRIMARY KEY)");

// ADD server_passkey COLUMN (stores the passkey)
if (!columnExists($pdo, 'server_passkey')) {
    $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN server_passkey VARCHAR(255) DEFAULT NULL");
}

// ADD copied_links COLUMN (stores all copied URLs)
if (!columnExists($pdo, 'copied_links')) {
    $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN copied_links JSON DEFAULT NULL");
}

// ADD uploadedjpgs COLUMN (stores URLs to auto-move)
if (!columnExists($pdo, 'uploadedjpgs')) {
    $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN uploadedjpgs JSON DEFAULT NULL");
}

// ---------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------
function columnExists($pdo, $col) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM jpgsvault LIKE ?");
    $stmt->execute([$col]);
    return $stmt->rowCount() > 0;
}

function formatName($col) {
    return ucwords(str_replace('_', ' ', $col));
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

function getPairedUploadedFolder($folder) {
    return $folder . '_uploaded';
}

function isUploadedFolder($folder) {
    return str_ends_with($folder, '_uploaded');
}

function getOriginalFolder($uploadedFolder) {
    return substr($uploadedFolder, 0, -9);
}

function ensureDir($dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function rmdirIfEmpty($dir) {
    if (is_dir($dir) && count(glob("$dir/*")) === 0) @rmdir($dir);
}

function baseUrl() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
}

// ---------------------------------------------------------------
// SCAN AND CREATE MISSING FOLDERS
// ---------------------------------------------------------------
function scanAndCreateMissingFolders($pdo) {
    $result = [
        'created_folders' => [],
        'created_uploaded_folders' => [],
        'existing_folders' => [],
        'scanned_directories' => [],
        'total_images_found' => 0,
        'errors' => []
    ];
    
    $existingColumns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && 
            $field !== 'copied_links' && 
            $field !== 'server_passkey' && 
            $field !== 'uploadedjpgs' &&
            $field !== 'all_urls' &&
            $field !== 'all_urls_uploaded') {
            $existingColumns[] = $field;
        }
    }
    
    $jpgsDir = 'jpgs/';
    if (!is_dir($jpgsDir)) {
        return $result;
    }
    
    $scannedFolders = scandir($jpgsDir);
    $folderNames = array_filter($scannedFolders, function($item) {
        return $item !== '.' && $item !== '..' && is_dir('jpgs/' . $item);
    });
    
    $result['scanned_directories'] = $folderNames;
    
    foreach ($folderNames as $folderName) {
        if (isUploadedFolder($folderName)) {
            $mainFolder = getOriginalFolder($folderName);
            if (!in_array($mainFolder, $existingColumns) && !columnExists($pdo, $mainFolder)) {
                try {
                    $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$mainFolder` JSON DEFAULT NULL");
                    $result['created_folders'][] = $mainFolder;
                    $existingColumns[] = $mainFolder;
                } catch (Exception $e) {
                    $result['errors'][] = "Failed to create folder $mainFolder: " . $e->getMessage();
                }
            }
            continue;
        }
        
        if (!in_array($folderName, $existingColumns) && !columnExists($pdo, $folderName)) {
            try {
                $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$folderName` JSON DEFAULT NULL");
                $result['created_folders'][] = $folderName;
                $existingColumns[] = $folderName;
            } catch (Exception $e) {
                $result['errors'][] = "Failed to create folder $folderName: " . $e->getMessage();
            }
        }
        
        $uploadedFolder = getPairedUploadedFolder($folderName);
        if (!in_array($uploadedFolder, $existingColumns) && !columnExists($pdo, $uploadedFolder)) {
            try {
                $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$uploadedFolder` JSON DEFAULT NULL");
                $result['created_uploaded_folders'][] = $uploadedFolder;
                $existingColumns[] = $uploadedFolder;
            } catch (Exception $e) {
                $result['errors'][] = "Failed to create uploaded folder $uploadedFolder: " . $e->getMessage();
            }
        }
    }
    
    // After creating columns, scan images and populate them
    $allFolders = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && 
            $field !== 'copied_links' && 
            $field !== 'server_passkey' && 
            $field !== 'uploadedjpgs' &&
            $field !== 'all_urls' &&
            $field !== 'all_urls_uploaded' &&
            !isUploadedFolder($field)) {
            $allFolders[] = $field;
        }
    }
    
    foreach ($allFolders as $folderName) {
        $folderPath = "jpgs/$folderName/";
        if (!is_dir($folderPath)) {
            ensureDir($folderPath);
            continue;
        }
        
        $dbImages = getImagesInFolder($pdo, $folderName);
        $files = glob($folderPath . "*.*");
        $fsImages = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $fsImages[] = $file;
            }
        }
        
        if (!empty($fsImages)) {
            $allImages = array_merge($dbImages, $fsImages);
            $uniqueImages = array_values(array_unique($allImages));
            
            if (count($uniqueImages) > count($dbImages)) {
                saveImagesToFolder($pdo, $folderName, $uniqueImages);
                $result['total_images_found'] += count($fsImages);
            }
        }
    }
    
    // Also scan and populate uploaded folders
    $uploadedFolders = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && 
            $field !== 'copied_links' && 
            $field !== 'server_passkey' && 
            $field !== 'uploadedjpgs' &&
            $field !== 'all_urls' &&
            $field !== 'all_urls_uploaded' &&
            isUploadedFolder($field)) {
            $uploadedFolders[] = $field;
        }
    }
    
    foreach ($uploadedFolders as $folderName) {
        $folderPath = "jpgs/$folderName/";
        if (!is_dir($folderPath)) {
            ensureDir($folderPath);
            continue;
        }
        
        $dbImages = getImagesInFolder($pdo, $folderName);
        $files = glob($folderPath . "*.*");
        $fsImages = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $fsImages[] = $file;
            }
        }
        
        if (!empty($fsImages)) {
            $allImages = array_merge($dbImages, $fsImages);
            $uniqueImages = array_values(array_unique($allImages));
            
            if (count($uniqueImages) > count($dbImages)) {
                saveImagesToFolder($pdo, $folderName, $uniqueImages);
            }
        }
    }
    
    return $result;
}

// ---------------------------------------------------------------
// EXTRACT URLS FROM UPLOADED DATA
// ---------------------------------------------------------------
function extractUrlsFromUploadedData($data) {
    $result = [
        'urls' => [],
        'folder' => null,
        'metadata' => [],
        'markers' => []
    ];
    
    if (!is_array($data)) {
        return $result;
    }
    
    foreach ($data as $item) {
        if (is_array($item) && isset($item['folder'])) {
            $result['folder'] = $item['folder'];
            $result['markers'][] = 'folder';
            continue;
        }
        
        if (is_array($item)) {
            $isMetadata = false;
            foreach ($item as $key => $value) {
                if (str_starts_with($key, '_')) {
                    $isMetadata = true;
                    $result['metadata'][$key] = $value;
                }
            }
            if ($isMetadata) {
                $result['markers'][] = 'metadata';
                continue;
            }
        }
        
        if (is_string($item)) {
            $url = trim($item);
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $result['urls'][] = $url;
            }
        }
    }
    
    return $result;
}

// ---------------------------------------------------------------
// AUTO-MOVE UPLOADED JPGS ON PAGE LOAD
// ---------------------------------------------------------------
function autoMoveUploadedJpgs($pdo) {
    $result = [
        'success' => false,
        'message' => '',
        'moved_count' => 0,
        'failed_count' => 0,
        'target_folder' => null,
        'uploaded_folder' => null,
        'moved_from_folders' => [],
        'urls_processed' => 0,
        'urls_not_found' => [],
        'details' => [],
        'created_new_folder' => false,
        'folder_created' => null
    ];
    
    if (!columnExists($pdo, 'uploadedjpgs')) {
        $result['message'] = "uploadedjpgs column does not exist";
        return $result;
    }
    
    $stmt = $pdo->prepare("SELECT uploadedjpgs FROM jpgsvault WHERE id = 1");
    $stmt->execute();
    $jsonData = $stmt->fetchColumn();
    
    if (!$jsonData) {
        $result['success'] = true;
        $result['message'] = "No data to process";
        return $result;
    }
    
    $uploadedData = json_decode($jsonData, true);
    if (!is_array($uploadedData)) {
        $result['message'] = "Invalid JSON data in uploadedjpgs column";
        return $result;
    }
    
    $extracted = extractUrlsFromUploadedData($uploadedData);
    $urlsToMove = $extracted['urls'];
    $targetFolderName = $extracted['folder'];
    $metadata = $extracted['metadata'];
    
    $result['urls_processed'] = count($urlsToMove);
    $result['target_folder'] = $targetFolderName;
    $result['details']['metadata'] = $metadata;
    
    if (empty($targetFolderName)) {
        $result['message'] = "No 'folder' marker found in uploadedjpgs data";
        return $result;
    }
    
    if (empty($urlsToMove)) {
        $result['success'] = true;
        $result['message'] = "No URLs to move for folder '$targetFolderName'";
        return $result;
    }
    
    $allFolders = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && 
            $field !== 'copied_links' && 
            $field !== 'server_passkey' && 
            $field !== 'uploadedjpgs' &&
            $field !== 'all_urls' &&
            $field !== 'all_urls_uploaded' &&
            !isUploadedFolder($field)) {
            $allFolders[] = $field;
        }
    }
    
    $uploadedFolder = getPairedUploadedFolder($targetFolderName);
    $createdNewFolder = false;
    
    if (!columnExists($pdo, $uploadedFolder)) {
        try {
            $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$uploadedFolder` JSON DEFAULT NULL");
            $createdNewFolder = true;
            $result['created_new_folder'] = true;
            $result['folder_created'] = $uploadedFolder;
            error_log("JPGS Vault: Created new uploaded folder column: $uploadedFolder");
        } catch (Exception $e) {
            $result['message'] = "Failed to create uploaded folder column: " . $e->getMessage();
            return $result;
        }
    }
    
    if (!columnExists($pdo, $targetFolderName)) {
        try {
            $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$targetFolderName` JSON DEFAULT NULL");
            error_log("JPGS Vault: Created new main folder column: $targetFolderName");
        } catch (Exception $e) {
            // Continue anyway
        }
    }
    
    $result['uploaded_folder'] = $uploadedFolder;
    $uploadedImages = getImagesInFolder($pdo, $uploadedFolder);
    $targetDir = "jpgs/$uploadedFolder/";
    ensureDir($targetDir);
    
    $mainDir = "jpgs/$targetFolderName/";
    ensureDir($mainDir);
    
    $allImagesMap = [];
    $folderMap = [];
    
    foreach ($allFolders as $folderName) {
        $images = getImagesInFolder($pdo, $folderName);
        foreach ($images as $path) {
            $filename = basename($path);
            $allImagesMap[$filename] = $path;
            $folderMap[$path] = $folderName;
        }
    }
    
    $movedPaths = [];
    $removedOriginalPaths = [];
    $failed = [];
    $foundPaths = [];
    $notFoundUrls = [];
    
    foreach ($urlsToMove as $index => $url) {
        $url = trim($url);
        $url = preg_replace('/\?v=\d+$/', '', $url);
        $decodedUrl = urldecode($url);
        $inputFilename = basename($decodedUrl);
        $inputFilename = urldecode($inputFilename);
        
        $matchedPath = null;
        $matchedFolder = null;
        $found = false;
        
        if (isset($allImagesMap[$inputFilename])) {
            $matchedPath = $allImagesMap[$inputFilename];
            $matchedFolder = $folderMap[$matchedPath];
            $found = true;
        } else {
            foreach ($allImagesMap as $storedFilename => $storedPath) {
                if (strcasecmp($storedFilename, $inputFilename) === 0) {
                    $matchedPath = $storedPath;
                    $matchedFolder = $folderMap[$matchedPath];
                    $found = true;
                    break;
                }
            }
        }
        
        if (!$found) {
            foreach ($allFolders as $folderName) {
                $images = getImagesInFolder($pdo, $folderName);
                foreach ($images as $storedPath) {
                    if (strcasecmp(basename($storedPath), $inputFilename) === 0) {
                        $matchedPath = $storedPath;
                        $matchedFolder = $folderName;
                        $found = true;
                        break 2;
                    }
                }
            }
        }
        
        if (!$found) {
            $notFoundUrls[] = $url;
            $failed[] = $url;
            continue;
        }
        
        $oldPath = $matchedPath;
        $filename = basename($oldPath);
        $newPath = $targetDir . $filename;
        
        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $counter = 1;
            do {
                $filename = $name . '_' . $counter . '.' . $ext;
                $newPath = $targetDir . $filename;
                $counter++;
            } while (file_exists($newPath));
        }
        
        $realOld = realpath($oldPath);
        if ($realOld && is_file($realOld)) {
            if (rename($realOld, $newPath)) {
                $movedPaths[] = $newPath;
                $removedOriginalPaths[] = $oldPath;
                if (!isset($foundPaths[$matchedFolder])) {
                    $foundPaths[$matchedFolder] = [];
                }
                $foundPaths[$matchedFolder][] = $oldPath;
            } else {
                $failed[] = $oldPath;
            }
        } else {
            $failed[] = $oldPath;
        }
    }
    
    if (!empty($movedPaths)) {
        $newUploaded = array_merge($uploadedImages, $movedPaths);
        saveImagesToFolder($pdo, $uploadedFolder, $newUploaded);
        
        foreach ($foundPaths as $sourceFolder => $paths) {
            if (empty($paths)) continue;
            $currentImages = getImagesInFolder($pdo, $sourceFolder);
            $remaining = array_values(array_diff($currentImages, $paths));
            saveImagesToFolder($pdo, $sourceFolder, $remaining);
            $sourceDir = "jpgs/$sourceFolder/";
            rmdirIfEmpty($sourceDir);
        }
        
        purgeCopiedLog($pdo, $removedOriginalPaths);
        
        $clearData = [
            ["folder" => $targetFolderName],
            [
                "_timestamp" => date('c'),
                "_status" => "processed",
                "_processed_at" => date('c'),
                "_total_moved" => count($movedPaths),
                "_total_failed" => count($failed),
                "_moved_from_folders" => array_keys($foundPaths),
                "_previous_metadata" => $metadata
            ]
        ];
        
        $pdo->prepare("UPDATE jpgsvault SET uploadedjpgs = ? WHERE id = 1")
            ->execute([json_encode($clearData)]);
        
        syncAllUrls($pdo);
        
        $result['moved_count'] = count($movedPaths);
        $result['moved_from_folders'] = array_keys($foundPaths);
        $result['message'] = "Successfully moved " . count($movedPaths) . " images to '$uploadedFolder'";
        $result['success'] = true;
    } else {
        $result['success'] = true;
        $result['message'] = "No images were moved";
    }
    
    $result['failed_count'] = count($failed);
    $result['urls_not_found'] = $notFoundUrls;
    $result['details']['found_paths'] = $foundPaths;
    $result['details']['moved_paths'] = $movedPaths;
    
    return $result;
}

// ---------------------------------------------------------------
// PURGE COPIED LOG
// ---------------------------------------------------------------
function purgeCopiedLog($pdo, $paths) {
    if (empty($paths)) return;
    $stmt = $pdo->prepare("SELECT copied_links FROM jpgsvault WHERE id = 1");
    $stmt->execute();
    $json = $stmt->fetchColumn();
    if (!$json) return;

    $logs = json_decode($json, true);
    if (!is_array($logs)) return;

    $base = baseUrl();
    $deletedUrls = array_map(fn($p) => rtrim($base, '/') . '/' . ltrim($p, '/'), $paths);

    $logs = array_filter($logs, fn($log) => !in_array($log['url'], $deletedUrls));

    $pdo->prepare("UPDATE jpgsvault SET copied_links = ? WHERE id = 1")
        ->execute([json_encode(array_values($logs))]);
}

// ---------------------------------------------------------------
// SYNC ALL URLS
// ---------------------------------------------------------------
function syncAllUrls($pdo) {
    $allUrls = [];
    $totalUrls = 0;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && 
            $field !== 'copied_links' && 
            $field !== 'server_passkey' && 
            $field !== 'uploadedjpgs' &&
            !isUploadedFolder($field) &&
            $field !== 'all_urls' &&
            $field !== 'all_urls_uploaded') {
            
            $images = getImagesInFolder($pdo, $field);
            $validImages = array_filter($images, function($p) {
                return file_exists($p);
            });
            
            if (!empty($validImages)) {
                $allUrls = array_merge($allUrls, array_values($validImages));
                $totalUrls += count($validImages);
            }
        }
    }
    
    $allUrls[] = "total_urls: " . $totalUrls;
    
    $pdo->prepare("UPDATE jpgsvault SET all_urls = ? WHERE id = 1")
        ->execute([json_encode($allUrls)]);
    
    return $totalUrls;
}

// ---------------------------------------------------------------
// INITIAL SETUP
// ---------------------------------------------------------------
$pdo->prepare("INSERT IGNORE INTO jpgsvault (id) VALUES (1)")->execute();

// AUTO-CREATE UPLOADED COLUMN FOR EACH FOLDER
$allColumns = [];
$stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($col['Field'] !== 'id' && $col['Field'] !== 'copied_links' && $col['Field'] !== 'server_passkey' && $col['Field'] !== 'uploadedjpgs') {
        $allColumns[] = $col['Field'];
    }
}
foreach ($allColumns as $col) {
    if (!isUploadedFolder($col)) {
        $uploaded = getPairedUploadedFolder($col);
        if (!columnExists($pdo, $uploaded)) {
            $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$uploaded` JSON DEFAULT NULL");
        }
    }
}

// SYNC on page load
$syncedCount = syncAllUrls($pdo);

// SCAN AND CREATE MISSING FOLDERS ON PAGE LOAD
$scanResult = scanAndCreateMissingFolders($pdo);
if (!empty($scanResult['created_folders']) || !empty($scanResult['created_uploaded_folders'])) {
    error_log("JPGS Vault: Created " . count($scanResult['created_folders']) . " main folders and " . 
              count($scanResult['created_uploaded_folders']) . " uploaded folders from filesystem scan");
}
if ($scanResult['total_images_found'] > 0) {
    error_log("JPGS Vault: Synced " . $scanResult['total_images_found'] . " images from filesystem");
}

error_log("JPGS Vault: Synced all_urls with " . $syncedCount . " valid URLs on page load");

// RUN AUTO-MOVE ON PAGE LOAD (but not during AJAX requests)
$autoMoveResult = null;
$isAjaxRequest = isset($_GET['action']) || isset($_POST['action']);

if (!$isAjaxRequest) {
    $autoMoveResult = autoMoveUploadedJpgs($pdo);
    
    if ($autoMoveResult['success'] && $autoMoveResult['moved_count'] > 0) {
        error_log("JPGS Vault: Auto-moved " . $autoMoveResult['moved_count'] . " images to '" . $autoMoveResult['uploaded_folder'] . "'");
        if ($autoMoveResult['created_new_folder']) {
            error_log("JPGS Vault: Created new folder: " . $autoMoveResult['folder_created']);
        }
    }
}

// CLEAN STALE COPIED LINKS ON PAGE LOAD
$stmt = $pdo->prepare("SELECT copied_links FROM jpgsvault WHERE id = 1");
$stmt->execute();
$json = $stmt->fetchColumn();
if ($json) {
    $logs = json_decode($json, true);
    if (is_array($logs)) {
        $base = baseUrl();
        $valid = array_filter($logs, function($log) use ($base) {
            $urlNoQuery = preg_replace('/\?v=\d+$/', '', $log['url']);
            $path = parse_url($urlNoQuery, PHP_URL_PATH);
            $local = ltrim(substr($path, strlen(dirname($_SERVER['SCRIPT_NAME']))), '/'); 
            return file_exists($local);
        });
        if (count($valid) < count($logs)) {
            $pdo->prepare("UPDATE jpgsvault SET copied_links = ? WHERE id = 1")
                ->execute([json_encode(array_values($valid))]);
        }
    }
}

// ---------------------------------------------------------------
// AJAX HANDLERS
// ---------------------------------------------------------------

// CREATE FOLDER
if (isset($_POST['action']) && $_POST['action'] === 'create_folder') {
    $folder = trim($_POST['folder_name']);
    $folder = preg_replace('/[^a-zA-Z0-9_]/', '', $folder);
    if ($folder && !columnExists($pdo, $folder)) {
        $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$folder` JSON DEFAULT NULL");
        $uploaded = getPairedUploadedFolder($folder);
        $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$uploaded` JSON DEFAULT NULL");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or existing folder']);
    }
    exit;
}

// RENAME FOLDER
if (isset($_POST['action']) && $_POST['action'] === 'rename_folder') {
    $old = $_POST['old_folder'];
    $new = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['new_name']));
    if (!$old || !$new || $old === $new || !columnExists($pdo, $old) || columnExists($pdo, $new)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or existing name']);
        exit;
    }
    $pdo->exec("ALTER TABLE jpgsvault CHANGE `$old` `$new` JSON DEFAULT NULL");

    $oldUploaded = getPairedUploadedFolder($old);
    $newUploaded = getPairedUploadedFolder($new);
    if (columnExists($pdo, $oldUploaded)) {
        $pdo->exec("ALTER TABLE jpgsvault CHANGE `$oldUploaded` `$newUploaded` JSON DEFAULT NULL");
    }

    $uploadDirOld = "jpgs/$old/";
    $uploadDirNew = "jpgs/$new/";
    if (is_dir($uploadDirOld)) rename($uploadDirOld, $uploadDirNew);

    $uploadDirOldUp = "jpgs/$oldUploaded/";
    $uploadDirNewUp = "jpgs/$newUploaded/";
    if (is_dir($uploadDirOldUp)) rename($uploadDirOldUp, $uploadDirNewUp);

    echo json_encode(['success' => true, 'new_folder' => $new, 'new_name' => formatName($new)]);
    exit;
}

// DELETE FOLDER
if (isset($_POST['action']) && $_POST['action'] === 'delete_folder') {
    $folder = $_POST['folder'];
    if (!columnExists($pdo, $folder)) {
        echo json_encode(['success' => false]);
        exit;
    }
    $images = getImagesInFolder($pdo, $folder);
    foreach ($images as $path) {
        $real = realpath($path);
        if ($real && is_file($real)) @unlink($real);
    }
    purgeCopiedLog($pdo, $images);

    $dir = "jpgs/$folder/";
    if (is_dir($dir)) { array_map('unlink', glob("$dir*.*") ?: []); @rmdir($dir); }
    $pdo->exec("ALTER TABLE jpgsvault DROP COLUMN `$folder`");

    $uploaded = getPairedUploadedFolder($folder);
    if (columnExists($pdo, $uploaded)) {
        $imagesUp = getImagesInFolder($pdo, $uploaded);
        foreach ($imagesUp as $path) {
            $real = realpath($path);
            if ($real && is_file($real)) @unlink($real);
        }
        purgeCopiedLog($pdo, $imagesUp);
        $dirUp = "jpgs/$uploaded/";
        if (is_dir($dirUp)) { array_map('unlink', glob("$dirUp*.*") ?: []); @rmdir($dirUp); }
        $pdo->exec("ALTER TABLE jpgsvault DROP COLUMN `$uploaded`");
    }
    echo json_encode(['success' => true]);
    exit;
}

// BULK UPLOAD
if (isset($_POST['action']) && $_POST['action'] === 'upload_images') {
    $folder = $_POST['folder'];
    if (!columnExists($pdo, $folder)) {
        echo json_encode(['success' => false, 'message' => 'Folder not found']);
        exit;
    }
    $uploadDir = "jpgs/$folder/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $allowed = ['jpg','jpeg','png','gif','webp','bmp','svg'];
    $uploaded = []; $errors = [];
    foreach ($_FILES['images']['name'] as $i => $name) {
        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$name: Upload error";
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            $errors[] = "$name: Invalid type";
            continue;
        }
        $tmp = $_FILES['images']['tmp_name'][$i];
        do {
            $filename = uniqid('img_') . '.' . $ext;
            $path = $uploadDir . $filename;
        } while (file_exists($path));
        if (move_uploaded_file($tmp, $path)) {
            $uploaded[] = $path;
        } else {
            $errors[] = "$name: Save failed";
        }
    }
    if (!empty($uploaded)) {
        $current = getImagesInFolder($pdo, $folder);
        $all = array_merge($current, $uploaded);
        saveImagesToFolder($pdo, $folder, $all);
        syncAllUrls($pdo);
    }
    $baseUrl = baseUrl();
    $results = array_map(fn($p) => ['path'=>$p,'url'=>rtrim($baseUrl, '/').'/'.$p], $uploaded);
    echo json_encode([
        'success' => !empty($uploaded),
        'uploaded' => $results,
        'errors' => $errors
    ]);
    exit;
}

// MOVE TO UPLOADED
if (isset($_POST['action']) && $_POST['action'] === 'move_to_uploaded') {
    $folder = $_POST['folder'];
    $urlList = $_POST['urls'] ?? '';
    $urls = array_filter(array_map('trim', explode(',', $urlList)));
    if (!columnExists($pdo, $folder) || empty($urls)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    $uploadedFolder = getPairedUploadedFolder($folder);
    if (!columnExists($pdo, $uploadedFolder)) {
        echo json_encode(['success' => false, 'message' => 'Uploaded folder missing']);
        exit;
    }

    $images = getImagesInFolder($pdo, $folder);
    $uploadedImages = getImagesInFolder($pdo, $uploadedFolder);
    $base = baseUrl();
    
    $oldDir = "jpgs/$folder/";
    $newDir = "jpgs/$uploadedFolder/";
    ensureDir($newDir);

    $movedPaths = [];
    $removedOriginalPaths = [];
    $failed = [];

    foreach ($urls as $url) {
        $url = trim($url);
        $url = preg_replace('/\?v=\d+$/', '', $url);
        $decodedUrl = urldecode($url);
        $pathFromRoot = parse_url($decodedUrl, PHP_URL_PATH) ?: '';
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if (str_starts_with($pathFromRoot, $basePath)) {
            $normalizedInput = ltrim(substr($pathFromRoot, strlen($basePath)), '/');
        } else {
            $normalizedInput = ltrim($pathFromRoot, '/');
        }
        $inputFilename = basename($normalizedInput);

        $matchedPath = null;
        foreach ($images as $storedPath) {
            $storedNormalized = ltrim($storedPath, '/');
            if (
                strcasecmp($storedNormalized, $normalizedInput) === 0 ||
                strcasecmp(basename($storedPath), $inputFilename) === 0
            ) {
                $matchedPath = $storedPath;
                break;
            }
        }

        if (!$matchedPath) {
            $failed[] = $url;
            continue;
        }

        $oldPath = $matchedPath;
        $filename = basename($oldPath);
        $newPath = $newDir . $filename;

        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            do {
                $filename = $name . '_' . uniqid() . '.' . $ext;
                $newPath = $newDir . $filename;
            } while (file_exists($newPath));
        }

        $realOld = realpath($oldPath);
        if ($realOld && is_file($realOld) && rename($realOld, $newPath)) {
            $movedPaths[] = $newPath;
            $removedOriginalPaths[] = $oldPath;
        } else {
            $failed[] = $oldPath;
        }
    }

    if (empty($movedPaths)) {
        echo json_encode(['success' => false, 'message' => 'No images moved']);
        exit;
    }

    $newUploaded = array_merge($uploadedImages, $movedPaths);
    saveImagesToFolder($pdo, $uploadedFolder, $newUploaded);

    $remaining = array_values(array_diff($images, $removedOriginalPaths));
    saveImagesToFolder($pdo, $folder, $remaining);

    purgeCopiedLog($pdo, $removedOriginalPaths);

    rmdirIfEmpty($oldDir);
    echo json_encode(['success' => true, 'moved' => count($movedPaths), 'failed' => $failed]);
    exit;
}

// MOVE TO ANOTHER FOLDER
if (isset($_POST['action']) && $_POST['action'] === 'move_to_folder') {
    $sourceFolder = $_POST['source_folder'];
    $targetFolder = $_POST['target_folder'];
    $urlList = $_POST['urls'] ?? '';
    $urls = array_filter(array_map('trim', explode(',', $urlList)));
    
    if (!columnExists($pdo, $sourceFolder) || !columnExists($pdo, $targetFolder) || empty($urls) || $sourceFolder === $targetFolder) {
        echo json_encode(['success' => false, 'message' => 'Invalid request or same folder']);
        exit;
    }

    $sourceImages = getImagesInFolder($pdo, $sourceFolder);
    $targetImages = getImagesInFolder($pdo, $targetFolder);
    $base = baseUrl();
    
    $sourceDir = "jpgs/$sourceFolder/";
    $targetDir = "jpgs/$targetFolder/";
    ensureDir($targetDir);

    $movedPaths = [];
    $removedOriginalPaths = [];
    $failed = [];

    foreach ($urls as $url) {
        $url = trim($url);
        $url = trim($url, '"\'');
        $url = preg_replace('/\?v=\d+$/', '', $url);
        $decodedUrl = urldecode($url);
        
        $pathFromRoot = parse_url($decodedUrl, PHP_URL_PATH) ?: '';
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if (str_starts_with($pathFromRoot, $basePath)) {
            $normalizedInput = ltrim(substr($pathFromRoot, strlen($basePath)), '/');
        } else {
            $normalizedInput = ltrim($pathFromRoot, '/');
        }
        $inputFilename = basename($normalizedInput);

        $matchedPath = null;
        foreach ($sourceImages as $storedPath) {
            $storedNormalized = ltrim($storedPath, '/');
            if (
                strcasecmp($storedNormalized, $normalizedInput) === 0 ||
                strcasecmp(basename($storedPath), $inputFilename) === 0
            ) {
                $matchedPath = $storedPath;
                break;
            }
        }

        if (!$matchedPath) {
            $failed[] = $url;
            continue;
        }

        $oldPath = $matchedPath;
        $filename = basename($oldPath);
        $newPath = $targetDir . $filename;

        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            do {
                $filename = $name . '_' . uniqid() . '.' . $ext;
                $newPath = $targetDir . $filename;
            } while (file_exists($newPath));
        }

        $realOld = realpath($oldPath);
        if ($realOld && is_file($realOld) && rename($realOld, $newPath)) {
            $movedPaths[] = $newPath;
            $removedOriginalPaths[] = $oldPath;
        } else {
            $failed[] = $oldPath;
        }
    }

    if (empty($movedPaths)) {
        echo json_encode(['success' => false, 'message' => 'No images moved']);
        exit;
    }

    $newTargetImages = array_merge($targetImages, $movedPaths);
    saveImagesToFolder($pdo, $targetFolder, $newTargetImages);

    $remainingSource = array_values(array_diff($sourceImages, $removedOriginalPaths));
    saveImagesToFolder($pdo, $sourceFolder, $remainingSource);

    purgeCopiedLog($pdo, $removedOriginalPaths);

    rmdirIfEmpty($sourceDir);
    echo json_encode(['success' => true, 'moved' => count($movedPaths), 'failed' => $failed]);
    exit;
}

// MOVE SELECTED IMAGES
if (isset($_POST['action']) && $_POST['action'] === 'move_selected') {
    $sourceFolder = $_POST['source_folder'];
    $targetFolder = $_POST['target_folder'];
    $paths = json_decode($_POST['paths'] ?? '[]', true);
    
    if (!columnExists($pdo, $sourceFolder) || !columnExists($pdo, $targetFolder) || empty($paths) || $sourceFolder === $targetFolder) {
        echo json_encode(['success' => false, 'message' => 'Invalid request or same folder']);
        exit;
    }

    $sourceImages = getImagesInFolder($pdo, $sourceFolder);
    $targetImages = getImagesInFolder($pdo, $targetFolder);
    
    $sourceDir = "jpgs/$sourceFolder/";
    $targetDir = "jpgs/$targetFolder/";
    ensureDir($targetDir);

    $movedPaths = [];
    $removedOriginalPaths = [];
    $failed = [];

    foreach ($paths as $oldPath) {
        if (!in_array($oldPath, $sourceImages)) {
            $failed[] = $oldPath;
            continue;
        }

        $filename = basename($oldPath);
        $newPath = $targetDir . $filename;

        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            do {
                $filename = $name . '_' . uniqid() . '.' . $ext;
                $newPath = $targetDir . $filename;
            } while (file_exists($newPath));
        }

        $realOld = realpath($oldPath);
        if ($realOld && is_file($realOld) && rename($realOld, $newPath)) {
            $movedPaths[] = $newPath;
            $removedOriginalPaths[] = $oldPath;
        } else {
            $failed[] = $oldPath;
        }
    }

    if (empty($movedPaths)) {
        echo json_encode(['success' => false, 'message' => 'No images moved']);
        exit;
    }

    $newTargetImages = array_merge($targetImages, $movedPaths);
    saveImagesToFolder($pdo, $targetFolder, $newTargetImages);

    $remainingSource = array_values(array_diff($sourceImages, $removedOriginalPaths));
    saveImagesToFolder($pdo, $sourceFolder, $remainingSource);

    purgeCopiedLog($pdo, $removedOriginalPaths);

    rmdirIfEmpty($sourceDir);
    echo json_encode(['success' => true, 'moved' => count($movedPaths), 'failed' => $failed]);
    exit;
}

// MOVE BACK FROM UPLOADED
if (isset($_POST['action']) && $_POST['action'] === 'move_back_to_main') {
    $uploadedFolder = $_POST['folder'];
    $urlList = $_POST['urls'] ?? '';
    $urls = array_filter(array_map('trim', explode(',', $urlList)));
    if (!isUploadedFolder($uploadedFolder) || empty($urls)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    $mainFolder = getOriginalFolder($uploadedFolder);
    if (!columnExists($pdo, $mainFolder)) {
        echo json_encode(['success' => false, 'message' => 'Main folder missing']);
        exit;
    }

    $uploadedImages = getImagesInFolder($pdo, $uploadedFolder);
    $mainImages = getImagesInFolder($pdo, $mainFolder);
    $base = baseUrl();
    
    $oldDir = "jpgs/$uploadedFolder/";
    $newDir = "jpgs/$mainFolder/";
    ensureDir($newDir);

    $movedPaths = [];
    $removedOriginalPaths = [];
    $failed = [];

    foreach ($urls as $url) {
        $url = trim($url);
        $url = preg_replace('/\?v=\d+$/', '', $url);
        $decodedUrl = urldecode($url);
        $pathFromRoot = parse_url($decodedUrl, PHP_URL_PATH) ?: '';
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if (str_starts_with($pathFromRoot, $basePath)) {
            $normalizedInput = ltrim(substr($pathFromRoot, strlen($basePath)), '/');
        } else {
            $normalizedInput = ltrim($pathFromRoot, '/');
        }
        $inputFilename = basename($normalizedInput);

        $matchedPath = null;
        foreach ($uploadedImages as $storedPath) {
            $storedNormalized = ltrim($storedPath, '/');
            if (
                strcasecmp($storedNormalized, $normalizedInput) === 0 ||
                strcasecmp(basename($storedPath), $inputFilename) === 0
            ) {
                $matchedPath = $storedPath;
                break;
            }
        }

        if (!$matchedPath) {
            $failed[] = $url;
            continue;
        }

        $oldPath = $matchedPath;
        $filename = basename($oldPath);
        $newPath = $newDir . $filename;

        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            do {
                $filename = $name . '_' . uniqid() . '.' . $ext;
                $newPath = $newDir . $filename;
            } while (file_exists($newPath));
        }

        $realOld = realpath($oldPath);
        if ($realOld && is_file($realOld) && rename($realOld, $newPath)) {
            $movedPaths[] = $newPath;
            $removedOriginalPaths[] = $oldPath;
        } else {
            $failed[] = $oldPath;
        }
    }

    if (empty($movedPaths)) {
        echo json_encode(['success' => false, 'message' => 'No images moved']);
        exit;
    }

    $newMain = array_merge($mainImages, $movedPaths);
    saveImagesToFolder($pdo, $mainFolder, $newMain);

    $remaining = array_values(array_diff($uploadedImages, $removedOriginalPaths));
    saveImagesToFolder($pdo, $uploadedFolder, $remaining);

    purgeCopiedLog($pdo, $removedOriginalPaths);

    rmdirIfEmpty($oldDir);
    echo json_encode(['success' => true, 'moved' => count($movedPaths), 'failed' => $failed]);
    exit;
}

// MOVE ALL JPGS TO UPLOADED
if (isset($_POST['action']) && $_POST['action'] === 'move_all_jpgs') {
    $folder = $_POST['folder'];
    if (!columnExists($pdo, $folder) || isUploadedFolder($folder)) {
        echo json_encode(['success' => false, 'message' => 'Invalid folder']);
        exit;
    }
    $uploadedFolder = getPairedUploadedFolder($folder);
    $oldDir = "jpgs/$folder/";
    $newDir = "jpgs/$uploadedFolder/";
    ensureDir($newDir);

    $images = getImagesInFolder($pdo, $folder);
    $jpgImages = array_filter($images, fn($p) => preg_match('/\.(jpe?g)$/i', $p));

    $moved = [];
    foreach ($jpgImages as $oldPath) {
        $filename = basename($oldPath);
        $newPath = $newDir . $filename;
        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            do {
                $filename = $name . '_' . uniqid() . '.' . $ext;
                $newPath = $newDir . $filename;
            } while (file_exists($newPath));
        }
        if (rename($oldPath, $newPath)) {
            $moved[] = $newPath;
        }
    }

    $uploadedImages = getImagesInFolder($pdo, $uploadedFolder);
    $newUploaded = array_merge($uploadedImages, $moved);
    saveImagesToFolder($pdo, $uploadedFolder, $newUploaded);

    $remaining = array_values(array_diff($images, $jpgImages));
    saveImagesToFolder($pdo, $folder, $remaining);

    purgeCopiedLog($pdo, $jpgImages);

    rmdirIfEmpty($oldDir);
    echo json_encode(['success' => true, 'moved' => count($moved)]);
    exit;
}

// MOVE ALL JPGS BACK
if (isset($_POST['action']) && $_POST['action'] === 'move_all_jpgs_back') {
    $uploadedFolder = $_POST['folder'];
    if (!isUploadedFolder($uploadedFolder)) {
        echo json_encode(['success' => false, 'message' => 'Invalid folder']);
        exit;
    }
    $mainFolder = getOriginalFolder($uploadedFolder);
    $oldDir = "jpgs/$uploadedFolder/";
    $newDir = "jpgs/$mainFolder/";
    ensureDir($newDir);

    $images = getImagesInFolder($pdo, $uploadedFolder);
    $jpgImages = array_filter($images, fn($p) => preg_match('/\.(jpe?g)$/i', $p));

    $moved = [];
    foreach ($jpgImages as $oldPath) {
        $filename = basename($oldPath);
        $newPath = $newDir . $filename;
        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            do {
                $filename = $name . '_' . uniqid() . '.' . $ext;
                $newPath = $newDir . $filename;
            } while (file_exists($newPath));
        }
        if (rename($oldPath, $newPath)) {
            $moved[] = $newPath;
        }
    }

    $mainImages = getImagesInFolder($pdo, $mainFolder);
    $newMain = array_merge($mainImages, $moved);
    saveImagesToFolder($pdo, $mainFolder, $newMain);

    $remaining = array_values(array_diff($images, $jpgImages));
    saveImagesToFolder($pdo, $uploadedFolder, $remaining);

    purgeCopiedLog($pdo, $jpgImages);

    rmdirIfEmpty($oldDir);
    echo json_encode(['success' => true, 'moved' => count($moved)]);
    exit;
}

// DELETE IMAGES
if (isset($_POST['action']) && $_POST['action'] === 'delete_images') {
    $folder = $_POST['folder'] ?? '';
    $paths = json_decode($_POST['paths'] ?? '[]', true);
    if (!columnExists($pdo, $folder) || !is_array($paths) || empty($paths)) {
        echo json_encode(['success'=>false]);
        exit;
    }
    $current = getImagesInFolder($pdo, $folder);
    $remaining = array_values(array_diff($current, $paths));
    saveImagesToFolder($pdo, $folder, $remaining);
    foreach ($paths as $p) {
        $real = realpath($p);
        if ($real && is_file($real)) @unlink($real);
    }
    purgeCopiedLog($pdo, $paths);
    $dir = "jpgs/$folder/";
    rmdirIfEmpty($dir);
    syncAllUrls($pdo);
    echo json_encode(['success'=>true]);
    exit;
}

// GET IMAGES
if (isset($_GET['action']) && $_GET['action'] === 'get_images') {
    $folder = $_GET['folder'];
    if (!columnExists($pdo, $folder)) {
        echo json_encode([]);
        exit;
    }
    $images = getImagesInFolder($pdo, $folder);
    $valid = array_filter($images, function($p) {
        $real = realpath($p);
        return $real && is_file($real);
    });
    if (count($valid) < count($images)) {
        saveImagesToFolder($pdo, $folder, array_values($valid));
    }
    $baseUrl = baseUrl();
    $withUrl = array_map(function($p) use ($baseUrl) {
        $url = rtrim($baseUrl, '/') . '/' . $p;
        $real = realpath($p);
        $timestamp = ($real && is_file($real)) ? filemtime($real) : time();
        return ['path' => $p, 'url' => $url . '?v=' . $timestamp];
    }, array_values($valid));
    echo json_encode($withUrl);
    exit;
}

// GET FOLDER LIST
if (isset($_GET['action']) && $_GET['action'] === 'get_folders') {
    $folders = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && $field !== 'copied_links' && $field !== 'server_passkey' && $field !== 'uploadedjpgs' && !isUploadedFolder($field)) {
            $images = getImagesInFolder($pdo, $field);
            $count = count(array_filter($images, function($p) {
                return file_exists($p);
            }));
            $folders[] = [
                'name' => formatName($field),
                'folder' => $field,
                'count' => $count,
                'first_letter' => substr(formatName($field), 0, 1) . substr(formatName($field), -1, 1)
            ];
        }
    }
    echo json_encode($folders);
    exit;
}

// LOG COPIED LINK (single)
if (isset($_POST['action']) && $_POST['action'] === 'log_copy') {
    $url = trim($_POST['url'] ?? '');
    $folder = $_POST['folder'] ?? 'unknown';
    if (!$url) {
        echo json_encode(['success' => false]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT copied_links FROM jpgsvault WHERE id = 1");
    $stmt->execute();
    $json = $stmt->fetchColumn();
    $logs = $json ? json_decode($json, true) : [];

    $logs = array_filter($logs, fn($log) => $log['url'] !== $url);
    $logs[] = ['url' => $url, 'folder' => $folder, 'timestamp' => date('c')];
    if (count($logs) > 200) $logs = array_slice($logs, -200);

    $pdo->prepare("UPDATE jpgsvault SET copied_links = ? WHERE id = 1")
        ->execute([json_encode(array_values($logs))]);

    echo json_encode(['success' => true]);
    exit;
}

// LOG COPIED LINKS – BULK
if (isset($_POST['action']) && $_POST['action'] === 'log_copy_bulk') {
    $folder = $_POST['folder'] ?? 'unknown';
    $urls   = json_decode($_POST['urls'] ?? '[]', true);
    if (!is_array($urls) || empty($urls)) {
        echo json_encode(['success' => false]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT copied_links FROM jpgsvault WHERE id = 1");
    $stmt->execute();
    $json = $stmt->fetchColumn();
    $logs = $json ? json_decode($json, true) : [];

    $existingUrls = array_column($logs, 'url');
    $newEntries = [];

    foreach ($urls as $url) {
        $url = trim($url);
        if (!in_array($url, $existingUrls)) {
            $newEntries[] = ['url' => $url, 'folder' => $folder, 'timestamp' => date('c')];
            $existingUrls[] = $url;
        } else {
            foreach ($logs as &$log) {
                if ($log['url'] === $url) {
                    $log['timestamp'] = date('c');
                    $log['folder'] = $folder;
                    break;
                }
            }
            unset($log);
        }
    }

    $logs = array_merge($logs, $newEntries);
    if (count($logs) > 200) $logs = array_slice($logs, -200);

    $pdo->prepare("UPDATE jpgsvault SET copied_links = ? WHERE id = 1")
        ->execute([json_encode(array_values($logs))]);

    echo json_encode(['success' => true]);
    exit;
}

// GET COPIED LOG
if (isset($_GET['action']) && $_GET['action'] === 'get_copied_log') {
    $stmt = $pdo->query("SELECT copied_links FROM jpgsvault WHERE id = 1");
    $json = $stmt->fetchColumn();
    $logs = $json ? json_decode($json, true) : [];
    usort($logs, fn($a,$b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
    echo json_encode($logs);
    exit;
}

// CHECK AUTH (for AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'check_auth') {
    $authenticated = isset($_SESSION['jpgsvault_authenticated']) && $_SESSION['jpgsvault_authenticated'] === true;
    
    if ($authenticated && isset($_SESSION['jpgsvault_last_activity'])) {
        if (time() - $_SESSION['jpgsvault_last_activity'] > 1800) {
            session_destroy();
            $authenticated = false;
        } else {
            $_SESSION['jpgsvault_last_activity'] = time();
        }
    }
    
    echo json_encode(['authenticated' => $authenticated]);
    exit;
}

// LOGOUT
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// ---------------------------------------------------------------
// LIST FOLDERS FOR INITIAL PAGE LOAD
// ---------------------------------------------------------------
$mainFolders = [];
$uploadedFolders = [];
$stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($col['Field'] !== 'id' && $col['Field'] !== 'copied_links' && $col['Field'] !== 'server_passkey' && $col['Field'] !== 'uploadedjpgs') {
        if (isUploadedFolder($col['Field'])) {
            $orig = getOriginalFolder($col['Field']);
            if (columnExists($pdo, $orig)) {
                $uploadedFolders[] = ['name' => formatName($orig) . ' (Uploaded)', 'folder' => $col['Field'], 'original' => $orig];
            }
        } else {
            $mainFolders[] = ['name'=>formatName($col['Field']), 'folder'=>$col['Field']];
        }
    }
}
$folders = array_merge($mainFolders, $uploadedFolders);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JPGS Vault - Image Gallery</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --primary: #6366f1; --primary-dark: #4f46e5; --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
    --bg: #f8fafc; --card: #ffffff; --text: #1e293b; --text-light: #64748b; --border: #e2e8f0;
    --shadow: 0 10px 25px rgba(0,0,0,0.08); --radius: 16px; --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  *{margin:0;padding:0;box-sizing:border-box}
  html, body {height:100%;margin:0;overflow:hidden;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text)}
  .contentsdiv {height:100vh;overflow:hidden;display:flex;flex-direction:column}
  .contentsinnerdiv {flex:1;overflow-y:auto;padding-bottom:3rem}
  header{text-align:center;padding:2.5rem 1rem 1.5rem;background:linear-gradient(135deg,var(--primary),#8b5cf6);color:#fff;border-bottom-left-radius:var(--radius);border-bottom-right-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:-1rem;position:relative;overflow:hidden;flex-shrink:0}
  header::after{content:'';position:absolute;bottom:0;left:0;right:0;height:50px;background:linear-gradient(transparent,var(--bg))}
  header h1{font-size:2.8rem;font-weight:700;margin-bottom:.5rem;text-shadow:0 2px 10px rgba(0,0,0,.2)}
  header .subtitle{font-size:1rem;opacity:.9;font-weight:500}
  .controls{background:var(--card);padding:1.5rem 1rem;border-radius:var(--radius);margin:1.5rem 1rem 0;max-width:1100px;box-shadow:var(--shadow);display:flex;flex-direction:column;align-items:center;gap:1.2rem;position:sticky;top:1rem;z-index:10;backdrop-filter:blur(10px);border:1px solid var(--border);flex-shrink:0}
  .top-controls{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:center;width:100%}
  .active-folder-btn{padding:.75rem 1.8rem;font-size:1rem;font-weight:600;color:white;background:var(--primary);border:none;border-radius:50px;cursor:default;box-shadow:0 4px 15px rgba(99,102,241,.3);min-width:180px}
  .folder-dropdown{position:relative;display:inline-block}
  .folder-toggle{padding:.75rem 1.5rem;font-size:.95rem;font-weight:600;color:var(--text-light);background:#f1f5f9;border:none;border-radius:50px;cursor:pointer;transition:var(--transition);box-shadow:0 2px 6px rgba(0,0,0,.05);min-width:140px}
  .folder-toggle:hover{background:#e2e8f0}
  .folder-menu{
    position:absolute;
    top:100%;
    left:50%;
    transform:translateX(-50%);
    margin-top:.5rem;
    background:#fff;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,.15);
    overflow:hidden;
    min-width:220px;
    max-height:60vh;
    display:none;
    flex-direction:column;
    z-index:20;
    animation:fadeIn .2s ease;
  }
  .folder-menu.show{display:flex}
  .folder-menu-content{
    overflow-y:auto;
    flex:1;
  }
  .folder-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:.75rem 1rem;
    border:none;
    background:none;
    font-size:.95rem;
    color:var(--text);
    cursor:pointer;
    transition:var(--transition);
    width:100%;
    text-align:left;
    flex-shrink:0;
  }
  .folder-item:hover{background:#f8fafc}
  .folder-item .folder-name{
    flex:1;
    overflow:hidden;
    word-wrap: break-word;
    white-space: normal;
    line-height: 1.3;
    padding-right: 8px;
  }
  .folder-item .folder-actions{display:flex;gap:.4rem;flex-shrink:0}
  .folder-actions button{background:none;border:none;cursor:pointer;font-size:1rem;padding:2px 4px;border-radius:4px;transition:background .2s}
  .folder-actions .edit-btn{color:#f59e0b}
  .folder-actions .edit-btn:hover{background:#fffbe6}
  .folder-actions .delete-btn{color:#ef4444}
  .folder-actions .delete-btn:hover{background:#fee2e2}
  .folder-menu .create-folder-item{border-top:1px solid #eee;color:var(--primary);font-weight:600;background:#f0f9ff;justify-content:center}
  .folder-menu .create-folder-item:hover{background:#e0f2fe}
  .folder-menu .uploaded-section{border-top:2px solid #ddd;padding-top:.5rem;margin-top:.5rem;font-weight:600;color:#555;pointer-events:none}
  .search-wrapper{position:relative;flex:1;max-width:360px}
  .search-input{width:100%;padding:.75rem 1rem .75rem 2.5rem;border:1px solid var(--border);border-radius:50px;font-size:.95rem;background:#fff;transition:var(--transition)}
  .search-input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.2)}
  .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none}
  .selection-controls{display:none;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:center;font-size:.95rem;color:var(--text-light)}
  .selection-controls label{display:flex;align-items:center;gap:.5rem;cursor:pointer}
  .selection-controls input[type=checkbox]{width:18px;height:18px;accent-color:var(--primary);cursor:pointer}
  .delete-selected-btn{background:var(--danger);color:white;padding:.6rem 1.2rem;font-size:.9rem;border:none;border-radius:50px;cursor:pointer;font-weight:600;display:none;transition:var(--transition)}
  .delete-selected-btn:hover{background:#dc2626;transform:translateY(-1px)}
  .move-selected-uploaded-btn{background:var(--success);color:white;padding:.6rem 1.2rem;font-size:.9rem;border:none;border-radius:50px;cursor:pointer;font-weight:600;display:none;transition:var(--transition)}
  .move-selected-uploaded-btn:hover{background:#0d8b5f;transform:translateY(-1px)}
  .move-selected-folder-btn{background:#8b5cf6;color:white;padding:.6rem 1.2rem;font-size:.9rem;border:none;border-radius:50px;cursor:pointer;font-weight:600;display:none;transition:var(--transition)}
  .move-selected-folder-btn:hover{background:#7c3aed;transform:translateY(-1px)}
  .gallery{padding:1rem 1.5rem;max-width:1400px;margin:auto;flex:1}
  .gallery-title{text-align:center;margin-bottom:1rem;font-size:1.5rem;color:#444}
  .images-container{background:#fff;border-radius:8px;padding:1rem;position:relative}
  
  .image-scroll{
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap:1.5rem;
    padding:1rem 0;
    align-items:start;
  }
  
  .image-item{
    display:flex;
    flex-direction:column;
    align-items:center;
    text-align:center;
    position:relative;
    background:#fafafa;
    border-radius:10px;
    padding:0.75rem;
    box-shadow:0 2px 8px rgba(0,0,0,0.06);
    transition:var(--transition);
    min-height:280px;
    width:100%;
    overflow:hidden;
  }
  
  .image-item:hover{
    box-shadow:0 4px 16px rgba(0,0,0,0.12);
  }
  
  .image-item .image-wrapper{
    width:100%;
    height:220px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:#f0f0f0;
    border-radius:8px;
    position:relative;
  }
  
  .image-item img{
    max-width:100%;
    max-height:100%;
    width:auto;
    height:auto;
    object-fit:contain;
    border-radius:6px;
    cursor:pointer;
    transition:transform 0.2s;
  }
  
  .image-item img:hover{
    transform:scale(1.02);
  }
  
  .image-item p{
    margin-top:0.5rem;
    font-size:0.85rem;
    color:#555;
    font-weight:500;
    padding:0 4px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:100%;
    width:100%;
  }
  
  .image-item .checkbox{
    position:absolute;
    top:12px;
    left:12px;
    width:22px;
    height:22px;
    background:rgba(255,255,255,0.95);
    border:2px solid #ccc;
    border-radius:4px;
    opacity:0;
    transition:opacity 0.2s;
    cursor:pointer;
    z-index:10;
    display:flex;
    align-items:center;
    justify-content:center;
  }
  
  .image-item .checkbox::after{
    content:'✓';
    font-size:14px;
    color:white;
    opacity:0;
    transition:opacity 0.2s;
  }
  
  .image-item.selected .checkbox{
    opacity:1;
    border-color:var(--primary);
    background:var(--primary);
  }
  
  .image-item.selected .checkbox::after{
    opacity:1;
  }
  
  .image-item.selected img{
    outline:3px solid var(--primary);
    outline-offset:-3px;
    border-radius:6px;
  }
  
  .empty-state,.loading,.no-results{text-align:center;padding:3rem;color:#888;font-style:italic}
  .add-btn-container{text-align:center;padding:2rem}
  .add-btn-container button{padding:1rem 2rem;font-size:1.1rem;background:#007bff;color:white;border:none;border-radius:50px;cursor:pointer}
  .add-btn-container button:hover{background:#0056b3}
  .move-to-uploaded-btn,.move-to-another-folder-btn,.move-all-jpgs-btn,.move-back-to-main-btn,.move-all-jpgs-back-btn{margin-top:1rem;padding:.8rem 1.8rem;color:white;border:none;border-radius:50px;font-weight:600;cursor:pointer;width:240px;font-size:.95rem}
  .move-to-uploaded-btn{background:#10b981}
  .move-to-uploaded-btn:hover{background:#0d8b5f}
  .move-to-another-folder-btn{background:#8b5cf6}
  .move-to-another-folder-btn:hover{background:#7c3aed}
  .move-all-jpgs-btn{background:#f59e0b}
  .move-all-jpgs-btn:hover{background:#e68a00}
  .move-back-to-main-btn{background:#3b82f6}
  .move-back-to-main-btn:hover{background:#2563eb}
  .move-all-jpgs-back-btn{background:#8b5cf6}
  .move-all-jpgs-back-btn:hover{background:#7c3aed}
  
  .home-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 2rem;
    padding: 2rem;
    max-width: 1200px;
    margin: 0 auto;
  }
  .folder-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    text-align: center;
    padding: 1rem;
  }
  .folder-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
  }
  .folder-cover {
    width: 120px;
    height: 120px;
    margin: 0 auto 1rem;
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
    font-weight: 600;
    text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(99,102,241,0.3);
  }
  .folder-info {
    text-align: center;
  }
  .folder-name {
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--text);
    margin-bottom: 0.25rem;
    word-wrap: break-word;
    white-space: normal;
    line-height: 1.3;
    max-width: 100%;
    padding: 0 5px;
  }
  .folder-count {
    font-size: 0.9rem;
    color: var(--text-light);
  }
  .no-previews {
    text-align: center;
    padding: 3rem;
    color: #888;
    font-style: italic;
    grid-column: 1 / -1;
  }
  
  .logout-btn {
    margin-left: 1rem;
    padding: .6rem 1.2rem;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.3s;
  }
  .logout-btn:hover {
    background: #c82333;
  }
  
  .fab{position:fixed;bottom:2rem;right:2rem;background:var(--primary);width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.3);z-index:50;transition:transform .2s}
  .fab:hover{transform:scale(1.1)}
  .modal,.fullscreen-modal,.bulk-upload-overlay,.confirm-modal,.rename-modal,.delete-folder-modal,.move-modal,.move-all-modal,.move-back-modal,.move-all-back-modal,.move-to-folder-modal,.history-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);justify-content:center;align-items:center;z-index:100;padding:1rem}
  .modal-content,.confirm-box,.bulk-upload-modal,.rename-box,.delete-folder-box,.move-box,.move-all-box,.move-back-box,.move-all-back-box,.move-to-folder-box,.history-modal .modal-content{background:#fff;padding:2rem;border-radius:12px;width:90%;max-width:420px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.2);animation:modalPop .3s ease}
  
  .auto-move-modal .modal-content {
    max-width: 500px;
    border-left: 5px solid var(--success);
  }
  .auto-move-modal .modal-content .icon {
    font-size: 3rem;
    margin-bottom: 1rem;
  }
  .auto-move-modal .modal-content .summary {
    text-align: left;
    margin: 1rem 0;
    padding: 0.5rem;
    background: #f8fafc;
    border-radius: 8px;
  }
  .auto-move-modal .modal-content .summary-item {
    padding: 0.3rem 0;
    font-size: 0.95rem;
    display: flex;
    justify-content: space-between;
  }
  .auto-move-modal .modal-content .summary-item .label {
    color: var(--text-light);
  }
  .auto-move-modal .modal-content .summary-item .value {
    font-weight: 600;
  }
  .auto-move-modal .modal-content .failed-urls {
    max-height: 150px;
    overflow-y: auto;
    text-align: left;
    font-size: 0.85rem;
    color: var(--danger);
    background: #fee2e2;
    padding: 0.5rem;
    border-radius: 6px;
    margin: 0.5rem 0;
  }
  
  .history-modal .modal-content {
    max-width: 600px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    padding: 0;
    overflow: hidden;
  }
  .history-modal .modal-header {
    padding: 1.5rem 2rem 1rem;
    border-bottom: 1px solid #eee;
    text-align: center;
  }
  .history-modal .history-list-container {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 2rem;
    max-height: calc(85vh - 140px);
  }
  .history-modal .modal-footer {
    padding: 1rem 2rem;
    border-top: 1px solid #eee;
    background: #fafafa;
    text-align: center;
  }
  .history-modal .modal-footer button {
    background: #dc3545;
    color: white;
    padding: .6rem 1.8rem;
    border: none;
    border-radius: 50px;
    font-weight: 600;
    cursor: pointer;
  }
  .history-item {
    padding: .75rem 0;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    font-size: .9rem;
  }
  .history-item:last-child {
    border-bottom: none;
  }
  .history-item .info {
    flex: 1;
    min-width: 0;
  }
  .history-item .folder-name {
    font-weight: 600;
    color: var(--primary);
    margin-bottom: .2rem;
  }
  .history-item .url {
    color: #0066cc;
    word-break: break-all;
    font-family: monospace;
    font-size: .85rem;
    line-height: 1.4;
  }
  .history-item .copy-again {
    background: #17a2b8;
    color: white;
    border: none;
    padding: .4rem .8rem;
    border-radius: 8px;
    font-size: .8rem;
    cursor: pointer;
    white-space: nowrap;
    align-self: center;
  }
  .history-item .copy-again:hover {
    background: #138496;
  }

  .folder-select {
    width: 100%;
    padding: .8rem;
    margin: 1rem 0;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
  }

  @keyframes modalPop{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}
  .modal input[type=text], .rename-box input[type=text], .move-box textarea, .move-back-box textarea, .move-to-folder-box textarea{width:100%;padding:.8rem;margin:1rem 0;border:1px solid #ddd;border-radius:8px;font-size:1rem}
  .modal button,.confirm-box button,.bulk-modal-actions button,.rename-box button,.delete-folder-box button,.move-box button,.move-all-box button,.move-back-box button,.move-all-back-box button,.move-to-folder-box button,.history-modal button{padding:.6rem 1.2rem;margin:.3rem;border:none;border-radius:50px;cursor:pointer;font-weight:600}
  .modal button:first-of-type,.confirm-yes,.rename-yes,.delete-folder-yes,.move-yes,.move-all-yes,.move-back-yes,.move-all-back-yes,.move-to-folder-yes{background:#dc3545;color:#fff}
  .modal button:last-of-type,.confirm-no,.close-bulk,.rename-no,.delete-folder-no,.move-no,.move-all-no,.move-back-no,.move-all-back-no,.move-to-folder-no{background:#ccc;color:#333}
  .fullscreen-modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.95);z-index:200;flex-direction:column;justify-content:center;align-items:center;color:#fff}
  .fullscreen-img{max-width:95%;max-height:80vh;border-radius:8px;box-shadow:0 0 30px rgba(0,0,0,.8)}
  .fullscreen-actions{margin-top:1.5rem;display:flex;gap:1.2rem;flex-wrap:wrap;justify-content:center}
  .fullscreen-actions button{min-width:180px;padding:.8rem 1.6rem;font-size:1rem;border:none;border-radius:50px;cursor:pointer;font-weight:600;transition:background .3s}
  .copy-btn{background:#17a2b8;color:white}
  .copy-btn:hover{background:#138496}
  .close-fullscreen{background:#dc3545;color:white}
  .close-fullscreen:hover{background:#c82333}
  .copied-notif{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#28a745;color:white;padding:.8rem 1.6rem;border-radius:50px;font-weight:600;z-index:300;opacity:0;pointer-events:none;transition:opacity .3s}
  .copied-notif.show{opacity:1}
  .bulk-upload-modal{max-width:800px;max-height:90vh;overflow-y:auto}
  .bulk-upload-area{padding:2rem;background:#f8f9fa;border:2px dashed #ccc;border-radius:12px;text-align:center;position:relative}
  .bulk-upload-area.dragover{background:#e3f2fd;border-color:#6e8efb}
  .bulk-file-count{font-weight:600;margin:1rem 0;color:#444}
  .bulk-preview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:.5rem;max-height:300px;overflow-y:auto;padding:1rem;background:#fff;border-radius:8px}
  .bulk-preview-item{position:relative}
  .bulk-preview-item img{width:100%;height:70px;object-fit:cover;border-radius:6px}
  .bulk-remove{position:absolute;top:2px;right:2px;background:#dc3545;color:white;width:18px;height:18px;border-radius:50%;font-size:10px;line-height:18px;cursor:pointer}
  .bulk-progress{margin:1rem 0;display:none}
  .bulk-progress-bar{height:8px;background:#eee;border-radius:4px;overflow:hidden}
  .bulk-progress-fill{height:100%;background:#28a745;width:0;transition:width .3s}
  .bulk-modal-actions{margin-top:1.5rem;display:flex;gap:1rem;justify-content:center}
  @keyframes fadeIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
  .history-btn{margin-left:1rem;padding:.6rem 1.2rem;background:#8b5cf6;color:white;border:none;border-radius:50px;font-weight:600;cursor:pointer}
  
  .auto-move-btn {
    background: var(--success);
    color: white;
    padding: .6rem 1.2rem;
    border: none;
    border-radius: 50px;
    font-weight: 600;
    cursor: pointer;
    margin-left: 0.5rem;
  }
  .auto-move-btn:hover {
    background: #0d8b5f;
  }
  
  @media(max-width:768px){
    .image-scroll{
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap:1rem;
    }
    .image-item{
      min-height:220px;
    }
    .image-item .image-wrapper{
      height:160px;
    }
    .fab{bottom:1rem;right:1rem;width:48px;height:48px;font-size:1.5rem}
    .fullscreen-actions{flex-direction:column;gap:1rem}
    .fullscreen-actions button{min-width:160px}
    .active-folder-btn{font-size:.9rem;padding:.6rem 1.2rem;min-width:140px}
    .folder-menu{
      position:fixed;
      left:50%!important;
      transform:translateX(-50%);
      width:90vw;
      max-width:280px;
      min-width:200px;
      max-height:70vh;
    }
    .top-controls{flex-direction:column}
    .search-wrapper{max-width:none}
    .move-to-uploaded-btn,.move-to-another-folder-btn,.move-all-jpgs-btn,.move-back-to-main-btn,.move-all-jpgs-back-btn{width:100%;max-width:240px}
    .history-modal .modal-content {max-width: 95vw;}
    .history-item {flex-direction: column; gap: .5rem;}
    .history-item .copy-again {align-self: flex-end;}
    .home-grid {grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 1rem; padding: 1rem;}
    .folder-cover {width: 80px; height: 80px; font-size: 2rem;}
    .folder-name {font-size: 0.9rem;}
    .logout-btn {margin-left: 0; margin-top: 0.5rem;}
    .auto-move-btn {margin-left: 0; margin-top: 0.5rem;}
  }
  
  @media(max-width:480px){
    .image-scroll{
      grid-template-columns: 1fr;
      gap:1rem;
    }
    .image-item{
      min-height:260px;
    }
    .image-item .image-wrapper{
      height:200px;
    }
  }
  /* Back Button Styles */
    #backBtn {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    padding: 8px 18px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
    backdrop-filter: blur(5px);
    display: none;
    white-space: nowrap;
    }

    #backBtn:hover {
    background: rgba(255,255,255,0.35);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .go-dashboard-btn {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    padding: 8px 18px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
    backdrop-filter: blur(5px);
    white-space: nowrap;
    }

    .go-dashboard-btn:hover {
    background: rgba(255,255,255,0.35);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    @media(max-width:768px){
    #backBtn, .go-dashboard-btn {
        padding: 6px 12px;
        font-size: 12px;
    }
    header h1 {
        font-size: 1.8rem !important;
    }
    header .subtitle {
        font-size: 0.8rem !important;
    }
    }
</style>
</head>
<body>
<div class="contentsdiv">
  <div class="contentsinnerdiv">
    <header>
    <div style="display:flex; align-items:center; justify-content:space-between; max-width:1400px; margin:0 auto; padding:0 1rem;">
        <div style="display:flex; align-items:center; gap:15px;">
        <button id="backBtn" onclick="goBack()" style="background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); color:white; padding:8px 18px; border-radius:10px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.3s; backdrop-filter:blur(5px); display:none;">
            ← Back
        </button>
        <div style="text-align:left;">
            <h1 style="font-size:2.8rem; font-weight:700; text-shadow:0 2px 10px rgba(0,0,0,.2); margin-bottom:.5rem;">JPGS Vault</h1>
            <p class="subtitle" style="font-size:1rem; opacity:.9; font-weight:500;">JPG • PNG • GIF • WEBP • BMP • SVG</p>
        </div>
        </div>
    </div>
    </header>

    <div class="controls">
      <div class="top-controls">
        <button class="active-folder-btn" id="active-folder-btn">All Folders</button>
        <div class="folder-dropdown">
          <button class="folder-toggle" id="folder-toggle">FOLDERS ▼</button>
          <div class="folder-menu" id="folder-menu"></div>
        </div>
        <button class="history-btn" id="history-btn">Copied History</button>
        <button class="logout-btn" id="logout-btn">Logout</button>
        <div class="search-wrapper" id="search-wrapper" style="display:none">
          <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input type="text" class="search-input" id="search-input" placeholder="Search by URL or filename...">
        </div>
      </div>
      <div class="selection-controls" id="selection-controls">
        <label><input type="checkbox" id="select-all-checkbox"> Select All</label>
        <button class="delete-selected-btn" id="delete-selected">Delete Selected (<span id="selected-count">0</span>)</button>
        <button class="move-selected-uploaded-btn" id="move-selected-uploaded">Move Selected to Uploaded (<span id="selected-count-uploaded">0</span>)</button>
        <button class="move-selected-folder-btn" id="move-selected-folder">Move Selected to Folder (<span id="selected-count-folder">0</span>)</button>
      </div>
    </div>

    <section class="gallery">
      <h2 class="gallery-title" id="gallery-title">All Folders</h2>
      <div class="images-container" id="images-container">
        <div class="loading">Loading folders...</div>
      </div>
      <div class="fab" id="fab-add">+</div>
    </section>

    <!-- Auto-Move Notification Modal -->
    <div class="modal auto-move-modal" id="auto-move-modal">
      <div class="modal-content">
        <div class="icon">📦</div>
        <h3 id="auto-move-title">Images Auto-Moved Successfully!</h3>
        <p id="auto-move-message"></p>
        <div class="summary" id="auto-move-summary"></div>
        <button onclick="document.getElementById('auto-move-modal').style.display='none'" style="background:var(--primary);color:white;padding:.6rem 1.8rem;border:none;border-radius:50px;cursor:pointer;font-weight:600;">OK, Got It!</button>
        <button onclick="document.getElementById('auto-move-modal').style.display='none'; location.reload();" style="background:var(--success);color:white;padding:.6rem 1.8rem;border:none;border-radius:50px;cursor:pointer;font-weight:600;margin-top:0.5rem;">Refresh Gallery</button>
      </div>
    </div>

    <!-- Create Folder Modal -->
    <div class="modal" id="folder-modal">
      <div class="modal-content">
        <h3>Create New Folder</h3>
        <input type="text" id="folder-name" placeholder="e.g. Vacation 2025" maxlength="50">
        <div><button id="confirm-create">Create</button><button id="cancel-create">Cancel</button></div>
      </div>
    </div>

    <!-- Rename Folder Modal -->
    <div class="rename-modal" id="rename-modal">
      <div class="rename-box">
        <h3>Rename Folder</h3>
        <input type="text" id="rename-input" placeholder="New name" maxlength="50">
        <div><button id="rename-yes">Rename</button><button id="rename-no">Cancel</button></div>
      </div>
    </div>

    <!-- Delete Folder Modal -->
    <div class="delete-folder-modal" id="delete-folder-modal">
      <div class="delete-folder-box">
        <p>Delete folder "<span id="delete-folder-name"></span>" and <strong>all its images</strong>?</p>
        <div><button id="delete-folder-yes">Delete</button><button id="delete-folder-no">Cancel</button></div>
      </div>
    </div>

    <!-- Move to Uploaded Modal -->
    <div class="move-modal" id="move-modal">
      <div class="move-box">
        <h3>Move Images to Uploaded</h3>
        <p>Enter image URLs (comma-separated, with or without quotes):</p>
        <textarea id="move-indices" placeholder='"url1", "url2", "url3" or url1, url2, url3' rows="3"></textarea>
        <div><button id="move-yes">Move</button><button id="move-no">Cancel</button></div>
      </div>
    </div>

    <!-- Move to Another Folder Modal -->
    <div class="move-to-folder-modal" id="move-to-folder-modal">
      <div class="move-to-folder-box">
        <h3>Move Images to Another Folder</h3>
        <p>Select destination folder:</p>
        <select class="folder-select" id="target-folder-select"></select>
        <p>Enter image URLs (comma-separated, with or without quotes):</p>
        <textarea id="move-to-folder-indices" placeholder='"url1", "url2", "url3" or url1, url2, url3' rows="3"></textarea>
        <div><button id="move-to-folder-yes">Move</button><button id="move-to-folder-no">Cancel</button></div>
      </div>
    </div>

    <!-- Move Selected to Folder Modal -->
    <div class="move-to-folder-modal" id="move-selected-folder-modal">
      <div class="move-to-folder-box">
        <h3>Move Selected Images to Folder</h3>
        <p>Select destination folder:</p>
        <select class="folder-select" id="move-selected-target-select"></select>
        <div><button id="move-selected-folder-yes">Move Selected</button><button id="move-selected-folder-no">Cancel</button></div>
      </div>
    </div>

    <!-- Move All JPGs Modal -->
    <div class="move-all-modal" id="move-all-modal">
      <div class="move-all-box">
        <p>Move <strong>all JPG/JPEG images</strong> to "<span id="move-all-target"></span>"?</p>
        <div><button id="move-all-yes">Move All</button><button id="move-all-no">Cancel</button></div>
      </div>
    </div>

    <!-- Move Back Modal -->
    <div class="move-back-modal" id="move-back-modal">
      <div class="move-back-box">
        <h3>Move Back to Main</h3>
        <p>Enter image URLs (comma-separated, with or without quotes):</p>
        <textarea id="move-back-indices" placeholder='"url1", "url2", "url3"' rows="3"></textarea>
        <div><button id="move-back-yes">Move Back</button><button id="move-back-no">Cancel</button></div>
      </div>
    </div>

    <!-- Move All JPGs Back Modal -->
    <div class="move-all-back-modal" id="move-all-back-modal">
      <div class="move-all-back-box">
        <p>Move <strong>all JPG/JPEG images</strong> back to "<span id="move-all-back-target"></span>"?</p>
        <div><button id="move-all-back-yes">Move All Back</button><button id="move-all-back-no">Cancel</button></div>
      </div>
    </div>

    <!-- Fullscreen Modal -->
    <div class="fullscreen-modal" id="fullscreen-modal">
      <img src="" alt="Full" class="fullscreen-img" id="fullscreen-img">
      <div class="fullscreen-actions">
        <button class="copy-btn" id="copy-link-btn">Copy Image Link</button>
        <button class="close-fullscreen" id="close-fullscreen">Close</button>
      </div>
    </div>

    <!-- Copied Notification -->
    <div class="copied-notif" id="copied-notif">Link copied & saved!</div>

    <!-- Bulk Upload Overlay -->
    <div class="bulk-upload-overlay" id="bulk-upload-overlay">
      <div class="bulk-upload-modal">
        <h3 style="margin-bottom:1rem;text-align:center">Add Images to <span id="bulk-folder-name"></span></h3>
        <div class="bulk-upload-area" id="bulk-upload-area">
          <p>Drop images or click to select (100+ supported)</p>
          <input type="file" id="bulk-input" accept="image/*" multiple style="display:none">
          <button id="bulk-choose">Choose Files</button>
          <div class="bulk-file-count" id="bulk-count">0 images selected</div>
          <div class="bulk-preview-grid" id="bulk-preview"></div>
          <div class="bulk-progress" id="bulk-progress">
            <div class="bulk-progress-bar"><div class="bulk-progress-fill" id="bulk-fill"></div></div>
            <p id="bulk-text" style="margin-top:.5rem;font-size:.9rem;color:#555"></p>
          </div>
          <div class="bulk-modal-actions">
            <button class="save-btn" id="bulk-save">Upload All</button>
            <button class="close-bulk" id="bulk-cancel">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div class="confirm-modal" id="confirm-modal">
      <div class="confirm-box">
        <p>Delete <span id="confirm-count">0</span> image(s)?</p>
        <button id="confirm-yes">Delete</button>
        <button id="confirm-no">Cancel</button>
      </div>
    </div>

    <!-- History Modal -->
    <div class="modal history-modal" id="history-modal">
      <div class="modal-content">
        <div class="modal-header">
          <h3>Copied Links History</h3>
        </div>
        <div class="history-list-container" id="history-list"></div>
        <div class="modal-footer">
          <button onclick="document.getElementById('history-modal').style.display='none'">Close</button>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
  const folders = <?= json_encode($folders) ?>;
  const mainFolders = <?= json_encode($mainFolders) ?>;
  const autoMoveResult = <?= json_encode($autoMoveResult) ?>;
  
  const activeBtn = document.getElementById('active-folder-btn');
  const folderToggle = document.getElementById('folder-toggle');
  const folderMenu = document.getElementById('folder-menu');
  const title = document.getElementById('gallery-title');
  const container = document.getElementById('images-container');
  const fab = document.getElementById('fab-add');
  const searchWrapper = document.getElementById('search-wrapper');
  const searchInput = document.getElementById('search-input');
  const selectionControls = document.getElementById('selection-controls');
  const selectAllCheckbox = document.getElementById('select-all-checkbox');
  const deleteSelectedBtn = document.getElementById('delete-selected');
  const moveSelectedUploadedBtn = document.getElementById('move-selected-uploaded');
  const moveSelectedFolderBtn = document.getElementById('move-selected-folder');
  const selectedCountSpan = document.getElementById('selected-count');
  const selectedCountUploadedSpan = document.getElementById('selected-count-uploaded');
  const selectedCountFolderSpan = document.getElementById('selected-count-folder');
  const confirmModal = document.getElementById('confirm-modal');
  const confirmCount = document.getElementById('confirm-count');
  const confirmYes = document.getElementById('confirm-yes');
  const confirmNo = document.getElementById('confirm-no');
  const renameModal = document.getElementById('rename-modal');
  const renameInput = document.getElementById('rename-input');
  const renameYes = document.getElementById('rename-yes');
  const renameNo = document.getElementById('rename-no');
  const deleteFolderModal = document.getElementById('delete-folder-modal');
  const deleteFolderName = document.getElementById('delete-folder-name');
  const deleteFolderYes = document.getElementById('delete-folder-yes');
  const deleteFolderNo = document.getElementById('delete-folder-no');
  const moveModal = document.getElementById('move-modal');
  const moveUrls = document.getElementById('move-indices');
  const moveYes = document.getElementById('move-yes');
  const moveNo = document.getElementById('move-no');
  const moveToFolderModal = document.getElementById('move-to-folder-modal');
  const targetFolderSelect = document.getElementById('target-folder-select');
  const moveToFolderIndices = document.getElementById('move-to-folder-indices');
  const moveToFolderYes = document.getElementById('move-to-folder-yes');
  const moveToFolderNo = document.getElementById('move-to-folder-no');
  const moveSelectedFolderModal = document.getElementById('move-selected-folder-modal');
  const moveSelectedTargetSelect = document.getElementById('move-selected-target-select');
  const moveSelectedFolderYes = document.getElementById('move-selected-folder-yes');
  const moveSelectedFolderNo = document.getElementById('move-selected-folder-no');
  const moveAllModal = document.getElementById('move-all-modal');
  const moveAllTarget = document.getElementById('move-all-target');
  const moveAllYes = document.getElementById('move-all-yes');
  const moveAllNo = document.getElementById('move-all-no');
  const moveBackModal = document.getElementById('move-back-modal');
  const moveBackUrls = document.getElementById('move-back-indices');
  const moveBackYes = document.getElementById('move-back-yes');
  const moveBackNo = document.getElementById('move-back-no');
  const moveAllBackModal = document.getElementById('move-all-back-modal');
  const moveAllBackTarget = document.getElementById('move-all-back-target');
  const moveAllBackYes = document.getElementById('move-all-back-yes');
  const moveAllBackNo = document.getElementById('move-all-back-no');
  const historyBtn = document.getElementById('history-btn');
  const historyModal = document.getElementById('history-modal');
  const historyList = document.getElementById('history-list');
  const logoutBtn = document.getElementById('logout-btn');
  const autoMoveModal = document.getElementById('auto-move-modal');
  const autoMoveTitle = document.getElementById('auto-move-title');
  const autoMoveMessage = document.getElementById('auto-move-message');
  const autoMoveSummary = document.getElementById('auto-move-summary');

  let allImages = [], filteredImages = [], searchTimer = null;
  let currentFolder = null, selectedImages = new Set(), bulkFiles = [], folderToRename = null, folderToDelete = null;
  let currentImageUrl = '';
  let isHomeView = true;
  let activityTimer = null;

  // SHOW AUTO-MOVE NOTIFICATION
  function showAutoMoveNotification(result) {
      if (!result || !result.success || result.moved_count === 0) return;
      
      const folderName = result.target_folder || 'Unknown';
      const uploadedFolder = result.uploaded_folder || (folderName + '_uploaded');
      
      let titleText = '📦 Images Auto-Moved Successfully!';
      if (result.created_new_folder) {
          titleText = '📦 New Folder Created & Images Auto-Moved!';
      }
      autoMoveTitle.textContent = titleText;
      
      let message = `${result.moved_count} image(s) were automatically moved to "<strong>${uploadedFolder}</strong>" folder.`;
      if (result.failed_count > 0) {
          message += ` <span style="color:var(--danger)">${result.failed_count} image(s) failed to move.</span>`;
      }
      if (result.created_new_folder) {
          message += `<br><span style="color:var(--success)">✨ New folder "${uploadedFolder}" was created automatically!</span>`;
      }
      autoMoveMessage.innerHTML = message;
      
      let summaryHtml = `
          <div class="summary-item">
              <span class="label">Target Folder:</span>
              <span class="value">${uploadedFolder}</span>
          </div>
          <div class="summary-item">
              <span class="label">Files Moved:</span>
              <span class="value" style="color:var(--success)">${result.moved_count}</span>
          </div>
      `;
      
      if (result.failed_count > 0) {
          summaryHtml += `
              <div class="summary-item">
                  <span class="label">Files Failed:</span>
                  <span class="value" style="color:var(--danger)">${result.failed_count}</span>
              </div>
          `;
      }
      
      if (result.moved_from_folders && result.moved_from_folders.length > 0) {
          summaryHtml += `
              <div class="summary-item">
                  <span class="label">Moved From:</span>
                  <span class="value">${result.moved_from_folders.join(', ')}</span>
              </div>
          `;
      }
      
      if (result.created_new_folder) {
          summaryHtml += `
              <div class="summary-item" style="color:var(--success); font-weight:600;">
                  <span class="label">✨ New Folder Created:</span>
                  <span class="value">${result.folder_created}</span>
              </div>
          `;
      }
      
      if (result.urls_not_found && result.urls_not_found.length > 0) {
          const maxShow = Math.min(5, result.urls_not_found.length);
          const more = result.urls_not_found.length - maxShow;
          let urlList = result.urls_not_found.slice(0, maxShow).map(u => `<div>• ${u}</div>`).join('');
          if (more > 0) {
              urlList += `<div style="color:var(--text-light)">... and ${more} more</div>`;
          }
          summaryHtml += `
              <div style="margin-top:0.5rem;">
                  <div class="label" style="color:var(--danger)">URLs Not Found (${result.urls_not_found.length}):</div>
                  <div class="failed-urls">${urlList}</div>
              </div>
          `;
      }
      
      autoMoveSummary.innerHTML = summaryHtml;
      
      setTimeout(() => {
          autoMoveModal.style.display = 'flex';
      }, 800);
  }

  // CHECK AUTH
  function checkAuth() {
    fetch('?action=check_auth')
      .then(r => r.json())
      .then(data => {
        if (!data.authenticated) {
          window.location.href = 'login.php';
        }
      })
      .catch(() => {
        window.location.href = 'login.php';
      });
  }

  // ACTIVITY TIMER
  function resetActivityTimer() {
    if (activityTimer) clearTimeout(activityTimer);
    activityTimer = setTimeout(function() {
      logout();
    }, 30 * 60 * 1000);
  }

  ['click', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(function(event) {
    document.addEventListener(event, resetActivityTimer);
  });

  function logout() {
    fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=logout'
    })
    .then(function() {
      window.location.href = 'login.php';
    })
    .catch(function() {
      window.location.href = 'login.php';
    });
  }

  logoutBtn.onclick = logout;
  resetActivityTimer();

  // RENDER FOLDER MENU
  function renderFolderMenu() {
    folderMenu.innerHTML = '';
    var contentWrapper = document.createElement('div');
    contentWrapper.className = 'folder-menu-content';

    var createItem = document.createElement('div');
    createItem.className = 'create-folder-item folder-item';
    createItem.innerHTML = '+ Create New Folder';
    createItem.onclick = function(e) { 
      e.stopPropagation(); 
      folderMenu.classList.remove('show'); 
      document.getElementById('folder-modal').style.display = 'flex'; 
    };
    contentWrapper.appendChild(createItem);

    var main = folders.filter(function(f) { return !f.original; });
    main.forEach(function(f) { renderFolderItem(f, contentWrapper); });

    var uploaded = folders.filter(function(f) { return f.original; });
    if (uploaded.length > 0) {
      var section = document.createElement('div');
      section.className = 'uploaded-section folder-item';
      section.innerText = 'Uploaded Collections';
      contentWrapper.appendChild(section);
      uploaded.forEach(function(f) { renderFolderItem(f, contentWrapper, true); });
    }

    folderMenu.appendChild(contentWrapper);
  }

  function renderFolderItem(f, parent, isUploaded) {
    isUploaded = isUploaded || false;
    var item = document.createElement('div');
    item.className = 'folder-item';
    item.innerHTML = `
      <span class="folder-name" title="${f.name}">${f.name}</span>
      ${!isUploaded ? `
        <div class="folder-actions">
          <button class="edit-btn" title="Rename">Edit</button>
          <button class="delete-btn" title="Delete">Delete</button>
        </div>
      ` : ''}
    `;

    var nameSpan = item.querySelector('.folder-name');
    nameSpan.onclick = function(e) { 
      e.stopPropagation(); 
      loadFolder(f.folder, f.name); 
      folderMenu.classList.remove('show'); 
    };

    if (!isUploaded) {
      item.querySelector('.edit-btn').onclick = function(e) { 
        e.stopPropagation(); 
        folderToRename = f.folder; 
        renameInput.value = f.name; 
        renameModal.style.display = 'flex'; 
        folderMenu.classList.remove('show'); 
      };
      item.querySelector('.delete-btn').onclick = function(e) { 
        e.stopPropagation(); 
        folderToDelete = f.folder; 
        deleteFolderName.textContent = f.name; 
        deleteFolderModal.style.display = 'flex'; 
        folderMenu.classList.remove('show'); 
      };
    }

    parent.appendChild(item);
  }
  renderFolderMenu();

  // TOGGLE MENU
  folderToggle.onclick = function() { folderMenu.classList.toggle('show'); };
  document.addEventListener('click', function(e) { if (!folderToggle.contains(e.target) && !folderMenu.contains(e.target)) folderMenu.classList.remove('show'); });

    // LOAD HOME VIEW
    async function loadHomeView() {
    isHomeView = true;
    currentFolder = null;
    activeBtn.textContent = 'All Folders';
    title.textContent = 'All Folders';
    container.innerHTML = '<div class="loading">Loading folders...</div>';
    selectedImages.clear();
    selectionControls.style.display = 'none';
    searchWrapper.style.display = 'none';
    fab.style.display = 'flex';
    
    // Update back button
    updateBackButton();

    try {
        var res = await fetch('?action=get_folders');
        var folders = await res.json();

        if (folders.length === 0) {
        container.innerHTML = '<div class="empty-state">No folders yet. Click the + button to create one!</div>';
        return;
        }

        var grid = document.createElement('div');
        grid.className = 'home-grid';

        folders.forEach(function(f) {
        var card = document.createElement('div');
        card.className = 'folder-card';
        card.onclick = function() { loadFolder(f.folder, f.name); };
        var firstLetter = f.name.charAt(0).toUpperCase();
        var lastLetter = f.name.length > 1 ? f.name.charAt(f.name.length - 1).toUpperCase() : firstLetter;
        card.innerHTML = `
            <div class="folder-cover">${firstLetter}${lastLetter}</div>
            <div class="folder-info">
            <div class="folder-name">${f.name}</div>
            <div class="folder-count">${f.count} image${f.count !== 1 ? 's' : ''}</div>
            </div>
        `;
        grid.appendChild(card);
        });

        container.innerHTML = '';
        container.appendChild(grid);
    } catch (error) {
        console.error('Error loading folders:', error);
        container.innerHTML = '<div class="empty-state">Error loading folders. Please refresh.</div>';
    }
    }

  // CREATE FOLDER
  document.getElementById('cancel-create').onclick = function() { document.getElementById('folder-modal').style.display = 'none'; };
  document.getElementById('confirm-create').onclick = function() {
    var name = document.getElementById('folder-name').value.trim();
    if (!name) return showAlert('Please enter a folder name.');
    var folder = name.replace(/[^a-zA-Z0-9_]/g, '');
    fetch('', {method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=create_folder&folder_name=' + encodeURIComponent(folder)})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) {
        var formatted = formatName(folder);
        folders.push({name: formatted, folder: folder});
        folders.push({name: formatted + ' (Uploaded)', folder: folder + '_uploaded', original: folder});
        renderFolderMenu();
        document.getElementById('folder-modal').style.display = 'none';
        loadFolder(folder, formatted);
      } else showAlert(res.message || 'Failed to create folder.');
    });
  };

  // RENAME FOLDER
  renameNo.onclick = function() { renameModal.style.display = 'none'; folderToRename = null; };
  renameYes.onclick = function() {
    var newName = renameInput.value.trim();
    if (!newName || !folderToRename) return;
    var newFolder = newName.replace(/[^a-zA-Z0-9_]/g, '');
    fetch('', {method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=rename_folder&old_folder=' + folderToRename + '&new_name=' + newFolder})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) {
        var idx = folders.findIndex(function(f) { return f.folder === folderToRename; });
        if (idx !== -1) { folders[idx] = { name: res.new_name, folder: res.new_folder }; }
        var upIdx = folders.findIndex(function(f) { return f.original === folderToRename; });
        if (upIdx !== -1) { folders[upIdx] = { name: res.new_name + ' (Uploaded)', folder: res.new_folder + '_uploaded', original: res.new_folder }; }
        renderFolderMenu();
        if (currentFolder === folderToRename || currentFolder === folderToRename + '_uploaded') loadFolder(res.new_folder, res.new_name);
      } else showAlert(res.message || 'Rename failed');
      renameModal.style.display = 'none'; folderToRename = null;
    });
  };

  // DELETE FOLDER
  deleteFolderNo.onclick = function() { deleteFolderModal.style.display = 'none'; folderToDelete = null; };
  deleteFolderYes.onclick = function() {
    if (!folderToDelete) return;
    fetch('', {method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: 'action=delete_folder&folder=' + folderToDelete})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) {
        folders.splice(folders.findIndex(function(f) { return f.folder === folderToDelete; }), 1);
        var upFolder = folderToDelete + '_uploaded';
        var upIdx = folders.findIndex(function(f) { return f.folder === upFolder; });
        if (upIdx !== -1) folders.splice(upIdx, 1);
        renderFolderMenu();
        if (currentFolder === folderToDelete || currentFolder === upFolder) {
          loadHomeView();
        }
      }
      deleteFolderModal.style.display = 'none'; folderToDelete = null;
    });
  };

  // ALERT
  var alertModal = document.createElement('div'); alertModal.className = 'modal'; alertModal.id = 'alert-modal';
  alertModal.innerHTML = '<div class="modal-content"><p id="alert-message"></p><div><button id="alert-ok">OK</button></div></div>';
  document.body.appendChild(alertModal);
  var alertMessage = document.getElementById('alert-message');
  var alertOk = document.getElementById('alert-ok');
  function showAlert(msg) { alertMessage.textContent = msg; alertModal.style.display = 'flex'; }
  alertOk.onclick = function() { alertModal.style.display = 'none'; };

  // PARSE URL INPUT
  function parseUrlInput(input) {
    return input.split(',')
      .map(function(item) { return item.trim().replace(/^["']|["']$/g, ''); })
      .filter(function(item) { return item.length > 0; });
  }

  // LOAD FOLDER
    // LOAD FOLDER
    async function loadFolder(folder, name) {
    isHomeView = false;
    currentFolder = folder; 
    activeBtn.textContent = name; 
    title.textContent = name + ' Collection';
    container.innerHTML = '<div class="loading">Loading images...</div>';
    selectedImages.clear(); 
    selectionControls.style.display = 'none'; 
    searchWrapper.style.display = 'block';
    searchInput.value = '';
    fab.style.display = 'flex';
    
    // Update back button
    updateBackButton();

    var res = await fetch('?action=get_images&folder=' + folder);
    var images = await res.json(); 
    allImages = images; 
    filteredImages = images; 

    if (images.length > 0) {
        var urls = images.map(function(img) { return img.url; });
        fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=log_copy_bulk&folder=' + encodeURIComponent(folder) + '&urls=' + encodeURIComponent(JSON.stringify(urls))
        });
    }

    renderImages(filteredImages);
    }

  // RENDER IMAGES
  function renderImages(images) {
    container.innerHTML = '';
    if (images.length === 0) {
      var div = document.createElement('div');
      div.className = searchInput.value ? 'no-results' : 'add-btn-container';
      div.innerHTML = searchInput.value ? '<p>No images match "<strong>' + searchInput.value + '</strong>"</p>' : '<button id="add-first">+ Add Images (Bulk)</button>';
      container.appendChild(div);
      if (!searchInput.value) div.querySelector('button').onclick = showBulkUpload;
      return;
    }
    var scroll = document.createElement('div'); scroll.className = 'image-scroll';
    images.forEach(function(img, i) {
      var item = document.createElement('div'); item.className = 'image-item'; item.dataset.path = img.path; item.dataset.url = img.url; item.dataset.index = i;
      var ext = img.path.split('.').pop().toUpperCase();
      item.innerHTML = '<div class="checkbox"></div><img src="' + img.url + '" alt="Image ' + (i+1) + '" loading="lazy"><p>#' + (i+1) + ' • ' + ext + '</p>';
      var cb = item.querySelector('.checkbox');
      cb.onclick = function(e) { e.stopPropagation(); toggleSelect(item); };
      item.onclick = function(e) { if (e.target !== cb) openFullscreen(img.path, img.url); };
      scroll.appendChild(item);
    });
    container.appendChild(scroll);
    var moveWrapper = document.createElement('div'); moveWrapper.style.textAlign = 'center';
    var isUploaded = currentFolder.endsWith('_uploaded');
    if (!isUploaded) {
      moveWrapper.innerHTML = '<button class="move-to-uploaded-btn" id="move-to-uploaded-btn">Move to Uploaded</button><button class="move-to-another-folder-btn" id="move-to-another-folder-btn">Move to Another Folder</button><button class="move-all-jpgs-btn" id="move-all-jpgs-btn" style="display:none";>Move All JPGs to Uploaded</button>';
    } else {
      moveWrapper.innerHTML = '<button class="move-back-to-main-btn" id="move-back-to-main-btn">Move Back to Main</button><button class="move-all-jpgs-back-btn" id="move-all-jpgs-back-btn">Move All JPGs Back</button>';
    }
    container.appendChild(moveWrapper);
    if (!isUploaded) {
      document.getElementById('move-to-uploaded-btn').onclick = function() { moveUrls.value = ''; moveModal.style.display = 'flex'; };
      document.getElementById('move-to-another-folder-btn').onclick = function() { 
        targetFolderSelect.innerHTML = '';
        folders.filter(function(f) { return !f.original && f.folder !== currentFolder; }).forEach(function(f) {
          var option = document.createElement('option');
          option.value = f.folder;
          option.textContent = f.name;
          targetFolderSelect.appendChild(option);
        });
        moveToFolderIndices.value = '';
        moveToFolderModal.style.display = 'flex';
      };
      document.getElementById('move-all-jpgs-btn').onclick = function() { moveAllTarget.textContent = activeBtn.textContent + ' (Uploaded)'; moveAllModal.style.display = 'flex'; };
    } else {
      document.getElementById('move-back-to-main-btn').onclick = function() { moveBackUrls.value = ''; moveBackModal.style.display = 'flex'; };
      document.getElementById('move-all-jpgs-back-btn').onclick = function() { moveAllBackTarget.textContent = activeBtn.textContent.replace(' (Uploaded)', ''); moveAllBackModal.style.display = 'flex'; };
    }
    fab.style.display = 'flex'; setupSelection();
  }

  // MOVE TO UPLOADED
  moveNo.onclick = function() { moveModal.style.display = 'none'; moveUrls.value = ''; };
  moveYes.onclick = function() {
    var raw = moveUrls.value.trim();
    if (!raw) return showAlert('Paste at least one image URL.');
    var urls = parseUrlInput(raw);
    if (urls.length === 0) return showAlert('No valid URLs found.');
    
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=move_to_uploaded&folder=' + currentFolder + '&urls=' + encodeURIComponent(urls.join(','))})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) { 
        moveModal.style.display = 'none'; 
        moveUrls.value = ''; 
        var message = res.failed && res.failed.length > 0 
          ? 'Moved ' + res.moved + ' image(s) to uploaded. ' + res.failed.length + ' invalid URL(s) ignored.'
          : 'Moved ' + res.moved + ' image(s) to uploaded.';
        showAlert(message); 
        loadFolder(currentFolder, activeBtn.textContent); 
      }
      else showAlert(res.message || 'Move failed');
    });
  };

  // MOVE TO ANOTHER FOLDER
  moveToFolderNo.onclick = function() { moveToFolderModal.style.display = 'none'; moveToFolderIndices.value = ''; };
  moveToFolderYes.onclick = function() {
    var raw = moveToFolderIndices.value.trim();
    var targetFolder = targetFolderSelect.value;
    if (!raw) return showAlert('Paste at least one image URL.');
    if (!targetFolder) return showAlert('Please select a destination folder.');
    
    var urls = parseUrlInput(raw);
    if (urls.length === 0) return showAlert('No valid URLs found.');
    
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=move_to_folder&source_folder=' + currentFolder + '&target_folder=' + targetFolder + '&urls=' + encodeURIComponent(urls.join(','))})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) { 
        moveToFolderModal.style.display = 'none'; 
        moveToFolderIndices.value = ''; 
        var message = res.failed && res.failed.length > 0 
          ? 'Moved ' + res.moved + ' image(s) to ' + targetFolderSelect.selectedOptions[0].textContent + '. ' + res.failed.length + ' invalid URL(s) ignored.'
          : 'Moved ' + res.moved + ' image(s) to ' + targetFolderSelect.selectedOptions[0].textContent + '.';
        showAlert(message); 
        loadFolder(currentFolder, activeBtn.textContent); 
      }
      else showAlert(res.message || 'Move failed');
    });
  };

  // MOVE SELECTED TO UPLOADED
  moveSelectedUploadedBtn.onclick = function() {
    if (selectedImages.size === 0) return;
    var paths = Array.from(selectedImages);
    fetch('', {
      method: 'POST', 
      headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
      body: 'action=move_selected&source_folder=' + currentFolder + '&target_folder=' + currentFolder + '_uploaded&paths=' + encodeURIComponent(JSON.stringify(paths))
    })
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) { 
        selectedImages.clear(); 
        var message = res.failed && res.failed.length > 0 
          ? 'Moved ' + res.moved + ' image(s) to uploaded. ' + res.failed.length + ' image(s) could not be moved.'
          : 'Moved ' + res.moved + ' image(s) to uploaded.';
        showAlert(message); 
        loadFolder(currentFolder, activeBtn.textContent); 
      }
      else showAlert(res.message || 'Move failed');
    });
  };

  // MOVE SELECTED TO FOLDER
  moveSelectedFolderBtn.onclick = function() {
    if (selectedImages.size === 0) return;
    moveSelectedTargetSelect.innerHTML = '';
    folders.filter(function(f) { return !f.original && f.folder !== currentFolder; }).forEach(function(f) {
      var option = document.createElement('option');
      option.value = f.folder;
      option.textContent = f.name;
      moveSelectedTargetSelect.appendChild(option);
    });
    moveSelectedFolderModal.style.display = 'flex';
  };

  moveSelectedFolderNo.onclick = function() { moveSelectedFolderModal.style.display = 'none'; };
  moveSelectedFolderYes.onclick = function() {
    var targetFolder = moveSelectedTargetSelect.value;
    if (!targetFolder) return showAlert('Please select a destination folder.');
    var paths = Array.from(selectedImages);
    fetch('', {
      method: 'POST', 
      headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
      body: 'action=move_selected&source_folder=' + currentFolder + '&target_folder=' + targetFolder + '&paths=' + encodeURIComponent(JSON.stringify(paths))
    })
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) { 
        moveSelectedFolderModal.style.display = 'none'; 
        selectedImages.clear(); 
        var message = res.failed && res.failed.length > 0 
          ? 'Moved ' + res.moved + ' image(s) to ' + moveSelectedTargetSelect.selectedOptions[0].textContent + '. ' + res.failed.length + ' image(s) could not be moved.'
          : 'Moved ' + res.moved + ' image(s) to ' + moveSelectedTargetSelect.selectedOptions[0].textContent + '.';
        showAlert(message); 
        loadFolder(currentFolder, activeBtn.textContent); 
      }
      else showAlert(res.message || 'Move failed');
    });
  };

  // SEARCH
  searchInput.addEventListener('input', function() {
    if (isHomeView) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
      var term = searchInput.value.trim().toLowerCase();
      filteredImages = term ? allImages.filter(function(img) { return img.url.toLowerCase().includes(term) || img.path.toLowerCase().includes(term); }) : allImages;
      renderImages(filteredImages);
      document.querySelectorAll('.image-item').forEach(function(item) {
        var path = item.dataset.path;
        if (selectedImages.has(path)) item.classList.add('selected'); else item.classList.remove('selected');
      });
      updateSelectionUI();
    }, 250);
  });

  // SELECTION
  function setupSelection() {
    selectionControls.style.display = 'flex';
    selectAllCheckbox.onchange = function() {
      var checked = selectAllCheckbox.checked;
      document.querySelectorAll('.image-item').forEach(function(item) {
        var path = item.dataset.path;
        if (checked) { selectedImages.add(path); item.classList.add('selected'); }
        else { selectedImages.delete(path); item.classList.remove('selected'); }
      });
      updateSelectionUI();
    };
    deleteSelectedBtn.onclick = showConfirmModal;
  }
  
  function toggleSelect(item) {
    var path = item.dataset.path;
    if (selectedImages.has(path)) { selectedImages.delete(path); item.classList.remove('selected'); }
    else { selectedImages.add(path); item.classList.add('selected'); }
    updateSelectionUI();
    var all = document.querySelectorAll('.image-item').length;
    var sel = selectedImages.size;
    selectAllCheckbox.checked = (all === sel && sel > 0);
  }
  
  function updateSelectionUI() {
    var cnt = selectedImages.size;
    selectedCountSpan.textContent = cnt;
    selectedCountUploadedSpan.textContent = cnt;
    selectedCountFolderSpan.textContent = cnt;
    deleteSelectedBtn.style.display = cnt > 0 ? 'inline-block' : 'none';
    moveSelectedUploadedBtn.style.display = cnt > 0 && !currentFolder.endsWith('_uploaded') ? 'inline-block' : 'none';
    moveSelectedFolderBtn.style.display = cnt > 0 && !currentFolder.endsWith('_uploaded') ? 'inline-block' : 'none';
  }

  // CONFIRM DELETE
  function showConfirmModal() {
    if (selectedImages.size === 0) return;
    confirmCount.textContent = selectedImages.size;
    confirmModal.style.display = 'flex';
  }
  confirmNo.onclick = function() { confirmModal.style.display = 'none'; };
  confirmYes.onclick = function() {
    confirmModal.style.display = 'none';
    var paths = Array.from(selectedImages);
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=delete_images&folder=' + currentFolder + '&paths=' + encodeURIComponent(JSON.stringify(paths))})
    .then(function(r) { return r.json(); }).then(function(res) { if (res.success) { selectedImages.clear(); loadFolder(currentFolder, activeBtn.textContent); } });
  };

  // MOVE ALL JPGS
  moveAllNo.onclick = function() { moveAllModal.style.display = 'none'; };
  moveAllYes.onclick = function() {
    moveAllModal.style.display = 'none';
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=move_all_jpgs&folder=' + currentFolder})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) showAlert('Moved ' + res.moved + ' JPG(s) to uploaded.');
      else showAlert(res.message || 'Failed to move JPGs.');
      loadFolder(currentFolder, activeBtn.textContent);
    });
  };

  // MOVE BACK
  moveBackNo.onclick = function() { moveBackModal.style.display = 'none'; moveBackUrls.value = ''; };
  moveBackYes.onclick = function() {
    var raw = moveBackUrls.value.trim();
    if (!raw) return showAlert('Paste at least one image URL.');
    var urls = parseUrlInput(raw);
    if (urls.length === 0) return showAlert('No valid URLs found.');
    
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=move_back_to_main&folder=' + currentFolder + '&urls=' + encodeURIComponent(urls.join(','))})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) { 
        moveBackModal.style.display = 'none'; 
        moveBackUrls.value = ''; 
        var message = res.failed && res.failed.length > 0 
          ? 'Moved ' + res.moved + ' image(s) back to main. ' + res.failed.length + ' invalid URL(s) ignored.'
          : 'Moved ' + res.moved + ' image(s) back to main.';
        showAlert(message); 
        loadFolder(currentFolder, activeBtn.textContent); 
      }
      else showAlert(res.message || 'Move back failed');
    });
  };

  // MOVE ALL JPGS BACK
  moveAllBackNo.onclick = function() { moveAllBackModal.style.display = 'none'; };
  moveAllBackYes.onclick = function() {
    moveAllBackModal.style.display = 'none';
    fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=move_all_jpgs_back&folder=' + currentFolder})
    .then(function(r) { return r.json(); }).then(function(res) {
      if (res.success) showAlert('Moved ' + res.moved + ' JPG(s) back to main.');
      else showAlert(res.message || 'Failed to move back JPGs.');
      loadFolder(currentFolder, activeBtn.textContent);
    });
  };

  // BULK UPLOAD
  fab.onclick = showBulkUpload;
  function showBulkUpload() {
    if (isHomeView) {
      document.getElementById('folder-modal').style.display = 'flex';
      return;
    }
    if (!currentFolder) return;
    document.getElementById('bulk-folder-name').textContent = activeBtn.textContent;
    document.getElementById('bulk-upload-overlay').style.display = 'flex';
    bulkFiles = []; updateBulkPreview();
  }
  var bulkOverlay = document.getElementById('bulk-upload-overlay');
  var bulkArea = document.getElementById('bulk-upload-area');
  var bulkInput = document.getElementById('bulk-input');
  var bulkChoose = document.getElementById('bulk-choose');
  var bulkCount = document.getElementById('bulk-count');
  var bulkPreview = document.getElementById('bulk-preview');
  var bulkProgress = document.getElementById('bulk-progress');
  var bulkFill = document.getElementById('bulk-fill');
  var bulkText = document.getElementById('bulk-text');
  var bulkSave = document.getElementById('bulk-save');
  var bulkCancel = document.getElementById('bulk-cancel');
  bulkChoose.onclick = function() { bulkInput.click(); };
  bulkInput.onchange = function() { handleBulkFiles(bulkInput.files); };
  ['dragenter','dragover'].forEach(function(e) { bulkArea.addEventListener(e, function() { bulkArea.classList.add('dragover'); }); });
  ['dragleave','drop'].forEach(function(e) { bulkArea.addEventListener(e, function() { bulkArea.classList.remove('dragover'); }); });
  bulkArea.ondrop = function(e) { e.preventDefault(); handleBulkFiles(e.dataTransfer.files); };
  function handleBulkFiles(files) {
    var valid = Array.from(files).filter(function(f) { return f.type.startsWith('image/'); });
    bulkFiles = bulkFiles.concat(valid);
    updateBulkPreview();
  }
  function updateBulkPreview() {
    bulkCount.textContent = bulkFiles.length + ' image(s) selected';
    bulkPreview.innerHTML = '';
    bulkFiles.forEach(function(file, i) {
      var reader = new FileReader();
      reader.onload = function(e) {
        var div = document.createElement('div');
        div.className = 'bulk-preview-item';
        div.innerHTML = '<img src="' + e.target.result + '" alt="Preview"><div class="bulk-remove" onclick="removeBulk(' + i + ')">X</div>';
        bulkPreview.appendChild(div);
      };
      reader.readAsDataURL(file);
    });
    bulkSave.style.display = bulkFiles.length > 0 ? 'inline-block' : 'none';
  }
  window.removeBulk = function(i) { bulkFiles.splice(i, 1); updateBulkPreview(); };
  bulkSave.onclick = function() { if (bulkFiles.length) uploadBulkInChunks(); };
  bulkCancel.onclick = function() { bulkOverlay.style.display = 'none'; bulkFiles = []; };
  async function uploadBulkInChunks() {
    var chunkSize = 10; var uploaded = 0;
    bulkProgress.style.display = 'block';
    for (var i = 0; i < bulkFiles.length; i += chunkSize) {
      var chunk = bulkFiles.slice(i, i + chunkSize);
      var form = new FormData();
      form.append('action', 'upload_images');
      form.append('folder', currentFolder);
      chunk.forEach(function(f) { form.append('images[]', f); });
      await fetch('', {method: 'POST', body: form})
        .then(function(r) { return r.json(); })
        .then(function(res) {
          uploaded += res.uploaded ? res.uploaded.length : 0;
          var pct = (uploaded / bulkFiles.length) * 100;
          bulkFill.style.width = pct + '%';
          bulkText.textContent = 'Uploaded ' + uploaded + ' of ' + bulkFiles.length;
        });
    }
    setTimeout(function() { bulkOverlay.style.display = 'none'; loadFolder(currentFolder, activeBtn.textContent); }, 600);
  }
    // Go back - if in folder view go to home view, if in home view go to serenum
    function goBack() {
    if (!isHomeView && currentFolder) {
        // If in a folder, go back to home view (all folders)
        loadHomeView();
    } else {
        // If in home view, go to serenum
        goToSerenum();
    }
    }

    // Go to serenum dashboard
    function goToSerenum() {
    window.location.href = 'serenum.php';
    }

    // Update back button visibility based on current view
    function updateBackButton() {
    const backBtn = document.getElementById('backBtn');
    if (backBtn) {
        if (!isHomeView && currentFolder) {
        // In a folder - show back button to go to home view
        backBtn.style.display = 'flex';
        backBtn.textContent = '← All Folders';
        } else if (isHomeView) {
        // In home view - show back button to go to serenum
        backBtn.style.display = 'flex';
        backBtn.textContent = '← Home';
        } else {
        backBtn.style.display = 'none';
        }
    }
    }

  // FULLSCREEN
  var fullscreenModal = document.getElementById('fullscreen-modal');
  var fullscreenImg = document.getElementById('fullscreen-img');
  var copyBtn = document.getElementById('copy-link-btn');
  var closeBtn = document.getElementById('close-fullscreen');
  var notif = document.getElementById('copied-notif');
  function openFullscreen(path, url) {
    fullscreenImg.src = url; currentImageUrl = url; fullscreenModal.style.display = 'flex'; document.body.style.overflow = 'hidden';
  }
  closeBtn.onclick = function() { fullscreenModal.style.display = 'none'; document.body.style.overflow = 'auto'; };
  copyBtn.onclick = function() {
    navigator.clipboard.writeText(currentImageUrl).then(function() {
      notif.classList.add('show');
      setTimeout(function() { notif.classList.remove('show'); }, 2000);
    });
  };

  // HISTORY
  historyBtn.onclick = async function() {
    var res = await fetch('?action=get_copied_log');
    var logs = await res.json();
    historyList.innerHTML = logs.map(function(l) {
      return '<div class="history-item"><div class="info"><div class="folder-name">' + l.folder + '</div><div class="url">' + l.url + '</div></div><button class="copy-again" onclick="navigator.clipboard.writeText(\'' + l.url + '\');this.textContent=\'Copied!\'">Copy</button></div>';
    }).join('') || '<p style="color:#888">No copied links yet.</p>';
    historyModal.style.display = 'flex';
  };

  // ESC CLOSE
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      [fullscreenModal, bulkOverlay, confirmModal, renameModal, deleteFolderModal, moveModal, moveToFolderModal, moveSelectedFolderModal, moveAllModal, moveBackModal, moveAllBackModal, alertModal, historyModal, autoMoveModal].forEach(function(m) {
        if (m.style.display === 'flex') {
          var closeBtn = m.querySelector('button[onclick*="none"]');
          if (closeBtn) closeBtn.click();
        }
      });
      if (folderMenu.classList.contains('show')) folderMenu.classList.remove('show');
    }
  });

  // CHECK AUTH EVERY MINUTE
  setInterval(checkAuth, 60000);

  // LOAD HOME VIEW
  loadHomeView();

  function formatName(str) { return str.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }); }

  // SHOW AUTO-MOVE NOTIFICATION AFTER PAGE LOADS
  if (autoMoveResult && autoMoveResult.success && autoMoveResult.moved_count > 0) {
      window.addEventListener('load', function() {
          setTimeout(function() {
              showAutoMoveNotification(autoMoveResult);
          }, 1000);
      });
  }

</script>
</body>
</html>