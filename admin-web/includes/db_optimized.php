<?php
/**
 * Central database optimization - SINGLE SOURCE OF TRUTH for all counts
 */

// Start timing
$GLOBALS['db_queries'] = 0;
$GLOBALS['db_time'] = 0;

/**
 * Get ALL counts in ONE query (this is the KEY optimization)
 */
function getCachedCounts($conn) {
    $cache_key = 'all_prereg_counts';
    $cache_time = 30; // 30 seconds cache
    
    // Check session cache
    if (isset($_SESSION[$cache_key]) && time() - $_SESSION[$cache_key]['time'] < $cache_time) {
        return $_SESSION[$cache_key]['data'];
    }
    
    $start = microtime(true);
    
    // SINGLE QUERY to get all counts at once
    $result = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN DATE(submitted_at) = CURDATE() THEN 1 ELSE 0 END) as today
        FROM preregistrations
    ");
    
    $GLOBALS['db_queries']++;
    $GLOBALS['db_time'] += (microtime(true) - $start);
    
    $counts = $result ? $result->fetch_assoc() : [
        'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'today' => 0
    ];
    
    // Cache in session
    $_SESSION[$cache_key] = [
        'data' => $counts,
        'time' => time()
    ];
    
    return $counts;
}

/**
 * Get unread notifications count (DISTINCT preregistrations)
 */
function getUnreadCount($conn) {
    $cache_key = 'unread_count';
    $cache_time = 30;
    
    if (isset($_SESSION[$cache_key]) && time() - $_SESSION[$cache_key]['time'] < $cache_time) {
        return $_SESSION[$cache_key]['data'];
    }
    
    $start = microtime(true);
    
    $result = $conn->query("
        SELECT COUNT(DISTINCT preregistration_id) as count
        FROM preregistration_notifications
        WHERE is_read = 0
    ");
    
    $GLOBALS['db_queries']++;
    $GLOBALS['db_time'] += (microtime(true) - $start);
    
    $count = $result ? (int)$result->fetch_assoc()['count'] : 0;
    
    $_SESSION[$cache_key] = [
        'data' => $count,
        'time' => time()
    ];
    
    return $count;
}

/**
 * Debug function to show query performance (add at bottom of pages)
 */
function showQueryStats() {
    if (isset($_SESSION['admin_id'])) {
        return "<!-- Queries: {$GLOBALS['db_queries']} | Time: " . round($GLOBALS['db_time'] * 1000, 2) . "ms -->";
    }
    return '';
}
?>