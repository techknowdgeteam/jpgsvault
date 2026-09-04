<?php
    // sceneiq_server.php - Server Admin Interface for SceneIQ
    session_start();

    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

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

    // ===== FUNCTIONS =====

    function getAllUsers($pdo) {
        $stmt = $pdo->query("SELECT id, email, username, created_at FROM users ORDER BY username");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    function getUserById($pdo, $userId) {
        $stmt = $pdo->prepare("SELECT id, email, username, created_at FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    function createUser($pdo, $email, $username, $password) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, username, password) VALUES (:email, :username, :password)");
        return $stmt->execute([
            ':email' => $email,
            ':username' => $username,
            ':password' => $hashedPassword
        ]);
    }

    function deleteUser($pdo, $userId) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        return $stmt->execute([':id' => $userId]);
    }

    function getGlobalConfig($pdo) {
        $stmt = $pdo->prepare("SELECT id, settings, dynamic_fields FROM sceneiq_config WHERE user_id = 0 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    function getUserConfig($pdo, $userId) {
        $stmt = $pdo->prepare("SELECT id, settings, dynamic_fields, dynamic_tabs FROM sceneiq_config WHERE user_id = :user_id ORDER BY id DESC LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    function saveGlobalConfig($pdo, $settings, $dynamicFields) {
        $config = getGlobalConfig($pdo);
        
        if ($config) {
            $stmt = $pdo->prepare("UPDATE sceneiq_config SET settings = :settings, dynamic_fields = :dynamic_fields WHERE id = :id");
            return $stmt->execute([
                ':settings' => json_encode($settings),
                ':dynamic_fields' => json_encode($dynamicFields),
                ':id' => $config['id']
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO sceneiq_config (user_id, settings, dynamic_fields) VALUES (0, :settings, :dynamic_fields)");
            return $stmt->execute([
                ':settings' => json_encode($settings),
                ':dynamic_fields' => json_encode($dynamicFields)
            ]);
        }
    }

    function saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs = null) {
        $config = getUserConfig($pdo, $userId);
        
        if ($dynamicTabs === null) {
            $dynamicTabs = [];
        }
        
        // Ensure each tab has submit_tab property
        foreach ($dynamicTabs as &$tab) {
            if (!isset($tab['submit_tab'])) {
                $tab['submit_tab'] = true; // Default to true
            }
        }
        
        if ($config) {
            $stmt = $pdo->prepare("UPDATE sceneiq_config SET settings = :settings, dynamic_fields = :dynamic_fields, dynamic_tabs = :dynamic_tabs WHERE id = :id");
            return $stmt->execute([
                ':settings' => json_encode($settings),
                ':dynamic_fields' => json_encode($dynamicFields),
                ':dynamic_tabs' => json_encode($dynamicTabs),
                ':id' => $config['id']
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO sceneiq_config (user_id, settings, dynamic_fields, dynamic_tabs) VALUES (:user_id, :settings, :dynamic_fields, :dynamic_tabs)");
            return $stmt->execute([
                ':user_id' => $userId,
                ':settings' => json_encode($settings),
                ':dynamic_fields' => json_encode($dynamicFields),
                ':dynamic_tabs' => json_encode($dynamicTabs)
            ]);
        }
    }

    function repairSettings($settings) {
        if (!is_array($settings)) return [];
        if (isset($settings[0]) && is_array($settings[0])) {
            return array_values(array_filter($settings, function($item) {
                return is_array($item);
            }));
        }
        if (isset($settings['status']) || isset($settings['dynamic_values'])) {
            return [$settings];
        }
        $result = [];
        foreach ($settings as $key => $value) {
            if (is_array($value) && (isset($value['status']) || isset($value['dynamic_values']))) {
                $result[] = $value;
            }
        }
        return empty($result) ? $settings : $result;
    }

    // ===== HANDLE POST REQUESTS WITH PRG PATTERN =====
    $redirect = false;
    $debugMessages = [];

    // Create User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$email || !$username || !$password) {
            $_SESSION['flash_error'] = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid email address.';
        } elseif (strlen($password) < 6) {
            $_SESSION['flash_error'] = 'Password must be at least 6 characters.';
        } else {
            try {
                if (createUser($pdo, $email, $username, $password)) {
                    $_SESSION['flash_success'] = 'User created successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Failed to create user. Email or username may already exist.';
                }
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
            }
        }
        $redirect = true;
    }

    // Delete User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            if (deleteUser($pdo, $userId)) {
                $_SESSION['flash_success'] = 'User deleted successfully!';
                if (isset($_SESSION['selected_user_id']) && $_SESSION['selected_user_id'] == $userId) {
                    unset($_SESSION['selected_user_id']);
                }
            } else {
                $_SESSION['flash_error'] = 'Failed to delete user.';
            }
        }
        $redirect = true;
    }

    // Select User (set session)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_user'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $_SESSION['selected_user_id'] = $userId;
            unset($_SESSION['dashboard_view']);
        }
        $redirect = true;
    }

    // Clear selected user (go to dashboard)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_user'])) {
        unset($_SESSION['selected_user_id']);
        $_SESSION['dashboard_view'] = 'initialize';
        $redirect = true;
    }

    // Set dashboard view
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_dashboard_view'])) {
        $_SESSION['dashboard_view'] = $_POST['view'] ?? 'initialize';
        $redirect = true;
    }

    // Save Global Dynamic Fields
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_dynamic_fields'])) {
        $debugMessages[] = "=== SAVE GLOBAL DYNAMIC FIELDS DEBUG ===";
        $debugMessages[] = "POST keys: " . implode(', ', array_keys($_POST));
        
        $dynamicFields = [];
        $rawData = '';
        
        if (isset($_POST['dynamic_fields_data']) && !empty($_POST['dynamic_fields_data'])) {
            $rawData = $_POST['dynamic_fields_data'];
            $debugMessages[] = "Raw dynamic_fields_data length: " . strlen($rawData);
            
            $decoded = json_decode($rawData, true);
            if (is_array($decoded) && !empty($decoded)) {
                $dynamicFields = $decoded;
                $debugMessages[] = "SUCCESS: Decoded dynamic_fields_data, count: " . count($dynamicFields);
                $debugMessages[] = "Field keys: " . implode(', ', array_keys($dynamicFields));
            } else {
                $debugMessages[] = "ERROR: Failed to decode dynamic_fields_data";
            }
        }
        
        $config = getGlobalConfig($pdo);
        $settings = $config ? json_decode($config['settings'], true) : [];
        if (!is_array($settings)) $settings = [];
        $settings = repairSettings($settings);
        
        $result = saveGlobalConfig($pdo, $settings, $dynamicFields);
        $debugMessages[] = "Save result: " . ($result ? "TRUE" : "FALSE");
        
        if ($result) {
            $_SESSION['flash_success'] = 'Dynamic fields saved successfully! ' . count($dynamicFields) . ' field(s) saved.';
        } else {
            $_SESSION['flash_error'] = 'Failed to save dynamic fields.';
        }
        
        $_SESSION['debug_messages'] = $debugMessages;
        $redirect = true;
    }

    // Update Global Dynamic Field
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_global_field'])) {
        $oldKey = $_POST['old_key'] ?? '';
        $newKey = $_POST['new_key'] ?? '';
        $fieldData = isset($_POST['field_data']) ? json_decode($_POST['field_data'], true) : [];
        
        error_log("=== UPDATE GLOBAL FIELD ===");
        error_log("Old Key: " . $oldKey);
        error_log("New Key: " . $newKey);
        error_log("Field Data: " . json_encode($fieldData));
        
        if ($oldKey && $newKey && !empty($fieldData)) {
            try {
                $config = getGlobalConfig($pdo);
                $settings = $config ? json_decode($config['settings'], true) : [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $dynamicFields = $config ? json_decode($config['dynamic_fields'], true) : [];
                if (!is_array($dynamicFields)) $dynamicFields = [];
                
                // Remove old key if it exists
                if (isset($dynamicFields[$oldKey])) {
                    unset($dynamicFields[$oldKey]);
                }
                
                // Add with new key
                $dynamicFields[$newKey] = $fieldData;
                
                $result = saveGlobalConfig($pdo, $settings, $dynamicFields);
                error_log("Save result: " . ($result ? "TRUE" : "FALSE"));
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Field updated successfully!']);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update field.']);
                    exit;
                }
            } catch (Exception $e) {
                error_log("Exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
    }

    // Save single global dynamic field
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_single_field'])) {
        $fieldData = isset($_POST['field_data']) ? json_decode($_POST['field_data'], true) : [];
        
        error_log("=== SAVE GLOBAL SINGLE FIELD ===");
        error_log("Field Data: " . json_encode($fieldData));
        
        if (!empty($fieldData)) {
            try {
                $config = getGlobalConfig($pdo);
                $settings = $config ? json_decode($config['settings'], true) : [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $dynamicFields = $config ? json_decode($config['dynamic_fields'], true) : [];
                if (!is_array($dynamicFields)) $dynamicFields = [];
                
                if (isset($fieldData['field_type']) && $fieldData['field_type'] === 'objects') {
                    $fieldKey = $fieldData['field-name'] ?? '';
                    if ($fieldKey) {
                        $dynamicFields[$fieldKey] = [
                            'field-title' => $fieldData['field-title'] ?? $fieldKey,
                            'field_type' => 'objects',
                            'fieldkeyvalue' => $fieldData['fieldkeyvalue'] ?? []
                        ];
                    }
                } else {
                    $fieldKey = $fieldData['field-name'] ?? '';
                    if ($fieldKey) {
                        $dynamicFields[$fieldKey] = [
                            'field-title' => $fieldData['field-title'] ?? $fieldKey,
                            'field_type' => 'individual',
                            'element_type' => $fieldData['element_type'] ?? 'input',
                            'default_value' => $fieldData['default_value'] ?? '',
                            'default_values' => $fieldData['default_values'] ?? []
                        ];
                    }
                }
                
                $result = saveGlobalConfig($pdo, $settings, $dynamicFields);
                error_log("Save result: " . ($result ? "TRUE" : "FALSE"));
                
                if ($result) {
                    $_SESSION['flash_success'] = 'Field saved successfully!';
                    echo json_encode(['success' => true, 'message' => 'Field saved successfully']);
                    exit;
                } else {
                    $_SESSION['flash_error'] = 'Failed to save field.';
                    echo json_encode(['success' => false, 'message' => 'Failed to save field']);
                    exit;
                }
            } catch (Exception $e) {
                error_log("Exception: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                exit;
            }
        } else {
            error_log("ERROR: Invalid data");
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
            exit;
        }
    }

    // Delete Global Dynamic Field
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_global_dynamic_field'])) {
        $fieldKey = $_POST['field_key'] ?? '';
        
        if ($fieldKey) {
            $config = getGlobalConfig($pdo);
            if ($config) {
                $settings = json_decode($config['settings'], true) ?: [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $dynamicFields = json_decode($config['dynamic_fields'], true) ?: [];
                if (!is_array($dynamicFields)) $dynamicFields = [];
                
                if (isset($dynamicFields[$fieldKey])) {
                    unset($dynamicFields[$fieldKey]);
                    saveGlobalConfig($pdo, $settings, $dynamicFields);
                    $_SESSION['flash_success'] = 'Field "' . $fieldKey . '" deleted successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Field not found.';
                }
            }
        }
        $redirect = true;
    }

    // Save Settings for selected user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $settings = isset($_POST['settings']) ? json_decode($_POST['settings'], true) : [];
        
        if ($userId > 0 && is_array($settings)) {
            $settings = repairSettings($settings);
            $config = getUserConfig($pdo, $userId);
            $dynamicFields = $config ? json_decode($config['dynamic_fields'], true) : [];
            if (!is_array($dynamicFields)) $dynamicFields = [];
            $dynamicTabs = $config ? json_decode($config['dynamic_tabs'], true) : [];
            if (!is_array($dynamicTabs)) $dynamicTabs = [];
            
            if (saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs)) {
                $_SESSION['flash_success'] = 'Settings saved successfully!';
            } else {
                $_SESSION['flash_error'] = 'Failed to save settings.';
            }
        }
        $redirect = true;
    }

    // Handle Delete Entry for a user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_entry'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $index = intval($_POST['index'] ?? -1);
        
        if ($userId > 0 && $index >= 0) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $settings = json_decode($config['settings'], true) ?: [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                if ($index < count($settings)) {
                    array_splice($settings, $index, 1);
                    $dynamicFields = json_decode($config['dynamic_fields'], true) ?: [];
                    $dynamicTabs = json_decode($config['dynamic_tabs'], true) ?: [];
                    $result = saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    
                    if ($result) {
                        $_SESSION['flash_success'] = 'Entry deleted successfully!';
                    } else {
                        $_SESSION['flash_error'] = 'Failed to delete entry.';
                    }
                } else {
                    $_SESSION['flash_error'] = 'Entry not found.';
                }
            } else {
                $_SESSION['flash_error'] = 'User config not found.';
            }
        }
        $redirect = true;
    }

    // Handle New Project for a user - UPDATED for submit tabs filtering
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_entry'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $entryData = isset($_POST['entry_data']) ? json_decode($_POST['entry_data'], true) : [];
        
        if ($userId > 0 && is_array($entryData) && !empty($entryData)) {
            $config = getUserConfig($pdo, $userId);
            $globalConfig = getGlobalConfig($pdo);
            $globalDynamicFields = $globalConfig ? json_decode($globalConfig['dynamic_fields'], true) : [];
            
            // Get dynamic tabs with submit_tab settings
            $dynamicTabs = $config ? json_decode($config['dynamic_tabs'], true) : [];
            if (!is_array($dynamicTabs)) $dynamicTabs = [];
            
            // Build list of field keys that should be submitted based on submit_tab settings
            $submittedFieldKeys = [];
            foreach ($dynamicTabs as $tab) {
                $submitTab = isset($tab['submit_tab']) ? $tab['submit_tab'] : true;
                if ($submitTab) {
                    $displayFields = $tab['dynamic_fields_display'] ?? [];
                    foreach ($displayFields as $fieldKey) {
                        $submittedFieldKeys[] = $fieldKey;
                    }
                }
            }
            
            $flatEntry = [];
            foreach ($entryData as $key => $value) {
                if (is_array($value) && isset($value['_object'])) {
                    // Check if this object field should be submitted
                    if (in_array($key, $submittedFieldKeys)) {
                        $objectData = [];
                        foreach ($value as $subKey => $subValue) {
                            if ($subKey !== '_object') {
                                $objectData[$subKey] = $subValue;
                            }
                        }
                        $flatEntry[$key] = $objectData;
                    }
                } else {
                    // Check if this individual field should be submitted
                    if (in_array($key, $submittedFieldKeys)) {
                        $flatEntry[$key] = $value;
                    }
                }
            }
            
            if ($config) {
                $settings = json_decode($config['settings'], true) ?: [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                $newEntry = $flatEntry;
                $newEntry['status'] = 'pending';
                $newEntry['operation_status'] = 'entry_created: New Project added by admin';
                $settings[] = $newEntry;
                
                $dynamicFields = json_decode($config['dynamic_fields'], true) ?: [];
                saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                $_SESSION['flash_success'] = 'Entry added successfully!';
            } else {
                $settings = [];
                $newEntry = $flatEntry;
                $newEntry['status'] = 'pending';
                $newEntry['operation_status'] = 'entry_created: New Project added by admin';
                $settings[] = $newEntry;
                saveUserConfig($pdo, $userId, $settings, $globalDynamicFields, $dynamicTabs);
                $_SESSION['flash_success'] = 'Entry added successfully!';
            }
        } else {
            $_SESSION['flash_error'] = 'No entry data provided.';
        }
        $redirect = true;
    }

    // Update entry status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_entry_status'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $index = intval($_POST['index'] ?? -1);
        $newStatus = $_POST['new_status'] ?? '';
        
        if ($userId > 0 && $index >= 0 && in_array($newStatus, ['pending', 'completed', 'aborted'])) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $settings = json_decode($config['settings'], true) ?: [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                if ($index < count($settings)) {
                    $settings[$index]['status'] = $newStatus;
                    $dynamicFields = json_decode($config['dynamic_fields'], true) ?: [];
                    $dynamicTabs = json_decode($config['dynamic_tabs'], true) ?: [];
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Status updated successfully!';
                }
            }
        }
        $redirect = true;
    }

    // Update entry field data
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_entry_field'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $index = intval($_POST['index'] ?? -1);
        $fieldKey = $_POST['field_key'] ?? '';
        $fieldValue = $_POST['field_value'] ?? '';
        
        if ($userId > 0 && $index >= 0 && $fieldKey) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $settings = json_decode($config['settings'], true) ?: [];
                if (!is_array($settings)) $settings = [];
                $settings = repairSettings($settings);
                
                if ($index < count($settings)) {
                    $settings[$index][$fieldKey] = $fieldValue;
                    $dynamicFields = json_decode($config['dynamic_fields'], true) ?: [];
                    $dynamicTabs = json_decode($config['dynamic_tabs'], true) ?: [];
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Field updated successfully!';
                }
            }
        }
        $redirect = true;
    }

    // ===== DYNAMIC TABS HANDLING =====
    
    // Save Dynamic Tabs for a user (UPDATED with additional_features)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dynamic_tabs'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $tabsData = isset($_POST['tabs_data']) ? json_decode($_POST['tabs_data'], true) : [];
        
        if ($userId > 0 && is_array($tabsData)) {
            $config = getUserConfig($pdo, $userId);
            $settings = $config ? json_decode($config['settings'], true) : [];
            if (!is_array($settings)) $settings = [];
            $settings = repairSettings($settings);
            $dynamicFields = $config ? json_decode($config['dynamic_fields'], true) : [];
            if (!is_array($dynamicFields)) $dynamicFields = [];
            
            // Preserve existing submit_tab values from stored tabs
            $existingTabs = $config ? json_decode($config['dynamic_tabs'], true) : [];
            if (!is_array($existingTabs)) $existingTabs = [];
            
            // Create a lookup of existing submit_tab values by tab_name
            $submitTabLookup = [];
            foreach ($existingTabs as $existingTab) {
                if (isset($existingTab['tab_name'])) {
                    $submitTabLookup[$existingTab['tab_name']] = $existingTab['submit_tab'] ?? true;
                }
            }
            
            // Merge submitted tabs with existing submit_tab values and ensure additional_features
            foreach ($tabsData as &$tab) {
                if (isset($tab['tab_name']) && isset($submitTabLookup[$tab['tab_name']])) {
                    $tab['submit_tab'] = $submitTabLookup[$tab['tab_name']];
                } elseif (!isset($tab['submit_tab'])) {
                    $tab['submit_tab'] = true;
                }
                
                // Ensure additional_features exists with defaults
                if (!isset($tab['additional_features']) || !is_array($tab['additional_features'])) {
                    $tab['additional_features'] = [
                        'copy_button' => false,
                        'transcript_detection' => false,
                        'transcript_with_structured_data_detection' => false
                    ];
                }
                // Ensure all keys exist
                if (!isset($tab['additional_features']['copy_button'])) {
                    $tab['additional_features']['copy_button'] = false;
                }
                if (!isset($tab['additional_features']['transcript_detection'])) {
                    $tab['additional_features']['transcript_detection'] = false;
                }
                if (!isset($tab['additional_features']['transcript_with_structured_data_detection'])) {
                    $tab['additional_features']['transcript_with_structured_data_detection'] = false;
                }
            }
            
            if (saveUserConfig($pdo, $userId, $settings, $dynamicFields, $tabsData)) {
                $_SESSION['flash_success'] = 'Dynamic tabs saved successfully!';
            } else {
                $_SESSION['flash_error'] = 'Failed to save dynamic tabs.';
            }
        }
        $redirect = true;
    }

    // Save Submit Tabs Configuration
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_submit_tabs'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $submitTabsData = isset($_POST['submit_tabs_data']) ? json_decode($_POST['submit_tabs_data'], true) : [];
        
        if ($userId > 0 && is_array($submitTabsData)) {
            $config = getUserConfig($pdo, $userId);
            $settings = $config ? json_decode($config['settings'], true) : [];
            if (!is_array($settings)) $settings = [];
            $settings = repairSettings($settings);
            $dynamicFields = $config ? json_decode($config['dynamic_fields'], true) : [];
            if (!is_array($dynamicFields)) $dynamicFields = [];
            
            // Get existing tabs
            $existingTabs = $config ? json_decode($config['dynamic_tabs'], true) : [];
            if (!is_array($existingTabs)) $existingTabs = [];
            
            // Update ONLY submit_tab values on existing tabs - NO NEW TABS CREATED
            foreach ($submitTabsData as $updatedTab) {
                $tabName = $updatedTab['tab_name'] ?? '';
                if (!$tabName) continue;
                
                // Find and update the existing tab
                foreach ($existingTabs as &$existingTab) {
                    if (($existingTab['tab_name'] ?? '') === $tabName) {
                        $existingTab['submit_tab'] = $updatedTab['submit_tab'] ?? true;
                        break;
                    }
                }
            }
            
            // Save only if we have existing tabs (preserve all other tab properties)
            if (!empty($existingTabs)) {
                if (saveUserConfig($pdo, $userId, $settings, $dynamicFields, $existingTabs)) {
                    $_SESSION['flash_success'] = 'Submit tabs configuration saved successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Failed to save submit tabs configuration.';
                }
            } else {
                $_SESSION['flash_error'] = 'No tabs found to update. Please create tabs in "Dynamic Tabs" first.';
            }
        }
        $redirect = true;
    }
    
    // Delete a dynamic tab
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dynamic_tab'])) {
        $userId = intval($_POST['user_id'] ?? 0);
        $tabIndex = intval($_POST['tab_index'] ?? -1);
        
        if ($userId > 0 && $tabIndex >= 0) {
            $config = getUserConfig($pdo, $userId);
            if ($config) {
                $dynamicTabs = json_decode($config['dynamic_tabs'], true) ?: [];
                if (!is_array($dynamicTabs)) $dynamicTabs = [];
                
                if (isset($dynamicTabs[$tabIndex])) {
                    array_splice($dynamicTabs, $tabIndex, 1);
                    $settings = json_decode($config['settings'], true) ?: [];
                    if (!is_array($settings)) $settings = [];
                    $settings = repairSettings($settings);
                    $dynamicFields = json_decode($config['dynamic_fields'], true) ?: [];
                    if (!is_array($dynamicFields)) $dynamicFields = [];
                    
                    saveUserConfig($pdo, $userId, $settings, $dynamicFields, $dynamicTabs);
                    $_SESSION['flash_success'] = 'Tab deleted successfully!';
                } else {
                    $_SESSION['flash_error'] = 'Tab not found.';
                }
            }
        }
        $redirect = true;
    }

    // Show debug modal
    if (isset($_GET['show_debug'])) {
        $debugMessages = isset($_SESSION['debug_messages']) ? $_SESSION['debug_messages'] : ['No debug messages available'];
        echo '<!DOCTYPE html><html><head><title>Debug Info</title>';
        echo '<style>body{font-family:monospace;padding:20px;background:#1a1a2e;color:#fff;}';
        echo '.debug-box{background:#2d2d44;padding:20px;border-radius:10px;margin:10px 0;overflow:auto;max-height:80vh;}';
        echo '.debug-line{padding:4px 0;border-bottom:1px solid #3d3d5a;}';
        echo '.error{color:#ff6b6b;}';
        echo '.success{color:#51cf66;}';
        echo '.warning{color:#ffd93d;}';
        echo 'pre{background:#1a1a2e;padding:10px;border-radius:5px;overflow:auto;}';
        echo '</style></head><body>';
        echo '<h1> Debug Information</h1>';
        echo '<div class="debug-box">';
        foreach ($debugMessages as $msg) {
            $class = '';
            if (strpos($msg, 'ERROR') !== false) $class = 'error';
            else if (strpos($msg, 'SUCCESS') !== false) $class = 'success';
            else if (strpos($msg, 'WARNING') !== false) $class = 'warning';
            echo '<div class="debug-line ' . $class . '">' . htmlspecialchars($msg) . '</div>';
        }
        echo '</div>';
        echo '<button onclick="window.close()" style="padding:10px 20px;margin-top:20px;cursor:pointer;">Close</button>';
        echo '<button onclick="location.href=\'sceneiq_server.php\'" style="padding:10px 20px;margin-top:20px;margin-left:10px;cursor:pointer;">Back to Admin</button>';
        echo '</body></html>';
        exit;
    }

    // If any POST request that should redirect, do it here
    if ($redirect) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // ===== GET FLASH MESSAGES =====
    $flashSuccess = isset($_SESSION['flash_success']) ? $_SESSION['flash_success'] : null;
    $flashError = isset($_SESSION['flash_error']) ? $_SESSION['flash_error'] : null;
    $debugMessages = isset($_SESSION['debug_messages']) ? $_SESSION['debug_messages'] : [];
    unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['debug_messages']);

    // ===== GET DATA =====
    $users = getAllUsers($pdo);
    $selectedUserId = isset($_SESSION['selected_user_id']) ? intval($_SESSION['selected_user_id']) : 0;
    $dashboardView = isset($_SESSION['dashboard_view']) ? $_SESSION['dashboard_view'] : 'initialize';

    $selectedUser = null;
    $userConfig = null;
    $userSettings = [];
    $userDynamicFields = [];
    $userDynamicTabs = [];
    $globalConfig = getGlobalConfig($pdo);
    $globalDynamicFields = $globalConfig ? json_decode($globalConfig['dynamic_fields'], true) : [];
    if (!is_array($globalDynamicFields)) $globalDynamicFields = [];

    $groupedEntries = ['pending' => [], 'completed' => [], 'aborted' => []];
    $statusLabels = ['pending' => 'Pending', 'completed' => 'Completed', 'aborted' => 'Aborted'];
    $statusColors = ['pending' => '#f59e0b', 'completed' => '#10b981', 'aborted' => '#ef4444'];
    $statusIcons = ['pending' => '', 'completed' => '', 'aborted' => ''];

    // For All Account Config - get all entries across all users
    $allUsersEntries = ['pending' => [], 'completed' => [], 'aborted' => []];
    foreach ($users as $user) {
        $userConf = getUserConfig($pdo, $user['id']);
        if ($userConf) {
            $settings = json_decode($userConf['settings'], true) ?: [];
            if (!is_array($settings)) $settings = [];
            $settings = repairSettings($settings);
            foreach ($settings as $index => $entry) {
                $status = isset($entry['status']) ? strtolower($entry['status']) : 'pending';
                $entry['_user'] = $user['username'];
                $entry['_user_id'] = $user['id'];
                $entry['_index'] = $index;
                $allUsersEntries[$status][] = $entry;
            }
        }
    }

    if ($selectedUserId > 0) {
        $selectedUser = getUserById($pdo, $selectedUserId);
        if ($selectedUser) {
            $userConfig = getUserConfig($pdo, $selectedUserId);
            if ($userConfig) {
                $userSettings = json_decode($userConfig['settings'], true) ?: [];
                if (!is_array($userSettings)) $userSettings = [];
                $userSettings = repairSettings($userSettings);
                
                $userDynamicFields = json_decode($userConfig['dynamic_fields'], true) ?: [];
                if (!is_array($userDynamicFields)) $userDynamicFields = [];
                
                $userDynamicTabs = json_decode($userConfig['dynamic_tabs'], true) ?: [];
                if (!is_array($userDynamicTabs)) $userDynamicTabs = [];
                
                foreach ($userSettings as $index => $entry) {
                    $status = isset($entry['status']) ? strtolower($entry['status']) : 'pending';
                    if (!isset($groupedEntries[$status])) {
                        $groupedEntries[$status] = [];
                    }
                    $entry['_index'] = $index;
                    $groupedEntries[$status][] = $entry;
                }
            } else {
                saveUserConfig($pdo, $selectedUserId, [], $globalDynamicFields, []);
                $userDynamicFields = $globalDynamicFields;
                $userDynamicTabs = [];
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SceneIQ Server - Admin</title>
<style>
        /* ===== RESET & BODY ===== */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        html, body { 
            height: 100%; 
            overflow: hidden;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #ffffff;
        }
        
        input, select, textarea {
            font-size: 16px !important;
        }
        
        .custom-body {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 100%;
        }
        
        /* ===== HEADER ===== */
        .main-header {
            flex-shrink: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #00695c 0%, #00897b 50%, #26a69a 100%);
            color: white;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,105,92,0.3);
            min-height: 60px;
            width: 100%;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .main-header h1 { 
            font-size: 18px; 
            font-weight: 600;
            white-space: nowrap;
        }
        .main-header h1 span { opacity: 0.6; font-weight: 300; }
        
        .debug-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .debug-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        /* ===== SCROLLABLE BODY ===== */
        .scroll-body {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 15px;
            padding-bottom: 30px;
            background: #ffffff;
            width: 100%;
            max-width: 100%;
        }
        
        .scroll-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .scroll-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.02);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #00695c, #26a69a);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #004d40, #00897b);
        }
        
        .server-container {
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            padding: 0 5px;
        }
        
        .two-col {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 20px;
            margin-top: 15px;
            width: 100%;
        }
        
        .sidebar {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
            height: fit-content;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        
        .sidebar h2 {
            font-size: 16px;
            color: #1a1a2e;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-users-btn {
            width: 100%;
            padding: 10px 14px;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            color: #6b7280;
            transition: all 0.3s;
            margin-bottom: 12px;
            text-align: center;
        }
        
        .search-users-btn:hover {
            background: #e5e7eb;
            border-color: #00695c;
            color: #00695c;
        }
        
        .content-area {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
            min-height: 400px;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        
        /* ===== DASHBOARD TABS - HORIZONTAL SCROLL ===== */
        .dashboard-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 48px;
            flex-wrap: nowrap;
        }
        
        .dashboard-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .dashboard-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .dashboard-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .dashboard-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .dashboard-tab-btn {
            padding: 10px 16px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .dashboard-tab-btn:hover {
            color: #1a1a2e;
        }
        
        .dashboard-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .dashboard-content {
            display: none;
            padding-top: 10px;
            width: 100%;
        }
        
        .dashboard-content.active {
            display: block;
        }
        
        /* ===== USER CONFIG TABS - HORIZONTAL SCROLL ===== */
        .user-config-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 48px;
            flex-wrap: nowrap;
        }
        
        .user-config-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .user-config-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .user-config-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .user-config-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .user-config-tab-btn {
            padding: 10px 16px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .user-config-tab-btn:hover {
            color: #1a1a2e;
        }
        
        .user-config-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .user-config-content {
            display: none;
            padding-top: 10px;
            width: 100%;
        }
        
        .user-config-content.active {
            display: block;
        }
        
        /* ===== DYNAMIC SUB TABS ===== */
        .dynamic-sub-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            white-space: nowrap;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
            align-items: stretch;
            min-height: 40px;
            flex-wrap: nowrap;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar {
            height: 3px;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 10px;
        }
        
        .dynamic-sub-tabs::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .dynamic-sub-tab-btn {
            padding: 8px 14px;
            background: none;
            border: none;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .dynamic-sub-tab-btn:hover {
            color: #1a1a2e;
        }
        
        .dynamic-sub-tab-btn.active {
            color: #00695c;
            border-bottom-color: #00695c;
        }
        
        .dynamic-sub-tab-content {
            display: none;
            padding-top: 10px;
            width: 100%;
        }
        
        .dynamic-sub-tab-content.active {
            display: block;
        }
        
        .create-user-form {
            max-width: 500px;
            width: 100%;
            margin: 0 auto;
            padding: 20px;
            background: #fafafa;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }
        
        .create-user-form h3 {
            font-size: 18px;
            color: #1a1a2e;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .create-user-form .form-row {
            display: grid;
            gap: 10px;
            width: 100%;
        }
        
        .create-user-form input {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
            width: 100%;
            box-sizing: border-box;
        }
        
        .create-user-form input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
        }
        
        .create-user-form .btn-create {
            padding: 12px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
            width: 100%;
        }
        
        .create-user-form .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(16,185,129,0.3);
        }
        
        .form-group {
            margin-bottom: 18px;
            width: 100%;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #1a1a2e;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: #fafafa;
            box-sizing: border-box;
            max-width: 100%;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #00695c, #26a69a);
            color: white;
            box-shadow: 0 4px 20px rgba(0,105,92,0.25);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(0,105,92,0.35);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 20px rgba(16,185,129,0.25);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(16,185,129,0.35);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 20px rgba(239,68,68,0.2);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(239,68,68,0.3);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #1a1a2e;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 20px rgba(245,158,11,0.25);
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(245,158,11,0.35);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .user-settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
        }
        
        .user-settings-header .user-info h2 {
            font-size: 20px;
            color: #1a1a2e;
            word-break: break-word;
        }
        
        .user-settings-header .user-info .email {
            font-size: 14px;
            color: #6b7280;
            word-break: break-all;
        }
        
        .config-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 15px;
            width: 100%;
        }
        
        .config-item {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid #00695c;
            transition: all 0.3s;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.05);
            border-left-width: 4px;
            width: 100%;
            overflow: visible;
        }
        
        .config-item:hover {
            box-shadow: 0 8px 32px rgba(0,0,0,0.10);
            transform: translateX(5px);
        }
        
        .config-item .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            width: 100%;
        }
        
        .config-item .item-id {
            font-size: 13px;
            font-weight: 600;
            color: #00695c;
        }
        
        .config-item .item-status {
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
        }
        
        /* ===== ENTRY DISPLAY STYLES - UPDATED FOR OBJECT FIELDS ===== */
        .entry-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
            margin: 10px 0;
        }
        
        .entry-field-group {
            border-radius: 8px;
            overflow: visible;
            width: 100%;
        }
        
        .entry-field-group.individual-field {
            background: #f8fafc;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        
        .entry-field-group.object-field {
            background: #f0fdf4;
            padding: 12px 14px;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
        }
        
        .field-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 4px;
        }
        
        .field-value {
            font-size: 14px;
            color: #1a1a2e;
            word-break: break-word;
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .field-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .field-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .field-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .field-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        .sub-fields-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 8px;
            padding-left: 8px;
            border-left: 2px solid #a7f3d0;
        }
        
        .sub-field-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 6px 10px;
            background: #ffffff;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        
        .sub-field-item .sub-label {
            font-size: 11px;
            font-weight: 500;
            color: #6b7280;
        }
        
        .sub-field-item .sub-value {
            font-size: 13px;
            color: #1a1a2e;
            word-break: break-word;
            max-height: 80px;
            overflow-y: auto;
        }
        
        .sub-field-item .sub-value::-webkit-scrollbar {
            width: 3px;
        }
        
        .sub-field-item .sub-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .sub-field-item .sub-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 3px;
        }
        
        .object-label {
            font-size: 13px;
            font-weight: 600;
            color: #00695c;
            margin-bottom: 6px;
        }
        
        .entry-field-group .field-value {
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        /* Fallback for older browsers */
        .config-item .item-details .detail-label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            flex-shrink: 0;
            padding-bottom: 2px;
        }
        
        .config-item .item-details .detail-value {
            font-size: 13px;
            color: #1a1a2e;
            word-break: break-word;
            max-height: 80px;
            overflow-y: auto;
        }
        
        .config-item .item-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
            width: 100%;
        }
        
        .config-item .item-actions .btn {
            padding: 6px 14px;
            font-size: 12px;
        }
        
        .config-item .item-actions form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .config-item .status-select {
            padding: 4px 8px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 12px;
            background: #fafafa;
            cursor: pointer;
        }
        
        .config-item .status-select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        .entry-user-tag {
            font-size: 12px;
            color: #00695c;
            background: #e0f2f1;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .notification {
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 14px;
            width: 100%;
            box-sizing: border-box;
            word-break: break-word;
        }
        
        .notification.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .notification.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .datetime-group {
            width: 100%;
            box-sizing: border-box;
        }
        
        .datetime-group .date-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            box-sizing: border-box;
        }
        
        .datetime-group .date-input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .datetime-group .time-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
            width: 100%;
        }
        
        .datetime-group .time-group input[type="number"],
        .datetime-group .time-group select {
            padding: 8px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: #fafafa;
            min-width: 50px;
        }
        
        .datetime-group .time-group input[type="number"] {
            width: 60px;
        }
        
        .datetime-group .time-group select {
            width: 70px;
        }
        
        .datetime-group .time-group input[type="number"]:focus,
        .datetime-group .time-group select:focus {
            border-color: #00695c;
            outline: none;
        }
        
        .value-tag {
            display: inline-block;
            background: #e5e7eb;
            padding: 4px 12px;
            border-radius: 12px;
            margin: 3px;
            font-size: 13px;
        }
        
        .value-tag .remove-value {
            cursor: pointer;
            color: #ef4444;
            margin-left: 6px;
        }
        
        .sub-field-group {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            background: #fafafa;
            position: relative;
        }
        
        .remove-sub-field {
            color: #ef4444;
            cursor: pointer;
            float: right;
            font-size: 18px;
            background: none;
            border: none;
            padding: 0 5px;
        }
        
        /* ================================================================
           CUSTOM DIALOG - FIXED FOR ALL SCREEN SIZES
           ================================================================ */
        .custom-dialog-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            min-height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999999 !important;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            padding: 20px;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .custom-dialog-overlay.active {
            display: flex !important;
            opacity: 1;
            visibility: visible;
        }
        
        .custom-dialog-box {
            background: #ffffff;
            border-radius: 16px;
            padding: 30px;
            max-width: 650px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
            animation: dialogSlideIn 0.3s ease;
            position: relative;
            margin: 20px;
            box-sizing: border-box;
            display: block !important;
            visibility: visible !important;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        @keyframes dialogSlideIn {
            from {
                transform: translateY(-40px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }
        
        .custom-dialog-box .dialog-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #1a1a2e;
            display: block;
        }
        
        .custom-dialog-box .dialog-message {
            font-size: 16px;
            color: #6b7280;
            margin-bottom: 20px;
            line-height: 1.6;
            display: block;
        }
        
        .custom-dialog-box .dialog-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
            width: 100%;
            margin-top: 10px;
        }
        
        .custom-dialog-box .dialog-buttons .btn {
            min-width: 80px;
        }
        
        .custom-dialog-box .dialog-buttons .btn-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .custom-dialog-box .dialog-buttons .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(239, 68, 68, 0.3);
        }
        
        .custom-dialog-box .dialog-content-area {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
            max-height: 300px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            width: 100%;
            box-sizing: border-box;
        }
        
        .custom-dialog-box .dialog-content-area:focus {
            outline: 2px solid #00695c;
            outline-offset: 2px;
        }
        
        /* Scrollbar for dialog content */
        .custom-dialog-box .dialog-content-area::-webkit-scrollbar {
            width: 6px;
        }
        
        .custom-dialog-box .dialog-content-area::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .custom-dialog-box .dialog-content-area::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        /* Dialog content wrapper */
        #dialogContent {
            margin-bottom: 15px;
            display: block;
            width: 100%;
        }
        
        #dialogContent > * {
            display: block;
            width: 100%;
        }
        
        /* ===== USER SEARCH MODAL ===== */
        .user-search-modal .custom-dialog-box {
            max-width: 600px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            padding: 0;
            overflow: hidden;
        }
        
        .user-search-modal .dialog-header {
            padding: 20px 25px 15px;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
            background: white;
            border-radius: 16px 16px 0 0;
        }
        
        .user-search-modal .dialog-header h2 {
            font-size: 20px;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        
        .user-search-modal .dialog-header .search-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            background: #fafafa;
            transition: all 0.3s;
        }
        
        .user-search-modal .dialog-header .search-input:focus {
            border-color: #00695c;
            outline: none;
            box-shadow: 0 0 0 4px rgba(0,105,92,0.1);
            background: white;
        }
        
        .user-search-modal .dialog-body {
            flex: 1;
            overflow-y: auto;
            padding: 15px 25px 25px;
        }
        
        .user-search-modal .dialog-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .user-search-modal .dialog-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.02);
            border-radius: 10px;
        }
        
        .user-search-modal .dialog-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #00695c, #26a69a);
            border-radius: 10px;
        }
        
        .user-search-modal .user-search-item {
            padding: 12px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 4px;
            border: 1px solid transparent;
        }
        
        .user-search-modal .user-search-item:hover {
            background: #f3f4f6;
            border-color: #e5e7eb;
        }
        
        .user-search-modal .user-search-item .user-name {
            font-weight: 500;
            color: #1a1a2e;
            font-size: 15px;
        }
        
        .user-search-modal .user-search-item .user-email {
            font-size: 13px;
            color: #6b7280;
        }
        
        .user-search-modal .dialog-footer {
            padding: 15px 25px 20px;
            border-top: 1px solid #e5e7eb;
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            background: white;
            border-radius: 0 0 16px 16px;
        }
        
        
        .copy-btn {
            background: none;
            border: 1px solid #d1d5db;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .copy-btn:hover {
            background: #f3f4f6;
            border-color: #00695c;
            color: #00695c;
        }
        
        /* ===== TOGGLE SWITCH ===== */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 22px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .toggle-switch input:checked + .toggle-slider {
            background-color: #00695c;
        }
        
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(18px);
        }
        
        .tab-editor-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            background: #fafafa;
        }
        
        .tab-editor-item .tab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .tab-editor-item .tab-header .tab-title {
            font-weight: 600;
            color: #1a1a2e;
        }
        
        .field-toggle-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            margin: 2px 0;
            border-radius: 6px;
            background: #f3f4f6;
        }
        
        .field-toggle-item:hover {
            background: #e5e7eb;
        }
        
        .field-toggle-item .field-name {
            font-size: 13px;
            color: #1a1a2e;
        }
        
        /* ===== SCROLLABLE VALUE ===== */
        .scrollable-value {
            max-height: 80px;
            overflow-y: auto;
            padding-right: 5px;
            word-break: break-word;
            display: block;
            width: 100%;
        }
        
        .scrollable-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .scrollable-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .scrollable-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .scrollable-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        /* ================================================================
           RESPONSIVE MEDIA QUERIES
           ================================================================ */
        @media (max-width: 1024px) {
            .two-col {
                grid-template-columns: 250px 1fr;
                gap: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .two-col {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .sidebar {
                max-height: 300px;
                overflow-y: auto;
            }
            
            .content-area {
                padding: 15px;
            }
            
            .main-header {
                padding: 10px 15px;
                min-height: 55px;
            }
            
            .main-header h1 {
                font-size: 16px;
            }
            
            .scroll-body {
                padding: 10px;
            }
            
            .dashboard-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .dashboard-tab-btn {
                padding: 8px 14px;
                font-size: 13px;
                white-space: nowrap;
                min-height: 42px;
            }
            
            .user-config-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .user-config-tab-btn {
                padding: 8px 14px;
                font-size: 13px;
                white-space: nowrap;
                min-height: 42px;
            }
            
            .dynamic-sub-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }
            
            .dynamic-sub-tab-btn {
                padding: 6px 12px;
                font-size: 12px;
                white-space: nowrap;
                min-height: 36px;
            }
            
            .config-item {
                padding: 14px;
            }
            
            .config-item .item-details {
                grid-template-columns: 1fr;
            }
            
            .custom-dialog-box {
                padding: 20px;
                margin: 10px;
                max-width: 95%;
                max-height: 90vh;
            }
            
            .user-search-modal .custom-dialog-box {
                max-width: 95%;
                max-height: 90%;
            }
            
            .user-search-modal .dialog-header {
                padding: 15px 18px 12px;
            }
            
            .user-search-modal .dialog-body {
                padding: 12px 18px 18px;
            }
            
            .create-user-form {
                padding: 15px;
            }
            
            .btn {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
            }
            
            .config-item .item-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .config-item .item-actions form {
                width: 100%;
            }
            
            .config-item .item-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .config-item .status-select {
                width: 100%;
            }
            
            .datetime-group .time-group {
                flex-wrap: wrap;
            }
            
            .datetime-group .time-group input[type="number"] {
                width: 50px;
            }
            
            .datetime-group .time-group select {
                width: 60px;
            }
        }
        
        @media (max-width: 480px) {
            .main-header {
                padding: 8px 12px;
                min-height: 50px;
            }
            
            .main-header h1 {
                font-size: 14px;
            }
            
            .debug-btn {
                padding: 4px 10px;
                font-size: 12px;
            }
            
            .content-area {
                padding: 12px;
            }
            
            .sidebar {
                padding: 12px;
            }
            
            .dashboard-tab-btn {
                padding: 6px 10px;
                font-size: 12px;
                min-height: 38px;
            }
            
            .user-config-tab-btn {
                padding: 6px 10px;
                font-size: 12px;
                min-height: 38px;
            }
            
            .dynamic-sub-tab-btn {
                padding: 5px 10px;
                font-size: 11px;
                min-height: 34px;
            }
            
            .config-item {
                padding: 12px;
            }
            
            .config-item .item-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .custom-dialog-box {
                padding: 15px;
                max-width: 98%;
                max-height: 95vh;
                margin: 10px;
            }
            
            .custom-dialog-box .dialog-title {
                font-size: 18px;
            }
            
            .create-user-form {
                padding: 12px;
            }
            
            .create-user-form input {
                padding: 10px 14px;
                font-size: 14px;
            }
            
            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 8px 12px;
                font-size: 14px;
            }
            
            .btn {
                padding: 8px 14px;
                font-size: 12px;
            }
            
            .btn-sm {
                padding: 4px 10px;
                font-size: 11px;
            }
            
            .user-settings-header .user-info h2 {
                font-size: 18px;
            }
            
            .user-search-modal .custom-dialog-box {
                max-width: 98%;
                max-height: 95%;
            }
        }
        
        .default-value-wrapper {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .default-value-wrapper input {
            width: 100%;
        }
        
        .field-with-default {
            width: 100%;
        }
        
        /* ===== COPY BUTTON STYLES ===== */
        .entry-field-group .field-value {
            max-height: 120px;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar {
            width: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb {
            background: #00695c;
            border-radius: 4px;
        }
        
        .entry-field-group .field-value::-webkit-scrollbar-thumb:hover {
            background: #004d40;
        }
        
        /* Individual field copy button */
        .entry-field-group.individual-field {
            background: #f8fafc;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        
        /* Object field copy button */
        .entry-field-group.object-field {
            background: #f0fdf4;
            padding: 12px 14px;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
        }
        
        /* Copy button styling */
        .btn-secondary.btn-sm {
            padding: 4px 10px;
            font-size: 11px;
            background: #e5e7eb;
            color: #1a1a2e;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-secondary.btn-sm:hover {
            background: #d1d5db;
            transform: translateY(-1px);
        }
        
        /* ================================================================
           DIALOG FIXES FOR LARGE SCREENS - ENSURES VISIBILITY
           ================================================================ */
        .custom-dialog-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            min-height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999999 !important;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
            padding: 20px;
            box-sizing: border-box;
            overflow: hidden;
        }
        
        .custom-dialog-overlay.active {
            display: flex !important;
            opacity: 1;
            visibility: visible;
        }
        
        /* Force dialog to be on top of everything */
        .custom-dialog-overlay,
        .custom-dialog-overlay * {
            z-index: 999999 !important;
        }
        
        /* Ensure dialog box is visible on all screen sizes */
        .custom-dialog-box {
            background: #ffffff;
            border-radius: 16px;
            padding: 30px;
            max-width: 650px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4);
            animation: dialogSlideIn 0.3s ease;
            position: relative;
            margin: 20px;
            box-sizing: border-box;
            display: block !important;
            visibility: visible !important;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Fix for very large screens */
        @media (min-width: 1600px) {
            .custom-dialog-box {
                max-width: 700px;
                padding: 40px;
            }
        }
        
        /* Fix for very small screens */
        @media (max-width: 400px) {
            .custom-dialog-box {
                padding: 12px;
                margin: 5px;
            }
            
            .custom-dialog-box .dialog-title {
                font-size: 16px;
            }
            
            .custom-dialog-box .dialog-message {
                font-size: 14px;
            }
            
            .custom-dialog-box .dialog-buttons .btn {
                font-size: 12px;
                padding: 6px 12px;
                min-width: 60px;
            }
        }
    /* ===== TRANSCRIPT DETECTION STYLES ===== */
    .transcript-indicator {
        margin-top: 8px;
        padding: 8px 12px;
        background: #d1fae5;
        border-radius: 8px;
        border-left: 4px solid #00695c;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .transcript-indicator .indicator-text {
        color: #065f46;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .transcript-indicator .btn-view {
        padding: 4px 12px;
        font-size: 12px;
    }

    /* Transcript viewer modal adjustments */
    #transcriptConversionResult {
        margin-top: 15px;
    }

    #transcriptConversionResult .result-box {
        background: #f0fdf4;
        border-radius: 8px;
        padding: 15px;
        border: 1px solid #a7f3d0;
    }

    #transcriptConversionResult .result-box .result-title {
        color: #065f46;
        font-weight: 600;
        margin-bottom: 8px;
    }

    #transcriptConversionResult .result-box .result-stats {
        font-size: 13px;
        color: #065f46;
    }
    /* Toggle switch styles - ensure these exist */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 40px;
        height: 22px;
        flex-shrink: 0;
    }

    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 22px;
    }

    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    .toggle-switch input:checked + .toggle-slider {
        background-color: #00695c;
    }

    .toggle-switch input:checked + .toggle-slider:before {
        transform: translateX(18px);
    }
</style>
</head>
<body>
    <div class="custom-body">
        <div class="main-header"  style="display: flex;">
            <div>
                <h1>SceneIQ <span>Server</span></h1>
            </div>
            
            <div>
                <button   style="background: none; border: 1px solid linear-gradient(135deg, #00695c 0%, #00897b 50%, #26a69a 100%); padding: 8px 15px; color: white; font-size: 0.8rem; border-radius: 10px;" onclick="window.location.href='index.php'">← Tasks</button>
            </div>
        </div>
        
        <div class="scroll-body">
            <div class="server-container">
                
                <?php if ($flashSuccess): ?>
                    <div class="notification success"> <?php echo htmlspecialchars($flashSuccess); ?></div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="notification error"> <?php echo htmlspecialchars($flashError); ?></div>
                <?php endif; ?>
                
                <div class="two-col">
                    <div class="sidebar">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <h2 style="margin:0;"> Users</h2>
                            <?php if ($selectedUserId > 0): ?>
                                <form method="POST" style="margin:0;">
                                    <button type="submit" name="clear_user" class="btn btn-secondary btn-sm" style="padding:4px 12px; font-size:12px;">Dashboard</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        
                        <button class="search-users-btn" onclick="openUserSearchDialog()">
                            Search Account
                        </button>
                        
                    </div>
                    
                    <div class="content-area">
                        <?php if ($selectedUserId > 0 && $selectedUser): ?>
                            <div class="user-settings-header">
                                <div class="user-info">
                                    <h2><?php echo htmlspecialchars($selectedUser['username']); ?></h2>
                                    <div class="email"><?php echo htmlspecialchars($selectedUser['email']); ?></div>
                                </div>
                            </div>
                            
                            <div class="user-config-tabs">
                                <button class="user-config-tab-btn  active" onclick="switchUserConfigTab('add_entry', this)"> New Project</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('pending', this)"> Pending</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('completed', this)"> Completed</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('aborted', this)"> Aborted</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('dynamic_tabs', this)"> Dynamic Tabs</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('submit_tabs', this)"> Submit Tabs</button>
                                <button class="user-config-tab-btn" onclick="switchUserConfigTab('dynamic_fields', this)"> Dynamic Fields</button>
                            </div>
                            
                            <div id="user_tab_pending" class="user-config-content active">
                                <div id="pendingEntriesList"></div>
                            </div>
                            
                            <div id="user_tab_completed" class="user-config-content">
                                <div id="completedEntriesList"></div>
                            </div>
                            
                            <div id="user_tab_aborted" class="user-config-content">
                                <div id="abortedEntriesList"></div>
                            </div>

                            <!-- Dynamic Fields Tab -->
                            <div id="user_tab_dynamic_fields" class="user-config-content">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">Global Dynamic Fields</h3>
                                <p style="color:#6b7280; margin-bottom:15px;">These fields are used for all users.</p>
                                
                                <div id="userGlobalDynamicFieldsList"></div>
                                
                                <div style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary" onclick="addGlobalDynamicField()">+ Add Field</button>
                                </div>
                            </div>
                            
                            
                            <div id="user_tab_dynamic_tabs" class="user-config-content">
                                <div id="dynamicTabsEditor"></div>
                            </div>

                            <div id="user_tab_submit_tabs" class="user-config-content">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">Submit Tabs Configuration</h3>
                                <p style="color:#6b7280; margin-bottom:15px;">Toggle which tab's fields should be submitted to entries. Tabs turned OFF will have their fields excluded when saving to Pending, Completed, or Aborted.</p>
                                <div id="submitTabsEditor"></div>
                            </div>
                            

                            <div id="user_tab_add_entry" class="user-config-content active">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">Add New Project</h3>
                                <?php
                                    $globalConfig = getGlobalConfig($pdo);
                                    $globalDynamicFields = $globalConfig ? json_decode($globalConfig['dynamic_fields'], true) : [];
                                    if (!is_array($globalDynamicFields)) $globalDynamicFields = [];
                                ?>
                                <?php if (empty($globalDynamicFields)): ?>
                                    <p style="color:#6b7280;">No dynamic fields configured globally. Please add fields in the Dynamic Fields tab first.</p>
                                <?php else: ?>
                                    <!-- Dynamic Sub Tabs for New Project -->
                                    <div class="dynamic-sub-tabs" id="newProjectSubTabs">
                                        <?php 
                                        $firstTabIndex = !empty($userDynamicTabs) ? array_key_first($userDynamicTabs) : -1;
                                        foreach ($userDynamicTabs as $tabIndex => $tab): 
                                            $isActive = ($tabIndex === $firstTabIndex) ? 'active' : '';
                                        ?>
                                            <button class="dynamic-sub-tab-btn <?php echo $isActive; ?>" data-tab="tab_<?php echo $tabIndex; ?>" onclick="switchNewProjectSubTab('tab_<?php echo $tabIndex; ?>', this)"> <?php echo htmlspecialchars($tab['tab_name'] ?? 'Tab ' . ($tabIndex + 1)); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <!-- All Fields Content -->
                                    <div id="newProjectSubTab_all_fields" class="dynamic-sub-tab-content">
                                        <?php renderNewProjectForm($globalDynamicFields, $selectedUserId); ?>
                                    </div>
                                    
                                    <!-- Dynamic Tab Contents -->
                                    <?php 
                                    $firstTabIndex = !empty($userDynamicTabs) ? array_key_first($userDynamicTabs) : -1;
                                    foreach ($userDynamicTabs as $tabIndex => $tab): 
                                        $isActive = ($tabIndex === $firstTabIndex) ? 'active' : '';
                                    ?>
                                        <div id="newProjectSubTab_tab_<?php echo $tabIndex; ?>" class="dynamic-sub-tab-content <?php echo $isActive; ?>">
                                            <?php 
                                                $displayFields = $tab['dynamic_fields_display'] ?? [];
                                                $filteredFields = [];
                                                foreach ($displayFields as $fieldKey) {
                                                    if (isset($globalDynamicFields[$fieldKey])) {
                                                        $filteredFields[$fieldKey] = $globalDynamicFields[$fieldKey];
                                                    }
                                                }
                                                if (!empty($filteredFields)) {
                                                    echo '<div style="margin-bottom:15px; padding:10px; background:#f0fdf4; border-radius:8px; border-left:4px solid #00695c;">';
                                                    if (!empty($tab['header'])) {
                                                        echo '<h4 style="color:#00695c;">' . htmlspecialchars($tab['header']) . '</h4>';
                                                    }
                                                    if (!empty($tab['description'])) {
                                                        echo '<p style="color:#6b7280; font-size:14px;">' . htmlspecialchars($tab['description']) . '</p>';
                                                    }
                                                    echo '</div>';
                                                    renderNewProjectForm($filteredFields, $selectedUserId);
                                                } else {
                                                    echo '<div style="margin-bottom:15px; padding:10px; background:#f0fdf4; border-radius:8px; border-left:4px solid #00695c;">';
                                                    if (!empty($tab['header'])) {
                                                        echo '<h4 style="color:#00695c;">' . htmlspecialchars($tab['header']) . '</h4>';
                                                    }
                                                    if (!empty($tab['description'])) {
                                                        echo '<p style="color:#6b7280; font-size:14px;">' . htmlspecialchars($tab['description']) . '</p>';
                                                    }
                                                    echo '</div>';
                                                }
                                            ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div id="user_tab_pending" class="user-config-content">
                                <div id="pendingEntriesList"></div>
                            </div>

                            <div id="user_tab_completed" class="user-config-content">
                                <div id="completedEntriesList"></div>
                            </div>

                            <div id="user_tab_aborted" class="user-config-content">
                                <div id="abortedEntriesList"></div>
                            </div>
                            
                        <?php else: ?>
                            <div class="dashboard-tabs">
                                <button class="dashboard-tab-btn <?php echo ($dashboardView === 'initialize') ? 'active' : ''; ?>" onclick="switchDashboardTab('initialize', this)"> Initialize Account</button>
                                <button class="dashboard-tab-btn <?php echo ($dashboardView === 'dynamic_fields') ? 'active' : ''; ?>" onclick="switchDashboardTab('dynamic_fields', this)"> Dynamic Fields</button>
                                <button class="dashboard-tab-btn <?php echo ($dashboardView === 'all_config') ? 'active' : ''; ?>" onclick="switchDashboardTab('all_config', this)"> All Account Config</button>
                            </div>
                            
                            <div id="dash_tab_initialize" class="dashboard-content <?php echo ($dashboardView === 'initialize') ? 'active' : ''; ?>">
                                <div class="create-user-form">
                                    <h3> Create New Account</h3>
                                    <form method="POST" id="createUserForm">
                                        <div class="form-row">
                                            <input type="email" name="email" placeholder="Email" required>
                                            <input type="text" name="username" placeholder="Username" required>
                                            <input type="password" name="password" placeholder="Password (min 6 chars)" required minlength="6">
                                            <button type="submit" name="create_user" class="btn-create">Create Account</button>
                                            <button class="btn-create" onclick="window.location.href='users.php'">Login as a Account</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <div id="dash_tab_dynamic_fields" class="dashboard-content <?php echo ($dashboardView === 'dynamic_fields') ? 'active' : ''; ?>">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">Global Dynamic Fields</h3>
                                <p style="color:#6b7280; margin-bottom:15px;">These fields are used for all users.</p>
                                
                                <div id="globalDynamicFieldsList"></div>
                                
                                <div style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary" onclick="addGlobalDynamicField()">+ Add Field</button>
                                </div>
                            </div>
                            
                            <div id="dash_tab_all_config" class="dashboard-content <?php echo ($dashboardView === 'all_config') ? 'active' : ''; ?>">
                                <h3 style="margin-bottom:15px; color:#1a1a2e;">All Account Configuration</h3>
                                
                                <?php if (empty($users)): ?>
                                    <p style="color:#6b7280; text-align:center; padding:40px;">No users found. Create a user first.</p>
                                <?php else: ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ===== CUSTOM DIALOG ===== -->
    <div class="custom-dialog-overlay" id="customDialog">
        <div class="custom-dialog-box">
            <div class="dialog-title" id="dialogTitle">Notification</div>
            <div class="dialog-message" id="dialogMessage">Message here</div>
            <div id="dialogContent" style="margin-bottom:15px;"></div>
            <div class="dialog-buttons" id="dialogButtons">
                <button class="btn btn-primary" onclick="closeDialog()">OK</button>
            </div>
        </div>
    </div>
    
    <!-- ===== USER SEARCH DIALOG ===== -->
    <div class="custom-dialog-overlay user-search-modal" id="userSearchDialog">
        <div class="custom-dialog-box">
            <div class="dialog-header">
                <h2> Select Account</h2>
                <input type="text" class="search-input" id="userSearchInput" placeholder="Type to Search Account..." onkeyup="filterUserSearch()">
            </div>
            <div class="dialog-body" id="userSearchResults">
                <?php foreach ($users as $user): ?>
                    <div class="user-search-item" data-user-id="<?php echo $user['id']; ?>" data-user-name="<?php echo strtolower(htmlspecialchars($user['username'])); ?>" data-user-email="<?php echo strtolower(htmlspecialchars($user['email'])); ?>" onclick="selectUserFromSearch(<?php echo $user['id']; ?>)">
                        <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                        <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="dialog-footer">
                <button class="btn btn-secondary" onclick="closeUserSearchDialog()">Close</button>
            </div>
        </div>
    </div>

<?php
    function renderNewProjectForm($dynamicFields, $selectedUserId) {
        if (empty($dynamicFields)) {
            echo '<p style="color:#6b7280;">No fields available for this view.</p>';
            return;
        }
        ?>
        <form method="POST" id="addEntryForm">
            <input type="hidden" name="add_entry" value="true">
            <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
            <input type="hidden" name="entry_data" id="entryDataInput">
            
            <?php foreach ($dynamicFields as $key => $field): ?>
                <?php if ($field['field_type'] === 'objects'): ?>
                    <div class="form-group" style="border:2px solid #00695c; border-radius:10px; padding:15px; margin-bottom:15px;">
                        <h4 style="color:#00695c; margin-bottom:10px;"> <?php echo htmlspecialchars($field['field-title'] ?? $key); ?></h4>
                        <div id="object_<?php echo $key; ?>_container">
                            <?php 
                                $subFields = $field['fieldkeyvalue'] ?? [];
                                foreach ($subFields as $subKey => $subField):
                                    $elementType = $subField['element_type'] ?? 'input';
                                    $title = $subField['field-title'] ?? $subKey;
                                    $defaultValue = $subField['default_value'] ?? '';
                                    $buttonUrl = $subField['button_url'] ?? '';
                            ?>
                                <div class="sub-field-group">
                                    <label><?php echo htmlspecialchars($title); ?></label>
                                    <?php if ($elementType === 'date-time-input'): ?>
                                        <div class="datetime-group">
                                            <input type="date" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_date" class="date-input" value="<?php echo htmlspecialchars($defaultValue); ?>">
                                            <div class="time-group">
                                                <input type="number" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_hour_12" placeholder="HH" min="1" max="12" style="width:60px;">
                                                <span>:</span>
                                                <input type="number" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_minute_12" placeholder="MM" min="0" max="59" style="width:60px;">
                                                <select id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>_ampm" style="width:70px;">
                                                    <option value="AM">AM</option>
                                                    <option value="PM">PM</option>
                                                </select>
                                                <span style="color:#6b7280; font-size:12px; margin-left:5px;">(12h format)</span>
                                            </div>
                                        </div>
                                    <?php elseif ($elementType === 'textarea'): ?>
                                        <textarea id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" rows="3" placeholder="Enter <?php echo htmlspecialchars($title); ?>..." class="field-with-default new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>"><?php echo htmlspecialchars($defaultValue); ?></textarea>
                                    <?php elseif ($elementType === 'select'): ?>
                                        <select id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" class="new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>">
                                            <option value="">Select...</option>
                                            <?php foreach (($subField['default_values'] ?? []) as $val): ?>
                                                <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($val === $defaultValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($elementType === 'button'): ?>
                                        <div>
                                            <button type="button" class="btn btn-primary" onclick="openUrl('<?php echo htmlspecialchars(trim($buttonUrl), ENT_QUOTES); ?>')" style="padding:8px 20px;">
                                                <?php echo htmlspecialchars($title); ?>
                                            </button>
                                            <input type="hidden" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" value="<?php echo htmlspecialchars($buttonUrl); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>">
                                        </div>
                                    <?php else: ?>
                                        <input type="text" id="entry_<?php echo $key; ?>_<?php echo $subKey; ?>" placeholder="Enter <?php echo htmlspecialchars($title); ?>..." value="<?php echo htmlspecialchars($defaultValue); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>" data-sub-key="<?php echo $subKey; ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="copyObjectFromDOM('object_<?php echo $key; ?>_container')">📋 Copy Element Values</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="copyObjectDefaultValues('object_<?php echo $key; ?>_container')">📋 Copy Default Values</button>
                        </div>
                        <input type="hidden" id="object_<?php echo $key; ?>_data" value="">
                    </div>
                <?php else: ?>
                    <div class="form-group" id="field_group_<?php echo $key; ?>">
                        <label><?php echo htmlspecialchars($field['field-title'] ?? $key); ?></label>
                        <?php 
                            $elementType = $field['element_type'] ?? 'input';
                            $defaultValue = $field['default_value'] ?? '';
                            $defaultValues = $field['default_values'] ?? [];
                            $buttonUrl = $field['button_url'] ?? '';
                        ?>
                        <?php if ($elementType === 'date-time-input'): ?>
                            <div class="datetime-group" id="field_<?php echo $key; ?>_container">
                                <input type="date" id="entry_<?php echo $key; ?>_date" class="date-input new-project-field" data-field-key="<?php echo $key; ?>" value="<?php echo htmlspecialchars($defaultValue); ?>">
                                <div class="time-group">
                                    <input type="number" id="entry_<?php echo $key; ?>_hour_12" placeholder="HH" min="1" max="12" style="width:60px;">
                                    <span>:</span>
                                    <input type="number" id="entry_<?php echo $key; ?>_minute_12" placeholder="MM" min="0" max="59" style="width:60px;">
                                    <select id="entry_<?php echo $key; ?>_ampm" style="width:70px;">
                                        <option value="AM">AM</option>
                                        <option value="PM">PM</option>
                                    </select>
                                    <span style="color:#6b7280; font-size:12px; margin-left:5px;">(12h format)</span>
                                </div>
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM('field_<?php echo $key; ?>_container')">📋 Copy Element Value</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue('field_<?php echo $key; ?>_container')">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php elseif ($elementType === 'textarea'): ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <textarea id="entry_<?php echo $key; ?>" rows="3" placeholder="Enter <?php echo htmlspecialchars($field['field-title'] ?? $key); ?>..." class="field-with-default new-project-field" data-field-key="<?php echo $key; ?>"><?php echo htmlspecialchars($defaultValue); ?></textarea>
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM('field_<?php echo $key; ?>_container')">📋 Copy Element Value</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue('field_<?php echo $key; ?>_container')">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php elseif ($elementType === 'select'): ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <select id="entry_<?php echo $key; ?>" class="new-project-field" data-field-key="<?php echo $key; ?>">
                                    <option value="">Select...</option>
                                    <?php foreach ($defaultValues as $val): ?>
                                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($val === $defaultValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM('field_<?php echo $key; ?>_container')">📋 Copy Element Value</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue('field_<?php echo $key; ?>_container')">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php elseif ($elementType === 'button'): ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <button type="button" class="btn btn-primary" onclick="openUrl('<?php echo htmlspecialchars(trim($buttonUrl), ENT_QUOTES); ?>')" style="padding:8px 20px;">
                                    <?php echo htmlspecialchars($field['field-title'] ?? $key); ?>
                                </button>
                                <input type="hidden" id="entry_<?php echo $key; ?>" value="<?php echo htmlspecialchars($buttonUrl); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>">
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM('field_<?php echo $key; ?>_container')">📋 Copy Element Value</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div id="field_<?php echo $key; ?>_container">
                                <input type="text" id="entry_<?php echo $key; ?>" placeholder="Enter <?php echo htmlspecialchars($field['field-title'] ?? $key); ?>..." value="<?php echo htmlspecialchars($defaultValue); ?>" class="new-project-field" data-field-key="<?php echo $key; ?>">
                                <div style="margin-top:5px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualFromDOM('field_<?php echo $key; ?>_container')">📋 Copy Element Value</button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyIndividualDefaultValue('field_<?php echo $key; ?>_container')">📋 Copy Default Value</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <div class="form-actions">
                <button type="button" class="btn btn-success" onclick="submitUserEntry()"> Save Entry</button>
            </div>
        </form>
        <?php
    }
?>

<script>
    function openUrl(url) {
        if (!url) return;
        // If URL doesn't have a protocol, add https://
        if (!url.match(/^https?:\/\//i)) {
            url = 'https://' + url;
        }
        window.open(url, '_blank');
    }
    // ===== DATA =====
    var selectedUserId = <?php echo json_encode($selectedUserId); ?>;
    var globalDynamicFields = <?php echo json_encode($globalDynamicFields); ?>;
    var userSettings = <?php echo json_encode($userSettings); ?>;
    var userDynamicTabs = <?php echo json_encode($userDynamicTabs); ?>;
    var allUsersEntries = <?php echo json_encode($allUsersEntries); ?>;
    var _pendingSaveData = null;

    // ============================================================
    // ===== BACKGROUND SYNC FUNCTIONS - KEEPS DATA FRESH =====
    // ============================================================

    window._fieldValueCache = {};

    function syncFieldValues() {
        var activeContent = document.querySelector('#user_tab_add_entry .dynamic-sub-tab-content.active');
        if (!activeContent) return;
        
        var fields = activeContent.querySelectorAll('.new-project-field');
        fields.forEach(function(field) {
            var id = field.id;
            if (!id) return;
            var value = field.value || '';
            var cacheKey = id;
            if (id.startsWith('entry_')) {
                cacheKey = id.replace('entry_', '');
            }
            window._fieldValueCache[cacheKey] = value;
        });
        
        var objectContainers = activeContent.querySelectorAll('[id^="object_"]');
        objectContainers.forEach(function(container) {
            var containerId = container.id;
            if (!containerId) return;
            var objectKey = containerId.replace('object_', '').replace('_container', '');
            var subFields = container.querySelectorAll('.sub-field-group');
            var objectData = {};
            subFields.forEach(function(subField) {
                var inputs = subField.querySelectorAll('input, textarea, select');
                inputs.forEach(function(input) {
                    if (input.id && input.id.startsWith('entry_' + objectKey + '_')) {
                        var subKey = input.id.replace('entry_' + objectKey + '_', '');
                        if (subKey.includes('_date') || subKey.includes('_hour_12') || 
                            subKey.includes('_minute_12') || subKey.includes('_ampm')) {
                            return;
                        }
                        objectData[subKey] = input.value || '';
                    }
                });
            });
            window._fieldValueCache[objectKey] = objectData;
        });
    }

    var syncInterval = null;

    function startBackgroundSync() {
        if (syncInterval) clearInterval(syncInterval);
        syncInterval = setInterval(function() {
            syncFieldValues();
        }, 1000);
    }

    function stopBackgroundSync() {
        if (syncInterval) {
            clearInterval(syncInterval);
            syncInterval = null;
        }
    }

    // ============================================================
    // ===== COPY FUNCTIONS =====
    // ============================================================

    function copyIndividualFromDOM(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            showDialog('Error', 'Field not found');
            return;
        }
        var valueElement = container.querySelector('input, textarea, select');
        if (!valueElement) {
            showDialog('Error', 'Value element not found');
            return;
        }
        var id = valueElement.id;
        if (!id) {
            showDialog('Error', 'Element ID not found');
            return;
        }
        var textToCopy = '';
        var cacheKey = id.replace('entry_', '');
        if (window._fieldValueCache && window._fieldValueCache[cacheKey] !== undefined) {
            textToCopy = window._fieldValueCache[cacheKey];
        } else {
            textToCopy = valueElement.value || valueElement.textContent || '';
        }
        if (!textToCopy || textToCopy.trim() === '') {
            showDialog('Info', 'No value to copy. Please enter some text first.');
            return;
        }
        copyToClipboard(textToCopy);
    }

    function copyObjectFromDOM(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            showDialog('Error', 'Object container not found');
            return;
        }
        var objectKey = containerId.replace('object_', '').replace('_container', '');
        var objectData = {};
        if (window._fieldValueCache && window._fieldValueCache[objectKey]) {
            objectData = window._fieldValueCache[objectKey];
        } else {
            var subFields = container.querySelectorAll('.sub-field-group');
            subFields.forEach(function(subField) {
                var inputs = subField.querySelectorAll('input, textarea, select');
                inputs.forEach(function(input) {
                    if (input.id && input.id.startsWith('entry_' + objectKey + '_')) {
                        var subKey = input.id.replace('entry_' + objectKey + '_', '');
                        if (subKey.includes('_date') || subKey.includes('_hour_12') || 
                            subKey.includes('_minute_12') || subKey.includes('_ampm')) {
                            return;
                        }
                        objectData[subKey] = input.value || '';
                    }
                });
            });
        }
        var subKeys = Object.keys(objectData);
        if (subKeys.length === 0) {
            showDialog('Info', 'No values to copy');
            return;
        }
        var copyText = '';
        subKeys.forEach(function(subKey) {
            var value = objectData[subKey];
            if (value && value.trim() !== '') {
                if (copyText) copyText += '\n';
                copyText += value.trim();
            }
        });
        if (!copyText) {
            showDialog('Info', 'No values to copy. Please enter some text first.');
            return;
        }
        copyToClipboard(copyText);
    }

    function copyIndividualDefaultValue(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            showDialog('Error', 'Field not found');
            return;
        }
        var valueElement = container.querySelector('input, textarea, select');
        if (!valueElement) {
            showDialog('Error', 'Value element not found');
            return;
        }
        var textToCopy = '';
        if (valueElement.tagName.toLowerCase() === 'select') {
            var selectedOption = valueElement.options[valueElement.selectedIndex];
            textToCopy = selectedOption ? selectedOption.value : '';
        } else {
            textToCopy = valueElement.getAttribute('value') || valueElement.value || '';
        }
        if (!textToCopy || textToCopy.trim() === '') {
            showDialog('Info', 'No default value set for this field.');
            return;
        }
        copyToClipboard(textToCopy);
    }

    function copyObjectDefaultValues(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            showDialog('Error', 'Object container not found');
            return;
        }
        var subFields = container.querySelectorAll('.sub-field-group');
        if (subFields.length === 0) {
            showDialog('Info', 'No sub-fields to copy');
            return;
        }
        var copyText = '';
        subFields.forEach(function(subField) {
            var inputs = subField.querySelectorAll('input, textarea, select');
            var value = '';
            inputs.forEach(function(input) {
                if (input.id && (input.id.includes('_date') || input.id.includes('_hour_12') || 
                    input.id.includes('_minute_12') || input.id.includes('_ampm'))) {
                    return;
                }
                var val = '';
                if (input.tagName.toLowerCase() === 'select') {
                    var selectedOption = input.options[input.selectedIndex];
                    val = selectedOption ? selectedOption.value : '';
                } else {
                    val = input.getAttribute('value') || input.value || '';
                }
                if (val && val.trim() !== '') {
                    if (value) value += ' ';
                    value += val.trim();
                }
            });
            if (value && value.trim() !== '') {
                if (copyText) copyText += '\n';
                copyText += value.trim();
            }
        });
        if (!copyText) {
            showDialog('Info', 'No default values set for this object.');
            return;
        }
        copyToClipboard(copyText);
    }

    function copyToClipboard(text) {
        var textToCopy = typeof text === 'string' ? text : String(text);
        if (!textToCopy || textToCopy.trim() === '') {
            showDialog('Info', 'No text to copy');
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                showDialog('Success', 'Copied to clipboard!');
            }).catch(function() {
                fallbackCopy(textToCopy);
            });
        } else {
            fallbackCopy(textToCopy);
        }
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '-9999px';
        textarea.style.width = '1px';
        textarea.style.height = '1px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showDialog('Success', 'Copied to clipboard!');
        } catch (e) {
            showDialog('Error', 'Failed to copy. Please select and copy manually.');
        }
        document.body.removeChild(textarea);
    }

    // ============================================================
    // ===== CUSTOM DIALOG =====
    // ============================================================

    function showDialog(title, message, buttons, content) {
        console.log('showDialog called with title:', title);
        
        document.getElementById('dialogTitle').textContent = title;
        document.getElementById('dialogMessage').textContent = message;
        
        var contentDiv = document.getElementById('dialogContent');
        if (content) {
            contentDiv.innerHTML = content;
            contentDiv.style.display = 'block';
            console.log('Content set, length:', content.length);
        } else {
            contentDiv.innerHTML = '';
            contentDiv.style.display = 'none';
        }
        
        var buttonsDiv = document.getElementById('dialogButtons');
        if (buttons && buttons.length > 0) {
            buttonsDiv.innerHTML = buttons.map(function(b, i) {
                return '<button class="btn ' + (b.class || 'btn-primary') + '" onclick="dialogButtonClick(' + i + ')">' + b.label + '</button>';
            }).join('');
            window._dialogCallbacks = buttons.map(function(b) { return b.callback || null; });
        } else {
            buttonsDiv.innerHTML = '<button class="btn btn-primary" onclick="closeDialog()">OK</button>';
            window._dialogCallbacks = [null];
        }
        
        var dialog = document.getElementById('customDialog');
        if (dialog) {
            dialog.style.display = 'flex';
            dialog.classList.add('active');
            console.log('Dialog overlay activated');
            
            void dialog.offsetHeight;
            
            var dialogBox = dialog.querySelector('.custom-dialog-box');
            if (dialogBox) {
                dialogBox.style.display = 'block';
                dialogBox.style.visibility = 'visible';
            }
        } else {
            console.error('Dialog overlay not found!');
        }
    }
    
    function dialogButtonClick(index) {
        var callback = window._dialogCallbacks ? window._dialogCallbacks[index] : null;
        if (callback && typeof callback === 'function') {
            callback();
        }
        closeDialog();
    }
    
    function closeDialog() {
        var dialog = document.getElementById('customDialog');
        if (dialog) {
            dialog.classList.remove('active');
            dialog.style.display = 'none';
        }
        document.getElementById('dialogContent').innerHTML = '';
        window._dialogCallbacks = null;
    }
    
    document.getElementById('customDialog').addEventListener('click', function(e) {
        if (e.target === this) closeDialog();
    });
    
    function confirmAction(title, message, callback) {
        showDialog(title, message, [
            { label: 'Cancel', class: 'btn-secondary', callback: null },
            { label: 'Confirm', class: 'btn-confirm', callback: callback }
        ]);
    }

    // ============================================================
    // ===== DASHBOARD TAB SWITCHING =====
    // ============================================================

    function switchDashboardTab(tabName, btn) {
        document.querySelectorAll('.dashboard-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('.dashboard-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        document.getElementById('dash_tab_' + tabName).classList.add('active');
        if (btn) btn.classList.add('active');
        var formData = new FormData();
        formData.append('set_dashboard_view', 'true');
        formData.append('view', tabName);
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        if (tabName === 'dynamic_fields') {
            renderGlobalDynamicFields();
        } else if (tabName === 'all_config') {
            renderAllUsersEntries('pending', 'allUsersPendingList');
        }
    }

    function switchAllUsersConfigTab(tabName, btn) {
        document.querySelectorAll('#dash_tab_all_config .user-config-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('#dash_tab_all_config .user-config-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        var targetId = 'allusers_tab_' + tabName;
        document.getElementById(targetId).classList.add('active');
        if (btn) btn.classList.add('active');
        var listId = tabName === 'pending' ? 'allUsersPendingList' : 
                       tabName === 'completed' ? 'allUsersCompletedList' : 'allUsersAbortedList';
        renderAllUsersEntries(tabName, listId);
    }

    function renderAllUsersEntries(status, containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';
        var entries = allUsersEntries[status] || [];
        if (entries.length === 0) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">No ' + status + ' entries found across all users.</p>';
            return;
        }
        var statusColors = {
            'pending': '#f59e0b',
            'completed': '#10b981',
            'aborted': '#ef4444'
        };
        var statusLabels = {
            'pending': 'Pending',
            'completed': 'Completed',
            'aborted': 'Aborted'
        };
        entries.forEach(function(entry, idx) {
            var div = document.createElement('div');
            div.className = 'config-item';
            var entryStatus = (entry.status || 'pending').toLowerCase();
            var username = entry._user || 'Unknown';
            var userId = entry._user_id || 0;
            var index = entry._index || 0;
            var fieldsHtml = renderEntryFields(entry, index);
            div.innerHTML = 
                '<div class="item-header">' +
                '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">' +
                '<span class="item-id">Entry #' + (idx + 1) + '</span>' +
                '<span class="entry-user-tag"> ' + username + '</span>' +
                '</div>' +
                '<span class="item-status" style="background: ' + (statusColors[entryStatus] || '#6b7280') + '">' + (statusLabels[entryStatus] || entryStatus) + '</span>' +
                '</div>' +
                fieldsHtml +
                '<div class="item-actions">' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="delete_entry" value="true">' +
                '<input type="hidden" name="user_id" value="' + userId + '">' +
                '<input type="hidden" name="index" value="' + index + '">' +
                '<button type="button" class="btn btn-danger btn-sm" onclick="confirmAction(\'Delete Entry\', \'Delete this entry for ' + username + '?\', function(){ this.closest(\'form\').submit(); }.bind(this))"> Delete</button>' +
                '</form>' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="update_entry_status" value="true">' +
                '<input type="hidden" name="user_id" value="' + userId + '">' +
                '<input type="hidden" name="index" value="' + index + '">' +
                '<select name="new_status" class="status-select" onchange="this.form.submit()">' +
                '<option value="pending" ' + (entryStatus === 'pending' ? 'selected' : '') + '> Pending</option>' +
                '<option value="completed" ' + (entryStatus === 'completed' ? 'selected' : '') + '> Completed</option>' +
                '<option value="aborted" ' + (entryStatus === 'aborted' ? 'selected' : '') + '> Aborted</option>' +
                '</select>' +
                '</form>' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="select_user" value="true">' +
                '<input type="hidden" name="user_id" value="' + userId + '">' +
                '<button type="submit" class="btn btn-primary btn-sm"> View Account</button>' +
                '</form>' +
                '</div>';
            container.appendChild(div);
        });
    }

    // ============================================================
    // ===== USER CONFIG TAB SWITCHING =====
    // ============================================================

    function switchUserConfigTab(tabName, btn) {
        document.querySelectorAll('.user-config-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('.user-config-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        document.getElementById('user_tab_' + tabName).classList.add('active');
        if (btn) btn.classList.add('active');
        if (tabName === 'pending') {
            renderStatusEntries('pending', 'pendingEntriesList');
        } else if (tabName === 'completed') {
            renderStatusEntries('completed', 'completedEntriesList');
        } else if (tabName === 'aborted') {
            renderStatusEntries('aborted', 'abortedEntriesList');
        } else if (tabName === 'dynamic_tabs') {
            renderDynamicTabsEditor();
        } else if (tabName === 'submit_tabs') {
            renderSubmitTabsEditor();
        } else if (tabName === 'dynamic_fields') {
            renderUserGlobalDynamicFields();
        }
    }

    function renderSubmitTabsEditor() {
        var container = document.getElementById('submitTabsEditor');
        if (!container) return;
        container.innerHTML = '';
        
        var tabs = userDynamicTabs || [];
        var globalFields = globalDynamicFields || {};
        var fieldKeys = Object.keys(globalFields);
        
        var html = '';
        
        if (tabs.length === 0) {
            html += '<p style="color:#6b7280; text-align:center; padding:20px;">No dynamic tabs configured. Please add tabs in the "Dynamic Tabs" tab first.</p>';
        } else {
            html += '<div style="margin-bottom:15px;">';
            html += '<p style="color:#6b7280; font-size:14px;">Toggle the switches below to control which tab\'s fields are submitted to entries.</p>';
            html += '<p style="color:#6b7280; font-size:14px; margin-top:5px;"><strong style="color:#10b981;">Green</strong> = Fields will be submitted &nbsp;|&nbsp; <strong style="color:#6b7280;">Gray</strong> = Fields will be excluded</p>';
            html += '</div>';
            
            html += '<form method="POST" id="submitTabsForm">';
            html += '<input type="hidden" name="save_submit_tabs" value="true">';
            html += '<input type="hidden" name="user_id" value="' + selectedUserId + '">';
            html += '<input type="hidden" name="submit_tabs_data" id="submitTabsDataInput">';
            
            tabs.forEach(function(tab, index) {
                var isSubmitted = tab.submit_tab !== undefined ? tab.submit_tab : true;
                var tabFields = tab.dynamic_fields_display || [];
                var fieldLabels = tabFields.map(function(fk) {
                    var field = globalFields[fk];
                    return field ? field['field-title'] || fk : fk;
                });
                
                html += '<div class="tab-editor-item" style="border:1px solid ' + (isSubmitted ? '#10b981' : '#e5e7eb') + '; border-radius:10px; padding:15px; margin-bottom:12px; background:' + (isSubmitted ? '#f0fdf4' : '#fafafa') + ';">';
                html += '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">';
                html += '<div>';
                html += '<strong style="font-size:16px; color:#1a1a2e;">' + (tab.tab_name || 'Tab #' + (index + 1)) + '</strong>';
                html += '<div style="font-size:13px; color:#6b7280; margin-top:4px;">Fields: ' + (fieldLabels.length > 0 ? fieldLabels.join(', ') : 'No fields assigned') + '</div>';
                html += '</div>';
                html += '<div style="display:flex; align-items:center; gap:12px;">';
                html += '<span style="font-size:13px; color:' + (isSubmitted ? '#10b981' : '#6b7280') + '; font-weight:500;">' + (isSubmitted ? 'Submitted' : 'Excluded') + '</span>';
                html += '<label class="toggle-switch" style="position:relative; display:inline-block; width:50px; height:26px; flex-shrink:0;">';
                html += '<input type="checkbox" class="submit-tab-toggle" data-tab-index="' + index + '" ' + (isSubmitted ? 'checked' : '') + ' style="opacity:0; width:0; height:0;">';
                html += '<span class="toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:' + (isSubmitted ? '#10b981' : '#ccc') + '; transition:.4s; border-radius:26px;"></span>';
                html += '</label>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
            
            html += '<div class="form-actions">';
            html += '<button type="button" class="btn btn-success" onclick="saveSubmitTabs()"> Save Submit Tabs Configuration</button>';
            html += '</div>';
            html += '</form>';
        }
        
        container.innerHTML = html;
    }

    function saveSubmitTabs() {
        var tabItems = document.querySelectorAll('.tab-editor-item');
        var updatedTabs = [];
        var processedIndices = new Set();
        
        tabItems.forEach(function(item) {
            var toggle = item.querySelector('.submit-tab-toggle');
            var indexAttr = item.querySelector('.submit-tab-toggle');
            if (!indexAttr) return;
            
            var index = parseInt(indexAttr.getAttribute('data-tab-index'));
            if (isNaN(index)) return;
            
            if (userDynamicTabs[index]) {
                var tab = userDynamicTabs[index];
                tab.submit_tab = toggle ? toggle.checked : true;
                updatedTabs.push(tab);
                processedIndices.add(index);
            }
        });
        
        userDynamicTabs.forEach(function(tab, idx) {
            if (!processedIndices.has(idx)) {
                if (tab.submit_tab === undefined) tab.submit_tab = true;
                updatedTabs.push(tab);
            }
        });
        
        updatedTabs = updatedTabs.map(function(tab) {
            if (tab.submit_tab === undefined) tab.submit_tab = true;
            return tab;
        });
        
        userDynamicTabs = updatedTabs;
        document.getElementById('submitTabsDataInput').value = JSON.stringify(updatedTabs);
        document.getElementById('submitTabsForm').submit();
    }

    // ===== UPDATE renderUserGlobalDynamicFields FUNCTION =====
    // Replace the entire function with this version

    function renderUserGlobalDynamicFields() {
        var container = document.getElementById('userGlobalDynamicFieldsList');
        if (!container) return;
        container.innerHTML = '';
        var fields = globalDynamicFields;
        var fieldKeys = Object.keys(fields);
        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">No dynamic fields configured.</p>';
            return;
        }
        for (var i = 0; i < fieldKeys.length; i++) {
            var key = fieldKeys[i];
            var field = fields[key];
            var div = document.createElement('div');
            div.className = 'form-group';
            div.style.border = '1px solid #e5e7eb';
            div.style.borderRadius = '10px';
            div.style.padding = '15px';
            div.style.marginBottom = '12px';
            div.style.background = '#fafafa';
            var fieldInfo = '';
            var editFieldsHtml = '';
            if (field.field_type === 'objects') {
                var subFields = field.fieldkeyvalue || {};
                var subKeys = Object.keys(subFields);
                fieldInfo = 
                    '<div><strong>Type:</strong> Objects Field</div>' +
                    '<div><strong>Sub-fields:</strong> ' + subKeys.length + '</div>' +
                    subKeys.map(function(sk) { 
                        var sub = subFields[sk];
                        var subElement = sub.element_type || 'input';
                        var extraInfo = subElement === 'button' ? ' (Button → ' + (sub.button_url || 'No URL') + ')' : '';
                        return '<div style="font-size:12px; color:#6b7280; margin-left:10px;">• ' + sk + ' (' + subElement + extraInfo + ')</div>'; 
                    }).join('');
                editFieldsHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="editGlobalDynamicField(\'' + key + '\')"> Edit Field</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteGlobalDynamicField(\'' + key + '\')" style="margin-left:8px;"> Delete</button>' +
                    '</div>';
            } else {
                var defaultValue = field.default_value || '';
                var defaultValues = field.default_values || [];
                var buttonUrl = field.button_url || '';
                var elementType = field.element_type || 'input';
                var extraInfo = elementType === 'button' ? ' (URL: ' + buttonUrl + ')' : '';
                fieldInfo = 
                    '<div><strong>Type:</strong> Individual Field</div>' +
                    '<div><strong>Element:</strong> ' + elementType + extraInfo + '</div>' +
                    (defaultValue ? '<div><strong>Default Value:</strong> ' + defaultValue + '</div>' : '') +
                    (defaultValues.length > 0 ? '<div><strong>Select Options:</strong> ' + defaultValues.join(', ') + '</div>' : '');
                editFieldsHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="editGlobalDynamicField(\'' + key + '\')"> Edit Field</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteGlobalDynamicField(\'' + key + '\')" style="margin-left:8px;"> Delete</button>' +
                    '</div>';
            }
            div.innerHTML = 
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">' +
                '<strong style="color:#1a1a2e;">' + (field['field-title'] || key) + '</strong>' +
                '<div>' +
                '<span style="font-size:12px; color:#6b7280; background:#e5e7eb; padding:2px 10px; border-radius:12px;">' + (field.field_type || 'individual') + '</span>' +
                '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px; color:#6b7280;">' +
                '<div><strong>Field Key:</strong> ' + key + '</div>' +
                fieldInfo +
                '</div>' +
                editFieldsHtml;
            container.appendChild(div);
        }
    }

    // ============================================================
    // ===== SWITCH STATUS SUB TAB =====
    // ============================================================

    function switchStatusSubTab(status, tabId, btn) {
        var container = document.getElementById(status + 'SubTabs');
        if (container) {
            container.querySelectorAll('.dynamic-sub-tab-btn').forEach(function(el) { el.classList.remove('active'); });
            if (btn) btn.classList.add('active');
        }
        var contentContainer = document.getElementById('user_tab_' + status);
        if (contentContainer) {
            contentContainer.querySelectorAll('.dynamic-sub-tab-content').forEach(function(el) { el.classList.remove('active'); });
            var target = document.getElementById(status + 'SubTab_' + tabId);
            if (target) {
                target.classList.add('active');
            }
        }
        if (tabId === 'all_fields') {
            renderStatusEntries(status, status + 'EntriesList');
        } else {
            var tabIndex = parseInt(tabId.replace('tab_', ''));
            if (!isNaN(tabIndex)) {
                renderStatusEntriesWithFilter(status, status + 'EntriesList_tab_' + tabIndex, tabIndex);
            }
        }
    }

    // ============================================================
    // ===== RENDER STATUS ENTRIES =====
    // ============================================================

    function renderStatusEntries(status, containerId) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var entries = userSettings.filter(function(entry, index) {
            var entryStatus = (entry.status || 'pending').toLowerCase();
            if (entryStatus === status) {
                entry._index = index;
                return true;
            }
            return false;
        });
        renderEntryList(entries, container, status);
    }

    function renderStatusEntriesWithFilter(status, containerId, tabIndex) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var tab = userDynamicTabs[tabIndex];
        if (!tab) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">Tab not found.</p>';
            return;
        }
        var displayFields = tab.dynamic_fields_display || [];
        var entries = userSettings.filter(function(entry, index) {
            var entryStatus = (entry.status || 'pending').toLowerCase();
            if (entryStatus === status) {
                entry._index = index;
                return true;
            }
            return false;
        });
        var filteredEntries = entries.map(function(entry) {
            var filtered = {};
            displayFields.forEach(function(fieldKey) {
                if (entry[fieldKey] !== undefined) {
                    filtered[fieldKey] = entry[fieldKey];
                }
            });
            filtered.status = entry.status;
            filtered.operation_status = entry.operation_status;
            filtered._index = entry._index;
            return filtered;
        });
        renderEntryList(filteredEntries, container, status);
    }

    function copyObjectValueFromAttr(button) {
        var text = button.getAttribute('data-copy-text');
        if (!text) {
            showDialog('Error', 'No text to copy');
            return;
        }
        var decodedText = text.replace(/\\n/g, '\n').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
        copyToClipboard(decodedText);
    }

    // ============================================================
    // ===== RENDER ENTRY FIELDS =====
    // ============================================================

    function renderEntryFields(entry, index) {
        var displayFields = Object.keys(entry).filter(function(k) {
            return k !== 'status' && k !== 'operation_status' && k !== '_user' && k !== '_user_id' && k !== '_index' && k !== '_object';
        });
        if (displayFields.length === 0) {
            return '<div class="entry-details"><p style="color:#6b7280; text-align:center; padding:10px;">No fields available</p></div>';
        }
        var html = '<div class="entry-details">';
        var flattenedGroups = {};
        var individualFields = [];
        displayFields.forEach(function(key) {
            if (key.includes('_')) {
                var parts = key.split('_');
                var mainKey = parts[0];
                var subKey = parts.slice(1).join('_');
                if (!flattenedGroups[mainKey]) {
                    flattenedGroups[mainKey] = {};
                }
                flattenedGroups[mainKey][subKey] = entry[key];
            } else {
                individualFields.push(key);
            }
        });
        var hasFlattenedGroups = Object.keys(flattenedGroups).some(function(mainKey) {
            return Object.keys(flattenedGroups[mainKey]).length > 1;
        });
        if (hasFlattenedGroups) {
            for (var mainKey in flattenedGroups) {
                var subFields = flattenedGroups[mainKey];
                var subKeys = Object.keys(subFields);
                if (subKeys.length > 0) {
                    var objectId = 'obj_' + index + '_' + mainKey;
                    html += '<div class="entry-field-group object-field" id="' + objectId + '">';
                    html += '<span class="object-label">' + mainKey + '</span>';
                    html += '<div class="sub-fields-container">';
                    subKeys.forEach(function(subKey) {
                        var subValue = subFields[subKey];
                        var displayValue = typeof subValue === 'object' ? JSON.stringify(subValue, null, 2) : subValue;
                        var escapedValue = typeof displayValue === 'string' ? 
                            displayValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : 
                            displayValue;
                        var rawValue = typeof subValue === 'object' ? JSON.stringify(subValue) : subValue;
                        html += 
                            '<div class="sub-field-item" data-raw-value="' + encodeURIComponent(rawValue) + '">' +
                            '<span class="sub-label">' + subKey + '</span>' +
                            '<span class="sub-value">' + escapedValue + '</span>' +
                            '</div>';
                    });
                    html += '</div>';
                    var combinedText = subKeys.map(function(sk) {
                        var val = subFields[sk];
                        return typeof val === 'object' ? JSON.stringify(val) : val;
                    }).filter(function(v) { return v && v !== '' && v !== '{}'; }).join('\n');
                    html += '<div style="margin-top:8px;"><button class="btn btn-secondary btn-sm" onclick="copyObjectValuesFromDiv(\'' + objectId + '\')">📋 Copy All</button></div>';
                    html += '</div>';
                }
            }
        }
        displayFields.forEach(function(key) {
            var value = entry[key];
            if (key.includes('_')) {
                var parts = key.split('_');
                var mainKey = parts[0];
                if (flattenedGroups[mainKey] && Object.keys(flattenedGroups[mainKey]).length > 1) {
                    return;
                }
            }
            var isObjectField = (
                (typeof value === 'object' && 
                value !== null && 
                !Array.isArray(value) && 
                value.hasOwnProperty('_object') && 
                value._object === true)
                ||
                (typeof value === 'object' && 
                value !== null && 
                !Array.isArray(value) && 
                Object.keys(value).length > 0 && 
                !value.hasOwnProperty('_object'))
            );
            if (isObjectField) {
                var objectData = value;
                var subKeys = Object.keys(objectData).filter(function(k) { return k !== '_object'; });
                var objectId = 'obj_' + index + '_' + key;
                html += '<div class="entry-field-group object-field" id="' + objectId + '">';
                html += '<span class="object-label">' + key + '</span>';
                html += '<div class="sub-fields-container">';
                if (subKeys.length > 0) {
                    subKeys.forEach(function(subKey) {
                        var subValue = objectData[subKey];
                        var displayValue = typeof subValue === 'object' ? JSON.stringify(subValue, null, 2) : subValue;
                        var escapedValue = typeof displayValue === 'string' ? 
                            displayValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : 
                            displayValue;
                        var rawValue = typeof subValue === 'object' ? JSON.stringify(subValue) : subValue;
                        html += 
                            '<div class="sub-field-item" data-raw-value="' + encodeURIComponent(rawValue) + '">' +
                            '<span class="sub-label">' + subKey + '</span>' +
                            '<span class="sub-value">' + escapedValue + '</span>' +
                            '</div>';
                    });
                } else {
                    html += 
                        '<div class="sub-field-item">' +
                        '<span class="sub-label">No sub-fields</span>' +
                        '<span class="sub-value">-</span>' +
                        '</div>';
                }
                html += '</div>';
                var combinedText = subKeys.map(function(sk) {
                    var val = objectData[sk];
                    return typeof val === 'object' ? JSON.stringify(val) : val;
                }).filter(function(v) { return v && v !== '' && v !== '{}'; }).join('\n');
                html += '<div style="margin-top:8px;"><button class="btn btn-secondary btn-sm" onclick="copyObjectValuesFromDiv(\'' + objectId + '\')">📋 Copy All</button></div>';
                html += '</div>';
            } else if (!key.includes('_') || !hasFlattenedGroups) {
                var displayValue = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
                var escapedValue = typeof displayValue === 'string' ? 
                    displayValue.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') : 
                    displayValue;
                var rawValue = typeof value === 'object' ? JSON.stringify(value) : value;
                var fieldId = 'field_' + index + '_' + key;
                html += 
                    '<div class="entry-field-group individual-field" id="' + fieldId + '" data-raw-value="' + encodeURIComponent(rawValue) + '">' +
                    '<span class="field-label">' + key + '</span>' +
                    '<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">' +
                    '<div class="field-value">' + escapedValue + '</div>' +
                    '<button class="btn btn-secondary btn-sm" onclick="copyIndividualFromDiv(\'' + fieldId + '\')">📋 Copy</button>' +
                    '</div>' +
                    '</div>';
            }
        });
        html += '</div>';
        return html;
    }

    function copyIndividualFromDiv(elementId) {
        var container = document.getElementById(elementId);
        if (!container) {
            showDialog('Error', 'Field not found');
            return;
        }
        var rawValue = container.getAttribute('data-raw-value');
        if (!rawValue) {
            showDialog('Info', 'No value to copy');
            return;
        }
        var textToCopy = decodeURIComponent(rawValue);
        if (textToCopy.trim().startsWith('{') || textToCopy.trim().startsWith('[')) {
            try {
                var parsed = JSON.parse(textToCopy);
                textToCopy = JSON.stringify(parsed, null, 2);
            } catch(e) {}
        }
        if (!textToCopy || textToCopy.trim() === '') {
            showDialog('Info', 'No value to copy');
            return;
        }
        copyToClipboard(textToCopy);
    }

    function copyObjectValuesFromDiv(containerId) {
        var container = document.getElementById(containerId);
        if (!container) {
            showDialog('Error', 'Object container not found');
            return;
        }
        var subItems = container.querySelectorAll('.sub-field-item');
        if (subItems.length === 0) {
            showDialog('Info', 'No sub-fields to copy');
            return;
        }
        var copyTexts = [];
        subItems.forEach(function(item) {
            var rawValue = item.getAttribute('data-raw-value');
            if (rawValue) {
                var value = decodeURIComponent(rawValue);
                if (value.trim().startsWith('{') || value.trim().startsWith('[')) {
                    try {
                        var parsed = JSON.parse(value);
                        value = JSON.stringify(parsed, null, 2);
                    } catch(e) {}
                }
                if (value && value.trim() !== '' && value.trim() !== '{}') {
                    copyTexts.push(value);
                }
            }
        });
        if (copyTexts.length === 0) {
            showDialog('Info', 'No values to copy');
            return;
        }
        var textToCopy = copyTexts.join('\n');
        copyToClipboard(textToCopy);
    }

    function renderEntryList(entries, container, status) {
        container.innerHTML = '';
        if (entries.length === 0) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">No ' + status + ' entries found.</p>';
            return;
        }
        var statusColors = {
            'pending': '#f59e0b',
            'completed': '#10b981',
            'aborted': '#ef4444'
        };
        var statusLabels = {
            'pending': 'Pending',
            'completed': 'Completed',
            'aborted': 'Aborted'
        };
        entries.forEach(function(entry) {
            var div = document.createElement('div');
            div.className = 'config-item';
            var index = entry._index;
            var entryStatus = (entry.status || 'pending').toLowerCase();
            var fieldsHtml = renderEntryFields(entry, index);
            div.innerHTML = 
                '<div class="item-header">' +
                '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">' +
                '<span class="item-id">Entry #' + (index + 1) + '</span>' +
                '</div>' +
                '<span class="item-status" style="background: ' + (statusColors[entryStatus] || '#6b7280') + '">' + (statusLabels[entryStatus] || entryStatus) + '</span>' +
                '</div>' +
                fieldsHtml +
                '<div class="item-actions">' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="delete_entry" value="true">' +
                '<input type="hidden" name="user_id" value="' + selectedUserId + '">' +
                '<input type="hidden" name="index" value="' + index + '">' +
                '<button type="button" class="btn btn-danger btn-sm" onclick="confirmAction(\'Delete Entry\', \'Delete this entry?\', function(){ this.closest(\'form\').submit(); }.bind(this))"> Delete</button>' +
                '</form>' +
                '<form method="POST" style="display:inline;">' +
                '<input type="hidden" name="update_entry_status" value="true">' +
                '<input type="hidden" name="user_id" value="' + selectedUserId + '">' +
                '<input type="hidden" name="index" value="' + index + '">' +
                '<select name="new_status" class="status-select" onchange="this.form.submit()">' +
                '<option value="pending" ' + (entryStatus === 'pending' ? 'selected' : '') + '> Pending</option>' +
                '<option value="completed" ' + (entryStatus === 'completed' ? 'selected' : '') + '> Completed</option>' +
                '<option value="aborted" ' + (entryStatus === 'aborted' ? 'selected' : '') + '> Aborted</option>' +
                '</select>' +
                '</form>' +
                '</div>';
            container.appendChild(div);
        });
    }

    // ============================================================
    // ===== DYNAMIC TABS EDITOR =====
    // ============================================================

    function renderDynamicTabsEditor() {
        var container = document.getElementById('dynamicTabsEditor');
        if (!container) return;
        container.innerHTML = '';
        
        var tabs = userDynamicTabs || [];
        var filteredTabs = tabs.filter(function(tab) {
            return (tab.tab_name && tab.tab_name.trim() !== '') || 
                (tab.dynamic_fields_display && tab.dynamic_fields_display.length > 0);
        });
        
        if (filteredTabs.length !== tabs.length) {
            userDynamicTabs = filteredTabs;
            tabs = filteredTabs;
        }
        
        var globalFields = globalDynamicFields || {};
        var fieldKeys = Object.keys(globalFields);
        
        var html = 
            '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">' +
            '<h3 style="color:#1a1a2e;"> Dynamic Tabs</h3>' +
            '<button type="button" class="btn btn-primary" onclick="addDynamicTab()">+ Add Tab</button>' +
            '</div>' +
            '<p style="color:#6b7280; margin-bottom:15px;">Create tabs that group specific dynamic fields for "New Project" view.</p>';
        
        if (tabs.length === 0) {
            html += '<p style="color:#6b7280; text-align:center; padding:20px;">No dynamic tabs configured. Click "Add Tab" to create one.</p>';
        } else {
            html += '<form method="POST" id="dynamicTabsForm">';
            html += '<input type="hidden" name="save_dynamic_tabs" value="true">';
            html += '<input type="hidden" name="user_id" value="' + selectedUserId + '">';
            html += '<input type="hidden" name="tabs_data" id="tabsDataInput">';
            
            tabs.forEach(function(tab, index) {
                var tabFields = tab.dynamic_fields_display || [];
                var isExpanded = tab._expanded || false;
                
                // Get additional features with defaults
                var additionalFeatures = tab.additional_features || {
                    copy_button: false,
                    transcript_detection: false,
                    transcript_with_structured_data_detection: false
                };
                
                var availableFieldsHtml = '';
                if (fieldKeys.length === 0) {
                    availableFieldsHtml = '<p style="color:#6b7280; font-size:13px;">No dynamic fields available globally.</p>';
                } else {
                    availableFieldsHtml = fieldKeys.map(function(key) {
                        var isChecked = tabFields.includes(key);
                        var fieldData = globalFields[key];
                        var fieldTitle = fieldData ? (fieldData['field-title'] || key) : key;
                        return '<div class="field-toggle-item" style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; margin:2px 0; border-radius:6px; background:#f3f4f6;">' +
                            '<span class="field-name" style="font-size:13px; color:#1a1a2e;">' + fieldTitle + ' <span style="color:#6b7280; font-size:11px;">(' + key + ')</span></span>' +
                            '<label class="toggle-switch" style="position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0;">' +
                            '<input type="checkbox" class="field-toggle" data-field-key="' + key + '" ' + (isChecked ? 'checked' : '') + ' style="opacity:0; width:0; height:0;">' +
                            '<span class="toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:' + (isChecked ? '#00695c' : '#ccc') + '; transition:.4s; border-radius:22px;"></span>' +
                            '</label>' +
                            '</div>';
                    }).join('');
                }
                
                // Additional features HTML with toggle switches
                var additionalFeaturesHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<div style="font-weight:600; color:#1a1a2e; margin-bottom:8px;">Additional Features</div>' +
                    '<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">' +
                    // Copy Element Value Toggle
                    '<div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:#f8fafc; border-radius:6px; border:1px solid #e5e7eb;">' +
                    '<span style="font-size:13px; color:#1a1a2e;">📋 Copy Element Value</span>' +
                    '<label class="toggle-switch" style="position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0;">' +
                    '<input type="checkbox" class="additional-feature-toggle" data-feature="copy_button" ' + (additionalFeatures.copy_button ? 'checked' : '') + ' style="opacity:0; width:0; height:0;">' +
                    '<span class="toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:' + (additionalFeatures.copy_button ? '#00695c' : '#ccc') + '; transition:.4s; border-radius:22px;"></span>' +
                    '</label>' +
                    '</div>' +
                    // Transcript Detection Toggle
                    '<div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:#f8fafc; border-radius:6px; border:1px solid #e5e7eb;">' +
                    '<span style="font-size:13px; color:#1a1a2e;">📝 Transcript Detection</span>' +
                    '<label class="toggle-switch" style="position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0;">' +
                    '<input type="checkbox" class="additional-feature-toggle" data-feature="transcript_detection" ' + (additionalFeatures.transcript_detection ? 'checked' : '') + ' style="opacity:0; width:0; height:0;">' +
                    '<span class="toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:' + (additionalFeatures.transcript_detection ? '#00695c' : '#ccc') + '; transition:.4s; border-radius:22px;"></span>' +
                    '</label>' +
                    '</div>' +
                    // Structured Data Detection Toggle
                    '<div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:#f8fafc; border-radius:6px; border:1px solid #e5e7eb; grid-column: span 2;">' +
                    '<span style="font-size:13px; color:#1a1a2e;">📊 Structured Data Detection</span>' +
                    '<label class="toggle-switch" style="position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0;">' +
                    '<input type="checkbox" class="additional-feature-toggle" data-feature="transcript_with_structured_data_detection" ' + (additionalFeatures.transcript_with_structured_data_detection ? 'checked' : '') + ' style="opacity:0; width:0; height:0;">' +
                    '<span class="toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:' + (additionalFeatures.transcript_with_structured_data_detection ? '#00695c' : '#ccc') + '; transition:.4s; border-radius:22px;"></span>' +
                    '</label>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
                
                html += 
                    '<div class="tab-editor-item" data-tab-index="' + index + '" style="border:1px solid #e5e7eb; border-radius:10px; padding:15px; margin-bottom:12px; background:#fafafa;">' +
                    '<div class="tab-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:10px;">' +
                    '<span class="tab-title" style="font-weight:600; color:#1a1a2e; font-size:16px;"> ' + (tab.tab_name || 'Tab #' + (index + 1)) + '</span>' +
                    '<div style="display:flex; gap:8px; flex-wrap:wrap;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="toggleTabEdit(' + index + ')" id="toggleTabBtn_' + index + '">' +
                    (isExpanded ? ' Hide Editor' : ' Edit Tab') +
                    '</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteDynamicTab(' + index + ')"> Delete</button>' +
                    '</div>' +
                    '</div>' +
                    '<div id="tabEditContent_' + index + '" style="' + (isExpanded ? 'display:block;' : 'display:none;') + ' margin-top:10px;">' +
                    '<div class="form-group">' +
                    '<label>Tab Name</label>' +
                    '<input type="text" class="tab-name-input" value="' + (tab.tab_name || '') + '" placeholder="Enter tab name..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '</div>' +
                    '<div class="form-group">' +
                    '<label>Header</label>' +
                    '<input type="text" class="tab-header-input" value="' + (tab.header || '') + '" placeholder="Enter header text..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '</div>' +
                    '<div class="form-group">' +
                    '<label>Description</label>' +
                    '<textarea class="tab-description-input" rows="2" placeholder="Enter description..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' + (tab.description || '') + '</textarea>' +
                    '</div>' +
                    '<div class="form-group">' +
                    '<label>Fields to Display</label>' +
                    '<div style="border:1px solid #e5e7eb; border-radius:8px; padding:10px; background:white;">' +
                    availableFieldsHtml +
                    '</div>' +
                    '</div>' +
                    additionalFeaturesHtml +
                    '</div>' +
                    '</div>';
            });
            
            html += 
                '<div class="form-actions">' +
                '<button type="button" class="btn btn-success" onclick="saveDynamicTabs()"> Save All Tabs</button>' +
                '</div>';
            html += '</form>';
        }
        container.innerHTML = html;
    }

    function cleanEmptyTabs() {
        if (!userDynamicTabs || userDynamicTabs.length === 0) return;
        
        var cleaned = userDynamicTabs.filter(function(tab) {
            var hasName = tab.tab_name && tab.tab_name.trim() !== '';
            var hasFields = tab.dynamic_fields_display && tab.dynamic_fields_display.length > 0;
            return hasName || hasFields;
        });
        
        if (cleaned.length !== userDynamicTabs.length) {
            userDynamicTabs = cleaned;
            saveCleanTabs();
        }
    }

    function saveCleanTabs() {
        if (!selectedUserId || selectedUserId === 0) return;
        
        var formData = new FormData();
        formData.append('save_dynamic_tabs', 'true');
        formData.append('user_id', selectedUserId);
        formData.append('tabs_data', JSON.stringify(userDynamicTabs));
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        }).catch(function(error) {
            console.log('Error saving cleaned tabs:', error);
        });
    }

    function toggleTabEdit(index) {
        var content = document.getElementById('tabEditContent_' + index);
        var btn = document.getElementById('toggleTabBtn_' + index);
        if (content) {
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
                if (btn) btn.textContent = ' Hide Editor';
                if (userDynamicTabs[index]) {
                    userDynamicTabs[index]._expanded = true;
                }
            } else {
                content.style.display = 'none';
                if (btn) btn.textContent = ' Edit Tab';
                if (userDynamicTabs[index]) {
                    userDynamicTabs[index]._expanded = false;
                }
            }
        }
    }

    function addDynamicTab() {
        var tabs = userDynamicTabs || [];
        tabs.push({
            tab_name: 'New Tab ' + (tabs.length + 1),
            header: '',
            description: '',
            dynamic_fields_display: [],
            _expanded: false,
            submit_tab: true,
            additional_features: {
                copy_button: false,
                transcript_detection: false,
                transcript_with_structured_data_detection: false
            }
        });
        userDynamicTabs = tabs;
        renderDynamicTabsEditor();
    }

    function deleteDynamicTab(index) {
        confirmAction('Delete Tab', 'Delete this tab? This action cannot be undone.', function() {
            var tabs = userDynamicTabs || [];
            if (index >= 0 && index < tabs.length) {
                tabs.splice(index, 1);
                userDynamicTabs = tabs;
                renderDynamicTabsEditor();
            }
        });
    }

    function saveDynamicTabs() {
        var tabItems = document.querySelectorAll('.tab-editor-item');
        var tabs = [];
        tabItems.forEach(function(item) {
            var nameInput = item.querySelector('.tab-name-input');
            var headerInput = item.querySelector('.tab-header-input');
            var descInput = item.querySelector('.tab-description-input');
            var toggles = item.querySelectorAll('.field-toggle');
            var index = parseInt(item.getAttribute('data-tab-index'));
            var dynamicFieldsDisplay = [];
            toggles.forEach(function(toggle) {
                if (toggle.checked) {
                    dynamicFieldsDisplay.push(toggle.getAttribute('data-field-key'));
                }
            });
            
            // Get additional features from toggles
            var featureToggles = item.querySelectorAll('.additional-feature-toggle');
            var additionalFeatures = {
                copy_button: false,
                transcript_detection: false,
                transcript_with_structured_data_detection: false
            };
            featureToggles.forEach(function(toggle) {
                var feature = toggle.getAttribute('data-feature');
                if (feature && additionalFeatures.hasOwnProperty(feature)) {
                    additionalFeatures[feature] = toggle.checked;
                }
            });
            
            var existingTab = userDynamicTabs[index] || {};
            tabs.push({
                tab_name: nameInput ? nameInput.value.trim() : '',
                header: headerInput ? headerInput.value.trim() : '',
                description: descInput ? descInput.value.trim() : '',
                dynamic_fields_display: dynamicFieldsDisplay,
                _expanded: existingTab._expanded || false,
                submit_tab: existingTab.submit_tab !== undefined ? existingTab.submit_tab : true,
                additional_features: additionalFeatures // Save directly, no need to fetch existing
            });
        });
        document.getElementById('tabsDataInput').value = JSON.stringify(tabs);
        document.getElementById('dynamicTabsForm').submit();
    }

    // ============================================================
    // ===== SWITCH NEW PROJECT SUB TAB =====
    // ============================================================

    function switchNewProjectSubTab(tabId, btn) {
        document.querySelectorAll('#user_tab_add_entry .dynamic-sub-tab-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('#user_tab_add_entry .dynamic-sub-tab-btn').forEach(function(el) { el.classList.remove('active'); });
        var target = document.getElementById('newProjectSubTab_' + tabId);
        if (target) {
            target.classList.add('active');
        }
        if (btn) {
            btn.classList.add('active');
        }
    }

    // ============================================================
    // ===== SUBMIT USER ENTRY =====
    // ============================================================

    function submitUserEntry() {
        var activeContent = document.querySelector('#user_tab_add_entry .dynamic-sub-tab-content.active');
        if (!activeContent) {
            showDialog('Error', 'No active form found.');
            return;
        }
        var inputs = activeContent.querySelectorAll('input, textarea, select');
        var entryData = {};
        var objectContainers = activeContent.querySelectorAll('[id^="object_"]');
        inputs.forEach(function(input) {
            if (input.id && input.id.startsWith('entry_')) {
                var key = input.id.replace('entry_', '');
                if (key.includes('_date') || key.includes('_hour_12') || key.includes('_minute_12') || key.includes('_ampm')) {
                    return;
                }
                entryData[key] = input.value;
            }
        });
        objectContainers.forEach(function(container) {
            var objectKey = container.id.replace('object_', '').replace('_container', '');
            var subInputs = container.querySelectorAll('input, textarea, select');
            var objectData = {};
            objectData['_object'] = true;
            subInputs.forEach(function(input) {
                if (input.id && input.id.startsWith('entry_' + objectKey + '_')) {
                    var subKey = input.id.replace('entry_' + objectKey + '_', '');
                    if (subKey.includes('_date') || subKey.includes('_hour_12') || subKey.includes('_minute_12') || subKey.includes('_ampm')) {
                        return;
                    }
                    objectData[subKey] = input.value;
                }
            });
            var hasData = Object.keys(objectData).some(function(k) { return k !== '_object' && objectData[k] !== ''; });
            if (hasData) {
                entryData[objectKey] = objectData;
            }
        });
        var hasData = Object.values(entryData).some(function(v) {
            if (typeof v === 'object') {
                return Object.values(v).some(function(sv) { return sv !== '' && sv !== '_object'; });
            }
            return v !== '';
        });
        if (!hasData) {
            showDialog('Error', 'Please enter at least one field value.');
            return;
        }
        document.getElementById('entryDataInput').value = JSON.stringify(entryData);
        document.getElementById('addEntryForm').submit();
    }

    // ============================================================
    // ===== RENDER GLOBAL DYNAMIC FIELDS =====
    // ============================================================

    // ===== UPDATE renderGlobalDynamicFields FUNCTION =====
    // Replace the entire function with this version

    function renderGlobalDynamicFields() {
        var container = document.getElementById('globalDynamicFieldsList');
        if (!container) return;
        container.innerHTML = '';
        var fields = globalDynamicFields;
        var fieldKeys = Object.keys(fields);
        if (fieldKeys.length === 0) {
            container.innerHTML = '<p style="color:#6b7280; text-align:center; padding:20px;">No dynamic fields configured.</p>';
            return;
        }
        for (var i = 0; i < fieldKeys.length; i++) {
            var key = fieldKeys[i];
            var field = fields[key];
            var div = document.createElement('div');
            div.className = 'form-group';
            div.style.border = '1px solid #e5e7eb';
            div.style.borderRadius = '10px';
            div.style.padding = '15px';
            div.style.marginBottom = '12px';
            div.style.background = '#fafafa';
            var fieldInfo = '';
            var editFieldsHtml = '';
            if (field.field_type === 'objects') {
                var subFields = field.fieldkeyvalue || {};
                var subKeys = Object.keys(subFields);
                fieldInfo = 
                    '<div><strong>Type:</strong> Objects Field</div>' +
                    '<div><strong>Sub-fields:</strong> ' + subKeys.length + '</div>' +
                    subKeys.map(function(sk) { 
                        var sub = subFields[sk];
                        var subElement = sub.element_type || 'input';
                        var extraInfo = subElement === 'button' ? ' (Button → ' + (sub.button_url || 'No URL') + ')' : '';
                        return '<div style="font-size:12px; color:#6b7280; margin-left:10px;">• ' + sk + ' (' + subElement + extraInfo + ')</div>'; 
                    }).join('');
                editFieldsHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="editGlobalDynamicField(\'' + key + '\')"> Edit Field</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteGlobalDynamicField(\'' + key + '\')" style="margin-left:8px;"> Delete</button>' +
                    '</div>';
            } else {
                var defaultValue = field.default_value || '';
                var defaultValues = field.default_values || [];
                var buttonUrl = field.button_url || '';
                var elementType = field.element_type || 'input';
                var extraInfo = elementType === 'button' ? ' (URL: ' + buttonUrl + ')' : '';
                fieldInfo = 
                    '<div><strong>Type:</strong> Individual Field</div>' +
                    '<div><strong>Element:</strong> ' + elementType + extraInfo + '</div>' +
                    (defaultValue ? '<div><strong>Default Value:</strong> ' + defaultValue + '</div>' : '') +
                    (defaultValues.length > 0 ? '<div><strong>Select Options:</strong> ' + defaultValues.join(', ') + '</div>' : '');
                editFieldsHtml = 
                    '<div style="margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="editGlobalDynamicField(\'' + key + '\')"> Edit Field</button>' +
                    '<button type="button" class="btn btn-danger btn-sm" onclick="deleteGlobalDynamicField(\'' + key + '\')" style="margin-left:8px;"> Delete</button>' +
                    '</div>';
            }
            div.innerHTML = 
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">' +
                '<strong style="color:#1a1a2e;">' + (field['field-title'] || key) + '</strong>' +
                '<div>' +
                '<span style="font-size:12px; color:#6b7280; background:#e5e7eb; padding:2px 10px; border-radius:12px;">' + (field.field_type || 'individual') + '</span>' +
                '</div>' +
                '</div>' +
                '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px; color:#6b7280;">' +
                '<div><strong>Field Key:</strong> ' + key + '</div>' +
                fieldInfo +
                '</div>' +
                editFieldsHtml;
            container.appendChild(div);
        }
        if (document.getElementById('userGlobalDynamicFieldsList')) {
            renderUserGlobalDynamicFields();
        }
    }

    // ============================================================
    // ===== EDIT GLOBAL DYNAMIC FIELD =====
    // ============================================================

    var subFields = [];

    function editGlobalDynamicField(fieldKey) {
        var field = globalDynamicFields[fieldKey];
        if (!field) {
            showDialog('Error', 'Field not found.');
            return;
        }
        subFields = [];
        window._dfDefaultValues = [];
        var isObjects = field.field_type === 'objects';
        var title = field['field-title'] || '';
        var elementType = field.element_type || 'input';
        var defaultValue = field.default_value || '';
        var defaultValues = field.default_values || [];
        if (isObjects) {
            var subFieldData = field.fieldkeyvalue || {};
            var subKeys = Object.keys(subFieldData);
            subKeys.forEach(function(sk, idx) {
                var sub = subFieldData[sk];
                subFields.push({
                    defaultValues: sub.default_values || [],
                    default_value: sub.default_value || ''
                });
            });
        } else {
            window._dfDefaultValues = defaultValues;
        }
        renderEditFieldDialog(fieldKey, field, title, isObjects, elementType, defaultValue);
    }

    // ===== UPDATE renderEditFieldDialog FUNCTION =====
    // Replace the entire function with this version

    function renderEditFieldDialog(fieldKey, field, title, isObjects, elementType, defaultValue) {
        var category = isObjects ? 'objects' : 'individual';
        var subFieldsHtml = '';
        var buttonUrl = field.button_url || '';
        
        if (isObjects) {
            var subFieldData = field.fieldkeyvalue || {};
            var subKeys = Object.keys(subFieldData);
            subKeys.forEach(function(sk, idx) {
                var sub = subFieldData[sk];
                var subElement = sub.element_type || 'input';
                var subDefaultValue = sub.default_value || '';
                var subDefaultValues = sub.default_values || [];
                subFieldsHtml += 
                    '<div class="sub-field-group" id="sub_field_' + idx + '">' +
                    '<button type="button" class="remove-sub-field" onclick="removeSubField(' + idx + ')">×</button>' +
                    '<div class="form-group" style="margin-bottom:10px;">' +
                    '<label>Display Title</label>' +
                    '<input type="text" id="sub_title_' + idx + '" value="' + (sub['field-title'] || sk) + '" placeholder="Enter display title..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '</div>' +
                    '<div class="form-group" style="margin-bottom:10px;">' +
                    '<label>Field Key</label>' +
                    '<input type="text" id="sub_key_' + idx + '" value="' + sk + '" placeholder="Enter field key..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '</div>' +
                    '<div class="form-group">' +
                    '<label>Element Type</label>' +
                    '<select id="sub_element_' + idx + '" onchange="toggleSubDefaultValueInput(' + idx + ')" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '<option value="input" ' + (subElement === 'input' ? 'selected' : '') + '>Input</option>' +
                    '<option value="select" ' + (subElement === 'select' ? 'selected' : '') + '>Select</option>' +
                    '<option value="textarea" ' + (subElement === 'textarea' ? 'selected' : '') + '>Textarea</option>' +
                    '<option value="date-time-input" ' + (subElement === 'date-time-input' ? 'selected' : '') + '>Date & Time</option>' +
                    '<option value="button" ' + (subElement === 'button' ? 'selected' : '') + '>Button</option>' +
                    '</select>' +
                    '</div>' +
                    '<div class="form-group" id="sub_default_value_container_' + idx + '">' +
                    '<label>Default Value</label>' +
                    '<div id="sub_default_value_input_container_' + idx + '">' +
                    '<input type="text" id="sub_default_value_' + idx + '" value="' + subDefaultValue + '" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '</div>' +
                    '<div id="sub_default_value_textarea_container_' + idx + '" style="display:none;">' +
                    '<textarea id="sub_default_value_textarea_' + idx + '" rows="3" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' + subDefaultValue + '</textarea>' +
                    '</div>' +
                    '</div>' +
                    '<div class="form-group" id="sub_select_values_container_' + idx + '" style="display:none;">' +
                    '<label>Select Options</label>' +
                    '<div style="display:flex; gap:8px;">' +
                    '<input type="text" id="sub_default_value_input_' + idx + '" placeholder="Enter option..." style="flex:1; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
                    '<button type="button" class="btn btn-secondary" onclick="addSubDefaultValue(' + idx + ')">Add</button>' +
                    '</div>' +
                    '<div id="sub_default_values_list_' + idx + '" style="margin-top:8px;"></div>' +
                    '</div>' +
                    '</div>';
            });
        }
        
        var defaultValuesHtml = '';
        var defaultValues = field.default_values || [];
        defaultValues.forEach(function(val, idx) {
            defaultValuesHtml += '<span class="value-tag">' + val + ' <span class="remove-value" onclick="removeDefaultValue(' + idx + ')">×</span></span>';
        });
        
        var content = 
            '<div class="form-group">' +
            '<label>Display Title</label>' +
            '<input type="text" id="df_title" value="' + title + '" placeholder="Enter display title..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Field Key</label>' +
            '<input type="text" id="df_key" value="' + fieldKey + '" placeholder="Enter field key (no spaces)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">Used for storage. Spaces will be replaced with underscores.</small>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Field Category</label>' +
            '<select id="df_category" onchange="toggleFieldCategory()" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="individual" ' + (!isObjects ? 'selected' : '') + '>Individual Field</option>' +
            '<option value="objects" ' + (isObjects ? 'selected' : '') + '>Objects Field</option>' +
            '</select>' +
            '</div>' +
            '<div id="individual_fields_container" style="' + (isObjects ? 'display:none;' : 'display:block;') + '">' +
            '<div class="form-group">' +
            '<label>Element Type</label>' +
            '<select id="df_element_type" onchange="toggleDefaultValueInput()" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="input" ' + (elementType === 'input' ? 'selected' : '') + '>Input</option>' +
            '<option value="select" ' + (elementType === 'select' ? 'selected' : '') + '>Select</option>' +
            '<option value="textarea" ' + (elementType === 'textarea' ? 'selected' : '') + '>Textarea</option>' +
            '<option value="date-time-input" ' + (elementType === 'date-time-input' ? 'selected' : '') + '>Date & Time</option>' +
            '<option value="button" ' + (elementType === 'button' ? 'selected' : '') + '>Button</option>' +
            '</select>' +
            '</div>' +
            '<div class="form-group" id="df_default_value_container">' +
            '<label>Default Value</label>' +
            '<div id="df_default_value_input_container" ' + (elementType === 'textarea' ? 'style="display:none;"' : '') + '>' +
            '<input type="text" id="df_default_value" value="' + defaultValue + '" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div id="df_default_value_textarea_container" ' + (elementType === 'textarea' ? 'style="display:block;"' : 'style="display:none;"') + '>' +
            '<textarea id="df_default_value_textarea" rows="3" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' + defaultValue + '</textarea>' +
            '</div>' +
            '</div>' +
            '<div class="form-group" id="df_select_values_container" style="' + (elementType === 'select' ? 'display:block;' : 'display:none;') + '">' +
            '<label>Select Options</label>' +
            '<div style="display:flex; gap:8px;">' +
            '<input type="text" id="df_default_value_input" placeholder="Enter option..." style="flex:1; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<button type="button" class="btn btn-secondary" onclick="addDefaultValue()">Add</button>' +
            '</div>' +
            '<div id="df_default_values_list" style="margin-top:8px;">' + defaultValuesHtml + '</div>' +
            '</div>' +
            '<div class="form-group" id="df_url_container" style="' + (elementType === 'button' ? 'display:block;' : 'display:none;') + '">' +
            '<label>Button URL (onclick value)</label>' +
            '<div id="df_url_input_container">' +
            '<input type="url" id="df_url_value" value="' + buttonUrl + '" placeholder="Enter URL (e.g., https://example.com)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">This URL will be opened in a new window/tab when the button is clicked.</small>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div id="objects_fields_container" style="' + (isObjects ? 'display:block;' : 'display:none;') + '">' +
            '<h4 style="margin-bottom:10px;"> Sub Fields</h4>' +
            '<div id="sub_fields_list">' + subFieldsHtml + '</div>' +
            '<div style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">' +
            '<button type="button" class="btn btn-secondary btn-sm" onclick="addSubField()">+ Add Sub Field</button>' +
            '</div>' +
            '</div>' +
            '<div style="display:flex; gap:10px; margin-top:20px;">' +
            '<button type="button" class="btn btn-success" onclick="updateDynamicField(\'' + fieldKey + '\')"> Update Field</button>' +
            '<button type="button" class="btn btn-secondary" onclick="closeDialog()">Cancel</button>' +
            '</div>';
        
        showDialog('Edit Dynamic Field', '', null, content);
        toggleFieldCategory();
        toggleDefaultValueInput();
        if (isObjects) {
            var subFieldData2 = field.fieldkeyvalue || {};
            var subKeys2 = Object.keys(subFieldData2);
            subKeys2.forEach(function(sk, idx) {
                var sub = subFieldData2[sk];
                subFields[idx] = {
                    defaultValues: sub.default_values || [],
                    default_value: sub.default_value || ''
                };
                renderSubFieldDefaultValues(idx);
                toggleSubDefaultValueInput(idx);
            });
        }
    }

    function toggleFieldCategory() {
        var category = document.getElementById('df_category').value;
        document.getElementById('individual_fields_container').style.display = category === 'individual' ? 'block' : 'none';
        document.getElementById('objects_fields_container').style.display = category === 'objects' ? 'block' : 'none';
    }

    function toggleDefaultValueInput() {
        var elementType = document.getElementById('df_element_type').value;
        var defaultValueContainer = document.getElementById('df_default_value_container');
        var defaultValueInput = document.getElementById('df_default_value_input_container');
        var defaultValueTextarea = document.getElementById('df_default_value_textarea_container');
        var selectValuesContainer = document.getElementById('df_select_values_container');
        if (elementType === 'select') {
            defaultValueContainer.style.display = 'none';
            selectValuesContainer.style.display = 'block';
        } else if (elementType === 'textarea') {
            defaultValueContainer.style.display = 'block';
            defaultValueInput.style.display = 'none';
            defaultValueTextarea.style.display = 'block';
            selectValuesContainer.style.display = 'none';
        } else {
            defaultValueContainer.style.display = 'block';
            defaultValueInput.style.display = 'block';
            defaultValueTextarea.style.display = 'none';
            selectValuesContainer.style.display = 'none';
        }
    }

    // ===== UPDATE addSubField FUNCTION =====
    // Replace the entire function with this version

    function addSubField() {
        var index = subFields.length;
        var html = 
            '<div class="sub-field-group" id="sub_field_' + index + '">' +
            '<button type="button" class="remove-sub-field" onclick="removeSubField(' + index + ')">×</button>' +
            '<div class="form-group" style="margin-bottom:10px;">' +
            '<label>Display Title</label>' +
            '<input type="text" id="sub_title_' + index + '" placeholder="Enter display title..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div class="form-group" style="margin-bottom:10px;">' +
            '<label>Field Key</label>' +
            '<input type="text" id="sub_key_' + index + '" placeholder="Enter field key..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Element Type</label>' +
            '<select id="sub_element_' + index + '" onchange="toggleSubDefaultValueInput(' + index + ')" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="input">Input</option>' +
            '<option value="select">Select</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="date-time-input">Date & Time</option>' +
            '<option value="button">Button</option>' +
            '</select>' +
            '</div>' +
            '<div class="form-group" id="sub_default_value_container_' + index + '">' +
            '<label>Default Value</label>' +
            '<div id="sub_default_value_input_container_' + index + '">' +
            '<input type="text" id="sub_default_value_' + index + '" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div id="sub_default_value_textarea_container_' + index + '" style="display:none;">' +
            '<textarea id="sub_default_value_textarea_' + index + '" rows="3" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;"></textarea>' +
            '</div>' +
            '</div>' +
            '<div class="form-group" id="sub_select_values_container_' + index + '" style="display:none;">' +
            '<label>Select Options</label>' +
            '<div style="display:flex; gap:8px;">' +
            '<input type="text" id="sub_default_value_input_' + index + '" placeholder="Enter option..." style="flex:1; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<button type="button" class="btn btn-secondary" onclick="addSubDefaultValue(' + index + ')">Add</button>' +
            '</div>' +
            '<div id="sub_default_values_list_' + index + '" style="margin-top:8px;"></div>' +
            '</div>' +
            '<div class="form-group" id="sub_url_container_' + index + '" style="display:none;">' +
            '<label>Button URL (onclick value)</label>' +
            '<div id="sub_url_input_container_' + index + '">' +
            '<input type="url" id="sub_url_value_' + index + '" placeholder="Enter URL (e.g., https://example.com)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">This URL will be opened in a new window/tab when the button is clicked.</small>' +
            '</div>' +
            '</div>' +
            '</div>';
        
        subFields.push({ defaultValues: [], default_value: '', button_url: '' });
        document.getElementById('sub_fields_list').insertAdjacentHTML('beforeend', html);
        renderSubFieldDefaultValues(index);
        toggleSubDefaultValueInput(index);
    }

    // ===== UPDATE toggleSubDefaultValueInput FUNCTION =====
    // Replace the entire function with this version

    function toggleSubDefaultValueInput(index) {
        var elementType = document.getElementById('sub_element_' + index).value;
        var defaultValueContainer = document.getElementById('sub_default_value_container_' + index);
        var defaultValueInputContainer = document.getElementById('sub_default_value_input_container_' + index);
        var defaultValueTextareaContainer = document.getElementById('sub_default_value_textarea_container_' + index);
        var selectValuesContainer = document.getElementById('sub_select_values_container_' + index);
        var urlContainer = document.getElementById('sub_url_container_' + index);
        
        // Hide all optional containers first
        if (defaultValueContainer) defaultValueContainer.style.display = 'none';
        if (selectValuesContainer) selectValuesContainer.style.display = 'none';
        if (urlContainer) urlContainer.style.display = 'none';
        if (defaultValueInputContainer) defaultValueInputContainer.style.display = 'none';
        if (defaultValueTextareaContainer) defaultValueTextareaContainer.style.display = 'none';
        
        if (elementType === 'select') {
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (selectValuesContainer) selectValuesContainer.style.display = 'block';
        } else if (elementType === 'textarea') {
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (defaultValueInputContainer) defaultValueInputContainer.style.display = 'none';
            if (defaultValueTextareaContainer) defaultValueTextareaContainer.style.display = 'block';
        } else if (elementType === 'button') {
            if (urlContainer) urlContainer.style.display = 'block';
        } else {
            // Input, date-time-input, etc.
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (defaultValueInputContainer) defaultValueInputContainer.style.display = 'block';
            if (defaultValueTextareaContainer) defaultValueTextareaContainer.style.display = 'none';
        }
    }

    function removeSubField(index) {
        var element = document.getElementById('sub_field_' + index);
        if (element) {
            element.remove();
            subFields[index] = null;
        }
    }

    function addSubDefaultValue(index) {
        var input = document.getElementById('sub_default_value_input_' + index);
        var value = input.value.trim();
        if (value) {
            if (!subFields[index]) subFields[index] = { defaultValues: [], default_value: '' };
            if (!subFields[index].defaultValues) subFields[index].defaultValues = [];
            if (!subFields[index].defaultValues.includes(value)) {
                subFields[index].defaultValues.push(value);
                renderSubFieldDefaultValues(index);
                input.value = '';
            }
        }
    }

    function removeSubDefaultValue(index, valueIndex) {
        if (subFields[index] && subFields[index].defaultValues) {
            subFields[index].defaultValues.splice(valueIndex, 1);
            renderSubFieldDefaultValues(index);
        }
    }

    function renderSubFieldDefaultValues(index) {
        var container = document.getElementById('sub_default_values_list_' + index);
        if (!container) return;
        container.innerHTML = '';
        var values = subFields[index] ? subFields[index].defaultValues || [] : [];
        if (values.length === 0) {
            container.innerHTML = '<span style="color:#6b7280; font-size:13px;">No options added.</span>';
            return;
        }
        values.forEach(function(val, idx) {
            var span = document.createElement('span');
            span.className = 'value-tag';
            span.innerHTML = val + ' <span class="remove-value" onclick="removeSubDefaultValue(' + index + ', ' + idx + ')">×</span>';
            container.appendChild(span);
        });
    }

    function addDefaultValue() {
        var input = document.getElementById('df_default_value_input');
        var value = input.value.trim();
        if (value) {
            if (!window._dfDefaultValues) window._dfDefaultValues = [];
            if (!window._dfDefaultValues.includes(value)) {
                window._dfDefaultValues.push(value);
                renderDefaultValuesList();
                input.value = '';
            }
        }
    }

    function removeDefaultValue(index) {
        if (window._dfDefaultValues) {
            window._dfDefaultValues.splice(index, 1);
            renderDefaultValuesList();
        }
    }

    function renderDefaultValuesList() {
        var container = document.getElementById('df_default_values_list');
        if (!container) return;
        container.innerHTML = '';
        var values = window._dfDefaultValues || [];
        if (values.length === 0) {
            container.innerHTML = '<span style="color:#6b7280; font-size:13px;">No options added.</span>';
            return;
        }
        values.forEach(function(val, idx) {
            var span = document.createElement('span');
            span.className = 'value-tag';
            span.innerHTML = val + ' <span class="remove-value" onclick="removeDefaultValue(' + idx + ')">×</span>';
            container.appendChild(span);
        });
    }

    // ===== UPDATE updateDynamicField FUNCTION =====
    // Replace the entire function with this version

    function updateDynamicField(oldKey) {
        var title = document.getElementById('df_title').value.trim();
        var key = document.getElementById('df_key').value.trim();
        var category = document.getElementById('df_category').value;
        
        if (!title || !key) {
            showDialog('Error', 'Please enter both title and key.');
            return;
        }
        key = key.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
        
        var fieldData = {};
        if (category === 'individual') {
            var elementType = document.getElementById('df_element_type').value;
            var defaultValue = '';
            var buttonUrl = '';
            
            if (elementType === 'textarea') {
                defaultValue = document.getElementById('df_default_value_textarea').value.trim();
            } else if (elementType === 'select') {
                // For select, we don't use defaultValue directly
            } else if (elementType === 'button') {
                buttonUrl = document.getElementById('df_url_value').value.trim();
                // ADD PROTOCOL IF MISSING
                if (buttonUrl && !buttonUrl.match(/^https?:\/\//i)) {
                    buttonUrl = 'https://' + buttonUrl;
                }
            } else {
                defaultValue = document.getElementById('df_default_value').value.trim();
            }
            
            var defaultValues = window._dfDefaultValues || [];
            fieldData = {
                'field-title': title,
                'field-name': key,
                'field_type': 'individual',
                'element_type': elementType,
                'default_value': defaultValue,
                'default_values': defaultValues,
                'button_url': buttonUrl
            };
        } else {
            var subFieldData = {};
            var subFieldElements = document.querySelectorAll('.sub-field-group');
            subFieldElements.forEach(function(el, idx) {
                var subTitle = document.getElementById('sub_title_' + idx);
                var subKey = document.getElementById('sub_key_' + idx);
                var subElement = document.getElementById('sub_element_' + idx);
                if (subTitle && subKey && subElement) {
                    var st = subTitle.value.trim();
                    var sk = subKey.value.trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
                    var se = subElement.value;
                    var sdv = subFields[idx] ? subFields[idx].defaultValues || [] : [];
                    var sDefaultValue = '';
                    var sButtonUrl = '';
                    
                    if (se === 'textarea') {
                        var textarea = document.getElementById('sub_default_value_textarea_' + idx);
                        if (textarea) sDefaultValue = textarea.value.trim();
                    } else if (se === 'button') {
                        var urlInput = document.getElementById('sub_url_value_' + idx);
                        if (urlInput) {
                            sButtonUrl = urlInput.value.trim();
                            // ADD PROTOCOL IF MISSING
                            if (sButtonUrl && !sButtonUrl.match(/^https?:\/\//i)) {
                                sButtonUrl = 'https://' + sButtonUrl;
                            }
                        }
                    } else if (se !== 'select') {
                        var input = document.getElementById('sub_default_value_' + idx);
                        if (input) sDefaultValue = input.value.trim();
                    }
                    
                    if (st && sk) {
                        subFieldData[sk] = {
                            'field-title': st,
                            'field-name': sk,
                            'field_type': 'individual',
                            'element_type': se,
                            'default_value': sDefaultValue,
                            'default_values': sdv,
                            'button_url': sButtonUrl
                        };
                    }
                }
            });
            fieldData = {
                'field-title': title,
                'field-name': key,
                'field_type': 'objects',
                'fieldkeyvalue': subFieldData
            };
        }
        
        var finalKey = key;
        var formData = new FormData();
        formData.append('update_global_field', 'true');
        formData.append('old_key', oldKey);
        formData.append('new_key', finalKey);
        formData.append('field_data', JSON.stringify(fieldData));
        
        showDialog('Saving', 'Updating field...');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                closeDialog();
                showDialog('Success', data.message + ' Refreshing...', [
                    { label: 'OK', class: 'btn-primary', callback: function(){ location.reload(); } }
                ]);
            } else {
                closeDialog();
                showDialog('Error', data.message);
            }
        })
        .catch(function(error) {
            closeDialog();
            showDialog('Error', 'Failed to update: ' + error.message);
        });
    }

    function deleteGlobalDynamicField(key) {
        showDialog('Delete Field', 'Are you sure you want to delete the field "' + key + '"? This action cannot be undone.', [
            { label: 'Cancel', class: 'btn-secondary', callback: function() { closeDialog(); } },
            { label: 'Delete', class: 'btn-confirm', callback: function() {
                var formData = new FormData();
                formData.append('delete_global_dynamic_field', 'true');
                formData.append('field_key', key);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    if (response.redirected || response.url) {
                        window.location.reload();
                    } else {
                        window.location.reload();
                    }
                })
                .catch(function(error) {
                    showDialog('Error', 'Failed to delete field: ' + error.message);
                });
            }}
        ]);
    }

    function addGlobalDynamicField() {
        subFields = [];
        window._dfDefaultValues = [];
        renderAddFieldDialog();
    }
    // ===== UPDATE toggleDefaultValueInput FUNCTION =====
    // Add this complete function with the new "button" case

    function toggleDefaultValueInput() {
        var elementType = document.getElementById('df_element_type').value;
        var defaultValueContainer = document.getElementById('df_default_value_container');
        var defaultValueInput = document.getElementById('df_default_value_input_container');
        var defaultValueTextarea = document.getElementById('df_default_value_textarea_container');
        var selectValuesContainer = document.getElementById('df_select_values_container');
        var urlContainer = document.getElementById('df_url_container');
        var urlInput = document.getElementById('df_url_input_container');
        
        // Hide all optional containers first
        if (defaultValueContainer) defaultValueContainer.style.display = 'none';
        if (selectValuesContainer) selectValuesContainer.style.display = 'none';
        if (urlContainer) urlContainer.style.display = 'none';
        if (defaultValueInput) defaultValueInput.style.display = 'none';
        if (defaultValueTextarea) defaultValueTextarea.style.display = 'none';
        
        if (elementType === 'select') {
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (selectValuesContainer) selectValuesContainer.style.display = 'block';
        } else if (elementType === 'textarea') {
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (defaultValueInput) defaultValueInput.style.display = 'none';
            if (defaultValueTextarea) defaultValueTextarea.style.display = 'block';
        } else if (elementType === 'button') {
            // For button type, show URL input instead of default value
            if (urlContainer) urlContainer.style.display = 'block';
            if (urlInput) urlInput.style.display = 'block';
        } else {
            // Input, date-time-input, etc.
            if (defaultValueContainer) defaultValueContainer.style.display = 'block';
            if (defaultValueInput) defaultValueInput.style.display = 'block';
            if (defaultValueTextarea) defaultValueTextarea.style.display = 'none';
        }
    }

    // ===== UPDATE renderAddFieldDialog FUNCTION =====
    // Replace the entire function with this version

    function renderAddFieldDialog() {
        var content = 
            '<div class="form-group">' +
            '<label>Display Title</label>' +
            '<input type="text" id="df_title" placeholder="Enter display title..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Field Key</label>' +
            '<input type="text" id="df_key" placeholder="Enter field key (no spaces)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">Used for storage. Spaces will be replaced with underscores.</small>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Field Category</label>' +
            '<select id="df_category" onchange="toggleFieldCategory()" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="individual">Individual Field</option>' +
            '<option value="objects">Objects Field</option>' +
            '</select>' +
            '</div>' +
            '<div id="individual_fields_container">' +
            '<div class="form-group">' +
            '<label>Element Type</label>' +
            '<select id="df_element_type" onchange="toggleDefaultValueInput()" style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<option value="input">Input</option>' +
            '<option value="select">Select</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="date-time-input">Date & Time</option>' +
            '<option value="button">Button</option>' +
            '</select>' +
            '</div>' +
            '<div class="form-group" id="df_default_value_container">' +
            '<label>Default Value</label>' +
            '<div id="df_default_value_input_container">' +
            '<input type="text" id="df_default_value" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '</div>' +
            '<div id="df_default_value_textarea_container" style="display:none;">' +
            '<textarea id="df_default_value_textarea" rows="3" placeholder="Enter default value..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;"></textarea>' +
            '</div>' +
            '</div>' +
            '<div class="form-group" id="df_select_values_container" style="display:none;">' +
            '<label>Select Options</label>' +
            '<div style="display:flex; gap:8px;">' +
            '<input type="text" id="df_default_value_input" placeholder="Enter option..." style="flex:1; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<button type="button" class="btn btn-secondary" onclick="addDefaultValue()">Add</button>' +
            '</div>' +
            '<div id="df_default_values_list" style="margin-top:8px;"></div>' +
            '</div>' +
            '<div class="form-group" id="df_url_container" style="display:none;">' +
            '<label>Button URL (onclick value)</label>' +
            '<div id="df_url_input_container">' +
            '<input type="url" id="df_url_value" placeholder="Enter URL (e.g., https://example.com)..." style="width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px;">' +
            '<small style="color:#6b7280;">This URL will be opened in a new window/tab when the button is clicked.</small>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div id="objects_fields_container" style="display:none;">' +
            '<h4 style="margin-bottom:10px;"> Sub Fields</h4>' +
            '<div id="sub_fields_list"></div>' +
            '<div style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">' +
            '<button type="button" class="btn btn-secondary btn-sm" onclick="addSubField()">+ Add Sub Field</button>' +
            '</div>' +
            '</div>' +
            '<div style="display:flex; gap:10px; margin-top:20px;">' +
            '<button type="button" class="btn btn-success" onclick="saveDynamicField()"> Save Field</button>' +
            '<button type="button" class="btn btn-secondary" onclick="closeDialog()">Cancel</button>' +
            '</div>';
        
        showDialog('Add Dynamic Field', '', null, content);
        toggleFieldCategory();
        toggleDefaultValueInput();
        renderDefaultValuesList();
    }

    function saveDynamicField() {
        var title = document.getElementById('df_title').value.trim();
        var key = document.getElementById('df_key').value.trim();
        var category = document.getElementById('df_category').value;
        
        if (!title || !key) {
            showDialog('Error', 'Please enter both title and key.');
            return;
        }
        key = key.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
        
        var fieldData = {};
        if (category === 'individual') {
            var elementType = document.getElementById('df_element_type').value;
            var defaultValue = '';
            var buttonUrl = '';
            
            if (elementType === 'textarea') {
                defaultValue = document.getElementById('df_default_value_textarea').value.trim();
            } else if (elementType === 'select') {
                // For select, we don't use defaultValue directly
            } else if (elementType === 'button') {
                buttonUrl = document.getElementById('df_url_value').value.trim();
                // ADD PROTOCOL IF MISSING
                if (buttonUrl && !buttonUrl.match(/^https?:\/\//i)) {
                    buttonUrl = 'https://' + buttonUrl;
                }
            } else {
                defaultValue = document.getElementById('df_default_value').value.trim();
            }
            
            var defaultValues = window._dfDefaultValues || [];
            
            fieldData = {
                'field-title': title,
                'field-name': key,
                'field_type': 'individual',
                'element_type': elementType,
                'default_value': defaultValue,
                'default_values': defaultValues,
                'button_url': buttonUrl
            };
        } else {
            var subFieldData = {};
            var subFieldElements = document.querySelectorAll('.sub-field-group');
            subFieldElements.forEach(function(el, idx) {
                var subTitle = document.getElementById('sub_title_' + idx);
                var subKey = document.getElementById('sub_key_' + idx);
                var subElement = document.getElementById('sub_element_' + idx);
                if (subTitle && subKey && subElement) {
                    var st = subTitle.value.trim();
                    var sk = subKey.value.trim().toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
                    var se = subElement.value;
                    var sdv = subFields[idx] ? subFields[idx].defaultValues || [] : [];
                    var sDefaultValue = '';
                    var sButtonUrl = '';
                    
                    if (se === 'textarea') {
                        var textarea = document.getElementById('sub_default_value_textarea_' + idx);
                        if (textarea) sDefaultValue = textarea.value.trim();
                    } else if (se === 'button') {
                        var urlInput = document.getElementById('sub_url_value_' + idx);
                        if (urlInput) {
                            sButtonUrl = urlInput.value.trim();
                            // ADD PROTOCOL IF MISSING
                            if (sButtonUrl && !sButtonUrl.match(/^https?:\/\//i)) {
                                sButtonUrl = 'https://' + sButtonUrl;
                            }
                        }
                    } else if (se !== 'select') {
                        var input = document.getElementById('sub_default_value_' + idx);
                        if (input) sDefaultValue = input.value.trim();
                    }
                    
                    if (st && sk) {
                        subFieldData[sk] = {
                            'field-title': st,
                            'field-name': sk,
                            'field_type': 'individual',
                            'element_type': se,
                            'default_value': sDefaultValue,
                            'default_values': sdv,
                            'button_url': sButtonUrl
                        };
                    }
                }
            });
            fieldData = {
                'field-title': title,
                'field-name': key,
                'field_type': 'objects',
                'fieldkeyvalue': subFieldData
            };
        }
        
        var formData = new FormData();
        formData.append('save_global_single_field', 'true');
        formData.append('field_data', JSON.stringify(fieldData));
        
        showDialog('Saving', 'Saving field...');
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                closeDialog();
                showDialog('Success', data.message + ' Refreshing...', [
                    { label: 'OK', class: 'btn-primary', callback: function(){ location.reload(); } }
                ]);
            } else {
                closeDialog();
                showDialog('Error', data.message);
            }
        })
        .catch(function(error) {
            closeDialog();
            showDialog('Error', 'Failed to save: ' + error.message);
        });
    }

    // ============================================================
    // ===== USER SEARCH DIALOG =====
    // ============================================================

    function openUserSearchDialog() {
        document.getElementById('userSearchDialog').classList.add('active');
        document.getElementById('userSearchInput').value = '';
        document.querySelectorAll('#userSearchResults .user-search-item').forEach(function(item) {
            item.style.display = 'block';
        });
        setTimeout(function() { document.getElementById('userSearchInput').focus(); }, 100);
    }

    function closeUserSearchDialog() {
        document.getElementById('userSearchDialog').classList.remove('active');
    }

    function filterUserSearch() {
        var query = document.getElementById('userSearchInput').value.toLowerCase().trim();
        var items = document.querySelectorAll('#userSearchResults .user-search-item');
        items.forEach(function(item) {
            var name = item.getAttribute('data-user-name') || '';
            var email = item.getAttribute('data-user-email') || '';
            var match = name.includes(query) || email.includes(query);
            item.style.display = match ? 'block' : 'none';
        });
    }

    function selectUserFromSearch(userId) {
        var form = document.createElement('form');
        form.method = 'POST';
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'select_user';
        input.value = 'true';
        var input2 = document.createElement('input');
        input2.type = 'hidden';
        input2.name = 'user_id';
        input2.value = userId;
        form.appendChild(input);
        form.appendChild(input2);
        document.body.appendChild(form);
        form.submit();
    }

    document.getElementById('userSearchDialog').addEventListener('click', function(e) {
        if (e.target === this) closeUserSearchDialog();
    });

    // ============================================================
    // ===== SYNC ON INPUT =====
    // ============================================================

    document.addEventListener('input', function(e) {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (addEntryTab && addEntryTab.contains(e.target)) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                syncFieldValues();
            }
        }
    });

    document.addEventListener('paste', function(e) {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (addEntryTab && addEntryTab.contains(e.target)) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                setTimeout(function() { syncFieldValues(); }, 100);
            }
        }
    });
    // ============================================================
    // ===== TRANSCRIPT DETECTION AND CONVERSION ENGINE =====
    // ============================================================

   
</script>
<script>
    // ============================================================
    // ===== COPY BUTTON VISIBILITY BASED ON TAB FEATURES =====
    // ============================================================

    // Function to update copy button visibility based on active tab
    function updateCopyButtonsVisibility() {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) return;
        
        var activeContent = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        if (!activeContent) return;
        
        // Find the active tab
        var activeTabBtn = document.querySelector('#user_tab_add_entry .dynamic-sub-tab-btn.active');
        var tabId = null;
        if (activeTabBtn) {
            tabId = activeTabBtn.getAttribute('data-tab');
            if (!tabId) {
                var onclickAttr = activeTabBtn.getAttribute('onclick');
                if (onclickAttr) {
                    var match = onclickAttr.match(/switchNewProjectSubTab\('([^']+)'/);
                    if (match) tabId = match[1];
                }
            }
        }
        
        var tabIndex = -1;
        if (tabId) {
            tabIndex = parseInt(tabId.replace('tab_', ''));
        }
        
        // Check if we're on the all_fields tab
        var allFieldsTab = document.getElementById('newProjectSubTab_all_fields');
        if (allFieldsTab && allFieldsTab.classList.contains('active')) {
            // On all_fields tab, show all copy buttons by default
            activeContent.querySelectorAll('[id^="field_"]_container .btn-secondary, [id^="object_"]_container .btn-secondary').forEach(function(btn) {
                if (btn.textContent.includes('Copy')) {
                    btn.style.display = 'inline-block';
                }
            });
            return;
        }
        
        if (isNaN(tabIndex) || tabIndex < 0 || tabIndex >= userDynamicTabs.length) {
            // Default: show all copy buttons
            activeContent.querySelectorAll('[id^="field_"]_container .btn-secondary, [id^="object_"]_container .btn-secondary').forEach(function(btn) {
                if (btn.textContent.includes('Copy')) {
                    btn.style.display = 'inline-block';
                }
            });
            return;
        }
        
        var tab = userDynamicTabs[tabIndex];
        if (!tab) {
            // Default: show all copy buttons
            activeContent.querySelectorAll('[id^="field_"]_container .btn-secondary, [id^="object_"]_container .btn-secondary').forEach(function(btn) {
                if (btn.textContent.includes('Copy')) {
                    btn.style.display = 'inline-block';
                }
            });
            return;
        }
        
        var additionalFeatures = tab.additional_features || {
            copy_button: false,
            transcript_detection: false,
            transcript_with_structured_data_detection: false
        };
        
        var copyButtonEnabled = additionalFeatures.copy_button === true;
        
        // Find all copy buttons in the active content
        var copyButtons = activeContent.querySelectorAll('.btn-secondary');
        copyButtons.forEach(function(btn) {
            // Check if this is a copy button (contains copy-related text)
            var btnText = btn.textContent || '';
            if (btnText.includes('Copy') || btnText.includes('📋')) {
                if (copyButtonEnabled) {
                    btn.style.display = 'inline-block';
                } else {
                    btn.style.display = 'none';
                }
            }
        });
        
        // Also handle the copy buttons inside field containers
        activeContent.querySelectorAll('[id^="field_"]_container .btn-secondary, [id^="object_"]_container .btn-secondary').forEach(function(btn) {
            var btnText = btn.textContent || '';
            if (btnText.includes('Copy') || btnText.includes('📋')) {
                if (copyButtonEnabled) {
                    btn.style.display = 'inline-block';
                } else {
                    btn.style.display = 'none';
                }
            }
        });
    }

    // Override switchNewProjectSubTab to also update copy buttons
    var _originalSwitchNewProjectSubTab2 = switchNewProjectSubTab;
    switchNewProjectSubTab = function(tabId, btn) {
        _originalSwitchNewProjectSubTab2(tabId, btn);
        setTimeout(function() { 
            detectTranscripts();
            updateCopyButtonsVisibility();
        }, 200);
    };

    // Run copy button visibility check after DOM changes
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            updateCopyButtonsVisibility();
        }, 500);
    });

    // Also run after any AJAX-like updates
    var _originalRenderDynamicTabsEditor = renderDynamicTabsEditor;
    renderDynamicTabsEditor = function() {
        _originalRenderDynamicTabsEditor();
        setTimeout(function() {
            updateCopyButtonsVisibility();
        }, 300);
    };

    console.log('✅ Copy button visibility based on tab features initialized');
</script>
<script>
    // ============================================================
    // ===== TRANSCRIPT DETECTION AND CONVERSION ENGINE =====
    // ============================================================

    // Store transcript data for each element
    window._transcriptData = {};

    // Store character data from the separate element
    window._characterData = null;

    // Store conversion results for each element
    window._conversionResults = {};

    // Function to get the currently active tab index
    function getActiveTabIndex() {
        var activeTabBtn = document.querySelector('#user_tab_add_entry .dynamic-sub-tab-btn.active');
        if (!activeTabBtn) return -1;
        
        var tabId = activeTabBtn.getAttribute('data-tab');
        if (!tabId) {
            var onclickAttr = activeTabBtn.getAttribute('onclick');
            if (onclickAttr) {
                var match = onclickAttr.match(/switchNewProjectSubTab\('([^']+)'/);
                if (match) tabId = match[1];
            }
        }
        
        if (!tabId) return -1;
        var tabIndex = parseInt(tabId.replace('tab_', ''));
        return isNaN(tabIndex) ? -1 : tabIndex;
    }

    // Function to get additional features for the active tab
    function getActiveTabFeatures() {
        var tabIndex = getActiveTabIndex();
        
        // Check if we're on the all_fields tab
        var allFieldsTab = document.getElementById('newProjectSubTab_all_fields');
        if (allFieldsTab && allFieldsTab.classList.contains('active')) {
            // All fields tab - return default features (all enabled)
            return {
                copy_button: true,
                transcript_detection: true,
                transcript_with_structured_data_detection: true
            };
        }
        
        if (tabIndex < 0 || tabIndex >= userDynamicTabs.length) {
            // Default: all features enabled
            return {
                copy_button: true,
                transcript_detection: true,
                transcript_with_structured_data_detection: true
            };
        }
        
        var tab = userDynamicTabs[tabIndex];
        if (!tab) {
            return {
                copy_button: true,
                transcript_detection: true,
                transcript_with_structured_data_detection: true
            };
        }
        
        return tab.additional_features || {
            copy_button: false,
            transcript_detection: false,
            transcript_with_structured_data_detection: false
        };
    }

    // Function to detect transcript in element values
    function detectTranscripts() {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) return;
        
        var activeContent = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        if (!activeContent) return;
        
        var elements = activeContent.querySelectorAll('input, textarea, select');
        
        // Get features for the active tab
        var features = getActiveTabFeatures();
        var transcriptDetectionEnabled = features.transcript_detection === true;
        var structuredDetectionEnabled = features.transcript_with_structured_data_detection === true;
        var copyButtonEnabled = features.copy_button === true;
        
        elements.forEach(function(element) {
            var value = element.value || element.textContent || '';
            var elementId = element.id || '';
            
            if (!value || !elementId) return;
            
            var timePattern = /[-_\(]?\d{1,2}:\d{2}[-_\)]?/;
            var hasTime = timePattern.test(value);
            
            // Check if this transcript contains structured data
            var hasStructuredData = detectStructuredCharacterData(value);
            
            var existingIndicator = document.getElementById('transcript_indicator_' + elementId);
            var existingResult = document.getElementById('transcript_result_' + elementId);
            
            // Determine if we should show anything
            var shouldShowTranscript = false;
            var shouldShowStructured = false;
            
            // INDEPENDENT CHECKS - each feature works separately
            if (hasTime) {
                // Regular transcript detection is independent
                if (transcriptDetectionEnabled) {
                    shouldShowTranscript = true;
                }
            }
            
            // Structured data detection is independent
            if (hasStructuredData) {
                if (structuredDetectionEnabled) {
                    shouldShowStructured = true;
                    // Structured data also counts as a transcript
                    shouldShowTranscript = true;
                }
            }
            
            // If we should show either type
            if (shouldShowTranscript) {
                window._transcriptData[elementId] = {
                    value: value,
                    element: element
                };
                
                // Also look for character data in other elements
                detectCharacterData();
                
                if (!existingIndicator) {
                    var indicator = document.createElement('div');
                    indicator.id = 'transcript_indicator_' + elementId;
                    indicator.style.cssText = 'margin-top:8px; padding:8px 12px; background:#d1fae5; border-radius:8px; border-left:4px solid #00695c; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;';
                    
                    var indicatorText = '';
                    var buttonsHtml = '';
                    
                    if (shouldShowStructured) {
                        // Structured data detected - Show Convert to Text button
                        indicatorText = '📝 Transcript with Structured Character Data Detected';
                        buttonsHtml = 
                            '<div style="display:flex; gap:8px; flex-wrap:wrap;">' +
                            '<button type="button" class="btn btn-primary btn-sm" onclick="convertToText(\'' + elementId + '\')" style="padding:4px 12px; font-size:12px; background:#7c3aed; color:white;">📄 Convert to Text</button>' +
                            '</div>';
                    } else if (shouldShowTranscript) {
                        // Regular transcript detected - Show Convert to JSON and View buttons
                        indicatorText = '📝 Transcript Detected';
                        buttonsHtml = 
                            '<div style="display:flex; gap:8px; flex-wrap:wrap;">' +
                            '<button type="button" class="btn btn-success btn-sm" onclick="convertAndDisplayInline(\'' + elementId + '\')" style="padding:4px 12px; font-size:12px;">🔄 Convert to JSON</button>' +
                            '<button type="button" class="btn btn-primary btn-sm" onclick="openTranscriptDialog(\'' + elementId + '\')" style="padding:4px 12px; font-size:12px;">View</button>' +
                            '</div>';
                    }
                    
                    // Add copy button if enabled
                    if (copyButtonEnabled) {
                        buttonsHtml = buttonsHtml.replace('</div>', '');
                        buttonsHtml += '<button type="button" class="btn btn-secondary btn-sm" onclick="copyTranscriptValue(\'' + elementId + '\')" style="padding:4px 12px; font-size:12px;">📋 Copy</button>';
                        buttonsHtml += '</div>';
                    }
                    
                    indicator.innerHTML = 
                        '<span style="color:#065f46; font-weight:500;">' + indicatorText + '</span>' +
                        buttonsHtml;
                    
                    var container = element.closest('.form-group') || element.closest('.sub-field-group') || element.parentElement;
                    if (container) {
                        container.appendChild(indicator);
                    } else {
                        element.parentNode.insertBefore(indicator, element.nextSibling);
                    }
                } else {
                    // Update existing indicator - check visibility
                    if (!shouldShowTranscript) {
                        existingIndicator.style.display = 'none';
                    } else {
                        existingIndicator.style.display = 'flex';
                    }
                }
            } else {
                // Remove if no transcript or structured data
                if (existingIndicator) {
                    existingIndicator.remove();
                    delete window._transcriptData[elementId];
                }
                if (existingResult) {
                    existingResult.remove();
                    delete window._conversionResults[elementId];
                }
            }
        });
    }

    // Helper function to copy transcript value
    function copyTranscriptValue(elementId) {
        var data = window._transcriptData[elementId];
        if (data) {
            copyToClipboard(data.value);
        } else {
            showDialog('Info', 'No transcript data to copy.');
        }
    }

    // Detect if character data is in structured JSON format - ONLY when both characters_visual_prompts and script exist
    function detectStructuredCharacterData(text) {
        try {
            // Check for both characters_visual_prompts and script to ensure it's complete structured data
            var hasPrompts = text.match(/"characters_visual_prompts"\s*:\s*\{/);
            var hasScript = text.match(/"script"\s*:\s*\[/);
            return hasPrompts !== null && hasScript !== null;
        } catch (e) {
            return false;
        }
    }

    // Function to detect character data from other elements
    function detectCharacterData() {
        var addEntryTab = document.getElementById('user_tab_add_entry');
        if (!addEntryTab) return;
        
        var activeContent = addEntryTab.querySelector('.dynamic-sub-tab-content.active');
        if (!activeContent) return;
        
        var elements = activeContent.querySelectorAll('input, textarea, select');
        
        var foundCharacterData = null;
        
        elements.forEach(function(element) {
            var value = element.value || element.textContent || '';
            if (!value) return;
            
            // Look for JSON character data
            var charData = extractCharacterDataFromString(value);
            if (charData && Object.keys(charData).length > 0) {
                foundCharacterData = charData;
                console.log('✅ Found character data in element:', element.id || 'unnamed');
            }
        });
        
        // If found character data, store it globally
        if (foundCharacterData) {
            window._characterData = foundCharacterData;
            console.log('✅ Character data stored globally with ' + Object.keys(foundCharacterData).length + ' characters');
        }
    }

    // Extract character data from a string
    function extractCharacterDataFromString(text) {
        var characterData = {};
        
        if (!text) return characterData;
        
        // Look for JSON array format: [{"character": "Name", ...}, ...]
        var jsonArrayMatch = text.match(/\[\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*(?:,\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*)*\]/);
        if (jsonArrayMatch) {
            try {
                var charArray = JSON.parse(jsonArrayMatch[0]);
                if (Array.isArray(charArray)) {
                    charArray.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            // Extract clean name from character field (remove parentheses, special chars)
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                // Store all possible variations for matching
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    if (Object.keys(characterData).length > 0) {
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ Failed to parse JSON array:', e.message);
            }
        }
        
        // Look for individual character objects
        var charObjects = text.match(/\{[^}]*"character"\s*:\s*"[^"]*"[^}]*"relation"\s*:\s*"[^"]*"[^}]*"character_details"\s*:\s*"[^"]*"[^}]*"character_visual_prompt"\s*:\s*"[^"]*"[^}]*\}/g);
        if (charObjects) {
            charObjects.forEach(function(charObjStr) {
                try {
                    var charObj = JSON.parse(charObjStr);
                    var characterName = charObj.character || '';
                    if (characterName) {
                        var relation = charObj.relation || '';
                        var castDetails = charObj.character_details || '';
                        var prompt = charObj.character_visual_prompt || '';
                        
                        var cleanName = extractCleanName(characterName);
                        
                        var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                        
                        characterData[characterName] = {
                            name: characterName,
                            clean_name: cleanName,
                            relation: relation,
                            character_details: castDetails,
                            details: fullDetails,
                            prompt: prompt,
                            variations: generateNameVariations(cleanName, characterName)
                        };
                    }
                } catch (e) {
                    // Skip invalid JSON objects
                }
            });
            if (Object.keys(characterData).length > 0) {
                return characterData;
            }
        }
        
        // Look for JSON code block
        var jsonCodeMatch = text.match(/```json\s*(\[[\s\S]*?\])\s*```/);
        if (jsonCodeMatch) {
            try {
                var charArray2 = JSON.parse(jsonCodeMatch[1]);
                if (Array.isArray(charArray2)) {
                    charArray2.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    if (Object.keys(characterData).length > 0) {
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ Failed to parse JSON code block:', e.message);
            }
        }
        
        return characterData;
    }

    // Extract clean name from character field (remove parentheses and special chars)
    function extractCleanName(characterName) {
        // Remove anything in parentheses
        var cleanName = characterName.replace(/\([^)]*\)/g, '').trim();
        // Remove extra spaces
        cleanName = cleanName.replace(/\s+/g, ' ').trim();
        // If cleanName is empty, use the original
        if (!cleanName) cleanName = characterName;
        return cleanName;
    }

    // Generate variations of a name for intelligent matching
    function generateNameVariations(cleanName, fullName) {
        var variations = new Set();
        
        // Add clean name
        if (cleanName) {
            variations.add(cleanName.toLowerCase());
            // Add without spaces
            variations.add(cleanName.toLowerCase().replace(/\s+/g, ''));
        }
        
        // Add full name variations
        if (fullName) {
            var fullLower = fullName.toLowerCase();
            variations.add(fullLower);
            // Remove parentheses and commas
            var stripped = fullLower.replace(/[(),]/g, '').replace(/\s+/g, ' ').trim();
            variations.add(stripped);
            variations.add(stripped.replace(/\s+/g, ''));
        }
        
        // Split by commas, spaces, and extract individual words
        var parts = fullName.split(/[,\s]+/);
        parts.forEach(function(part) {
            part = part.trim().toLowerCase();
            if (part.length > 2) { // Only consider meaningful words
                variations.add(part);
                // Remove parentheses from part
                var cleanPart = part.replace(/[()]/g, '').trim();
                if (cleanPart.length > 2) {
                    variations.add(cleanPart);
                }
            }
        });
        
        // Generate combinations of words (2-word, 3-word combos)
        var words = [];
        parts.forEach(function(part) {
            part = part.trim().toLowerCase().replace(/[()]/g, '');
            if (part.length > 2) {
                words.push(part);
            }
        });
        
        // Generate 2-word combinations
        for (var i = 0; i < words.length; i++) {
            for (var j = i + 1; j < words.length; j++) {
                var combo = words[i] + words[j];
                variations.add(combo);
                var comboSpace = words[i] + ' ' + words[j];
                variations.add(comboSpace);
            }
        }
        
        // Generate 3-word combinations
        for (var i = 0; i < words.length; i++) {
            for (var j = i + 1; j < words.length; j++) {
                for (var k = j + 1; k < words.length; k++) {
                    var combo = words[i] + words[j] + words[k];
                    variations.add(combo);
                    var comboSpace = words[i] + ' ' + words[j] + ' ' + words[k];
                    variations.add(comboSpace);
                }
            }
        }
        
        // Return as array
        return Array.from(variations);
    }

    // Function to open transcript dialog
    function openTranscriptDialog(elementId) {
        // Check if transcript detection is enabled for this tab
        var features = getActiveTabFeatures();
        if (features.transcript_detection !== true) {
            showDialog('Info', 'Transcript detection is not enabled for this tab.');
            return;
        }
        
        var data = window._transcriptData[elementId];
        if (!data) {
            showDialog('Error', 'Transcript data not found.');
            return;
        }
        
        var characterStatus = window._characterData ? 
            '<span style="color:#065f46;">✅ Character data found (' + Object.keys(window._characterData).length + ' characters)</span>' : 
            '<span style="color:#f59e0b;">⚠️ No character data found - converting transcript only</span>';
        
        var content = 
            '<div style="margin-bottom:15px;">' +
            '<label style="display:block; font-weight:600; color:#1a1a2e; margin-bottom:8px;">Transcript Content</label>' +
            '<textarea id="transcriptTextarea" rows="12" style="width:100%; padding:12px; border:2px solid #e5e7eb; border-radius:8px; font-family:monospace; font-size:14px; background:#fafafa; resize:vertical;">' + 
            data.value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
            '</textarea>' +
            '</div>' +
            '<div style="margin-bottom:15px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e5e7eb;">' +
            '<div style="font-size:13px; color:#1a1a2e;">' + characterStatus + '</div>' +
            '</div>' +
            '<div style="margin-top:10px;">' +
            '<button type="button" class="btn btn-primary" onclick="convertTranscriptToJson(\'' + elementId + '\')" style="width:100%; padding:12px;">🔄 Convert Transcript to JSON</button>' +
            '</div>' +
            '<div id="transcriptConversionResult" style="margin-top:15px; display:none;"></div>' +
            '<div id="transcriptJsonResult" style="margin-top:15px; display:none;"></div>';
        
        showDialog('Transcript Viewer', '', null, content);
    }

    // NEW FUNCTION: Convert to Text format with batches
    function convertToText(elementId) {
        // Check if structured data detection is enabled for this tab
        var features = getActiveTabFeatures();
        if (features.transcript_with_structured_data_detection !== true) {
            showDialog('Info', 'Structured data detection is not enabled for this tab.');
            return;
        }
        
        var data = window._transcriptData[elementId];
        if (!data) {
            showDialog('Error', 'Transcript data not found.');
            return;
        }
        
        var transcriptText = data.value;
        if (!transcriptText || transcriptText.trim() === '') {
            showDialog('Error', 'Transcript is empty.');
            return;
        }
        
        // Show loading indicator
        var indicator = document.getElementById('transcript_indicator_' + elementId);
        if (indicator) {
            var convertBtn = indicator.querySelector('.btn-primary');
            if (convertBtn && convertBtn.textContent.includes('Convert to Text')) {
                convertBtn.textContent = '⏳ Processing...';
                convertBtn.disabled = true;
            }
        }
        
        setTimeout(function() {
            try {
                var result = processStructuredTextConversion(transcriptText);
                
                // Store result
                window._conversionResults[elementId + '_text'] = result;
                
                // Remove any existing result div first
                var existingResultDiv = document.getElementById('transcript_result_' + elementId);
                if (existingResultDiv) {
                    existingResultDiv.remove();
                }
                
                // Create result display
                var resultDiv = document.createElement('div');
                resultDiv.id = 'transcript_result_' + elementId;
                resultDiv.style.cssText = 'margin-top:8px; padding:12px; background:#f0fdf4; border-radius:8px; border:1px solid #a7f3d0;';
                
                resultDiv.innerHTML = 
                    '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">' +
                    '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">' +
                    '<span style="color:#065f46; font-weight:600;">✅ Text Conversion Complete</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">📊 ' + result.totalEntries + ' entries</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">📦 ' + result.batches + ' batches</span>' +
                    '</div>' +
                    '<div style="display:flex; gap:6px; flex-wrap:wrap;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="copyTextResult(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px;">📋 Copy Text</button>' +
                    '<button type="button" class="btn btn-sm" onclick="toggleInlineResult(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px; background:#e5e7eb; color:#374151;">✕</button>' +
                    '</div>' +
                    '</div>' +
                    '<div style="background:#1a1a2e; color:#e5e7eb; padding:12px; border-radius:6px; overflow:auto; max-height:400px; font-size:12px; font-family:monospace; white-space:pre-wrap; word-break:break-word;">' + 
                    result.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                    '</div>';
                
                // Insert after indicator
                var indicatorEl = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl) {
                    indicatorEl.parentNode.insertBefore(resultDiv, indicatorEl.nextSibling);
                }
                
                // Reset button
                if (indicatorEl) {
                    var convertBtn2 = indicatorEl.querySelector('.btn-primary');
                    if (convertBtn2 && convertBtn2.textContent.includes('Convert to Text')) {
                        convertBtn2.textContent = '📄 Convert to Text';
                        convertBtn2.disabled = false;
                    }
                }
                
            } catch (error) {
                // Reset button
                var indicatorEl2 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl2) {
                    var convertBtn3 = indicatorEl2.querySelector('.btn-primary');
                    if (convertBtn3 && convertBtn3.textContent.includes('Convert to Text')) {
                        convertBtn3.textContent = '📄 Convert to Text';
                        convertBtn3.disabled = false;
                    }
                }
                
                // Show error
                var errorDiv = document.createElement('div');
                errorDiv.id = 'transcript_result_' + elementId;
                errorDiv.style.cssText = 'margin-top:8px; padding:10px; background:#fef2f2; border-radius:8px; border:1px solid #fecaca; color:#dc2626;';
                errorDiv.textContent = '❌ Error: ' + error.message;
                
                var indicatorEl3 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl3) {
                    indicatorEl3.parentNode.insertBefore(errorDiv, indicatorEl3.nextSibling);
                }
            }
        }, 100);
    }

    // Copy text result
    function copyTextResult(elementId) {
        var result = window._conversionResults[elementId + '_text'];
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        copyToClipboard(result.text);
    }

    // Find characters mentioned in a text (scene_pov_prompt or dialogue)
    function findMentionedCharacters(text, characterPrompts) {
        var mentioned = [];
        if (!text || !characterPrompts) return mentioned;
        
        var textUpper = text.toUpperCase();
        
        for (var key in characterPrompts) {
            if (characterPrompts.hasOwnProperty(key)) {
                var keyUpper = key.toUpperCase();
                // Check if character name appears in the text
                if (textUpper.includes(keyUpper)) {
                    mentioned.push(key);
                }
                // Also check for variations
                var charInfo = characterPrompts[key];
                if (charInfo.clean_name) {
                    var cleanUpper = charInfo.clean_name.toUpperCase();
                    if (cleanUpper !== keyUpper && textUpper.includes(cleanUpper)) {
                        if (mentioned.indexOf(key) === -1) {
                            mentioned.push(key);
                        }
                    }
                }
            }
        }
        
        return mentioned;
    }

    // Process structured text conversion - handles both array and batch formats
    function processStructuredTextConversion(transcriptText) {
        // Parse the full JSON from the text
        var parsedData = null;
        try {
            var startIdx = transcriptText.indexOf('{');
            if (startIdx === -1) {
                throw new Error('No JSON object found in text');
            }
            
            var braceCount = 0;
            var endIdx = -1;
            for (var i = startIdx; i < transcriptText.length; i++) {
                if (transcriptText[i] === '{') braceCount++;
                else if (transcriptText[i] === '}') {
                    braceCount--;
                    if (braceCount === 0) {
                        endIdx = i + 1;
                        break;
                    }
                }
            }
            
            if (endIdx === -1) {
                throw new Error('Could not find matching closing brace for JSON object');
            }
            
            var jsonStr = transcriptText.substring(startIdx, endIdx);
            parsedData = JSON.parse(jsonStr);
        } catch (e) {
            console.log('⚠️ Failed to parse JSON:', e.message);
            throw new Error('Failed to parse structured JSON data: ' + e.message);
        }
        
        if (!parsedData || !parsedData.characters_visual_prompts || !parsedData.script) {
            throw new Error('Invalid structured data: missing characters_visual_prompts or script');
        }
        
        var scriptEntries = [];
        
        // Check if script is an array or object with batches
        if (Array.isArray(parsedData.script)) {
            // Array format - use as is
            scriptEntries = parsedData.script;
        } else if (typeof parsedData.script === 'object') {
            // Batch format - extract entries from all batches
            for (var batchKey in parsedData.script) {
                if (parsedData.script.hasOwnProperty(batchKey)) {
                    var batch = parsedData.script[batchKey];
                    if (batch && batch.entries && Array.isArray(batch.entries)) {
                        scriptEntries = scriptEntries.concat(batch.entries);
                    }
                }
            }
        }
        
        if (scriptEntries.length === 0) {
            throw new Error('No script entries found');
        }
        
        // Process all entries WITHOUT distributing characters to view_focus_characters
        var updatedScriptEntries = [];
        var charPrompts = parsedData.characters_visual_prompts || {};
        
        for (var idx = 0; idx < scriptEntries.length; idx++) {
            var entry = scriptEntries[idx];
            
            // Extract view_focus_characters - if it exists, use it, otherwise extract from view_focus
            var viewFocusChars = '';
            if (entry.view_focus_characters) {
                viewFocusChars = entry.view_focus_characters;
            } else if (entry.view_focus) {
                // Try to extract from view_focus
                viewFocusChars = entry.view_focus;
            }
            
            // Parse view focus characters - handle "and" and commas
            var charNames = [];
            if (viewFocusChars) {
                // Split by "and" or commas
                var parts = viewFocusChars.split(/\s+and\s+|\s*,\s*/);
                parts.forEach(function(part) {
                    var trimmed = part.trim();
                    if (trimmed) {
                        charNames.push(trimmed);
                    }
                });
            }
            
            // Also check for characters in scene_pov_prompt and dialogue
            var scenePov = entry.scene_pov_prompt || entry.Scene_Pov_promt || '';
            var dialogue = entry.dialogue || '';
            var allText = scenePov + ' ' + dialogue;
            
            // Find mentioned characters
            var mentionedChars = findMentionedCharacters(allText, charPrompts);
            
            // Combine with view focus characters
            var allCharNames = charNames.slice();
            mentionedChars.forEach(function(charName) {
                if (allCharNames.indexOf(charName) === -1) {
                    allCharNames.push(charName);
                }
            });
            
            // Create updated entry WITHOUT view_focus_characters distribution
            var updatedEntry = {
                start: entry.start || '',
                stop: entry.stop || '',
                duration: entry.duration || '',
                view_focus_characters: '', // Always empty - no distribution
                dialogue: entry.dialogue || '',
                Scene_Pov_promt: entry.scene_pov_prompt || entry.Scene_Pov_promt || '',
                // Store character names for details display
                _characters: allCharNames
            };
            
            updatedScriptEntries.push(updatedEntry);
        }
        
        // Generate text output with batches of 20
        var textOutput = 'SCENES DIALOGUE\n\n';
        var batchSize = 20;
        var totalEntries = updatedScriptEntries.length;
        var totalBatches = Math.ceil(totalEntries / batchSize);
        
        for (var b = 0; b < totalBatches; b++) {
            var startIdx2 = b * batchSize;
            var endIdx2 = Math.min((b + 1) * batchSize, totalEntries);
            var batchStart = startIdx2 + 1;
            var batchEnd = endIdx2;
            
            textOutput += 'BATCH ' + batchStart + ' - ' + batchEnd + '\n\n';
            
            for (var j = startIdx2; j < endIdx2; j++) {
                var entry = updatedScriptEntries[j];
                var entryNum = j + 1;
                
                textOutput += entryNum + '.\n';
                
                // Add View Focus Characters
                if (entry._characters && entry._characters.length > 0) {
                    textOutput += 'View Focus Characters: ' + entry._characters.join(' and ') + '\n';
                } else {
                    textOutput += 'View Focus Characters: (none)\n';
                }
                
                // Add character details for each character
                if (entry._characters && entry._characters.length > 0) {
                    for (var c = 0; c < entry._characters.length; c++) {
                        var charName = entry._characters[c];
                        var charInfo = charPrompts[charName];
                        if (charInfo) {
                            textOutput += charName + ' Details:\n';
                            textOutput += '    relation: ' + (charInfo.relation || '') + '\n';
                            textOutput += '    details: ' + (charInfo.character_details || '') + '\n';
                        }
                    }
                }
                
                textOutput += 'Dialogue: ' + (entry.dialogue || '') + '\n';
                textOutput += 'Scene Pov Promt: ' + (entry.Scene_Pov_promt || '') + '\n\n';
            }
        }
        
        return {
            text: textOutput,
            totalEntries: totalEntries,
            batches: totalBatches,
            script: updatedScriptEntries
        };
    }

    // NEW FUNCTION: Convert and display inline without modal
    function convertAndDisplayInline(elementId) {
        // Check if transcript detection is enabled for this tab
        var features = getActiveTabFeatures();
        if (features.transcript_detection !== true) {
            showDialog('Info', 'Transcript detection is not enabled for this tab.');
            return;
        }
        
        var data = window._transcriptData[elementId];
        if (!data) {
            showDialog('Error', 'Transcript data not found.');
            return;
        }
        
        var transcriptText = data.value;
        if (!transcriptText || transcriptText.trim() === '') {
            showDialog('Error', 'Transcript is empty.');
            return;
        }
        
        // Check if result already exists
        var existingResult = document.getElementById('transcript_result_' + elementId);
        if (existingResult) {
            // If exists, just toggle visibility or refresh
            if (existingResult.style.display === 'none') {
                existingResult.style.display = 'block';
            } else {
                // Re-convert
                existingResult.remove();
                delete window._conversionResults[elementId];
            }
        }
        
        // Show loading indicator
        var indicator = document.getElementById('transcript_indicator_' + elementId);
        if (indicator) {
            var convertBtn = indicator.querySelector('.btn-success');
            if (convertBtn) {
                convertBtn.textContent = '⏳ Processing...';
                convertBtn.disabled = true;
            }
        }
        
        setTimeout(function() {
            try {
                var characterData = window._characterData || null;
                var result = processTranscriptConversion(transcriptText, characterData);
                
                // Store result
                window._conversionResults[elementId] = result;
                
                // Remove any existing result div first
                var existingResultDiv = document.getElementById('transcript_result_' + elementId);
                if (existingResultDiv) {
                    existingResultDiv.remove();
                }
                
                // Create result display
                var resultDiv = document.createElement('div');
                resultDiv.id = 'transcript_result_' + elementId;
                resultDiv.style.cssText = 'margin-top:8px; padding:12px; background:#f0fdf4; border-radius:8px; border:1px solid #a7f3d0;';
                
                // Build character status
                var charStatus = characterData && Object.keys(characterData).length > 0 ? 
                    '👤 ' + Object.keys(characterData).length + ' characters' : 
                    '👤 No character data';
                
                resultDiv.innerHTML = 
                    '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">' +
                    '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">' +
                    '<span style="color:#065f46; font-weight:600;">✅ Conversion Complete</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">📊 ' + result.script.length + ' entries</span>' +
                    '<span style="font-size:12px; color:#065f46; background:#d1fae5; padding:2px 8px; border-radius:12px;">' + charStatus + '</span>' +
                    '</div>' +
                    '<div style="display:flex; gap:6px; flex-wrap:wrap;">' +
                    '<button type="button" class="btn btn-primary btn-sm" onclick="copyInlineJson(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px;">📋 Copy JSON</button>' +
                    '<button type="button" class="btn btn-sm" onclick="toggleInlineResult(\'' + elementId + '\')" style="padding:2px 10px; font-size:11px; background:#e5e7eb; color:#374151;">✕</button>' +
                    '</div>' +
                    '</div>' +
                    '<div style="background:#1a1a2e; color:#e5e7eb; padding:12px; border-radius:6px; overflow:auto; max-height:250px; font-size:11px; font-family:monospace; white-space:pre-wrap; word-break:break-all;">' + 
                    result.jsonString.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                    '</div>';
                
                // Insert after indicator
                var indicatorEl = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl) {
                    indicatorEl.parentNode.insertBefore(resultDiv, indicatorEl.nextSibling);
                } else {
                    // Fallback: find container
                    var container = document.querySelector('.form-group') || document.body;
                    container.appendChild(resultDiv);
                }
                
                // Reset button
                if (indicatorEl) {
                    var convertBtn2 = indicatorEl.querySelector('.btn-success');
                    if (convertBtn2) {
                        convertBtn2.textContent = '🔄 Convert to JSON';
                        convertBtn2.disabled = false;
                    }
                }
                
            } catch (error) {
                // Reset button
                var indicatorEl2 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl2) {
                    var convertBtn3 = indicatorEl2.querySelector('.btn-success');
                    if (convertBtn3) {
                        convertBtn3.textContent = '🔄 Convert to JSON';
                        convertBtn3.disabled = false;
                    }
                }
                
                // Show error
                var errorDiv = document.createElement('div');
                errorDiv.id = 'transcript_result_' + elementId;
                errorDiv.style.cssText = 'margin-top:8px; padding:10px; background:#fef2f2; border-radius:8px; border:1px solid #fecaca; color:#dc2626;';
                errorDiv.textContent = '❌ Error: ' + error.message;
                
                var indicatorEl3 = document.getElementById('transcript_indicator_' + elementId);
                if (indicatorEl3) {
                    indicatorEl3.parentNode.insertBefore(errorDiv, indicatorEl3.nextSibling);
                }
            }
        }, 100);
    }

    // Toggle inline result visibility
    function toggleInlineResult(elementId) {
        var resultDiv = document.getElementById('transcript_result_' + elementId);
        if (resultDiv) {
            if (resultDiv.style.display === 'none') {
                resultDiv.style.display = 'block';
            } else {
                resultDiv.style.display = 'none';
            }
        }
    }

    // Copy inline JSON result
    function copyInlineJson(elementId) {
        var result = window._conversionResults[elementId];
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        copyToClipboard(result.jsonString);
    }

    // Download inline JSON result
    function downloadInlineJson(elementId) {
        var result = window._conversionResults[elementId];
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        var blob = new Blob([result.jsonString], {type: 'application/json'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'transcript_converted.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Function to convert transcript to JSON (for modal)
    function convertTranscriptToJson(elementId) {
        var textarea = document.getElementById('transcriptTextarea');
        if (!textarea) {
            showDialog('Error', 'Transcript content not found.');
            return;
        }
        
        var transcriptText = textarea.value;
        if (!transcriptText || transcriptText.trim() === '') {
            showDialog('Error', 'Transcript is empty.');
            return;
        }
        
        var resultDiv = document.getElementById('transcriptConversionResult');
        if (resultDiv) {
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="color:#00695c; padding:10px; text-align:center;">⏳ Processing transcript...</div>';
        }
        
        setTimeout(function() {
            try {
                var characterData = window._characterData || null;
                var result = processTranscriptConversion(transcriptText, characterData);
                
                // Build detailed log message
                var logMessages = [];
                logMessages.push('📁 [CONVERT] Processing transcript...');
                logMessages.push('📖 [CONVERT] Reading transcript content...');
                logMessages.push('✅ [CONVERT] Found ' + result.timestamps + ' timestamps');
                
                if (characterData && Object.keys(characterData).length > 0) {
                    var charNames = Object.keys(characterData);
                    logMessages.push('📋 [CONVERT] Found character data: ' + JSON.stringify(charNames));
                    logMessages.push('✅ [CONVERT] Extracted ' + charNames.length + ' characters from character data');
                    logMessages.push('📋 [CONVERT] Character data extracted: ' + JSON.stringify(charNames));
                    // Log variations for each character
                    for (var charName in characterData) {
                        if (characterData[charName].variations) {
                            logMessages.push('🔍 [CONVERT] "' + charName + '" variations: ' + JSON.stringify(characterData[charName].variations));
                        }
                    }
                } else {
                    logMessages.push('⚠️ [CONVERT] No character data found, proceeding without character prompts');
                }
                
                logMessages.push('✅ [CONVERT] Parsed ' + result.script.length + ' dialogue entries');
                logMessages.push('✅ [CONVERT] JSON conversion complete');
                logMessages.push('📊 [CONVERT] Total dialogue entries: ' + result.script.length);
                
                var logHtml = logMessages.map(function(msg) {
                    var color = '#6b7280';
                    if (msg.indexOf('✅') !== -1) color = '#065f46';
                    else if (msg.indexOf('📋') !== -1) color = '#00695c';
                    else if (msg.indexOf('⚠️') !== -1) color = '#f59e0b';
                    else if (msg.indexOf('❌') !== -1) color = '#ef4444';
                    else if (msg.indexOf('🔍') !== -1) color = '#8b5cf6';
                    else if (msg.indexOf('📁') !== -1 || msg.indexOf('📖') !== -1) color = '#2563eb';
                    else if (msg.indexOf('📊') !== -1) color = '#7c3aed';
                    return '<div style="color:' + color + '; font-family:monospace; font-size:13px; padding:2px 0;">' + msg + '</div>';
                }).join('');
                
                var jsonResultDiv = document.getElementById('transcriptJsonResult');
                if (jsonResultDiv) {
                    jsonResultDiv.style.display = 'block';
                    jsonResultDiv.innerHTML = 
                        '<div style="background:#f0fdf4; border-radius:8px; padding:15px; border:1px solid #a7f3d0;">' +
                        '<div style="color:#065f46; font-weight:600; margin-bottom:8px;">✅ Conversion Complete</div>' +
                        '<div style="font-size:13px; color:#065f46; margin-bottom:10px;">' +
                        '📊 ' + result.script.length + ' dialogue entries parsed' +
                        (characterData && Object.keys(characterData).length > 0 ? ' | 👤 ' + Object.keys(characterData).length + ' characters extracted' : ' | 👤 No character data') +
                        '</div>' +
                        '<div style="background:#f8fafc; border-radius:8px; padding:12px; margin-bottom:10px; border:1px solid #e5e7eb; max-height:200px; overflow-y:auto;">' +
                        logHtml +
                        '</div>' +
                        '<div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">' +
                        '<button type="button" class="btn btn-primary btn-sm" onclick="copyJsonResult()">📋 Copy JSON</button>' +
                        '</div>' +
                        '<pre style="background:#1a1a2e; color:#e5e7eb; padding:15px; border-radius:8px; overflow:auto; max-height:300px; font-size:12px; white-space:pre-wrap; word-break:break-all; margin-top:10px;">' + 
                        result.jsonString.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                        '</pre>' +
                        '</div>';
                }
                
                if (resultDiv) {
                    resultDiv.style.display = 'none';
                }
                
                window._transcriptResult = result;
                
            } catch (error) {
                if (resultDiv) {
                    resultDiv.innerHTML = '<div style="color:#ef4444; padding:10px; text-align:center;">❌ Error: ' + error.message + '</div>';
                }
            }
        }, 100);
    }

    // Normalize transcript text - remove special characters around timestamps
    function normalizeTranscriptText(text) {
        var normalized = text;
        var jsonArrayMatch = text.match(/^[\s\n\r]*\[\s*\{[^]*\}\s*\]\s*/);
        if (jsonArrayMatch) {
            normalized = text.substring(jsonArrayMatch[0].length);
        }
        normalized = normalized.replace(/[\(\[\{\-\_\s]*(\d{1,2}:\d{2})[\)\]\}\-\_\s]*/g, '$1');
        normalized = normalized.replace(/^[\(\[\{\-\_\s]*(\d{1,2}:\d{2})[\)\]\}\-\_\s]*/gm, '$1');
        normalized = normalized.replace(/(\d{1,2}:\d{2})[\)\]\}\-\_]/g, '$1');
        normalized = normalized.replace(/[\(\[\{\-\_]( \d{1,2}:\d{2})/g, '$1');
        return normalized;
    }

    // Main conversion engine - WITHOUT view_focus_characters and WITHOUT remaining_nonfeatured_characters
    function processTranscriptConversion(transcriptText, characterData) {
        // ===== Extract character data from JSON array at the beginning if not provided =====
        if (!characterData || Object.keys(characterData).length === 0) {
            characterData = extractCharacterDataFromJson(transcriptText);
        }
        
        // ===== Extract only the transcript part (remove JSON) =====
        var transcriptOnly = extractTranscriptOnly(transcriptText);
        
        // ===== Parse the transcript - find all timestamps =====
        var normalizedText = normalizeTranscriptText(transcriptOnly);
        var timestampPattern = /(\d{1,2}:\d{2})/g;
        var matches = [];
        var match;
        
        while ((match = timestampPattern.exec(normalizedText)) !== null) {
            var timeParts = match[1].split(':');
            var minutes = parseInt(timeParts[0]);
            var seconds = parseInt(timeParts[1]);
            var timeStr = minutes + ':' + String(seconds).padStart(2, '0');
            var timeSeconds = minutes * 60 + seconds;
            matches.push({
                time_str: timeStr,
                time_seconds: timeSeconds,
                start_pos: match.index,
                end_pos: match.index + match[0].length
            });
        }
        
        if (matches.length === 0) {
            throw new Error('No timestamps found in transcript');
        }
        
        // ===== Build script entries WITHOUT view_focus_characters =====
        var scriptLines = [];
        
        for (var i = 0; i < matches.length - 1; i++) {
            var current = matches[i];
            var nextMatch = matches[i + 1];
            
            var dialogueStart = current.end_pos;
            var dialogueEnd = nextMatch.start_pos;
            var dialogueText = normalizedText.substring(dialogueStart, dialogueEnd).trim();
            
            // Clean up dialogue - remove any extra timestamps
            dialogueText = dialogueText.replace(/\d{1,2}:\d{2}/g, '').trim();
            dialogueText = dialogueText.replace(/\s+/g, ' ');
            
            if (dialogueText) {
                var duration = nextMatch.time_seconds - current.time_seconds;
                
                // Build the script entry WITHOUT view_focus_characters
                var scriptEntry = {
                    start: current.time_str,
                    stop: nextMatch.time_str,
                    duration: duration + ' seconds',
                    view_focus_characters: '', // Always empty
                    dialogue: dialogueText,
                    Scene_Pov_promt: ''
                };
                
                scriptLines.push(scriptEntry);
            }
        }
        
        if (scriptLines.length === 0) {
            throw new Error('No dialogue entries could be parsed');
        }
        
        // ===== Create the JSON structure with ONLY characters_visual_prompts and script =====
        var jsonData = {};
        
        // Add characters_visual_prompts if character data exists
        if (characterData && Object.keys(characterData).length > 0) {
            var charactersVisualPrompts = {};
            for (var charName in characterData) {
                var charInfo = characterData[charName];
                charactersVisualPrompts[charName] = {
                    relation: charInfo.relation || '',
                    character_details: charInfo.character_details || '',
                    character_visual_prompt: charInfo.prompt || ''
                };
            }
            jsonData.characters_visual_prompts = charactersVisualPrompts;
        } else {
            jsonData.characters_visual_prompts = {};
        }
        
        // Add script (NO remaining_nonfeatured_characters)
        jsonData.script = scriptLines;
        
        return {
            script: scriptLines,
            characters: characterData,
            remaining: {}, // Always empty
            timestamps: matches.length,
            json: jsonData,
            jsonString: JSON.stringify(jsonData, null, 4)
        };
    }

    // Extract character data from JSON array at the beginning (kept for backward compatibility)
    function extractCharacterDataFromJson(transcriptText) {
        var characterData = {};
        
        // Look for the JSON array format: [{"character": "Name", ...}, ...]
        var jsonPattern = /\[\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*(?:,\s*\{[^}]*"character"\s*:\s*"[^"]*"[^}]*\}\s*)*\]/;
        var jsonMatch = transcriptText.match(jsonPattern);
        
        if (jsonMatch) {
            try {
                var jsonStr = jsonMatch[0];
                var characterArray = JSON.parse(jsonStr);
                
                if (Array.isArray(characterArray)) {
                    characterArray.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    
                    if (Object.keys(characterData).length > 0) {
                        console.log('✅ [CONVERT] Extracted ' + Object.keys(characterData).length + ' characters from JSON array');
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ [CONVERT] Failed to parse JSON array:', e.message);
            }
        }
        
        // Method 2: Try to find individual character objects
        var charObjects = transcriptText.match(/\{[^}]*"character"\s*:\s*"[^"]*"[^}]*"relation"\s*:\s*"[^"]*"[^}]*"character_details"\s*:\s*"[^"]*"[^}]*"character_visual_prompt"\s*:\s*"[^"]*"[^}]*\}/g);
        
        if (charObjects) {
            charObjects.forEach(function(charObjStr) {
                try {
                    var charObj = JSON.parse(charObjStr);
                    var characterName = charObj.character || '';
                    if (characterName) {
                        var relation = charObj.relation || '';
                        var castDetails = charObj.character_details || '';
                        var prompt = charObj.character_visual_prompt || '';
                        
                        var cleanName = extractCleanName(characterName);
                        
                        var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                        
                        characterData[characterName] = {
                            name: characterName,
                            clean_name: cleanName,
                            relation: relation,
                            character_details: castDetails,
                            details: fullDetails,
                            prompt: prompt,
                            variations: generateNameVariations(cleanName, characterName)
                        };
                    }
                } catch (e) {
                    // Skip invalid JSON objects
                }
            });
            
            if (Object.keys(characterData).length > 0) {
                console.log('✅ [CONVERT] Extracted ' + Object.keys(characterData).length + ' characters from individual JSON objects');
                return characterData;
            }
        }
        
        // Method 3: Look for JSON code block
        var jsonCodeMatch = transcriptText.match(/```json\s*(\[[\s\S]*?\])\s*```/);
        if (jsonCodeMatch) {
            try {
                var charArray2 = JSON.parse(jsonCodeMatch[1]);
                if (Array.isArray(charArray2)) {
                    charArray2.forEach(function(charObj) {
                        var characterName = charObj.character || '';
                        if (characterName) {
                            var relation = charObj.relation || '';
                            var castDetails = charObj.character_details || '';
                            var prompt = charObj.character_visual_prompt || '';
                            
                            var cleanName = extractCleanName(characterName);
                            
                            var fullDetails = "**Story Details:**\n" + castDetails + "\n\n**Character Image Generation Prompt:**\n\"" + prompt + "\"";
                            
                            characterData[characterName] = {
                                name: characterName,
                                clean_name: cleanName,
                                relation: relation,
                                character_details: castDetails,
                                details: fullDetails,
                                prompt: prompt,
                                variations: generateNameVariations(cleanName, characterName)
                            };
                        }
                    });
                    
                    if (Object.keys(characterData).length > 0) {
                        console.log('✅ [CONVERT] Extracted ' + Object.keys(characterData).length + ' characters from JSON code block');
                        return characterData;
                    }
                }
            } catch (e) {
                console.log('⚠️ [CONVERT] Failed to parse JSON code block:', e.message);
            }
        }
        
        console.log('⚠️ [CONVERT] No character data found in JSON format');
        return characterData;
    }

    // Extract only the transcript part (remove JSON)
    function extractTranscriptOnly(transcriptText) {
        // Find where the transcript starts - look for the first timestamp
        var timestampPattern = /\(\d{1,2}:\d{2}\)/;
        var firstTimestamp = timestampPattern.exec(transcriptText);
        
        if (firstTimestamp) {
            // Start from the first timestamp
            return transcriptText.substring(firstTimestamp.index);
        }
        
        return transcriptText;
    }

    // Legacy function for backward compatibility
    function findCharactersInDialogue(dialogueText, characterData) {
        return [];
    }

    // Copy JSON result (from modal)
    function copyJsonResult() {
        var result = window._transcriptResult;
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        copyToClipboard(result.jsonString);
    }

    // Download JSON result (from modal)
    function downloadJsonResult() {
        var result = window._transcriptResult;
        if (!result) {
            showDialog('Error', 'No conversion result found.');
            return;
        }
        var blob = new Blob([result.jsonString], {type: 'application/json'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'transcript_converted.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Helper: Copy to clipboard
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showDialog('Success', 'Copied to clipboard!');
            }).catch(function() {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showDialog('Success', 'Copied to clipboard!');
        } catch (e) {
            showDialog('Error', 'Failed to copy. Please copy manually.');
        }
        document.body.removeChild(textarea);
    }

    // Start transcript detection
    function startTranscriptDetection() {
        detectTranscripts();
        if (window._transcriptDetectionInterval) {
            clearInterval(window._transcriptDetectionInterval);
        }
        window._transcriptDetectionInterval = setInterval(function() {
            detectTranscripts();
        }, 1000);
    }

    // Override switchUserConfigTab to re-detect transcripts when switching to add_entry
    var originalSwitchUserConfigTab = window.switchUserConfigTab || function() {};
    switchUserConfigTab = function(tabName, btn) {
        originalSwitchUserConfigTab(tabName, btn);
        if (tabName === 'add_entry') {
            setTimeout(function() { 
                detectTranscripts();
                updateCopyButtonsVisibility();
            }, 100);
        }
    };

    // Override switchNewProjectSubTab to re-detect transcripts when switching sub-tabs
    var originalSwitchNewProjectSubTab = window.switchNewProjectSubTab || function() {};
    switchNewProjectSubTab = function(tabId, btn) {
        originalSwitchNewProjectSubTab(tabId, btn);
        setTimeout(function() { 
            detectTranscripts();
            updateCopyButtonsVisibility();
        }, 100);
    };

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        startTranscriptDetection();
        setTimeout(function() {
            if (typeof updateCopyButtonsVisibility === 'function') {
                updateCopyButtonsVisibility();
            }
        }, 500);
    });
</script>
</body>
</html>