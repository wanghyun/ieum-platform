<?php
$sub_menu = '950181';
require_once './_common.php';

$g5['title'] = '아이이음 차량 일지';
$academy = ieum_require_academy_page();
$academy_id = (int) $academy['academy_id'];
$ride_type = isset($_GET['ride_type']) ? preg_replace('/[^a-z]/', '', trim($_GET['ride_type'])) : '';
$route_id = isset($_GET['route_id']) ? (int) $_GET['route_id'] : 0;
$journal_date = isset($_GET['journal_date']) ? preg_replace('/[^0-9-]/', '', trim($_GET['journal_date'])) : G5_TIME_YMD;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journal_date)) {
    $journal_date = G5_TIME_YMD;
}
$weekday_keys = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');
$journal_weekday = $weekday_keys[(int) date('w', strtotime($journal_date))];
$weekday_labels = array('sun' => '일', 'mon' => '월', 'tue' => '화', 'wed' => '수', 'thu' => '목', 'fri' => '금', 'sat' => '토');
$journal_weekday_label = isset($weekday_labels[$journal_weekday]) ? $weekday_labels[$journal_weekday] : '';
if ($ride_type !== 'pickup' && $ride_type !== 'dropoff') {
    $ride_type = '';
}

$weekday_sql = sql_escape_string($journal_weekday);
$where = " sv.academy_id = '{$academy_id}' and sv.is_active = 1 and st.is_active = 1 and s.is_active = 1 and (sv.ride_days = '' or find_in_set('{$weekday_sql}', sv.ride_days)) ";
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
    select sv.ride_type, sv.place_name, sv.contact_phone, sv.ride_days, sv.memo as vehicle_memo, s.student_name, s.grade_group, s.memo as student_memo,
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
*{box-sizing:border-box}body{margin:0;background:#f5f6f8;color:#111827;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1120px;margin:20px auto;padding:0 18px}.wrap.is-loading{opacity:.55;pointer-events:none}.topline{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px}h1{margin:0;font-size:26px}.meta{color:#667085;margin-top:6px}.filter{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 10px}.btn,select,input[type=date]{border:1px solid #cfd6df;border-radius:6px;background:#fff;color:#111827;text-decoration:none;padding:9px 12px;font-weight:700}.btn.primary{background:#1769c2;border-color:#1769c2;color:#fff}.journal-guide{margin:0 0 16px;color:#667085;font-size:13px}.group{background:#fff;border:1px solid #d9dee7;border-radius:8px;margin-bottom:14px;overflow:hidden}.group-head{display:flex;justify-content:space-between;gap:12px;background:#15204a;color:#fff;padding:10px 12px;font-weight:900}.group-head small{font-weight:600;color:#dbeafe;text-align:right}.stop-head{background:#eef2f7;padding:8px 12px;font-weight:900;border-top:1px solid #d9dee7}.student-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;padding:7px;border-top:1px solid #e2e8f0}.student-card{border:1px solid #dbe2ec;border-radius:6px;background:#fff;padding:7px;min-width:0}.student-main{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:6px;font-weight:900}.student-main span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.student-main small{color:#667085;font-weight:800;white-space:nowrap}.student-sub{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:5px;margin-top:4px;font-size:12px}.student-sub span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.phone{white-space:nowrap;font-weight:800}.memo{min-height:17px;margin-top:4px;color:#344054;font-size:12px;line-height:1.3;word-break:keep-all;overflow-wrap:anywhere}.empty{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:32px;text-align:center;color:#667085}
@media (max-width:900px){.student-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:720px){.student-grid{grid-template-columns:1fr}.topline{align-items:flex-start;flex-direction:column}.student-sub{grid-template-columns:1fr}}
@media print{@page{size:A4;margin:5mm}body{background:#fff;color:#111;font-size:9px}.filter,.print-hide{display:none}.wrap{max-width:none;margin:0;padding:0}.topline{margin-bottom:4px;align-items:flex-end}h1{font-size:16px}.meta{font-size:9px;margin-top:2px}.group{break-inside:avoid;border-color:#999;border-radius:4px;margin-bottom:4px}.group-head{background:#eee!important;color:#111!important;padding:3px 5px;font-size:9.5px}.group-head small{color:#333}.stop-head{background:#f4f4f4!important;padding:3px 5px;font-size:9.5px}.student-grid{grid-template-columns:repeat(3,1fr);gap:3px;padding:3px}.student-card{padding:3px 4px;border-color:#b8b8b8;border-radius:4px;break-inside:avoid;min-height:39px}.student-main{font-size:9.5px;gap:4px}.student-sub,.memo{font-size:8px}.student-sub{margin-top:1px;gap:3px}.memo{min-height:11px;margin-top:1px;line-height:1.2}.phone{font-size:8px}.empty{border-color:#999;padding:18px}}
</style>
</head>
<body>
<main class="wrap">
    <div class="topline">
        <div>
            <h1>차량 일지</h1>
            <div class="meta"><?php echo get_text($academy['academy_name']); ?> · <?php echo get_text($journal_date . ($journal_weekday_label ? ' (' . $journal_weekday_label . ')' : '')); ?></div>
        </div>
        <button type="button" class="btn primary print-hide" onclick="window.print()">인쇄</button>
    </div>
    <form method="get" class="filter print-hide">
        <input type="date" name="journal_date" value="<?php echo get_text($journal_date); ?>">
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
    <p class="journal-guide print-hide">A4 세로 인쇄 기준으로 학생 카드가 한 줄에 3명씩 배치됩니다. 날짜를 바꾸면 해당 요일 차량 이용 학생만 표시됩니다.</p>
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
                echo '</div></section>';
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
                echo '</div>';
            }
            $current_stop = $stop_key;
            echo '<div class="stop-head">' . get_text($row['stop_time'] . ' ' . $row['stop_name']) . '</div>';
            echo '<div class="student-grid">';
        }
        $student_count++;
        $grade_label = ieum_journal_grade_label($row['grade_group']);
        $class_label = trim(($row['class_name'] ?: '') . ' ' . ($row['class_start_time'] ?: ''));
        $memo = trim(($row['vehicle_memo'] ?: $row['place_name']) . ($row['student_memo'] ? ' / ' . $row['student_memo'] : ''));
        echo '<article class="student-card">';
        echo '<div class="student-main"><span>' . get_text($row['student_name']) . '</span><small>' . get_text($grade_label) . '</small></div>';
        echo '<div class="student-sub"><span>' . get_text($class_label) . '</span><span class="phone">' . get_text($row['contact_phone']) . '</span></div>';
        echo '<div class="memo">' . get_text($memo) . '</div>';
        echo '</article>';
    }
    if ($current_group !== '') {
        echo '</div></section>';
    }
    if (!$has_rows) {
        echo '<div class="empty">차량 배정 학생이 없습니다.</div>';
    }
    ?>
</main>
<script>
(function () {
    const main = document.querySelector('main.wrap');
    if (!main) return;
    const loadView = async (url, push) => {
        main.classList.add('is-loading');
        try {
            const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.querySelector('main.wrap');
            if (!next) {
                window.location.href = url;
                return;
            }
            main.innerHTML = next.innerHTML;
            if (push) history.pushState({ieumAjax: true}, '', url);
        } catch (error) {
            window.location.href = url;
        } finally {
            main.classList.remove('is-loading');
        }
    };
    main.addEventListener('submit', (event) => {
        const form = event.target.closest('form.filter');
        if (!form || String(form.method || 'get').toLowerCase() !== 'get') return;
        event.preventDefault();
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });
    main.addEventListener('change', (event) => {
        const control = event.target.closest('form.filter input, form.filter select');
        if (!control) return;
        const form = control.form;
        if (!form) return;
        const url = form.action || window.location.pathname;
        loadView(url + '?' + new URLSearchParams(new FormData(form)).toString(), true);
    });
    window.addEventListener('popstate', () => loadView(window.location.href, false));
})();
</script>
</body>
</html>
