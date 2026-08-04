<?php
// uploadedjpgsurl.php - Standalone backend for auto-moving JPGs from uploadedjpgs column
// This file extracts URLs from the array format: [{"folder":"name"}, "url1", "url2", {"_timestamp":...}]

// ---------------------------------------------------------------
// CONFIGURATION
// ---------------------------------------------------------------
$host = 'sql201.infinityfree.com';
$dbname = 'if0_40367004_automation_tree';
$username = 'if0_40367004';
$password = 'NkwFAH15FRIlvCf';

// ---------------------------------------------------------------
// DATABASE CONNECTION
// ---------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]));
}

// ---------------------------------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------------------------------
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

function syncAllUrls($pdo) {
    $allUrls = [];
    $totalUrls = 0;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = $col['Field'];
        if ($field !== 'id' && 
            $field !== 'copied_links' && 
            $field !== 'server_passkey' && 
            !isUploadedFolder($field) &&
            $field !== 'all_urls' &&
            $field !== 'all_urls_uploaded' &&
            $field !== 'uploadedjpgs') {
            
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

/**
 * Extract URLs from the uploadedjpgs array format
 * Format: [{"folder":"name"}, "url1", "url2", {"_timestamp":...}]
 * Returns: ['urls' => [...], 'folder' => 'name', 'metadata' => [...]]
 */
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
        // Check for folder marker
        if (is_array($item) && isset($item['folder'])) {
            $result['folder'] = $item['folder'];
            $result['markers'][] = 'folder';
            continue;
        }
        
        // Check for timestamp/metadata marker (keys starting with _)
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
        
        // If it's a string and looks like a URL, add it to urls
        if (is_string($item)) {
            $url = trim($item);
            // Basic URL validation
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $result['urls'][] = $url;
            }
        }
    }
    
    return $result;
}

/**
 * SCAN EXISTING FOLDERS AND CREATE MISSING COLUMNS
 * This ensures all folders with images in the filesystem are displayed in the frontend
 */
function scanAndCreateMissingFolders($pdo, $debug = false) {
    $result = [
        'created_folders' => [],
        'created_uploaded_folders' => [],
        'existing_folders' => [],
        'scanned_directories' => [],
        'total_images_found' => 0,
        'errors' => []
    ];
    
    if ($debug) echo "\n📂 SCANNING FILESYSTEM FOR EXISTING FOLDERS...\n";
    
    // Get all existing columns from database
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
    
    if ($debug) echo "   Existing database columns: " . count($existingColumns) . "\n";
    
    // Scan the jpgs directory for subdirectories
    $jpgsDir = 'jpgs/';
    if (!is_dir($jpgsDir)) {
        if ($debug) echo "   ⚠️ jpgs directory does not exist\n";
        return $result;
    }
    
    $scannedFolders = scandir($jpgsDir);
    $folderNames = array_filter($scannedFolders, function($item) {
        return $item !== '.' && $item !== '..' && is_dir('jpgs/' . $item);
    });
    
    $result['scanned_directories'] = $folderNames;
    
    if ($debug) echo "   Found " . count($folderNames) . " directories in jpgs/\n";
    
    // Process each folder
    foreach ($folderNames as $folderName) {
        // Skip uploaded folders for now
        if (isUploadedFolder($folderName)) {
            $mainFolder = getOriginalFolder($folderName);
            // Check if main folder exists, if not, create it
            if (!in_array($mainFolder, $existingColumns) && !columnExists($pdo, $mainFolder)) {
                if ($debug) echo "   📁 Creating main folder for uploaded: '$mainFolder'\n";
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
        
        // Check if folder exists in database
        if (!in_array($folderName, $existingColumns) && !columnExists($pdo, $folderName)) {
            if ($debug) echo "   📁 Creating missing folder: '$folderName'\n";
            try {
                $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$folderName` JSON DEFAULT NULL");
                $result['created_folders'][] = $folderName;
                $existingColumns[] = $folderName;
            } catch (Exception $e) {
                $result['errors'][] = "Failed to create folder $folderName: " . $e->getMessage();
            }
        }
        
        // Check if uploaded version exists and create if needed
        $uploadedFolder = getPairedUploadedFolder($folderName);
        if (!in_array($uploadedFolder, $existingColumns) && !columnExists($pdo, $uploadedFolder)) {
            if ($debug) echo "   📁 Creating uploaded folder: '$uploadedFolder'\n";
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
    if ($debug) echo "\n📋 POPULATING FOLDERS WITH IMAGES...\n";
    
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
            if ($debug) echo "   ⚠️ Directory missing: $folderPath (will be created)\n";
            ensureDir($folderPath);
            continue;
        }
        
        // Get existing images from database
        $dbImages = getImagesInFolder($pdo, $folderName);
        
        // Scan filesystem for images
        $files = glob($folderPath . "*.*");
        $fsImages = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $fsImages[] = $file;
            }
        }
        
        // If there are filesystem images not in database, add them
        if (!empty($fsImages)) {
            $allImages = array_merge($dbImages, $fsImages);
            $uniqueImages = array_values(array_unique($allImages));
            
            if (count($uniqueImages) > count($dbImages)) {
                saveImagesToFolder($pdo, $folderName, $uniqueImages);
                $result['total_images_found'] += count($fsImages);
                if ($debug) echo "   📸 Added " . count($fsImages) . " images to '$folderName' (total: " . count($uniqueImages) . ")\n";
            } else {
                if ($debug) echo "   ✅ '$folderName' already has " . count($dbImages) . " images\n";
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
                if ($debug) echo "   📸 Added " . count($fsImages) . " images to uploaded '$folderName'\n";
            }
        }
    }
    
    if ($debug) echo "\n✅ Folder scan complete. Created " . count($result['created_folders']) . " new folders.\n";
    
    return $result;
}

// ---------------------------------------------------------------
// MAIN AUTO-MOVE FUNCTION
// ---------------------------------------------------------------
function autoMoveJpgsToUploaded($pdo, $debug = false) {
    $response = [
        'success' => false,
        'message' => '',
        'details' => [],
        'errors' => [],
        'warnings' => [],
        'moved_count' => 0,
        'failed_count' => 0,
        'target_folder' => null,
        'uploaded_folder' => null,
        'moved_from_folders' => [],
        'urls_processed' => 0,
        'urls_not_found' => [],
        'move_errors' => [],
        'metadata' => [],
        'created_new_folder' => false,
        'folder_created' => null,
        'scan_results' => null
    ];
    
    // FIRST: Scan and create any missing folders
    if ($debug) echo "\n🔍 STEP 1: Scanning for missing folders...\n";
    $scanResults = scanAndCreateMissingFolders($pdo, $debug);
    $response['scan_results'] = $scanResults;
    
    if (!empty($scanResults['created_folders']) || !empty($scanResults['created_uploaded_folders'])) {
        $response['details'][] = "Created " . count($scanResults['created_folders']) . " main folders and " . 
                                count($scanResults['created_uploaded_folders']) . " uploaded folders";
    }
    
    // Check if uploadedjpgs column exists
    if (!columnExists($pdo, 'uploadedjpgs')) {
        $error = "uploadedjpgs column does not exist in jpgsvault table";
        if ($debug) echo "❌ ERROR: $error\n";
        $response['success'] = false;
        $response['message'] = $error;
        $response['errors'][] = $error;
        return $response;
    }
    
    // Get data from uploadedjpgs column
    $stmt = $pdo->prepare("SELECT uploadedjpgs FROM jpgsvault WHERE id = 1");
    $stmt->execute();
    $jsonData = $stmt->fetchColumn();
    
    if (!$jsonData) {
        $message = "No data found in uploadedjpgs column - nothing to process";
        if ($debug) echo "ℹ️ INFO: $message\n";
        $response['success'] = true;
        $response['message'] = $message;
        return $response;
    }
    
    $uploadedData = json_decode($jsonData, true);
    if (!is_array($uploadedData)) {
        $error = "Invalid JSON data in uploadedjpgs column";
        if ($debug) echo "❌ ERROR: $error\n";
        $response['success'] = false;
        $response['message'] = $error;
        $response['errors'][] = $error;
        return $response;
    }
    
    if ($debug) {
        echo "\n📊 RAW DATA STRUCTURE:\n";
        echo json_encode($uploadedData, JSON_PRETTY_PRINT) . "\n\n";
    }
    
    // Extract URLs and folder from the array
    $extracted = extractUrlsFromUploadedData($uploadedData);
    $urlsToMove = $extracted['urls'];
    $targetFolderName = $extracted['folder'];
    $metadata = $extracted['metadata'];
    
    $response['metadata'] = $metadata;
    $response['urls_processed'] = count($urlsToMove);
    
    if (empty($targetFolderName)) {
        $error = "No 'folder' marker found in uploadedjpgs data";
        if ($debug) echo "❌ ERROR: $error\n";
        $response['success'] = false;
        $response['message'] = $error;
        $response['errors'][] = $error;
        return $response;
    }
    
    $response['target_folder'] = $targetFolderName;
    
    if (empty($urlsToMove)) {
        $message = "No URLs found for target folder '$targetFolderName' - nothing to move";
        if ($debug) echo "ℹ️ INFO: $message\n";
        $response['success'] = true;
        $response['message'] = $message;
        return $response;
    }
    
    if ($debug) {
        echo "\n📁 TARGET FOLDER: '$targetFolderName'\n";
        echo "📋 URLs to move: " . count($urlsToMove) . "\n";
        foreach ($urlsToMove as $i => $url) {
            echo "   [" . ($i+1) . "] $url\n";
        }
        echo "\n📊 Metadata:\n";
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                echo "   - $key: " . json_encode($value) . "\n";
            } else {
                echo "   - $key: $value\n";
            }
        }
        echo "\n";
    }
    
    // Get ALL folders from the database
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
    
    if ($debug) echo "🔍 Found " . count($allFolders) . " folders to search\n";
    
    // Ensure the target uploaded folder exists
    $uploadedFolder = getPairedUploadedFolder($targetFolderName);
    $createdNewFolder = false;
    
    if (!columnExists($pdo, $uploadedFolder)) {
        if ($debug) echo "📁 Creating uploaded folder '$uploadedFolder'\n";
        try {
            $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$uploadedFolder` JSON DEFAULT NULL");
            $createdNewFolder = true;
            $response['created_new_folder'] = true;
            $response['folder_created'] = $uploadedFolder;
            $response['details'][] = "Created uploaded folder column: $uploadedFolder";
        } catch (Exception $e) {
            $error = "Failed to create uploaded folder column: " . $e->getMessage();
            if ($debug) echo "❌ ERROR: $error\n";
            $response['errors'][] = $error;
            $response['success'] = false;
            $response['message'] = $error;
            return $response;
        }
    }
    
    // Also ensure the main folder exists
    if (!columnExists($pdo, $targetFolderName)) {
        if ($debug) echo "📁 Creating main folder '$targetFolderName'\n";
        try {
            $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN `$targetFolderName` JSON DEFAULT NULL");
            $response['details'][] = "Created main folder column: $targetFolderName";
        } catch (Exception $e) {
            // Continue anyway
            if ($debug) echo "⚠️ Could not create main folder: " . $e->getMessage() . "\n";
        }
    }
    
    $response['uploaded_folder'] = $uploadedFolder;
    
    // Get current images from target uploaded folder
    $uploadedImages = getImagesInFolder($pdo, $uploadedFolder);
    
    // Prepare target directory
    $targetDir = "jpgs/$uploadedFolder/";
    ensureDir($targetDir);
    
    // Also ensure main directory exists
    $mainDir = "jpgs/$targetFolderName/";
    ensureDir($mainDir);
    
    $movedPaths = [];
    $removedOriginalPaths = [];
    $failed = [];
    $foundPaths = [];
    $notFoundUrls = [];
    $moveErrors = [];
    
    // Build a map of all images across all folders
    $allImagesMap = [];
    $folderMap = [];
    $pathToUrlMap = [];
    
    if ($debug) echo "📂 Building image map from all folders...\n";
    
    foreach ($allFolders as $folderName) {
        $images = getImagesInFolder($pdo, $folderName);
        $count = count($images);
        if ($count > 0 && $debug) echo "   - $folderName: $count images\n";
        
        foreach ($images as $path) {
            $filename = basename($path);
            $allImagesMap[$filename] = $path;
            $folderMap[$path] = $folderName;
            
            // Build URL map for matching
            $baseUrl = baseUrl();
            $url = rtrim($baseUrl, '/') . '/' . $path;
            $pathToUrlMap[$path] = $url;
        }
    }
    
    if ($debug) echo "✅ Loaded " . count($allImagesMap) . " total images from all folders\n\n";
    
    // Process each URL
    foreach ($urlsToMove as $index => $url) {
        $url = trim($url);
        $url = preg_replace('/\?v=\d+$/', '', $url);
        $decodedUrl = urldecode($url);
        
        // Extract the filename from the URL
        $inputFilename = basename($decodedUrl);
        $inputFilename = urldecode($inputFilename);
        
        if ($debug) echo "🔍 [$index] Looking for file: '$inputFilename'\n";
        
        // Try to find the file in any folder
        $matchedPath = null;
        $matchedFolder = null;
        $found = false;
        
        // Method 1: Search by filename in the map
        if (isset($allImagesMap[$inputFilename])) {
            $matchedPath = $allImagesMap[$inputFilename];
            $matchedFolder = $folderMap[$matchedPath];
            $found = true;
            if ($debug) echo "   ✅ Found by filename in folder '$matchedFolder'\n";
        } else {
            // Method 2: Search by basename (case-insensitive)
            foreach ($allImagesMap as $storedFilename => $storedPath) {
                if (strcasecmp($storedFilename, $inputFilename) === 0) {
                    $matchedPath = $storedPath;
                    $matchedFolder = $folderMap[$matchedPath];
                    $found = true;
                    if ($debug) echo "   ✅ Found by basename (case-insensitive) in folder '$matchedFolder'\n";
                    break;
                }
            }
        }
        
        // Method 3: Search by URL matching
        if (!$found) {
            foreach ($pathToUrlMap as $path => $pathUrl) {
                $cleanPathUrl = preg_replace('/\?v=\d+$/', '', $pathUrl);
                $cleanUrl = preg_replace('/\?v=\d+$/', '', $url);
                if (strcasecmp($cleanPathUrl, $cleanUrl) === 0) {
                    $matchedPath = $path;
                    $matchedFolder = $folderMap[$path];
                    $found = true;
                    if ($debug) echo "   ✅ Found by URL match in folder '$matchedFolder'\n";
                    break;
                }
            }
        }
        
        // Method 4: Direct search through all folders
        if (!$found) {
            foreach ($allFolders as $folderName) {
                $images = getImagesInFolder($pdo, $folderName);
                foreach ($images as $storedPath) {
                    if (strcasecmp(basename($storedPath), $inputFilename) === 0) {
                        $matchedPath = $storedPath;
                        $matchedFolder = $folderName;
                        $found = true;
                        if ($debug) echo "   ✅ Found by direct search in folder '$matchedFolder'\n";
                        break 2;
                    }
                }
            }
        }
        
        if (!$found) {
            $notFoundUrls[] = $url;
            $failed[] = $url;
            if ($debug) echo "   ❌ Could not find match for file: '$inputFilename'\n";
            continue;
        }
        
        $oldPath = $matchedPath;
        $filename = basename($oldPath);
        $newPath = $targetDir . $filename;
        
        // Handle filename conflicts
        if (file_exists($newPath)) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $counter = 1;
            do {
                $filename = $name . '_' . $counter . '.' . $ext;
                $newPath = $targetDir . $filename;
                $counter++;
            } while (file_exists($newPath));
            if ($debug) echo "   ⚠️ File exists, renamed to: $filename\n";
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
                if ($debug) echo "   ✅ Moved: " . basename($oldPath) . " -> " . basename($newPath) . "\n";
            } else {
                $moveErrors[] = "Failed to move: $oldPath -> $newPath";
                $failed[] = $oldPath;
                if ($debug) echo "   ❌ Failed to move: $oldPath\n";
            }
        } else {
            $moveErrors[] = "File not found on disk: $oldPath";
            $failed[] = $oldPath;
            if ($debug) echo "   ❌ File not found on disk: $oldPath\n";
        }
    }
    
    // If any files were moved, update the database
    if (!empty($movedPaths)) {
        if ($debug) echo "\n📝 Updating database with moved files...\n";
        
        // Update target uploaded folder
        $newUploaded = array_merge($uploadedImages, $movedPaths);
        saveImagesToFolder($pdo, $uploadedFolder, $newUploaded);
        
        // Remove files from their original folders
        foreach ($foundPaths as $sourceFolder => $paths) {
            if (empty($paths)) continue;
            
            $currentImages = getImagesInFolder($pdo, $sourceFolder);
            $remaining = array_values(array_diff($currentImages, $paths));
            saveImagesToFolder($pdo, $sourceFolder, $remaining);
            
            $sourceDir = "jpgs/$sourceFolder/";
            rmdirIfEmpty($sourceDir);
            
            if ($debug) echo "   - Removed " . count($paths) . " images from '$sourceFolder'\n";
        }
        
        // Clean up copied log
        purgeCopiedLog($pdo, $removedOriginalPaths);
        
        // Clear the uploadedjpgs column after successful move
        // Keep the folder marker and add processed status
        $clearData = [
            ["folder" => $targetFolderName],
            [
                "_timestamp" => date('c'),
                "_status" => "processed",
                "_processed_at" => date('c'),
                "_total_moved" => count($movedPaths),
                "_total_failed" => count($failed),
                "_moved_from_folders" => array_keys($foundPaths),
                "_moved_files" => array_map('basename', $movedPaths),
                "_previous_metadata" => $metadata
            ]
        ];
        
        $pdo->prepare("UPDATE jpgsvault SET uploadedjpgs = ? WHERE id = 1")
            ->execute([json_encode($clearData)]);
        
        if ($debug) echo "   - Cleared uploadedjpgs column after processing (kept folder marker)\n";
        
        // Sync all_urls
        $syncedCount = syncAllUrls($pdo);
        if ($debug) echo "   - Synced all_urls: $syncedCount URLs\n";
        
        $response['moved_count'] = count($movedPaths);
        $response['moved_from_folders'] = array_keys($foundPaths);
        
        $message = "Successfully moved " . count($movedPaths) . " images to '$uploadedFolder'";
        if (!empty($failed)) {
            $message .= " (" . count($failed) . " files failed)";
        }
        $response['message'] = $message;
        $response['success'] = true;
        
    } else {
        // No files were moved
        $response['success'] = true;
        $response['message'] = "No images were moved - all URLs could not be found or failed to move";
        if (!empty($failed)) {
            $response['failed_count'] = count($failed);
        }
    }
    
    // Add detailed info
    $response['details'] = array_merge($response['details'], [
        'urls_processed' => count($urlsToMove),
        'urls_not_found' => $notFoundUrls,
        'move_errors' => $moveErrors,
        'moved_paths' => $movedPaths,
        'removed_paths' => $removedOriginalPaths,
        'failed' => $failed,
        'found_paths' => $foundPaths,
        'extracted_metadata' => $metadata,
        'scan_results' => $scanResults
    ]);
    
    $response['failed_count'] = count($failed);
    $response['urls_not_found'] = $notFoundUrls;
    $response['move_errors'] = $moveErrors;
    
    return $response;
}

// ---------------------------------------------------------------
// EXECUTION - Called directly or via include
// ---------------------------------------------------------------
function executeUploadedJpgsMove($debug = true) {
    global $pdo;
    
    echo "\n" . "=" . str_repeat("=", 70) . "\n";
    echo "  📸 UPLOADED JPGS URL PROCESSOR\n";
    echo "  Auto-move images from uploadedjpgs column\n";
    echo "  Format: [{\"folder\":\"name\"}, \"url1\", \"url2\", {\"_timestamp\":...}]\n";
    echo "=" . str_repeat("=", 70) . "\n\n";
    
    $startTime = microtime(true);
    $result = autoMoveJpgsToUploaded($pdo, $debug);
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    echo "\n" . "=" . str_repeat("=", 70) . "\n";
    echo "  📊 EXECUTION RESULTS\n";
    echo "=" . str_repeat("=", 70) . "\n";
    
    if ($result['success']) {
        echo "  ✅ STATUS: SUCCESS\n";
        echo "  📝 MESSAGE: " . $result['message'] . "\n";
        echo "  📁 Target Folder: " . ($result['target_folder'] ?? 'N/A') . "\n";
        echo "  📁 Uploaded Folder: " . ($result['uploaded_folder'] ?? 'N/A') . "\n";
        echo "  📦 URLs Processed: " . $result['urls_processed'] . "\n";
        echo "  📦 Moved Count: " . $result['moved_count'] . "\n";
        echo "  ❌ Failed Count: " . $result['failed_count'] . "\n";
        
        if (!empty($result['moved_from_folders'])) {
            echo "  📂 Moved From: " . implode(', ', $result['moved_from_folders']) . "\n";
        }
        
        if ($result['created_new_folder']) {
            echo "  ✨ Created New Folder: " . $result['folder_created'] . "\n";
        }
        
        // Show scan results
        if (!empty($result['scan_results'])) {
            $scan = $result['scan_results'];
            if (!empty($scan['created_folders'])) {
                echo "\n  📁 Created Main Folders: " . implode(', ', $scan['created_folders']) . "\n";
            }
            if (!empty($scan['created_uploaded_folders'])) {
                echo "  📁 Created Uploaded Folders: " . implode(', ', $scan['created_uploaded_folders']) . "\n";
            }
            if ($scan['total_images_found'] > 0) {
                echo "  📸 Scanned and synced " . $scan['total_images_found'] . " images\n";
            }
        }
        
        if (!empty($result['metadata'])) {
            echo "\n  📊 Metadata from array:\n";
            foreach ($result['metadata'] as $key => $value) {
                if (is_array($value)) {
                    echo "     - $key: " . json_encode($value) . "\n";
                } else {
                    echo "     - $key: $value\n";
                }
            }
        }
        
        if (!empty($result['warnings'])) {
            echo "\n  ⚠️ WARNINGS:\n";
            foreach ($result['warnings'] as $warning) {
                echo "     - $warning\n";
            }
        }
        
        if (!empty($result['urls_not_found'])) {
            echo "\n  ❌ URLs NOT FOUND (" . count($result['urls_not_found']) . "):\n";
            foreach ($result['urls_not_found'] as $url) {
                echo "     - $url\n";
            }
        }
        
        if (!empty($result['move_errors'])) {
            echo "\n  ❌ MOVE ERRORS:\n";
            foreach ($result['move_errors'] as $error) {
                echo "     - $error\n";
            }
        }
        
    } else {
        echo "  ❌ STATUS: FAILED\n";
        echo "  📝 ERROR: " . $result['message'] . "\n";
        if (!empty($result['errors'])) {
            echo "\n  ❌ DETAILS:\n";
            foreach ($result['errors'] as $error) {
                echo "     - $error\n";
            }
        }
    }
    
    echo "\n  ⏱️ Execution Time: " . $executionTime . " seconds\n";
    echo "=" . str_repeat("=", 70) . "\n\n";
    
    return $result;
}

// ---------------------------------------------------------------
// HANDLE AJAX/API REQUESTS
// ---------------------------------------------------------------
if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if ($action === 'process') {
        // Handle AJAX request
        header('Content-Type: application/json');
        $result = autoMoveJpgsToUploaded($pdo, false);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'debug') {
        // Handle debug request
        header('Content-Type: text/plain');
        $result = executeUploadedJpgsMove(true);
        exit;
    }
    
    if ($action === 'status') {
        // Check if there's data to process
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("SELECT uploadedjpgs FROM jpgsvault WHERE id = 1");
        $stmt->execute();
        $jsonData = $stmt->fetchColumn();
        
        if (!$jsonData) {
            echo json_encode(['has_data' => false, 'message' => 'No data to process']);
        } else {
            $data = json_decode($jsonData, true);
            $extracted = extractUrlsFromUploadedData($data);
            $urlCount = count($extracted['urls']);
            $folder = $extracted['folder'] ?? 'unknown';
            
            echo json_encode([
                'has_data' => $urlCount > 0,
                'url_count' => $urlCount,
                'folder' => $folder,
                'metadata' => $extracted['metadata'],
                'urls' => $extracted['urls']
            ]);
        }
        exit;
    }
}

// ---------------------------------------------------------------
// AUTO-RUN IF CALLED DIRECTLY (not included)
// ---------------------------------------------------------------
if (basename($_SERVER['SCRIPT_FILENAME']) === 'uploadedjpgsurl.php' && !isset($_GET['action']) && !isset($_POST['action'])) {
    // Running directly - execute with debug output
    executeUploadedJpgsMove(true);
}

?>