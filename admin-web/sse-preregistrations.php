<?php
/**
 * Optimized Server-Sent Events (SSE) endpoint
 */

// Prevent any output buffering
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
while (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Define missing functions if they don't exist
if (!function_exists('get_all_preregistration_counts')) {
    function get_all_preregistration_counts($conn) {
        $result = $conn->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN DATE(submitted_at) = CURDATE() THEN 1 ELSE 0 END) as today
            FROM preregistrations
        ");
        
        if ($result && $result->num_rows > 0) {
            $counts = $result->fetch_assoc();
            $result->free_result();
            return [
                'total' => (int)$counts['total'],
                'pending' => (int)$counts['pending'],
                'approved' => (int)$counts['approved'],
                'rejected' => (int)$counts['rejected'],
                'today' => (int)$counts['today']
            ];
        }
        
        return [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'today' => 0
        ];
    }
}

if (!function_exists('get_unread_notifications_count')) {
    function get_unread_notifications_count($conn) {
        $result = $conn->query("
            SELECT COUNT(DISTINCT preregistration_id) as count
            FROM preregistration_notifications 
            WHERE is_read = 0
        ");
        
        if ($result && $result->num_rows > 0) {
            $count = $result->fetch_assoc();
            $result->free_result();
            return (int)$count['count'];
        }
        
        return 0;
    }
}

// Start session
if (!function_exists('secure_session_start')) {
    function secure_session_start() {
        if (session_status() === PHP_SESSION_NONE) {
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
                ini_set('session.cookie_secure', 1);
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_samesite', 'Strict');
            session_start();
        }
    }
}

secure_session_start();

// Verify admin login
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
    } else {
        header('Content-Type: text/event-stream');
        echo "event: error\n";
        echo "data: " . json_encode(['message' => 'Unauthorized', 'redirect' => 'index.php']) . "\n\n";
    }
    flush();
    exit;
}

function getSnapshot($conn) {
    try {
        // Get all counts
        $counts = get_all_preregistration_counts($conn);
        
        // Get unread count
        $unread = get_unread_notifications_count($conn);
        
        // Get recent pending entries (limited to 5)
        $recent = [];
        $result = $conn->query("
            SELECT p.preregistration_id, p.tracking_code, p.first_name, p.last_name, 
                   p.email, p.contact_number, p.submitted_at, p.status,
                   b.name as barangay_name, p.current_custom_barangay
            FROM preregistrations p
            LEFT JOIN barangays b ON p.current_barangay_id = b.barangay_id
            WHERE p.status = 'pending'
            ORDER BY p.submitted_at DESC
            LIMIT 5
        ");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $recent[] = $row;
            }
            $result->free_result();
        }
        
        return [
            'counts' => [
                'pending' => $counts['pending'],
                'approved' => $counts['approved'],
                'rejected' => $counts['rejected'],
                'all' => $counts['total'],
                'unread' => $unread,
                'today' => $counts['today']
            ],
            'recent' => $recent,
            'timestamp' => time()
        ];
    } catch (Exception $e) {
        error_log("SSE GetSnapshot Error: " . $e->getMessage());
        return [
            'counts' => [
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'all' => 0,
                'unread' => 0,
                'today' => 0
            ],
            'recent' => [],
            'timestamp' => time(),
            'error' => true
        ];
    }
}

// Check if this is a JSON polling request
if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    header('Content-Type: application/json');
    $snapshot = getSnapshot($conn);
    echo json_encode($snapshot);
    exit;
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

set_time_limit(0);
ignore_user_abort(true);

$admin_id = intval($_SESSION['admin_id']);
$last_check = time();
$last_data = null;
$heartbeat_count = 0;

function sendEvent($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// Send initial data
try {
    $last_data = getSnapshot($conn);
    sendEvent('init', $last_data);
} catch (Exception $e) {
    error_log("SSE Init Error: " . $e->getMessage());
    sendEvent('error', ['message' => 'Failed to initialize', 'error' => $e->getMessage()]);
    exit;
}

// Main loop - 30 seconds max
$start_time = time();
$max_runtime = 30;

while (time() - $start_time < $max_runtime) {
    if (connection_aborted()) break;
    
    // Check database connection
    if (!@$conn->ping()) {
        @$conn->close();
        try {
            require_once __DIR__ . '/includes/db.php';
        } catch (Exception $e) {
            error_log("SSE DB Reconnect Error: " . $e->getMessage());
            sendEvent('error', ['message' => 'Database connection lost']);
            break;
        }
    }
    
    try {
        $current_data = getSnapshot($conn);
        
        // Check for changes
        if ($last_data && $current_data['timestamp'] !== $last_data['timestamp']) {
            $changes = [];
            foreach ($current_data['counts'] as $key => $value) {
                if (isset($last_data['counts'][$key]) && $last_data['counts'][$key] != $value) {
                    $changes[$key] = [
                        'old' => $last_data['counts'][$key],
                        'new' => $value
                    ];
                }
            }
            
            $new_rows = [];
            if (!empty($current_data['recent']) && !empty($last_data['recent'])) {
                $last_ids = array_column($last_data['recent'], 'preregistration_id');
                foreach ($current_data['recent'] as $row) {
                    if (!in_array($row['preregistration_id'], $last_ids)) {
                        $new_rows[] = $row;
                    }
                }
            } elseif (!empty($current_data['recent'])) {
                $new_rows = $current_data['recent'];
            }
            
            if (!empty($changes) || !empty($new_rows)) {
                sendEvent('update', [
                    'counts' => $current_data['counts'],
                    'changes' => $changes,
                    'new_rows' => $new_rows,
                    'timestamp' => $current_data['timestamp']
                ]);
            }
            
            $last_data = $current_data;
        }
        
        // Heartbeat every 15 seconds
        if (time() - $last_check > 15) {
            echo ": heartbeat\n\n";
            flush();
            $last_check = time();
            $heartbeat_count++;
        }
        
    } catch (Exception $e) {
        error_log("SSE Loop Error: " . $e->getMessage());
        sendEvent('warning', ['message' => 'Error fetching data', 'error' => $e->getMessage()]);
    }
    
    sleep(3); // Check every 3 seconds
}

// Send reconnect signal
sendEvent('reconnect', ['message' => 'Reconnecting...', 'timestamp' => time()]);
?>