<?php
$sub_menu = '950182';
require_once './_common.php';
require_once IEUM_PATH . '/lib/program.php';

$g5['title'] = '아이이음 차량 배정 현황';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];

function ieum_vehicle_assign_grade_label($value)
{
    $labels = array(
        '' => '미지정',
        'kindergarten' => '유치부',
        'elementary_1' => '초등 1학년',
        'elementary_2' => '초등 2학년',
        'elementary_3' => '초등 3학년',
        'elementary_4' => '초등 4학년',
        'elementary_5' => '초등 5학년',
        'elementary_6' => '초등 6학년',
        'middle_1' => '중등 1학년',
        'middle_2' => '중등 2학년',
        'middle_3' => '중등 3학년',
        'high_1' => '고등 1학년',
        'high_2' => '고등 2학년',
        'high_3' => '고등 3학년',
        'adult' => '성인부',
        'jump_rope' => '줄넘기부',
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_vehicle_assign_status_label($value)
{
    $labels = array(
        'enrolled' => '재원',
        'trial' => '체험',
        'paused' => '휴관',
        'returned' => '복귀',
        'withdrawn' => '퇴관',
        'waiting' => '대기',
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_vehicle_assign_text($row, $prefix)
{
    $route_id = isset($row[$prefix . '_route_id']) ? (int) $row[$prefix . '_route_id'] : 0;
    $stop_id = isset($row[$prefix . '_stop_id']) ? (int) $row[$prefix . '_stop_id'] : 0;
    if (!$route_id && !$stop_id) {
        return '-';
    }

    $parts = array();
    if (!empty($row[$prefix . '_vehicle_label'])) {
        $parts[] = $row[$prefix . '_vehicle_label'];
    }
    if (!empty($row[$prefix . '_route_name'])) {
        $parts[] = $row[$prefix . '_route_name'];
    }
    $stop = trim((isset($row[$prefix . '_stop_time']) ? $row[$prefix . '_stop_time'] : '') . ' ' . (isset($row[$prefix . '_stop_name']) ? $row[$prefix . '_stop_name'] : ''));
    if ($stop !== '') {
        $parts[] = $stop;
    } elseif (!empty($row[$prefix . '_place_name'])) {
        $parts[] = $row[$prefix . '_place_name'];
    }

    return $parts ? implode(' / ', $parts) : '-';
}

$filter_program = isset($_GET['program_code']) ? ieum_program_code($_GET['program_code']) : '';
$filter_class_time_id = isset($_GET['class_time_id']) ? (int) $_GET['class_time_id'] : 0;
$filter_vehicle_label = isset($_GET['vehicle_label']) ? trim($_GET['vehicle_label']) : '';
$filter_assignment = isset($_GET['assignment']) ? preg_replace('/[^a-z_]/', '', trim($_GET['assignment'])) : '';
if (!in_array($filter_assignment, array('', 'both', 'pickup_only', 'dropoff_only', 'none'), true)) {
    $filter_assignment = '';
}

$program_options = ieum_program_options($academy_id, true);
$program_labels = array();
foreach ($program_options as $program) {
    $program_labels[$program['program_code']] = $program['program_name'];
}

$class_times = array();
$class_result = sql_query("
    select class_time_id, class_name, start_time
      from " . IEUM_CLASS_TIME_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, start_time asc, class_time_id asc
", false);
while ($class = sql_fetch_array($class_result)) {
    $class_times[] = $class;
}

$vehicle_labels = array();
$vehicle_label_result = sql_query("
    select distinct vehicle_label
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and vehicle_label <> ''
  order by vehicle_label asc
", false);
while ($vehicle = sql_fetch_array($vehicle_label_result)) {
    $vehicle_labels[] = $vehicle['vehicle_label'];
}

$route_summary = array();
$route_where = "academy_id = '{$academy_id}' and is_active = 1";
if ($filter_vehicle_label !== '') {
    $route_where .= " and vehicle_label = '" . sql_escape_string($filter_vehicle_label) . "'";
}
$route_result = sql_query("
    select route_id, route_name, vehicle_label, route_type
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where {$route_where}
  order by vehicle_label asc, sort_order asc, route_name asc
", false);
while ($route = sql_fetch_array($route_result)) {
    $route_summary[(int) $route['route_id']] = array(
        'route_id' => (int) $route['route_id'],
        'route_name' => $route['route_name'],
        'vehicle_label' => $route['vehicle_label'],
        'route_type' => $route['route_type'],
        'pickup' => 0,
        'dropoff' => 0,
    );
}

$where = "s.academy_id = '{$academy_id}' and s.is_active = 1 and ifnull(s.student_status, 'enrolled') <> 'withdrawn'";
if ($filter_program !== '') {
    $where .= " and s.program_code = '" . sql_escape_string($filter_program) . "'";
}
if ($filter_class_time_id > 0) {
    $where .= " and s.class_time_id = '{$filter_class_time_id}'";
}
if ($filter_vehicle_label !== '') {
    $vehicle_sql = sql_escape_string($filter_vehicle_label);
    $where .= " and (pr.vehicle_label = '{$vehicle_sql}' or dr.vehicle_label = '{$vehicle_sql}')";
}

$student_result = sql_query("
    select s.student_id, s.student_code, s.student_name, s.student_status, s.program_code, s.grade_group, s.student_phone,
           c.class_name, c.start_time as class_start_time,
           p.student_vehicle_id as pickup_vehicle_id, p.route_id as pickup_route_id, p.stop_id as pickup_stop_id,
           p.place_name as pickup_place_name, p.memo as pickup_memo, p.ride_days as pickup_ride_days,
           ps.stop_name as pickup_stop_name, ps.stop_time as pickup_stop_time,
           pr.route_name as pickup_route_name, pr.vehicle_label as pickup_vehicle_label,
           d.student_vehicle_id as dropoff_vehicle_id, d.route_id as dropoff_route_id, d.stop_id as dropoff_stop_id,
           d.place_name as dropoff_place_name, d.memo as dropoff_memo, d.ride_days as dropoff_ride_days,
           ds.stop_name as dropoff_stop_name, ds.stop_time as dropoff_stop_time,
           dr.route_name as dropoff_route_name, dr.vehicle_label as dropoff_vehicle_label
      from " . IEUM_STUDENT_TABLE . " s
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
 left join " . IEUM_STUDENT_VEHICLE_TABLE . " p on p.student_id = s.student_id and p.academy_id = s.academy_id and p.ride_type = 'pickup' and p.is_active = 1
 left join " . IEUM_VEHICLE_STOP_TABLE . " ps on ps.stop_id = p.stop_id and ps.academy_id = p.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " pr on pr.route_id = p.route_id and pr.academy_id = p.academy_id
 left join " . IEUM_STUDENT_VEHICLE_TABLE . " d on d.student_id = s.student_id and d.academy_id = s.academy_id and d.ride_type = 'dropoff' and d.is_active = 1
 left join " . IEUM_VEHICLE_STOP_TABLE . " ds on ds.stop_id = d.stop_id and ds.academy_id = d.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " dr on dr.route_id = d.route_id and dr.academy_id = d.academy_id
     where {$where}
  order by c.sort_order asc, c.start_time asc, s.student_name asc, s.student_code asc
", false);

$students = array();
$summary = array(
    'total' => 0,
    'both' => 0,
    'pickup_only' => 0,
    'dropoff_only' => 0,
    'none' => 0,
);
while ($row = sql_fetch_array($student_result)) {
    $has_pickup = !empty($row['pickup_route_id']) || !empty($row['pickup_stop_id']) || !empty($row['pickup_place_name']);
    $has_dropoff = !empty($row['dropoff_route_id']) || !empty($row['dropoff_stop_id']) || !empty($row['dropoff_place_name']);
    if ($has_pickup && $has_dropoff) {
        $assignment_status = 'both';
    } elseif ($has_pickup) {
        $assignment_status = 'pickup_only';
    } elseif ($has_dropoff) {
        $assignment_status = 'dropoff_only';
    } else {
        $assignment_status = 'none';
    }

    if ($filter_assignment !== '' && $assignment_status !== $filter_assignment) {
        continue;
    }

    $row['assignment_status'] = $assignment_status;
    $students[] = $row;
    $summary['total']++;
    $summary[$assignment_status]++;

    $pickup_route_id = (int) $row['pickup_route_id'];
    if ($pickup_route_id && isset($route_summary[$pickup_route_id])) {
        $route_summary[$pickup_route_id]['pickup']++;
    }
    $dropoff_route_id = (int) $row['dropoff_route_id'];
    if ($dropoff_route_id && isset($route_summary[$dropoff_route_id])) {
        $route_summary[$dropoff_route_id]['dropoff']++;
    }
}

$assignment_labels = array(
    'both' => '등원+하원',
    'pickup_only' => '등원만',
    'dropoff_only' => '하원만',
    'none' => '미배정',
);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1220px;margin:28px auto;padding:0 20px}.hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:18px;flex-wrap:wrap}h1{margin:0;font-size:30px}.meta{color:#667085;margin-top:6px}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn,select{display:inline-flex;align-items:center;justify-content:center;min-height:40px;border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:9px 12px;font-weight:800}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;margin-bottom:18px;box-shadow:0 8px 20px rgba(15,23,42,.05)}.filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}.card{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.05)}.card span{display:block;color:#667085;font-size:13px;font-weight:800}.card strong{display:block;margin-top:5px;font-size:28px}.card.warn{border-color:#f4c27a;background:#fffaf0}.route-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.route-card{border:1px solid #d9dee7;border-radius:8px;padding:14px;background:#fff}.route-card h3{margin:0 0 8px;font-size:17px}.route-card .muted{color:#667085;font-size:13px}.route-counts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}.route-counts div{border-radius:8px;background:#f4f7fb;padding:10px}.route-counts span{display:block;color:#667085;font-size:12px;font-weight:800}.route-counts strong{font-size:22px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:980px}th,td{border:1px solid #d8dee9;padding:10px;text-align:center;font-size:14px;vertical-align:middle}th{background:#72829d;color:#fff}.left{text-align:left}.badge{display:inline-flex;align-items:center;justify-content:center;min-width:76px;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900}.badge.both{background:#e8f5ef;color:#087443}.badge.pickup_only{background:#eaf2ff;color:#1769c2}.badge.dropoff_only{background:#fff4e5;color:#9a5b00}.badge.none{background:#feecec;color:#a4262c}.student-name{font-weight:900}.sub{display:block;color:#667085;font-size:12px;margin-top:3px}.vehicle-text{font-weight:800}.vehicle-memo{display:block;color:#667085;font-size:12px;margin-top:3px}.empty{padding:28px;text-align:center;color:#667085}.quick{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.quick a{display:inline-flex;border:1px solid #d9dee7;border-radius:999px;padding:6px 10px;text-decoration:none;color:#344054;background:#f8fafc;font-size:13px;font-weight:800}.quick a.active{background:#1769c2;border-color:#1769c2;color:#fff}@media(max-width:980px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.route-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.wrap{padding:0 14px}.cards,.route-grid{grid-template-columns:1fr}.hero{align-items:flex-start}.actions .btn{width:100%}.filter select,.filter .btn{width:100%}}
</style>
</head>
<body>
<?php echo ieum_admin_header('vehicle_assignments'); ?>
<?php echo ieum_admin_subnav('vehicle_assignments'); ?>
<main class="wrap">
    <div class="hero">
        <div>
            <h1>차량 배정 현황</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · 호차별 등원/하원 배정 확인</div>
        </div>
        <div class="actions">
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicles.php">차량 관리</a>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_journal.php">차량 일지</a>
            <a class="btn primary" href="<?php echo IEUM_URL; ?>/admin/students.php">학생 관리</a>
        </div>
    </div>

    <section class="panel">
        <form method="get" class="filter">
            <select name="program_code">
                <option value="">전체 프로그램</option>
                <?php foreach ($program_options as $program) { ?>
                <option value="<?php echo get_text($program['program_code']); ?>" <?php echo get_selected($filter_program, $program['program_code']); ?>><?php echo get_text($program['program_name']); ?></option>
                <?php } ?>
            </select>
            <select name="class_time_id">
                <option value="0">전체 부</option>
                <?php foreach ($class_times as $class) { ?>
                <option value="<?php echo (int) $class['class_time_id']; ?>" <?php echo get_selected($filter_class_time_id, (int) $class['class_time_id']); ?>><?php echo get_text(trim($class['class_name'] . ' ' . $class['start_time'])); ?></option>
                <?php } ?>
            </select>
            <select name="vehicle_label">
                <option value="">전체 호차</option>
                <?php foreach ($vehicle_labels as $vehicle_label) { ?>
                <option value="<?php echo get_text($vehicle_label); ?>" <?php echo get_selected($filter_vehicle_label, $vehicle_label); ?>><?php echo get_text($vehicle_label); ?></option>
                <?php } ?>
            </select>
            <select name="assignment">
                <option value="">전체 배정</option>
                <?php foreach ($assignment_labels as $key => $label) { ?>
                <option value="<?php echo get_text($key); ?>" <?php echo get_selected($filter_assignment, $key); ?>><?php echo get_text($label); ?></option>
                <?php } ?>
            </select>
            <button type="submit" class="btn primary">조회</button>
            <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicle_assignments.php">초기화</a>
        </form>
        <div class="quick">
            <?php foreach ($assignment_labels as $key => $label) {
                $query = $_GET;
                $query['assignment'] = $key;
            ?>
            <a class="<?php echo $filter_assignment === $key ? 'active' : ''; ?>" href="<?php echo IEUM_URL; ?>/admin/vehicle_assignments.php?<?php echo http_build_query($query); ?>"><?php echo get_text($label); ?> <?php echo number_format((int) $summary[$key]); ?>명</a>
            <?php } ?>
        </div>
    </section>

    <section class="cards">
        <article class="card"><span>조회 학생</span><strong><?php echo number_format((int) $summary['total']); ?>명</strong></article>
        <article class="card"><span>등원+하원</span><strong><?php echo number_format((int) $summary['both']); ?>명</strong></article>
        <article class="card"><span>등원만</span><strong><?php echo number_format((int) $summary['pickup_only']); ?>명</strong></article>
        <article class="card"><span>하원만</span><strong><?php echo number_format((int) $summary['dropoff_only']); ?>명</strong></article>
        <article class="card warn"><span>미배정</span><strong><?php echo number_format((int) $summary['none']); ?>명</strong></article>
    </section>

    <section class="panel">
        <h2>호차/노선별 배정</h2>
        <div class="route-grid">
            <?php foreach ($route_summary as $route) { ?>
            <article class="route-card">
                <h3><?php echo get_text(trim(($route['vehicle_label'] ?: '호차 미지정') . ' · ' . $route['route_name'])); ?></h3>
                <div class="muted"><?php echo get_text($route['route_type'] === 'pickup' ? '등원 전용' : ($route['route_type'] === 'dropoff' ? '하원 전용' : '등원+하원')); ?></div>
                <div class="route-counts">
                    <div><span>등원</span><strong><?php echo number_format((int) $route['pickup']); ?>명</strong></div>
                    <div><span>하원</span><strong><?php echo number_format((int) $route['dropoff']); ?>명</strong></div>
                </div>
            </article>
            <?php } ?>
            <?php if (!$route_summary) { ?><div class="empty">등록된 차량 노선이 없습니다.</div><?php } ?>
        </div>
    </section>

    <section class="panel">
        <h2>학생별 배정 상태</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>상태</th>
                        <th>학생</th>
                        <th>프로그램/학년</th>
                        <th>수업 부</th>
                        <th>등원 차량</th>
                        <th>하원 차량</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $row) {
                        $status = $row['assignment_status'];
                        $program_label = isset($program_labels[$row['program_code']]) ? $program_labels[$row['program_code']] : $row['program_code'];
                        $class_label = trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''));
                    ?>
                    <tr>
                        <td><span class="badge <?php echo get_text($status); ?>"><?php echo get_text($assignment_labels[$status]); ?></span></td>
                        <td class="left">
                            <span class="student-name"><?php echo get_text($row['student_name']); ?></span>
                            <span class="sub"><?php echo get_text($row['student_code'] . ' · ' . ieum_vehicle_assign_status_label($row['student_status'])); ?></span>
                        </td>
                        <td><?php echo get_text(trim($program_label . ' / ' . ieum_vehicle_assign_grade_label($row['grade_group']))); ?></td>
                        <td><?php echo get_text($class_label !== '' ? $class_label : '미지정'); ?></td>
                        <td class="left">
                            <span class="vehicle-text"><?php echo get_text(ieum_vehicle_assign_text($row, 'pickup')); ?></span>
                            <?php if ($row['pickup_memo'] !== '') { ?><span class="vehicle-memo"><?php echo get_text($row['pickup_memo']); ?></span><?php } ?>
                        </td>
                        <td class="left">
                            <span class="vehicle-text"><?php echo get_text(ieum_vehicle_assign_text($row, 'dropoff')); ?></span>
                            <?php if ($row['dropoff_memo'] !== '') { ?><span class="vehicle-memo"><?php echo get_text($row['dropoff_memo']); ?></span><?php } ?>
                        </td>
                        <td><a class="btn" href="<?php echo IEUM_URL; ?>/admin/students.php?mode=form&student_id=<?php echo (int) $row['student_id']; ?>">수정</a></td>
                    </tr>
                    <?php } ?>
                    <?php if (!$students) { ?>
                    <tr><td colspan="7" class="empty">조건에 맞는 학생이 없습니다.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
