<?php
    // serenum.php - Serenum Configuration
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
    // Handle Logout
    if (isset($_POST['action']) && $_POST['action'] === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // Handle Check Auth
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

    // ===== GET COPIED LINKS DATA FROM JPGSVAULT =====
    function getCopiedLinksData($pdo) {
        try {
            // STEP 1: Get ALL columns from jpgsvault table (exactly like debug script)
            $stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault");
            $allColumns = [];
            while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $field = $col['Field'];
                // Skip system columns (same as debug script)
                if ($field !== 'id' && $field !== 'copied_links' && $field !== 'server_passkey' && 
                    !str_starts_with($field, 'all_urls')) {
                    $allColumns[] = $field;
                }
            }
            
            // STEP 2: Build folder data from ALL columns - reading each column's data
            $folderData = [];
            
            foreach ($allColumns as $folder) {
                // Get the images from this column (exactly like debug script's getImagesInFolder)
                $stmt = $pdo->prepare("SELECT `$folder` FROM jpgsvault WHERE id = 1");
                $stmt->execute();
                $json = $stmt->fetchColumn();
                $images = $json ? json_decode($json, true) : [];
                if (!is_array($images)) $images = [];
                
                // Build URLs array from images
                $urls = [];
                foreach ($images as $image) {
                    if (is_string($image)) {
                        $urls[] = $image;
                    }
                }
                
                $folderData[$folder] = [
                    'folder' => $folder,
                    'urls' => $urls,
                    'count' => count($urls)
                ];
            }
            
            // STEP 3: Sort folders by count (most URLs first) - like debug script
            uasort($folderData, function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            return $folderData;
            
        } catch (PDOException $e) {
            error_log("Error getting copied links data: " . $e->getMessage());
            return [];
        }
    }

    // Handle Save All - Saves to respective columns
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
        try {
            // Decode all the POST data
            $settingsData = isset($_POST['settings']) ? json_decode($_POST['settings'], true) : [];
            $accountsData = isset($_POST['accounts']) ? json_decode($_POST['accounts'], true) : [];
            $captionsData = isset($_POST['captions']) ? json_decode($_POST['captions'], true) : [];
            $timeordersData = isset($_POST['timeorders']) ? json_decode($_POST['timeorders'], true) : [];
            $filtersData = isset($_POST['filters']) ? json_decode($_POST['filters'], true) : [];
            $dynamicFieldsData = isset($_POST['dynamic_fields']) ? json_decode($_POST['dynamic_fields'], true) : [];
            
            // Ensure data is properly formatted
            if (!is_array($settingsData)) $settingsData = [];
            if (!is_array($accountsData)) $accountsData = [];
            if (!is_array($captionsData)) $captionsData = [];
            if (!is_array($timeordersData)) $timeordersData = [];
            if (!is_array($filtersData)) $filtersData = [];
            if (!is_array($dynamicFieldsData)) $dynamicFieldsData = [];
            
            // ===== CRITICAL FIX: Normalize nested JSON strings =====
            $settingsData = normalizeSettings($settingsData);
            
            // Auto-repair settings: ensure it's always an array of objects
            $settingsData = repairSettings($settingsData);
            
            // Check if record exists
            $stmt = $pdo->query("SELECT COUNT(*) FROM serenum_config");
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                // Update existing record - get the latest ID first
                $stmt = $pdo->query("SELECT id FROM serenum_config ORDER BY id DESC LIMIT 1");
                $latestId = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("UPDATE serenum_config SET 
                    settings = :settings, 
                    accounts_url = :accounts_url, 
                    captions = :captions, 
                    time_orders = :time_orders, 
                    filters = :filters,
                    settings_dynamic_fields = :dynamic_fields
                    WHERE id = :id");
                
                $params = [
                    ':settings' => json_encode($settingsData),
                    ':accounts_url' => json_encode($accountsData),
                    ':captions' => json_encode($captionsData),
                    ':time_orders' => json_encode($timeordersData),
                    ':filters' => json_encode($filtersData),
                    ':dynamic_fields' => json_encode($dynamicFieldsData),
                    ':id' => $latestId
                ];
                
                $stmt->execute($params);
                
            } else {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO serenum_config 
                    (settings, accounts_url, captions, time_orders, filters, settings_dynamic_fields) 
                    VALUES 
                    (:settings, :accounts_url, :captions, :time_orders, :filters, :dynamic_fields)");
                
                $params = [
                    ':settings' => json_encode($settingsData),
                    ':accounts_url' => json_encode($accountsData),
                    ':captions' => json_encode($captionsData),
                    ':time_orders' => json_encode($timeordersData),
                    ':filters' => json_encode($filtersData),
                    ':dynamic_fields' => json_encode($dynamicFieldsData)
                ];
                
                $stmt->execute($params);
            }
            
            echo json_encode(['success' => true, 'message' => 'All data saved successfully!']);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error saving data: ' . $e->getMessage()]);
            exit;
        }
    }

    // ===== NEW FUNCTION: Normalize nested JSON strings =====
    function normalizeSettings($settings) {
        if (!is_array($settings)) {
            return $settings;
        }
        
        // Process each config in the settings array
        foreach ($settings as &$config) {
            if (!is_array($config)) continue;
            
            // List of fields that contain nested JSON strings
            $jsonFields = ['account_url', 'author_caption', 'Jpgsurl', 'jpgsurl', 'time_order_type'];
            
            foreach ($jsonFields as $field) {
                if (isset($config[$field]) && is_string($config[$field])) {
                    // Try to decode the JSON string
                    $decoded = json_decode($config[$field], true);
                    if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                        // It's valid JSON, replace the string with the decoded array/object
                        $config[$field] = $decoded;
                    }
                    // If it's not valid JSON, leave it as is
                }
            }
            
            // Also handle dynamic_values if present (for backward compatibility)
            if (isset($config['dynamic_values']) && is_array($config['dynamic_values'])) {
                foreach ($jsonFields as $field) {
                    if (isset($config['dynamic_values'][$field]) && is_string($config['dynamic_values'][$field])) {
                        $decoded = json_decode($config['dynamic_values'][$field], true);
                        if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                            $config['dynamic_values'][$field] = $decoded;
                        }
                    }
                }
            }
        }
        
        return $settings;
    }

    // Helper function to repair settings structure
    // Helper function to repair settings structure
    function repairSettings($settings) {
        // If not an array, return empty array
        if (!is_array($settings)) {
            return [];
        }
        
        // If it's already an indexed array of objects, just filter out non-objects
        if (isset($settings[0]) && is_array($settings[0])) {
            return array_values(array_filter($settings, function($item) {
                return is_array($item);
            }));
        }
        
        // If it's a single object, wrap it in an array
        if (isset($settings['status']) || isset($settings['dynamic_values'])) {
            return [$settings];
        }
        
        // Try to extract objects with 'status' or 'dynamic_values' key
        $result = [];
        foreach ($settings as $key => $value) {
            if (is_array($value) && (isset($value['status']) || isset($value['dynamic_values']))) {
                $result[] = $value;
            }
        }
        
        // If we couldn't find any valid configs, return the original as-is
        if (empty($result)) {
            return $settings;
        }
        
        return $result;
    }

    // Handle Update Status
    // Handle Update Status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $status = isset($_POST['status']) ? $_POST['status'] : '';
            $author = isset($_POST['author']) ? $_POST['author'] : '';
            
            if ($id > 0 && $status && $author) {
                // Get current data
                $stmt = $pdo->prepare("SELECT settings FROM serenum_config WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row) {
                    $settings = json_decode($row['settings'], true) ?: [];
                    if (!is_array($settings)) $settings = [];
                    
                    // Repair settings
                    $settings = repairSettings($settings);
                    
                    // Find the configuration with matching author
                    $found = false;
                    foreach ($settings as &$config) {
                        if (is_array($config) && isset($config['author']) && $config['author'] === $author) {
                            $config['status'] = $status;
                            // Add operation_status with function name
                            $config['operation_status'] = "change_status: Status updated to '$status' for author '$author'";
                            $found = true;
                            break;
                        }
                    }
                    
                    if (!$found) {
                        // If no config found, create a new one
                        $newConfig = [
                            'author' => $author,
                            'engine' => 'automation',
                            'page' => 'none',
                            'group' => 'include',
                            'processjpgfrom' => 'freshjpgs',
                            'cardamount' => '1',
                            'schedule_date' => '',
                            'post_types' => '',
                            'include_profile_link' => false,
                            'captions_state' => 'fixed',
                            'tag' => '',
                            'type' => '',
                            'group_types' => [],
                            'group_switch' => 'no',
                            'author_caption' => [],
                            'author_account_url' => [],
                            'status' => $status,
                            'dynamic_values' => [],
                            'operation_status' => "change_status: Status set to '$status' for new author '$author'"
                        ];
                        $settings[] = $newConfig;
                    }
                    
                    // Update the database
                    $stmt = $pdo->prepare("UPDATE serenum_config SET settings = :settings WHERE id = :id");
                    $stmt->execute([
                        ':settings' => json_encode($settings),
                        ':id' => $id
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Status updated successfully!']);
                    exit;
                }
            }
            
            echo json_encode(['success' => false, 'message' => 'Invalid request - missing id, status, or author']);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit;
        }
    }

    // Handle Delete Configuration
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_config'])) {
        try {
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            $author = isset($_POST['author']) ? $_POST['author'] : '';
            
            if ($id > 0 && $author) {
                // Get current data
                $stmt = $pdo->prepare("SELECT settings FROM serenum_config WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row) {
                    $settings = json_decode($row['settings'], true) ?: [];
                    if (!is_array($settings)) $settings = [];
                    
                    $settings = repairSettings($settings);
                    
                    // Filter out the config with matching author
                    $settings = array_filter($settings, function($config) use ($author) {
                        return !(is_array($config) && isset($config['author']) && $config['author'] === $author);
                    });
                    // Re-index array
                    $settings = array_values($settings);
                    
                    // Update the database
                    $stmt = $pdo->prepare("UPDATE serenum_config SET settings = :settings WHERE id = :id");
                    $stmt->execute([
                        ':settings' => json_encode($settings),
                        ':id' => $id
                    ]);
                    
                    echo json_encode(['success' => true, 'message' => 'Configuration deleted successfully!']);
                    exit;
                }
            }
            
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit;
        }
    }

    // Handle Get Config for Editing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_config'])) {
        try {
            $author = isset($_POST['author']) ? $_POST['author'] : '';
            
            if ($author) {
                // Get current data
                $stmt = $pdo->query("SELECT id, settings, accounts_url, captions, time_orders, filters, settings_dynamic_fields FROM serenum_config ORDER BY id DESC LIMIT 1");
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings = json_decode($row['settings'], true) ?: [];
                    if (!is_array($settings)) $settings = [];
                    $settings = repairSettings($settings);
                    
                    $foundConfig = null;
                    foreach ($settings as $config) {
                        if (is_array($config) && isset($config['author']) && $config['author'] === $author) {
                            $foundConfig = $config;
                            break;
                        }
                    }
                    
                    if ($foundConfig) {
                        echo json_encode(['success' => true, 'config' => $foundConfig]);
                        exit;
                    }
                }
            }
            
            echo json_encode(['success' => false, 'message' => 'Configuration not found']);
            exit;
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit;
        }
    }

    // Fetch existing data - handle null values properly
    $settings_data = [];
    $accounts_data = [];
    $captions_data = [];
    $timeorders_data = [];
    $filters_data = [];
    $dynamic_fields_data = [];

    try {
        $stmt = $pdo->query("SELECT id, settings, accounts_url, captions, time_orders, filters, settings_dynamic_fields FROM serenum_config ORDER BY id DESC LIMIT 1");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings_data = json_decode($row['settings'], true) ?: [];
            $accounts_data = json_decode($row['accounts_url'], true) ?: [];
            $captions_data = json_decode($row['captions'], true) ?: [];
            $timeorders_data = json_decode($row['time_orders'], true) ?: [];
            $filters_data = json_decode($row['filters'], true) ?: [];
            $dynamic_fields_data = json_decode($row['settings_dynamic_fields'], true) ?: [];
            $config_id = $row['id'];
        }
    } catch(PDOException $e) {
        // Table might be empty
        $config_id = 0;
    }

    // Ensure data is always an object/array
    if (!is_array($settings_data)) $settings_data = [];
    if (!is_array($accounts_data)) $accounts_data = [];
    if (!is_array($captions_data)) $captions_data = [];
    if (!is_array($timeorders_data)) $timeorders_data = [];
    if (!is_array($filters_data)) $filters_data = [];
    if (!is_array($dynamic_fields_data)) $dynamic_fields_data = [];

    // Auto-repair settings data
    $settings_data = repairSettings($settings_data);

    // Get configurations list from settings
    $configurations = [];
    if (!empty($settings_data)) {
        if (isset($settings_data[0]) && is_array($settings_data[0])) {
            $configurations = $settings_data;
        } else if (isset($settings_data['author'])) {
            $configurations = [$settings_data];
        }
    }

    // Ensure each config has a status, engine and operation_status
    foreach ($configurations as &$config) {
        if (!isset($config['status']) || empty($config['status'])) {
            $config['status'] = 'pending';
        }
        if (!isset($config['engine']) || empty($config['engine'])) {
            $config['engine'] = 'automation';
        }
        if (!isset($config['author_caption']) || empty($config['author_caption'])) {
            $config['author_caption'] = [];
        }
        if (!isset($config['author_account_url']) || empty($config['author_account_url'])) {
            $config['author_account_url'] = [];
        }
        if (!isset($config['dynamic_values']) || empty($config['dynamic_values'])) {
            $config['dynamic_values'] = [];
        }
        if (!isset($config['operation_status']) || empty($config['operation_status'])) {
            $config['operation_status'] = '';
        }
        // Ensure author exists - if not, create from dynamic_values or use a default
        if (!isset($config['author']) || empty($config['author'])) {
            if (isset($config['dynamic_values']['author'])) {
                $config['author'] = $config['dynamic_values']['author'];
            } else {
                $config['author'] = 'Config_' . uniqid();
            }
        }
    }

    // Group configurations by status
    $grouped_configs = [
        'pending' => [],
        'completed' => [],
        'aborted' => []
    ];

    foreach ($configurations as $config) {
        $status = isset($config['status']) ? strtolower($config['status']) : 'pending';
        if (!isset($grouped_configs[$status])) {
            $grouped_configs[$status] = [];
        }
        $grouped_configs[$status][] = $config;
    }

    // Status labels for display
    $status_labels = [
        'pending' => 'Pending Operations',
        'completed' => 'Completed Operations',
        'aborted' => 'Aborted Operations'
    ];

    $status_colors = [
        'pending' => '#f6ad55',
        'completed' => '#48bb78',
        'aborted' => '#fc8181'
    ];

    // Get copied links data for use in JavaScript
    $copiedLinksData = getCopiedLinksData($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Serenum Configuration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { 
            height: 100%; 
            overflow: hidden;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #e8f4f8 0%, #d4e9f0 50%, #e8f5e9 100%);
        }
        
        input, select, textarea {
            font-size: 16px !important;
        }
        
        /* ===== HEADER ===== */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #2ecc71 0%, #1abc9c 50%, #3498db 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(46,204,113,0.3);
            height: 65px;
        }
        
        .main-header h1 { 
            font-size: 22px; 
            font-weight: 600; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .main-header .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-right: -15px;
        }
        
        .main-header .header-back {
            background: rgba(255,255,255,0.25);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 8px 20px;
            border-radius: 8px 0 0 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(5px);
            margin-right: 5px;
            height: auto;
        }
        
        .main-header .header-back:hover {
            background: rgba(255,255,255,0.4);
            transform: translateY(-2px);
        }
        
        /* ===== TABS ===== */
        .tabs {
            position: fixed;
            top: 65px;
            left: 0;
            right: 0;
            z-index: 999;
            display: none;
            background: rgba(255,255,255,0.95);
            border-bottom: 2px solid #e0f0e8;
            box-shadow: 0 2px 15px rgba(46,204,113,0.1);
            backdrop-filter: blur(10px);
            padding: 10px 0;
        }
        
        .tabs.visible {
            display: flex;
        }
        
        .tab-btn { 
            padding: 16px 25px;
            background: none; 
            border: none; 
            font-size: 15px; 
            font-weight: 500; 
            color: #5a7a8a; 
            cursor: pointer; 
            transition: all 0.3s; 
            border-bottom: 3px solid transparent;
            flex: 1;
            text-align: center;
            margin: 6px 0;
        }
        
        .tab-btn:hover { 
            background: rgba(46,204,113,0.05); 
            color: #2ecc71;
            border-radius: 8px 8px 0 0;
        }
        .tab-btn.active { 
            color: #2ecc71; 
            border-bottom-color: #2ecc71; 
            background: rgba(46,204,113,0.08);
            border-radius: 8px 8px 0 0;
        }
        
        /* ===== SCROLLABLE BODY ===== */
        .scroll-body {
            position: fixed;
            top: 65px;
            left: 0;
            right: 0;
            bottom: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px;
            padding-bottom: 100px;
            scroll-behavior: smooth;
        }
        
        .scroll-body.tabs-visible {
            top: 145px;
        }
        
        .scroll-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .scroll-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.02);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #2ecc71, #3498db);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #27ae60, #2980b9);
        }
        
        /* ===== MAIN DASHBOARD ===== */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .dashboard-title {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .dashboard-title .subtitle {
            font-size: 16px;
            font-weight: 400;
            color: #5a7a8a;
            margin-left: 10px;
        }
        
        .config-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        /* ===== ALL CARDS SAME BACKGROUND ===== */
        .config-card {
            background: linear-gradient(145deg, #ffffff, #f0faf5);
            border-radius: 20px;
            padding: 30px 25px;
            box-shadow: 0 8px 32px rgba(46,204,113,0.08), 0 2px 8px rgba(0,0,0,0.04);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            min-height: 160px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(46,204,113,0.08);
        }
        
        .config-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(46,204,113,0.03), rgba(52,152,219,0.03));
            opacity: 0;
            transition: opacity 0.4s ease;
            border-radius: 20px;
        }
        
        .config-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 40px rgba(46,204,113,0.15), 0 4px 12px rgba(0,0,0,0.06);
            border-color: rgba(46,204,113,0.15);
        }
        
        .config-card:hover::before {
            opacity: 1;
        }
        
        /* ===== ADD NEW CARD - SAME BACKGROUND ===== */
        .config-card.add-new {
            background: linear-gradient(145deg, #ffffff, #f0faf5);
            color: #2c3e50;
            min-height: 160px;
            padding: 30px 25px;
            border: 1px solid rgba(46,204,113,0.08);
        }
        
        .config-card.add-new .card-icon {
            font-size: 40px;
            margin-bottom: 8px;
            color: #2ecc71;
        }
        
        .config-card.add-new .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .config-card.add-new .card-subtitle {
            font-size: 14px;
            opacity: 0.7;
            margin-top: 5px;
            color: #5a7a8a;
        }
        
        /* ===== SETUP CARD - SAME BACKGROUND ===== */
        .config-card.setup-card {
            background: linear-gradient(145deg, #ffffff, #f0faf5);
            color: #2c3e50;
            min-height: 160px;
            padding: 30px 25px;
            border: 1px solid rgba(46,204,113,0.08);
        }
        
        .config-card.setup-card .card-icon {
            font-size: 40px;
            margin-bottom: 8px;
            color: #3498db;
        }
        
        .config-card.setup-card .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .config-card.setup-card .card-subtitle {
            font-size: 14px;
            opacity: 0.7;
            margin-top: 5px;
            color: #5a7a8a;
        }
        
        /* ===== STATUS CARDS - SAME BACKGROUND ===== */
        .config-card.status-card {
            padding: 30px 25px;
            min-height: 160px;
            background: linear-gradient(145deg, #ffffff, #f0faf5);
        }
        
        .config-card.status-card .card-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }
        
        .config-card.status-card .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .config-card.status-card .card-count {
            font-size: 14px;
            color: #5a7a8a;
            margin-top: 5px;
        }
        
        .config-card.status-card .card-status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 3px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .config-card.status-card.pending .card-status-badge {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        .config-card.status-card.successful .card-status-badge {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }
        .config-card.status-card.aborted .card-status-badge {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        .config-card.status-card.incomplete .card-status-badge {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
        
        /* ===== DETAIL VIEW ===== */
        .detail-view {
            display: none;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 0 20px 0;
        }
        
        .detail-view.active {
            display: block;
        }
        
        .detail-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .btn-back {
            padding: 10px 25px;
            background: linear-gradient(135deg, #e8f4f8, #d4e9f0);
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 500;
            color: #2c3e50;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back:hover {
            background: linear-gradient(135deg, #d4e9f0, #b8dce8);
            transform: translateX(-3px);
            box-shadow: 0 4px 12px rgba(46,204,113,0.15);
        }
        
        .detail-title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .detail-title .count {
            font-size: 16px;
            font-weight: 400;
            color: #5a7a8a;
            margin-left: 10px;
        }
        
        .config-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .config-item {
            background: linear-gradient(145deg, #ffffff, #f8fcf9);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border-left: 5px solid #2ecc71;
            transition: all 0.3s;
        }
        
        .config-item:hover {
            box-shadow: 0 8px 30px rgba(46,204,113,0.1);
            transform: translateX(5px);
        }
        
        .config-item .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .config-item .item-author {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .config-item .item-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .config-item .item-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px 20px;
            margin-top: 10px;
        }
        
        .config-item .item-details .detail-label {
            font-size: 12px;
            color: #8aa8b8;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        /* ===== CONFIG ITEM DETAILS - SCROLLABLE FIELDS ===== */
        .config-item .item-details .detail-value {
            font-size: 14px;
            color: #2c3e50;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            line-height: 1.5;
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }

        /* Custom scrollbar for detail values */
        .config-item .item-details .detail-value::-webkit-scrollbar {
            width: 4px;
        }

        .config-item .item-details .detail-value::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.05);
            border-radius: 4px;
        }

        .config-item .item-details .detail-value::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #2ecc71, #3498db);
            border-radius: 4px;
        }

        .config-item .item-details .detail-value::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #27ae60, #2980b9);
        }

        /* For Firefox */
        .config-item .item-details .detail-value {
            scrollbar-width: thin;
            scrollbar-color: #2ecc71 rgba(0,0,0,0.05);
        }

        /* Ensure the field container doesn't overflow */
        .config-item .item-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px 20px;
            margin-top: 10px;
            max-width: 100%;
            overflow: hidden;
        }

        /* Make sure long JSON strings wrap properly */
        .config-item .item-details .detail-value pre,
        .config-item .item-details .detail-value code {
            white-space: pre-wrap;
            word-wrap: break-word;
            max-width: 100%;
            font-family: monospace;
            font-size: 12px;
            background: #f8fcf9;
            padding: 4px 8px;
            border-radius: 4px;
            margin: 0;
        }

        /* Optional: Add a subtle gradient fade for long content */
        .config-item .item-details .detail-value.long-content {
            position: relative;
        }

        .config-item .item-details .detail-value.long-content::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 30px;
            background: linear-gradient(to bottom, transparent, #f8fcf9);
            pointer-events: none;
            display: none;
        }

        .config-item .item-details .detail-value.long-content.has-scroll::after {
            display: block;
        }
        
        .config-item .item-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .config-item .item-actions .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .config-item .item-actions .btn-edit-config {
            background: linear-gradient(135deg, #3498db, #2ecc71);
            color: white;
            box-shadow: 0 2px 10px rgba(46,204,113,0.2);
        }
        
        .config-item .item-actions .btn-edit-config:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(46,204,113,0.3);
        }
        
        .config-item .item-actions .btn-delete {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            box-shadow: 0 2px 10px rgba(231,76,60,0.2);
        }
        
        .config-item .item-actions .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(231,76,60,0.3);
        }
        
        .config-item .item-actions .btn-status {
            background: linear-gradient(135deg, #ecf0f1, #e0e8e5);
            color: #2c3e50;
        }
        
        .config-item .item-actions .btn-status:hover {
            background: linear-gradient(135deg, #d4e9f0, #b8dce8);
            transform: translateY(-2px);
        }
        
        /* ===== ADD NEW CONFIG ===== */
        .add-config-view {
            display: none;
        }
        
        .add-config-view.active {
            display: block;
        }
        
        /* ===== SETUP VIEW ===== */
        .setup-view {
            display: none;
        }
        
        .setup-view.active {
            display: block;
        }
        
        .setup-view .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            background: linear-gradient(145deg, #ffffff, #f5fcf8);
            border-radius: 16px;
            box-shadow: 0 4px 30px rgba(46,204,113,0.06);
            overflow: hidden;
            border: 1px solid rgba(46,204,113,0.06);
        }
        
        .setup-view .tab-content { display: none; padding: 30px; }
        .setup-view .tab-content.active { display: block; }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            background: linear-gradient(145deg, #ffffff, #f5fcf8);
            border-radius: 16px;
            box-shadow: 0 4px 30px rgba(46,204,113,0.06);
            overflow: hidden;
            padding: 30px;
            border: 1px solid rgba(46,204,113,0.06);
        }
        
        .tab-content { display: none; padding: 30px; }
        .tab-content.active { display: block; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50; }
        .form-group input, .form-group select { 
            width: 100%; 
            padding: 12px 16px; 
            border: 2px solid #e0ece8; 
            border-radius: 10px; 
            font-size: 14px; 
            transition: all 0.3s; 
            box-sizing: border-box;
            background: #fafdfc;
        }
        .form-group input:focus, .form-group select:focus { 
            border-color: #2ecc71; 
            outline: none; 
            box-shadow: 0 0 0 4px rgba(46,204,113,0.1);
            background: white;
        }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        
        .btn { 
            padding: 10px 15px; 
            margin: 0px 20px;
            border: none; 
            border-radius: 10px; 
            font-size: 14px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.3s; 
        }
        .btn-primary { 
            background: linear-gradient(135deg, #2ecc71, #1abc9c); 
            color: white; 
            box-shadow: 0 2px 12px rgba(46,204,113,0.25);
        }
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(46,204,113,0.35);
        }
        .btn-success { 
            background: linear-gradient(135deg, #2ecc71, #27ae60); 
            color: white; 
            box-shadow: 0 2px 12px rgba(46,204,113,0.25);
        }
        .btn-success:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(46,204,113,0.35);
        }
        .btn-danger { 
            background: linear-gradient(135deg, #e74c3c, #c0392b); 
            color: white; 
            box-shadow: 0 2px 12px rgba(231,76,60,0.2);
        }
        .btn-danger:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(231,76,60,0.3);
        }
        .btn-secondary { 
            background: linear-gradient(135deg, #ecf0f1, #d4e9f0); 
            color: #2c3e50; 
        }
        .btn-secondary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .btn-warning { 
            background: linear-gradient(135deg, #f39c12, #e67e22); 
            color: white; 
            box-shadow: 0 2px 12px rgba(243,156,18,0.25);
            margin: 10px 5px;
        }
        .btn-warning:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(243,156,18,0.35);
        }
        
        /* Edit mode banner */
        .edit-mode-banner {
            display: none;
            background: linear-gradient(135deg, #2ecc71, #1abc9c);
            color: white;
            padding: 15px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 4px 20px rgba(46,204,113,0.25);
        }
        
        .edit-mode-banner.visible {
            display: flex;
        }
        
        .edit-mode-banner .edit-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 16px;
        }
        
        .edit-mode-banner .edit-info strong {
            font-size: 18px;
        }
        
        .edit-mode-banner .btn-cancel-edit {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 8px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(5px);
        }
        
        .edit-mode-banner .btn-cancel-edit:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .toggle-switch { 
            position: relative; 
            display: inline-block; 
            width: 50px; 
            height: 26px; 
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { 
            position: absolute; 
            cursor: pointer; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background: #ddd; 
            transition: .4s; 
            border-radius: 26px; 
        }
        .toggle-slider:before { 
            position: absolute; 
            content: ""; 
            height: 20px; 
            width: 20px; 
            left: 3px; 
            bottom: 3px; 
            background: white; 
            transition: .4s; 
            border-radius: 50%; 
        }
        .toggle-switch input:checked + .toggle-slider { background: linear-gradient(135deg, #2ecc71, #1abc9c); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }
        
        .toggle-wrapper { display: flex; align-items: center; gap: 10px; }
        .toggle-wrapper span { font-size: 14px; color: #5a7a8a; }
        
        .item-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 12px 15px; 
            border-bottom: 1px solid #e8f0ec; 
        }
        .item-row:last-child { border-bottom: none; }
        .item-row .item-info { display: flex; gap: 20px; align-items: center; flex: 1; flex-wrap: wrap; }
        .item-row .item-info .key { font-weight: 600; color: #2c3e50; min-width: 120px; }
        .item-row .item-info .value { 
            color: #5a7a8a; 
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            flex: 1;
        }
        
        .group-input-container { display: flex; gap: 10px; margin-top: 10px; }
        .group-input-container input { flex: 1; }
        
        .group-item { 
            background: linear-gradient(135deg, #e8f4f8, #d4e9f0); 
            padding: 8px 15px; 
            border-radius: 20px; 
            display: inline-block; 
            margin: 5px; 
            color: #2c3e50;
        }
        .group-item .remove-group { cursor: pointer; margin-left: 8px; color: #e74c3c; font-weight: 600; }
        
        .time-input-group { display: flex; gap: 10px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
        .time-input-group input[type="number"] { width: 80px; padding: 10px; }
        .time-input-group select { width: 100px; padding: 10px; }
        
        .caption-item { 
            background: #f5fcf8; 
            padding: 12px 15px; 
            border-radius: 10px; 
            margin-bottom: 10px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border: 1px solid #e0ece8;
        }
        .caption-item:hover { background: #e8f4f0; border-color: #2ecc71; }
        .caption-item .caption-content { flex: 1; }
        .caption-item .caption-author { font-weight: 600; color: #2ecc71; margin-right: 15px; }
        
        .caption-entry {
            background: #f5fcf8;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
            border-left: 4px solid #2ecc71;
            border: 1px solid #e0ece8;
        }
        .caption-entry .caption-id {
            font-weight: 600;
            color: #2ecc71;
            margin-right: 10px;
        }
        
        /* ===== SAVE BUTTONS ===== */
        .save-btn-container { 
            position: fixed; 
            bottom: 30px; 
            right: 30px; 
            z-index: 1000; 
            display: none;
            gap: 15px;
        }
        
        .save-btn-container.visible {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .save-btn-container .btn { 
            padding: 15px 40px; 
            font-size: 18px; 
            border-radius: 12px;
            margin: 0;
            width: 100%;
            min-width: 200px;
        }
        
        .save-btn-container .btn-save-setup {
            background: linear-gradient(135deg, #3498db, #2ecc71);
            color: white;
            box-shadow: 0 4px 30px rgba(52,152,219,0.4);
        }
        
        .save-btn-container .btn-save-setup:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 40px rgba(52,152,219,0.5);
        }
        
        .save-btn-container .btn-save-config {
            background: linear-gradient(135deg, #2ecc71, #1abc9c);
            color: white;
            box-shadow: 0 4px 30px rgba(46,204,113,0.4);
        }
        
        .save-btn-container .btn-save-config:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 40px rgba(46,204,113,0.5);
        }
        
        .schedule-time-group { 
            display: flex; 
            gap: 10px; 
            align-items: center; 
            flex-wrap: wrap; 
        }
        .schedule-time-group input[type="number"] { width: 60px; }
        .schedule-time-group select { width: 80px; }
        
        .time-display-24h { 
            background: linear-gradient(135deg, #e8f4f8, #d4e9f0); 
            padding: 8px 15px; 
            border-radius: 8px; 
            border: 1px solid #2ecc71; 
            color: #2ecc71; 
            font-weight: 600; 
            min-width: 80px; 
            text-align: center; 
        }
        
        .timeorder-item {
            background: #f5fcf8;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid #e0ece8;
        }
        .timeorder-item .timeorder-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .timeorder-item .timeorder-header .key {
            font-weight: 600;
            color: #2c3e50;
            font-size: 16px;
        }
        .timeorder-item .time-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
            margin-top: 10px;
        }
        .timeorder-item .time-list .time-badge {
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e0ece8;
            font-size: 14px;
            text-align: center;
            color: #2c3e50;
        }
        .timeorder-item .time-list .time-badge.header {
            background: linear-gradient(135deg, #2ecc71, #1abc9c);
            color: white;
            font-weight: 600;
            border: none;
        }
        .timeorder-item .add-time-area {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e0ece8;
        }
        
        .schedule-date-input {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
            -webkit-appearance: none;
            appearance: none;
        }
        
        /* ===== MODAL ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-box {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
            border: 1px solid rgba(46,204,113,0.1);
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        
        .modal-box .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #2c3e50;
        }
        
        .modal-box .modal-message {
            font-size: 16px;
            color: #5a7a8a;
            margin-bottom: 20px;
            line-height: 1.6;
            word-wrap: break-word;
        }
        
        .modal-box .modal-content {
            margin-bottom: 20px;
        }
        
        .modal-box .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .modal-box .modal-buttons .btn {
            min-width: 80px;
            border-radius: 10px;
        }
        
        .modal-box textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0ece8;
            border-radius: 10px;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
            transition: border-color 0.3s;
            background: #fafdfc;
        }
        
        .modal-box textarea:focus {
            border-color: #2ecc71;
            outline: none;
            box-shadow: 0 0 0 4px rgba(46,204,113,0.1);
        }
        
        /* ===== DYNAMIC FIELDS ===== */
        .dynamic-field-item {
            background: #f5fcf8;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid #e0ece8;
            transition: all 0.3s;
        }
        
        .dynamic-field-item:hover {
            border-color: #2ecc71;
            box-shadow: 0 2px 12px rgba(46,204,113,0.08);
        }
        
        .dynamic-field-item .field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .dynamic-field-item .field-name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 16px;
        }
        
        .dynamic-field-item .field-type-badge {
            background: linear-gradient(135deg, #3498db, #2ecc71);
            color: white;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .dynamic-field-item .default-values {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }
        
        .dynamic-field-item .default-values .value-tag {
            background: white;
            padding: 4px 12px;
            border-radius: 12px;
            border: 1px solid #e0ece8;
            font-size: 13px;
            color: #2c3e50;
        }
        
        /* ===== DATE-TIME INPUT STYLES ===== */
        .datetime-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }

        .datetime-group .date-input {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            padding: 12px 16px !important;
            border: 2px solid #e0ece8 !important;
            border-radius: 10px !important;
            font-size: 14px !important;
            transition: all 0.3s !important;
            box-sizing: border-box !important;
            background: #fafdfc !important;
            display: block !important;
            -webkit-appearance: none !important;
            appearance: none !important;
            height: 50px;
        }

        .datetime-group .date-input:focus {
            border-color: #2ecc71 !important;
            outline: none !important;
            box-shadow: 0 0 0 4px rgba(46,204,113,0.1) !important;
            background: white !important;
        }

        .datetime-group .time-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        .datetime-group .time-group input[type="number"] {
            width: 60px !important;
            min-width: 45px !important;
            max-width: 80px !important;
            padding: 10px !important;
            border: 2px solid #e0ece8 !important;
            border-radius: 10px !important;
            font-size: 14px !important;
            transition: all 0.3s !important;
            background: #fafdfc !important;
            box-sizing: border-box !important;
        }

        .datetime-group .time-group input[type="number"]:focus {
            border-color: #2ecc71 !important;
            outline: none !important;
            box-shadow: 0 0 0 4px rgba(46,204,113,0.1) !important;
            background: white !important;
        }

        .datetime-group .time-group select {
            padding: 10px !important;
            border: 2px solid #e0ece8 !important;
            border-radius: 10px !important;
            font-size: 14px !important;
            transition: all 0.3s !important;
            background: #fafdfc !important;
            width: 80px !important;
            min-width: 70px !important;
            box-sizing: border-box !important;
        }

        .datetime-group .time-group select:focus {
            border-color: #2ecc71 !important;
            outline: none !important;
            box-shadow: 0 0 0 4px rgba(46,204,113,0.1) !important;
            background: white !important;
        }
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .config-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .tab-btn { 
                padding: 12px 15px; 
                font-size: 13px; 
            }
            .schedule-time-group { flex-direction: column; align-items: stretch; }
            .main-header h1 { font-size: 17px; }
            .main-header { padding: 10px 15px; height: 55px; }
            .main-header .header-back {
                padding: 8px 15px;
                margin-right: 5px;
                height: auto;
            }
            .scroll-body { top: 55px; }
            .scroll-body.tabs-visible { top: 125px; }
            .tabs { 
                top: 55px;
                padding: 6px 0;
            }
            .schedule-date-input { max-width: 100%; }
            .timeorder-item .time-list {
                grid-template-columns: 1fr;
            }
            .modal-box { max-width: 95%; padding: 20px; }
            .time-input-group input[type="number"] { width: 60px; }
            .time-input-group select { width: 80px; }
            .dashboard-title { font-size: 22px; flex-direction: column; align-items: flex-start; }
            .config-item .item-details {
                grid-template-columns: 1fr;
            }
            .config-item .item-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .detail-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .save-btn-container { 
                bottom: 15px; 
                right: 15px;
                gap: 10px;
            }
            .save-btn-container .btn { 
                padding: 12px 25px; 
                font-size: 15px;
                min-width: 150px;
            }
            .edit-mode-banner {
                flex-direction: column;
                align-items: flex-start;
            }
            .container {
                padding: 15px;
            }
            .item-row .item-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .item-row .item-info .key {
                min-width: auto;
            }
            
            .datetime-group {
                width: 100% !important;
                max-width: 100% !important;
                overflow: hidden !important;
            }
            
            .datetime-group .date-input {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                font-size: 16px !important;
                padding: 12px 14px !important;
                box-sizing: border-box !important;
            }
            
            .datetime-group .time-group {
                width: 100% !important;
                max-width: 100% !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 50px !important;
                min-width: 40px !important;
                padding: 8px !important;
                font-size: 14px !important;
                flex: 0 1 auto !important;
            }
            
            .datetime-group .time-group select {
                width: 70px !important;
                min-width: 60px !important;
                padding: 8px !important;
                font-size: 14px !important;
            }
            
            .submit-as-select {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .submit-as-select select {
                width: 100%;
                min-width: unset;
            }
        }

        @media (max-width: 480px) {
            .config-card {
                min-height: 130px;
                padding: 20px !important;
            }
            .config-card .card-icon { font-size: 28px; }
            .config-card .card-title { font-size: 17px; }
            .save-btn-container .btn { 
                padding: 10px 20px; 
                font-size: 13px;
                min-width: 120px;
            }
            
            .datetime-group {
                width: 100% !important;
                max-width: 100% !important;
                overflow: hidden !important;
            }
            
            .datetime-group .date-input {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                font-size: 16px !important;
                padding: 10px 12px !important;
                box-sizing: border-box !important;
            }
            
            .datetime-group .time-group {
                width: 100% !important;
                max-width: 100% !important;
                gap: 6px !important;
                flex-wrap: wrap !important;
                justify-content: flex-start !important;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 45px !important;
                min-width: 35px !important;
                padding: 8px !important;
                font-size: 14px !important;
                flex: 0 1 auto !important;
            }
            
            .datetime-group .time-group select {
                width: 60px !important;
                min-width: 55px !important;
                padding: 8px !important;
                font-size: 14px !important;
            }
            
            .time-format-select {
                flex-wrap: wrap !important;
                gap: 8px !important;
            }
            
            .time-format-select select {
                width: 100% !important;
                min-width: unset !important;
            }
        }
        
        /* ===== LIST INPUT STYLES ===== */
        .list-input-container {
            display: flex;
            gap: 10px;
            margin-top: 5px;
            flex-wrap: wrap;
        }
        
        .list-input-container input {
            flex: 1;
            min-width: 150px;
        }
        
        .list-values {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }
        
        .list-values .value-tag {
            background: linear-gradient(135deg, #e8f4f8, #d4e9f0);
            padding: 4px 12px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #2c3e50;
            border: 1px solid #c8dce8;
        }
        
        .list-values .value-tag .remove-value {
            cursor: pointer;
            color: #e74c3c;
            font-weight: 600;
            font-size: 14px;
            line-height: 1;
        }
        
        .list-values .value-tag .remove-value:hover {
            color: #c0392b;
        }
        
        /* ===== SOURCE SELECT ===== */
        .source-select-container {
            margin-top: 10px;
        }
        
        .source-select-container select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e0ece8;
            border-radius: 10px;
            font-size: 14px;
            background: #fafdfc;
        }
        
        .source-select-container select:focus {
            border-color: #2ecc71;
            outline: none;
            box-shadow: 0 0 0 4px rgba(46,204,113,0.1);
        }
        
        
        /* ===== TIME FORMAT SELECT ===== */
        .time-format-select {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
            padding: 8px 12px;
            background: #f8fcf9;
            border-radius: 8px;
            border: 1px solid #e0ece8;
        }
        
        .time-format-select label {
            margin-bottom: 0 !important;
            font-weight: 500 !important;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .time-format-select select {
            padding: 6px 12px;
            border: 2px solid #e0ece8;
            border-radius: 6px;
            font-size: 14px;
            background: #fafdfc;
            width: auto;
            min-width: 80px;
        }
        
        .time-format-select select:focus {
            border-color: #2ecc71;
            outline: none;
        }

        /* ===== SUBMIT AS SELECT ===== */
        .submit-as-select {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
            padding: 8px 12px;
            background: #f0faf5;
            border-radius: 8px;
            border: 1px solid #c8e0d8;
        }
        
        .submit-as-select label {
            margin-bottom: 0 !important;
            font-weight: 500 !important;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .submit-as-select select {
            padding: 6px 12px;
            border: 2px solid #c8e0d8;
            border-radius: 6px;
            font-size: 14px;
            background: #fafdfc;
            width: auto;
            min-width: 180px;
        }
        
        .submit-as-select select:focus {
            border-color: #2ecc71;
            outline: none;
        }
        
        .submit-as-preview {
            margin-top: 5px;
            padding: 6px 12px;
            background: #f8fcf9;
            border-radius: 6px;
            border: 1px dashed #c8e0d8;
            font-size: 12px;
            color: #5a7a8a;
            font-family: monospace;
            word-break: break-all;
            max-height: 100px;
            overflow-y: auto;
        }
        
        /* ===== OPERATION STATUS BADGE ===== */
        .operation-status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .operation-status-badge.running {
            background: #48bb78;
            color: white;
        }
        
        .operation-status-badge.paused {
            background: #f6ad55;
            color: white;
        }
        
        .operation-status-badge.stopped {
            background: #fc8181;
            color: white;
        }
        
        .operation-status-badge.completed {
            background: #4299e1;
            color: white;
        }
        
        .operation-status-badge.failed {
            background: #e53e3e;
            color: white;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .config-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .tab-btn { 
                padding: 12px 15px; 
                font-size: 13px; 
            }
            .schedule-time-group { flex-direction: column; align-items: stretch; }
            .main-header h1 { font-size: 17px; }
            .main-header { padding: 10px 15px; height: 55px; }
            .main-header .header-back {
                padding: 8px 15px;
                margin-right: 5px;
                height: auto;
            }
            .scroll-body { top: 55px; }
            .scroll-body.tabs-visible { top: 125px; }
            .tabs { 
                top: 55px;
                padding: 6px 0;
            }
            .schedule-date-input { max-width: 100%; }
            .timeorder-item .time-list {
                grid-template-columns: 1fr;
            }
            .modal-box { max-width: 95%; padding: 20px; }
            .time-input-group input[type="number"] { width: 60px; }
            .time-input-group select { width: 80px; }
            .dashboard-title { font-size: 22px; flex-direction: column; align-items: flex-start; }
            .config-item .item-details {
                grid-template-columns: 1fr;
            }
            .config-item .item-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .detail-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .save-btn-container { 
                bottom: 15px; 
                right: 15px;
                gap: 10px;
            }
            .save-btn-container .btn { 
                padding: 12px 25px; 
                font-size: 15px;
                min-width: 150px;
            }
            .edit-mode-banner {
                flex-direction: column;
                align-items: flex-start;
            }
            .container {
                padding: 15px;
            }
            .item-row .item-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            .item-row .item-info .key {
                min-width: auto;
            }
            
            .datetime-group .time-group {
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 50px;
            }
            .datetime-group .time-group select {
                width: 70px;
            }
            
            .submit-as-select {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .submit-as-select select {
                width: 100%;
                min-width: unset;
            }
        }
        
        @media (max-width: 480px) {
            .config-card {
                min-height: 130px;
                padding: 20px !important;
            }
            .config-card .card-icon { font-size: 28px; }
            .config-card .card-title { font-size: 17px; }
            .save-btn-container .btn { 
                padding: 10px 20px; 
                font-size: 13px;
                min-width: 120px;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 50px;
            }
            .datetime-group .time-group select {
                width: 70px;
            }
        }
    </style>
</head>
<body>
    <!-- ===== HEADER ===== -->
    <div class="main-header" id="mainHeader">
        <div style="display:flex; align-items:center; gap:15px;">
            <button class="header-back" id="backToDashboardBtn" onclick="location.href='serenum.php'" style="display:none; background:rgba(255,255,255,0.25); border:1px solid rgba(255,255,255,0.2); color:white; padding:8px 20px; border-radius:8px; cursor:pointer; font-size:14px; transition:all 0.3s; backdrop-filter:blur(5px);">
                ← Back to Dashboard
            </button>
            <h1 style="font-size:22px; font-weight:600; text-shadow:0 2px 4px rgba(0,0,0,0.1);">Serenum Configuration</h1>
        </div>
        <div class="header-actions" style="display:flex; gap:10px; align-items:center;">
            <button onclick="logout()" style="padding:8px 20px; background:linear-gradient(135deg, #e74c3c, #c0392b); color:white; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:all 0.3s; box-shadow:0 2px 10px rgba(231,76,60,0.3);">
                Logout
            </button>
        </div>
    </div>
    
    <!-- ===== TABS ===== -->
    <div class="tabs" id="configTabs">
        <button class="tab-btn active" onclick="switchTab('accounts', this)">Accounts URL</button>
        <button class="tab-btn" onclick="switchTab('captions', this)">Captions</button>
        <button class="tab-btn" onclick="switchTab('timeorders', this)">Time Orders</button>
        <button class="tab-btn" onclick="switchTab('filters', this)">Filters</button>
        <button class="tab-btn" onclick="switchTab('dynamicfields', this)">Dynamic Fields</button>
    </div>
    
    <!-- ===== SCROLLABLE BODY ===== -->
    <div class="scroll-body" id="scrollBody">
        
        <!-- ===== MAIN DASHBOARD ===== -->
        <div id="dashboardView" class="dashboard-container">
            <div class="dashboard-title">
                Configuration Dashboard
                <span class="subtitle">Manage your automation configurations</span>
            </div>
            
            <div class="config-grid">
                <!-- JPGS Vault Card -->
                <div class="config-card vault-card" onclick="location.href='jpgsvault.php'" style="background: linear-gradient(145deg, #ffffff, #f0faf5); color: #2c3e50; min-height: 160px; padding: 30px 25px; border: 1px solid rgba(46,204,113,0.08); cursor: pointer; transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1); border-radius: 20px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; position: relative; overflow: hidden;">
                    <div style="position:absolute; top:12px; right:12px; padding:3px 14px; border-radius:20px; font-size:11px; font-weight:600; color:white; background: linear-gradient(135deg, #6366f1, #8b5cf6); box-shadow:0 2px 8px rgba(99,102,241,0.3);">Live</div>
                    <div style="font-size:40px; margin-bottom:8px; color: #6366f1;">🖼️</div>
                    <div style="font-size:20px; font-weight:600; color:#2c3e50;">JPGS Vault</div>
                    <div style="font-size:14px; opacity:0.7; margin-top:5px; color:#5a7a8a;">Secure image gallery with bulk upload and folder management</div>
                </div>
                
                <!-- Setup Config Card -->
                <div class="config-card setup-card" onclick="showSetupView()">
                    <div class="card-icon">⚙️</div>
                    <div class="card-title">Setup Config</div>
                    <div class="card-subtitle">Configure accounts, captions & time orders</div>
                </div>
                
                <!-- Add New Config Card -->
                <div class="config-card add-new" onclick="showAddConfig()">
                    <div class="card-icon">+</div>
                    <div class="card-title">Add New Configuration</div>
                    <div class="card-subtitle">Create a new automation setup</div>
                </div>
                
                <!-- Status Cards -->
                <?php foreach (['pending', 'completed', 'aborted'] as $status): ?>
                    <?php 
                        $count = count($grouped_configs[$status] ?? []);
                        $label = $status_labels[$status] ?? ucfirst($status);
                        $color = $status_colors[$status] ?? '#a0aec0';
                        $icon = ($status == 'pending') ? '⏳' : (($status == 'completed') ? '✅' : '❌');
                    ?>
                    <div class="config-card status-card <?php echo $status; ?>" onclick="showDetailView('<?php echo $status; ?>')">
                        <div class="card-status-badge"><?php echo ucfirst($status); ?></div>
                        <div class="card-icon"><?php echo $icon; ?></div>
                        <div class="card-title"><?php echo $label; ?></div>
                        <div class="card-count"><?php echo $count; ?> configuration(s)</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- ===== DETAIL VIEW ===== -->
        <div id="detailView" class="detail-view">
            <div class="detail-header">
                <button class="btn-back" onclick="showDashboard()">← Back</button>
                <div class="detail-title" id="detailTitle">Pending Operations <span class="count" id="detailCount"></span></div>
            </div>
            <div class="config-list" id="detailList"></div>
        </div>
        
        <!-- ===== ADD NEW CONFIG ===== -->
        <div id="addConfigView" class="add-config-view">
            <!-- Edit Mode Banner -->
            <div class="edit-mode-banner" id="editModeBanner">
                <div class="edit-info">
                    <span>✏️</span>
                    <span>Editing: <strong id="editAuthorNameDisplay"></strong></span>
                </div>
                <button class="btn-cancel-edit" onclick="cancelEditMode()">Cancel Editing</button>
            </div>
            
            <div class="container">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                    <h2 style="color: #333;" id="settingsTabTitle">Set Configuration</h2>
                </div>
                
                
                <!-- ===== DYNAMIC FIELDS RENDER AREA ===== -->
                <div id="dynamicFieldsRenderArea">
                    <div id="dynamicFieldsContainer"></div>
                </div>
            </div>
        </div>
        
        <!-- ===== SETUP VIEW ===== -->
        <div id="setupView" class="setup-view">
            <div class="container">
                <!-- Accounts URL Tab -->
                <div id="accounts-tab" class="tab-content active">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: #333;">Accounts URL</h2>
                        <button class="btn btn-primary" onclick="showAddAccount()">Add New Account</button>
                    </div>
                    
                    <div id="add_account_form" style="display: none; background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px;">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Account Name</label>
                                <input type="text" id="new_account_name" placeholder="Enter account name...">
                            </div>
                            <div class="form-group">
                                <label>Account URL</label>
                                <input type="url" id="new_account_url" placeholder="Enter account URL...">
                            </div>
                        </div>
                        <button class="btn btn-success" onclick="addAccount()">Add Account</button>
                        <button class="btn btn-secondary" onclick="hideAddAccount()" style="margin-left: 10px;">Cancel</button>
                    </div>
                    
                    <div id="accounts_list"></div>
                </div>
                
                <!-- Captions Tab -->
                <div id="captions-tab" class="tab-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: #333;">Captions</h2>
                        <button class="btn btn-primary" onclick="showAddCaption()">Add New Author Captions</button>
                    </div>
                    
                    <div id="add_caption_form" style="display: none; background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label>Select Author</label>
                            <select id="caption_author_select">
                                <option value="">Select Author</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Caption Text</label>
                            <textarea id="new_caption_text" rows="3" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; resize:vertical;" placeholder="Enter caption text..."></textarea>
                        </div>
                        <button class="btn btn-success" onclick="addCaption()">Add Caption</button>
                        <button class="btn btn-secondary" onclick="hideAddCaption()" style="margin-left: 10px;">Cancel</button>
                    </div>
                    
                    <div id="captions_list"></div>
                </div>
                
                <!-- Time Orders Tab -->
                <div id="timeorders-tab" class="tab-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: #333;">Time Orders</h2>
                        <button class="btn btn-primary" onclick="showAddTimeOrder()">Add New Time Order</button>
                    </div>
                    
                    <div id="timeorders_list"></div>
                </div>
            
                <!-- Filters Tab -->
                <div id="filters-tab" class="tab-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: #333;">Filters</h2>
                    </div>
                    
                    <div class="form-group">
                        <label>Add Filter</label>
                        <div class="group-input-container">
                            <input type="text" id="filter_input" placeholder="Enter filter name...">
                            <button class="btn btn-primary" onclick="addFilter()">Add</button>
                        </div>
                        <div id="filters_list" style="margin-top: 10px;"></div>
                    </div>
                </div>
                
                <!-- Dynamic Fields Tab -->
                <div id="dynamicfields-tab" class="tab-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="color: #333;">Dynamic Fields</h2>
                        <button class="btn btn-primary" onclick="showAddDynamicFieldModal()">Add New Field</button>
                    </div>
                    
                    <div id="dynamic_fields_list"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ===== SAVE BUTTONS ===== -->
    <div class="save-btn-container" id="saveBtnContainer">
        <button class="btn btn-save-config" id="saveConfigBtn" onclick="saveConfig()" style="display:none;">💾 Save Configuration</button>
        <button class="btn btn-save-setup" id="saveSetupBtn" onclick="saveSetup()" style="display:none;">💾 Save Setup</button>
    </div>
    
    <!-- ===== MODAL ===== -->
    <div class="modal-overlay" id="customModal">
        <div class="modal-box">
            <div class="modal-title" id="modalTitle">Notification</div>
            <div class="modal-message" id="modalMessage">Message here</div>
            <div class="modal-content"></div>
            <div class="modal-buttons">
                <button class="btn btn-primary" onclick="closeModal()">OK</button>
            </div>
        </div>
    </div>

<script>
    // ===== DATA =====
    let configData = {
        settings: <?php echo json_encode($settings_data); ?>,
        accounts: <?php echo json_encode($accounts_data); ?>,
        captions: <?php echo json_encode($captions_data); ?>,
        timeorders: <?php echo json_encode($timeorders_data); ?>,
        filters: <?php echo json_encode($filters_data); ?>,
        dynamic_fields: <?php echo json_encode($dynamic_fields_data); ?>
    };
    
    // ===== COPIED LINKS DATA FROM JPGSVAULT =====
    let copiedLinksData = <?php echo json_encode($copiedLinksData); ?>;
    
    let configId = <?php echo $config_id ?? 0; ?>;
    let isEditMode = false;
    let editingAuthor = '';
    let editingDynamicFieldKey = null;
    let editingIndex = null;
    
    // Store time format preference per field
    let timeFormatPreferences = {};
    
    // Store submit as preference per field
    let submitAsPreferences = {};
    
    // Store list values for modal
    let _newListValues = [];
    
    // Ensure proper data types
    if (!configData.accounts || typeof configData.accounts !== 'object' || Array.isArray(configData.accounts)) {
        configData.accounts = {};
    }
    if (!configData.captions || !Array.isArray(configData.captions)) {
        configData.captions = [];
    }
    if (!configData.timeorders || typeof configData.timeorders !== 'object' || Array.isArray(configData.timeorders)) {
        configData.timeorders = {};
    }
    if (!configData.settings || !Array.isArray(configData.settings) && !configData.settings.author) {
        configData.settings = [];
    }
    if (!configData.dynamic_fields || typeof configData.dynamic_fields !== 'object' || Array.isArray(configData.dynamic_fields)) {
        configData.dynamic_fields = {};
    }
    
    let currentTimeOrderName = '';
    let editingTimeOrderKey = null;
    let editingCaptionAuthor = null;
    let _newTimeList = [];
    let _newDefaultValues = [];
    
    // ===== VIEW NAVIGATION =====
    function showDashboard() {
        clearEditMode();
        document.getElementById('dashboardView').style.display = 'block';
        document.getElementById('detailView').classList.remove('active');
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('addConfigView').classList.remove('active');
        document.getElementById('addConfigView').style.display = 'none';
        document.getElementById('setupView').classList.remove('active');
        document.getElementById('setupView').style.display = 'none';
        document.getElementById('configTabs').classList.remove('visible');
        document.getElementById('saveBtnContainer').classList.remove('visible');
        document.getElementById('saveConfigBtn').style.display = 'none';
        document.getElementById('saveSetupBtn').style.display = 'none';
        document.getElementById('backToDashboardBtn').style.display = 'none';
        document.getElementById('scrollBody').classList.remove('tabs-visible');
        document.getElementById('editModeBanner').classList.remove('visible');
    }
    
    function showDetailView(status) {
        clearEditMode();
        document.getElementById('dashboardView').style.display = 'none';
        document.getElementById('addConfigView').classList.remove('active');
        document.getElementById('addConfigView').style.display = 'none';
        document.getElementById('setupView').classList.remove('active');
        document.getElementById('setupView').style.display = 'none';
        document.getElementById('configTabs').classList.remove('visible');
        document.getElementById('saveBtnContainer').classList.remove('visible');
        document.getElementById('saveConfigBtn').style.display = 'none';
        document.getElementById('saveSetupBtn').style.display = 'none';
        document.getElementById('backToDashboardBtn').style.display = 'block';
        document.getElementById('scrollBody').classList.remove('tabs-visible');
        document.getElementById('editModeBanner').classList.remove('visible');
        
        const view = document.getElementById('detailView');
        view.style.display = 'block';
        view.classList.add('active');
        
        const statusLabels = {
            'pending': 'Pending Operations',
            'completed': 'Completed Operations',
            'aborted': 'Aborted Operations'
        };
        
        document.getElementById('detailTitle').innerHTML = (statusLabels[status] || status) + ' <span class="count" id="detailCount"></span>';
        
        // Get configurations for this status
        const configs = getConfigsByStatus(status);
        document.getElementById('detailCount').textContent = configs.length + ' configuration(s)';
        
        renderConfigList(configs);
    }
    
    function showAddConfig() {
        clearEditMode();
        document.getElementById('dashboardView').style.display = 'none';
        document.getElementById('detailView').classList.remove('active');
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('setupView').classList.remove('active');
        document.getElementById('setupView').style.display = 'none';
        document.getElementById('addConfigView').classList.add('active');
        document.getElementById('addConfigView').style.display = 'block';
        document.getElementById('configTabs').classList.remove('visible');
        document.getElementById('saveBtnContainer').classList.add('visible');
        document.getElementById('saveConfigBtn').style.display = 'block';
        document.getElementById('saveSetupBtn').style.display = 'none';
        document.getElementById('backToDashboardBtn').style.display = 'block';
        document.getElementById('settingsTabTitle').textContent = 'Set Configuration';
        document.getElementById('scrollBody').classList.remove('tabs-visible');
        document.getElementById('editModeBanner').classList.remove('visible');
        
        loadAllData();
        renderDynamicFields();
    }
        
    function showSetupView() {
        clearEditMode();
        document.getElementById('dashboardView').style.display = 'none';
        document.getElementById('detailView').classList.remove('active');
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('addConfigView').classList.remove('active');
        document.getElementById('addConfigView').style.display = 'none';
        document.getElementById('setupView').classList.add('active');
        document.getElementById('setupView').style.display = 'block';
        document.getElementById('configTabs').classList.add('visible');
        document.getElementById('saveBtnContainer').classList.add('visible');
        document.getElementById('saveConfigBtn').style.display = 'none';
        document.getElementById('saveSetupBtn').style.display = 'block';
        document.getElementById('backToDashboardBtn').style.display = 'block';
        document.getElementById('scrollBody').classList.add('tabs-visible');
        document.getElementById('editModeBanner').classList.remove('visible');
        
        // Switch to accounts tab by default
        switchTab('accounts', document.querySelector('.tab-btn'));
        
        loadSetupData();
    }
    
    function showEditConfig(author, index) {
        // If index is provided, use it
        if (index !== undefined && index !== null) {
            editConfigByIndex(index);
            return;
        }
        
        // Fallback: try to find by author (for backward compatibility)
        const settings = configData.settings;
        let foundIndex = -1;
        
        if (Array.isArray(settings)) {
            for (let i = 0; i < settings.length; i++) {
                if (settings[i] && typeof settings[i] === 'object') {
                    let configAuthor = settings[i].author || (settings[i].dynamic_values && settings[i].dynamic_values.author);
                    if (configAuthor === author) {
                        foundIndex = i;
                        break;
                    }
                }
            }
        }
        
        if (foundIndex === -1) {
            showModal('Error', 'Configuration not found for author: ' + author);
            return;
        }
        
        editConfigByIndex(foundIndex);
    }

    function editConfigByIndex(index) {
        const settings = configData.settings;
        
        if (!Array.isArray(settings) || index < 0 || index >= settings.length) {
            showModal('Error', 'Invalid configuration index.');
            return;
        }
        
        const config = settings[index];
        let author = 'Unknown Author';
        
        if (config && typeof config === 'object') {
            author = config.author || (config.dynamic_values && config.dynamic_values.author) || 'Unknown';
        }
        
        isEditMode = true;
        editingAuthor = author;
        window._editingIndex = index;
        
        document.getElementById('dashboardView').style.display = 'none';
        document.getElementById('detailView').classList.remove('active');
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('setupView').classList.remove('active');
        document.getElementById('setupView').style.display = 'none';
        document.getElementById('addConfigView').classList.add('active');
        document.getElementById('addConfigView').style.display = 'block';
        document.getElementById('configTabs').classList.remove('visible');
        document.getElementById('saveBtnContainer').classList.add('visible');
        document.getElementById('saveConfigBtn').style.display = 'block';
        document.getElementById('saveSetupBtn').style.display = 'none';
        document.getElementById('backToDashboardBtn').style.display = 'block';
        document.getElementById('editAuthorNameDisplay').textContent = author;
        document.getElementById('settingsTabTitle').textContent = 'Edit Configuration - ' + author;
        document.getElementById('scrollBody').classList.remove('tabs-visible');
        document.getElementById('editModeBanner').classList.add('visible');
        
        // Load the config data
        if (config && config.dynamic_values) {
            loadDynamicValues(config.dynamic_values);
        }
        
        loadAccounts();
        loadCaptions();
        loadTimeOrders();
        loadFilters();
        loadCaptionAuthors();
        renderDynamicFields();
    }
    
    function cancelEditMode() {
        window._editingIndex = null;
        showDashboard();
    }
    
    function clearEditMode() {
        isEditMode = false;
        editingAuthor = '';
        window._editingIndex = null;
        document.getElementById('settingsTabTitle').textContent = 'Set Configuration';
        document.getElementById('editModeBanner').classList.remove('visible');
    }
    
    function loadSetupData() {
        loadAccounts();
        loadCaptions();
        loadTimeOrders();
        loadFilters();
        loadCaptionAuthors();
        loadDynamicFields();
    }
    function loadConfigForEdit(author) {
        // Find the config in the data
        const settings = configData.settings;
        let foundConfig = null;
        
        if (Array.isArray(settings)) {
            for (let config of settings) {
                if (config && typeof config === 'object') {
                    // Check if config matches by author or by dynamic_values.author
                    let configAuthor = config.author || (config.dynamic_values && config.dynamic_values.author);
                    if (configAuthor === author) {
                        foundConfig = config;
                        break;
                    }
                }
            }
        } else if (settings && typeof settings === 'object') {
            let configAuthor = settings.author || (settings.dynamic_values && settings.dynamic_values.author);
            if (configAuthor === author) {
                foundConfig = settings;
            }
        }
        
        if (foundConfig) {
            // Load dynamic values
            if (foundConfig.dynamic_values && typeof foundConfig.dynamic_values === 'object') {
                loadDynamicValues(foundConfig.dynamic_values);
            }
            
            // Load accounts, captions, time orders for the dropdowns
            loadAccounts();
            loadCaptions();
            loadTimeOrders();
            loadFilters();
            loadCaptionAuthors();
        } else {
            // If config not found, create a temporary one with the author from dynamic_values
            showModal('Error', 'Configuration not found for author: ' + author);
        }
    }
    
    function loadDynamicValues(dynamicValues) {
        // This will be called after dynamic fields are rendered
        // We'll set the values in the renderDynamicFields function
        window._dynamicValuesToLoad = dynamicValues;
    }
    
    function getConfigsByStatus(status) {
        const settings = configData.settings;
        const configs = [];
        
        if (!settings || !Array.isArray(settings)) return configs;
        
        settings.forEach(config => {
            if (config && typeof config === 'object') {
                // Don't require author - display any config with or without author
                const configStatus = (config.status || 'pending').toLowerCase();
                if (configStatus === status) {
                    configs.push(config);
                }
            }
        });
        
        return configs;
    }
    
    function renderConfigList(configs) {
        const container = document.getElementById('detailList');
        container.innerHTML = '';
        
        if (configs.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 40px; background: white; border-radius: 12px;">
                    <p style="color: #718096; font-size: 18px;">No configurations found with this status.</p>
                </div>
            `;
            return;
        }
        
        configs.forEach((config, index) => {
            const div = document.createElement('div');
            div.className = 'config-item';
            
            const statusColor = {
                'pending': '#f6ad55',
                'completed': '#48bb78',
                'aborted': '#fc8181',
                'incomplete': '#a0aec0'
            };
            
            const statusLabel = {
                'pending': 'Pending',
                'completed': 'completed',
                'aborted': 'Aborted',
                'incomplete': 'Incomplete'
            };
            
            const status = (config.status || 'pending').toLowerCase();
            
            // Get author from dynamic_values or use a fallback
            let author = 'Unknown Author';
            if (config.dynamic_values && config.dynamic_values.author) {
                author = config.dynamic_values.author;
            } else if (config.author) {
                author = config.author;
            } else {
                // Use the first dynamic value or a generic name
                const firstKey = Object.keys(config.dynamic_values || {})[0];
                if (firstKey) {
                    author = firstKey;
                }
            }
            
            // Find the actual index in the full settings array
            const fullSettings = configData.settings;
            let actualIndex = -1;
            if (Array.isArray(fullSettings)) {
                for (let i = 0; i < fullSettings.length; i++) {
                    let configAuthor = fullSettings[i].author || (fullSettings[i].dynamic_values && fullSettings[i].dynamic_values.author);
                    if (configAuthor === author) {
                        actualIndex = i;
                        break;
                    }
                }
            }
            
            // If we can't find by author, try to match by content
            if (actualIndex === -1 && Array.isArray(fullSettings)) {
                const configStr = JSON.stringify(config);
                for (let i = 0; i < fullSettings.length; i++) {
                    if (JSON.stringify(fullSettings[i]) === configStr) {
                        actualIndex = i;
                        break;
                    }
                }
            }
            
            // Build dynamic fields display
            let dynamicFieldsHtml = '';
            if (config.dynamic_values && typeof config.dynamic_values === 'object') {
                const entries = Object.entries(config.dynamic_values);
                if (entries.length > 0) {
                    dynamicFieldsHtml = '<div><div class="detail-label">Dynamic Fields</div>';
                    entries.forEach(([key, value]) => {
                        let displayValue = value;
                        if (typeof value === 'object' && value !== null) {
                            displayValue = JSON.stringify(value);
                        }
                        dynamicFieldsHtml += `<div class="detail-value"><strong>${key}:</strong> ${displayValue}</div>`;
                    });
                    dynamicFieldsHtml += '</div>';
                }
            }
            
            // Add operation status if present
            let operationStatusHtml = '';
            if (config.operation_status) {
                const statusClass = config.operation_status.toLowerCase();
                operationStatusHtml = `
                    <div style="margin-top: 10px;">
                        <div class="detail-label">Operation Status</div>
                        <div class="detail-value">
                            <span class="operation-status-badge ${statusClass}">${config.operation_status}</span>
                        </div>
                    </div>
                `;
            }
            
            // Use author for edit/delete functions
            const authorParam = author;
            const indexParam = actualIndex !== -1 ? actualIndex : index;
            
            div.innerHTML = `
                <div class="item-header">
                    <span class="item-author">${author}</span>
                    <span class="item-status" style="background: ${statusColor[status] || '#a0aec0'}">${statusLabel[status] || status}</span>
                </div>
                <div class="item-details">
                    ${dynamicFieldsHtml}
                    ${operationStatusHtml}
                </div>
                <div class="item-actions">
                    <button class="btn btn-edit-config" onclick="showEditConfig('${authorParam}', ${indexParam})">Edit</button>
                    <button class="btn btn-delete" onclick="deleteConfig('${authorParam}', ${indexParam})">Delete</button>
                    <button class="btn btn-status" onclick="changeStatus('${authorParam}', 'completed')">Mark Completed</button>
                    <button class="btn btn-status" onclick="changeStatus('${authorParam}', 'aborted')">Mark Aborted</button>
                    <button class="btn btn-status" onclick="changeStatus('${authorParam}', 'pending')">Mark Pending</button>
                </div>
            `;
            
            container.appendChild(div);
        });
    }
    
    // ===== CONFIG OPERATIONS - UPDATED DELETE FUNCTIONS =====
    function deleteConfig(author, index) {
        // If index is provided, use it directly
        if (index !== undefined && index !== null) {
            showModal('Confirm Delete', 'Are you sure you want to delete configuration #' + (index + 1) + '?', function() {
                deleteConfigFromDB(index);
            });
            return;
        }
        
        // Fallback: try to find by author (for backward compatibility)
        const settings = configData.settings;
        let foundIndex = -1;
        
        if (Array.isArray(settings)) {
            for (let i = 0; i < settings.length; i++) {
                if (settings[i] && typeof settings[i] === 'object') {
                    let configAuthor = settings[i].author || (settings[i].dynamic_values && settings[i].dynamic_values.author);
                    if (configAuthor === author) {
                        foundIndex = i;
                        break;
                    }
                }
            }
        }
        
        if (foundIndex === -1) {
            showModal('Error', 'Configuration not found for author: ' + author);
            return;
        }
        
        showModal('Confirm Delete', 'Are you sure you want to delete configuration for "' + author + '"?', function() {
            deleteConfigFromDB(foundIndex);
        });
    }

    function deleteConfigFromDB(index) {
        if (!configId) {
            showModal('Error', 'No configuration found to delete.');
            return;
        }
        
        // Get the settings array
        let settings = configData.settings;
        
        if (!Array.isArray(settings) || settings.length === 0) {
            showModal('Error', 'No configurations to delete.');
            return;
        }
        
        // Check if the index is valid
        if (index < 0 || index >= settings.length) {
            showModal('Error', 'Invalid configuration index.');
            return;
        }
        
        // Get the author for the confirmation message (if available)
        let authorName = 'Unknown';
        if (settings[index] && typeof settings[index] === 'object') {
            authorName = settings[index].author || (settings[index].dynamic_values && settings[index].dynamic_values.author) || 'Unknown';
        }
        
        // Remove the entry at the specified index
        settings.splice(index, 1);
        
        // Update configData
        configData.settings = settings;
        
        // Save to database
        const postData = {
            settings: configData.settings,
            accounts: configData.accounts || {},
            captions: configData.captions || [],
            timeorders: configData.timeorders || {},
            filters: configData.filters || [],
            dynamic_fields: configData.dynamic_fields || {}
        };
        
        const formData = new FormData();
        formData.append('save_all', 'true');
        formData.append('settings', JSON.stringify(postData.settings));
        formData.append('accounts', JSON.stringify(postData.accounts));
        formData.append('captions', JSON.stringify(postData.captions));
        formData.append('timeorders', JSON.stringify(postData.timeorders));
        formData.append('filters', JSON.stringify(postData.filters));
        formData.append('dynamic_fields', JSON.stringify(postData.dynamic_fields));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showModal('Success', 'Configuration "' + authorName + '" deleted successfully! Refreshing...');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showModal('Error', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showModal('Error', 'Error deleting configuration: ' + error.message);
        });
    }
        
    function changeStatus(author, status, index) {
        if (!configId) {
            showModal('Error', 'No configuration found.');
            return;
        }
        
        // If index is provided, use it to update the config directly
        if (index !== undefined && index !== null) {
            const settings = configData.settings;
            if (Array.isArray(settings) && index >= 0 && index < settings.length) {
                const config = settings[index];
                if (config && typeof config === 'object') {
                    config.status = status;
                    config.operation_status = "change_status: Status updated to '" + status + "'";
                    
                    // Save all data
                    const postData = {
                        settings: configData.settings,
                        accounts: configData.accounts || {},
                        captions: configData.captions || [],
                        timeorders: configData.timeorders || {},
                        filters: configData.filters || [],
                        dynamic_fields: configData.dynamic_fields || {}
                    };
                    
                    const formData = new FormData();
                    formData.append('save_all', 'true');
                    formData.append('settings', JSON.stringify(postData.settings));
                    formData.append('accounts', JSON.stringify(postData.accounts));
                    formData.append('captions', JSON.stringify(postData.captions));
                    formData.append('timeorders', JSON.stringify(postData.timeorders));
                    formData.append('filters', JSON.stringify(postData.filters));
                    formData.append('dynamic_fields', JSON.stringify(postData.dynamic_fields));
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            showModal('Error', data.message);
                        }
                    })
                    .catch(error => {
                        showModal('Error', 'Error: ' + error.message);
                    });
                    return;
                }
            }
        }
        
        // Fallback: use the old method with author
        const formData = new FormData();
        formData.append('update_status', 'true');
        formData.append('id', configId);
        formData.append('status', status);
        formData.append('author', author);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showModal('Error', data.message);
            }
        })
        .catch(error => {
            showModal('Error', 'Error: ' + error.message);
        });
    }
    
    // ===== MODAL =====
    function showModal(title, message, callback, extraContent) {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalMessage').textContent = message;
        
        const contentDiv = document.querySelector('.modal-content');
        if (extraContent) {
            contentDiv.innerHTML = extraContent;
        } else {
            contentDiv.innerHTML = '';
        }
        
        document.getElementById('customModal').classList.add('active');
        
        if (callback) {
            window._modalCallback = callback;
            const modalButtons = document.querySelector('.modal-buttons');
            modalButtons.innerHTML = `
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" onclick="confirmModalAction()">Confirm</button>
            `;
        } else {
            window._modalCallback = null;
            const modalButtons = document.querySelector('.modal-buttons');
            modalButtons.innerHTML = `
                <button class="btn btn-primary" onclick="closeModal()">OK</button>
            `;
        }
    }
    
    function confirmModalAction() {
        if (window._modalCallback) {
            window._modalCallback();
            window._modalCallback = null;
        }
        closeModal();
    }
    
    function closeModal() {
        document.getElementById('customModal').classList.remove('active');
        window._modalCallback = null;
        const contentDiv = document.querySelector('.modal-content');
        if (contentDiv) contentDiv.innerHTML = '';
        document.querySelector('.modal-buttons').innerHTML = `
            <button class="btn btn-primary" onclick="closeModal()">OK</button>
        `;
    }
    
    document.getElementById('customModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // ===== TAB SWITCHING =====
    function switchTab(tabName, btnElement) {
        document.querySelectorAll('.setup-view .tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabName + '-tab').classList.add('active');
        if (btnElement) {
            btnElement.classList.add('active');
        }
        
        // Refresh dynamic fields when switching to that tab
        if (tabName === 'dynamicfields') {
            loadDynamicFields();
        }
    }
    
    function loadAllData() {
        loadAccounts();
        loadCaptions();
        loadTimeOrders();
        loadFilters();
        loadCaptionAuthors();
        loadDynamicFields();
    }
    
    function loadAccounts() {
        const container = document.getElementById('accounts_list');
        container.innerHTML = '';
        
        const accounts = configData.accounts || {};
        
        if (Object.keys(accounts).length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No accounts added yet.</p>';
            return;
        }
        
        for (const [key, value] of Object.entries(accounts)) {
            const url = value.schedule && value.schedule[0] ? value.schedule[0] : '';
            const profileLink = value.profile_link && value.profile_link[0] ? value.profile_link[0] : '';
            const div = document.createElement('div');
            div.className = 'item-row';
            div.innerHTML = `
                <div class="item-info">
                    <span class="key">${key}</span>
                    <span class="value">${url}</span>
                    ${profileLink ? '<span class="value" style="color:#667eea;">Link: ' + profileLink + '</span>' : ''}
                </div>
                <button class="btn btn-danger" onclick="deleteAccount('${key}')">Delete</button>
            `;
            container.appendChild(div);
        }
    }
    
    function loadCaptions() {
        const container = document.getElementById('captions_list');
        container.innerHTML = '';
        
        const captions = configData.captions || [];
        
        if (captions.length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No captions added yet.</p>';
            return;
        }
        
        captions.forEach((authorData, index) => {
            const div = document.createElement('div');
            div.className = 'caption-item';
            
            // Display captions as plain text lines without JSON
            let captionsDisplay = '';
            if (authorData.captions && authorData.captions.length > 0) {
                const captionLines = authorData.captions
                    .map(cap => cap.description)
                    .slice(0, 3); // Show first 3 captions as preview
                captionsDisplay = captionLines.join(' | ');
                if (authorData.captions.length > 3) {
                    captionsDisplay += ` ... (+${authorData.captions.length - 3} more)`;
                }
            } else {
                captionsDisplay = 'No captions';
            }
            
            div.innerHTML = `
                <div class="caption-content">
                    <span class="caption-author">${authorData.name || 'Unknown Author'}</span>
                    <span style="font-size:13px; color:#5a7a8a;">${authorData.captions ? authorData.captions.length : 0} caption(s)</span>
                    <div style="margin-top:5px; font-size:13px; color:#718096; word-break:break-word;">
                        ${captionsDisplay}
                    </div>
                </div>
                <div>
                    <button class="btn btn-warning" onclick="editCaptions(${index})" style="margin-right:5px;">Edit</button>
                    <button class="btn btn-danger" onclick="deleteCaptionAuthor(${index})">Delete</button>
                </div>
            `;
            container.appendChild(div);
        });
    }
    
    function loadCaptionAuthors() {
        const select = document.getElementById('caption_author_select');
        if (!select) return;
        select.innerHTML = '<option value="">Select Author</option>';
        
        const accounts = configData.accounts || {};
        for (const key of Object.keys(accounts)) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = key;
            select.appendChild(option);
        }
    }
    
    function loadTimeOrders() {
        const container = document.getElementById('timeorders_list');
        container.innerHTML = '';
        
        const timeOrders = configData.timeorders || {};
        
        if (Object.keys(timeOrders).length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No time orders added yet.</p>';
            return;
        }
        
        for (const [key, value] of Object.entries(timeOrders)) {
            const div = document.createElement('div');
            div.className = 'timeorder-item';
            
            let timesHtml = '';
            if (Array.isArray(value) && value.length > 0) {
                timesHtml += '<div class="time-list">';
                value.forEach(t => {
                    const time12 = t['12hours'] || t.twelveHour || t.time || '';
                    const time24 = t['24hours'] || '';
                    timesHtml += '<div class="time-badge">' + time12 + '</div><div class="time-badge">' + time24 + '</div>';
                });
                timesHtml += '</div>';
            } else {
                timesHtml = '<span style="color:#999;">No times added</span>';
            }
            
            div.innerHTML = `
                <div class="timeorder-header">
                    <span class="key">${key}</span>
                    <button class="btn btn-danger" onclick="deleteTimeOrder('${key}')">Delete</button>
                </div>
                ${timesHtml}
                <div class="add-time-area">
                    <button class="btn btn-primary" onclick="addTimeToExistingOrder('${key}')">Add Time</button>
                    <div id="add_time_${key}" style="display:none; margin-top:10px;">
                        <div class="time-input-group">
                            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">
                                <span style="font-weight:500;">24h:</span>
                                <input type="number" id="time_hour_24_${key}" placeholder="HH" min="0" max="23" style="width:80px; padding:10px;">
                                <span>:</span>
                                <input type="number" id="time_minute_24_${key}" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                            </div>
                            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                <span style="font-weight:500;">12h:</span>
                                <input type="number" id="time_hour_12_${key}" placeholder="HH" min="1" max="12" style="width:80px; padding:10px;">
                                <span>:</span>
                                <input type="number" id="time_minute_12_${key}" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                                <select id="time_ampm_${key}" style="width:100px; padding:10px;">
                                    <option value="AM">AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                            <div style="margin-top:10px;">
                                <button class="btn btn-success" onclick="saveTimeToOrder('${key}')">Add to List</button>
                                <button class="btn btn-secondary" onclick="cancelAddTime('${key}')">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(div);
            setupTimeOrderConversion(key);
        }
    }
    
    function loadFilters() {
        const container = document.getElementById('filters_list');
        container.innerHTML = '';
        
        const filters = configData.filters || [];
        if (filters.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 14px;">No filters added.</p>';
            return;
        }
        
        filters.forEach((filter, index) => {
            const span = document.createElement('span');
            span.className = 'group-item';
            span.innerHTML = filter + ' <span class="remove-group" onclick="removeFilter(' + index + ')">×</span>';
            container.appendChild(span);
        });
    }
    
    // ===== DYNAMIC FIELDS FUNCTIONS =====
    function loadDynamicFields() {
        const container = document.getElementById('dynamic_fields_list');
        container.innerHTML = '';
        
        const fields = configData.dynamic_fields || {};
        const fieldKeys = Object.keys(fields);
        
        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No dynamic fields added yet.</p>';
            return;
        }
        
        for (const key of fieldKeys) {
            const field = fields[key];
            const div = document.createElement('div');
            div.className = 'dynamic-field-item';
            
            const defaultValues = field.default_values || [];
            const sourceType = field.source_type || 'none';
            const allowList = field.allow_list || false;
            const title = field.title || key;
            const submitAs = field.submit_as || 'key';
            
            let sourceInfo = '';
            if (sourceType === 'accounts') sourceInfo = ' | Source: Account URLs';
            else if (sourceType === 'captions') sourceInfo = ' | Source: Captions';
            else if (sourceType === 'timeorders') sourceInfo = ' | Source: Time Orders';
            else if (sourceType === 'filters') sourceInfo = ' | Source: filters';
            else if (sourceType === 'copied_links') sourceInfo = ' | Source: Copied Links (JPGS Vault)';
            
            let submitAsLabel = '';
            if (submitAs === 'key') submitAsLabel = 'Submit: Key Only';
            else if (submitAs === 'value') submitAsLabel = 'Submit: Value Only';
            else if (submitAs === 'both') submitAsLabel = 'Submit: Key & Value';
            
            div.innerHTML = `
                <div class="field-header">
                    <span class="field-name">${title} (${key})</span>
                    <span class="field-type-badge">${field.element_type || 'input'}${allowList ? ' + List' : ''}</span>
                </div>
                <div style="color: #5a7a8a; font-size: 13px;">
                    ${field.element_type === 'select' ? 'Options: ' + (defaultValues.length > 0 ? defaultValues.join(', ') : 'None') : ''}
                    ${sourceInfo}
                    ${allowList ? ' | Allows adding custom values' : ''}
                    ${submitAsLabel ? ' | ' + submitAsLabel : ''}
                </div>
                <div class="default-values">
                    ${defaultValues.map(val => `<span class="value-tag">${val}</span>`).join('')}
                </div>
                <div style="margin-top: 10px;">
                    <button class="btn btn-warning" onclick="editDynamicField('${key}')" style="margin-right:5px;">Edit</button>
                    <button class="btn btn-danger" onclick="deleteDynamicField('${key}')">Delete</button>
                </div>
            `;
            container.appendChild(div);
        }
    }
    
    function showAddDynamicFieldModal() {
        _newDefaultValues = [];
        _newListValues = [];
        editingDynamicFieldKey = null;
        
        const html = `
            <div class="form-group">
                <label>Display Title (shown in UI)</label>
                <input type="text" id="modal_field_title" placeholder="Enter display title..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#999;">This is what users will see</small>
            </div>
            <div class="form-group">
                <label>Field Value (used in backend)</label>
                <input type="text" id="modal_field_name" placeholder="Enter field value (no spaces)..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#999;">Spaces will be replaced with underscores. Used for storage.</small>
            </div>
            <div class="form-group">
                <label>Element Type</label>
                <select id="modal_element_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="select">Select</option>
                    <option value="input">Input</option>
                    <option value="textarea">Textarea</option>
                    <option value="date-time-input">Date & Time</option>
                </select>
            </div>
            <div class="form-group" id="modal_allow_list_container" style="display:none;">
                <label>Allow Adding Custom Values (List Input)</label>
                <select id="modal_allow_list" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="no">No - Single value only</option>
                    <option value="yes">Yes - Allow multiple values (add/remove)</option>
                </select>
                <small style="color:#999;">If Yes, users can add multiple values to this field</small>
            </div>
            <div class="form-group" id="modal_default_values_container">
                <label>Default Values</label>
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <input type="text" id="modal_default_value_input" placeholder="Enter value..." style="flex:1; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <button class="btn btn-primary" onclick="addModalDefaultValue()">Add</button>
                </div>
                <div id="modal_default_values_list"></div>
            </div>
            <div class="form-group" id="modal_source_container">
                <label>Source Options From (for Select type)</label>
                <select id="modal_source_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="none">None - Use default values only</option>
                    <option value="accounts">Account URLs</option>
                    <option value="captions">Captions</option>
                    <option value="timeorders">Time Orders</option>
                    <option value="filters">Filters</option>
                    <option value="copied_links">Copied Links (JPGS Vault)</option>
                </select>
                <small style="color:#999;">Select which data source to populate the dropdown with</small>
            </div>
            <div class="form-group" id="modal_submit_as_container">
                <label>Submit As</label>
                <select id="modal_submit_as" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="key">Submit Key Only</option>
                    <option value="value">Submit Value Only</option>
                    <option value="both">Submit Key & Value</option>
                </select>
                <small style="color:#999;">Choose what data to submit to the backend</small>
                <div id="modal_submit_preview" class="submit-as-preview" style="margin-top:5px; padding:6px 12px; background:#f8fcf9; border-radius:6px; border:1px dashed #c8e0d8; font-size:12px; color:#5a7a8a; font-family:monospace;">
                    Example: "key" → "value"
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-success" onclick="saveDynamicFieldFromModal()">Save Field</button>
            </div>
        `;
        
        showModal('Add Dynamic Field', '', null, html);
        renderModalDefaultValues();
        
        // Toggle visibility based on element type
        document.getElementById('modal_element_type').addEventListener('change', function() {
            const isSelect = this.value === 'select';
            const isInput = this.value === 'input';
            
            document.getElementById('modal_default_values_container').style.display = (isSelect || isInput) ? 'block' : 'none';
            document.getElementById('modal_source_container').style.display = isSelect ? 'block' : 'none';
            document.getElementById('modal_allow_list_container').style.display = isInput ? 'block' : 'none';
            document.getElementById('modal_submit_as_container').style.display = isSelect ? 'block' : 'none';
        });
        
        // Auto-generate field name from title
        document.getElementById('modal_field_title').addEventListener('input', function() {
            const title = this.value;
            const nameInput = document.getElementById('modal_field_name');
            if (!nameInput.dataset.manual) {
                nameInput.value = title.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
            }
        });
        
        document.getElementById('modal_field_name').addEventListener('input', function() {
            this.dataset.manual = 'true';
        });
        
        // Update preview when submit as changes
        document.getElementById('modal_submit_as').addEventListener('change', function() {
            updateSubmitPreview(this.value);
        });
    }
    
    function updateSubmitPreview(value) {
        const preview = document.getElementById('modal_submit_preview');
        if (!preview) return;
        
        if (value === 'key') {
            preview.innerHTML = 'Example: "key"';
        } else if (value === 'value') {
            preview.innerHTML = 'Example: "value"';
        } else if (value === 'both') {
            preview.innerHTML = 'Example: {"key": "value"}';
        }
    }
    
    function editDynamicField(key) {
        editingDynamicFieldKey = key;
        const field = configData.dynamic_fields[key];
        if (!field) return;
        
        _newDefaultValues = field.default_values || [];
        _newListValues = field.list_values || [];
        
        const html = `
            <div class="form-group">
                <label>Display Title (shown in UI)</label>
                <input type="text" id="modal_field_title" value="${field.title || key}" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                <small style="color:#999;">This is what users will see</small>
            </div>
            <div class="form-group">
                <label>Field Value (used in backend)</label>
                <input type="text" id="modal_field_name" value="${key}" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;" readonly>
                <small style="color:#999;">Field value cannot be changed</small>
            </div>
            <div class="form-group">
                <label>Element Type</label>
                <select id="modal_element_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="select" ${field.element_type === 'select' ? 'selected' : ''}>Select</option>
                    <option value="input" ${field.element_type === 'input' ? 'selected' : ''}>Input</option>
                    <option value="textarea" ${field.element_type === 'textarea' ? 'selected' : ''}>Textarea</option>
                    <option value="date-time-input" ${field.element_type === 'date-time-input' ? 'selected' : ''}>Date & Time</option>
                </select>
            </div>
            <div class="form-group" id="modal_allow_list_container" style="${field.element_type === 'input' ? 'display:block' : 'display:none'}">
                <label>Allow Adding Custom Values (List Input)</label>
                <select id="modal_allow_list" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="no" ${field.allow_list ? '' : 'selected'}>No - Single value only</option>
                    <option value="yes" ${field.allow_list ? 'selected' : ''}>Yes - Allow multiple values (add/remove)</option>
                </select>
                <small style="color:#999;">If Yes, users can add multiple values to this field</small>
            </div>
            <div class="form-group" id="modal_default_values_container" style="${field.element_type === 'select' || field.element_type === 'input' ? 'display:block' : 'display:none'}">
                <label>Default Values ${field.element_type === 'input' ? '(Pre-filled values)' : ''}</label>
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <input type="text" id="modal_default_value_input" placeholder="Enter value..." style="flex:1; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <button class="btn btn-primary" onclick="addModalDefaultValue()">Add</button>
                </div>
                <div id="modal_default_values_list"></div>
            </div>
            <div class="form-group" id="modal_source_container" style="${field.element_type === 'select' ? 'display:block' : 'display:none'}">
                <label>Source Options From (for Select type)</label>
                <select id="modal_source_type" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="none" ${field.source_type === 'none' || !field.source_type ? 'selected' : ''}>None - Use default values only</option>
                    <option value="accounts" ${field.source_type === 'accounts' ? 'selected' : ''}>Account URLs</option>
                    <option value="captions" ${field.source_type === 'captions' ? 'selected' : ''}>Captions</option>
                    <option value="timeorders" ${field.source_type === 'timeorders' ? 'selected' : ''}>Time Orders</option>
                    <option value="filters" ${field.source_type === 'filters' ? 'selected' : ''}>filters</option>
                    <option value="copied_links" ${field.source_type === 'copied_links' ? 'selected' : ''}>Copied Links (JPGS Vault)</option>
                </select>
                <small style="color:#999;">Select which data source to populate the dropdown with</small>
            </div>
            <div class="form-group" id="modal_submit_as_container" style="${field.element_type === 'select' ? 'display:block' : 'display:none'}">
                <label>Submit As</label>
                <select id="modal_submit_as" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
                    <option value="key" ${field.submit_as === 'key' || !field.submit_as ? 'selected' : ''}>Submit Key Only</option>
                    <option value="value" ${field.submit_as === 'value' ? 'selected' : ''}>Submit Value Only</option>
                    <option value="both" ${field.submit_as === 'both' ? 'selected' : ''}>Submit Key & Value</option>
                </select>
                <small style="color:#999;">Choose what data to submit to the backend</small>
                <div id="modal_submit_preview" class="submit-as-preview" style="margin-top:5px; padding:6px 12px; background:#f8fcf9; border-radius:6px; border:1px dashed #c8e0d8; font-size:12px; color:#5a7a8a; font-family:monospace;">
                    ${field.submit_as === 'key' || !field.submit_as ? 'Example: "key"' : field.submit_as === 'value' ? 'Example: "value"' : 'Example: {"key": "value"}'}
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-success" onclick="saveDynamicFieldFromModal()">Update Field</button>
            </div>
        `;
        
        showModal('Edit Dynamic Field', '', null, html);
        renderModalDefaultValues();
        
        // Toggle visibility based on element type
        document.getElementById('modal_element_type').addEventListener('change', function() {
            const isSelect = this.value === 'select';
            const isInput = this.value === 'input';
            
            document.getElementById('modal_default_values_container').style.display = (isSelect || isInput) ? 'block' : 'none';
            document.getElementById('modal_source_container').style.display = isSelect ? 'block' : 'none';
            document.getElementById('modal_allow_list_container').style.display = isInput ? 'block' : 'none';
            document.getElementById('modal_submit_as_container').style.display = isSelect ? 'block' : 'none';
        });
        
        // Update preview when submit as changes
        document.getElementById('modal_submit_as').addEventListener('change', function() {
            updateSubmitPreview(this.value);
        });
    }
    
    function addModalDefaultValue() {
        const input = document.getElementById('modal_default_value_input');
        const value = input.value.trim();
        if (value) {
            if (!_newDefaultValues.includes(value)) {
                _newDefaultValues.push(value);
                renderModalDefaultValues();
                input.value = '';
            }
        }
    }
    
    function removeModalDefaultValue(index) {
        _newDefaultValues.splice(index, 1);
        renderModalDefaultValues();
    }
    
    function renderModalDefaultValues() {
        const container = document.getElementById('modal_default_values_list');
        if (!container) return;
        container.innerHTML = '';
        
        if (_newDefaultValues.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 14px;">No values added.</p>';
            return;
        }
        
        _newDefaultValues.forEach((val, index) => {
            const span = document.createElement('span');
            span.className = 'group-item';
            span.innerHTML = val + ' <span class="remove-group" onclick="removeModalDefaultValue(' + index + ')">×</span>';
            container.appendChild(span);
        });
    }
    
    function normalizeFieldName(name) {
        return name.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
    }
    
    function saveDynamicFieldFromModal() {
        const title = document.getElementById('modal_field_title').value.trim();
        let fieldName = document.getElementById('modal_field_name').value.trim();
        const elementType = document.getElementById('modal_element_type').value;
        const sourceType = document.getElementById('modal_source_type').value;
        const allowList = document.getElementById('modal_allow_list').value === 'yes';
        const submitAs = document.getElementById('modal_submit_as') ? document.getElementById('modal_submit_as').value : 'key';
        
        if (!title) {
            showModal('Error', 'Please enter a display title.');
            return;
        }
        
        // If field name is empty or wasn't manually set, generate from title
        if (!fieldName || !document.getElementById('modal_field_name').dataset.manual) {
            fieldName = normalizeFieldName(title);
        }
        
        if (!fieldName) {
            showModal('Error', 'Please enter a field value.');
            return;
        }
        
        // Check if field name already exists (for new fields)
        if (!editingDynamicFieldKey && configData.dynamic_fields[fieldName]) {
            showModal('Error', 'A field with this value already exists.');
            return;
        }
        
        const fieldData = {
            title: title,
            element_type: elementType,
            default_values: (elementType === 'select' || elementType === 'input') ? _newDefaultValues : [],
            source_type: (elementType === 'select' && sourceType !== 'none') ? sourceType : 'none',
            allow_list: elementType === 'input' ? allowList : false,
            submit_as: (elementType === 'select' && sourceType !== 'none') ? submitAs : 'key'
        };
        
        // If editing, remove old key and add new one (if name changed - but we don't allow name change in edit)
        if (editingDynamicFieldKey) {
            if (editingDynamicFieldKey !== fieldName) {
                delete configData.dynamic_fields[editingDynamicFieldKey];
            }
        }
        
        configData.dynamic_fields[fieldName] = fieldData;
        
        // Re-render dynamic fields in setup view
        loadDynamicFields();
        
        // Re-render dynamic fields in add config view
        renderDynamicFields();
        
        closeModal();
        showModal('Success', 'Dynamic field saved successfully!');
    }
    
    function deleteDynamicField(key) {
        showModal('Confirm Delete', 'Are you sure you want to delete dynamic field "' + key + '"?', function() {
            if (configData.dynamic_fields) {
                delete configData.dynamic_fields[key];
                loadDynamicFields();
                renderDynamicFields();
                showModal('Success', 'Dynamic field deleted successfully!');
            }
        });
    }
    
    // ===== GET SOURCE VALUES =====
    function getSourceValues(sourceType, submitAs) {
        const values = [];
        
        if (sourceType === 'accounts') {
            const accounts = configData.accounts || {};
            for (const [key, value] of Object.entries(accounts)) {
                const url = value.schedule && value.schedule[0] ? value.schedule[0] : '';
                if (url) {
                    let submitData = '';
                    let displayText = '';
                    
                    if (submitAs === 'key') {
                        submitData = key;
                        displayText = key;
                    } else if (submitAs === 'value') {
                        submitData = url;
                        displayText = url;
                    } else if (submitAs === 'both') {
                        submitData = JSON.stringify({ [key]: url });
                        displayText = key + ' → ' + url;
                    }
                    
                    values.push({ 
                        key: key, 
                        value: url,
                        display: displayText,
                        submitData: submitData,
                        submitAs: submitAs,
                        isGrouped: false
                    });
                }
            }
        } else if (sourceType === 'captions') {
            const captions = configData.captions || [];
            
            const authorCaptionMap = {};
            
            captions.forEach(item => {
                if (item.name && item.captions && item.captions.length > 0) {
                    const captionObjects = item.captions.map(caption => ({
                        'key-name': item.name,
                        'id': caption.id,
                        'description': caption.description
                    }));
                    
                    if (authorCaptionMap[item.name]) {
                        authorCaptionMap[item.name] = authorCaptionMap[item.name].concat(captionObjects);
                    } else {
                        authorCaptionMap[item.name] = captionObjects;
                    }
                } else if (item.name && (!item.captions || item.captions.length === 0)) {
                    if (!authorCaptionMap[item.name]) {
                        authorCaptionMap[item.name] = [];
                    }
                }
            });
            
            for (const [authorName, captionsList] of Object.entries(authorCaptionMap)) {
                let submitData = '';
                let displayText = '';
                
                if (submitAs === 'key') {
                    submitData = authorName;
                    displayText = authorName;
                } else if (submitAs === 'value') {
                    submitData = JSON.stringify(captionsList);
                    displayText = JSON.stringify(captionsList);
                } else if (submitAs === 'both') {
                    submitData = JSON.stringify(captionsList);
                    displayText = authorName + ' (' + captionsList.length + ' captions)';
                }
                
                values.push({ 
                    key: authorName,
                    value: captionsList,
                    display: displayText,
                    submitData: submitData,
                    submitAs: submitAs,
                    isGrouped: true,
                    captionCount: captionsList.length
                });
            }
            
        } else if (sourceType === 'timeorders') {
            const timeOrders = configData.timeorders || {};
            
            for (const [orderName, timesList] of Object.entries(timeOrders)) {
                let submitData = '';
                let displayText = '';
                
                if (submitAs === 'key') {
                    submitData = orderName;
                    displayText = orderName;
                } else if (submitAs === 'value') {
                    submitData = JSON.stringify(timesList);
                    displayText = JSON.stringify(timesList);
                } else if (submitAs === 'both') {
                    submitData = JSON.stringify({ [orderName]: timesList });
                    displayText = orderName + ' (' + (Array.isArray(timesList) ? timesList.length : 0) + ' times)';
                }
                
                values.push({ 
                    key: orderName,
                    value: timesList,
                    display: displayText,
                    submitData: submitData,
                    submitAs: submitAs,
                    isGrouped: true,
                    timeCount: timesList.length
                });
            }
            
        } else if (sourceType === 'filters') {
            const filters = configData.filters || [];
            filters.forEach(filter => {
                let submitData = '';
                let displayText = '';
                
                if (submitAs === 'key') {
                    submitData = filter;
                    displayText = filter;
                } else if (submitAs === 'value') {
                    submitData = filter;
                    displayText = filter;
                } else if (submitAs === 'both') {
                    submitData = JSON.stringify({ [filter]: filter });
                    displayText = filter;
                }
                
                values.push({ 
                    key: filter, 
                    value: filter,
                    display: displayText,
                    submitData: submitData,
                    submitAs: submitAs,
                    isGrouped: false
                });
            });
        } else if (sourceType === 'copied_links') {
            const copiedLinks = copiedLinksData || {};
            
            // Get the domain from the current page URL
            const domain = window.location.origin; // e.g., https://yourdomain.com
            
            console.log('Total folders from JPGS Vault:', Object.keys(copiedLinks).length);
            
            for (const [folder, data] of Object.entries(copiedLinks)) {
                const urlCount = data.count || 0;
                const urls = data.urls || [];
                
                // Store original URLs for display
                const originalUrls = urls;
                
                // Prepend domain to each URL for submission
                const urlsWithDomain = urls.map(url => {
                    // Remove leading slash if exists to avoid double slashes
                    const cleanUrl = url.startsWith('/') ? url.substring(1) : url;
                    return domain + '/' + cleanUrl;
                });
                
                // For display: show folder name with count
                const displayName = folder + ' (' + urlCount + ' URL' + (urlCount !== 1 ? 's' : '') + ')';
                
                let submitData = '';
                
                if (submitAs === 'key') {
                    submitData = folder;
                } else if (submitAs === 'value') {
                    // Submit the full URLs with domain
                    submitData = urlsWithDomain.join(', ');
                } else if (submitAs === 'both') {
                    // For both, store as {"folder": "url1, url2, url3"} with domain
                    submitData = JSON.stringify({ [folder]: urlsWithDomain.join(', ') });
                }
                
                values.push({
                    key: folder,
                    value: urlsWithDomain, // Store with domain for value
                    originalUrls: originalUrls, // Store original for reference
                    display: displayName,
                    submitData: submitData,
                    submitAs: submitAs,
                    urlCount: urlCount,
                    urls: urlsWithDomain,
                    isGrouped: false,
                    isCopiedLinks: true // Flag to identify this source type
                });
            }
            
            // Sort by URL count (most first)
            values.sort((a, b) => b.urlCount - a.urlCount);
        }
        return values;
    }
    
    // ===== RENDER DYNAMIC FIELDS IN ADD CONFIG VIEW =====
    function renderDynamicFields() {
        const container = document.getElementById('dynamicFieldsContainer');
        container.innerHTML = '';
        
        const fields = configData.dynamic_fields || {};
        const fieldKeys = Object.keys(fields);
        
        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color: #999; text-align: center; padding: 20px;">No dynamic fields configured. Go to Setup Config → Dynamic Fields to add some.</p>';
            return;
        }
        
        // Get dynamic values from current config if in edit mode
        let dynamicValues = {};
        if (isEditMode && editingAuthor) {
            const settings = configData.settings;
            if (Array.isArray(settings)) {
                for (let config of settings) {
                    if (config && config.author === editingAuthor && config.dynamic_values) {
                        dynamicValues = config.dynamic_values;
                        break;
                    }
                }
            }
        }
        
        // Check if we have values to load from loadDynamicValues
        if (window._dynamicValuesToLoad) {
            dynamicValues = window._dynamicValuesToLoad;
            window._dynamicValuesToLoad = null;
        }
        
        for (const key of fieldKeys) {
            const field = fields[key];
            const elementType = field.element_type || 'input';
            const title = field.title || key;
            const defaultValues = field.default_values || [];
            const sourceType = field.source_type || 'none';
            const allowList = field.allow_list || false;
            const submitAs = field.submit_as || 'key';
            
            const div = document.createElement('div');
            div.className = 'form-group';
            
            const label = document.createElement('label');
            label.textContent = title;
            div.appendChild(label);
            
            let inputElement;
            const value = dynamicValues[key] || '';
            
            if (elementType === 'date-time-input') {
                // Create date-time input with 12/24 hour select
                const wrapper = document.createElement('div');
                wrapper.className = 'datetime-group';
                wrapper.style.width = '100%';
                wrapper.style.maxWidth = '100%';
                wrapper.style.boxSizing = 'border-box';
                
                // Date input
                const dateInput = document.createElement('input');
                dateInput.type = 'date';
                dateInput.id = 'dynamic_field_' + key + '_date';
                dateInput.className = 'date-input';
                dateInput.style.width = '100%';
                dateInput.style.maxWidth = '100%';
                dateInput.style.boxSizing = 'border-box';
                
                // Set date value if exists
                if (value && typeof value === 'string') {
                    if (value.includes('/')) {
                        const parts = value.split(' ');
                        if (parts.length > 0 && parts[0].includes('/')) {
                            const dateParts = parts[0].split('/');
                            if (dateParts.length === 3) {
                                const formattedDate = dateParts[2] + '-' + dateParts[1].padStart(2, '0') + '-' + dateParts[0].padStart(2, '0');
                                dateInput.value = formattedDate;
                            }
                        }
                    } else if (value.includes('-')) {
                        dateInput.value = value.split(' ')[0] || '';
                    }
                } else if (value && typeof value === 'object') {
                    if (value.date) {
                        dateInput.value = value.date;
                    }
                }
                
                // Time format select
                const formatDiv = document.createElement('div');
                formatDiv.className = 'time-format-select';
                formatDiv.style.cssText = 'display:flex; align-items:center; gap:12px; margin-top:8px; padding:8px 12px; background:#f8fcf9; border-radius:8px; border:1px solid #e0ece8; flex-wrap:wrap;';
                
                const formatLabel = document.createElement('label');
                formatLabel.textContent = 'Submit in format:';
                formatLabel.style.marginBottom = '0 !important';
                formatLabel.style.fontWeight = '500 !important';
                formatLabel.style.color = '#2c3e50';
                formatLabel.style.fontSize = '14px';
                
                const formatSelect = document.createElement('select');
                formatSelect.id = 'dynamic_field_' + key + '_format';
                formatSelect.style.cssText = 'padding:6px 12px; border:2px solid #e0ece8; border-radius:6px; font-size:14px; background:#fafdfc; width:auto; min-width:80px;';
                
                const option12 = document.createElement('option');
                option12.value = '12h';
                option12.textContent = '12h';
                formatSelect.appendChild(option12);
                
                const option24 = document.createElement('option');
                option24.value = '24h';
                option24.textContent = '24h';
                formatSelect.appendChild(option24);
                
                // Set initial format preference
                const prefKey = key;
                if (timeFormatPreferences[prefKey] === undefined) {
                    timeFormatPreferences[prefKey] = '12h';
                }
                formatSelect.value = timeFormatPreferences[prefKey];
                
                formatDiv.appendChild(formatLabel);
                formatDiv.appendChild(formatSelect);
                
                // Time input group - will be shown/hidden based on format
                const timeGroup = document.createElement('div');
                timeGroup.id = 'dynamic_field_' + key + '_time_group';
                timeGroup.className = 'time-group';
                timeGroup.style.cssText = 'display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%; margin-top:10px;';
                
                // 12-hour inputs
                const twelveHourDiv = document.createElement('div');
                twelveHourDiv.id = 'dynamic_field_' + key + '_12h_group';
                twelveHourDiv.style.cssText = 'display:flex; gap:10px; align-items:center; flex-wrap:wrap;';
                
                const hour12 = document.createElement('input');
                hour12.type = 'number';
                hour12.id = 'dynamic_field_' + key + '_hour_12';
                hour12.placeholder = 'HH';
                hour12.min = '1';
                hour12.max = '12';
                hour12.style.cssText = 'width:60px; padding:10px; border:2px solid #e0ece8; border-radius:10px; font-size:14px; background:#fafdfc;';
                
                const colon1 = document.createElement('span');
                colon1.textContent = ':';
                
                const minute12 = document.createElement('input');
                minute12.type = 'number';
                minute12.id = 'dynamic_field_' + key + '_minute_12';
                minute12.placeholder = 'MM';
                minute12.min = '0';
                minute12.max = '59';
                minute12.style.cssText = 'width:60px; padding:10px; border:2px solid #e0ece8; border-radius:10px; font-size:14px; background:#fafdfc;';
                
                const ampmSelect = document.createElement('select');
                ampmSelect.id = 'dynamic_field_' + key + '_ampm';
                ampmSelect.style.cssText = 'width:80px; padding:10px; border:2px solid #e0ece8; border-radius:10px; font-size:14px; background:#fafdfc;';
                
                const amOption = document.createElement('option');
                amOption.value = 'AM';
                amOption.textContent = 'AM';
                ampmSelect.appendChild(amOption);
                
                const pmOption = document.createElement('option');
                pmOption.value = 'PM';
                pmOption.textContent = 'PM';
                ampmSelect.appendChild(pmOption);
                
                twelveHourDiv.appendChild(hour12);
                twelveHourDiv.appendChild(colon1);
                twelveHourDiv.appendChild(minute12);
                twelveHourDiv.appendChild(ampmSelect);
                
                // 24-hour inputs
                const twentyFourDiv = document.createElement('div');
                twentyFourDiv.id = 'dynamic_field_' + key + '_24h_group';
                twentyFourDiv.style.cssText = 'display:none; gap:10px; align-items:center; flex-wrap:wrap;';
                
                const hour24 = document.createElement('input');
                hour24.type = 'number';
                hour24.id = 'dynamic_field_' + key + '_hour_24';
                hour24.placeholder = 'HH';
                hour24.min = '0';
                hour24.max = '23';
                hour24.style.cssText = 'width:60px; padding:10px; border:2px solid #e0ece8; border-radius:10px; font-size:14px; background:#fafdfc;';
                
                const colon2 = document.createElement('span');
                colon2.textContent = ':';
                
                const minute24 = document.createElement('input');
                minute24.type = 'number';
                minute24.id = 'dynamic_field_' + key + '_minute_24';
                minute24.placeholder = 'MM';
                minute24.min = '0';
                minute24.max = '59';
                minute24.style.cssText = 'width:60px; padding:10px; border:2px solid #e0ece8; border-radius:10px; font-size:14px; background:#fafdfc;';
                
                twentyFourDiv.appendChild(hour24);
                twentyFourDiv.appendChild(colon2);
                twentyFourDiv.appendChild(minute24);
                
                timeGroup.appendChild(twelveHourDiv);
                timeGroup.appendChild(twentyFourDiv);
                
                wrapper.appendChild(dateInput);
                wrapper.appendChild(formatDiv);
                wrapper.appendChild(timeGroup);
                
                // Show/hide time inputs based on format selection
                formatSelect.addEventListener('change', function() {
                    const format = this.value;
                    timeFormatPreferences[prefKey] = format;
                    
                    if (format === '12h') {
                        twelveHourDiv.style.display = 'flex';
                        twentyFourDiv.style.display = 'none';
                    } else {
                        twelveHourDiv.style.display = 'none';
                        twentyFourDiv.style.display = 'flex';
                    }
                });
                
                // Trigger initial visibility
                if (formatSelect.value === '12h') {
                    twelveHourDiv.style.display = 'flex';
                    twentyFourDiv.style.display = 'none';
                } else {
                    twelveHourDiv.style.display = 'none';
                    twentyFourDiv.style.display = 'flex';
                }
                
                // Set initial values if they exist
                if (value && typeof value === 'string') {
                    const timeMatch = value.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/);
                    if (timeMatch) {
                        hour12.value = parseInt(timeMatch[1]);
                        minute12.value = parseInt(timeMatch[2]);
                        ampmSelect.value = timeMatch[3];
                        formatSelect.value = '12h';
                        twelveHourDiv.style.display = 'flex';
                        twentyFourDiv.style.display = 'none';
                    } else {
                        const timeMatch24 = value.match(/(\d{1,2}):(\d{2})/);
                        if (timeMatch24) {
                            hour24.value = parseInt(timeMatch24[1]);
                            minute24.value = parseInt(timeMatch24[2]);
                            formatSelect.value = '24h';
                            twelveHourDiv.style.display = 'none';
                            twentyFourDiv.style.display = 'flex';
                        }
                    }
                }
                
                inputElement = wrapper;
                
            } else if (elementType === 'textarea') {
                inputElement = document.createElement('textarea');
                inputElement.id = 'dynamic_field_' + key;
                inputElement.className = 'dynamic-field-input';
                inputElement.rows = 3;
                inputElement.placeholder = 'Enter ' + title + '...';
                if (typeof value === 'string') {
                    inputElement.value = value;
                }
                inputElement.style.width = '100%';
                inputElement.style.padding = '12px 16px';
                inputElement.style.border = '2px solid #e0ece8';
                inputElement.style.borderRadius = '10px';
                inputElement.style.fontSize = '14px';
                inputElement.style.transition = 'all 0.3s';
                inputElement.style.boxSizing = 'border-box';
                inputElement.style.background = '#fafdfc';
                inputElement.style.fontFamily = 'inherit';
                inputElement.style.resize = 'vertical';
            } else if (elementType === 'select') {
                const wrapper = document.createElement('div');
                wrapper.style.width = '100%';
                
                inputElement = document.createElement('select');
                inputElement.id = 'dynamic_field_' + key;
                inputElement.className = 'dynamic-field-input';
                inputElement.style.width = '100%';
                inputElement.style.padding = '12px 16px';
                inputElement.style.border = '2px solid #e0ece8';
                inputElement.style.borderRadius = '10px';
                inputElement.style.fontSize = '14px';
                inputElement.style.transition = 'all 0.3s';
                inputElement.style.boxSizing = 'border-box';
                inputElement.style.background = '#fafdfc';
                
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'Select ' + title + '...';
                inputElement.appendChild(emptyOption);
                
                let sourceValues = [];
                
                if (sourceType !== 'none') {
                    sourceValues = getSourceValues(sourceType, submitAs);
                } else {
                    defaultValues.forEach(val => {
                        let submitData = '';
                        if (submitAs === 'key') {
                            submitData = val;
                        } else if (submitAs === 'value') {
                            submitData = val;
                        } else if (submitAs === 'both') {
                            submitData = JSON.stringify({ [val]: val });
                        }
                        
                        sourceValues.push({ 
                            key: val, 
                            value: val,
                            display: val,
                            submitData: submitData,
                            submitAs: submitAs
                        });
                    });
                }
                
                sourceValues.forEach(({ key: optionKey, value: actualValue, display, submitData, isCopiedLinks }) => {
                    const option = document.createElement('option');
                    // For copied links, store the submitData which already has domain
                    option.value = submitData;
                    option.textContent = display;
                    option.dataset.key = optionKey;
                    option.dataset.value = actualValue;
                    option.dataset.submitAs = submitAs;
                    option.dataset.isCopiedLinks = isCopiedLinks || false;
                    
                    // If this is copied links, store the raw URLs without domain for display purposes
                    if (isCopiedLinks && actualValue) {
                        option.dataset.rawUrls = JSON.stringify(actualValue);
                    }
                    
                    if (value === actualValue || value === submitData) {
                        option.selected = true;
                    }
                    inputElement.appendChild(option);
                });
                
                wrapper.appendChild(inputElement);
                
                // Add preview of what will be submitted
                const previewDiv = document.createElement('div');
                previewDiv.className = 'submit-as-preview';
                previewDiv.style.cssText = 'margin-top:5px; padding:6px 12px; background:#f8fcf9; border-radius:6px; border:1px dashed #c8e0d8; font-size:12px; color:#5a7a8a; font-family:monospace; word-break:break-all; max-height:100px; overflow-y:auto;';
                previewDiv.textContent = 'Selected: ' + (inputElement.value || 'None');
                wrapper.appendChild(previewDiv);
                
                inputElement.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption && selectedOption.value) {
                        // Display the selected value (which already has domain if copied links)
                        previewDiv.textContent = 'Selected: ' + selectedOption.value;
                    } else {
                        previewDiv.textContent = 'Selected: None';
                    }
                });
                
                inputElement = wrapper;
            } else {
                // input - could be list input or single input
                if (allowList) {
                    // List input with add/remove functionality
                    const wrapper = document.createElement('div');
                    wrapper.style.width = '100%';
                    
                    const listContainer = document.createElement('div');
                    listContainer.id = 'dynamic_field_' + key + '_list';
                    listContainer.className = 'list-values';
                    
                    // Load existing values
                    let listValues = [];
                    if (Array.isArray(value)) {
                        listValues = value;
                    } else if (typeof value === 'string' && value) {
                        listValues = [value];
                    } else if (value && typeof value === 'object') {
                        listValues = Object.values(value);
                    }
                    
                    // Input with add button
                    const inputGroup = document.createElement('div');
                    inputGroup.className = 'list-input-container';
                    inputGroup.style.display = 'flex';
                    inputGroup.style.gap = '10px';
                    inputGroup.style.marginTop = '5px';
                    inputGroup.style.flexWrap = 'wrap';
                    
                    const listInput = document.createElement('input');
                    listInput.type = 'text';
                    listInput.id = 'dynamic_field_' + key + '_input';
                    listInput.placeholder = 'Add value...';
                    listInput.style.flex = '1';
                    listInput.style.minWidth = '150px';
                    listInput.style.padding = '10px 14px';
                    listInput.style.border = '2px solid #e0ece8';
                    listInput.style.borderRadius = '10px';
                    listInput.style.fontSize = '14px';
                    listInput.style.background = '#fafdfc';
                    
                    const addBtn = document.createElement('button');
                    addBtn.type = 'button';
                    addBtn.className = 'btn btn-primary';
                    addBtn.textContent = 'Add';
                    addBtn.style.padding = '10px 20px';
                    addBtn.style.margin = '0';
                    addBtn.style.whiteSpace = 'nowrap';
                    
                    inputGroup.appendChild(listInput);
                    inputGroup.appendChild(addBtn);
                    
                    wrapper.appendChild(listContainer);
                    wrapper.appendChild(inputGroup);
                    
                    // Store reference for save
                    wrapper._fieldKey = key;
                    wrapper._listValues = listValues;
                    
                    // Function to render list values
                    function renderListValues() {
                        listContainer.innerHTML = '';
                        if (listValues.length === 0) {
                            listContainer.innerHTML = '<span style="color: #999; font-size: 13px;">No values added</span>';
                            return;
                        }
                        listValues.forEach((val, idx) => {
                            const tag = document.createElement('span');
                            tag.className = 'value-tag';
                            tag.innerHTML = val + ' <span class="remove-value" data-index="' + idx + '">×</span>';
                            tag.querySelector('.remove-value').addEventListener('click', function() {
                                listValues.splice(parseInt(this.dataset.index), 1);
                                renderListValues();
                            });
                            listContainer.appendChild(tag);
                        });
                    }
                    
                    // Add value function
                    addBtn.addEventListener('click', function() {
                        const val = listInput.value.trim();
                        if (val && !listValues.includes(val)) {
                            listValues.push(val);
                            renderListValues();
                            listInput.value = '';
                        }
                    });
                    
                    listInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addBtn.click();
                        }
                    });
                    
                    renderListValues();
                    inputElement = wrapper;
                    
                } else {
                    // Single input
                    inputElement = document.createElement('input');
                    inputElement.type = 'text';
                    inputElement.id = 'dynamic_field_' + key;
                    inputElement.className = 'dynamic-field-input';
                    inputElement.placeholder = 'Enter ' + title + '...';
                    if (typeof value === 'string') {
                        inputElement.value = value;
                    }
                    inputElement.style.width = '100%';
                    inputElement.style.padding = '12px 16px';
                    inputElement.style.border = '2px solid #e0ece8';
                    inputElement.style.borderRadius = '10px';
                    inputElement.style.fontSize = '14px';
                    inputElement.style.transition = 'all 0.3s';
                    inputElement.style.boxSizing = 'border-box';
                    inputElement.style.background = '#fafdfc';
                }
            }
            
            div.appendChild(inputElement);
            container.appendChild(div);
        }
    }
    
    // ===== TIME CONVERSION =====
    function setupTimeOrderConversion(key) {
        const hour12 = document.getElementById('time_hour_12_' + key);
        const minute12 = document.getElementById('time_minute_12_' + key);
        const ampm = document.getElementById('time_ampm_' + key);
        const hour24 = document.getElementById('time_hour_24_' + key);
        const minute24 = document.getElementById('time_minute_24_' + key);
        
        if (!hour12 || !minute12 || !ampm || !hour24 || !minute24) return;
        
        function convert12To24() {
            let h = parseInt(hour12.value);
            const m = parseInt(minute12.value);
            const a = ampm.value;
            
            if (isNaN(h) || isNaN(m) || h < 1 || h > 12 || m < 0 || m > 59) {
                return;
            }
            
            let h24 = h;
            if (a === 'PM' && h !== 12) h24 = h + 12;
            if (a === 'AM' && h === 12) h24 = 0;
            
            hour24.value = String(h24).padStart(2, '0');
            minute24.value = String(m).padStart(2, '0');
        }
        
        function convert24To12() {
            let h = parseInt(hour24.value);
            const m = parseInt(minute24.value);
            
            if (isNaN(h) || isNaN(m) || h < 0 || h > 23 || m < 0 || m > 59) {
                return;
            }
            
            let a = 'AM';
            let h12 = h;
            if (h >= 12) {
                a = 'PM';
                if (h > 12) h12 = h - 12;
            }
            if (h === 0) {
                h12 = 12;
                a = 'AM';
            }
            
            hour12.value = h12;
            ampm.value = a;
            minute12.value = String(m).padStart(2, '0');
        }
        
        hour12.addEventListener('input', convert12To24);
        minute12.addEventListener('input', convert12To24);
        ampm.addEventListener('change', convert12To24);
        hour24.addEventListener('input', convert24To12);
        minute24.addEventListener('input', convert24To12);
    }
    
    // ===== COUNTRY FUNCTIONS =====
    function addFilter() {
        const input = document.getElementById('filter_input');
        const value = input.value.trim();
        if (value) {
            if (!configData.filters) configData.filters = [];
            if (!configData.filters.includes(value)) {
                configData.filters.push(value);
                loadFilters();
                renderDynamicFields();
                input.value = '';
            }
        }
    }
    
    function removeFilter(index) {
        showModal('Confirm Delete', 'Are you sure you want to remove this filter?', function() {
            if (configData.filters) {
                configData.filters.splice(index, 1);
                loadFilters();
                renderDynamicFields();
            }
        });
    }
    
    // ===== ACCOUNT FUNCTIONS =====
    function showAddAccount() {
        document.getElementById('add_account_form').style.display = 'block';
    }
    
    function hideAddAccount() {
        document.getElementById('add_account_form').style.display = 'none';
        document.getElementById('new_account_name').value = '';
        document.getElementById('new_account_url').value = '';
    }
    
    function addAccount() {
        const name = document.getElementById('new_account_name').value.trim();
        const url = document.getElementById('new_account_url').value.trim();
        
        if (!name || !url) {
            showModal('Error', 'Please enter both account name and URL.');
            return;
        }
        
        if (!configData.accounts || typeof configData.accounts !== 'object' || Array.isArray(configData.accounts)) {
            configData.accounts = {};
        }
        
        if (configData.accounts[name]) {
            showModal('Error', 'Account already exists!');
            return;
        }
        
        configData.accounts[name] = { schedule: [url] };
        loadAccounts();
        loadCaptionAuthors();
        renderDynamicFields();
        hideAddAccount();
        showModal('Success', 'Account added successfully!');
    }
    
    function deleteAccount(name) {
        showModal('Confirm Delete', 'Are you sure you want to delete account "' + name + '"?', function() {
            if (configData.accounts) {
                delete configData.accounts[name];
                loadAccounts();
                loadCaptionAuthors();
                renderDynamicFields();
            }
        });
    }
    
    // ===== CAPTION FUNCTIONS =====
    function showAddCaption() {
        document.getElementById('add_caption_form').style.display = 'block';
        loadCaptionAuthors();
    }

    function hideAddCaption() {
        document.getElementById('add_caption_form').style.display = 'none';
        document.getElementById('new_caption_text').value = '';
        document.getElementById('caption_author_select').value = '';
    }

    // Parse caption input - supports multiple formats
    function parseCaptionInput(input) {
        if (!input || typeof input !== 'string') {
            return [];
        }
        
        const trimmed = input.trim();
        
        // Try to parse as JSON
        try {
            const parsed = JSON.parse(trimmed);
            
            // Format 2: [{id: number, description: string}, ...]
            if (Array.isArray(parsed)) {
                // Check if it's format 2 (objects with id and description)
                if (parsed.length > 0 && parsed[0].description !== undefined) {
                    // Format 2 - keep as is
                    return parsed.map(item => ({
                        id: item.id || null,
                        description: item.description || ''
                    })).filter(item => item.description);
                }
                // Format 1: ["caption 1", "caption 2", ...]
                else if (parsed.length > 0 && typeof parsed[0] === 'string') {
                    // Format 1 - convert to format 2
                    return parsed
                        .filter(item => typeof item === 'string' && item.trim())
                        .map((item, index) => ({
                            id: index + 1,
                            description: item.trim()
                        }));
                }
            }
        } catch (e) {
            // Not JSON - treat as single caption or split by newlines/commas
        }
        
        // Not JSON: split by newlines or commas
        const items = trimmed.split(/\n|,/).filter(item => item.trim());
        
        if (items.length === 0) {
            return [];
        }
        
        // Check if each item looks like a caption object (contains "description" or "id")
        const maybeObjects = items.map(item => {
            try {
                const obj = JSON.parse(item.trim());
                if (obj && typeof obj === 'object' && obj.description !== undefined) {
                    return { id: obj.id || null, description: obj.description };
                }
            } catch (e) {}
            return null;
        });
        
        // If all items are valid objects, use them
        if (maybeObjects.every(item => item !== null)) {
            return maybeObjects.filter(item => item && item.description);
        }
        
        // Otherwise treat as plain captions
        return items
            .filter(item => item.trim())
            .map((item, index) => ({
                id: index + 1,
                description: item.trim()
            }));
    }

    function addCaption() {
        const author = document.getElementById('caption_author_select').value;
        const inputText = document.getElementById('new_caption_text').value.trim();
        
        if (!author) {
            showModal('Error', 'Please select an author.');
            return;
        }
        
        if (!inputText) {
            showModal('Error', 'Please enter caption text or JSON.');
            return;
        }
        
        // Parse the input into captions array
        const newCaptions = parseCaptionInput(inputText);
        
        if (newCaptions.length === 0) {
            showModal('Error', 'No valid captions found. Please enter text, JSON array, or caption objects.');
            return;
        }
        
        if (!configData.captions || !Array.isArray(configData.captions)) {
            configData.captions = [];
        }
        
        let authorIndex = configData.captions.findIndex(item => item.name === author);
        
        if (authorIndex === -1) {
            configData.captions.push({
                name: author,
                id: configData.captions.length + 1,
                captions: []
            });
            authorIndex = configData.captions.length - 1;
        }
        
        // Get existing captions count for ID assignment
        const existingCount = configData.captions[authorIndex].captions.length;
        
        // Add new captions with proper IDs
        newCaptions.forEach((caption, index) => {
            const newCaption = {
                id: existingCount + index + 1,
                description: caption.description
            };
            configData.captions[authorIndex].captions.push(newCaption);
        });
        
        loadCaptions();
        renderDynamicFields();
        hideAddCaption();
        showModal('Success', `${newCaptions.length} caption(s) added successfully for ${author}!`);
    }

    function editCaptions(index) {
        const authorData = configData.captions[index];
        if (!authorData) return;
        
        // Display captions as plain sentences without JSON structure
        let captionsText = '';
        if (authorData.captions && authorData.captions.length > 0) {
            captionsText = authorData.captions
                .map(cap => cap.description)
                .join('\n');
        }
        
        let html = `
            <h3 style="margin-bottom:10px; color:#333;">Edit ${authorData.name} - Captions</h3>
            <div style="margin-bottom:10px; color:#718096; font-size:13px;">
                <p>Enter captions in any format:</p>
                <ul style="margin-top:5px; padding-left:20px;">
                    <li><strong>Simple:</strong> One caption per line</li>
                    <li><strong>JSON Array:</strong> ["caption 1", "caption 2"]</li>
                    <li><strong>Object Array:</strong> [{"id":1,"description":"caption"}]</li>
                </ul>
            </div>
            <div class="form-group">
                <textarea id="edit_captions_textarea" rows="8" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; resize:vertical; font-family:monospace;">${captionsText}</textarea>
            </div>
            <div style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">
                <button class="btn btn-success" onclick="saveEditedCaptions(${index})">Save Changes</button>
                <button class="btn btn-danger" onclick="deleteCaptionFromAuthor(${index})">Delete All Captions</button>
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            </div>
        `;
        
        showModal('Edit Captions', '', null, html);
        window._editingCaptionIndex = index;
    }

    function saveEditedCaptions(index) {
        const authorData = configData.captions[index];
        if (!authorData) return;
        
        const textarea = document.getElementById('edit_captions_textarea');
        if (!textarea) return;
        
        const inputText = textarea.value.trim();
        
        // Parse the input
        const newCaptions = parseCaptionInput(inputText);
        
        if (newCaptions.length === 0) {
            showModal('Error', 'No valid captions found. Please enter text, JSON array, or caption objects.');
            return;
        }
        
        // Replace all captions with the new ones
        authorData.captions = newCaptions.map((caption, index) => ({
            id: index + 1,
            description: caption.description
        }));
        
        loadCaptions();
        renderDynamicFields();
        closeModal();
        showModal('Success', `${authorData.captions.length} caption(s) updated successfully!`);
    }

    function deleteCaptionFromAuthor(index) {
        showModal('Confirm Delete', 'Are you sure you want to delete all captions for this author?', function() {
            if (configData.captions && configData.captions[index]) {
                configData.captions[index].captions = [];
                loadCaptions();
                renderDynamicFields();
                closeModal();
                showModal('Success', 'All captions deleted for this author.');
            }
        });
    }

function deleteCaptionAuthor(index) {
    showModal('Confirm Delete', 'Are you sure you want to delete this author and all their captions?', function() {
        if (configData.captions) {
            configData.captions.splice(index, 1);
            loadCaptions();
            renderDynamicFields();
        }
    });
}
    
    // ===== TIME ORDER FUNCTIONS =====
    function showAddTimeOrder() {
        document.getElementById('add_timeorder_form')?.remove();
        
        const container = document.getElementById('timeorders_list');
        const form = document.createElement('div');
        form.id = 'add_timeorder_form';
        form.style.cssText = 'background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px;';
        form.innerHTML = `
            <div class="form-group">
                <label>Time Order Name</label>
                <input type="text" id="new_timeorder_name" placeholder="Enter time order name..." style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div class="time-input-group">
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%; margin-top:10px;">
                    <span style="font-weight:500;">24h:</span>
                    <input type="number" id="new_time_hour_24" placeholder="HH" min="0" max="23" style="width:80px; padding:10px;">
                    <span>:</span>
                    <input type="number" id="new_time_minute_24" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%;">
                    <span style="font-weight:500;">12h:</span>
                    <input type="number" id="new_time_hour_12" placeholder="HH" min="1" max="12" style="width:80px; padding:10px;">
                    <span>:</span>
                    <input type="number" id="new_time_minute_12" placeholder="MM" min="0" max="59" style="width:80px; padding:10px;">
                    <select id="new_time_ampm" style="width:100px; padding:10px;">
                        <option value="AM">AM</option>
                        <option value="PM">PM</option>
                    </select>
                </div>
                <div style="margin-top:10px;">
                    <button class="btn btn-primary" onclick="addTimeToNewOrder()">Add to List</button>
                </div>
            </div>
            <div id="new_time_list" style="margin-top: 10px;"></div>
            <div style="margin-top: 15px;">
                <button class="btn btn-success" onclick="saveNewTimeOrder()">Save Time Order</button>
                <button class="btn btn-secondary" onclick="cancelNewTimeOrder()" style="margin-left: 10px;">Cancel</button>
            </div>
        `;
        container.prepend(form);
        _newTimeList = [];
        renderNewTimeList();
        setupNewTimeConversion();
    }
    
    function setupNewTimeConversion() {
        const hour12 = document.getElementById('new_time_hour_12');
        const minute12 = document.getElementById('new_time_minute_12');
        const ampm = document.getElementById('new_time_ampm');
        const hour24 = document.getElementById('new_time_hour_24');
        const minute24 = document.getElementById('new_time_minute_24');
        
        if (!hour12 || !minute12 || !ampm || !hour24 || !minute24) return;
        
        function convert12To24() {
            let h = parseInt(hour12.value);
            const m = parseInt(minute12.value);
            const a = ampm.value;
            
            if (isNaN(h) || isNaN(m) || h < 1 || h > 12 || m < 0 || m > 59) {
                return;
            }
            
            let h24 = h;
            if (a === 'PM' && h !== 12) h24 = h + 12;
            if (a === 'AM' && h === 12) h24 = 0;
            
            hour24.value = String(h24).padStart(2, '0');
            minute24.value = String(m).padStart(2, '0');
        }
        
        function convert24To12() {
            let h = parseInt(hour24.value);
            const m = parseInt(minute24.value);
            
            if (isNaN(h) || isNaN(m) || h < 0 || h > 23 || m < 0 || m > 59) {
                return;
            }
            
            let a = 'AM';
            let h12 = h;
            if (h >= 12) {
                a = 'PM';
                if (h > 12) h12 = h - 12;
            }
            if (h === 0) {
                h12 = 12;
                a = 'AM';
            }
            
            hour12.value = h12;
            ampm.value = a;
            minute12.value = String(m).padStart(2, '0');
        }
        
        hour12.addEventListener('input', convert12To24);
        minute12.addEventListener('input', convert12To24);
        ampm.addEventListener('change', convert12To24);
        hour24.addEventListener('input', convert24To12);
        minute24.addEventListener('input', convert24To12);
    }
    
    function cancelNewTimeOrder() {
        document.getElementById('add_timeorder_form')?.remove();
        _newTimeList = [];
    }
    
    function addTimeToNewOrder() {
        const hour12 = document.getElementById('new_time_hour_12').value;
        const minute12 = document.getElementById('new_time_minute_12').value;
        const ampm = document.getElementById('new_time_ampm').value;
        const hour24 = document.getElementById('new_time_hour_24').value;
        const minute24 = document.getElementById('new_time_minute_24').value;
        
        if (!hour12 || !minute12 || !hour24 || !minute24) {
            showModal('Error', 'Please enter valid time in both formats.');
            return;
        }
        
        const time12h = hour12 + ':' + String(minute12).padStart(2, '0') + ' ' + ampm;
        const time24h = String(hour24).padStart(2, '0') + ':' + String(minute24).padStart(2, '0');
        
        _newTimeList.push({ '12hours': time12h, '24hours': time24h });
        renderNewTimeList();
        
        document.getElementById('new_time_hour_12').value = '';
        document.getElementById('new_time_minute_12').value = '';
        document.getElementById('new_time_hour_24').value = '';
        document.getElementById('new_time_minute_24').value = '';
    }
    
    function renderNewTimeList() {
        const container = document.getElementById('new_time_list');
        if (!container) return;
        container.innerHTML = '';
        
        if (_newTimeList.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 14px;">No times added yet.</p>';
            return;
        }
        
        const table = document.createElement('div');
        table.style.cssText = 'display: grid; grid-template-columns: 1fr 1fr; gap: 5px;';
        table.innerHTML = `
            <div class="time-badge header" style="background:#667eea; color:white; padding:8px; border-radius:6px; text-align:center; font-weight:600;">12 Hours</div>
            <div class="time-badge header" style="background:#667eea; color:white; padding:8px; border-radius:6px; text-align:center; font-weight:600;">24 Hours</div>
        `;
        
        _newTimeList.forEach((item, index) => {
            const div1 = document.createElement('div');
            div1.className = 'time-badge';
            div1.style.cssText = 'background:white; padding:8px; border-radius:6px; border:1px solid #ddd; text-align:center;';
            div1.textContent = item['12hours'];
            
            const div2 = document.createElement('div');
            div2.className = 'time-badge';
            div2.style.cssText = 'background:white; padding:8px; border-radius:6px; border:1px solid #ddd; text-align:center; display:flex; justify-content:space-between; align-items:center;';
            div2.innerHTML = item['24hours'] + ' <span style="color:#fc8181; cursor:pointer; margin-left:10px;" onclick="removeNewTime(' + index + ')">×</span>';
            
            table.appendChild(div1);
            table.appendChild(div2);
        });
        
        container.appendChild(table);
    }
    
    function removeNewTime(index) {
        _newTimeList.splice(index, 1);
        renderNewTimeList();
    }
    
    function saveNewTimeOrder() {
        const name = document.getElementById('new_timeorder_name').value.trim();
        
        if (!name) {
            showModal('Error', 'Please enter a time order name.');
            return;
        }
        
        if (!configData.timeorders || typeof configData.timeorders !== 'object' || Array.isArray(configData.timeorders)) {
            configData.timeorders = {};
        }
        
        if (configData.timeorders[name]) {
            showModal('Error', 'Time order already exists!');
            return;
        }
        
        if (_newTimeList.length === 0) {
            showModal('Error', 'Please add at least one time.');
            return;
        }
        
        configData.timeorders[name] = _newTimeList;
        loadTimeOrders();
        renderDynamicFields();
        cancelNewTimeOrder();
        showModal('Success', 'Time order saved successfully!');
    }
    
    function addTimeToExistingOrder(key) {
        const container = document.getElementById('add_time_' + key);
        if (container) {
            container.style.display = container.style.display === 'none' ? 'block' : 'none';
        }
    }
    
    function cancelAddTime(key) {
        const container = document.getElementById('add_time_' + key);
        if (container) container.style.display = 'none';
        document.getElementById('time_hour_12_' + key).value = '';
        document.getElementById('time_minute_12_' + key).value = '';
        document.getElementById('time_hour_24_' + key).value = '';
        document.getElementById('time_minute_24_' + key).value = '';
    }
    
    function saveTimeToOrder(key) {
        const hour12 = document.getElementById('time_hour_12_' + key).value;
        const minute12 = document.getElementById('time_minute_12_' + key).value;
        const ampm = document.getElementById('time_ampm_' + key).value;
        const hour24 = document.getElementById('time_hour_24_' + key).value;
        const minute24 = document.getElementById('time_minute_24_' + key).value;
        
        if (!hour12 || !minute12 || !hour24 || !minute24) {
            showModal('Error', 'Please enter valid time in both formats.');
            return;
        }
        
        const time12h = hour12 + ':' + String(minute12).padStart(2, '0') + ' ' + ampm;
        const time24h = String(hour24).padStart(2, '0') + ':' + String(minute24).padStart(2, '0');
        
        if (configData.timeorders && configData.timeorders[key]) {
            configData.timeorders[key].push({ '12hours': time12h, '24hours': time24h });
            loadTimeOrders();
            renderDynamicFields();
            showModal('Success', 'Time added successfully!');
        }
    }
    
    function deleteTimeOrder(name) {
        showModal('Confirm Delete', 'Are you sure you want to delete time order "' + name + '"?', function() {
            if (configData.timeorders) {
                delete configData.timeorders[name];
                loadTimeOrders();
                renderDynamicFields();
            }
        });
    }
    
    function saveConfig() {
        // Get dynamic values from rendered fields
        const dynamicValues = {};
        const fieldKeys = Object.keys(configData.dynamic_fields || {});
        
        // Get the domain for URL construction
        const domain = window.location.origin;
        
        for (const key of fieldKeys) {
            const field = configData.dynamic_fields[key];
            const elementType = field.element_type || 'input';
            const allowList = field.allow_list || false;
            const sourceType = field.source_type || 'none';
            
            if (elementType === 'date-time-input') {
                const dateInput = document.getElementById('dynamic_field_' + key + '_date');
                const formatSelect = document.getElementById('dynamic_field_' + key + '_format');
                
                if (dateInput && formatSelect) {
                    const date = dateInput.value;
                    const format = formatSelect.value;
                    
                    let timeStr = '';
                    if (format === '12h') {
                        const hour12 = document.getElementById('dynamic_field_' + key + '_hour_12');
                        const minute12 = document.getElementById('dynamic_field_' + key + '_minute_12');
                        const ampm = document.getElementById('dynamic_field_' + key + '_ampm');
                        if (hour12 && minute12 && ampm) {
                            const h = hour12.value;
                            const m = minute12.value;
                            const a = ampm.value;
                            if (h && m) {
                                timeStr = h + ':' + String(m).padStart(2, '0') + ' ' + a;
                            }
                        }
                    } else {
                        const hour24 = document.getElementById('dynamic_field_' + key + '_hour_24');
                        const minute24 = document.getElementById('dynamic_field_' + key + '_minute_24');
                        if (hour24 && minute24) {
                            const h = hour24.value;
                            const m = minute24.value;
                            if (h && m) {
                                timeStr = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
                            }
                        }
                    }
                    
                    if (date || timeStr) {
                        let formattedDate = '';
                        if (date) {
                            const parts = date.split('-');
                            if (parts.length === 3) {
                                formattedDate = parts[2] + '/' + parts[1] + '/' + parts[0];
                            }
                        }
                        dynamicValues[key] = formattedDate + (timeStr ? ' ' + timeStr : '');
                    }
                }
            } else if (elementType === 'input' && allowList) {
                const listContainer = document.getElementById('dynamic_field_' + key + '_list');
                if (listContainer) {
                    const values = [];
                    const tags = listContainer.querySelectorAll('.value-tag');
                    tags.forEach(tag => {
                        const text = tag.textContent.replace('×', '').trim();
                        if (text) values.push(text);
                    });
                    dynamicValues[key] = values.join(', ');
                }
            } else if (elementType === 'select') {
                const select = document.getElementById('dynamic_field_' + key);
                let selectedValue = '';
                let isCopiedLinks = false;
                
                if (select) {
                    const selectedOption = select.options[select.selectedIndex];
                    if (selectedOption) {
                        selectedValue = selectedOption.value;
                        isCopiedLinks = selectedOption.dataset.isCopiedLinks === 'true';
                        
                        // For copied links, the value already has domain from getSourceValues
                        // But we need to ensure it's properly formatted
                        if (isCopiedLinks && sourceType === 'copied_links') {
                            // The value already has domain prepended, so we keep it as is
                            // But if it's a JSON string, we need to parse and reconstruct
                            try {
                                const parsed = JSON.parse(selectedValue);
                                if (typeof parsed === 'object' && parsed !== null) {
                                    // It's already in the correct format with domain
                                    // Keep it as is
                                    dynamicValues[key] = selectedValue;
                                } else {
                                    dynamicValues[key] = selectedValue;
                                }
                            } catch (e) {
                                // Not JSON, keep as is
                                dynamicValues[key] = selectedValue;
                            }
                        } else {
                            dynamicValues[key] = selectedValue;
                        }
                    }
                } else {
                    const wrapper = document.getElementById('dynamic_field_' + key);
                    if (wrapper && wrapper.querySelector) {
                        const selectEl = wrapper.querySelector('select');
                        if (selectEl) {
                            const selectedOption = selectEl.options[selectEl.selectedIndex];
                            if (selectedOption) {
                                dynamicValues[key] = selectedOption.value;
                            }
                        }
                    }
                }
            } else {
                const input = document.getElementById('dynamic_field_' + key);
                if (input) {
                    dynamicValues[key] = input.value;
                }
            }
        }
        
        const firstKey = Object.keys(dynamicValues)[0] || 'default';
        const author = dynamicValues[firstKey] || 'default';
        
        const settingsObj = {
            author: author,
            dynamic_values: dynamicValues,
            status: 'pending',
            operation_status: ''
        };
        
        let existingSettings = configData.settings;
        let found = false;
        
        if (Array.isArray(existingSettings)) {
            if (isEditMode && window._editingIndex !== undefined && window._editingIndex !== null) {
                const idx = window._editingIndex;
                if (idx >= 0 && idx < existingSettings.length) {
                    if (existingSettings[idx] && existingSettings[idx].status) {
                        settingsObj.status = existingSettings[idx].status;
                    }
                    if (existingSettings[idx] && existingSettings[idx].operation_status) {
                        settingsObj.operation_status = existingSettings[idx].operation_status;
                    }
                    existingSettings[idx] = settingsObj;
                    found = true;
                }
            } else if (isEditMode && editingAuthor) {
                for (let i = 0; i < existingSettings.length; i++) {
                    if (existingSettings[i] && existingSettings[i].author === editingAuthor) {
                        if (existingSettings[i] && existingSettings[i].status) {
                            settingsObj.status = existingSettings[i].status;
                        }
                        if (existingSettings[i] && existingSettings[i].operation_status) {
                            settingsObj.operation_status = existingSettings[i].operation_status;
                        }
                        existingSettings[i] = settingsObj;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    existingSettings.push(settingsObj);
                }
            } else {
                for (let i = 0; i < existingSettings.length; i++) {
                    if (existingSettings[i] && existingSettings[i].author === author) {
                        existingSettings[i] = settingsObj;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    existingSettings.push(settingsObj);
                }
            }
        } else {
            existingSettings = [settingsObj];
        }
        
        configData.settings = existingSettings;
        
        const postData = {
            settings: configData.settings,
            accounts: configData.accounts || {},
            captions: configData.captions || [],
            timeorders: configData.timeorders || {},
            filters: configData.filters || [],
            dynamic_fields: configData.dynamic_fields || {}
        };
        
        const saveBtn = document.getElementById('saveConfigBtn');
        const originalText = saveBtn.textContent;
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('save_all', 'true');
        formData.append('settings', JSON.stringify(postData.settings));
        formData.append('accounts', JSON.stringify(postData.accounts));
        formData.append('captions', JSON.stringify(postData.captions));
        formData.append('timeorders', JSON.stringify(postData.timeorders));
        formData.append('filters', JSON.stringify(postData.filters));
        formData.append('dynamic_fields', JSON.stringify(postData.dynamic_fields));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showModal('Success', data.message + ' Refreshing...');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showModal('Error', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showModal('Error', 'Error saving data: ' + error.message);
        })
        .finally(() => {
            saveBtn.textContent = originalText;
            saveBtn.disabled = false;
        });
    }
    
    // ===== SAVE SETUP =====
    function saveSetup() {
        const postData = {
            settings: configData.settings || [],
            accounts: configData.accounts || {},
            captions: configData.captions || [],
            timeorders: configData.timeorders || {},
            filters: configData.filters || [],
            dynamic_fields: configData.dynamic_fields || {}
        };
        
        const saveBtn = document.getElementById('saveSetupBtn');
        const originalText = saveBtn.textContent;
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('save_all', 'true');
        formData.append('settings', JSON.stringify(postData.settings));
        formData.append('accounts', JSON.stringify(postData.accounts));
        formData.append('captions', JSON.stringify(postData.captions));
        formData.append('timeorders', JSON.stringify(postData.timeorders));
        formData.append('filters', JSON.stringify(postData.filters));
        formData.append('dynamic_fields', JSON.stringify(postData.dynamic_fields));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showModal('Success', data.message + ' Refreshing...');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showModal('Error', data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showModal('Error', 'Error saving data: ' + error.message);
        })
        .finally(() => {
            saveBtn.textContent = originalText;
            saveBtn.disabled = false;
        });
    }
    function logout() {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=logout'
        })
        .then(() => {
            window.location.href = 'index.php';
        })
        .catch(() => {
            window.location.href = 'index.php';
        });
    }

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

    // Auto-logout after 30 minutes of inactivity
    let activityTimer;
    function resetActivityTimer() {
        if (activityTimer) clearTimeout(activityTimer);
        activityTimer = setTimeout(() => {
            logout();
        }, 30 * 60 * 1000);
    }

    ['click', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
        document.addEventListener(event, resetActivityTimer);
    });

    resetActivityTimer();
    setInterval(checkAuth, 60000);
    
    // ===== INIT =====
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('dashboardView').style.display = 'block';
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('addConfigView').style.display = 'none';
        document.getElementById('setupView').style.display = 'none';
        document.getElementById('configTabs').classList.remove('visible');
        document.getElementById('saveBtnContainer').classList.remove('visible');
        document.getElementById('saveConfigBtn').style.display = 'none';
        document.getElementById('saveSetupBtn').style.display = 'none';
        document.getElementById('backToDashboardBtn').style.display = 'none';
        document.getElementById('scrollBody').classList.remove('tabs-visible');
        document.getElementById('editModeBanner').classList.remove('visible');
        
        document.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('focus', function(e) {
                this.style.fontSize = '16px';
            });
        });
        
        loadDynamicFields();
    });
</script>
</body>
</html>