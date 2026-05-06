<?php
$sub_menu = '950182';
require_once './_common.php';

$g5['title'] = '아이이음 차량 탑승 확인';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$message = '';
$error = '';

$journal_date = isset($_REQUEST['journal_date']) ? preg_replace('/[^0-9-]/', '', trim($_REQUEST['journal_date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
    $journal_date = G5_TIME_YMD;
}
$ride_type = isset($_REQUEST['ride_type']) ? preg_replace('/[^a-z]/', '', trim($_REQUEST['ride_type'])) : 'pickup';
if ($ride_type !== 'pickup' && $ride_type !== 'dropoff') {
    $ride_type = 'pickup';
}
$route_id = isset($_REQUEST['route_id']) ? (int) $_REQUEST['route_id'] : 0;
$weekday_keys = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
$weekday = $weekday_keys[(int) date('w', strtotime($journal_date))];
$status_labels = array(
    'boarded' => '탑승',
    'missed' => '미탑승',
    'called' => '보호자 통화',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $student_vehicle_id = isset($_POST['student_vehicle_id']) ? (int) $_POST['student_vehicle_id'] : 0;
        $status = isset($_POST['status']) ? preg_replace('/[^a-z]/', '', trim($_POST['status'])) : '';
        $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        if (!isset($status_labels[$status])) {
            $error = '탑승 상태를 선택하세요.';
        } else {
            $vehicle = sql_fetch("
                select *
                  from " . IEUM_STUDENT_VEHICLE_TABLE . "
                 where academy_id = '{$academy_id}'
                   and student_vehicle_id = '{$student_vehicle_id}'
                   and is_active = 1
                 limit 1
            ", false);
            if (!isset($vehicle['student_vehicle_id'])) {
                $error = '차량 배정 정보를 찾을 수 없습니다.';
            } else {
                $status_sql = sql_escape_string($status);
                $note_sql = sql_escape_string($note);
                $checked_by_sql = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
                $ride_type_sql = sql_escape_string($vehicle['ride_type']);
                sql_query("
                    insert into " . IEUM_VEHICLE_BOARDING_TABLE . "
                        set academy_id = '{$academy_id}',
                            journal_date = '" . sql_escape_string($journal_date) . "',
                            student_vehicle_id = '{$student_vehicle_id}',
                            student_id = '" . (int) $vehicle['student_id'] . "',
                            ride_type = '{$ride_type_sql}',
                            route_id = '" . (int) $vehicle['route_id'] . "',
                            stop_id = '" . (int) $vehicle['stop_id'] . "',
                            status = '{$status_sql}',
                            note = '{$note_sql}',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            status = '{$status_sql}',
                            note = '{$note_sql}',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");
                $message = '탑승 확인이 저장되었습니다.';
            }
        }
    }
}

$csrf_token = ieum_new_csrf_token();
$weekday_sql = sql_escape_string($weekday);
$where = " sv.academy_id = '{$academy_id}' and sv.is_active = 1 and sv.ride_type = '" . sql_escape_string($ride_type) . "' and st.is_active = 1 and s.is_active = 1 and (sv.ride_days = '' or find_in_set('{$weekday_sql}', sv.ride_days)) ";
if ($route_id) {
    $where .= " and sv.route_id = '{$route_id}' ";
}

$routes = sql_query("
    select *
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, route_name asc
", false);

$rows = sql_query("
    select sv.student_vehicle_id, sv.ride_type, sv.place_name, sv.contact_phone, sv.memo as vehicle_memo,
           s.student_name, s.grade_group, s.memo as student_memo,
           c.class_name, c.start_time as class_start_time,
           st.stop_name, st.stop_time,
           r.route_name, r.vehicle_label, r.driver_name, r.driver_phone,
           bl.status as boarding_status, bl.note as boarding_note, bl.checked_at
      from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_BOARDING_TABLE . " bl on bl.academy_id = sv.academy_id and bl.student_vehicle_id = sv.student_vehicle_id and bl.journal_date = '" . sql_escape_string($journal_date) . "'
     where {$where}
  order by r.sort_order asc, st.stop_time asc, st.sort_order asc, st.stop_name asc, s.student_name asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:760px;margin:18px auto;padding:0 14px}h1{margin:0 0 6px;font-size:24px}.meta{color:#667085;margin-bottom:14px}.filter{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;margin-bottom:12px}.filter input,.filter select,.note{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:15px}.btn{border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:10px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.notice{padding:10px 12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.stop{margin:14px 0 8px;padding:9px 10px;background:#15204a;color:#fff;border-radius:8px;font-weight:900}.card{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:12px;margin-bottom:10px;box-shadow:0 4px 12px rgba(15,23,42,.05)}.student{display:flex;justify-content:space-between;gap:10px;font-size:18px;font-weight:900}.student small{font-size:13px;color:#667085}.info{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:6px;color:#344054;font-size:14px}.phone{font-weight:900;white-space:nowrap}.memo{margin-top:6px;color:#667085;font-size:13px}.actions{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin-top:10px}.actions button{min-height:42px}.status{display:inline-flex;margin-top:8px;padding:5px 8px;border-radius:999px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.status.boarded{background:#e8f7ee;color:#176b2c}.status.missed{background:#fdecec;color:#a4262c}.status.called{background:#fff4df;color:#915c00}.empty{padding:28px;text-align:center;color:#667085;background:#fff;border:1px solid #d9dee7;border-radius:10px}@media(max-width:680px){.filter{grid-template-columns:1fr 1fr}.filter .primary{grid-column:1/-1}.info{grid-template-columns:1fr}.actions{grid-template-columns:1fr}.top{display:none}.wrap{margin-top:12px}}
</style>
</head>
<body>
<?php echo ieum_admin_header('boarding'); ?>
<main class="wrap">
    <h1>차량 탑승 확인</h1>
    <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($journal_date); ?></div>
    <?php if ($message) { ?><p class="notice ok"><?php echo get_text($message); ?></p><?php } ?>
    <?php if ($error) { ?><p class="notice err"><?php echo get_text($error); ?></p><?php } ?>
    <form method="get" class="filter">
        <input type="date" name="journal_date" value="<?php echo get_text($journal_date); ?>">
        <select name="ride_type">
            <option value="pickup" <?php echo get_selected($ride_type, 'pickup'); ?>>등원</option>
            <option value="dropoff" <?php echo get_selected($ride_type, 'dropoff'); ?>>하원</option>
        </select>
        <select name="route_id">
            <option value="0">전체 노선</option>
            <?php while ($route = sql_fetch_array($routes)) { ?>
            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected($route_id, (int) $route['route_id']); ?>><?php echo get_text($route['route_name']); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">조회</button>
    </form>
    <?php
    $current_stop = '';
    $has_rows = false;
    while ($row = sql_fetch_array($rows)) {
        $has_rows = true;
        $stop_key = $row['stop_time'] . '|' . $row['stop_name'];
        if ($current_stop !== $stop_key) {
            $current_stop = $stop_key;
            echo '<div class="stop">' . get_text($row['stop_time'] . ' ' . $row['stop_name']) . '</div>';
        }
        $status = isset($row['boarding_status']) ? $row['boarding_status'] : '';
        $status_label = isset($status_labels[$status]) ? $status_labels[$status] : '미확인';
        $memo = trim(($row['vehicle_memo'] ?: $row['place_name']) . ($row['student_memo'] ? ' / ' . $row['student_memo'] : ''));
    ?>
    <section class="card">
        <div class="student"><span><?php echo get_text($row['student_name']); ?></span><small><?php echo get_text($row['grade_group']); ?></small></div>
        <div class="info">
            <span><?php echo get_text(trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''))); ?></span>
            <span class="phone"><?php echo get_text($row['contact_phone']); ?></span>
        </div>
        <?php if ($memo !== '') { ?><div class="memo"><?php echo get_text($memo); ?></div><?php } ?>
        <span class="status <?php echo get_text($status); ?>"><?php echo get_text($status_label . ($row['checked_at'] ? ' · ' . substr($row['checked_at'], 11, 5) : '')); ?></span>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <input class="note" type="text" name="note" value="<?php echo get_text(isset($row['boarding_note']) ? $row['boarding_note'] : ''); ?>" placeholder="메모: 연락 안 됨, 다음 차 탑승 등">
            <div class="actions">
                <button class="btn primary" type="submit" name="status" value="boarded">탑승</button>
                <button class="btn" type="submit" name="status" value="missed">미탑승</button>
                <button class="btn" type="submit" name="status" value="called">보호자 통화</button>
            </div>
        </form>
    </section>
    <?php } ?>
    <?php if (!$has_rows) { ?><div class="empty">오늘 조건에 맞는 차량 이용 학생이 없습니다.</div><?php } ?>
</main>
</body>
</html>
