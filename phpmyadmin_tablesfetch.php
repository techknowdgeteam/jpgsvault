
<?php
// phpmyadmin_tablesfetch.php
// Fixed version with proper JSON handling and error checking

  $host = 'sql201.infinityfree.com';
  $dbname = 'if0_40367004_automation_tree';
  $username = 'if0_40367004';
  $password = 'NkwFAH15FRIlvCf';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function handleDatabaseRequest($host, $dbname, $dbUsername, $dbPassword, $table = null, $sqlQuery = null) {
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUsername, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);

        // === CUSTOM SQL QUERY ===
        if ($sqlQuery !== null) {
            error_log("Executing query: " . $sqlQuery);
            
            try {
                $stmt = $pdo->query($sqlQuery);
                $result = [];

                if ($stmt->columnCount() > 0) {
                    $rows = $stmt->fetchAll();
                    
                    // Process each row to validate/fix JSON fields
                    foreach ($rows as &$row) {
                        foreach ($row as $key => $value) {
                            // Check if column might contain JSON
                            if (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
                                // Try to validate JSON
                                $decoded = json_decode($value, true);
                                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                                    // Attempt to fix common JSON issues
                                    $fixed = fixBrokenJson($value);
                                    if ($fixed !== null) {
                                        $row[$key] = $fixed;
                                    } else {
                                        $row[$key] = ['error' => 'Invalid JSON', 'raw' => substr($value, 0, 200) . '...'];
                                    }
                                } elseif ($decoded !== null) {
                                    // For large JSON arrays, show count instead of full data
                                    if (is_array($decoded) && count($decoded) > 1000000) {
                                        $row[$key] = [
                                            'type' => 'array',
                                            'count' => count($decoded),
                                            'preview' => array_slice($decoded, 0, 5),
                                            'total_items' => count($decoded)
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    
                    $result['rows'] = $rows;
                    $result['columnMeta'] = [];
                    for ($i = 0; $i < $stmt->columnCount(); $i++) {
                        $meta = $stmt->getColumnMeta($i);
                        $result['columnMeta'][] = ['name' => $meta['name']];
                    }
                } else {
                    $result['affectedRows'] = $stmt->rowCount();
                }

                return [
                    'status' => 'success', 
                    'data' => $result, 
                    'message' => 'Query executed successfully'
                ];
            } catch (PDOException $e) {
                return [
                    'status' => 'error',
                    'message' => 'SQL Error: ' . $e->getMessage(),
                    'sql' => $sqlQuery
                ];
            }
        }

        // === GET COLUMNS OF A SPECIFIC TABLE ===
        elseif ($table !== null) {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            $columns = $stmt->fetchAll();

            return [
                'status' => 'success', 
                'columns' => $columns, 
                'message' => "Columns for table `$table` retrieved"
            ];
        }

        // === LIST ALL TABLES (Default) ===
        else {
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return [
                'status' => 'success', 
                'tables' => $tables, 
                'message' => 'All tables retrieved successfully'
            ];
        }

    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'tables' => [],
            'columns' => [],
            'data' => []
        ];
    }
}

/**
 * Attempt to fix common JSON issues
 */
function fixBrokenJson($jsonString) {
    // Remove BOM and control characters
    $jsonString = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $jsonString);
    
    // Try to fix truncated JSON
    $decoded = json_decode($jsonString, true);
    if ($decoded !== null) {
        return $decoded;
    }
    
    // Check if it's a truncated array
    if (strpos($jsonString, '[') === 0) {
        // Try to complete the array
        $openBrackets = substr_count($jsonString, '[') - substr_count($jsonString, ']');
        if ($openBrackets > 0) {
            $fixed = $jsonString . str_repeat(']', $openBrackets);
            $decoded = json_decode($fixed, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
    }
    
    return null;
}

// Get parameters
$table = null;
$sqlQuery = null;

if (isset($_GET['table'])) {
    $table = $_GET['table'];
}
if (isset($_GET['sql_query'])) {
    $sqlQuery = $_GET['sql_query'];
}
if (isset($_POST['table'])) {
    $table = $_POST['table'];
}
if (isset($_POST['sql_query'])) {
    $sqlQuery = $_POST['sql_query'];
}

$inputJSON = file_get_contents('php://input');
if (!empty($inputJSON)) {
    $input = json_decode($inputJSON, true);
    if (is_array($input)) {
        if (isset($input['table'])) $table = $input['table'];
        if (isset($input['sql_query'])) $sqlQuery = $input['sql_query'];
    }
}

echo json_encode(
    handleDatabaseRequest($host, $dbname, $username, $password, $table, $sqlQuery),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES
);
?>