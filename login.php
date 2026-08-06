<?php
// login.php - Standalone login page for TASK TOOL
session_start();

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

// Ensure table and columns exist
$pdo->exec("CREATE TABLE IF NOT EXISTS jpgsvault (id INT AUTO_INCREMENT PRIMARY KEY)");

// Check if server_passkey column exists
$stmt = $pdo->query("SHOW COLUMNS FROM jpgsvault LIKE 'server_passkey'");
$hasPasskeyCol = $stmt->rowCount() > 0;

if (!$hasPasskeyCol) {
    $pdo->exec("ALTER TABLE jpgsvault ADD COLUMN server_passkey VARCHAR(255) DEFAULT NULL");
}

// Get stored passkey
$stmt = $pdo->prepare("SELECT server_passkey FROM jpgsvault WHERE id = 1");
$stmt->execute();
$storedPasskey = $stmt->fetchColumn();
$hasPasskey = !empty($storedPasskey);

// Handle passkey setup
if (isset($_POST['action']) && $_POST['action'] === 'setup_passkey') {
    $newPasskey = trim($_POST['passkey'] ?? '');
    if (empty($newPasskey)) {
        echo json_encode(['success' => false, 'message' => 'Passkey cannot be empty']);
        exit;
    }
    
    $hashedPasskey = password_hash($newPasskey, PASSWORD_DEFAULT);
    
    $pdo->prepare("UPDATE jpgsvault SET server_passkey = ? WHERE id = 1")
        ->execute([$hashedPasskey]);
    
    $_SESSION['jpgsvault_authenticated'] = true;
    $_SESSION['jpgsvault_last_activity'] = time();
    
    echo json_encode(['success' => true, 'redirect' => 'index.php']);
    exit;
}

// Handle passkey verification
if (isset($_POST['action']) && $_POST['action'] === 'verify_passkey') {
    $inputPasskey = trim($_POST['passkey'] ?? '');
    
    if (empty($storedPasskey)) {
        echo json_encode(['success' => false, 'message' => 'No passkey set']);
        exit;
    }
    
    if (password_verify($inputPasskey, $storedPasskey)) {
        $_SESSION['jpgsvault_authenticated'] = true;
        $_SESSION['jpgsvault_last_activity'] = time();
        echo json_encode(['success' => true, 'redirect' => 'index.php']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid passkey']);
    }
    exit;
}

// Check if already authenticated and redirect
if (isset($_SESSION['jpgsvault_authenticated']) && $_SESSION['jpgsvault_authenticated'] === true) {
    if (isset($_SESSION['jpgsvault_last_activity'])) {
        if (time() - $_SESSION['jpgsvault_last_activity'] > 1800) {
            session_destroy();
        } else {
            $_SESSION['jpgsvault_last_activity'] = time();
            header('Location: index.php');
            exit;
        }
    }
}

$redirectUrl = 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TASK TOOL - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html, body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: #ffffff;
            overflow: hidden;
        }
        .login-container {
            background: #ffffff;
            padding: 2.5rem 3rem;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.12);
            max-width: 520px;
            width: 92%;
            text-align: center;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        .login-container .logo {
            font-size: 3.2rem;
            margin-bottom: 0.3rem;
        }
        .login-container h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.2rem;
        }
        .login-container .subtitle {
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 1.8rem;
        }
        .input-group {
            text-align: left;
            margin-bottom: 1.1rem;
        }
        .input-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }
        .input-group input {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 2px solid #e8edf2;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: border-color 0.3s, box-shadow 0.3s;
            font-family: 'Inter', sans-serif;
            background: #fafbfc;
        }
        .input-group input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
            background: #ffffff;
        }
        .login-container button {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            margin-top: 0.3rem;
        }
        .login-container button:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(99,102,241,0.3);
        }
        .login-container button:active {
            transform: scale(0.98);
        }
        .error {
            color: #ef4444;
            font-size: 0.85rem;
            margin-top: 0.6rem;
            min-height: 1.2rem;
        }
        .success {
            color: #10b981;
            font-size: 0.85rem;
            margin-top: 0.6rem;
            min-height: 1.2rem;
        }
        .login-container .setup-message {
            background: #f0f9ff;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: #0284c7;
            font-size: 0.85rem;
            margin-bottom: 1.2rem;
            border: 1px solid #bae6fd;
        }
        .login-container .login-message {
            background: #f0fdf4;
            padding: 0.6rem 1rem;
            border-radius: 8px;
            color: #16a34a;
            font-size: 0.85rem;
            margin-bottom: 1.2rem;
            border: 1px solid #bbf7d0;
        }
        .loading-spinner {
            display: none;
            margin: 0.4rem auto;
            width: 26px;
            height: 26px;
            border: 3px solid #e2e8f0;
            border-top: 3px solid #6366f1;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
                max-width: 95%;
            }
            .login-container h1 {
                font-size: 1.6rem;
            }
            .login-container .logo {
                font-size: 2.8rem;
            }
            .login-container {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">🔧</div>
        <h1>TASK TOOL</h1>
        <p class="subtitle">Secure Access</p>
        
        <div id="setup-section" style="display: <?= $hasPasskey ? 'none' : 'block' ?>;">
            <div class="setup-message">
                🔐 First time setup - Create your passkey
            </div>
            <div class="input-group">
                <label>New Passkey</label>
                <input type="password" id="new-passkey" placeholder="Enter passkey" autocomplete="new-password">
            </div>
            <div class="input-group">
                <label>Confirm Passkey</label>
                <input type="password" id="confirm-passkey" placeholder="Confirm passkey" autocomplete="new-password">
            </div>
            <button onclick="setupPasskey()">Create Passkey</button>
        </div>
        
        <div id="login-section" style="display: <?= $hasPasskey ? 'block' : 'none' ?>;">
            <div class="login-message">
                🔑 Enter your passkey to continue
            </div>
            <div class="input-group">
                <label>Passkey</label>
                <input type="password" id="login-passkey" placeholder="Enter your passkey" autocomplete="current-password">
            </div>
            <button onclick="verifyPasskey()">Login</button>
        </div>
        
        <div id="login-error" class="error"></div>
        <div id="login-success" class="success"></div>
        <div class="loading-spinner" id="loading-spinner"></div>
    </div>

    <script>
        const redirectUrl = 'index.php';
        
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                if (document.getElementById('login-section').style.display === 'block') {
                    verifyPasskey();
                } else if (document.getElementById('setup-section').style.display === 'block') {
                    setupPasskey();
                }
            }
        });

        function setLoading(loading) {
            document.getElementById('loading-spinner').style.display = loading ? 'block' : 'none';
            document.querySelectorAll('#setup-section button, #login-section button').forEach(btn => {
                btn.disabled = loading;
                btn.style.opacity = loading ? '0.5' : '1';
            });
        }

        function showError(message) {
            const errorDiv = document.getElementById('login-error');
            errorDiv.textContent = message;
            document.getElementById('login-success').textContent = '';
        }

        function showSuccess(message) {
            const successDiv = document.getElementById('login-success');
            successDiv.textContent = message;
            document.getElementById('login-error').textContent = '';
        }

        function setupPasskey() {
            const newPasskey = document.getElementById('new-passkey').value;
            const confirmPasskey = document.getElementById('confirm-passkey').value;
            
            if (!newPasskey) {
                showError('Please enter a passkey');
                return;
            }
            
            if (newPasskey.length < 4) {
                showError('Passkey must be at least 4 characters');
                return;
            }
            
            if (newPasskey !== confirmPasskey) {
                showError('Passkeys do not match');
                return;
            }
            
            setLoading(true);
            showError('');
            
            fetch('login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=setup_passkey&passkey=${encodeURIComponent(newPasskey)}`
            })
            .then(r => r.json())
            .then(res => {
                setLoading(false);
                if (res.success) {
                    showSuccess('✅ Passkey created successfully! Redirecting...');
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 1000);
                } else {
                    showError(res.message || 'Setup failed');
                }
            })
            .catch(err => {
                setLoading(false);
                showError('Connection error. Please try again.');
            });
        }
        
        function verifyPasskey() {
            const passkey = document.getElementById('login-passkey').value;
            
            if (!passkey) {
                showError('Please enter your passkey');
                return;
            }
            
            setLoading(true);
            showError('');
            
            fetch('login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=verify_passkey&passkey=${encodeURIComponent(passkey)}`
            })
            .then(r => r.json())
            .then(res => {
                setLoading(false);
                if (res.success) {
                    showSuccess('✅ Login successful! Redirecting...');
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 800);
                } else {
                    showError(res.message || 'Invalid passkey');
                    document.getElementById('login-passkey').value = '';
                    document.getElementById('login-passkey').focus();
                }
            })
            .catch(err => {
                setLoading(false);
                showError('Connection error. Please try again.');
            });
        }
    </script>
</body>
</html>