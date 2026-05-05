<?php
$sub_menu = '950181';
require_once './_common.php';

$g5['title'] = '아이이음 차량 일지';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$ride_type = isset($_GET['ride_type']) ? preg_replace('/[^a-z]/', '', trim($_GET['ride_type'])) : '';
$route_id = isset($_GET['route_id']) ? (int) $_GET['route_id'] : 0;
if ($ride_type !== 'pickup' && $ride_type !== 'dropoff') {
    $ride_type = '';
}

$where = " sv.academy_id = '{$academy_id}' and sv.is_active = 1 and st.is_active = 1 and s.is_active = 1 ";
if ($ride_type !== '') {
    $where .= " and sv.ride_type = '" . sql_escape_string($ride_type) . "' ";
}
if ($route_id) {
    $where .= " and sv.route_id = '{$route_id}' ";
}

function ieum_journal_grade_label($value)
{
    $labels = array(
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
    );

    return isset($labels[$value]) ? $labels[$value] : $value;
}

$routes = sql_query("
    select *
      from " . IEUM_VEHICLE_ROUTE_TABLE . "
     where academy_id = '{$academy_id}'
       and is_active = 1
  order by sort_order asc, route_name asc
", false);

$rows = sql_query("
    select sv.ride_type, sv.place_name, sv.contact_phone, sv.memo as vehicle_memo, s.student_name, s.grade_group, s.memo as student_memo,
           c.class_name, c.start_time as class_start_time,
           st.stop_id, st.stop_name, st.stop_time,
           r.route_id, r.route_name, r.vehicle_label, r.driver_name, r.driver_phone
      from " . IEUM_STUDENT_VEHICLE_TABLE . " sv
      join " . IEUM_STUDENT_TABLE . " s on s.student_id = sv.student_id and s.academy_id = sv.academy_id
 left join " . IEUM_CLASS_TIME_TABLE . " c on c.class_time_id = s.class_time_id and c.academy_id = s.academy_id
      join " . IEUM_VEHICLE_STOP_TABLE . " st on st.stop_id = sv.stop_id and st.academy_id = sv.academy_id
 left join " . IEUM_VEHICLE_ROUTE_TABLE . " r on r.route_id = sv.route_id and r.academy_id = sv.academy_id
     where {$where}
  order by field(sv.ride_type, 'pickup', 'dropoff'), r.sort_order asc, st.stop_time asc, st.sort_order asc, st.stop_name asc, s.student_name asc
", false);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo get_text($g5['title']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1120px;margin:20px auto;padding:0 18px}.topline{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px}h1{margin:0;font-size:26px}.meta{color:#667085;margin-top:6px}.filter{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px}.btn,select{border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:9px 12px;font-weight:700}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.group{background:#fff;border:1px solid #d9dee7;border-radius:8px;margin-bottom:14px;overflow:hidden}.group-head{display:flex;justify-content:space-between;gap:12px;background:#15204a;color:#fff;padding:10px 12px;font-weight:900}.group-head small{font-weight:600;color:#dbeafe}.stop-head{background:#eef2f7;padding:8px 12px;font-weight:900;border-top:1px solid #d9dee7}table{width:100%;border-collapse:collapse}th,td{border-top:1px solid #e2e8f0;padding:6px 7px;text-align:center;font-size:14px}th{background:#72829d;color:#fff}.left{text-align:left}.check{width:42px}.phone{white-space:nowrap}.memo{min-width:180px}.empty{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:32px;text-align:center;color:#667085}
@media print{@page{size:A4;margin:8mm}body{background:#fff;font-size:11px}.filter,.print-hide{display:none}.wrap{max-width:none;margin:0;padding:0}.topline{margin-bottom:8px}h1{font-size:20px}.meta{font-size:11px}.group{break-inside:avoid;border-color:#999;margin-bottom:8px}.group-head{background:#eee!important;color:#111!important;padding:6px 8px}.group-head small{color:#333}.stop-head{background:#f4f4f4!important;padding:5px 8px}th{background:#ddd!important;color:#111!important}th,td{font-size:10.5px;padding:4px 5px}.memo{min-width:120px}}
</style>
</head>
<body>
<main class="wrap">
    <div class="topline">
        <div>
            <h1>차량 일지</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo G5_TIME_YMD; ?></div>
        </div>
        <button type="button" class="btn primary print-hide" onclick="window.print()">인쇄</button>
    </div>
    <form method="get" class="filter print-hide">
        <select name="ride_type">
            <option value="">전체</option>
            <option value="pickup" <?php echo get_selected($ride_type, 'pickup'); ?>>픽업</option>
            <option value="dropoff" <?php echo get_selected($ride_type, 'dropoff'); ?>>하차</option>
        </select>
        <select name="route_id">
            <option value="0">전체 노선</option>
            <?php while ($route = sql_fetch_array($routes)) { ?>
            <option value="<?php echo (int) $route['route_id']; ?>" <?php echo get_selected($route_id, (int) $route['route_id']); ?>><?php echo get_text($route['route_name']); ?></option>
            <?php } ?>
        </select>
        <button type="submit" class="btn">조회</button>
        <a class="btn" href="<?php echo IEUM_URL; ?>/admin/vehicles.php">차량 관리</a>
    </form>
    <?php
    $current_group = '';
    $current_stop = '';
    $has_rows = false;
    $student_count = 0;
    while ($row = sql_fetch_array($rows)) {
        $has_rows = true;
        $group_key = $row['ride_type'] . '|' . (int) $row['route_id'];
        $stop_key = $group_key . '|' . (int) $row['stop_id'];
        if ($current_group !== $group_key) {
            if ($current_group !== '') {
                echo '</tbody></table></section>';
            }
            $current_group = $group_key;
            $current_stop = '';
            $student_count = 0;
            $type_label = $row['ride_type'] === 'pickup' ? '픽업' : '하차';
            echo '<section class="group">';
            echo '<div class="group-head"><span>' . get_text($type_label . ' · ' . ($row['route_name'] ?: '노선 미지정')) . '</span><small>' . get_text(trim(($row['vehicle_label'] ?: '') . ' ' . ($row['driver_name'] ?: '') . ' ' . ($row['driver_phone'] ?: ''))) . '</small></div>';
        }
        if ($current_stop !== $stop_key) {
            if ($current_stop !== '') {
                echo '</tbody></table>';
            }
            $current_stop = $stop_key;
            echo '<div class="stop-head">' . get_text($row['stop_time'] . ' ' . $row['stop_name']) . '</div>';
            echo '<table><thead><tr><th class="check">확인</th><th>학생명</th><th>학년/부</th><th>수업부</th><th>연락처</th><th class="left memo">메모</th></tr></thead><tbody>';
        }
        $student_count++;
        echo '<tr>';
        echo '<td class="check">□</td>';
        echo '<td>' . get_text($row['student_name']) . '</td>';
        echo '<td>' . get_text(ieum_journal_grade_label($row['grade_group'])) . '</td>';
        echo '<td>' . get_text(trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''))) . '</td>';
        echo '<td class="phone">' . get_text($row['contact_phone']) . '</td>';
        $memo = trim(($row['vehicle_memo'] ?: $row['place_name']) . ($row['student_memo'] ? ' / ' . $row['student_memo'] : ''));
        echo '<td class="left memo">' . get_text($memo) . '</td>';
        echo '</tr>';
    }
    if ($current_group !== '') {
        echo '</tbody></table></section>';
    }
    if (!$has_rows) {
        echo '<div class="empty">차량 배정 학생이 없습니다.</div>';
    }
    ?>
</main>
</body>
</html>
