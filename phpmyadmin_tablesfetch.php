<?php
// phpmyadmin_tablesfetch.php
// Updated to accept both GET and POST for sql_query

$host       = 'sql312.infinityfree.com';
$dbname     = 'if0_40473107_harvhub';
$dbUsername = 'if0_40473107';
$dbPassword = 'InDQmdl53FZ85';

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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]);

        // Custom SQL query - now accepts from GET, POST, or JSON
        if ($sqlQuery !== null) {
            // Log for debugging
            error_log("Executing query: " . $sqlQuery);
            
            $stmt = $pdo->query($sqlQuery);
            $result = [];

            if ($stmt->columnCount() > 0) {
                $result['rows'] = $stmt->fetchAll();
                $result['columnMeta'] = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $result['columnMeta'][] = ['name' => $meta['name']];
                }
            } else {
                $result['affectedRows'] = $stmt->rowCount();
            }

            return ['status' => 'success', 'data' => $result, 'message' => 'Query executed'];
        }

        // Get columns of a table
        elseif ($table !== null) {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            $columns = $stmt->fetchAll();

            return ['status' => 'success', 'columns' => $columns, 'message' => "Columns for `$table`"];
        }

        // List all tables
        else {
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return ['status' => 'success', 'tables' => $tables, 'message' => 'Tables retrieved'];
        }

    } catch (PDOException $e) {
        return [
            'status'   => 'error',
            'message'  => 'Database error: ' . $e->getMessage(),
            'tables'   => [],
            'columns'  => [],
            'data'     => []
        ];
    }
}

// Get parameters from multiple sources
$table = null;
$sqlQuery = null;

// Check GET parameters
if (isset($_GET['table'])) {
    $table = $_GET['table'];
}
if (isset($_GET['sql_query'])) {
    $sqlQuery = $_GET['sql_query'];
}

// Check POST parameters (form data)
if (isset($_POST['table'])) {
    $table = $_POST['table'];
}
if (isset($_POST['sql_query'])) {
    $sqlQuery = $_POST['sql_query'];
}

// Check JSON input (for application/json requests)
$inputJSON = file_get_contents('php://input');
if ($inputJSON) {
    $input = json_decode($inputJSON, true);
    if ($input) {
        if (isset($input['table'])) $table = $input['table'];
        if (isset($input['sql_query'])) $sqlQuery = $input['sql_query'];
    }
}

// Return JSON
echo json_encode(handleDatabaseRequest($host, $dbname, $dbUsername, $dbPassword, $table, $sqlQuery));
?>