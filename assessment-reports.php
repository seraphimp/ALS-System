<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// assessment-reports.php - Complete Assessment Report with Pre/Post Test Comparison
require_once __DIR__ . '/als/admin-web/includes/db.php';
require_once __DIR__ . '/als/admin-web/includes/functions.php';

// Use the same authentication as your other admin pages
secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: /admin-secure');
    exit();
}

// Fetch ALL students
$students_query = "SELECT id, student_id, first_name, last_name, lrn FROM students WHERE status = 'active' OR status = 'enrolled'";
$students_result = $conn->query($students_query);
$students = $students_result->fetch_all(MYSQLI_ASSOC);

if (empty($students)) {
    die("No students found");
}

// Get student IDs for query
$student_ids = array_column($students, 'id');
$student_ids_str = implode(',', $student_ids);

// Fetch all assessment results for these students
$assessments_query = "
    SELECT ar.*, a.assessment_type, a.title, a.max_score as assessment_max_score
    FROM assessment_results ar
    LEFT JOIN assessments a ON ar.assessment_id = a.assessment_id
    WHERE ar.student_id IN ($student_ids_str) 
        AND ar.status = 'completed'
        AND a.assessment_type IN ('pre_test', 'post_test')
    ORDER BY a.assessment_type, ar.completed_at DESC
";
$assessments = $conn->query($assessments_query);

// Organize results by student and assessment type
$student_results = [];
foreach ($students as $student) {
    $student_results[$student['id']] = [
        'student' => $student,
        'pre_test' => null,
        'post_test' => null
    ];
}

while ($assessment = $assessments->fetch_assoc()) {
    $student_id = $assessment['student_id'];
    if ($student_id && isset($student_results[$student_id])) {
        if ($assessment['assessment_type'] == 'pre_test') {
            $student_results[$student_id]['pre_test'] = $assessment;
        } elseif ($assessment['assessment_type'] == 'post_test') {
            $student_results[$student_id]['post_test'] = $assessment;
        }
    }
}

// Function to calculate analytics
function calculateClassAnalytics($student_results) {
    $analytics = [
        'total_students' => count($student_results),
        'pre_test' => ['completed' => 0, 'total_score' => 0, 'max_score' => 0, 'scores' => []],
        'post_test' => ['completed' => 0, 'total_score' => 0, 'max_score' => 0, 'scores' => []],
        'improvement' => ['improved' => 0, 'declined' => 0, 'no_change' => 0, 'improvements' => []]
    ];
    
    foreach ($student_results as $data) {
        if ($data['pre_test'] && $data['pre_test']['percentage'] !== null) {
            $analytics['pre_test']['completed']++;
            $analytics['pre_test']['total_score'] += floatval($data['pre_test']['total_score']);
            $analytics['pre_test']['max_score'] += floatval($data['pre_test']['max_possible_score']);
            $analytics['pre_test']['scores'][] = floatval($data['pre_test']['percentage']);
        }
        
        if ($data['post_test'] && $data['post_test']['percentage'] !== null) {
            $analytics['post_test']['completed']++;
            $analytics['post_test']['total_score'] += floatval($data['post_test']['total_score']);
            $analytics['post_test']['max_score'] += floatval($data['post_test']['max_possible_score']);
            $analytics['post_test']['scores'][] = floatval($data['post_test']['percentage']);
            
            if ($data['pre_test'] && $data['pre_test']['percentage'] !== null) {
                $improvement = floatval($data['post_test']['percentage']) - floatval($data['pre_test']['percentage']);
                $analytics['improvement']['improvements'][] = $improvement;
                if ($improvement > 0) $analytics['improvement']['improved']++;
                elseif ($improvement < 0) $analytics['improvement']['declined']++;
                else $analytics['improvement']['no_change']++;
            }
        }
    }
    
    // Calculate averages with safety checks
    if ($analytics['pre_test']['completed'] > 0 && $analytics['pre_test']['max_score'] > 0) {
        $analytics['pre_test']['avg_percentage'] = ($analytics['pre_test']['total_score'] / $analytics['pre_test']['max_score']) * 100;
        $analytics['pre_test']['avg_percentage'] = round($analytics['pre_test']['avg_percentage'], 1);
    } else {
        $analytics['pre_test']['avg_percentage'] = 0;
    }
    
    if ($analytics['post_test']['completed'] > 0 && $analytics['post_test']['max_score'] > 0) {
        $analytics['post_test']['avg_percentage'] = ($analytics['post_test']['total_score'] / $analytics['post_test']['max_score']) * 100;
        $analytics['post_test']['avg_percentage'] = round($analytics['post_test']['avg_percentage'], 1);
    } else {
        $analytics['post_test']['avg_percentage'] = 0;
    }
    
    if (!empty($analytics['improvement']['improvements'])) {
        $analytics['improvement']['avg_improvement'] = round(array_sum($analytics['improvement']['improvements']) / count($analytics['improvement']['improvements']), 1);
    } else {
        $analytics['improvement']['avg_improvement'] = 0;
    }
    
    return $analytics;
}

$analytics = calculateClassAnalytics($student_results);

// Get performance distribution
function getPerformanceDistribution($student_results, $type) {
    $distribution = ['Excellent (90-100%)' => 0, 'Proficient (75-89%)' => 0, 'Developing (50-74%)' => 0, 'Needs Improvement (0-49%)' => 0];
    
    foreach ($student_results as $data) {
        $test = $type == 'pre' ? $data['pre_test'] : $data['post_test'];
        if ($test && $test['percentage'] !== null) {
            $percentage = floatval($test['percentage']);
            if ($percentage >= 90) $distribution['Excellent (90-100%)']++;
            elseif ($percentage >= 75) $distribution['Proficient (75-89%)']++;
            elseif ($percentage >= 50) $distribution['Developing (50-74%)']++;
            else $distribution['Needs Improvement (0-49%)']++;
        }
    }
    
    return $distribution;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Analytics Report - Student Performance</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            padding: 30px 20px;
            color: #1a1a2e;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 24px;
            padding: 30px 40px;
            margin-bottom: 30px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .report-header h1 { font-size: 28px; margin-bottom: 8px; }
        .report-header p { opacity: 0.9; font-size: 14px; }
        .stats-badge {
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 12px;
            text-align: center;
        }
        .stats-badge .number { font-size: 24px; font-weight: bold; }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        .analytics-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .analytics-card h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #4b5563;
        }
        .big-number {
            font-size: 48px;
            font-weight: 800;
            margin: 10px 0;
        }
        .big-number.pre { color: #f59e0b; }
        .big-number.post { color: #10b981; }
        
        .students-table {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        .students-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .students-table th, .students-table td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .students-table th {
            background: #f9fafb;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        .students-table tr:hover { background: #f9fafb; }
        .score-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }
        .score-high { background: #d1fae5; color: #065f46; }
        .score-medium { background: #fed7aa; color: #92400e; }
        .score-low { background: #fee2e2; color: #991b1b; }
        .improvement-up { color: #10b981; font-weight: 600; }
        .improvement-down { color: #ef4444; font-weight: 600; }
        
        .view-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        .view-link:hover { text-decoration: underline; }
        
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none; }
            .report-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="report-header">
        <div>
            <h1><i class="fas fa-chart-line"></i> Assessment Analytics Report</h1>
            <p>Student Performance Overview | Total Students: <?= $analytics['total_students'] ?></p>
        </div>
        <div class="stats-badge no-print">
            <div class="number"><i class="fas fa-calendar-alt"></i> <?= date('F d, Y') ?></div>
            <div>Report Date</div>
        </div>
    </div>
    
    <div class="analytics-grid">
        <div class="analytics-card">
            <h3><i class="fas fa-clipboard-list"></i> Pre-Test Average</h3>
            <div class="big-number pre"><?= $analytics['pre_test']['avg_percentage'] ?>%</div>
            <p><?= $analytics['pre_test']['completed'] ?>/<?= $analytics['total_students'] ?> students completed</p>
            <div class="progress-bar" style="background:#e5e7eb;border-radius:20px;height:8px;margin-top:15px;">
                <div style="width: <?= $analytics['pre_test']['avg_percentage'] ?>%; background:#f59e0b; height:100%; border-radius:20px;"></div>
            </div>
        </div>
        
        <div class="analytics-card">
            <h3><i class="fas fa-graduation-cap"></i> Post-Test Average</h3>
            <div class="big-number post"><?= $analytics['post_test']['avg_percentage'] ?>%</div>
            <p><?= $analytics['post_test']['completed'] ?>/<?= $analytics['total_students'] ?> students completed</p>
            <div class="progress-bar" style="background:#e5e7eb;border-radius:20px;height:8px;margin-top:15px;">
                <div style="width: <?= $analytics['post_test']['avg_percentage'] ?>%; background:#10b981; height:100%; border-radius:20px;"></div>
            </div>
        </div>
        
        <div class="analytics-card">
            <h3><i class="fas fa-chart-line"></i> Overall Improvement</h3>
            <div class="big-number <?= $analytics['improvement']['avg_improvement'] >= 0 ? 'post' : 'pre' ?>">
                <?= $analytics['improvement']['avg_improvement'] >= 0 ? '+' : '' ?><?= $analytics['improvement']['avg_improvement'] ?>%
            </div>
            <p><span class="improvement-up"><i class="fas fa-arrow-up"></i> Improved: <?= $analytics['improvement']['improved'] ?></span> | 
               <span class="improvement-down"><i class="fas fa-arrow-down"></i> Declined: <?= $analytics['improvement']['declined'] ?></span></p>
        </div>
    </div>
    
    <div class="analytics-grid">
        <div class="analytics-card">
            <h3><i class="fas fa-chart-pie"></i> Pre-Test Performance Distribution</h3>
            <canvas id="preDistributionChart" style="max-height: 250px;"></canvas>
        </div>
        <div class="analytics-card">
            <h3><i class="fas fa-chart-pie"></i> Post-Test Performance Distribution</h3>
            <canvas id="postDistributionChart" style="max-height: 250px;"></canvas>
        </div>
    </div>
    
    <div class="students-table">
        <h3 style="margin-bottom: 20px;"><i class="fas fa-users"></i> Student Performance Details</h3>
        <table>
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>LRN</th>
                    <th>Pre-Test Score</th>
                    <th>Pre-Test %</th>
                    <th>Post-Test Score</th>
                    <th>Post-Test %</th>
                    <th>Improvement</th>
                    <th>Status</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($student_results as $data): 
                    $student = $data['student'];
                    $pre = $data['pre_test'];
                    $post = $data['post_test'];
                    
                    $prePercent = $pre ? round(floatval($pre['percentage']), 1) : null;
                    $postPercent = $post ? round(floatval($post['percentage']), 1) : null;
                    $improvement = ($prePercent !== null && $postPercent !== null) ? round($postPercent - $prePercent, 1) : null;
                    
                    $preScoreClass = $prePercent ? ($prePercent >= 75 ? 'score-high' : ($prePercent >= 50 ? 'score-medium' : 'score-low')) : '';
                    $postScoreClass = $postPercent ? ($postPercent >= 75 ? 'score-high' : ($postPercent >= 50 ? 'score-medium' : 'score-low')) : '';
                    
                    $status = 'Not Started';
                    $statusClass = '';
                    if ($pre && $post) {
                        $status = 'Completed';
                        $statusClass = 'score-high';
                    } elseif ($pre) {
                        $status = 'Post-Test Pending';
                        $statusClass = 'score-medium';
                    } elseif ($post) {
                        $status = 'Pre-Test Missing';
                        $statusClass = 'score-low';
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($student['student_id']) ?></td>
                    <td><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></td>
                    <td><?= htmlspecialchars($student['lrn'] ?? 'N/A') ?></td>
                    <td><?= $pre ? $pre['total_score'] . '/' . $pre['max_possible_score'] : '-' ?></td>
                    <td><span class="score-badge <?= $preScoreClass ?>"><?= $prePercent ? $prePercent . '%' : '-' ?></span></td>
                    <td><?= $post ? $post['total_score'] . '/' . $post['max_possible_score'] : '-' ?></td>
                    <td><span class="score-badge <?= $postScoreClass ?>"><?= $postPercent ? $postPercent . '%' : '-' ?></span></td>
                    <td>
                        <?php if ($improvement !== null): ?>
                            <span class="<?= $improvement >= 0 ? 'improvement-up' : 'improvement-down' ?>">
                                <?= $improvement >= 0 ? '+' : '' ?><?= $improvement ?>%
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><span class="score-badge <?= $statusClass ?>"><?= $status ?></span></td>
                    <td class="no-print">
                        <?php if ($pre || $post): ?>
                        <a href="student-assessment-report.php?student_id=<?= $student['id'] ?>" class="view-link" target="_blank">
                            <i class="fas fa-chart-line"></i> View Details
                        </a>
                        <?php else: ?>
                        <span style="color: #9ca3af;">No data</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const preDistribution = <?= json_encode(getPerformanceDistribution($student_results, 'pre')) ?>;
const preCtx = document.getElementById('preDistributionChart').getContext('2d');
new Chart(preCtx, {
    type: 'doughnut',
    data: {
        labels: Object.keys(preDistribution),
        datasets: [{
            data: Object.values(preDistribution),
            backgroundColor: ['#10b981', '#f59e0b', '#fbbf24', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

const postDistribution = <?= json_encode(getPerformanceDistribution($student_results, 'post')) ?>;
const postCtx = document.getElementById('postDistributionChart').getContext('2d');
new Chart(postCtx, {
    type: 'doughnut',
    data: {
        labels: Object.keys(postDistribution),
        datasets: [{
            data: Object.values(postDistribution),
            backgroundColor: ['#10b981', '#f59e0b', '#fbbf24', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>
</body>
</html>