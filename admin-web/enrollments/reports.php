<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: ../../index.php');
    exit();
}

$isAjax     = isset($_GET['ajax']) && $_GET['ajax'] == 1;
$page_title = "ALS Enrollment System - Reports";

// ── Filters ──────────────────────────────────────────────────────────────
$school_year     = $_GET['sy']       ?? '';
$barangay_filter = $_GET['barangay'] ?? '';
$teacher_filter  = $_GET['teacher']  ?? '';
$report_type     = $_GET['type']     ?? 'summary';

// ── Pagination ────────────────────────────────────────────────────────────
$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 7;
$offset   = ($page - 1) * $per_page;

// ── Lookup data ───────────────────────────────────────────────────────────
$result_sy = $conn->query("SELECT DISTINCT YEAR(enrollment_date) as year FROM students ORDER BY year DESC");
$school_years = [];
while ($row = $result_sy->fetch_assoc()) {
    $year = $row['year'];
    $school_years[] = $year . '-' . ($year + 1);
}

$barangays     = $conn->query("SELECT * FROM barangays WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$teachers_list = $conn->query("SELECT teacher_id, full_name FROM teachers ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// ── WHERE conditions ──────────────────────────────────────────────────────
$where_conditions = ["s.status = 'enrolled'"];
$params      = [];
$param_types = "";

if (!empty($school_year)) {
    $years = explode('-', $school_year);
    $where_conditions[] = "YEAR(s.enrollment_date) = ?";
    $params[]      = $years[0];
    $param_types  .= "i";
}
if (!empty($barangay_filter)) {
    $where_conditions[] = "s.current_barangay_id = ?";
    $params[]      = $barangay_filter;
    $param_types  .= "i";
}
if (!empty($teacher_filter)) {
    $where_conditions[] = "s.teacher_id = ?";
    $params[]      = $teacher_filter;
    $param_types  .= "i";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// ── Helper: build current filter query string (used everywhere for links) ─
// Always reflects the live PHP variables so downloads stay in sync.
function currentFilterQS($extra = []) {
    global $school_year, $barangay_filter, $teacher_filter, $report_type;
    $base = array_filter([
        'sy'      => $school_year,
        'barangay'=> $barangay_filter,
        'teacher' => $teacher_filter,
        'type'    => $report_type,
    ]);
    return http_build_query(array_merge($base, $extra));
}

// ── Total count ───────────────────────────────────────────────────────────
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM students s $where_clause");
if (!empty($params)) $count_stmt->bind_param($param_types, ...$params);
$count_stmt->execute();
$total_rows  = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $per_page);

// ── Paginated students ────────────────────────────────────────────────────
$paged_params = array_merge($params, [$per_page, $offset]);
$paged_types  = $param_types . "ii";
$paged_stmt   = $conn->prepare("
    SELECT s.*, t.full_name as teacher_name
    FROM students s
    LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
    $where_clause
    ORDER BY s.enrollment_date DESC
    LIMIT ? OFFSET ?
");
$paged_stmt->bind_param($paged_types, ...$paged_params);
$paged_stmt->execute();
$students = $paged_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── All students (for stats + Excel export) ───────────────────────────────
$all_stmt = $conn->prepare("
    SELECT s.*, t.full_name as teacher_name
    FROM students s
    LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
    $where_clause
    ORDER BY s.last_name, s.first_name
");
if (!empty($params)) $all_stmt->bind_param($param_types, ...$params);
$all_stmt->execute();
$all_students = $all_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Excel export (triggered by ?generate=1&format=excel) ─────────────────
// Must run before any HTML output.
if (isset($_GET['generate']) && ($_GET['format'] ?? '') === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="enrollment_report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');

    // Build a human-readable subtitle showing active filters
    $filter_parts = [];
    if (!empty($school_year))     $filter_parts[] = "SY: $school_year";
    if (!empty($barangay_filter)) {
        foreach ($barangays as $b) {
            if ($b['barangay_id'] == $barangay_filter) { $filter_parts[] = "Barangay: " . $b['name']; break; }
        }
    }
    if (!empty($teacher_filter)) {
        foreach ($teachers_list as $t) {
            if ($t['teacher_id'] == $teacher_filter) { $filter_parts[] = "Teacher: " . $t['full_name']; break; }
        }
    }
    $filter_label = !empty($filter_parts) ? implode(' | ', $filter_parts) : 'All Records';

    $male_count   = count(array_filter($all_students, fn($s) => in_array(strtolower($s['sex'] ?? ''), ['male','m'])));
    $female_count = count(array_filter($all_students, fn($s) => in_array(strtolower($s['sex'] ?? ''), ['female','f'])));

    echo "ALS Enrollment Report\n";
    echo "Filters: $filter_label\n";
    echo "Generated: " . date('F d, Y h:i A') . "\n\n";
    echo "Total: " . count($all_students) . "\tMale: $male_count\tFemale: $female_count\n\n";
    echo "ID\tName\tGender\tAge\tBarangay\tAssigned Teacher\tALS Program\tEnrollment Date\n";

    foreach ($all_students as $s) {
        $bn = "Unknown";
        foreach ($barangays as $b) {
            if ($b['barangay_id'] == $s['current_barangay_id']) { $bn = $b['name']; break; }
        }
        echo implode("\t", [
            $s['student_id'],
            $s['last_name'] . ', ' . $s['first_name'],
            ucfirst($s['sex'] ?? ''),
            $s['age'] ?? '',
            $bn,
            $s['teacher_name'] ?? 'Not Assigned',
            $s['als_program']  ?? '',
            $s['enrollment_date'] ?? '',
        ]) . "\n";
    }
    $conn->close();
    exit();
}

// ── Build AF1 data + date stats ───────────────────────────────────────────
$af1_data           = [];
$date_by_mapped     = [];
$date_by_enrollment = [];

foreach ($students as $student) {
    $barangay_name = "Unknown";
    $city          = "Unknown";
    foreach ($barangays as $b) {
        if ($b['barangay_id'] == $student['current_barangay_id']) {
            $barangay_name = $b['name'];
            $city          = $b['city'] ?? $b['name'];
            break;
        }
    }

    $date_mapped = !empty($student['enrollment_date']) ? date('m/d/Y', strtotime($student['enrollment_date'])) : 'N/A';
    $gender      = $student['sex'] ?? '';

    $af1_data[] = [
        'student_id'       => $student['student_id'],
        'learner_name'     => $student['last_name'] . ', ' . $student['first_name'] .
                              (!empty($student['middle_name']) ? ' ' . $student['middle_name'] : ''),
        'birthdate'        => !empty($student['birthdate']) ? date('m/d/Y', strtotime($student['birthdate'])) : 'N/A',
        'sex'              => $gender,
        'age'              => $student['age'],
        'barangay'         => $barangay_name,
        'city'             => $city,
        'date_mapped'      => $date_mapped,
        'last_grade_level' => $student['last_grade_level'] ?? 'Not specified',
        'enrollment_date'  => !empty($student['enrollment_date']) ? date('m/d/Y', strtotime($student['enrollment_date'])) : 'N/A',
        'teacher_name'     => $student['teacher_name'] ?? 'Not Assigned',
    ];

    if (!empty($student['enrollment_date'])) {
        $key = date('Y-m-d', strtotime($student['enrollment_date']));
        foreach (['date_by_mapped', 'date_by_enrollment'] as $var) {
            if (!isset($$var[$key])) $$var[$key] = ['male'=>0,'female'=>0,'total'=>0];
            if ($gender === 'male' || $gender === 'female') $$var[$key][$gender]++;
            $$var[$key]['total']++;
        }
    }
}
krsort($date_by_mapped);
krsort($date_by_enrollment);

// ── Summary statistics ────────────────────────────────────────────────────
$summary_data = [
    'total'          => count($all_students),
    'by_gender'      => ['male'=>0,'female'=>0],
    'by_barangay'    => [],
    'by_age_group'   => ['15-17'=>0,'18-24'=>0,'25-35'=>0,'36+'=>0],
    'by_als_program' => ['basic literacy'=>0,'a&e elementary'=>0,'a&e secondary'=>0,'als-shs'=>0],
    'by_teacher'     => [],
];

foreach ($all_students as $student) {
    $gender = $student['sex'] ?? '';
    if ($gender === 'male' || $gender === 'female') $summary_data['by_gender'][$gender]++;

    $age = $student['age'] ?? 0;
    if      ($age >= 15 && $age <= 17) $summary_data['by_age_group']['15-17']++;
    elseif  ($age >= 18 && $age <= 24) $summary_data['by_age_group']['18-24']++;
    elseif  ($age >= 25 && $age <= 35) $summary_data['by_age_group']['25-35']++;
    else                               $summary_data['by_age_group']['36+']++;

    if (!empty($student['als_program']) && isset($summary_data['by_als_program'][$student['als_program']]))
        $summary_data['by_als_program'][$student['als_program']]++;

    $bid = $student['current_barangay_id'] ?? null;
    if ($bid) {
        if (!isset($summary_data['by_barangay'][$bid])) {
            $bname = "Unknown";
            foreach ($barangays as $b) { if ($b['barangay_id'] == $bid) { $bname = $b['name']; break; } }
            $summary_data['by_barangay'][$bid] = ['name'=>$bname,'count'=>0];
        }
        $summary_data['by_barangay'][$bid]['count']++;
    }

    $tid   = $student['teacher_id'] ?? null;
    $tname = $student['teacher_name'] ?? 'Not Assigned';
    $tkey  = $tid ?? 'unassigned';
    if (!isset($summary_data['by_teacher'][$tkey]))
        $summary_data['by_teacher'][$tkey] = ['name'=>$tname,'count'=>0];
    $summary_data['by_teacher'][$tkey]['count']++;
}

uasort($summary_data['by_barangay'], fn($a,$b) => $b['count'] - $a['count']);
uasort($summary_data['by_teacher'],  fn($a,$b) => $b['count'] - $a['count']);

// ── AI Insights ───────────────────────────────────────────────────────────
function generateAIInsights($summary_data) {
    $insights = [];
    $total    = $summary_data['total'];

    if ($total == 0)       { $insights['total'] = "No students found with current filters."; }
    elseif ($total < 10)   { $insights['total'] = "Small cohort of {$total} students. Consider expanding outreach."; }
    elseif ($total < 50)   { $insights['total'] = "Moderate enrollment of {$total} students. Good foundation for growth."; }
    else                   { $insights['total'] = "Strong enrollment with {$total} students. Program is reaching significant numbers."; }

    $male   = $summary_data['by_gender']['male'];
    $female = $summary_data['by_gender']['female'];
    if ($total > 0) {
        $mp = round(($male/$total)*100,1);
        $fp = round(($female/$total)*100,1);
        $insights['gender'] = abs($mp-$fp)>20
            ? "Gender imbalance detected ({$mp}% male vs {$fp}% female). Consider targeted outreach."
            : "Balanced gender distribution ({$mp}% male, {$fp}% female).";
    }

    $age_groups = $summary_data['by_age_group'];
    $max_age    = !empty($age_groups) ? max($age_groups) : 0;
    if ($max_age > 0 && $total > 0) {
        $lgroup = array_search($max_age, $age_groups);
        $insights['age'] = "Majority (" . round(($max_age/$total)*100,1) . "%) of learners are in the {$lgroup} age group.";
    }

    $prog_total = array_sum($summary_data['by_als_program']);
    if ($total > 0) {
        $pp = round(($prog_total/$total)*100,1);
        $insights['program'] = $pp < 50
            ? "Only {$pp}% of students have assigned programs. Consider program assignment."
            : "{$pp}% of students have assigned ALS programs.";
    }

    if (!empty($summary_data['by_barangay']) && $total > 0) {
        $top = reset($summary_data['by_barangay']);
        $tp  = round(($top['count']/$total)*100,1);
        $insights['barangay'] = $tp > 50
            ? "Concentrated enrollment from {$top['name']} ({$tp}%). Diversify outreach."
            : "Good geographic spread. Top barangay: {$top['name']} at {$tp}%.";
    }

    if (!empty($summary_data['by_teacher']) && $total > 0) {
        $assigned       = array_filter($summary_data['by_teacher'], fn($t) => $t['name'] !== 'Not Assigned');
        $assigned_count = array_sum(array_column($assigned,'count'));
        $ap = round(($assigned_count/$total)*100,1);
        $insights['teacher'] = $ap < 80
            ? "{$ap}% of students have assigned teachers. " . ($total - $assigned_count) . " need assignment."
            : "{$ap}% of students have an assigned teacher. Strong coverage.";
    }

    return $insights;
}

$ai_insights = generateAIInsights($summary_data);

// ── Chart data ────────────────────────────────────────────────────────────
$chart_age_labels   = array_keys($summary_data['by_age_group']);
$chart_age_data     = array_values($summary_data['by_age_group']);
$chart_gender_data  = [$summary_data['by_gender']['male'], $summary_data['by_gender']['female']];
$chart_bar_labels   = array_map(fn($b)=>$b['name'],  array_slice($summary_data['by_barangay'],0,8));
$chart_bar_data     = array_map(fn($b)=>$b['count'], array_slice($summary_data['by_barangay'],0,8));
$chart_prog_labels  = array_keys($summary_data['by_als_program']);
$chart_prog_data    = array_values($summary_data['by_als_program']);
$chart_teach_labels = array_map(fn($t)=>$t['name'],  array_slice($summary_data['by_teacher'],0,8));
$chart_teach_data   = array_map(fn($t)=>$t['count'], array_slice($summary_data['by_teacher'],0,8));

// ── AJAX handler ──────────────────────────────────────────────────────────
if ($isAjax) {
    if ($report_type == 'summary')       echo getSummaryContent();
    elseif ($report_type == 'detailed')  echo getDetailedContent();
    else                                 echo getAF1Content();
    exit();
}

// ══════════════════════════════════════════════════════════════════════════
// CONTENT FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

function renderPagination($page, $total_pages) {
    if ($total_pages <= 1) return '';
    ob_start(); ?>
<div class="pag-wrap">
    <nav><ul class="pag-list">
        <li class="<?= $page==1?'disabled':'' ?>">
            <a class="pag-link" href="#" data-page="<?= $page-1 ?>"><i class="fas fa-chevron-left"></i></a>
        </li>
        <?php
        $s=max(1,$page-2); $e=min($total_pages,$page+2);
        if($s>1){ echo '<li><a class="pag-link" href="#" data-page="1">1</a></li>'; if($s>2) echo '<li class="disabled"><span class="pag-link">…</span></li>'; }
        for($i=$s;$i<=$e;$i++) echo "<li class=\"".($i==$page?'active':'')."\"><a class=\"pag-link\" href=\"#\" data-page=\"$i\">$i</a></li>";
        if($e<$total_pages){ if($e<$total_pages-1) echo '<li class="disabled"><span class="pag-link">…</span></li>'; echo "<li><a class=\"pag-link\" href=\"#\" data-page=\"$total_pages\">$total_pages</a></li>"; }
        ?>
        <li class="<?= $page==$total_pages?'disabled':'' ?>">
            <a class="pag-link" href="#" data-page="<?= $page+1 ?>"><i class="fas fa-chevron-right"></i></a>
        </li>
    </ul></nav>
    <span class="pag-info">Page <?= $page ?> of <?= $total_pages ?></span>
    <select class="pag-jump">
        <?php for($i=1;$i<=$total_pages;$i++) echo "<option value=\"$i\"".($i==$page?' selected':'').">Page $i</option>"; ?>
    </select>
</div>
<?php return ob_get_clean(); }

function getSummaryContent() {
    global $summary_data, $ai_insights;
    ob_start(); ?>
<div class="tab-pane active" id="pane-summary">
    <?php if($summary_data['total']>0): ?>
    <div class="insight-band">
        <div class="insight-band-head"><i class="fas fa-wand-magic-sparkles"></i> AI Insights</div>
        <div class="insight-cards">
            <?php
            $icons  = ['total'=>'fa-users','gender'=>'fa-venus-mars','age'=>'fa-calendar','program'=>'fa-graduation-cap','barangay'=>'fa-map-pin','teacher'=>'fa-chalkboard-user'];
            $labels = ['total'=>'Enrollment','gender'=>'Gender Split','age'=>'Age Groups','program'=>'Programs','barangay'=>'Geographic','teacher'=>'Teacher Coverage'];
            foreach($ai_insights as $k=>$v): ?>
            <div class="ic">
                <div class="ic-icon"><i class="fas <?= $icons[$k]??'fa-circle-info' ?>"></i></div>
                <div class="ic-body"><div class="ic-lbl"><?= $labels[$k]??ucfirst($k) ?></div><div class="ic-txt"><?= $v ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="kpi-row">
        <div class="kpi blue"><div class="kpi-icon"><i class="fas fa-users"></i></div><div class="kpi-val"><?= $summary_data['total'] ?></div><div class="kpi-lbl">Total Students</div></div>
        <div class="kpi cyan"><div class="kpi-icon"><i class="fas fa-mars"></i></div><div class="kpi-val"><?= $summary_data['by_gender']['male'] ?></div><div class="kpi-lbl">Male</div></div>
        <div class="kpi rose"><div class="kpi-icon"><i class="fas fa-venus"></i></div><div class="kpi-val"><?= $summary_data['by_gender']['female'] ?></div><div class="kpi-lbl">Female</div></div>
        <div class="kpi green"><div class="kpi-icon"><i class="fas fa-chalkboard-user"></i></div><div class="kpi-val"><?= count(array_filter($summary_data['by_teacher'],fn($t)=>$t['name']!=='Not Assigned')) ?></div><div class="kpi-lbl">Teachers Active</div></div>
    </div>

    <?php if($summary_data['total']>0): ?>
    <div class="chart-grid">
        <div class="chart-box"><div class="chart-box-title"><i class="fas fa-chart-column"></i> Age Distribution</div><div class="chart-area"><canvas id="ageChart"></canvas></div></div>
        <div class="chart-box"><div class="chart-box-title"><i class="fas fa-chart-pie"></i> Gender Distribution</div><div class="chart-area"><canvas id="genderChart"></canvas></div></div>
        <div class="chart-box wide"><div class="chart-box-title"><i class="fas fa-map-pin"></i> Top Barangays</div><div class="chart-area"><canvas id="barangayChart"></canvas></div></div>
        <div class="chart-box"><div class="chart-box-title"><i class="fas fa-book-open-reader"></i> ALS Programs</div><div class="chart-area"><canvas id="programChart"></canvas></div></div>
        <div class="chart-box"><div class="chart-box-title"><i class="fas fa-chalkboard-user"></i> Students per Teacher</div><div class="chart-area"><canvas id="teacherChart"></canvas></div></div>
    </div>
    <?php else: ?>
    <div class="empty-state"><i class="fas fa-chart-bar"></i><h4>No Data Available</h4><p>Apply filters to see charts.</p></div>
    <?php endif; ?>
</div>
<?php return ob_get_clean(); }

function getDetailedContent() {
    global $students, $barangays, $total_rows, $per_page, $page, $total_pages;
    ob_start(); ?>
<div class="tab-pane active" id="pane-detailed">
    <?php if($total_rows > 0): ?>
    <div class="tbl-wrap">
        <div class="tbl-header">
            <div><div class="tbl-title">Student Enrollment Details</div><div class="tbl-sub">Showing <?= min($per_page,count($students)) ?> of <?= $total_rows ?> students</div></div>
        </div>
        <div class="tbl-scroll">
        <table class="data-tbl">
            <thead><tr>
                <th>Student ID</th><th>Name</th><th>Age</th><th>Gender</th>
                <th>Barangay</th><th>Assigned Teacher</th><th>Program</th><th>Enrolled</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach($students as $s):
                $bn="Unknown"; foreach($barangays as $b){if($b['barangay_id']==$s['current_barangay_id']){$bn=$b['name'];break;}}
            ?>
            <tr>
                <td><span class="badge b-blue"><?= htmlspecialchars($s['student_id']) ?></span></td>
                <td><strong><?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?></strong><?php if(!empty($s['middle_name'])): ?><br><small class="muted"><?= htmlspecialchars($s['middle_name']) ?></small><?php endif; ?></td>
                <td><?= $s['age'] ?></td>
                <td><?= $s['sex']=='male' ? '<span class="badge b-cyan">Male</span>' : '<span class="badge b-rose">Female</span>' ?></td>
                <td><?= htmlspecialchars($bn) ?></td>
                <td><?= !empty($s['teacher_name']) ? '<span class="badge b-green">'.htmlspecialchars($s['teacher_name']).'</span>' : '<span class="badge b-muted">Not Assigned</span>' ?></td>
                <td><?= !empty($s['als_program']) ? '<span class="badge b-purple">'.ucwords(str_replace('&',' & ',$s['als_program'])).'</span>' : '<span class="badge b-amber">Not Set</span>' ?></td>
                <td><?= !empty($s['enrollment_date'])?date('M d, Y',strtotime($s['enrollment_date'])):'N/A' ?></td>
                <td><span class="badge b-green">Enrolled</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?= renderPagination($page, $total_pages) ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><i class="fas fa-users-slash"></i><h4>No students found</h4><p>Try adjusting your filters.</p></div>
    <?php endif; ?>
</div>
<?php return ob_get_clean(); }

function getAF1Content() {
    global $af1_data, $date_by_mapped, $date_by_enrollment, $total_rows, $per_page, $page, $total_pages, $all_students, $summary_data;
    ob_start(); ?>
<div class="tab-pane active" id="pane-af1">
    <?php if($total_rows > 0): ?>
    <div class="note-strip"><i class="fas fa-circle-info"></i> Date Mapped is derived from Enrollment Date as they represent the same event in this system.</div>

    <div class="date-grid">
        <?php foreach([['Date by Mapped','fa-calendar-check',$date_by_mapped],['Date by Enrollment','fa-user-plus',$date_by_enrollment]] as [$dtitle,$dicon,$ddata]): ?>
        <div class="tbl-wrap">
            <div class="tbl-header"><div class="tbl-title"><i class="fas <?= $dicon ?>"></i> <?= $dtitle ?></div></div>
            <table class="data-tbl">
                <thead><tr><th>Date</th><th>Male</th><th>Female</th><th>Total</th></tr></thead>
                <tbody>
                <?php $mt=$ft=$ot=0; if(!empty($ddata)): foreach($ddata as $date=>$c): $mt+=$c['male']; $ft+=$c['female']; $ot+=$c['total']; ?>
                <tr><td><?= date('m/d/Y',strtotime($date)) ?></td><td><?= $c['male'] ?></td><td><?= $c['female'] ?></td><td class="tot-cell"><?= $c['total'] ?></td></tr>
                <?php endforeach; ?>
                <tr class="grand-row"><td><strong>GRAND TOTAL</strong></td><td><strong><?= $mt ?></strong></td><td><strong><?= $ft ?></strong></td><td class="tot-cell"><strong><?= $ot ?></strong></td></tr>
                <?php else: ?><tr><td colspan="4" class="empty-cell">No data available</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="tbl-wrap" style="margin-top:1.5rem;">
        <div class="tbl-header">
            <div><div class="tbl-title">ALS AF1 Form — Registration Data</div><div class="tbl-sub">Showing <?= min($per_page,count($af1_data)) ?> of <?= $total_rows ?> student(s)</div></div>
        </div>
        <div class="tbl-scroll">
        <table class="data-tbl">
            <thead><tr>
                <th>Student ID</th><th>Learner's Name</th><th>Date of Birth</th><th>Sex</th>
                <th>Age</th><th>Barangay</th><th>City</th><th>Assigned Teacher</th>
                <th>Date Mapped</th><th>Last Grade Level</th><th>Enrollment Date</th>
            </tr></thead>
            <tbody>
            <?php foreach($af1_data as $row): ?>
            <tr>
                <td><span class="badge b-blue"><?= htmlspecialchars($row['student_id']) ?></span></td>
                <td><strong><?= htmlspecialchars($row['learner_name']) ?></strong></td>
                <td><?= $row['birthdate'] ?></td>
                <td><?= $row['sex']=='male'?'<span class="badge b-cyan">Male</span>':'<span class="badge b-rose">Female</span>' ?></td>
                <td><?= $row['age'] ?></td>
                <td><?= htmlspecialchars($row['barangay']) ?></td>
                <td><?= htmlspecialchars($row['city']) ?></td>
                <td><?= $row['teacher_name']!='Not Assigned' ? '<span class="badge b-green">'.htmlspecialchars($row['teacher_name']).'</span>' : '<span class="badge b-muted">Not Assigned</span>' ?></td>
                <td><?= $row['date_mapped'] ?></td>
                <td><?= htmlspecialchars($row['last_grade_level']) ?></td>
                <td><?= $row['enrollment_date'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?= renderPagination($page, $total_pages) ?>
    </div>

    <div class="af1-stat-row" style="margin-top:1.5rem;">
        <div class="af1-stat-box">
            <div class="tbl-header"><div class="tbl-title"><i class="fas fa-percent"></i> Form Completion Rate</div></div>
            <?php
            $tf=$cf=0;
            $req=['last_name','first_name','birthdate','age','sex','current_barangay_id','last_grade_level','enrollment_date'];
            foreach($all_students as $st){ foreach($req as $f){ $tf++; if(!empty($st[$f])&&$st[$f]!='0') $cf++; } }
            $rate=$tf>0?round(($cf/$tf)*100,2):0;
            ?>
            <div class="completion-dial"><div class="dial-val"><?= $rate ?>%</div><div class="dial-lbl">Overall completion rate</div></div>
        </div>
        <div class="af1-stat-box">
            <div class="tbl-header"><div class="tbl-title"><i class="fas fa-circle-info"></i> Quick Stats</div></div>
            <div class="quick-stats">
                <div class="qs-row"><span>Total Students</span><span class="badge b-blue"><?= $total_rows ?></span></div>
                <div class="qs-row"><span>Male</span><span class="badge b-cyan"><?= $summary_data['by_gender']['male'] ?></span></div>
                <div class="qs-row"><span>Female</span><span class="badge b-rose"><?= $summary_data['by_gender']['female'] ?></span></div>
                <div class="qs-row"><span>Average Age</span>
                    <?php $ta=$tc=0; foreach($all_students as $st){if(!empty($st['age'])){$ta+=$st['age'];$tc++;}} ?>
                    <span class="badge b-green"><?= $tc>0?round($ta/$tc,1):0 ?></span>
                </div>
                <div class="qs-row"><span>With Teacher</span>
                    <?php $wt=count(array_filter($all_students,fn($s)=>!empty($s['teacher_id']))); ?>
                    <span class="badge b-purple"><?= $wt ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="empty-state"><i class="fas fa-file-alt"></i><h4>No AF1 Data Available</h4><p>No students match the selected criteria.</p></div>
    <?php endif; ?>
</div>
<?php return ob_get_clean(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Bricolage+Grotesque:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
    --bg:#f0f2f7;--surface:#ffffff;--border:#e4e7ef;--ink:#0e1117;--ink-soft:#4a505f;--ink-mute:#8b90a0;
    --blue:#3b5bdb;--blue-l:#eef2ff;--cyan:#0ea5e9;--cyan-l:#e0f2fe;--rose:#e11d48;--rose-l:#fff1f2;
    --green:#10b981;--green-l:#ecfdf5;--amber:#f59e0b;--amber-l:#fffbeb;--purple:#7c3aed;--purple-l:#f5f3ff;
    --r:12px;--r-l:18px;--r-xl:24px;
    --sh:0 1px 3px rgba(14,17,23,.06),0 1px 2px rgba(14,17,23,.04);
    --sh-m:0 4px 16px rgba(14,17,23,.09);--sh-l:0 12px 36px rgba(14,17,23,.12);
    --ff-head:'Bricolage Grotesque',sans-serif;--ff-body:'DM Sans',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--ff-body);background:var(--bg);color:var(--ink);font-size:14px;line-height:1.6;min-height:100vh}
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px}
.page-wrap{max-width:1440px;margin:0 auto;padding:24px}
.page-head{background:var(--blue);border-radius:var(--r-xl);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;position:relative;overflow:hidden;box-shadow:var(--sh-l)}
.page-head::before{content:'';position:absolute;top:-60px;right:-60px;width:220px;height:220px;background:rgba(255,255,255,.07);border-radius:50%}
.page-head::after{content:'';position:absolute;bottom:-40px;left:25%;width:160px;height:160px;background:rgba(255,255,255,.04);border-radius:50%}
.ph-inner{position:relative;z-index:2}
.ph-title{font-family:var(--ff-head);font-size:1.75rem;font-weight:800;color:#fff;letter-spacing:-.5px}
.ph-sub{font-size:13px;color:rgba(255,255,255,.7);margin-top:3px}
.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:99px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.25);color:#fff;font-family:var(--ff-body);font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;position:relative;z-index:2}
.btn-back:hover{background:rgba(255,255,255,.25)}
.filter-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);padding:24px;margin-bottom:24px;box-shadow:var(--sh)}
.filter-head{display:flex;align-items:center;gap:8px;font-family:var(--ff-head);font-size:1rem;font-weight:700;color:var(--ink);margin-bottom:18px}
.filter-head i{color:var(--blue)}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:18px}
.fg-item{display:flex;flex-direction:column;gap:6px}
.fg-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--ink-mute)}
.fg-select,.fg-input{border:1.5px solid var(--border);border-radius:var(--r);padding:9px 12px;font-family:var(--ff-body);font-size:13px;color:var(--ink);background:var(--bg);outline:none;transition:border-color .2s}
.fg-select:focus,.fg-input:focus{border-color:var(--blue);background:#fff}
.filter-actions{display:flex;gap:10px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border)}

/* Active filter pills */
.active-filters{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.filter-pill{display:inline-flex;align-items:center;gap:6px;background:var(--blue-l);border:1.5px solid var(--blue);color:var(--blue);border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600}
.filter-pill i{font-size:10px;cursor:pointer;opacity:.7}
.filter-pill i:hover{opacity:1}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:99px;font-family:var(--ff-body);font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:var(--blue);color:#fff;box-shadow:0 4px 12px rgba(59,91,219,.3)}
.btn-primary:hover{filter:brightness(1.08);transform:translateY(-1px)}
.btn-ghost{background:var(--bg);color:var(--ink-soft);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--border)}
.btn-danger{background:var(--rose);color:#fff}
.btn-danger:hover{filter:brightness(1.08)}
.btn-excel{background:var(--green);color:#fff}
.btn-excel:hover{filter:brightness(1.08)}
.report-toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px}
.report-title{font-family:var(--ff-head);font-size:1.3rem;font-weight:700;color:var(--ink)}
.toolbar-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dropdown{position:relative;display:inline-block}
.dropdown-menu{position:absolute;top:calc(100% + 8px);right:0;min-width:300px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);box-shadow:var(--sh-l);padding:6px;display:none;z-index:500}
.dropdown-menu.open{display:block;animation:ddFade .18s ease}
@keyframes ddFade{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.dd-header{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--ink-mute);padding:8px 12px 4px}
.dd-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:var(--r);cursor:pointer;transition:all .2s;text-decoration:none;color:var(--ink)}
.dd-item:hover{background:var(--blue-l);color:var(--blue)}
.dd-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0}
.dd-text strong{display:block;font-size:13px;font-weight:600}
.dd-text small{font-size:11px;color:var(--ink-mute)}
.dd-divider{border:none;border-top:1px solid var(--border);margin:4px 0}
.dd-footer{padding:8px 12px;font-size:11px;color:var(--ink-mute);text-align:center}

/* Filter badge on dropdown button */
.filter-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;background:#fff;color:var(--blue);border-radius:99px;font-size:10px;font-weight:800;padding:0 4px;margin-left:2px}
.dd-filter-note{background:var(--blue-l);border-radius:var(--r);padding:8px 12px;margin:4px;font-size:11px;color:var(--blue);display:flex;align-items:center;gap:6px}

.tab-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);padding:6px;display:flex;gap:4px;margin-bottom:20px;box-shadow:var(--sh)}
.tab-btn{flex:1;border:none;background:transparent;padding:10px 14px;border-radius:var(--r);font-family:var(--ff-body);font-size:13px;font-weight:600;color:var(--ink-mute);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px}
.tab-btn:hover{background:var(--bg);color:var(--ink)}
.tab-btn.active{background:var(--blue);color:#fff;box-shadow:0 4px 12px rgba(59,91,219,.28)}
.tab-pane{display:none}.tab-pane.active{display:block}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);padding:20px;display:flex;flex-direction:column;align-items:flex-start;gap:8px;box-shadow:var(--sh);transition:transform .2s,box-shadow .2s}
.kpi:hover{transform:translateY(-3px);box-shadow:var(--sh-m)}
.kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.kpi.blue .kpi-icon{background:var(--blue-l);color:var(--blue)}.kpi.cyan .kpi-icon{background:var(--cyan-l);color:var(--cyan)}.kpi.rose .kpi-icon{background:var(--rose-l);color:var(--rose)}.kpi.green .kpi-icon{background:var(--green-l);color:var(--green)}
.kpi-val{font-family:var(--ff-head);font-size:2rem;font-weight:800;line-height:1;color:var(--ink)}
.kpi-lbl{font-size:12px;color:var(--ink-mute);font-weight:500;text-transform:uppercase;letter-spacing:.5px}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.chart-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);padding:20px;box-shadow:var(--sh)}
.chart-box.wide{grid-column:1/-1}
.chart-box-title{font-family:var(--ff-head);font-size:.95rem;font-weight:700;color:var(--ink);margin-bottom:14px;display:flex;align-items:center;gap:7px}
.chart-box-title i{color:var(--blue)}
.chart-area{position:relative;height:230px}
.insight-band{background:linear-gradient(135deg,var(--blue) 0%,#4f46e5 100%);border-radius:var(--r-l);padding:20px 24px;margin-bottom:24px;box-shadow:var(--sh-m)}
.insight-band-head{font-family:var(--ff-head);color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px;margin-bottom:14px}
.insight-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}
.ic{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:var(--r);padding:12px 14px;display:flex;gap:12px;backdrop-filter:blur(10px)}
.ic-icon{width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0}
.ic-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.65);margin-bottom:2px}
.ic-txt{font-size:12.5px;color:rgba(255,255,255,.9);line-height:1.45}
.tbl-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);overflow:hidden;box-shadow:var(--sh)}
.tbl-header{padding:16px 20px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.tbl-title{font-family:var(--ff-head);font-size:.95rem;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:7px}
.tbl-title i{color:var(--blue)}.tbl-sub{font-size:12px;color:var(--ink-mute);margin-top:2px}
.tbl-scroll{overflow-x:auto}
.data-tbl{width:100%;border-collapse:collapse}
.data-tbl th{background:var(--bg);padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-mute);border-bottom:2px solid var(--border);white-space:nowrap}
.data-tbl td{padding:11px 16px;border-bottom:1px solid var(--border);font-size:13px;color:var(--ink-soft)}
.data-tbl tr:last-child td{border-bottom:none}.data-tbl tr:hover td{background:var(--blue-l)}
.tot-cell{background:var(--green-l)!important;color:var(--green);font-weight:700}.grand-row td{background:var(--bg)!important;font-weight:700}.empty-cell{text-align:center;padding:24px;color:var(--ink-mute)}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;letter-spacing:.2px}
.b-blue{background:var(--blue-l);color:var(--blue)}.b-cyan{background:var(--cyan-l);color:#0369a1}.b-rose{background:var(--rose-l);color:var(--rose)}.b-green{background:var(--green-l);color:#047857}.b-amber{background:var(--amber-l);color:#b45309}.b-purple{background:var(--purple-l);color:var(--purple)}.b-muted{background:var(--bg);color:var(--ink-mute)}
.pag-wrap{display:flex;align-items:center;justify-content:center;gap:12px;padding:16px;background:var(--bg);border-top:1px solid var(--border);flex-wrap:wrap}
.pag-list{display:flex;gap:4px;list-style:none}
.pag-link{display:flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 8px;border-radius:var(--r);background:var(--surface);color:var(--ink-soft);text-decoration:none;font-size:13px;font-weight:600;border:1.5px solid var(--border);transition:all .2s;cursor:pointer}
.pag-link:hover{background:var(--blue-l);color:var(--blue);border-color:var(--blue)}.pag-list li.active .pag-link{background:var(--blue);color:#fff;border-color:var(--blue)}.pag-list li.disabled .pag-link{background:var(--bg);color:var(--ink-mute);cursor:not-allowed}
.pag-info{font-size:12px;color:var(--ink-mute)}.pag-jump{padding:6px 10px;border-radius:var(--r);border:1.5px solid var(--border);background:var(--surface);font-size:12px;color:var(--ink);cursor:pointer}
.date-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.note-strip{display:flex;align-items:center;gap:8px;background:var(--amber-l);border-left:4px solid var(--amber);border-radius:var(--r);padding:10px 16px;margin-bottom:16px;font-size:13px;color:#92400e}
.note-strip i{color:var(--amber);flex-shrink:0}
.af1-stat-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.af1-stat-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-l);overflow:hidden;box-shadow:var(--sh)}
.completion-dial{text-align:center;padding:28px}.dial-val{font-family:var(--ff-head);font-size:3rem;font-weight:800;color:var(--blue);line-height:1}.dial-lbl{font-size:13px;color:var(--ink-mute);margin-top:6px}
.quick-stats{padding:8px 0}.qs-row{display:flex;align-items:center;justify-content:space-between;padding:10px 20px;border-bottom:1px solid var(--border)}.qs-row:last-child{border-bottom:none}
.empty-state{text-align:center;padding:52px 24px;color:var(--ink-mute)}.empty-state i{font-size:3rem;opacity:.3;display:block;margin-bottom:12px}.empty-state h4{font-family:var(--ff-head);font-size:1.1rem;color:var(--ink-soft);margin-bottom:6px}.empty-state p{font-size:13px}
#loadingOverlay{position:fixed;inset:0;background:rgba(255,255,255,.8);display:none;justify-content:center;align-items:center;z-index:9999;backdrop-filter:blur(4px)}
.spinner{width:44px;height:44px;border:4px solid var(--border);border-top-color:var(--blue);border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:900px){.chart-grid{grid-template-columns:1fr}.chart-box.wide{grid-column:auto}.date-grid{grid-template-columns:1fr}.af1-stat-row{grid-template-columns:1fr}}
@media(max-width:640px){.page-wrap{padding:12px}.page-head{padding:20px 18px}.ph-title{font-size:1.3rem}.kpi-row{grid-template-columns:1fr 1fr}.insight-cards{grid-template-columns:1fr}.filter-grid{grid-template-columns:1fr}.tab-btn span{display:none}.report-toolbar{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<div id="loadingOverlay"><div class="spinner"></div></div>

<div class="page-wrap">

    <!-- HEADER -->
    <div class="page-head">
        <div class="ph-inner">
            <div class="ph-title"><i class="fas fa-chart-bar" style="margin-right:10px;"></i>ALS Reports & Analytics</div>
            <div class="ph-sub">Enrollment data · AI insights · Export tools</div>
        </div>
        <a href="/AdminDashboard" class="btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>

    <!-- FILTER CARD -->
    <div class="filter-card">
        <div class="filter-head"><i class="fas fa-sliders"></i> Filter Reports</div>
        <form method="GET" id="filterForm">
            <input type="hidden" name="page" value="1" id="pageInput">
            <div class="filter-grid">
                <div class="fg-item">
                    <label class="fg-label">School Year</label>
                    <select class="fg-select" id="sy" name="sy">
                        <option value="">All School Years</option>
                        <?php foreach($school_years as $y): ?>
                        <option value="<?= $y ?>" <?= $school_year==$y?'selected':'' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg-item">
                    <label class="fg-label">Barangay</label>
                    <select class="fg-select" id="barangay" name="barangay">
                        <option value="">All Barangays</option>
                        <?php foreach($barangays as $b): ?>
                        <option value="<?= $b['barangay_id'] ?>" <?= $barangay_filter==$b['barangay_id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg-item">
                    <label class="fg-label">Assigned Teacher</label>
                    <select class="fg-select" id="teacher" name="teacher">
                        <option value="">All Teachers</option>
                        <?php foreach($teachers_list as $t): ?>
                        <option value="<?= $t['teacher_id'] ?>" <?= $teacher_filter==$t['teacher_id']?'selected':'' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg-item">
                    <label class="fg-label">Report Type</label>
                    <select class="fg-select" id="type" name="type">
                        <option value="summary"  <?= $report_type=='summary'?'selected':'' ?>>Summary Report</option>
                        <option value="detailed" <?= $report_type=='detailed'?'selected':'' ?>>Detailed Report</option>
                        <option value="af1"      <?= $report_type=='af1'?'selected':'' ?>>AF1 Form Report</option>
                    </select>
                </div>
            </div>

            <!-- Active filter pills -->
            <?php
            $active_filters = [];
            if (!empty($school_year))     $active_filters[] = ['School Year', $school_year, 'sy'];
            if (!empty($barangay_filter)) {
                $bn = 'Unknown';
                foreach ($barangays as $b) { if ($b['barangay_id']==$barangay_filter) { $bn=$b['name']; break; } }
                $active_filters[] = ['Barangay', $bn, 'barangay'];
            }
            if (!empty($teacher_filter)) {
                $tn = 'Unknown';
                foreach ($teachers_list as $t) { if ($t['teacher_id']==$teacher_filter) { $tn=$t['full_name']; break; } }
                $active_filters[] = ['Teacher', $tn, 'teacher'];
            }
            if (!empty($active_filters)): ?>
            <div class="active-filters">
                <?php foreach($active_filters as [$label, $value, $key]): ?>
                <span class="filter-pill">
                    <i class="fas fa-tag"></i><?= $label ?>: <strong><?= htmlspecialchars($value) ?></strong>
                    <i class="fas fa-xmark" onclick="clearFilter('<?= $key ?>')" title="Remove filter"></i>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="filter-actions">
                <button type="button" class="btn btn-ghost" id="resetBtn"><i class="fas fa-rotate-left"></i> Reset All</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- REPORT TOOLBAR -->
    <div class="report-toolbar">
        <div>
            <div class="report-title" id="reportTitle">
                <?= $report_type=='summary'?'Summary Report':($report_type=='detailed'?'Detailed Enrollment Report':'AF1 Form Report') ?>
            </div>
            <?php if(!empty($active_filters)): ?>
            <div style="font-size:12px;color:var(--ink-mute);margin-top:3px;">
                <i class="fas fa-filter" style="color:var(--blue);margin-right:4px;"></i>
                Filtered: <?= implode(' · ', array_map(fn($f) => $f[0].': '.$f[1], $active_filters)) ?>
                &nbsp;·&nbsp; <?= $total_rows ?> result(s)
            </div>
            <?php endif; ?>
        </div>
        <div class="toolbar-actions">
            <!-- ALS Forms dropdown — filters are baked into every link href -->
            <div class="dropdown" id="alsDropdown">
                <button class="btn btn-primary" id="alsDropBtn">
                    <i class="fas fa-file-contract"></i> ALS Forms
                    <?php if(!empty($active_filters)): ?><span class="filter-badge"><?= count($active_filters) ?></span><?php endif; ?>
                    <i class="fas fa-chevron-down" style="font-size:11px;"></i>
                </button>
                <div class="dropdown-menu" id="alsMenu">
                    <?php if(!empty($active_filters)): ?>
                    <div class="dd-filter-note">
                        <i class="fas fa-filter"></i>
                        Filters applied: <?= implode(', ', array_column($active_filters, 0)) ?>
                    </div>
                    <?php endif; ?>
                    <div class="dd-header">Download Form</div>
                    <?php
                    // Build filter QS once — always uses live PHP variables
                    $form_filter_qs = http_build_query(array_filter([
                        'sy'      => $school_year,
                        'barangay'=> $barangay_filter,
                        'teacher' => $teacher_filter,
                    ]));
                    
                    $forms = [
                        ['AF-1','Masterlist','Learner registration data','fa-list','#3b5bdb'],
                        ['AF-2','Enrollment Form','Individual learner enrollment','fa-user-edit','#10b981'],
                        ['AF-3','Enrolled Learners','Current enrolled learners list','fa-graduation-cap','#0ea5e9'],
                        ['AF-4','A&E Registrants','Accreditation & Equivalency','fa-clipboard-check','#f59e0b'],
                        ['AF-5','Permanent Record','Learner permanent record','fa-id-card','#e11d48'],
                    ];
                    
                    foreach($forms as [$code,$name,$desc,$icon,$color]):
                        // Ensure form parameter is included
                        $form_url = '/GenerateALS?' . 
                                   ($form_filter_qs ? $form_filter_qs . '&' : '') . 
                                   'form=' . $code;
                    ?>
                    <a class="dd-item" 
                       href="<?= htmlspecialchars($form_url) ?>" 
                       data-form="<?= $code ?>"
                       data-sy="<?= htmlspecialchars($school_year) ?>"
                       data-barangay="<?= htmlspecialchars($barangay_filter) ?>"
                       data-teacher="<?= htmlspecialchars($teacher_filter) ?>">
                        <div class="dd-icon" style="background:<?= $color ?>"><i class="fas <?= $icon ?>"></i></div>
                        <div class="dd-text"><strong><?= $code ?> — <?= $name ?></strong><small><?= $desc ?></small></div>
                    </a>
                    <?php endforeach; ?>
                    <div class="dd-divider"></div>
                    <div class="dd-footer"><i class="fas fa-circle-info" style="margin-right:4px;"></i>Active filters will be applied to the download</div>
                </div>
            </div>

            <!-- Excel download — always carries current filters -->
            <a id="excelBtn"
               href="/AdminReports?<?= htmlspecialchars(currentFilterQS(['generate'=>'1','format'=>'excel'])) ?>"
               class="btn btn-excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
        </div>
    </div>

    <!-- TAB BAR -->
    <div class="tab-bar">
        <button class="tab-btn <?= $report_type=='summary'?'active':'' ?>"  data-tab="summary"><i class="fas fa-chart-pie"></i><span>Summary</span></button>
        <button class="tab-btn <?= $report_type=='detailed'?'active':'' ?>" data-tab="detailed"><i class="fas fa-list"></i><span>Detailed List</span></button>
        <button class="tab-btn <?= $report_type=='af1'?'active':'' ?>"      data-tab="af1"><i class="fas fa-file-alt"></i><span>AF1 Form</span></button>
    </div>

    <!-- CONTENT -->
    <div id="contentContainer">
        <?php
        if ($report_type=='summary')       echo getSummaryContent();
        elseif ($report_type=='detailed')  echo getDetailedContent();
        else                               echo getAF1Content();
        ?>
    </div>

</div>

<!-- ════════════ CHART DATA ════════════ -->
<script>
const CHART_DATA = {
    age:   { labels: <?= json_encode($chart_age_labels) ?>,              data: <?= json_encode($chart_age_data) ?> },
    gender:{ data:   <?= json_encode($chart_gender_data) ?> },
    bar:   { labels: <?= json_encode(array_values($chart_bar_labels)) ?>, data: <?= json_encode(array_values($chart_bar_data)) ?> },
    prog:  { labels: <?= json_encode($chart_prog_labels) ?>,             data: <?= json_encode($chart_prog_data) ?> },
    teach: { labels: <?= json_encode(array_values($chart_teach_labels)) ?>, data: <?= json_encode(array_values($chart_teach_data)) ?> },
};
const COLORS = { blue:'#3b5bdb',cyan:'#0ea5e9',rose:'#e11d48',green:'#10b981',amber:'#f59e0b',purple:'#7c3aed',indigo:'#4f46e5',teal:'#14b8a6' };
const palette = Object.values(COLORS);
Chart.defaults.font.family = "'DM Sans',sans-serif";
Chart.defaults.color = '#4a505f';
let charts = {};
function destroyCharts(){ Object.values(charts).forEach(c=>c.destroy()); charts={}; }
function buildCharts(){
    destroyCharts();
    const mk=(el,cfg)=>{ const e=document.getElementById(el); if(e) return new Chart(e,cfg); };
    charts.age = mk('ageChart',{type:'bar',data:{labels:CHART_DATA.age.labels,datasets:[{label:'Students',data:CHART_DATA.age.data,backgroundColor:[COLORS.blue,COLORS.cyan,COLORS.indigo,COLORS.teal].map(c=>c+'cc'),borderColor:[COLORS.blue,COLORS.cyan,COLORS.indigo,COLORS.teal],borderWidth:2,borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#e4e7ef'}},x:{grid:{display:false}}}}});
    charts.gender = mk('genderChart',{type:'doughnut',data:{labels:['Male','Female'],datasets:[{data:CHART_DATA.gender.data,backgroundColor:[COLORS.cyan+'cc',COLORS.rose+'cc'],borderColor:[COLORS.cyan,COLORS.rose],borderWidth:2,hoverOffset:8}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom',labels:{padding:16,usePointStyle:true,pointStyleWidth:12}}}}});
    if(CHART_DATA.bar.labels.length) charts.barangay = mk('barangayChart',{type:'bar',data:{labels:CHART_DATA.bar.labels,datasets:[{label:'Students',data:CHART_DATA.bar.data,backgroundColor:CHART_DATA.bar.data.map((_,i)=>palette[i%palette.length]+'bb'),borderColor:CHART_DATA.bar.data.map((_,i)=>palette[i%palette.length]),borderWidth:2,borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#e4e7ef'}},y:{grid:{display:false},ticks:{font:{size:12}}}}}});
    charts.program = mk('programChart',{type:'pie',data:{labels:CHART_DATA.prog.labels.map(l=>l.replace('a&e','A&E').replace('als-shs','ALS-SHS').replace(/\b\w/g,c=>c.toUpperCase())),datasets:[{data:CHART_DATA.prog.data,backgroundColor:[COLORS.blue,COLORS.green,COLORS.amber,COLORS.purple].map(c=>c+'cc'),borderColor:[COLORS.blue,COLORS.green,COLORS.amber,COLORS.purple],borderWidth:2,hoverOffset:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{padding:14,usePointStyle:true,pointStyleWidth:10,font:{size:11}}}}}});
    if(CHART_DATA.teach.labels.length) charts.teacher = mk('teacherChart',{type:'bar',data:{labels:CHART_DATA.teach.labels,datasets:[{label:'Students',data:CHART_DATA.teach.data,backgroundColor:CHART_DATA.teach.data.map((_,i)=>palette[i%palette.length]+'bb'),borderColor:CHART_DATA.teach.data.map((_,i)=>palette[i%palette.length]),borderWidth:2,borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{stepSize:1},grid:{color:'#e4e7ef'}},y:{grid:{display:false},ticks:{font:{size:12}}}}}});
}

let loading = false;
function showLoading(){ document.getElementById('loadingOverlay').style.display='flex'; }
function hideLoading(){ document.getElementById('loadingOverlay').style.display='none'; }

// ── KEY FIX: collect current filter values from the form selects ──────────
function getCurrentFilters(){
    return {
        sy:       document.getElementById('sy').value,
        barangay: document.getElementById('barangay').value,
        teacher:  document.getElementById('teacher').value,
        type:     document.getElementById('type').value,
    };
}

// ── Rebuild all download links using live filter values ───────────────────
function refreshDownloadLinks(filters){
    const qs = new URLSearchParams();
    if (filters.sy) qs.set('sy', filters.sy);
    if (filters.barangay) qs.set('barangay', filters.barangay);
    if (filters.teacher) qs.set('teacher', filters.teacher);

    // Excel button
    const excelBtn = document.getElementById('excelBtn');
    if(excelBtn){
        const p = new URLSearchParams(qs);
        p.set('generate','1'); 
        p.set('format','excel');
        excelBtn.href = '/AdminReports?' + p.toString();
    }

    // ALS Form links inside dropdown
    document.querySelectorAll('.dd-item').forEach(a => {
        const p = new URLSearchParams(qs);
        const formCode = a.getAttribute('data-form');
        if (formCode) {
            p.set('form', formCode);
            a.href = '/GenerateALS?' + p.toString();
        }
    });
}

async function loadContent(params){
    if(loading) return;
    loading=true; showLoading();
    try {
        const u = new URLSearchParams();
        if (params.sy) u.set('sy', params.sy);
        if (params.barangay) u.set('barangay', params.barangay);
        if (params.teacher) u.set('teacher', params.teacher);
        if (params.type) u.set('type', params.type);
        if (params.page) u.set('page', params.page);
        u.set('ajax','1');
        
        const r = await fetch('/AdminReports?'+u.toString());
        if(!r.ok) throw new Error('HTTP '+r.status);
        document.getElementById('contentContainer').innerHTML = await r.text();
        u.delete('ajax');
        window.history.pushState({},'','/AdminReports?'+u.toString());
        updateUI(params);
        reattach();
        if((params.type||'summary')==='summary') setTimeout(buildCharts,80);
        // After any content load, update download links with current state
        refreshDownloadLinks(getCurrentFilters());
    } catch(e){ console.error(e); }
    finally{ loading=false; hideLoading(); }
}

function updateUI(p){
    const titles={summary:'Summary Report',detailed:'Detailed Enrollment Report',af1:'AF1 Form Report'};
    const rt = document.getElementById('reportTitle');
    if(rt) rt.textContent = titles[p.type||'summary'];
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.toggle('active',b.dataset.tab===(p.type||'summary')));
    ['sy','barangay','teacher','type'].forEach(id=>{ const el=document.getElementById(id); if(el&&p[id]!=null) el.value=p[id]; });
}

function reattach(){
    document.querySelectorAll('.pag-link').forEach(a=>a.addEventListener('click',onPagClick));
    document.querySelectorAll('.pag-jump').forEach(s=>s.addEventListener('change',onPagJump));
}

function onPagClick(e){
    e.preventDefault();
    if(this.closest('.disabled')) return;
    loadContent({...getCurrentFilters(), page:this.dataset.page});
}
function onPagJump(e){
    loadContent({...getCurrentFilters(), page:e.target.value});
}

// Remove a single filter pill
function clearFilter(key){
    document.getElementById(key).value = '';
    const filters = getCurrentFilters();
    loadContent({...filters, page:'1'});
}

document.addEventListener('DOMContentLoaded',()=>{

    // Filter form submit
    document.getElementById('filterForm').addEventListener('submit',function(e){
        e.preventDefault();
        const filters = getCurrentFilters();
        refreshDownloadLinks(filters);
        loadContent({...filters, page:'1'});
    });

    // Reset
    document.getElementById('resetBtn').addEventListener('click',()=>{
        ['sy','barangay','teacher'].forEach(id=>document.getElementById(id).value='');
        document.getElementById('type').value='summary';
        const filters = getCurrentFilters();
        refreshDownloadLinks(filters);
        loadContent({...filters, page:'1'});
    });

    // Tabs
    document.querySelectorAll('.tab-btn').forEach(btn=>{
        btn.addEventListener('click',function(){
            const filters = getCurrentFilters();
            filters.type = this.dataset.tab;
            document.getElementById('type').value = this.dataset.tab;
            refreshDownloadLinks(filters);
            loadContent({...filters, page:'1'});
        });
    });

    // ALS dropdown toggle
    const dropBtn=document.getElementById('alsDropBtn');
    const dropMenu=document.getElementById('alsMenu');
    dropBtn.addEventListener('click',e=>{e.stopPropagation();dropMenu.classList.toggle('open');});
    document.addEventListener('click',()=>dropMenu.classList.remove('open'));

    // Sync download links with whatever filters are set when change events fire
    ['sy','barangay','teacher','type'].forEach(id=>{
        const el = document.getElementById(id);
        if(el) el.addEventListener('change', ()=> refreshDownloadLinks(getCurrentFilters()));
    });

    reattach();
    if(document.querySelector('.tab-btn.active')?.dataset.tab==='summary') setTimeout(buildCharts,120);
    window.addEventListener('popstate',()=>{
        const u=new URLSearchParams(window.location.search);
        const p={sy:u.get('sy')||'',barangay:u.get('barangay')||'',teacher:u.get('teacher')||'',type:u.get('type')||'summary',page:u.get('page')||'1'};
        loadContent(p);
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>