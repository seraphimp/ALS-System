<?php
// Simple endpoint for polling fallback
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

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

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Define functions if they don't exist
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

try {
    $counts = get_all_preregistration_counts($conn);
    $unread = get_unread_notifications_count($conn);
    
    echo json_encode([
        'success' => true,
        'counts' => [
            'pending' => $counts['pending'],
            'approved' => $counts['approved'],
            'rejected' => $counts['rejected'],
            'all' => $counts['total'],
            'unread' => $unread,
            'today' => $counts['today']
        ],
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'counts' => [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'all' => 0,
            'unread' => 0,
            'today' => 0
        ]
    ]);
}
?>