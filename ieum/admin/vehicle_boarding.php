<?php
$sub_menu = '950182';
require_once './_common.php';
require_once IEUM_PATH . '/lib/sms_queue.php';

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
$vehicle_label = isset($_REQUEST['vehicle_label']) ? trim($_REQUEST['vehicle_label']) : '';
$weekday_keys = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
$weekday = $weekday_keys[(int) date('w', strtotime($journal_date))];
$status_labels = array(
    'boarded' => '탑승',
    'missed' => '미탑승',
    'called' => '보호자 통화',
);

function ieum_boarding_grade_label($value)
{
    $labels = array(
        'kindergarten' => '유치부',
        'elementary_1' => '초등/1학년',
        'elementary_2' => '초등/2학년',
        'elementary_3' => '초등/3학년',
        'elementary_4' => '초등/4학년',
        'elementary_5' => '초등/5학년',
        'elementary_6' => '초등/6학년',
        'middle_1' => '중등/1학년',
        'middle_2' => '중등/2학년',
        'middle_3' => '중등/3학년',
        'high_1' => '고등/1학년',
        'high_2' => '고등/2학년',
        'high_3' => '고등/3학년',
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

function ieum_boarding_ensure_stop_location_columns()
{
    $columns = array(
        'stop_address' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add stop_address varchar(160) not null default '' after stop_name",
        'map_lat' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add map_lat decimal(10,7) null after stop_address",
        'map_lng' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add map_lng decimal(10,7) null after map_lat",
        'map_url' => "alter table " . IEUM_VEHICLE_STOP_TABLE . " add map_url varchar(255) not null default '' after map_lng",
    );
    foreach ($columns as $column => $sql) {
        $exists = sql_fetch("show columns from " . IEUM_VEHICLE_STOP_TABLE . " like '" . sql_escape_string($column) . "'", false);
        if (empty($exists['Field'])) {
            sql_query($sql, false);
        }
    }
}

function ieum_boarding_stop_map_href($row)
{
    $map_url = isset($row['map_url']) ? trim($row['map_url']) : '';
    if ($map_url !== '') {
        return $map_url;
    }
    $query = isset($row['stop_address']) && trim($row['stop_address']) !== '' ? trim($row['stop_address']) : (isset($row['stop_name']) ? trim($row['stop_name']) : '');
    return $query !== '' ? 'https://map.naver.com/v5/search/' . rawurlencode($query) : '';
}

ieum_boarding_ensure_stop_location_columns();

function ieum_create_vehicle_alert_queue($academy_id, $vehicle, $status_label, $note)
{
    $academy_id = (int) $academy_id;
    $student_id = (int) $vehicle['student_id'];
    $route_label = trim(($vehicle['vehicle_label'] ?: '차량') . ' ' . ($vehicle['route_name'] ?: ''));
    $message = '[아이이음 차량] ' . $vehicle['student_name'] . ' ' . $status_label . ' - ' . $route_label;
    if (trim($note) !== '') {
        $message .= ' / ' . trim($note);
    }

    $contacts = sql_query("
        select contact_phone
          from " . IEUM_ACADEMY_CONTACT_TABLE . "
         where academy_id = '{$academy_id}'
           and is_active = 1
           and sms_system_alert = 1
           and contact_phone <> ''
      order by sort_order asc, contact_id asc
    ", false);

    while ($contact = sql_fetch_array($contacts)) {
        ieum_create_direct_sms_queue($academy_id, $contact['contact_phone'], $message, 'vehicle_alert', $student_id, 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = isset($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!ieum_verify_csrf_token($csrf_token)) {
        $error = '보안 토큰이 올바르지 않습니다.';
    } else {
        $action = isset($_POST['action']) ? trim($_POST['action']) : 'save_status';
        $student_vehicle_id = isset($_POST['student_vehicle_id']) ? (int) $_POST['student_vehicle_id'] : 0;
        $status = isset($_POST['status']) ? preg_replace('/[^a-z]/', '', trim($_POST['status'])) : '';
        $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        if ($action === 'resolve') {
            $resolved_by_sql = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
            sql_query("
                update " . IEUM_VEHICLE_BOARDING_TABLE . "
                   set resolved_by = '{$resolved_by_sql}',
                       resolved_at = '" . G5_TIME_YMDHIS . "',
                       updated_at = '" . G5_TIME_YMDHIS . "'
                 where academy_id = '{$academy_id}'
                   and journal_date = '" . sql_escape_string($journal_date) . "'
                   and student_vehicle_id = '{$student_vehicle_id}'
            ");
            $message = '차량 특이사항을 처리완료했습니다.';
        } elseif (!isset($status_labels[$status])) {
            $error = '탑승 상태를 선택하세요.';
        } else {
            $vehicle = sql_fetch("
                select sv.*, s.student_name, r.route_name, r.vehicle_label
                  from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
                  join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
             left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
                 where sv.academy_id = '{$academy_id}'
                   and sv.student_vehicle_id = '{$student_vehicle_id}'
                   and sv.is_active = 1
                 limit 1
            ", false);
            if (!isset($vehicle['student_vehicle_id'])) {
                $error = '차량 배정 정보를 찾을 수 없습니다.';
            } else {
                $status_sql = sql_escape_string($status);
                $note_sql = sql_escape_string($note);
                $checked_by_sql = sql_escape_string(isset($member['mb_id']) ? $member['mb_id'] : '');
                $ride_type_sql = sql_escape_string($vehicle['ride_type']);
                $before = sql_fetch("
                    select status, note
                      from " . IEUM_VEHICLE_BOARDING_TABLE . "
                     where academy_id = '{$academy_id}'
                       and journal_date = '" . sql_escape_string($journal_date) . "'
                       and student_vehicle_id = '{$student_vehicle_id}'
                     limit 1
                ", false);
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
                            resolved_by = '',
                            resolved_at = null,
                            created_at = '" . G5_TIME_YMDHIS . "'
                    on duplicate key update
                            status = '{$status_sql}',
                            note = '{$note_sql}',
                            checked_by = '{$checked_by_sql}',
                            checked_at = '" . G5_TIME_YMDHIS . "',
                            resolved_by = '',
                            resolved_at = null,
                            updated_at = '" . G5_TIME_YMDHIS . "'
                ");
                $is_new_alert = ($status === 'missed' || $status === 'called') && (!isset($before['status']) || $before['status'] !== $status || $before['note'] !== $note);
                if ($is_new_alert) {
                    ieum_create_vehicle_alert_queue($academy_id, $vehicle, $status_labels[$status], $note);
                }
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
if ($vehicle_label !== '') {
    $where .= " and r.vehicle_label = '" . sql_escape_string($vehicle_label) . "' ";
}

$vehicle_labels = sql_query("
    select distinct vehicle_label
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
       and vehicle_label <> ''
  order by vehicle_label asc
", false);

$route_where = " academy_id = '{$academy_id}' and is_active = 1 ";
if ($vehicle_label !== '') {
    $route_where .= " and vehicle_label = '" . sql_escape_string($vehicle_label) . "' ";
}
$routes = sql_query("
    select *
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where {$route_where}
  order by sort_order asc, route_name asc
", false);

$rows = sql_query("
    select sv.student_vehicle_id, sv.ride_type, sv.place_name, sv.contact_phone, sv.memo as vehicle_memo,
           s.student_name, s.grade_group, s.memo as student_memo,
           c.class_name, c.start_time as class_start_time,
            st.stop_name, st.stop_address, st.map_url, st.map_lat, st.map_lng, st.stop_time,
           r.route_name, r.vehicle_label, r.driver_name, r.driver_phone,
           bl.status as boarding_status, bl.note as boarding_note, bl.checked_at, bl.resolved_at
      from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_BOARDING_TABLE . " bl on bl.academy_id = sv.academy_id and bl.student_vehicle_id = sv.student_vehicle_id and bl.journal_date = '" . sql_escape_string($journal_date) . "'
     where {$where}
  order by r.sort_order asc, st.sort_order asc, st.stop_time asc, st.stop_name asc, s.student_name asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.top{background:#15204a;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}.ieum-brand{color:#fff;text-decoration:none;font-size:18px;font-weight:900}.ieum-nav{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.top a{color:#d8e2ff;text-decoration:none}.ieum-nav a{padding:8px 10px;border-radius:6px}.ieum-nav a.active,.ieum-nav a:hover{background:#253469;color:#fff}.ieum-user{margin-left:auto;color:#cbd5e1;font-size:13px}.wrap{max-width:760px;margin:18px auto;padding:0 14px}h1{margin:0 0 6px;font-size:24px}.meta{color:#667085;margin-bottom:14px}.filter{display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:8px;margin-bottom:12px}.filter input,.filter select,.note{width:100%;border:1px solid #cfd6df;border-radius:8px;padding:10px;font-size:15px}.btn{border:1px solid #cfd6df;border-radius:8px;background:#fff;color:#111827;text-decoration:none;padding:10px 12px;font-weight:900;cursor:pointer}.primary{background:#1769c2;border-color:#1769c2;color:#fff}.resolve{background:#0f766e;border-color:#0f766e;color:#fff}.notice{padding:10px 12px;border-radius:8px}.ok{background:#eef9f1;color:#176b2c}.err{background:#fdecec;color:#a4262c}.route-head{margin:16px 0 8px;padding:11px 12px;background:#101a42;color:#fff;border-radius:10px;font-weight:900}.route-head small{display:block;margin-top:3px;color:#cbd5e1;font-size:12px}.stop{margin:10px 0 8px;padding:8px 10px;background:#e8edf5;color:#111827;border-radius:8px;font-weight:900}.card{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:12px;margin-bottom:10px;box-shadow:0 4px 12px rgba(15,23,42,.05)}.card.resolved{opacity:.72}.student{display:flex;justify-content:space-between;gap:10px;font-size:18px;font-weight:900}.student small{font-size:13px;color:#667085}.info{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:6px;color:#344054;font-size:14px}.phone{font-weight:900;white-space:nowrap}.memo{margin-top:6px;color:#667085;font-size:13px}.actions{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin-top:10px}.actions button{min-height:42px}.status{display:inline-flex;margin-top:8px;padding:5px 8px;border-radius:999px;background:#eef2f7;color:#344054;font-size:12px;font-weight:900}.status.boarded{background:#e8f7ee;color:#176b2c}.status.missed{background:#fdecec;color:#a4262c}.status.called{background:#fff4df;color:#915c00}.empty{padding:28px;text-align:center;color:#667085;background:#fff;border:1px solid #d9dee7;border-radius:10px}@media(max-width:760px){.filter{grid-template-columns:1fr 1fr}.filter .primary{grid-column:1/-1}.info{grid-template-columns:1fr}.actions{grid-template-columns:1fr}.top{display:none}.wrap{margin-top:12px}}
.stop .map-link{display:inline-flex;margin-left:6px;padding:2px 7px;border-radius:999px;background:#dbeafe;color:#1769c2;text-decoration:none;font-size:12px;font-weight:900}.stop-address{display:block;margin-top:3px;color:#667085;font-size:12px;font-weight:700}
</style>
</head>
<body>
<?php echo ieum_admin_header('boarding'); ?>
<?php echo ieum_admin_subnav('boarding'); ?>
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
        <select name="vehicle_label" onchange="this.form.route_id.value='0'; this.form.submit();">
            <option value="">전체 호차</option>
            <?php while ($vehicle = sql_fetch_array($vehicle_labels)) { ?>
            <option value="<?php echo get_text($vehicle['vehicle_label']); ?>" <?php echo get_selected($vehicle_label, $vehicle['vehicle_label']); ?>><?php echo get_text($vehicle['vehicle_label']); ?></option>
            <?php } ?>
        </select>
        <select name="route_id">
            <option value="0">전체 노선</option>
            <?php while ($route = sql_fetch_array($routes)) { ?>
            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected($route_id, (int) $route['route_id']); ?>><?php echo get_text(trim(($route['vehicle_label'] ? $route['vehicle_label'] . ' · ' : '') . $route['route_name'])); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn primary">조회</button>
    </form>
    <?php
    $current_route = '';
    $current_stop = '';
    $has_rows = false;
    while ($row = sql_fetch_array($rows)) {
        $has_rows = true;
        $route_key = trim(($row['vehicle_label'] ?: '') . '|' . ($row['route_name'] ?: ''));
        if ($current_route !== $route_key) {
            $current_route = $route_key;
            $current_stop = '';
            $route_title = trim(($row['vehicle_label'] ?: '차량 미지정') . ' · ' . ($row['route_name'] ?: '노선 미지정'));
            $driver = trim(($row['driver_name'] ?: '') . ' ' . ($row['driver_phone'] ?: ''));
            echo '<div class="route-head">' . get_text($route_title) . ($driver !== '' ? '<small>' . get_text($driver) . '</small>' : '') . '</div>';
        }
        $stop_key = $row['stop_time'] . '|' . $row['stop_name'];
        if ($current_stop !== $stop_key) {
            $current_stop = $stop_key;
            $map_href = ieum_boarding_stop_map_href($row);
            echo '<div class="stop">' . get_text($row['stop_time'] . ' ' . $row['stop_name']);
            if (!empty($row['stop_address'])) {
                echo '<span class="stop-address">' . get_text($row['stop_address']) . '</span>';
            }
            if ($map_href !== '') {
                echo '<a class="map-link" href="' . get_text($map_href) . '" target="_blank" rel="noopener">지도</a>';
            }
            echo '</div>';
        }
        $status = isset($row['boarding_status']) ? $row['boarding_status'] : '';
        $status_label = isset($status_labels[$status]) ? $status_labels[$status] : '미확인';
        $needs_resolve = ($status === 'missed' || $status === 'called' || (isset($row['boarding_note']) && trim($row['boarding_note']) !== '')) && empty($row['resolved_at']);
        $memo = trim(($row['vehicle_memo'] ?: $row['place_name']) . ($row['student_memo'] ? ' / ' . $row['student_memo'] : ''));
    ?>
    <section class="card <?php echo empty($row['resolved_at']) ? '' : 'resolved'; ?>">
        <div class="student"><span><?php echo get_text($row['student_name']); ?></span><small><?php echo get_text(ieum_boarding_grade_label($row['grade_group'])); ?></small></div>
        <div class="info">
            <span><?php echo get_text(trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''))); ?></span>
            <span class="phone"><?php echo get_text($row['contact_phone']); ?></span>
        </div>
        <?php if ($memo !== '') { ?><div class="memo"><?php echo get_text($memo); ?></div><?php } ?>
        <span class="status <?php echo get_text($status); ?>"><?php echo get_text($status_label . ($row['checked_at'] ? ' · ' . substr($row['checked_at'], 11, 5) : '')); ?></span>
        <?php if (!empty($row['resolved_at'])) { ?><span class="status boarded">처리완료 · <?php echo get_text(substr($row['resolved_at'], 11, 5)); ?></span><?php } ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <input class="note" type="text" name="note" value="<?php echo get_text(isset($row['boarding_note']) ? $row['boarding_note'] : ''); ?>" placeholder="메모: 연락 안 됨, 다음 차 탑승 등">
            <div class="actions">
                <button class="btn primary" type="submit" name="status" value="boarded">탑승</button>
                <button class="btn" type="submit" name="status" value="missed">미탑승</button>
                <button class="btn" type="submit" name="status" value="called">보호자 통화</button>
            </div>
        </form>
        <?php if ($needs_resolve) { ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="journal_date" value="<?php echo get_text($journal_date); ?>">
            <input type="hidden" name="ride_type" value="<?php echo get_text($ride_type); ?>">
            <input type="hidden" name="vehicle_label" value="<?php echo get_text($vehicle_label); ?>">
            <input type="hidden" name="route_id" value="<?php echo (int) $route_id; ?>">
            <input type="hidden" name="student_vehicle_id" value="<?php echo (int) $row['student_vehicle_id']; ?>">
            <button class="btn resolve" type="submit">특이사항 처리완료</button>
        </form>
        <?php } ?>
    </section>
    <?php } ?>
    <?php if (!$has_rows) { ?><div class="empty">오늘 조건에 맞는 차량 이용 학생이 없습니다.</div><?php } ?>
</main>
</body>
</html>
