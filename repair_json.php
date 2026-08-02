<?php
// repair_json.php - Run this to fix corrupted JSON data

  $host = 'sql201.infinityfree.com';
  $dbname = 'if0_40367004_automation_tree';
  $username = 'if0_40367004';
  $password = 'NkwFAH15FRIlvCf';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get current data
    $stmt = $pdo->query("SELECT all_urls FROM jpgsvault WHERE id = 1");
    $currentData = $stmt->fetchColumn();
    
    if ($currentData) {
        // Try to fix JSON
        $fixed = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $currentData);
        
        // Check if it's truncated
        $openBrackets = substr_count($fixed, '[') - substr_count($fixed, ']');
        if ($openBrackets > 0) {
            $fixed .= str_repeat(']', $openBrackets);
        }
        
        $decoded = json_decode($fixed, true);
        if ($decoded !== null && is_array($decoded)) {
            // Re-encode properly
            $clean = json_encode($decoded, JSON_UNESCAPED_SLASHES);
            $update = $pdo->prepare("UPDATE jpgsvault SET all_urls = ? WHERE id = 1");
            $update->execute([$clean]);
            
            echo "✅ JSON repaired successfully! " . count($decoded) . " URLs restored.<br>";
            echo "Size: " . round(strlen($clean) / 1024, 2) . " KB";
        } else {
            echo "❌ Could not repair JSON. Error: " . json_last_error_msg();
        }
    } else {
        echo "No data found to repair.";
    }
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}
?>