<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_staff_logged_in()) {
    header('Location: login.php');
    exit();
}

$page_title = "ALS Enrollment System - Staff Dashboard";
$staff_name = $_SESSION['staff_full_name'];
$staff_role = $_SESSION['staff_role'];

// Get statistics
$today = date('Y-m-d');
$total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$today_enrollments = $conn->query("SELECT COUNT(*) as count FROM students WHERE DATE(enrollment_date) = '$today'")->fetch_assoc()['count'];
$my_enrollments = $conn->query("SELECT COUNT(*) as count FROM students WHERE processed_by = {$_SESSION['staff_id']}")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0056b3;
            --secondary-color: #ffcc00;
            --light-blue: #e6f0ff;
            --dark-blue: #003d82;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --border-radius: 0.375rem;
            --box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, var(--light-blue) 0%, #ffffff 100%);
            color: #333;
            line-height: 1.6;
            background-attachment: fixed;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
        }
        
        .logo-text {
            color: var(--primary-color);
            display: flex;
            flex-direction: column;
            margin-left: 1rem;
        }
        
        .logo {
            width: 60px;
            height: 60px;
        }
        
        .logo img {
            width: 100%;
            height: auto;
        }
        
        .logo-title h1 {
            font-size: 1.5rem;
            margin: 0;
            font-weight: 700;
        }
        
        .logo-title p {
            margin: 0;
            font-size: 0.9rem;
            color: #666;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-welcome {
            text-align: right;
        }
        
        .user-name {
            font-weight: 500;
            color: var(--primary-color);
        }
        
        .user-role {
            font-size: 0.9rem;
            color: #666;
        }
        
        .logout-btn {
            padding: 0.5rem 1rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s;
        }
        
        .logout-btn:hover {
            background-color: var(--dark-blue);
        }
        
        .dashboard-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .dashboard-content {
                grid-template-columns: 2fr 1fr;
            }
        }
        
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            border-top: 5px solid var(--primary-color);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--dark-blue) 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.125);
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 500;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: var(--dark-color);
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }
        
        .action-btn i {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .action-btn span {
            font-weight: 500;
            text-align: center;
        }
        
        .recent-activity {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .recent-activity li {
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .recent-activity li:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--light-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: 0.8rem;
            color: #666;
        }
        
        .footer {
            text-align: center;
            padding: 1.5rem;
            margin-top: 2rem;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid #eee;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-container">
            <div class="logo-container">
                <div class="logo">
                    <img src="../../logo/als-logo-removebg-preview.png" alt="ALS Logo">
                </div>
                <div class="logo-text">
                    <div class="logo-title">
                        <h1>Alternative Learning System</h1>
                        <p>La Carlota City Division - Staff Portal</p>
                    </div>
                </div>
            </div>
            
            <div class="user-info">
                <div class="user-welcome">
                    <div class="user-name">Welcome, <?= htmlspecialchars($staff_name) ?></div>
                    <div class="user-role"><?= ucfirst($staff_role) ?></div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        
        <div class="dashboard-content">
            <div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($total_students) ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($today_enrollments) ?></div>
                        <div class="stat-label">Today's Enrollments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($my_enrollments) ?></div>
                        <div class="stat-label">My Enrollments</div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2>Quick Actions</h2>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="enroll.php" class="action-btn">
                                <i class="fas fa-user-plus"></i>
                                <span>New Enrollment</span>
                            </a>
                            <a href="students.php" class="action-btn">
                                <i class="fas fa-users"></i>
                                <span>View Students</span>
                            </a>
                            <a href="reports.php" class="action-btn">
                                <i class="fas fa-chart-bar"></i>
                                <span>Reports</span>
                            </a>
                            <a href="profile.php" class="action-btn">
                                <i class="fas fa-user-cog"></i>
                                <span>My Profile</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div>
                <div class="card">
                    <div class="card-header">
                        <h2>Recent Activity</h2>
                    </div>
                    <div class="card-body">
                        <ul class="recent-activity">
                            <?php
                            // Get recent enrollments by this staff
                            $query = "SELECT s.student_id, s.first_name, s.last_name, s.enrollment_date 
                                     FROM students s 
                                     WHERE s.processed_by = ? 
                                     ORDER BY s.enrollment_date DESC 
                                     LIMIT 5";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $_SESSION['staff_id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0):
                                while ($row = $result->fetch_assoc()):
                            ?>
                            <li>
                                <div class="activity-icon">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        Enrolled <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>
                                    </div>
                                    <div class="activity-time">
                                        <?= date('M d, Y', strtotime($row['enrollment_date'])) ?>
                                    </div>
                                </div>
                            </li>
                            <?php
                                endwhile;
                            else:
                            ?>
                            <div class="empty-state">
                                <i class="fas fa-history"></i>
                                <p>No recent activity</p>
                            </div>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                
                <div class="card" style="margin-top: 1.5rem;">
                    <div class="card-header">
                        <h2>System Information</h2>
                    </div>
                    <div class="card-body">
                        <p><strong>System:</strong> ALS Enrollment System</p>
                        <p><strong>Version:</strong> 1.0.0</p>
                        <p><strong>Last Updated:</strong> <?= date('F d, Y') ?></p>
                        <p><strong>Support:</strong> Contact System Administrator</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>Alternative Learning System Enrollment System &copy; <?php echo date('Y'); ?> - La Carlota City Division</p>
            <p>Staff Portal v1.0</p>
        </div>
    </div>
</body>
</html>