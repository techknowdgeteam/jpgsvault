<?php
// index.php - Main Dashboard
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TASK TOOL - Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        
        /* ===== HEADER ===== */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 50%, #2c3e50 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            height: 65px;
            flex-shrink: 0;
        }
        
        .main-header .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .main-header .logo .icon {
            font-size: 28px;
        }
        
        .main-header .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .logout-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(231,76,60,0.3);
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(231,76,60,0.4);
        }
        
        /* ===== SCROLLABLE BODY ===== */
        .scroll-body {
            position: fixed;
            top: 65px;
            left: 0;
            right: 0;
            bottom: 0;
            overflow-y: auto;
            padding: 40px 30px;
        }
        
        .scroll-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .scroll-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.02);
            border-radius: 10px;
        }
        
        .scroll-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            border-radius: 10px;
        }
        
        /* ===== DASHBOARD ===== */
        .dashboard-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .dashboard-title {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .dashboard-subtitle {
            font-size: 16px;
            color: #7a8a9a;
            margin-bottom: 40px;
        }
        
        .app-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }
        
        .app-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 35px 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.06), 0 2px 8px rgba(0,0,0,0.03);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .app-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(44,62,80,0.02), rgba(52,152,219,0.02));
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        
        .app-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 48px rgba(0,0,0,0.1), 0 4px 12px rgba(0,0,0,0.04);
        }
        
        .app-card:hover::before {
            opacity: 1;
        }
        
        .app-card .app-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .app-card .app-name {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .app-card .app-description {
            font-size: 14px;
            color: #7a8a9a;
            line-height: 1.6;
        }
        
        .app-card .app-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            box-shadow: 0 2px 8px rgba(46,204,113,0.2);
        }
        
        .app-card .app-status {
            margin-top: 12px;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .app-card .app-status.active {
            background: rgba(46,204,113,0.1);
            color: #27ae60;
        }
        
        .app-card .app-status.coming {
            background: rgba(251,191,36,0.1);
            color: #d97706;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .main-header {
                padding: 10px 15px;
                height: 55px;
            }
            .main-header .logo {
                font-size: 17px;
            }
            .main-header .logo .icon {
                font-size: 22px;
            }
            .scroll-body {
                top: 55px;
                padding: 20px 15px;
            }
            .app-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            .dashboard-title {
                font-size: 22px;
            }
            .app-card {
                padding: 25px 20px;
            }
            .app-card .app-icon {
                font-size: 36px;
            }
            .logout-btn {
                padding: 6px 14px;
                font-size: 12px;
            }
            .dashboard-container {
                max-width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .app-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-title {
                font-size: 18px;
            }
            .dashboard-subtitle {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <!-- ===== HEADER ===== -->
    <div class="main-header">
        <div class="logo">
            <span class="icon">🔧</span>
            <span>TASK TOOL</span>
        </div>
        <div class="header-actions">
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </div>
    
    <!-- ===== SCROLLABLE BODY ===== -->
    <div class="scroll-body">
        <div class="dashboard-container">
            <div class="dashboard-title">Welcome to TASK TOOL</div>
            <div class="dashboard-subtitle">Select an application to get started</div>
            
            <div class="app-grid">
                <!-- Serenum Card -->
                <div class="app-card" onclick="location.href='serenum.php'">
                    <div class="app-badge">Active</div>
                    <div class="app-icon">⚙️</div>
                    <div class="app-name">Serenum Configuration</div>
                    <div class="app-description">Master multitasking with advanced automation that executes multiple operations simultaneously.</div>
                    <span class="app-status active">● Available</span>
                </div>
                
                <!-- SceneIQ Card -->
                <div class="app-card" onclick="location.href='sceneiq.php'">
                    <div class="app-badge">Beta</div>
                    <div class="app-icon">🧠</div>
                    <div class="app-name">SceneIQ</div>
                    <div class="app-description">Effortlessly build and manage complex scenes with powerful multitasking AI that handles everything simultaneously.</div>
                    <span class="app-status coming">● Coming Soon</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function logout() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=logout'
            })
            .then(() => {
                window.location.href = 'login.php';
            })
            .catch(() => {
                window.location.href = 'login.php';
            });
        }
        
        // Check authentication periodically
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
    </script>
</body>
</html>