<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: /admin-secure');
    exit();
}

// Set page title
$page_title = "ALS Enrollment System - Analytics Dashboard";

// Handle school year filter
$selected_year = isset($_GET['sy']) ? $_GET['sy'] : null;
$current_month = date('n');
$current_year = date('Y');

if ($selected_year && $selected_year !== 'current') {
    $year_parts = explode('-', $selected_year);
    $start_year = (int)$year_parts[0];
    $school_year = $selected_year;
} else {
    // School year runs June-May
    // If current month is Jan-May, we're in SY that started previous year
    if ($current_month >= 1 && $current_month <= 5) {
        $start_year = $current_year - 1;
    } else {
        $start_year = $current_year;
    }
    $school_year = $start_year . '-' . ($start_year + 1);
}

// Initialize statistics array
$stats = [
    'total' => 0,
    'completed' => 0,
    'by_gender' => ['Male' => 0, 'Female' => 0],
    'by_barangay' => [],
    'by_age_group' => [
        '15-17' => 0,
        '18-24' => 0,
        '25-35' => 0,
        '36+' => 0
    ],
    'by_status' => ['enrolled' => 0, 'pending' => 0, 'active' => 0, 'inactive' => 0],
    'monthly' => array_fill(1, 12, 0),
    'completion_rate' => 0,
    'new_enrollees' => 0,
    'performance_metrics' => [
        'avg_score' => 0,
        'total_submissions' => 0,
        'graded_activities' => 0,
        'avg_activities_per_student' => 0
    ],
    'trends' => [
        'monthly_growth' => [],
        'year_over_year' => 0,
        'projected_enrollment' => 0
    ],
    'teacher_workload' => [],
    'strand_performance' => []
];

// Get current date for comparisons
$today = date('Y-m-d');
$last_month = date('Y-m-d', strtotime('-1 month'));
$last_year = date('Y-m-d', strtotime('-1 year'));

// Total students for selected school year
$query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN MONTH(enrollment_date) = MONTH(CURDATE()) AND YEAR(enrollment_date) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as new_enrollees,
            SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) as completed
          FROM students 
          WHERE YEAR(enrollment_date) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['total'] = (int)$row['total'];
    $stats['new_enrollees'] = (int)$row['new_enrollees'];
    $stats['completed'] = (int)$row['completed'];
    $stats['completion_rate'] = $stats['total'] > 0 ? round(($row['completed'] / $stats['total']) * 100, 1) : 0;
}
$stmt->close();

// By status
$query = "SELECT status, COUNT(*) as count 
          FROM students 
          WHERE YEAR(enrollment_date) = ? 
          GROUP BY status";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $status = $row['status'] ?: 'unknown';
    if (isset($stats['by_status'][$status])) {
        $stats['by_status'][$status] = (int)$row['count'];
    }
}
$stmt->close();

// By gender
$query = "SELECT 
            CASE 
                WHEN sex = 'male' THEN 'Male'
                WHEN sex = 'female' THEN 'Female'
                ELSE 'Other'
            END as gender,
            COUNT(*) as count 
          FROM students 
          WHERE YEAR(enrollment_date) = ? 
          GROUP BY gender";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['by_gender'][$row['gender']] = (int)$row['count'];
}
$stmt->close();

// By barangay with performance data from activity_submissions
$query = "SELECT 
            COALESCE(b.name, 'Unspecified') as barangay_name, 
            COUNT(s.id) as student_count,
            AVG(a_s.score) as avg_performance,
            COUNT(DISTINCT a_s.student_id) as students_with_scores
          FROM students s 
          LEFT JOIN barangays b ON s.current_barangay_id = b.barangay_id 
          LEFT JOIN activity_submissions a_s ON s.id = a_s.student_id
          WHERE YEAR(s.enrollment_date) = ? 
          GROUP BY b.barangay_id 
          ORDER BY student_count DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['by_barangay'][$row['barangay_name']] = [
        'count' => (int)$row['student_count'],
        'avg_performance' => $row['avg_performance'] ? round($row['avg_performance'], 1) : 0,
        'students_with_scores' => (int)$row['students_with_scores']
    ];
}
$stmt->close();

// By age group
$query = "SELECT 
            CASE 
                WHEN age BETWEEN 15 AND 17 THEN '15-17'
                WHEN age BETWEEN 18 AND 24 THEN '18-24'
                WHEN age BETWEEN 25 AND 35 THEN '25-35'
                WHEN age > 35 THEN '36+'
                ELSE 'Not Specified'
            END as age_group,
            COUNT(*) as count
          FROM students 
          WHERE YEAR(enrollment_date) = ? 
          GROUP BY age_group";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (isset($stats['by_age_group'][$row['age_group']])) {
        $stats['by_age_group'][$row['age_group']] = (int)$row['count'];
    }
}
$stmt->close();

// Monthly enrollment trend
$query = "SELECT 
            MONTH(enrollment_date) as month, 
            COUNT(*) as count 
          FROM students 
          WHERE YEAR(enrollment_date) = ? 
          GROUP BY MONTH(enrollment_date)
          ORDER BY month";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats['monthly'][(int)$row['month']] = (int)$row['count'];
}
$stmt->close();

// Calculate monthly growth rate
$previous_month = 0;
foreach ($stats['monthly'] as $month => $count) {
    if ($previous_month > 0 && $count > 0) {
        $growth = round((($count - $previous_month) / $previous_month) * 100, 1);
        $stats['trends']['monthly_growth'][$month] = $growth;
    } else {
        $stats['trends']['monthly_growth'][$month] = 0;
    }
    $previous_month = $count;
}

// Year over year comparison
$query = "SELECT 
            COUNT(*) as last_year_count
          FROM students 
          WHERE YEAR(enrollment_date) = ?";
$stmt = $conn->prepare($query);
$last_year_num = $start_year - 1;
$stmt->bind_param("i", $last_year_num);
$stmt->execute();
$result = $stmt->get_result();
$last_year_count = $result->fetch_assoc()['last_year_count'] ?? 0;
$stats['trends']['year_over_year'] = $last_year_count > 0 ? 
    round((($stats['total'] - $last_year_count) / $last_year_count) * 100, 1) : 0;
$stmt->close();

// Projected enrollment (simple linear projection)
$total_months = count(array_filter($stats['monthly']));
$avg_monthly = $total_months > 0 ? $stats['total'] / $total_months : 0;
$remaining_months = 12 - $total_months;
$stats['trends']['projected_enrollment'] = round($stats['total'] + ($avg_monthly * $remaining_months));

// Performance metrics from activity_submissions (existing table)
$query = "SELECT 
            COUNT(DISTINCT a_s.submission_id) as total_submissions,
            COUNT(DISTINCT CASE WHEN a_s.score IS NOT NULL THEN a_s.submission_id END) as graded_submissions,
            AVG(a_s.score) as avg_score,
            COUNT(DISTINCT a_s.student_id) as students_with_submissions
          FROM activity_submissions a_s
          JOIN students s ON a_s.student_id = s.id
          WHERE YEAR(s.enrollment_date) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $stats['performance_metrics']['total_submissions'] = (int)$row['total_submissions'];
    $stats['performance_metrics']['graded_activities'] = (int)$row['graded_submissions'];
    $stats['performance_metrics']['avg_score'] = $row['avg_score'] ? round($row['avg_score'], 1) : 0;
    $students_with_subs = $row['students_with_submissions'] ?: 1;
    $stats['performance_metrics']['avg_activities_per_student'] = 
        round($row['total_submissions'] / $students_with_subs, 1);
}
$stmt->close();

// Teacher workload distribution
$query = "SELECT 
            t.full_name as teacher_name,
            COUNT(DISTINCT s.id) as student_count,
            COUNT(DISTINCT a_s.submission_id) as submissions_managed,
            AVG(a_s.score) as avg_student_score
          FROM teachers t
          LEFT JOIN students s ON t.teacher_id = s.teacher_id AND YEAR(s.enrollment_date) = ?
          LEFT JOIN activity_submissions a_s ON s.id = a_s.student_id
          WHERE t.status = 'active'
          GROUP BY t.teacher_id
          ORDER BY student_count DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if ($row['student_count'] > 0) {
        $stats['teacher_workload'][] = [
            'name' => $row['teacher_name'],
            'students' => (int)$row['student_count'],
            'submissions' => (int)$row['submissions_managed'],
            'avg_score' => $row['avg_student_score'] ? round($row['avg_student_score'], 1) : 0
        ];
    }
}
$stmt->close();

// Strand performance from existing tables
$query = "SELECT 
            ls.strand_number,
            ls.title,
            COUNT(DISTINCT a.activity_id) as total_activities,
            COUNT(DISTINCT a_s.submission_id) as submissions,
            AVG(a_s.score) as avg_score,
            COUNT(DISTINCT CASE WHEN a_s.score >= 75 THEN a_s.student_id END) as passing_students,
            COUNT(DISTINCT a_s.student_id) as total_students
          FROM learning_strands ls
          LEFT JOIN modules m ON ls.strand_id = m.strand_id
          LEFT JOIN activities a ON m.module_id = a.module_id
          LEFT JOIN activity_submissions a_s ON a.activity_id = a_s.activity_id
          LEFT JOIN students s ON a_s.student_id = s.id AND YEAR(s.enrollment_date) = ?
          WHERE ls.status = 'active'
          GROUP BY ls.strand_id
          ORDER BY ls.strand_number";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $start_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $total_students = $row['total_students'] ?: 1;
    $stats['strand_performance'][] = [
        'strand' => $row['strand_number'] . ' - ' . $row['title'],
        'activities' => (int)$row['total_activities'],
        'submissions' => (int)$row['submissions'],
        'avg_score' => $row['avg_score'] ? round($row['avg_score'], 1) : 0,
        'passing_rate' => $total_students > 0 ? 
            round(($row['passing_students'] / $total_students) * 100, 1) : 0
    ];
}
$stmt->close();

// Get available school years
$query = "SELECT DISTINCT YEAR(enrollment_date) as year 
          FROM students 
          WHERE enrollment_date IS NOT NULL
          ORDER BY year DESC";
$result = $conn->query($query);
$school_years = ['current' => 'Current (' . $school_year . ')'];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $year = $row['year'];
        $school_years[$year . '-' . ($year + 1)] = $year . '-' . ($year + 1);
    }
}

// Smart Insight Helpers
$male   = $stats['by_gender']['Male'] ?? 0;
$female = $stats['by_gender']['Female'] ?? 0;
$gender_total = $male + $female;
if ($gender_total > 0) {
    $male_pct   = round(($male   / $gender_total) * 100);
    $female_pct = round(($female / $gender_total) * 100);
    $gender_diff = abs($male_pct - $female_pct);
    if ($gender_diff <= 5) {
        $gender_insight_icon  = 'fas fa-equals';
        $gender_insight_color = '#10b981';
        $gender_insight_text  = "Enrollment is nearly gender-balanced ({$male_pct}% male, {$female_pct}% female). This reflects healthy, inclusive outreach across the community.";
    } elseif ($female_pct > $male_pct) {
        $gender_insight_icon  = 'fas fa-female';
        $gender_insight_color = '#f472b6';
        $gender_insight_text  = "Females lead enrollment at {$female_pct}% vs {$male_pct}% for males — a {$gender_diff}% gap. Consider targeted outreach programs to increase male participation.";
    } else {
        $gender_insight_icon  = 'fas fa-male';
        $gender_insight_color = '#667eea';
        $gender_insight_text  = "Males account for {$male_pct}% of enrollees vs {$female_pct}% for females — a {$gender_diff}% gap. Consider strategies to improve female engagement and retention.";
    }
} else {
    $gender_insight_icon  = 'fas fa-info-circle';
    $gender_insight_color = '#888';
    $gender_insight_text  = "No gender data available for this school year.";
}

// Status insight
$enrolled = $stats['by_status']['enrolled'] ?? 0;
$pending  = $stats['by_status']['pending']  ?? 0;
$active   = $stats['by_status']['active']   ?? 0;
$inactive = $stats['by_status']['inactive'] ?? 0;
$status_total = $enrolled + $pending + $active + $inactive;
$pending_pct  = $status_total > 0 ? round(($pending  / $status_total) * 100) : 0;
$inactive_pct = $status_total > 0 ? round(($inactive / $status_total) * 100) : 0;
$active_pct   = $status_total > 0 ? round((($enrolled + $active) / $status_total) * 100) : 0;
if ($pending_pct > 20) {
    $status_insight_icon  = 'fas fa-exclamation-triangle';
    $status_insight_color = '#f59e0b';
    $status_insight_text  = "{$pending_pct}% of students are still pending — that's {$pending} learners awaiting processing. Prioritise completing their enrollment to avoid drop-offs.";
} elseif ($inactive_pct > 15) {
    $status_insight_icon  = 'fas fa-user-slash';
    $status_insight_color = '#ef4444';
    $status_insight_text  = "{$inactive_pct}% of students are inactive ({$inactive} learners). Conducting a re-engagement campaign could significantly recover these enrollees.";
} else {
    $status_insight_icon  = 'fas fa-check-circle';
    $status_insight_color = '#10b981';
    $status_insight_text  = "{$active_pct}% of students are enrolled or active — indicating strong program uptake. Keep momentum by maintaining regular follow-ups with pending cases.";
}

// Age group insight
$age_groups   = $stats['by_age_group'];
$dominant_age = array_search(max($age_groups), $age_groups);
$age_total    = array_sum($age_groups);
$dom_pct      = $age_total > 0 ? round(($age_groups[$dominant_age] / $age_total) * 100) : 0;
$youth_count  = ($age_groups['15-17'] ?? 0) + ($age_groups['18-24'] ?? 0);
$youth_pct    = $age_total > 0 ? round(($youth_count / $age_total) * 100) : 0;
$adult_pct    = 100 - $youth_pct;
if ($youth_pct >= 60) {
    $age_insight_icon  = 'fas fa-user-graduate';
    $age_insight_color = '#667eea';
    $age_insight_text  = "Youth (15–24) make up {$youth_pct}% of enrollment. The dominant group is {$dominant_age} at {$dom_pct}%. Focus learning materials on digital-native, youth-oriented approaches.";
} elseif ($adult_pct >= 60) {
    $age_insight_icon  = 'fas fa-briefcase';
    $age_insight_color = '#764ba2';
    $age_insight_text  = "Adult learners (25+) represent {$adult_pct}% of enrollment. Flexible scheduling and work-relevant modules will be key to keeping this majority engaged.";
} else {
    $age_insight_icon  = 'fas fa-users';
    $age_insight_color = '#3b82f6';
    $age_insight_text  = "Age groups are well-distributed. The largest group is {$dominant_age} at {$dom_pct}%. A blended curriculum catering to both youth and adult learners is recommended.";
}

// Monthly trend insight
$monthly_values    = array_filter($stats['monthly']);
$peak_month_num    = !empty($monthly_values) ? array_search(max($monthly_values), $stats['monthly']) : 1;
$month_names_full  = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$peak_month_name   = $month_names_full[$peak_month_num] ?? 'N/A';
$peak_count        = $stats['monthly'][$peak_month_num] ?? 0;
$non_zero_months   = count($monthly_values);
$avg_per_month     = $non_zero_months > 0 ? round(array_sum($monthly_values) / $non_zero_months) : 0;

// Find recent trend: compare last two non-zero months
$monthly_arr = array_filter($stats['monthly']);
$monthly_keys = array_keys($monthly_arr);
$recent_trend_text = '';
if (count($monthly_keys) >= 2) {
    $last_key  = end($monthly_keys);
    $prev_key  = prev($monthly_keys);
    $last_val  = $stats['monthly'][$last_key];
    $prev_val  = $stats['monthly'][$prev_key];
    if ($prev_val > 0) {
        $recent_change = round((($last_val - $prev_val) / $prev_val) * 100);
        if ($recent_change > 0) {
            $recent_trend_text = " The latest recorded month shows a <strong>+{$recent_change}% rise</strong> — enrollment is growing.";
        } elseif ($recent_change < 0) {
            $recent_trend_text = " The latest recorded month shows a <strong>{$recent_change}% dip</strong> — consider a mid-year enrollment drive.";
        } else {
            $recent_trend_text = " Enrollment held steady in the most recent months.";
        }
    }
}
$monthly_insight_icon  = 'fas fa-lightbulb';
$monthly_insight_color = '#f59e0b';
$monthly_insight_text  = "Peak enrollment occurred in <strong>{$peak_month_name}</strong> with {$peak_count} learners — {$non_zero_months} active months averaging {$avg_per_month} enrollees/month.{$recent_trend_text}";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .dashboard {
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Header Styles */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 32px;
        }

        .filter-section {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .school-year-select {
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
        }

        .school-year-select:hover {
            border-color: #667eea;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 14px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            color: #667eea;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: #333;
            margin-bottom: 10px;
            line-height: 1.2;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
        }

        .trend-up { color: #10b981; }
        .trend-down { color: #ef4444; }
        .trend-neutral { color: #f59e0b; }

        .stat-footer {
            font-size: 13px;
            color: #888;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f0f0f0;
        }

        /* Chart Grid */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .chart-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .chart-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-header h3 i {
            color: #667eea;
            font-size: 20px;
        }

        .chart-legend {
            display: flex;
            gap: 15px;
            font-size: 12px;
            font-weight: 500;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 3px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .chart-insight {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #f8f9ff;
            border-left: 4px solid #667eea;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 13px;
            color: #444;
            line-height: 1.6;
        }

        .chart-insight .insight-icon-sm {
            flex-shrink: 0;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            margin-top: 1px;
        }

        .chart-insight strong {
            color: #333;
        }

        /* Table Styles */
        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .table-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #f0f0f0;
            color: #666;
        }

        .badge-success {
            background: #10b98120;
            color: #10b981;
        }

        .badge-warning {
            background: #f59e0b20;
            color: #f59e0b;
        }

        .badge-danger {
            background: #ef444420;
            color: #ef4444;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 13px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #f0f0f0;
        }

        td {
            padding: 15px;
            font-size: 14px;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #f8f9ff;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #f0f0f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Insight Cards */
        .insight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .insight-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .insight-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .insight-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
            margin-bottom: 15px;
        }

        .insight-title {
            font-size: 16px;
            font-weight: 600;
            color: #888;
            margin-bottom: 10px;
        }

        .insight-value {
            font-size: 28px;
            font-weight: 800;
            color: #333;
            margin-bottom: 5px;
        }

        .insight-desc {
            font-size: 13px;
            color: #888;
            line-height: 1.5;
        }

        .insight-trend {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .stats-grid,
            .chart-grid,
            .insight-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                text-align: center;
            }

            .filter-section {
                width: 100%;
                justify-content: center;
            }
        }

        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-in {
            animation: slideIn 0.5s ease forwards;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #888;
        }

        .no-data i {
            font-size: 50px;
            margin-bottom: 20px;
            color: #ddd;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Header -->
        <div class="header animate-in">
            <h1>
                <i class="fas fa-chart-pie"></i>
                ALS Analytics Dashboard
            </h1>
            <div class="filter-section">
                <select class="school-year-select" id="schoolYear" onchange="changeSchoolYear(this.value)">
                    <?php foreach ($school_years as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>" 
                                <?php echo ($value === 'current' && !$selected_year) || $value === $school_year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="/AdminDashboard" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($stats['total'] == 0): ?>
            <div class="no-data animate-in">
                <i class="fas fa-database"></i>
                <h3>No Data Available</h3>
                <p>No enrollment records found for the selected school year (<?php echo htmlspecialchars($school_year); ?>).</p>
            </div>
        <?php else: ?>
            <!-- Key Stats -->
            <div class="stats-grid">
                <div class="stat-card animate-in" style="animation-delay: 0.1s">
                    <div class="stat-header">
                        <span class="stat-title">Total Enrollment</span>
                        <span class="stat-icon"><i class="fas fa-users"></i></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-trend">
                        <?php if ($stats['trends']['year_over_year'] > 0): ?>
                            <span class="trend-up"><i class="fas fa-arrow-up"></i> +<?php echo $stats['trends']['year_over_year']; ?>%</span>
                        <?php elseif ($stats['trends']['year_over_year'] < 0): ?>
                            <span class="trend-down"><i class="fas fa-arrow-down"></i> <?php echo $stats['trends']['year_over_year']; ?>%</span>
                        <?php else: ?>
                            <span class="trend-neutral"><i class="fas fa-minus"></i> 0%</span>
                        <?php endif; ?>
                        <span>vs last year</span>
                    </div>
                    <div class="stat-footer">
                        <i class="fas fa-user-plus"></i> <?php echo number_format($stats['new_enrollees']); ?> new this month
                    </div>
                </div>

                <div class="stat-card animate-in" style="animation-delay: 0.2s">
                    <div class="stat-header">
                        <span class="stat-title">Completion Rate</span>
                        <span class="stat-icon"><i class="fas fa-graduation-cap"></i></span>
                    </div>
                    <div class="stat-value"><?php echo $stats['completion_rate']; ?>%</div>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-check-circle"></i> <?php echo number_format($stats['completed']); ?> completed</span>
                    </div>
                    <div class="stat-footer">
                        <i class="fas fa-chart-line"></i> Target: 75%
                    </div>
                </div>

                <div class="stat-card animate-in" style="animation-delay: 0.3s">
                    <div class="stat-header">
                        <span class="stat-title">Average Score</span>
                        <span class="stat-icon"><i class="fas fa-star"></i></span>
                    </div>
                    <div class="stat-value"><?php echo $stats['performance_metrics']['avg_score']; ?>%</div>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-file"></i> <?php echo number_format($stats['performance_metrics']['total_submissions']); ?> submissions</span>
                    </div>
                    <div class="stat-footer">
                        <i class="fas fa-tasks"></i> <?php echo $stats['performance_metrics']['avg_activities_per_student']; ?> activities/student
                    </div>
                </div>

                <div class="stat-card animate-in" style="animation-delay: 0.4s">
                    <div class="stat-header">
                        <span class="stat-title">Projected Enrollment</span>
                        <span class="stat-icon"><i class="fas fa-chart-line"></i></span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['trends']['projected_enrollment']); ?></div>
                    <div class="stat-trend">
                        <span class="trend-up"><i class="fas fa-calendar"></i> End of SY</span>
                    </div>
                    <div class="stat-footer">
                        <i class="fas fa-clock"></i> Based on current trend
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="chart-grid">

                <!-- Gender Distribution -->
                <div class="chart-card animate-in" style="animation-delay: 0.1s">
                    <div class="chart-header">
                        <h3><i class="fas fa-venus-mars"></i> Gender Distribution</h3>
                        <div class="chart-legend">
                            <span class="legend-item"><span class="legend-color" style="background: #667eea"></span> Male</span>
                            <span class="legend-item"><span class="legend-color" style="background: #f472b6"></span> Female</span>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="genderChart"></canvas>
                    </div>
                    <div class="chart-insight" style="border-left-color: <?php echo $gender_insight_color; ?>;">
                        <div class="insight-icon-sm" style="background: <?php echo $gender_insight_color; ?>;">
                            <i class="<?php echo $gender_insight_icon; ?>"></i>
                        </div>
                        <div><?php echo $gender_insight_text; ?></div>
                    </div>
                </div>

                <!-- Student Status -->
                <div class="chart-card animate-in" style="animation-delay: 0.2s">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-pie"></i> Student Status</h3>
                        <div class="chart-legend">
                            <span class="legend-item"><span class="legend-color" style="background: #10b981"></span> Enrolled</span>
                            <span class="legend-item"><span class="legend-color" style="background: #f59e0b"></span> Pending</span>
                            <span class="legend-item"><span class="legend-color" style="background: #3b82f6"></span> Active</span>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                    <div class="chart-insight" style="border-left-color: <?php echo $status_insight_color; ?>;">
                        <div class="insight-icon-sm" style="background: <?php echo $status_insight_color; ?>;">
                            <i class="<?php echo $status_insight_icon; ?>"></i>
                        </div>
                        <div><?php echo $status_insight_text; ?></div>
                    </div>
                </div>

                <!-- Age Group Distribution -->
                <div class="chart-card animate-in" style="animation-delay: 0.3s">
                    <div class="chart-header">
                        <h3><i class="fas fa-users"></i> Age Group Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="ageChart"></canvas>
                    </div>
                    <div class="chart-insight" style="border-left-color: <?php echo $age_insight_color; ?>;">
                        <div class="insight-icon-sm" style="background: <?php echo $age_insight_color; ?>;">
                            <i class="<?php echo $age_insight_icon; ?>"></i>
                        </div>
                        <div><?php echo $age_insight_text; ?></div>
                    </div>
                </div>

                <!-- Monthly Enrollment Trend -->
                <div class="chart-card animate-in" style="animation-delay: 0.4s">
                    <div class="chart-header">
                        <h3><i class="fas fa-calendar-alt"></i> Monthly Enrollment Trend</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                    <div class="chart-insight" style="border-left-color: <?php echo $monthly_insight_color; ?>;">
                        <div class="insight-icon-sm" style="background: <?php echo $monthly_insight_color; ?>;">
                            <i class="<?php echo $monthly_insight_icon; ?>"></i>
                        </div>
                        <div><?php echo $monthly_insight_text; ?></div>
                    </div>
                </div>

            </div>

            <!-- Insights Row -->
            <div class="insight-grid">
                <div class="insight-card animate-in" style="animation-delay: 0.1s">
                    <div class="insight-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="insight-title">Top Performing Barangay</div>
                    <div class="insight-value">
                        <?php 
                        $top_barangay = !empty($stats['by_barangay']) ? array_key_first($stats['by_barangay']) : 'N/A';
                        echo htmlspecialchars($top_barangay);
                        ?>
                    </div>
                    <div class="insight-desc">
                        <?php if (!empty($stats['by_barangay'][$top_barangay])): ?>
                            <?php echo $stats['by_barangay'][$top_barangay]['count']; ?> students | 
                            Avg Score: <?php echo $stats['by_barangay'][$top_barangay]['avg_performance']; ?>%
                        <?php endif; ?>
                    </div>
                    <div class="insight-trend">
                        <i class="fas fa-medal" style="color: #fbbf24;"></i>
                        Leading in enrollment and performance
                    </div>
                </div>

                <div class="insight-card animate-in" style="animation-delay: 0.2s">
                    <div class="insight-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="insight-title">Peak Enrollment Month</div>
                    <div class="insight-value">
                        <?php 
                        $month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        echo $month_names[$peak_month_num - 1] ?? 'N/A';
                        ?>
                    </div>
                    <div class="insight-desc">
                        <?php echo number_format(max($stats['monthly'])); ?> students enrolled
                    </div>
                    <div class="insight-trend">
                        <i class="fas fa-calendar-check" style="color: #10b981;"></i>
                        Best time for enrollment campaigns
                    </div>
                </div>

                <div class="insight-card animate-in" style="animation-delay: 0.3s">
                    <div class="insight-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="insight-title">Teacher Workload</div>
                    <div class="insight-value">
                        <?php 
                        $avg_workload = !empty($stats['teacher_workload']) ? 
                            round(array_sum(array_column($stats['teacher_workload'], 'students')) / count($stats['teacher_workload'])) : 0;
                        echo $avg_workload;
                        ?>
                    </div>
                    <div class="insight-desc">Average students per teacher</div>
                    <div class="insight-trend">
                        <i class="fas fa-users" style="color: #8b5cf6;"></i>
                        <?php echo count($stats['teacher_workload']); ?> active teachers
                    </div>
                </div>
            </div>

            <!-- Teacher Workload Table -->
            <?php if (!empty($stats['teacher_workload'])): ?>
            <div class="table-card animate-in" style="animation-delay: 0.2s">
                <div class="table-header">
                    <h3><i class="fas fa-chalkboard-teacher"></i> Teacher Workload Distribution</h3>
                    <span class="badge"><?php echo count($stats['teacher_workload']); ?> Active Teachers</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Teacher Name</th>
                                <th>Students Assigned</th>
                                <th>Submissions Managed</th>
                                <th>Avg Student Score</th>
                                <th>Workload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['teacher_workload'] as $teacher): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($teacher['name']); ?></strong></td>
                                <td><?php echo $teacher['students']; ?></td>
                                <td><?php echo number_format($teacher['submissions']); ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $teacher['avg_score'] >= 75 ? 'badge-success' : 
                                            ($teacher['avg_score'] >= 60 ? 'badge-warning' : 'badge-danger'); 
                                    ?>">
                                        <?php echo $teacher['avg_score']; ?>%
                                    </span>
                                </td>
                                <td style="width: 200px;">
                                    <div class="progress-bar">
                                        <?php 
                                        $workload_percent = min(100, round(($teacher['students'] / 30) * 100));
                                        ?>
                                        <div class="progress-fill" style="width: <?php echo $workload_percent; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Strand Performance Table -->
            <?php if (!empty($stats['strand_performance'])): ?>
            <div class="table-card animate-in" style="animation-delay: 0.3s">
                <div class="table-header">
                    <h3><i class="fas fa-book-open"></i> Learning Strand Performance</h3>
                    <span class="badge"><?php echo count($stats['strand_performance']); ?> Active Strands</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Learning Strand</th>
                                <th>Activities</th>
                                <th>Submissions</th>
                                <th>Avg Score</th>
                                <th>Passing Rate</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['strand_performance'] as $strand): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($strand['strand']); ?></strong></td>
                                <td><?php echo $strand['activities']; ?></td>
                                <td><?php echo number_format($strand['submissions']); ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $strand['avg_score'] >= 75 ? 'badge-success' : 
                                            ($strand['avg_score'] >= 60 ? 'badge-warning' : 'badge-danger'); 
                                    ?>">
                                        <?php echo $strand['avg_score']; ?>%
                                    </span>
                                </td>
                                <td><?php echo $strand['passing_rate']; ?>%</td>
                                <td style="width: 150px;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $strand['passing_rate']; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Barangay Performance Table -->
            <?php if (!empty($stats['by_barangay'])): ?>
            <div class="table-card animate-in" style="animation-delay: 0.4s">
                <div class="table-header">
                    <h3><i class="fas fa-map-marker-alt"></i> Barangay Performance</h3>
                    <span class="badge"><?php echo count($stats['by_barangay']); ?> Barangays</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Barangay</th>
                                <th>Students</th>
                                <th>% of Total</th>
                                <th>Avg Performance</th>
                                <th>Students with Scores</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_students = $stats['total'];
                            foreach (array_slice($stats['by_barangay'], 0, 15) as $barangay => $data): 
                                $percentage = $total_students > 0 ? round(($data['count'] / $total_students) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($barangay); ?></strong></td>
                                <td><?php echo $data['count']; ?></td>
                                <td><?php echo $percentage; ?>%</td>
                                <td>
                                    <span class="badge <?php 
                                        echo $data['avg_performance'] >= 75 ? 'badge-success' : 
                                            ($data['avg_performance'] >= 60 ? 'badge-warning' : 'badge-danger'); 
                                    ?>">
                                        <?php echo $data['avg_performance']; ?>%
                                    </span>
                                </td>
                                <td><?php echo $data['students_with_scores']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // School year filter
        function changeSchoolYear(value) {
            if (value === 'current') {
                window.location.href = '/AdminSummary';
            } else {
                window.location.href = '/AdminSummary?sy=' + encodeURIComponent(value);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            Chart.register(ChartDataLabels);

            // Color palette
            const colors = {
                primary: '#667eea',
                secondary: '#764ba2',
                success: '#10b981',
                warning: '#f59e0b',
                danger: '#ef4444',
                info: '#3b82f6',
                pink: '#f472b6'
            };

            <?php if ($stats['total'] > 0): ?>
            // Gender Chart
            const genderCtx = document.getElementById('genderChart').getContext('2d');
            new Chart(genderCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Male', 'Female'],
                    datasets: [{
                        data: [
                            <?php echo $stats['by_gender']['Male']; ?>,
                            <?php echo $stats['by_gender']['Female']; ?>
                        ],
                        backgroundColor: [colors.primary, colors.pink],
                        borderColor: 'white',
                        borderWidth: 3,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((context.parsed / total) * 100);
                                    return `${context.label}: ${context.parsed.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        },
                        datalabels: {
                            color: '#fff',
                            font: { weight: 'bold', size: 12 },
                            formatter: (value, ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                return Math.round((value / total) * 100) + '%';
                            }
                        }
                    }
                }
            });

            // Status Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'pie',
                data: {
                    labels: ['Enrolled', 'Pending', 'Active', 'Inactive'],
                    datasets: [{
                        data: [
                            <?php echo $stats['by_status']['enrolled']; ?>,
                            <?php echo $stats['by_status']['pending']; ?>,
                            <?php echo $stats['by_status']['active']; ?>,
                            <?php echo $stats['by_status']['inactive']; ?>
                        ],
                        backgroundColor: [colors.success, colors.warning, colors.info, colors.danger],
                        borderColor: 'white',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((context.parsed / total) * 100);
                                    return `${context.label}: ${context.parsed.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        },
                        datalabels: {
                            color: '#fff',
                            font: { weight: 'bold', size: 11 },
                            formatter: (value, ctx) => {
                                if (value === 0) return '';
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                return Math.round((value / total) * 100) + '%';
                            }
                        }
                    }
                }
            });

            // Age Group Chart
            const ageCtx = document.getElementById('ageChart').getContext('2d');
            new Chart(ageCtx, {
                type: 'bar',
                data: {
                    labels: ['15-17', '18-24', '25-35', '36+'],
                    datasets: [{
                        label: 'Number of Students',
                        data: [
                            <?php echo $stats['by_age_group']['15-17']; ?>,
                            <?php echo $stats['by_age_group']['18-24']; ?>,
                            <?php echo $stats['by_age_group']['25-35']; ?>,
                            <?php echo $stats['by_age_group']['36+']; ?>
                        ],
                        backgroundColor: [
                            colors.primary,
                            colors.secondary,
                            colors.info,
                            colors.pink
                        ],
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Students: ${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        },
                        datalabels: {
                            anchor: 'end',
                            align: 'end',
                            color: '#555',
                            font: { weight: 'bold', size: 11 },
                            formatter: (value) => value > 0 ? value.toLocaleString() : ''
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { drawBorder: false },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });

            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            const gradient = monthlyCtx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(102, 126, 234, 0.3)');
            gradient.addColorStop(1, 'rgba(102, 126, 234, 0)');

            new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Enrollments',
                        data: <?php echo json_encode(array_values($stats['monthly'])); ?>,
                        borderColor: colors.primary,
                        backgroundColor: gradient,
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: colors.primary,
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return `Enrollments: ${context.parsed.y.toLocaleString()}`;
                                }
                            }
                        },
                        datalabels: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { drawBorder: false, color: 'rgba(0, 0, 0, 0.05)' },
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>